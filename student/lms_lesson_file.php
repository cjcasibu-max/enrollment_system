<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$lessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT);

if (!$subjectId || !$lessonId) {
    http_response_code(400);
    exit('Invalid lesson file request.');
}

$lesson = fetchLmsLessonForStudentSubject(
    $pdo,
    (int)$lmsStudent['student_id'],
    $subjectId,
    $lessonId
);
if (!$lesson || !in_array($lesson['content_type'], ['image', 'presentation', 'pdf', 'video'], true)) {
    http_response_code(404);
    exit('Lesson file not found.');
}

$relativePath = str_replace('\\', '/', trim((string)$lesson['content_path']));
if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('#(^|/)\.\.?(/|$)#', $relativePath)) {
    http_response_code(404);
    exit('Lesson file not found.');
}

$storageRoot = realpath(__DIR__ . '/../private_uploads/lms_lessons');
$resolvedPath = $storageRoot
    ? realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))
    : false;
if (!$storageRoot || !$resolvedPath || !is_file($resolvedPath) || !str_starts_with($resolvedPath, $storageRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Lesson file not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string)$finfo->file($resolvedPath);
$allowedMimeTypes = [
    'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'presentation' => ['application/pdf', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'pdf' => ['application/pdf'],
    'video' => ['video/mp4', 'video/webm', 'video/ogg'],
];
if (!in_array($mimeType, $allowedMimeTypes[$lesson['content_type']], true)) {
    http_response_code(415);
    exit('Unsupported lesson file type.');
}

$filename = basename($resolvedPath);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'lesson-content';
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($resolvedPath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($resolvedPath);
exit;