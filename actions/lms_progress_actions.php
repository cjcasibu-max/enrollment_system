<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$subjectId = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
$lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT);
if (!$subjectId || !$lessonId || !validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid lesson progress request.');
}

try {
    if (!recordLmsLessonView($pdo, (int)$lmsStudent['student_id'], $subjectId, $lessonId)) {
        http_response_code(404);
        exit('Lesson not found.');
    }
    http_response_code(204);
} catch (Throwable $e) {
    error_log('LMS lesson progress update failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to save lesson progress.');
}
