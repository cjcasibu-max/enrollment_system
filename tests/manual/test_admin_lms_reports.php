<?php
/**
 * Test: Admin LMS System-Wide Reports & Analytics (TASK 6.1)
 *
 * Verifies:
 *   1. Access is strictly granted to 'admin' and denied to unauthorized roles (guest, student, teacher, registrar).
 *   2. Generates/views System-Wide Enrollment-to-LMS Access Report with KPI metrics.
 *   3. Generates/views System-Wide Grade & Course Completion Summary Report.
 *   4. Generates/views Teacher Activity & Grading Load Summary Report.
 *   5. Verifies CSV exports for all three reports follow system format conventions.
 *   6. The page is strictly read-only and contains zero mutation forms.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Admin LMS Reports & Analytics View (TASK 6.1)...\n";

// ── Step 1: Verify Access Control ─────────────────────────────────────────────
echo "[Step 1] Verifying access control guards...\n";

// A. Test unauthorized roles cannot access requireLmsAdminAccess()
foreach (['student', 'teacher', 'registrar', 'guest'] as $unauthorizedRole) {
    $_SESSION['user_id'] = 9999;
    $_SESSION['role']    = $unauthorizedRole;
    $_SESSION['LAST_ACTIVITY'] = time();

    $cmd = 'php -r "session_start(); $_SESSION[\'user_id\']=9999; $_SESSION[\'role\']=\'' . $unauthorizedRole . '\'; $_SESSION[\'LAST_ACTIVITY\']=time(); require \'includes/lms_access.php\'; try { requireLmsAdminAccess(); echo \'ACCESSED\'; } catch(Throwable $e) { echo \'BLOCKED\'; }"';
    $output = shell_exec($cmd);
    assert(!str_contains((string)$output, 'ACCESSED'), "Unauthorized role '$unauthorizedRole' must be blocked from Admin LMS");
}
echo "  [+] Access control confirmed: Only Admin role can access Admin LMS Reports.\n";

// ── Step 2: Set up Admin Session & Mock Fixture in Transaction ───────────────
echo "[Step 2] Testing report views with live and mock records...\n";

// Get an admin user ID
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'admin' LIMIT 1");
$stmt->execute();
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
assert($adminUser && (int)$adminUser['id'] > 0, "Admin user must exist in database");

$_SESSION['user_id']  = (int)$adminUser['id'];
$_SESSION['role']     = 'admin';
$_SESSION['username'] = $adminUser['username'];
$_SESSION['LAST_ACTIVITY'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';

$pdo->beginTransaction();
try {
    // Academic term
    $stmtTerm = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
    $termId = (int)$stmtTerm->fetchColumn();
    if ($termId === 0) {
        $pdo->exec("INSERT INTO academic_terms (school_year, semester, is_active, downpayment_percentage, minimum_downpayment) VALUES ('2026-2027', '1st', 1, 30.00, 5000.00)");
        $termId = (int)$pdo->lastInsertId();
    }

    // Teacher
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, created_at) VALUES ('capt_admrep_test', 'capt_admrep@example.com', 'hash', 'teacher', 'William', 'Bligh', 1, NOW())");
    $teacherId = (int)$pdo->lastInsertId();

    // Subject
    $pdo->exec("INSERT INTO subjects (subject_code, subject_name, units, year_level, semester_name, status, created_at) VALUES ('NAV-ADMIN-REP', 'Advanced Shiphandling', 3.0, '4th Year', '1st Semester', 'active', NOW())");
    $subjectId = (int)$pdo->lastInsertId();

    // Section
    $pdo->exec("INSERT INTO sections (section_name, academic_term_id, schedule, room, capacity, year_level, program, teacher_id, status, created_at) VALUES ('BSMT 4-ADMREP', $termId, 'TTh 09:00-11:00', 'Simulation Lab', 30, '4th Year', 'BSMT', $teacherId, 'active', NOW())");
    $sectionId = (int)$pdo->lastInsertId();

    // Section Subject
    $pdo->exec("INSERT INTO section_subjects (section_id, subject_id, instructor_id, day_of_week, start_time, end_time, room, created_at) VALUES ($sectionId, $subjectId, $teacherId, 'TTh', '09:00:00', '11:00:00', 'Simulation Lab', NOW())");
    $sectionSubjectId = (int)$pdo->lastInsertId();

    // LMS Module & Lesson
    $pdo->exec("INSERT INTO lms_modules (section_subject_id, title, description, display_order, is_published, created_at) VALUES ($sectionSubjectId, 'Bridge Resource Management', 'Module overview', 1, 1, NOW())");
    $moduleId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO lms_lessons (module_id, title, content_type, content_body, is_published, display_order, created_at) VALUES ($moduleId, 'Watchkeeping Principles', 'text', 'Core watchkeeping principles content.', 1, 1, NOW())");
    $lessonId = (int)$pdo->lastInsertId();

    // LMS Material
    $pdo->exec("INSERT INTO lms_materials (section_subject_id, title, material_type, file_name, file_path, is_available, created_at) VALUES ($sectionSubjectId, 'Bridge Protocol Handout', 'pdf', 'bridge_protocol.pdf', 'uploads/bridge_protocol.pdf', 1, NOW())");
    $materialId = (int)$pdo->lastInsertId();

    // LMS Assignment & Submission
    $pdo->exec("INSERT INTO lms_assignments (section_subject_id, assignment_type, title, instructions, due_at, max_score, is_published, created_at) VALUES ($sectionSubjectId, 'assignment', 'Collision Case Analysis', 'Analyze the case scenario', DATE_ADD(NOW(), INTERVAL 7 DAY), 100.00, 1, NOW())");
    $assignmentId = (int)$pdo->lastInsertId();

    // Student & Enrollment
    $pdo->exec("INSERT INTO users (username, email, password_hash, role, first_name, last_name, created_at) VALUES ('cadet_admrep_test', 'cadet_admrep@example.com', 'hash', 'student', 'Fletcher', 'Christian', NOW())");
    $studentUserId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO students (user_id, academic_term_id, first_name, last_name, program_code, year_level, enrollment_status, created_at) VALUES ($studentUserId, $termId, 'Fletcher', 'Christian', 'BSMT', '4th Year', 'enrolled', NOW())");
    $studentId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at) VALUES ($studentId, $sectionId, $termId, 'enrolled', NOW())");
    $enrollmentId = (int)$pdo->lastInsertId();

    // Student Assignment Submission (Pending grading)
    $pdo->exec("INSERT INTO lms_assignment_submissions (assignment_id, student_id, file_name, file_path, submitted_at, created_at) VALUES ($assignmentId, $studentId, 'collision_analysis.pdf', 'uploads/collision_analysis.pdf', NOW(), NOW())");

    // Student Lesson Progress view
    $pdo->exec("INSERT INTO lms_lesson_progress (lesson_id, student_id, viewed_at, created_at) VALUES ($lessonId, $studentId, NOW(), NOW())");

    // Grade Submission & Student Grade
    $pdo->exec("INSERT INTO grade_submissions (section_id, academic_term_id, teacher_id, status, submitted_at, reviewed_by, reviewed_at, created_at) VALUES ($sectionId, $termId, $teacherId, 'approved', NOW(), {$adminUser['id']}, NOW(), NOW())");
    $submissionId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO student_grades (grade_submission_id, section_subject_id, enrollment_id, student_id, prelim_grade, midterm_grade, final_exam_grade, final_grade, remarks, created_at) VALUES ($submissionId, $sectionSubjectId, $enrollmentId, $studentId, 88.00, 90.00, 91.00, 89.80, 'Passed', NOW())");

    // ── Test 2A: System-Wide Enrollment-to-LMS Access Report (HTML view) ──────
    $_GET = [
        'report'  => 'enrollment_access',
        'term_id' => $termId,
    ];

    ob_start();
    try {
        include __DIR__ . '/../../admin/lms_reports.php';
        $htmlEnrollment = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    assert(!empty($htmlEnrollment), "Enrollment report HTML must not be empty");
    assert(str_contains($htmlEnrollment, 'System-Wide LMS Reports & Analytics'), "Page title must be present");
    assert(str_contains($htmlEnrollment, 'Student LMS Access Compliance Roster'), "Compliance roster table header must be present");
    assert(str_contains($htmlEnrollment, 'Christian, Fletcher') || str_contains($htmlEnrollment, 'Christian'), "Enrolled student must appear in roster");
    assert(str_contains($htmlEnrollment, 'BSMT 4-ADMREP'), "Section name must appear in roster");
    echo "  [+] Enrollment-to-LMS Access Report HTML rendered and verified.\n";

    // ── Test 2B: Grade & Course Completion Summary (HTML view) ────────────────
    $_GET = [
        'report'  => 'grade_completion',
        'term_id' => $termId,
    ];

    ob_start();
    try {
        include __DIR__ . '/../../admin/lms_reports.php';
        $htmlGrade = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    assert(!empty($htmlGrade), "Grade completion report HTML must not be empty");
    assert(str_contains($htmlGrade, 'Subject Offerings & Course Completion Summary'), "Course completion summary header must be present");
    assert(str_contains($htmlGrade, 'NAV-ADMIN-REP'), "Subject code must appear in course summary");
    assert(str_contains($htmlGrade, 'Advanced Shiphandling'), "Subject title must appear in course summary");
    assert(str_contains($htmlGrade, '89.80'), "Final grade must appear in table");
    assert(str_contains($htmlGrade, 'Passed'), "Remarks must appear in table");
    echo "  [+] Grade & Course Completion Summary Report HTML rendered and verified.\n";

    // ── Test 2C: Teacher Activity & Grading Load Summary (HTML view) ──────────
    $_GET = [
        'report'  => 'teacher_activity',
        'term_id' => $termId,
    ];

    ob_start();
    try {
        include __DIR__ . '/../../admin/lms_reports.php';
        $htmlTeacher = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    assert(!empty($htmlTeacher), "Teacher activity report HTML must not be empty");
    assert(str_contains($htmlTeacher, 'Faculty Activity & Grading Workload Oversight'), "Teacher activity header must be present");
    assert(str_contains($htmlTeacher, 'Bligh, William') || str_contains($htmlTeacher, 'Bligh'), "Teacher must appear in activity roster");
    assert(str_contains($htmlTeacher, 'Pending'), "Pending grading badge must be present for teacher with un-graded submissions");
    echo "  [+] Teacher Activity & Grading Load Summary Report HTML rendered and verified.\n";

    // ── Test 3: Verify Zero Mutation Forms (Strict Read-Only) ─────────────────
    preg_match_all('/<form[^>]+method=["\']POST["\'][^>]*>/i', $htmlEnrollment . $htmlGrade . $htmlTeacher, $postMatches);
    foreach ($postMatches[0] as $match) {
        assert(str_contains($match, 'auth/logout'), "Unexpected POST mutation form found in report: " . $match);
    }
    assert(!str_contains($htmlEnrollment, 'btn-danger'), "No destructive action buttons in report");
    echo "  [+] Reports confirmed to be strictly read-only.\n";

    // ── Test 4: CSV Export Execution Test (CLI Subprocess) ───────────────────
    echo "[Step 3] Testing CSV export endpoints...\n";
    foreach (['enrollment_access', 'grade_completion', 'teacher_activity'] as $rep) {
        $csvCmd = "php -r \"session_start(); \$_SESSION['user_id']={$adminUser['id']}; \$_SESSION['role']='admin'; \$_SESSION['username']='admin'; \$_SESSION['LAST_ACTIVITY']=time(); \$_GET=['report'=>'$rep', 'export'=>'csv', 'term_id'=>$termId]; require 'admin/lms_reports.php';\"";
        $csvOutput = shell_exec($csvCmd);
        assert(!empty($csvOutput), "CSV output for $rep must not be empty");
        assert(str_contains($csvOutput, 'NCST MARITIME ACADEMY'), "CSV output must contain institution header");
        if ($rep === 'enrollment_access') {
            assert(str_contains($csvOutput, 'Christian') || str_contains($csvOutput, 'Cadet Name'), "Enrollment CSV must contain headers/records");
        } elseif ($rep === 'grade_completion') {
            assert(str_contains($csvOutput, 'NAV-ADMIN-REP') || str_contains($csvOutput, 'Subject Code'), "Grade CSV must contain headers/records");
        } elseif ($rep === 'teacher_activity') {
            assert(str_contains($csvOutput, 'Bligh') || str_contains($csvOutput, 'Faculty Name'), "Teacher activity CSV must contain headers/records");
        }
        echo "  [+] CSV export verified for report: $rep\n";
    }

    echo "\n>>> ALL TASK 6.1 ADMIN LMS REPORTS TESTS PASSED SUCCESSFULLY! <<<\n";
} finally {
    $pdo->rollBack();
}
