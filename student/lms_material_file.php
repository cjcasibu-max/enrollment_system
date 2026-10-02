<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$materialId = filter_input(INPUT_GET, 'material_id', FILTER_VALIDATE_INT);

if (!$subjectId || !$materialId) {
    http_response_code(400);
    exit('Invalid learning material request.');
}

$material = fetchLmsMaterialForStudentSubject(
    $pdo,
    (int)$lmsStudent['student_id'],
    $subjectId,
    $materialId
);
if (!$material) {
    http_response_code(404);
    exit('Learning material not found.');
}

try {
    recordLmsMaterialView($pdo, (int)$lmsStudent['student_id'], $subjectId, $materialId);
} catch (Throwable $e) {
    error_log('LMS material view update failed: ' . $e->getMessage());
}

$relativePath = str_replace('\\', '/', trim((string)$material['file_path']));
if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('#(^|/)\.\.?(/|$)#', $relativePath)) {
    http_response_code(404);
    exit('Learning material not found.');
}

$storageRoot = realpath(__DIR__ . '/../private_uploads/lms_materials');
$resolvedPath = $storageRoot
    ? realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
    : false;
if (!$storageRoot || !$resolvedPath || !is_file($resolvedPath) || !str_starts_with($resolvedPath, $storageRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Learning material not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string)$finfo->file($resolvedPath);
if (!preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', $mimeType)) {
    $mimeType = 'application/octet-stream';
}

$filename = basename((string)$material['file_name']);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'learning-material';
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($resolvedPath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($resolvedPath);
exit;
