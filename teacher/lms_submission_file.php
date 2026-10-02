<?php
/**
 * Teacher LMS - Submission File Download
 *
 * Serves a student submission file to the teacher after verifying:
 *   1. Teacher is authenticated (requireLmsTeacherAccess).
 *   2. The submission's assignment belongs to a subject this teacher owns
 *      (verifyTeacherOwnsSubject).
 *
 * URL: teacher/lms_submission_file?submission_id=N
 *
 * The file is stored in private_uploads/lms_assignment_submissions/{student_id}/
 * and is NOT web-accessible directly (.htaccess blocks it). This script reads
 * the file and streams it to the browser.
 */

require_once '../includes/lms_access.php';

$teacher      = requireLmsTeacherAccess();
$userId       = $teacher['user_id'];
$submissionId = (int)filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT);

if ($submissionId <= 0) {
    http_response_code(400);
    exit('Invalid submission ID.');
}

// ── Fetch submission + verify teacher ownership ────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT subm.file_name, subm.file_path,
            a.section_subject_id
     FROM lms_assignment_submissions subm
     JOIN lms_assignments a ON a.id = subm.assignment_id
     WHERE subm.id = :sid
     LIMIT 1"
);
$stmt->execute(['sid' => $submissionId]);
$submRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submRow) {
    http_response_code(404);
    exit('Submission not found.');
}

// Verify teacher owns the subject that contains this assignment
$subject = verifyTeacherOwnsSubject($pdo, $userId, (int)$submRow['section_subject_id']);
if (!$subject) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Resolve the file path ──────────────────────────────────────────────────────
$storageRoot = realpath(__DIR__ . '/../private_uploads/lms_assignment_submissions');
if (!$storageRoot) {
    http_response_code(500);
    exit('Storage unavailable.');
}

$relPath  = str_replace('\\', '/', (string)$submRow['file_path']);
$absolute = realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath));

if (!$absolute
    || !str_starts_with($absolute, $storageRoot . DIRECTORY_SEPARATOR)
    || !is_file($absolute)) {
    http_response_code(404);
    exit('File not found on server.');
}

// ── Stream the file ────────────────────────────────────────────────────────────
$originalName = basename((string)$submRow['file_name']);
$finfo        = new finfo(FILEINFO_MIME_TYPE);
$mimeType     = (string)$finfo->file($absolute) ?: 'application/octet-stream';
$fileSize     = filesize($absolute);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');

readfile($absolute);
exit;
