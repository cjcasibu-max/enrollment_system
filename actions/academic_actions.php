<?php
require_once '../includes/auth_check.php';
checkRole(['teacher', 'registrar', 'admin']);

require_once '../config/database.php';
require_once '../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Security validation failed. Please try again.';
    $redirect = ($_SESSION['role'] === 'teacher') ? '../teacher/my_classes' : '../registrar/grade_approvals';
    header('Location: ' . $redirect);
    exit;
}

require_once '../includes/academic_terms.php';

$action = $_POST['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

// Pre-validate posted grades against configured grade scale to avoid hardcoded bounds later on
if (in_array($action, ['save_grades', 'submit_grades'], true)) {
    $gradeScale = getGradeScale(isset($pdo) ? $pdo : null);
    $gradeMin = (float)$gradeScale['min'];
    $gradeMax = (float)$gradeScale['max'];
    foreach (['prelim', 'midterm', 'exam', 'final'] as $field) {
        if (!isset($_POST[$field]) || !is_array($_POST[$field])) continue;
        foreach ($_POST[$field] as $enrollmentId => $val) {
            $value = trim((string)$val);
            if ($value === '') continue;
            if (!is_numeric($value) || (float)$value < $gradeMin || (float)$value > $gradeMax) {
                throw new RuntimeException('Grades must be between ' . $gradeMin . ' and ' . $gradeMax . '.');
            }
        }
    }
}
function academicRedirect(string $path, string $message, bool $ok=false): void { $_SESSION[$ok?'flash_success':'flash_error']=$message; $path=preg_replace('/\.php(?=([?#]|$))/', '', $path); header('Location: '.$path); exit; }
function ownedSection(PDO $pdo,int $sectionId,int $userId): array { $q=$pdo->prepare('SELECT s.id,s.section_name,s.academic_term_id,s.teacher_id,COALESCE(c.course_code,s.section_name,\'Section\') AS course_code FROM sections s LEFT JOIN courses c ON c.id=s.course_id WHERE s.id=:id AND s.teacher_id=:teacher_id LIMIT 1');$q->execute(['id'=>$sectionId,'teacher_id'=>$userId]);$row=$q->fetch();if(!$row) throw new RuntimeException('This section is not assigned to you.');return $row; }
function canGradeSubject(PDO $pdo, int $sectionId, ?int $sectionSubjectId, int $userId): array {
    $q = $pdo->prepare('SELECT s.id, s.section_name, s.academic_term_id, s.teacher_id, COALESCE(c.course_code, s.section_name, \'Section\') AS course_code FROM sections s LEFT JOIN courses c ON c.id = s.course_id WHERE s.id = :id LIMIT 1');
    $q->execute(['id' => $sectionId]);
    $section = $q->fetch();
    if (!$section) throw new RuntimeException('This section is not assigned to you.');
    if ((int)$section['teacher_id'] === $userId) {
        if ($sectionSubjectId !== null) {
            $chk = $pdo->prepare('SELECT id FROM section_subjects WHERE id = :ss_id AND section_id = :sec_id LIMIT 1');
            $chk->execute(['ss_id' => $sectionSubjectId, 'sec_id' => $sectionId]);
            if (!$chk->fetch()) throw new RuntimeException('Invalid subject specified for this section.');
        }
        return $section;
    }
    if ($sectionSubjectId === null) {
        throw new RuntimeException('You are not authorized to grade this section.');
    }
    $chk = $pdo->prepare('SELECT id FROM section_subjects WHERE id = :ss_id AND section_id = :sec_id AND instructor_id = :user_id LIMIT 1');
    $chk->execute(['ss_id' => $sectionSubjectId, 'sec_id' => $sectionId, 'user_id' => $userId]);
    if (!$chk->fetch()) throw new RuntimeException('You are not authorized to grade this subject.');
    return $section;
}
try {
 if ($action==='save_attendance') { if($role!=='teacher') throw new RuntimeException('Only teachers may record attendance.'); $sectionId=(int)($_POST['section_id']??0);$section=ownedSection($pdo,$sectionId,$userId);$date=$_POST['session_date']??'';if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('A valid session date is required.');$today=(new DateTimeImmutable('today'))->format('Y-m-d');if($date > $today) throw new RuntimeException('Attendance cannot be recorded for a future date.');$startTime=trim($_POST['start_time']??'');$startTime=$startTime!=='' ? $startTime : null;$duplicate=$pdo->prepare('SELECT id FROM attendance_sessions WHERE section_id=:section_id AND session_date=:session_date AND start_time IS NOT NULL AND :start_time IS NOT NULL AND start_time=:start_time LIMIT 1');$duplicate->execute(['section_id'=>$sectionId,'session_date'=>$date,'start_time'=>$startTime]);if($startTime !== null && $duplicate->fetch()) throw new RuntimeException('An attendance session already exists for this section on the selected date and start time.');$pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO attendance_sessions(section_id,academic_term_id,session_date,start_time,end_time,topic,remarks,recorded_by) VALUES(:section_id,:term_id,:session_date,:start_time,:end_time,:topic,:remarks,:user_id)');$q->execute(['section_id'=>$sectionId,'term_id'=>$section['academic_term_id'],'session_date'=>$date,'start_time'=>$startTime,'end_time'=>($_POST['end_time']?:null),'topic'=>trim($_POST['topic']??'')?:null,'remarks'=>trim($_POST['remarks']??'')?:null,'user_id'=>$userId]);$sessionId=(int)$pdo->lastInsertId();$students=$pdo->prepare("SELECT student_id FROM enrollments WHERE section_id=:section_id AND status='enrolled'");$students->execute(['section_id'=>$sectionId]);$ins=$pdo->prepare('INSERT INTO attendance_records(attendance_session_id,student_id,status,remarks) VALUES(:session_id,:student_id,:status,:remarks)');foreach($students->fetchAll() as $student){$status=$_POST['status'][$student['student_id']]??'present';if(!in_array($status,['present','absent','late','excused'],true))$status='present';$ins->execute(['session_id'=>$sessionId,'student_id'=>$student['student_id'],'status'=>$status,'remarks'=>trim($_POST['student_remarks'][$student['student_id']]??'')?:null]);}$pdo->commit();academicRedirect('../teacher/attendance.php?section_id='.$sectionId,'Attendance session saved.',true);
 }
 if ($action==='save_grades' || $action==='submit_grades') {
     if($role!=='teacher')throw new RuntimeException('Only teachers may manage grades.');
     $sectionId=(int)($_POST['section_id']??0);
     $sectionSubjectId=isset($_POST['section_subject_id'])&&$_POST['section_subject_id']!==''?(int)$_POST['section_subject_id']:null;
     if ($action === 'submit_grades') {
         $section = ownedSection($pdo, $sectionId, $userId);
         if ($sectionSubjectId !== null) {
             $chk = $pdo->prepare("SELECT id FROM section_subjects WHERE id=:ss_id AND section_id=:sec_id LIMIT 1");
             $chk->execute(['ss_id' => $sectionSubjectId, 'sec_id' => $sectionId]);
             if (!$chk->fetch()) throw new RuntimeException('Invalid subject specified for this section.');
         }
     } else {
         $section = canGradeSubject($pdo, $sectionId, $sectionSubjectId, $userId);
     }
     $pdo->beginTransaction();
     $sub=$pdo->prepare('SELECT id,status FROM grade_submissions WHERE section_id=:section_id AND academic_term_id=:term_id FOR UPDATE');
     $sub->execute(['section_id'=>$sectionId,'term_id'=>$section['academic_term_id']]);
     $submission=$sub->fetch();
     if($submission && $submission['status']!=='draft')throw new RuntimeException('Submitted or approved grades cannot be changed.');
     if(!$submission){
         $q=$pdo->prepare("INSERT INTO grade_submissions(section_id,academic_term_id,teacher_id,status) VALUES(:section_id,:term_id,:teacher_id,'draft')");
         $q->execute(['section_id'=>$sectionId,'term_id'=>$section['academic_term_id'],'teacher_id'=>(int)$section['teacher_id']]);
         $submission=['id'=>(int)$pdo->lastInsertId(),'status'=>'draft'];
     }
     $enrollmentStmt=$pdo->prepare("SELECT id,student_id FROM enrollments WHERE section_id=:section_id AND status='enrolled'");
     $enrollmentStmt->execute(['section_id'=>$sectionId]);
     $enrollments=$enrollmentStmt->fetchAll();
     if(empty($enrollments))throw new RuntimeException('No enrolled students were found for this section.');
     $save=$pdo->prepare('INSERT INTO student_grades(grade_submission_id,section_subject_id,enrollment_id,student_id,prelim_grade,midterm_grade,final_exam_grade,final_grade,remarks) VALUES(:submission_id,:section_subject_id,:enrollment_id,:student_id,:prelim,:midterm,:exam,:final,:remarks) ON DUPLICATE KEY UPDATE prelim_grade=VALUES(prelim_grade),midterm_grade=VALUES(midterm_grade),final_exam_grade=VALUES(final_exam_grade),final_grade=VALUES(final_grade),remarks=VALUES(remarks)');
     foreach($enrollments as $enrollment){
         $grades=[];
         foreach(['prelim','midterm','exam','final'] as $field){
             $value=trim($_POST[$field][$enrollment['id']]??'');
             if($value!==''&&(!is_numeric($value)||(float)$value<0||(float)$value>100))throw new RuntimeException('Grades must be between 0 and 100.');
             $grades[$field]=$value===''?null:round((float)$value,2);
         }
         if($action==='submit_grades'&&$grades['final']===null)throw new RuntimeException('A final grade is required for every enrolled student before submission.');
         $save->execute(['submission_id'=>$submission['id'],'section_subject_id'=>$sectionSubjectId,'enrollment_id'=>$enrollment['id'],'student_id'=>$enrollment['student_id'],'prelim'=>$grades['prelim'],'midterm'=>$grades['midterm'],'exam'=>$grades['exam'],'final'=>$grades['final'],'remarks'=>trim($_POST['remarks'][$enrollment['id']]??'')?:null]);
     }
     if($action==='submit_grades'){
         $q=$pdo->prepare("UPDATE grade_submissions SET status='submitted',submitted_at=NOW() WHERE id=:id");
         $q->execute(['id'=>$submission['id']]);
     }
     $pdo->commit();
     $redirectUrl='../teacher/lms_grades.php'.($sectionSubjectId?'?section_subject_id='.$sectionSubjectId:'?section_id='.$sectionId);
     academicRedirect($redirectUrl,$action==='submit_grades'?'Grades submitted for Registrar approval.':'Draft grades saved.',true);
 }
 if ($action==='approve_grades') { if(!in_array($role,['registrar','admin'],true))throw new RuntimeException('Only the Registrar may approve grades.');$id=(int)($_POST['submission_id']??0);$q=$pdo->prepare("SELECT gs.id,gs.status,s.id section_id,s.section_name FROM grade_submissions gs JOIN sections s ON s.id=gs.section_id WHERE gs.id=:id FOR UPDATE");$pdo->beginTransaction();$q->execute(['id'=>$id]);$submission=$q->fetch();if(!$submission||$submission['status']!=='submitted')throw new RuntimeException('Only submitted gradebooks may be approved.');$u=$pdo->prepare("UPDATE grade_submissions SET status='approved',reviewed_by=:user_id,reviewed_at=NOW() WHERE id=:id");$u->execute(['user_id'=>$userId,'id'=>$id]);$students=$pdo->prepare('SELECT DISTINCT u.id FROM student_grades sg JOIN students s ON s.id=sg.student_id JOIN users u ON u.id=s.user_id WHERE sg.grade_submission_id=:id');$students->execute(['id'=>$id]);foreach($students->fetchAll(PDO::FETCH_COLUMN) as $studentUserId)createNotification($pdo,(int)$studentUserId,'Grades approved','Your grades for '.$submission['section_name'].' are now available in your academic record.','success');$pdo->commit();academicRedirect('../registrar/grade_approvals.php','Grades approved and locked for teacher editing.',true);
 }
 throw new RuntimeException('Invalid academic action.');
} catch (\RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    academicRedirect($role === 'teacher' ? '../teacher/my_classes' : '../registrar/grade_approvals', $e->getMessage());
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Academic operation unexpected failure: ' . $e->getMessage());
    academicRedirect($role === 'teacher' ? '../teacher/my_classes' : '../registrar/grade_approvals', 'An unexpected error occurred while processing the academic record. Please try again.');
}
