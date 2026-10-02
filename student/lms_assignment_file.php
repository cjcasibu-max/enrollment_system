<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$fileId = filter_input(INPUT_GET, 'file_id', FILTER_VALIDATE_INT);

if (!$subjectId || !$fileId) {
    http_response_code(400);
    exit('Invalid assignment file request.');
}

$assignmentFile = fetchLmsAssignmentFileForStudentSubject(
    $pdo,
    (int)$lmsStudent['student_id'],
    $subjectId,
    $fileId
);
if (!$assignmentFile) {
    http_response_code(404);
    exit('Assignment file not found.');
}

$relativePath = str_replace('\\', '/', trim((string)$assignmentFile['file_path']));
if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('#(^|/)\.\.?(/|$)#', $relativePath)) {
    http_response_code(404);
    exit('Assignment file not found.');
}

$storageRoot = realpath(__DIR__ . '/../private_uploads/lms_assignment_files');
$resolvedPath = $storageRoot
    ? realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
    : false;
if (!$storageRoot || !$resolvedPath || !is_file($resolvedPath) || !str_starts_with($resolvedPath, $storageRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Assignment file not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string)$finfo->file($resolvedPath);
if (!preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', $mimeType)) {
    $mimeType = 'application/octet-stream';
}

$filename = basename((string)$assignmentFile['file_name']);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'assignment-file';
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($resolvedPath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($resolvedPath);
exit;