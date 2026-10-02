<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/lms_access.php';

$studentId = 41;
$subjectId = 4; // BSMT-MT101

$course = fetchLmsSubjectForStudent($pdo, $studentId, $subjectId);
echo "fetchLmsSubjectForStudent:\n";
print_r($course);

$materials = fetchLmsMaterialsForStudentSubject($pdo, $studentId, $subjectId);
echo "Materials count: " . count($materials) . "\n";

$assignments = fetchLmsAssignmentsForStudentSubject($pdo, $studentId, $subjectId);
echo "Assignments count: " . count($assignments) . "\n";

$quizzes = fetchLmsQuizzesForStudentSubject($pdo, $studentId, $subjectId);
echo "Quizzes count: " . count($quizzes) . "\n";

$announcements = fetchLmsAnnouncementsForStudentSubject($pdo, $studentId, $subjectId);
echo "Announcements count: " . count($announcements) . "\n";
