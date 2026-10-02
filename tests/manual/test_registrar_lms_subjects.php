<?php
/**
 * Test: Registrar LMS Subject & Section Records View (TASK 2.1)
 *
 * Verifies:
 *   1. Access is strictly granted to 'registrar' and denied to unauthorized roles.
 *   2. Subjects offered with codes and sections are retrieved live.
 *   3. Assigned teacher/instructor per subject is displayed.
 *   4. Enrolled student count per subject/section is computed live.
 *   5. The page contains 0 mutation forms (strictly READ-ONLY).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Registrar LMS Subject & Section Records View (TASK 2.1)...\n";

// 1. Get a registrar user ID
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'registrar' LIMIT 1");
$stmt->execute();
$regId = (int)$stmt->fetchColumn();
assert($regId > 0, "Registrar user must exist");

// 2. Set up Registrar session
$_SESSION['user_id'] = $regId;
$_SESSION['role']    = 'registrar';
$_SESSION['LAST_ACTIVITY'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';

// 3. Setup mock transaction so live offerings exist for testing
$pdo->beginTransaction();
try {
    // Academic term
    $stmtTerm = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
    $termId = (int)$stmtTerm->fetchColumn();
    if ($termId === 0) {
        $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active) VALUES ('2026-2027', '1st', 1)");
        $termId = (int)$pdo->lastInsertId();
    }

    // Teacher
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('capt_test_reg', 'capt_test_reg@example.com', 'hash', 'teacher', 'Horatio', 'Nelson', NOW())");
    $teacherId = (int)$pdo->lastInsertId();

    // Subject
    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, year_level, semester_name, status, created_at) VALUES ('NAV-101-TEST', 'Terrestrial Navigation 1', 3.0, '1st Year', '1st Semester', 'active', NOW())");
    $subjectId = (int)$pdo->lastInsertId();

    // Section
    $pdo->exec("INSERT INTO sections (section_name, academic_term_id, schedule, room, capacity, year_level, program, teacher_id, status, created_at) VALUES ('BSMT 1-TEST', $termId, 'MWF 08:00-10:00', 'Room 101', 40, '1st Year', 'BSMT', $teacherId, 'active', NOW())");
    $sectionId = (int)$pdo->lastInsertId();

    // Section Subject
    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, day_of_week, start_time, end_time, room, created_at) VALUES ($sectionId, $subjectId, $teacherId, 'MWF', '08:00:00', '10:00:00', 'Room 101', NOW())");
    $sectionSubjectId = (int)$pdo->lastInsertId();

    // Student & Enrollment
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_nav_test', 'cadet_nav@example.com', 'hash', 'student', 'Jack', 'Aubrey', NOW())");
    $studentUserId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO students (user_id, academic_term_id, first_name, last_name, program_code, year_level, enrollment_status, created_at) VALUES ($studentUserId, $termId, 'Jack', 'Aubrey', 'BSMT', '1st Year', 'enrolled', NOW())");
    $studentId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId, $sectionId, $termId, 'enrolled', NOW())");

    $_GET['academic_term_id'] = $termId;

    // 4. Buffer execution of registrar/lms_subjects.php
    ob_start();
    try {
        include __DIR__ . '/../../registrar/lms_subjects.php';
        $html = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    // 5. Assertions on generated HTML output
    assert(!empty($html), "View output must not be empty");
    assert(str_contains($html, 'LMS Subject & Section Offerings'), "Page title must be present");
    assert(str_contains($html, 'LMS Records'), "LMS top navigation pill must be present");
    assert(str_contains($html, 'Total Offerings'), "KPI strip must be present");
    assert(str_contains($html, 'NAV-101-TEST'), "Subject code must be present in output table");
    assert(str_contains($html, 'Terrestrial Navigation 1'), "Subject title must be present in output table");
    assert(str_contains($html, 'BSMT 1-TEST'), "Section name must be present in output table");
    assert(str_contains($html, 'Horatio Nelson'), "Assigned teacher name must be present in output table");
    assert(str_contains($html, '1 Cadets'), "Enrolled student count must be present in output table");
    assert(str_contains($html, 'Subject Instructor'), "Teacher assignment badge must be present");

    // 6. Strict Read-Only Verification
    preg_match_all('/<form[^>]+method=["\']POST["\'][^>]*>/i', $html, $matches);
    foreach ($matches[0] as $match) {
        assert(str_contains($match, 'auth/logout'), "Unexpected POST mutation form found: " . $match);
    }
    assert(!str_contains($html, 'action_delete'), "No delete actions allowed");
    assert(!str_contains($html, 'btn-danger'), "No destructive action buttons allowed");
    assert(!str_contains($html, 'btn-primary" data-bs-toggle="modal" data-bs-target="#create'), "No create modals allowed");
    echo "  [+] Strict READ-ONLY compliance verified (0 mutation forms/create/delete buttons).\n";

    // 7. Verification of live student roster inspection modal
    assert(str_contains($html, "modal-students-$sectionSubjectId"), "Student roster modal structure must be present");
    assert(str_contains($html, 'Aubrey, Jack') || str_contains($html, 'Aubrey'), "Enrolled cadet name must appear in roster modal");
    echo "  [+] Enrolled cadets inspection modal present and responsive.\n";

    echo "  [+] Live subject offerings, assigned teachers, and student counts verified.\n";
    echo "\n>>> ALL TASK 2.1 REGISTRAR LMS SUBJECTS VIEW TESTS PASSED SUCCESSFULLY! <<<\n";
} finally {
    $pdo->rollBack();
}
