<?php
/**
 * Test LMS Access and My Courses across all 18 demo students + 1 unenrolled student
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/lms_access.php';

echo "=== VERIFYING LMS ACCESS FOR ALL DEMO STUDENTS ===\n\n";

$studentsStmt = $pdo->query("
    SELECT u.id AS user_id, u.username, u.email, s.id AS student_id, s.first_name, s.last_name, s.program_code, sec.section_name
    FROM users u
    JOIN students s ON s.user_id = u.id
    LEFT JOIN enrollments e ON e.student_id = s.id AND e.status = 'enrolled'
    LEFT JOIN sections sec ON sec.id = e.section_id
    WHERE u.email LIKE '%@demo.com' AND u.role = 'student'
    ORDER BY u.id ASC
");
$allDemoStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

$passedCount = 0;
$blockedExpectedCount = 0;

foreach ($allDemoStudents as $s) {
    $uId = (int)$s['user_id'];
    $stId = (int)$s['student_id'];
    $record = fetchLmsStudentAccessRecord($pdo, $uId);
    $meets = lmsStudentMeetsAccessRequirements($record);
    $subjects = fetchLmsEnrolledSubjects($pdo, $stId);

    if ($s['username'] === 'demo_student0') {
        // Expected blocked
        if (!$meets && count($subjects) === 0) {
            $blockedExpectedCount++;
            echo "  [PASS] {$s['username']} ({$s['first_name']}): Correctly BLOCKED from LMS (Gate enforced, 0 courses).\n";
        } else {
            echo "  [FAIL] {$s['username']}: Should be blocked but was not!\n";
        }
        continue;
    }

    if ($meets && count($subjects) === 5) {
        $passedCount++;
        echo "  [PASS] {$s['username']} ({$s['first_name']} {$s['last_name']} - {$s['section_name']}): Access GRANTED. 5 enrolled courses in My Courses.\n";
    } else {
        echo "  [FAIL] {$s['username']}: Meets=" . ($meets ? 'YES' : 'NO') . ", Courses=" . count($subjects) . "\n";
        print_r($record);
    }
}

echo "\nSummary: {$passedCount}/18 Enrolled Demo Students Granted Access. {$blockedExpectedCount}/1 Unenrolled Student Blocked.\n";

// Detailed check for 3 distinct students across different sections/courses
$sampleStudents = ['demo_student1', 'demo_student7', 'demo_student13'];
echo "\n=== DETAILED VERIFICATION FOR 3 SAMPLE STUDENTS ACROSS SECTIONS ===\n";

foreach ($sampleStudents as $sUser) {
    $uRow = $pdo->query("SELECT u.id, s.id AS student_id, s.first_name, s.last_name FROM users u JOIN students s ON s.user_id = u.id WHERE u.username = '$sUser'")->fetch(PDO::FETCH_ASSOC);
    $subjs = fetchLmsEnrolledSubjects($pdo, (int)$uRow['student_id']);
    echo "\n------------------------------------------------------------\n";
    echo "Student: {$uRow['first_name']} {$uRow['last_name']} ({$sUser})\n";
    echo "Courses in My Courses (" . count($subjs) . "):\n";
    foreach ($subjs as $sub) {
        echo "  * [{$sub['subject_code']}] {$sub['subject_name']} | Sec: {$sub['section_name']} | Prof: {$sub['instructor_name']} | Sched: {$sub['day_of_week']} {$sub['start_time']}-{$sub['end_time']} ({$sub['room']})\n";
    }

    // Check course items for the first subject
    $firstSub = $subjs[0];
    $mats = fetchLmsMaterialsForStudentSubject($pdo, (int)$uRow['student_id'], (int)$firstSub['subject_id']);
    $assigns = fetchLmsAssignmentsForStudentSubject($pdo, (int)$uRow['student_id'], (int)$firstSub['subject_id']);
    $quizzes = fetchLmsQuizzesForStudentSubject($pdo, (int)$uRow['student_id'], (int)$firstSub['subject_id']);
    $announces = fetchLmsAnnouncementsForStudentSubject($pdo, (int)$uRow['student_id'], (int)$firstSub['subject_id']);

    echo "  Course content for {$firstSub['subject_code']}:\n";
    echo "    - Materials: " . count($mats) . "\n";
    echo "    - Assignments: " . count($assigns) . "\n";
    echo "    - Quizzes: " . count($quizzes) . "\n";
    echo "    - Announcements: " . count($announces) . "\n";
}

echo "\n[ALL STUDENT PORTAL LMS CHECKS COMPLETED]\n";
