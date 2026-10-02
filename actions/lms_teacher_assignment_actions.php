<?php
/**
 * Teacher LMS - Assignment Action Handler (Phase 5)
 * Actions: create_assignment, edit_assignment, delete_assignment,
 *          toggle_publish (AJAX), upload_ref_file, delete_ref_file, grade_submission
 * Security: POST-only, CSRF, teacher ownership re-verified on every write.
 * Data: writes to lms_assignments/lms_assignment_files/lms_assignment_submissions
 *       - same tables student LMS reads, changes are immediately live.
 */
require_once '../includes/lms_access.php';

$teacher = requireLmsTeacherAccess();
$userId  = $teacher['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$action           = trim((string)($_POST['action'] ?? ''));
$sectionSubjectId = (int)filter_input(INPUT_POST, 'section_subject_id', FILTER_VALIDATE_INT);
$returnBase       = $sectionSubjectId > 0
    ? resolveAppUrl('teacher/lms_assignments?section_subject_id=' . $sectionSubjectId)
    : resolveAppUrl('teacher/lms_assignments');
$isAjax = ($action === 'toggle_publish');

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Session expired.']); exit; }
    $_SESSION['flash_error'] = 'Your session token expired. Please try again.';
    header('Location: ' . $returnBase); exit;
}

$subject = null;
if (!$isAjax && $sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms')); exit;
    }
}

$refStorageRootPath = __DIR__ . '/../private_uploads/lms_assignment_files';

function lmsTaEnsureRefStorage(string $path): string {
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) throw new RuntimeException('Reference storage unavailable.');
    $real = realpath($path);
    if (!$real || !is_writable($real)) throw new RuntimeException('Reference storage unavailable.');
    $ht = $real . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    return $real;
}

function lmsTaDeleteRefFile(string $storageRoot, string $filePath): void {
    $rel = str_replace('\\', '/', $filePath);
    $abs = realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($abs && str_starts_with($abs, $storageRoot . DIRECTORY_SEPARATOR) && is_file($abs)) @unlink($abs);
}

$allowedRefMimes = [
    'application/pdf'=>'pdf','application/msword'=>'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
    'application/vnd.ms-powerpoint'=>'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
    'application/vnd.ms-excel'=>'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
    'text/plain'=>'txt','text/csv'=>'csv','application/rtf'=>'rtf','text/rtf'=>'rtf',
    'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
    'application/zip'=>'zip','application/x-zip-compressed'=>'zip',
];

function lmsTaParseDue(string $raw): string {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw)
       ?: DateTime::createFromFormat('Y-m-d H:i', $raw)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $raw);
    if (!$dt) throw new DomainException('Invalid due date format.');
    return $dt->format('Y-m-d H:i:s');
}

function lmsTaParseMaxScore(string $raw): ?float {
    if ($raw === '') return null;
    $v = filter_var($raw, FILTER_VALIDATE_FLOAT);
    if ($v === false || $v < 0) throw new DomainException('Maximum score must be a positive number.');
    return round($v, 2);
}

