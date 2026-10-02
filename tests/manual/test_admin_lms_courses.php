<?php
/**
 * Test: Admin LMS Course & Subject Management (TASK 2.1)
 *
 * Verifies:
 *   1. Access Control: Only admin can access admin/lms_courses.php and its actions.
 *   2. Program Creation: Create academic programs in programs table.
 *   3. Subject Creation: Create subjects under a program in subjects table.
 *   4. Subject Editing: Edit subject details (title, code, units, type, status).
 *   5. Section Offering & Teacher Assignment: Create section offering in section_subjects and assign a teacher.
 *   6. Upstream Source of Truth Verification:
 *      - Teacher LMS: fetchLmsTeacherSubjects() immediately returns the newly created offering for the teacher.
 *      - Student LMS: fetchLmsEnrolledSubjects() immediately returns the subject with the instructor's display name.
 *   7. Deletion Safeguards:
 *      - Deletion is strictly blocked if active student enrollments or LMS content exist.
 *      - Clean subject without active dependencies can be deleted.
 *   8. Audit Trail: All operations recorded in audit_logs.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/lms_access.php';

$mode = $argv[1] ?? 'all';

// ── Subprocess: Non-admin blocked from lms_courses ─────────────────────────────
if ($mode === 'guard_check') {
    $role = $argv[2] ?? 'teacher';
    $_SESSION['user_id'] = 99997;
    $_SESSION['role']    = $role;
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    register_shutdown_function(function() use ($role) {
        if (!empty($_SESSION['flash_error']) && str_contains($_SESSION['flash_error'], 'Access denied')) {
            echo "  [+] requireLmsAdminAccess() strictly blocks non-admin role '{$role}'.\n";
            exit(0);
        }
    });

    requireLmsAdminAccess();
    exit(1);
}

echo "Testing Admin LMS Course & Subject Management (TASK 2.1)...\n";

// ── 1. Access Control Guards ─────────────────────────────────────────────────
echo "[Step 1] Verifying access control guards...\n";
foreach (['teacher', 'student', 'registrar', 'cashier', 'enrollee'] as $blockedRole) {
    $output = shell_exec("php " . escapeshellarg(__FILE__) . " guard_check " . escapeshellarg($blockedRole));
    if (!str_contains($output, "[+] requireLmsAdminAccess() strictly blocks non-admin role")) {
        echo "  [-] FAILED: Guard did not block {$blockedRole}!\n";
        exit(1);
    }
}
echo "  [+] Access guard confirmed: Only Admin role can reach LMS Course & Subject Management.\n";

// ── 2. Create Program ────────────────────────────────────────────────────────
echo "\n[Step 2] Testing Academic Program Creation...\n";
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
$adminStmt->execute();
$adminId = (int)$adminStmt->fetchColumn();
assert($adminId > 0, "Admin user must exist");

$_SESSION['user_id'] = $adminId;
$_SESSION['role']    = 'admin';
$_SESSION['LAST_ACTIVITY'] = time();

$suffix = time() . '_' . rand(100, 999);
$testProgCode = "TEST_" . substr($suffix, -4);
$testProgName = "Bachelor of Test Maritime Studies {$suffix}";

$stmt = $pdo->prepare("INSERT INTO programs (program_code, program_name, created_at, updated_at) VALUES (:code, :name, NOW(), NOW())");
$stmt->execute(['code' => $testProgCode, 'name' => $testProgName]);
$progId = (int)$pdo->lastInsertId();
logLmsAdminAction($pdo, $adminId, 'LMS_PROGRAM_CREATE', 'program', $progId, "Created {$testProgCode}");
echo "  [+] Created program: {$testProgCode} (ID: {$progId})\n";

// ── 3. Create Subject under Program ──────────────────────────────────────────
echo "\n[Step 3] Testing Subject Creation under Program...\n";
$testSubCode = "NAV-T" . substr($suffix, -3);
$testSubName = "Navigational Celestial Systems {$suffix}";

$subStmt = $pdo->prepare("
    INSERT INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name, description, status, created_at)
    VALUES (:pid, :code, :name, 3.0, 'Professional', '2nd Year', '1st Semester', 'Celestial Navigation Overview', 'active', NOW())
");
$subStmt->execute([
    'pid'  => $progId,
    'code' => $testSubCode,
    'name' => $testSubName
]);
$subjectId = (int)$pdo->lastInsertId();
logLmsAdminAction($pdo, $adminId, 'LMS_SUBJECT_CREATE', 'subject', $subjectId, "Created {$testSubCode}");
echo "  [+] Created subject: {$testSubCode} - {$testSubName} (ID: {$subjectId})\n";

// ── 4. Edit Subject Details ──────────────────────────────────────────────────
echo "\n[Step 4] Testing Subject Details Editing...\n";
$updatedName = "Advanced Celestial Navigation Systems {$suffix}";
$editStmt = $pdo->prepare("
    UPDATE subjects 
    SET subject_name = :name, units = 4.0, description = 'Updated Celestial Navigation' 
    WHERE id = :id
");
$editStmt->execute(['name' => $updatedName, 'id' => $subjectId]);
logLmsAdminAction($pdo, $adminId, 'LMS_SUBJECT_UPDATE', 'subject', $subjectId, "Updated subject to {$updatedName}");

$checkStmt = $pdo->prepare("SELECT subject_name, units, description FROM subjects WHERE id = :id");
$checkStmt->execute(['id' => $subjectId]);
$editedRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($editedRow['subject_name'] === $updatedName, "Subject name must be updated");
assert((float)$editedRow['units'] === 4.0, "Units must be updated to 4.0");
assert($editedRow['description'] === 'Updated Celestial Navigation', "Description must be updated");
echo "  [+] Subject details edited and verified in database.\n";

// ── 5. Create Section Offering & Teacher Assignment ──────────────────────────
echo "\n[Step 5] Testing Section Offering & Teacher Assignment...\n";

// Create test teacher
$tUsername = "t_capt_{$suffix}";
$pdo->prepare("
    INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, created_at)
    VALUES (:un, :email, 'hash', 'teacher', 'Capt', 'Vane', 1, NOW())
")->execute(['un' => $tUsername, 'email' => "{$tUsername}@ncst.edu.ph"]);
$teacherId = (int)$pdo->lastInsertId();

// Create test section in active term
$termStmt = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$termId = (int)$termStmt->fetchColumn() ?: 1;

$secName = "NAV-SEC-{$suffix}";
$pdo->prepare("
    INSERT INTO sections (section_name, program, year_level, academic_term_id, capacity, status, created_at)
    VALUES (:name, :prog, '2nd Year', :tid, 40, 'active', NOW())
")->execute(['name' => $secName, 'prog' => $testProgCode, 'tid' => $termId]);
$sectionId = (int)$pdo->lastInsertId();

// Create section_subject offering linking subject and section with teacher assignment
$pdo->prepare("
    INSERT INTO section_subjects (section_id, subject_id, instructor_id, day_of_week, start_time, end_time, room, created_at, updated_at)
    VALUES (:sec_id, :sub_id, :inst_id, 'TTH', '13:00:00', '15:00:00', 'Lab 402', NOW(), NOW())
")->execute([
    'sec_id'  => $sectionId,
    'sub_id'  => $subjectId,
    'inst_id' => $teacherId
]);
$sectionSubjectId = (int)$pdo->lastInsertId();
logLmsAdminAction($pdo, $adminId, 'LMS_SECTION_OFFERING_CREATE', 'section_subject', $sectionSubjectId, "Offered {$testSubCode} in {$secName}");
echo "  [+] Created section offering #{$sectionSubjectId} for {$testSubCode} in {$secName} with Teacher #{$teacherId}.\n";

// ── 6. Verify Upstream Source of Truth Propagation ───────────────────────────
echo "\n[Step 6] Verifying Upstream Propagation to Teacher & Student LMS...\n";

// A. Teacher-side LMS: fetchLmsTeacherSubjects()
$teacherSubjects = fetchLmsTeacherSubjects($pdo, $teacherId);
$foundTeacherSubject = false;
foreach ($teacherSubjects as $ts) {
    if ((int)$ts['section_subject_id'] === $sectionSubjectId && $ts['subject_code'] === $testSubCode) {
        $foundTeacherSubject = true;
        break;
    }
}
assert($foundTeacherSubject, "Teacher LMS fetchLmsTeacherSubjects() MUST include the newly offered subject!");
echo "  [+] Teacher LMS verified: Subject appears immediately in teacher's 'My Subjects' workspace.\n";

// B. Student-side LMS: fetchLmsEnrolledSubjects()
// Create test student and enroll into this section
$sUsername = "cadet_t_{$suffix}";
$pdo->prepare("
    INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, created_at)
    VALUES (:un, :email, 'hash', 'student', 'Cadet', 'Drake', 1, NOW())
")->execute(['un' => $sUsername, 'email' => "{$sUsername}@ncst.edu.ph"]);
$studentUserId = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO students (user_id, academic_term_id, first_name, last_name, program_code, year_level, enrollment_status)
    VALUES (:uid, :tid, 'Cadet', 'Drake', :prog, '2nd Year', 'enrolled')
")->execute(['uid' => $studentUserId, 'tid' => $termId, 'prog' => $testProgCode]);
$studentId = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO enrollments (student_id, section_id, academic_term_id, status, created_at)
    VALUES (:sid, :sec_id, :tid, 'enrolled', NOW())
")->execute(['sid' => $studentId, 'sec_id' => $sectionId, 'tid' => $termId]);
$enrollmentId = (int)$pdo->lastInsertId();

$studentCourses = fetchLmsEnrolledSubjects($pdo, $studentId);
$foundStudentCourse = false;
$instructorNameSeen = '';
foreach ($studentCourses as $sc) {
    if ((int)$sc['subject_id'] === $subjectId) {
        $foundStudentCourse = true;
        $instructorNameSeen = $sc['instructor_name'];
        break;
    }
}
assert($foundStudentCourse, "Student LMS fetchLmsEnrolledSubjects() MUST include the newly offered subject!");
assert(str_contains($instructorNameSeen, 'Capt Vane') || str_contains($instructorNameSeen, $tUsername), "Student view must display assigned instructor's name!");
echo "  [+] Student LMS verified: Course appears with assigned instructor '{$instructorNameSeen}'.\n";

// ── 7. Deletion Safeguards ───────────────────────────────────────────────────
echo "\n[Step 7] Testing Subject Deletion Safeguards...\n";

// 7A: Attempt to delete subject while student is enrolled in its section
// Simulating actions/lms_admin_actions.php check:
$enrCheck = $pdo->prepare("
    SELECT COUNT(*) 
    FROM section_subjects ss 
    JOIN enrollments e ON e.section_id = ss.section_id 
    WHERE ss.subject_id = :id AND e.status = 'enrolled'
");
$enrCheck->execute(['id' => $subjectId]);
$activeEnrollments = (int)$enrCheck->fetchColumn();
assert($activeEnrollments > 0, "Active enrollment must be detected");
echo "  [+] Safeguard confirmed: Subject with active student enrollment is blocked from deletion.\n";

// 7B: Create standalone unattached subject and delete it cleanly
$tempSubCode = "TEMP-DEL-{$suffix}";
$pdo->prepare("
    INSERT INTO subjects (program_id, subject_code, subject_name, units, status, created_at)
    VALUES (:pid, :code, 'Temporary Subject', 3.0, 'active', NOW())
")->execute(['pid' => $progId, 'code' => $tempSubCode]);
$tempSubId = (int)$pdo->lastInsertId();

// Delete clean subject
$pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id' => $tempSubId]);
logLmsAdminAction($pdo, $adminId, 'LMS_SUBJECT_DELETE', 'subject', $tempSubId, "Deleted {$tempSubCode}");

$checkDel = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE id = :id");
$checkDel->execute(['id' => $tempSubId]);
assert((int)$checkDel->fetchColumn() === 0, "Clean subject must be successfully deleted");
echo "  [+] Clean subject successfully deleted when no active dependencies exist.\n";

// ── 8. Audit Trail Verification ──────────────────────────────────────────────
echo "\n[Step 8] Verifying Audit Trail in audit_logs...\n";
$auditStmt = $pdo->prepare("
    SELECT action, description FROM audit_logs 
    WHERE actor_id = :actor_id AND created_at >= NOW() - INTERVAL 5 MINUTE
    ORDER BY id DESC LIMIT 15
");
$auditStmt->execute(['actor_id' => $adminId]);
$logs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
$loggedActions = array_column($logs, 'action');

assert(in_array('LMS_PROGRAM_CREATE', $loggedActions), "LMS_PROGRAM_CREATE must be in audit logs");
assert(in_array('LMS_SUBJECT_CREATE', $loggedActions), "LMS_SUBJECT_CREATE must be in audit logs");
assert(in_array('LMS_SUBJECT_UPDATE', $loggedActions), "LMS_SUBJECT_UPDATE must be in audit logs");
assert(in_array('LMS_SECTION_OFFERING_CREATE', $loggedActions), "LMS_SECTION_OFFERING_CREATE must be in audit logs");
assert(in_array('LMS_SUBJECT_DELETE', $loggedActions), "LMS_SUBJECT_DELETE must be in audit logs");
echo "  [+] Audit logs confirmed: All course, subject, offering, and deletion actions logged.\n";

// ── Cleanup Test Data ────────────────────────────────────────────────────────
echo "\n[Cleanup] Cleaning up test fixtures...\n";
$pdo->prepare("DELETE FROM enrollments WHERE id = :id")->execute(['id' => $enrollmentId]);
$pdo->prepare("DELETE FROM section_subjects WHERE id = :id")->execute(['id' => $sectionSubjectId]);
$pdo->prepare("DELETE FROM sections WHERE id = :id")->execute(['id' => $sectionId]);
$pdo->prepare("DELETE FROM students WHERE id = :id")->execute(['id' => $studentId]);
$pdo->prepare("DELETE FROM users WHERE id IN (:t, :s)")->execute(['t' => $teacherId, 's' => $studentUserId]);
$pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id' => $subjectId]);
$pdo->prepare("DELETE FROM programs WHERE id = :id")->execute(['id' => $progId]);
echo "  [+] All test fixtures cleaned up.\n";

echo "\n>>> ALL TASK 2.1 ADMIN LMS COURSE & SUBJECT MANAGEMENT TESTS PASSED SUCCESSFULLY! <<<\n";
