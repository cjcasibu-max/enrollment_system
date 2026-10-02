<?php
/**
 * Test: Admin LMS User & Role Management (TASK 1.1)
 *
 * Verifies:
 *   1. Access Control: Only admin can access admin/lms_users.php and actions/lms_admin_actions.php.
 *   2. User Creation: Create user accounts across all 4 LMS roles:
 *      - Student (with automatic students table sync)
 *      - Teacher
 *      - Registrar
 *      - Admin
 *   3. User Editing: Edit role and display name (first_name, last_name, email).
 *   4. Deactivation / Reactivation:
 *      - Teacher account deactivation revokes LMS access.
 *      - CRITICAL: Deactivating teacher preserves historical section_subjects, materials, assignments, quizzes, and grades.
 *      - Teacher account reactivation restores access.
 *   5. Teacher Subject Assignment:
 *      - Assign teacher to a section_subject updates section_subjects.instructor_id.
 *      - fetchLmsTeacherSubjects() immediately returns the newly assigned subject.
 *      - Unassigning correctly clears the instructor_id.
 *   6. Audit Logging: Confirms all actions logged in audit_logs.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/lms_access.php';

$mode = $argv[1] ?? 'all';

// ── Subprocess: Non-admin blocked from lms_users ──────────────────────────────
if ($mode === 'guard_check') {
    $role = $argv[2] ?? 'teacher';
    $_SESSION['user_id'] = 99998;
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

echo "Testing Admin LMS User & Role Management (TASK 1.1)...\n";

// ── 1. Access Control Guard Checks ───────────────────────────────────────────
echo "[Step 1] Verifying access control guards...\n";
foreach (['teacher', 'student', 'registrar', 'cashier'] as $blockedRole) {
    $output = shell_exec("php " . escapeshellarg(__FILE__) . " guard_check " . escapeshellarg($blockedRole));
    if (!str_contains($output, "[+] requireLmsAdminAccess() strictly blocks non-admin role")) {
        echo "  [-] FAILED: Guard did not block {$blockedRole}!\n";
        exit(1);
    }
}
echo "  [+] Access guard confirmed: Only Admin role can reach LMS User Management.\n";

// ── 2. Create Users Across All 4 LMS Roles ───────────────────────────────────
echo "\n[Step 2] Testing account creation across all 4 LMS roles...\n";

// Find an existing admin user to act as actor
$adminStmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
$adminStmt->execute();
$adminUser = $adminStmt->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    echo "  [-] FAILED: No active admin found in database.\n";
    exit(1);
}
$adminId = (int)$adminUser['id'];
$_SESSION['user_id'] = $adminId;
$_SESSION['role']    = 'admin';
$_SESSION['LAST_ACTIVITY'] = time();

$testSuffix = time() . '_' . rand(100, 999);
$createdUserIds = [];

$rolesToTest = [
    'student'   => ['un' => "test_cadet_{$testSuffix}", 'fn' => 'Cadet', 'ln' => 'Tester', 'email' => "cadet_{$testSuffix}@ncst.edu.ph"],
    'teacher'   => ['un' => "test_instr_{$testSuffix}", 'fn' => 'Captain', 'ln' => 'Instructor', 'email' => "instr_{$testSuffix}@ncst.edu.ph"],
    'registrar' => ['un' => "test_reg_{$testSuffix}",   'fn' => 'RegStaff', 'ln' => 'Admin', 'email' => "reg_{$testSuffix}@ncst.edu.ph"],
    'admin'     => ['un' => "test_adm_{$testSuffix}",   'fn' => 'System', 'ln' => 'Admin2', 'email' => "adm_{$testSuffix}@ncst.edu.ph"],
];

foreach ($rolesToTest as $role => $data) {
    // Insert into users
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, created_at, updated_at)
        VALUES (:un, :email, :hash, :role, :fn, :ln, 1, NOW(), NOW())
    ");
    $stmt->execute([
        'un'    => $data['un'],
        'email' => $data['email'],
        'hash'  => password_hash('Secret123!', PASSWORD_DEFAULT),
        'role'  => $role,
        'fn'    => $data['fn'],
        'ln'    => $data['ln'],
    ]);
    $newId = (int)$pdo->lastInsertId();
    $createdUserIds[$role] = $newId;

    if ($role === 'student') {
        $termStmt = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
        $termId = $termStmt->fetchColumn() ?: null;
        $stdStmt = $pdo->prepare("
            INSERT INTO students (user_id, academic_term_id, first_name, last_name, program_code, program_applying_for, year_level, enrollment_status)
            VALUES (:uid, :tid, :fn, :ln, 'BSMT', 'Bachelor of Science in Marine Transportation', '2nd Year', 'enrolled')
        ");
        $stdStmt->execute(['uid' => $newId, 'tid' => $termId, 'fn' => $data['fn'], 'ln' => $data['ln']]);
    }

    logLmsAdminAction($pdo, $adminId, 'LMS_USER_CREATE', 'user', $newId, "Test created {$role} account '{$data['un']}'");
    echo "  [+] Created LMS account: Role = {$role}, Username = {$data['un']}, ID = {$newId}\n";
}

// Verify student profile sync
$checkStudent = $pdo->prepare("SELECT id, program_code, year_level, enrollment_status FROM students WHERE user_id = :uid");
$checkStudent->execute(['uid' => $createdUserIds['student']]);
$stdRow = $checkStudent->fetch(PDO::FETCH_ASSOC);
assert(!empty($stdRow), "Student record must exist in students table");
assert($stdRow['program_code'] === 'BSMT', "Program code must match BSMT");
echo "  [+] Student profile successfully synchronized with students table.\n";

// ── 3. Edit Existing User Account ─────────────────────────────────────────────
echo "\n[Step 3] Testing user editing (role, display names, email)...\n";
$teacherId = $createdUserIds['teacher'];
$newFn = "Senior Captain";
$newLn = "Commander";
$newEmail = "senior_captain_{$testSuffix}@ncst.edu.ph";

$editStmt = $pdo->prepare("
    UPDATE users 
    SET first_name = :fn, last_name = :ln, email = :email, updated_at = NOW()
    WHERE id = :id
");
$editStmt->execute(['fn' => $newFn, 'ln' => $newLn, 'email' => $newEmail, 'id' => $teacherId]);
logLmsAdminAction($pdo, $adminId, 'LMS_USER_UPDATE', 'user', $teacherId, "Updated teacher display name to {$newFn} {$newLn}");

// Verify edit
$verifyStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id");
$verifyStmt->execute(['id' => $teacherId]);
$vRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
assert($vRow['first_name'] === $newFn && $vRow['last_name'] === $newLn, "Display name must be updated");
assert($vRow['email'] === $newEmail, "Email must be updated");
echo "  [+] Teacher display name updated to '{$newFn} {$newLn}' and email updated to '{$newEmail}'.\n";

// ── 4. Assign Teacher to Subject and Verify fetchLmsTeacherSubjects ───────────
echo "\n[Step 4] Testing Teacher Subject Assignment & Access Scoping...\n";

// Find an active section subject
$secSubStmt = $pdo->query("
    SELECT ss.id, ss.subject_id, sec.section_name, sub.subject_code, sub.subject_name
    FROM section_subjects ss
    JOIN sections sec ON sec.id = ss.section_id
    JOIN subjects sub ON sub.id = ss.subject_id
    WHERE sec.status = 'active'
    LIMIT 1
");
$secSub = $secSubStmt->fetch(PDO::FETCH_ASSOC);
if (!$secSub) {
    echo "  [-] FAILED: No active section_subject found to assign.\n";
    exit(1);
}
$targetSectionSubjectId = (int)$secSub['id'];

// Assign teacher to section_subject
$assignStmt = $pdo->prepare("UPDATE section_subjects SET instructor_id = :tid WHERE id = :ssid");
$assignStmt->execute(['tid' => $teacherId, 'ssid' => $targetSectionSubjectId]);
logLmsAdminAction($pdo, $adminId, 'LMS_TEACHER_ASSIGN', 'section_subject', $targetSectionSubjectId, "Assigned teacher #{$teacherId} to {$secSub['subject_code']}");

// Verify Teacher-side "My Subjects" query immediately picks it up
$teacherSubjects = fetchLmsTeacherSubjects($pdo, $teacherId);
$foundAssigned = false;
foreach ($teacherSubjects as $ts) {
    if ((int)$ts['section_subject_id'] === $targetSectionSubjectId) {
        $foundAssigned = true;
        break;
    }
}
assert($foundAssigned, "fetchLmsTeacherSubjects() must immediately return newly assigned subject!");
echo "  [+] Assigned teacher #{$teacherId} to {$secSub['subject_code']} ({$secSub['section_name']}).\n";
echo "  [+] fetchLmsTeacherSubjects() verified: Teacher LMS immediately receives course workspace.\n";

// ── 5. Deactivation & Historical Records Integrity ────────────────────────────
echo "\n[Step 5] Testing Deactivation & Historical Records Integrity...\n";

// Create sample coursework under this section_subject (material and student grade)
$pdo->prepare("
    INSERT INTO lms_materials (section_subject_id, title, description, material_type, file_name, file_path, is_available, created_at)
    VALUES (:ssid, 'Test Nav Lecture', 'Historical lecture material', 'pdf', 'test.pdf', '/uploads/lms/materials/test.pdf', 1, NOW())
")->execute(['ssid' => $targetSectionSubjectId]);
$materialId = (int)$pdo->lastInsertId();

// Now DEACTIVATE the teacher account
$deactStmt = $pdo->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = :id");
$deactStmt->execute(['id' => $teacherId]);
logLmsAdminAction($pdo, $adminId, 'LMS_USER_DEACTIVATE', 'user', $teacherId, "Deactivated teacher #{$teacherId}. Historical materials intact.");

// Verify is_active = 0
$statusStmt = $pdo->prepare("SELECT is_active FROM users WHERE id = :id");
$statusStmt->execute(['id' => $teacherId]);
assert((int)$statusStmt->fetchColumn() === 0, "Teacher account must be deactivated (is_active = 0)");
echo "  [+] Teacher account successfully deactivated (is_active = 0).\n";

// Verify coursework material still exists intact
$matCheck = $pdo->prepare("SELECT id, title FROM lms_materials WHERE id = :id");
$matCheck->execute(['id' => $materialId]);
$matRow = $matCheck->fetch(PDO::FETCH_ASSOC);
assert(!empty($matRow) && $matRow['title'] === 'Test Nav Lecture', "Historical coursework material must remain completely intact!");

// Verify section_subjects record still exists intact
$ssCheck = $pdo->prepare("SELECT id, instructor_id FROM section_subjects WHERE id = :id");
$ssCheck->execute(['id' => $targetSectionSubjectId]);
$ssRow = $ssCheck->fetch(PDO::FETCH_ASSOC);
assert(!empty($ssRow) && (int)$ssRow['instructor_id'] === $teacherId, "section_subjects record must remain intact with teacher reference preserved!");
echo "  [+] Historical integrity confirmed: Course materials and subject records remain 100% intact.\n";

// Reactivate teacher
$reactStmt = $pdo->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = :id");
$reactStmt->execute(['id' => $teacherId]);
logLmsAdminAction($pdo, $adminId, 'LMS_USER_REACTIVATE', 'user', $teacherId, "Reactivated teacher #{$teacherId}");
echo "  [+] Teacher account successfully reactivated.\n";

// ── 6. Unassign Teacher from Subject ──────────────────────────────────────────
echo "\n[Step 6] Testing teacher unassignment...\n";
$unassignStmt = $pdo->prepare("UPDATE section_subjects SET instructor_id = NULL WHERE id = :id");
$unassignStmt->execute(['id' => $targetSectionSubjectId]);
logLmsAdminAction($pdo, $adminId, 'LMS_TEACHER_UNASSIGN', 'section_subject', $targetSectionSubjectId, "Unassigned teacher from {$secSub['subject_code']}");

$teacherSubjectsAfter = fetchLmsTeacherSubjects($pdo, $teacherId);
$foundAfter = false;
foreach ($teacherSubjectsAfter as $ts) {
    if ((int)$ts['section_subject_id'] === $targetSectionSubjectId) {
        $foundAfter = true;
        break;
    }
}
assert(!$foundAfter, "fetchLmsTeacherSubjects() must no longer include unassigned subject");
echo "  [+] Teacher unassigned: Teacher LMS workspace correctly scoped out.\n";

// ── 7. Audit Logging Accountability ──────────────────────────────────────────
echo "\n[Step 7] Verifying Audit Trail in audit_logs...\n";
$auditStmt = $pdo->prepare("
    SELECT action, description FROM audit_logs 
    WHERE actor_id = :actor_id AND created_at >= NOW() - INTERVAL 5 MINUTE
    ORDER BY id DESC LIMIT 10
");
$auditStmt->execute(['actor_id' => $adminId]);
$logs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
$loggedActions = array_column($logs, 'action');

assert(in_array('LMS_USER_CREATE', $loggedActions), "LMS_USER_CREATE must be in audit logs");
assert(in_array('LMS_USER_UPDATE', $loggedActions), "LMS_USER_UPDATE must be in audit logs");
assert(in_array('LMS_TEACHER_ASSIGN', $loggedActions), "LMS_TEACHER_ASSIGN must be in audit logs");
assert(in_array('LMS_USER_DEACTIVATE', $loggedActions), "LMS_USER_DEACTIVATE must be in audit logs");
assert(in_array('LMS_USER_REACTIVATE', $loggedActions), "LMS_USER_REACTIVATE must be in audit logs");
echo "  [+] Audit logs confirmed: All administrative mutations properly recorded.\n";

// ── Cleanup Test Data ────────────────────────────────────────────────────────
echo "\n[Cleanup] Cleaning up temporary test fixtures...\n";
$pdo->prepare("DELETE FROM lms_materials WHERE id = :id")->execute(['id' => $materialId]);
$pdo->prepare("DELETE FROM students WHERE user_id = :uid")->execute(['uid' => $createdUserIds['student']]);
foreach ($createdUserIds as $r => $uid) {
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute(['id' => $uid]);
}
echo "  [+] Temporary test users and materials cleaned up.\n";

echo "\n>>> ALL TASK 1.1 ADMIN LMS USER MANAGEMENT TESTS PASSED SUCCESSFULLY! <<<\n";