try {
    switch ($action) {

        /* ===== CREATE ===================================================== */
        case 'create_assignment': {
            $title    = trim((string)($_POST['title'] ?? ''));
            $instruct = trim((string)($_POST['instructions'] ?? ''));
            $dueAt    = trim((string)($_POST['due_at'] ?? ''));
            $atype    = ($_POST['assignment_type'] ?? '') === 'activity' ? 'activity' : 'assignment';
            $late     = !empty($_POST['allow_late_submissions']) ? 1 : 0;
            $pub      = !empty($_POST['is_published']) ? 1 : 0;
            if ($title === '') throw new DomainException('Assignment title is required.');
            if ($instruct === '') throw new DomainException('Instructions are required.');
            if ($dueAt === '') throw new DomainException('Due date and time are required.');
            $dueAtSql  = lmsTaParseDue($dueAt);
            $maxScore  = lmsTaParseMaxScore(trim((string)($_POST['max_score'] ?? '')));
            $ordStmt = $pdo->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM lms_assignments WHERE section_subject_id=:ss');
            $ordStmt->execute(['ss'=>$sectionSubjectId]);
            $ord = (int)$ordStmt->fetchColumn();
            $pdo->beginTransaction();
            $s = $pdo->prepare('INSERT INTO lms_assignments(section_subject_id,assignment_type,title,instructions,due_at,max_score,allow_late_submissions,display_order,is_published) VALUES(:ss,:type,:title,:inst,:due,:ms,:late,:ord,:pub)');
            $s->execute(['ss'=>$sectionSubjectId,'type'=>$atype,'title'=>$title,'inst'=>$instruct,'due'=>$dueAtSql,'ms'=>$maxScore,'late'=>$late,'ord'=>$ord,'pub'=>$pub]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            $_SESSION['flash_success'] = 'Assignment "'.htmlspecialchars($title,ENT_QUOTES).'" created'.($pub?' and published.':' as a draft.');
            header('Location: '.$returnBase.'&highlight='.$newId); exit;
        }

        /* ===== EDIT ======================================================= */
        case 'edit_assignment': {
            $aid     = (int)filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT);
            $title   = trim((string)($_POST['title'] ?? ''));
            $instruct= trim((string)($_POST['instructions'] ?? ''));
            $dueAt   = trim((string)($_POST['due_at'] ?? ''));
            $atype   = ($_POST['assignment_type'] ?? '') === 'activity' ? 'activity' : 'assignment';
            $late    = !empty($_POST['allow_late_submissions']) ? 1 : 0;
            $pub     = !empty($_POST['is_published']) ? 1 : 0;
            if ($aid <= 0) throw new DomainException('Invalid assignment.');
            if ($title === '') throw new DomainException('Assignment title is required.');
            if ($instruct === '') throw new DomainException('Instructions are required.');
            if ($dueAt === '') throw new DomainException('Due date and time are required.');
            $dueAtSql = lmsTaParseDue($dueAt);
            $maxScore = lmsTaParseMaxScore(trim((string)($_POST['max_score'] ?? '')));
            $pdo->beginTransaction();
            $ck = $pdo->prepare('SELECT id FROM lms_assignments WHERE id=:id AND section_subject_id=:ss LIMIT 1 FOR UPDATE');
            $ck->execute(['id'=>$aid,'ss'=>$sectionSubjectId]);
            if (!$ck->fetch()) throw new DomainException('Assignment not found or access denied.');
            $pdo->prepare('UPDATE lms_assignments SET assignment_type=:type,title=:title,instructions=:inst,due_at=:due,max_score=:ms,allow_late_submissions=:late,is_published=:pub WHERE id=:id AND section_subject_id=:ss')
                ->execute(['type'=>$atype,'title'=>$title,'inst'=>$instruct,'due'=>$dueAtSql,'ms'=>$maxScore,'late'=>$late,'pub'=>$pub,'id'=>$aid,'ss'=>$sectionSubjectId]);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Assignment updated.';
            header('Location: '.$returnBase.'&highlight='.$aid); exit;
        }

        /* ===== DELETE ===================================================== */
        case 'delete_assignment': {
            $aid = (int)filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT);
            if ($aid <= 0) throw new DomainException('Invalid assignment.');
            $fStmt = $pdo->prepare('SELECT file_path FROM lms_assignment_files WHERE assignment_id=:id');
            $fStmt->execute(['id'=>$aid]);
            $refFiles = $fStmt->fetchAll(PDO::FETCH_COLUMN,0);
            $pdo->beginTransaction();
            $d = $pdo->prepare('DELETE FROM lms_assignments WHERE id=:id AND section_subject_id=:ss');
            $d->execute(['id'=>$aid,'ss'=>$sectionSubjectId]);
            if ($d->rowCount() === 0) throw new DomainException('Assignment not found or access denied.');
            $pdo->commit();
            $sr = realpath($refStorageRootPath) ?: $refStorageRootPath;
            foreach ($refFiles as $fp) lmsTaDeleteRefFile($sr, (string)$fp);
            $_SESSION['flash_success'] = 'Assignment deleted.';
            header('Location: '.$returnBase); exit;
        }

        /* ===== TOGGLE PUBLISH (AJAX) ====================================== */
        case 'toggle_publish': {
            header('Content-Type: application/json');
            $aid = (int)filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT);
            if ($aid <= 0 || $sectionSubjectId <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid request.']); exit; }
            if (!verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId)) { echo json_encode(['ok'=>false,'error'=>'Access denied.']); exit; }
            $pdo->beginTransaction();
            $fl = $pdo->prepare('UPDATE lms_assignments SET is_published=IF(is_published=1,0,1) WHERE id=:id AND section_subject_id=:ss');
            $fl->execute(['id'=>$aid,'ss'=>$sectionSubjectId]);
            if ($fl->rowCount() === 0) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'Assignment not found.']); exit; }
            $newVal = (int)$pdo->query('SELECT is_published FROM lms_assignments WHERE id='.(int)$aid)->fetchColumn();
            $pdo->commit();
            echo json_encode(['ok'=>true,'is_published'=>$newVal]); exit;
        }

        /* ===== UPLOAD REF FILE ============================================ */
        case 'upload_ref_file': {
            $aid = (int)filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT);
            if ($aid <= 0) throw new DomainException('Invalid assignment.');
            $ck = $pdo->prepare('SELECT id FROM lms_assignments WHERE id=:id AND section_subject_id=:ss LIMIT 1');
            $ck->execute(['id'=>$aid,'ss'=>$sectionSubjectId]);
            if (!$ck->fetch()) throw new DomainException('Assignment not found or access denied.');
            $file = $_FILES['ref_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('Choose a file to attach.');
            if ((int)$file['size'] < 1 || (int)$file['size'] > 50*1024*1024) throw new DomainException('File must be between 1 byte and 50 MB.');
            if (!is_uploaded_file((string)$file['tmp_name'])) throw new DomainException('File upload could not be verified.');
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            if (!isset($allowedRefMimes[$mime])) throw new DomainException('Unsupported file type.');
            $sr = lmsTaEnsureRefStorage($refStorageRootPath);
            $adir = $sr . DIRECTORY_SEPARATOR . $aid;
            if (!is_dir($adir) && !@mkdir($adir, 0750, true) && !is_dir($adir)) throw new RuntimeException('Cannot create reference storage directory.');
            $stored = bin2hex(random_bytes(16)).'.'.$allowedRefMimes[$mime];
            if (!move_uploaded_file((string)$file['tmp_name'], $adir . DIRECTORY_SEPARATOR . $stored)) throw new RuntimeException('Could not save file.');
            $orig = substr(preg_replace('/[\x00-\x1F\x7F]/','_',basename(str_replace('\\','/',(string)$file['name']))),0,255) ?: 'file';
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO lms_assignment_files(assignment_id,file_name,file_path) VALUES(:a,:n,:p)')
                ->execute(['a'=>$aid,'n'=>$orig,'p'=>$aid.'/'.$stored]);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Reference file "'.htmlspecialchars($orig,ENT_QUOTES).'" attached.';
            header('Location: '.$returnBase.'&highlight='.$aid); exit;
        }

        /* ===== DELETE REF FILE ============================================ */
        case 'delete_ref_file': {
            $fid = (int)filter_input(INPUT_POST,'file_id',FILTER_VALIDATE_INT);
            $aid = (int)filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT);
            if ($fid <= 0 || $aid <= 0) throw new DomainException('Invalid request.');
            $ck = $pdo->prepare('SELECT id FROM lms_assignments WHERE id=:id AND section_subject_id=:ss LIMIT 1');
            $ck->execute(['id'=>$aid,'ss'=>$sectionSubjectId]);
            if (!$ck->fetch()) throw new DomainException('Access denied.');
            $fRow = $pdo->prepare('SELECT file_path FROM lms_assignment_files WHERE id=:fid AND assignment_id=:aid LIMIT 1');
            $fRow->execute(['fid'=>$fid,'aid'=>$aid]);
            $fr = $fRow->fetch(PDO::FETCH_ASSOC);
            if (!$fr) throw new DomainException('Reference file not found.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lms_assignment_files WHERE id=:id')->execute(['id'=>$fid]);
            $pdo->commit();
            lmsTaDeleteRefFile(realpath($refStorageRootPath) ?: $refStorageRootPath, (string)$fr['file_path']);
            $_SESSION['flash_success'] = 'Reference file removed.';
            header('Location: '.$returnBase.'&highlight='.$aid); exit;
        }

        /* ===== GRADE SUBMISSION =========================================== */
        case 'grade_submission': {
            $sid      = (int)filter_input(INPUT_POST,'submission_id',FILTER_VALIDATE_INT);
            $aid      = (int)filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT);
            $scoreRaw = trim((string)($_POST['score'] ?? ''));
            $feedback = trim((string)($_POST['feedback'] ?? ''));
            if ($sid <= 0 || $aid <= 0) throw new DomainException('Invalid submission.');
            $ck = $pdo->prepare('SELECT id,max_score FROM lms_assignments WHERE id=:id AND section_subject_id=:ss LIMIT 1');
            $ck->execute(['id'=>$aid,'ss'=>$sectionSubjectId]);
            $ar = $ck->fetch(PDO::FETCH_ASSOC);
            if (!$ar) throw new DomainException('Assignment not found or access denied.');
            $scoreSql = null;
            if ($scoreRaw !== '') {
                $sv = filter_var($scoreRaw, FILTER_VALIDATE_FLOAT);
                if ($sv === false || $sv < 0) throw new DomainException('Score must be a non-negative number.');
                if ($ar['max_score'] !== null && $sv > (float)$ar['max_score']) throw new DomainException('Score cannot exceed the maximum of '.$ar['max_score'].'.');
                $scoreSql = round($sv, 2);
            }
            $fbSql = $feedback !== '' ? $feedback : null;
            if ($scoreSql === null && $fbSql === null) throw new DomainException('Provide at least a score or feedback.');
            $pdo->beginTransaction();
            $sc = $pdo->prepare('SELECT id FROM lms_assignment_submissions WHERE id=:sid AND assignment_id=:aid LIMIT 1 FOR UPDATE');
            $sc->execute(['sid'=>$sid,'aid'=>$aid]);
            if (!$sc->fetch()) throw new DomainException('Submission not found.');
            $pdo->prepare('UPDATE lms_assignment_submissions SET score=:score,feedback=:fb,graded_at=NOW() WHERE id=:id')
                ->execute(['score'=>$scoreSql,'fb'=>$fbSql,'id'=>$sid]);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Submission graded.';
            $redir = filter_input(INPUT_POST, 'redirect_to', FILTER_DEFAULT);
            if ($redir) {
                header('Location: ' . resolveAppUrl($redir));
                exit;
            }
            header('Location: '.resolveAppUrl('teacher/lms_assignments?section_subject_id='.$sectionSubjectId.'&view_submissions='.$aid)); exit;
        }

        default:
            $_SESSION['flash_error'] = 'Unknown action.';
            header('Location: '.$returnBase); exit;
    }

} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: '.$returnBase); exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('LMS teacher assignment ['.$action.']: '.$e->getMessage());
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'An unexpected error occurred.']); exit; }
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: '.$returnBase); exit;
}
