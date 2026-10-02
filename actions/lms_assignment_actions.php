<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$subjectId = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
$assignmentId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
$returnTo = $subjectId
    ? resolveAppUrl('student/lms_course?subject_id=' . (int)$subjectId . '&section=assignments')
    : resolveAppUrl('student/lms');

if (!$subjectId || !$assignmentId) {
    $_SESSION['flash_error'] = 'Invalid assignment submission request.';
    header('Location: ' . $returnTo);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Your session token expired. Please try again.';
    header('Location: ' . $returnTo);
    exit;
}

$storedPath = null;
$storageRoot = null;
$existingSubmission = null;
try {
    $assignment = fetchLmsAssignmentForStudentSubject(
        $pdo,
        (int)$lmsStudent['student_id'],
        $subjectId,
        $assignmentId
    );
    if (!$assignment) {
        throw new DomainException('That assignment is not available in your enrolled subject.');
    }

    $file = $_FILES['submission_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('Choose a file to submit.');
    }

    $maxFileSize = 20 * 1024 * 1024;
    if ((int)$file['size'] < 1 || (int)$file['size'] > $maxFileSize) {
        throw new DomainException('The submission must be no larger than 20 MB.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new DomainException('The uploaded file could not be verified. Please try again.');
    }

    $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/rtf' => 'rtf',
        'text/rtf' => 'rtf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file((string)$file['tmp_name']);
    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new DomainException('Unsupported file type. Upload a PDF, Office document, text/CSV file, image, video, or ZIP.');
    }
    $originalFilename = basename(str_replace('\\', '/', (string)$file['name']));
    $originalFilename = preg_replace('/[\x00-\x1F\x7F]/', '_', $originalFilename) ?: 'submission';
    $originalFilename = substr($originalFilename, 0, 255);

    $storageRootPath = __DIR__ . '/../private_uploads/lms_assignment_submissions';
    if (!is_dir($storageRootPath) && !@mkdir($storageRootPath, 0750, true) && !is_dir($storageRootPath)) {
        throw new RuntimeException('Private submission storage is unavailable.');
    }
    $storageRoot = realpath($storageRootPath);
    if (!$storageRoot || !is_writable($storageRoot)) {
        throw new RuntimeException('Private submission storage is unavailable.');
    }
    $privateHtaccess = $storageRoot . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($privateHtaccess)) {
        file_put_contents(
            $privateHtaccess,
            "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Deny from all\n"
                . "</IfModule>\n"
        );
    }

    $studentId = (int)$lmsStudent['student_id'];
    $studentDirectory = $storageRoot . DIRECTORY_SEPARATOR . $studentId;
    if (!is_dir($studentDirectory) && !@mkdir($studentDirectory, 0750, true) && !is_dir($studentDirectory)) {
        throw new RuntimeException('Could not create private submission storage.');
    }
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
    $storedPath = $studentDirectory . DIRECTORY_SEPARATOR . $storedFilename;
    if (!move_uploaded_file((string)$file['tmp_name'], $storedPath)) {
        throw new RuntimeException('The submission could not be saved. Please try again.');
    }

    $relativePath = $studentId . '/' . $storedFilename;
    $pdo->beginTransaction();
    $assignment = fetchLmsAssignmentForStudentSubject(
        $pdo,
        $studentId,
        $subjectId,
        $assignmentId,
        true
    );
    if (!$assignment) {
        throw new DomainException('That assignment is no longer available in your enrolled subject.');
    }
    if (!empty($assignment['is_past_due']) && empty($assignment['allow_late_submissions'])) {
        throw new DomainException('The deadline has passed and late submissions are not accepted.');
    }

    $existingStmt = $pdo->prepare(
        'SELECT id, file_path, score, feedback, graded_at
         FROM lms_assignment_submissions
         WHERE assignment_id = :assignment_id AND student_id = :student_id
         LIMIT 1 FOR UPDATE'
    );
    $existingStmt->execute([
        'assignment_id' => $assignmentId,
        'student_id' => $studentId,
    ]);
    $existingSubmission = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existingSubmission && (
        $existingSubmission['graded_at'] !== null
        || $existingSubmission['score'] !== null
        || $existingSubmission['feedback'] !== null
    )) {
        throw new DomainException('This assignment has been graded and can no longer be changed.');
    }

    if ($existingSubmission) {
        $saveStmt = $pdo->prepare(
            'UPDATE lms_assignment_submissions
             SET file_name = :file_name, file_path = :file_path, submitted_at = NOW()
             WHERE id = :id'
        );
        $saveStmt->execute([
            'file_name' => $originalFilename,
            'file_path' => $relativePath,
            'id' => $existingSubmission['id'],
        ]);
    } else {
        $saveStmt = $pdo->prepare(
            'INSERT INTO lms_assignment_submissions (assignment_id, student_id, file_name, file_path, submitted_at)
             VALUES (:assignment_id, :student_id, :file_name, :file_path, NOW())'
        );
        $saveStmt->execute([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'file_name' => $originalFilename,
            'file_path' => $relativePath,
        ]);
    }
    $pdo->commit();

    if ($existingSubmission && $storageRoot) {
        $oldRelativePath = str_replace('\\', '/', (string)$existingSubmission['file_path']);
        $oldPath = realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldRelativePath));
        if ($oldPath && str_starts_with($oldPath, $storageRoot . DIRECTORY_SEPARATOR) && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    $_SESSION['flash_success'] = !empty($assignment['is_past_due'])
        ? 'Your late assignment submission was uploaded.'
        : 'Your assignment submission was uploaded.';
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($storedPath && is_file($storedPath)) {
        @unlink($storedPath);
    }
    $_SESSION['flash_error'] = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($storedPath && is_file($storedPath)) {
        @unlink($storedPath);
    }
    error_log('LMS assignment submission failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'The submission could not be saved. Please try again.';
}

header('Location: ' . $returnTo);
exit;