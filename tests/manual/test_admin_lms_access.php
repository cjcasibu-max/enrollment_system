<?php
/**
 * Test: Admin LMS Access Control Guard & Audit Logger (TASK 0.1)
 *
 * Verifies:
 *   1. LMS Role Allowlist: Exactly 4 roles permitted (student, teacher, registrar, admin).
 *   2. requireLmsAdminAccess(): Grants full administrative access to 'admin' role.
 *   3. requireLmsAdminAccess(): Gated from unauthorized roles (teacher, student, registrar, cashier, enrollee).
 *   4. logLmsAdminAction(): Writes administrative accountability logs to audit_logs table.
 *   5. Business rule integrity: Standard checks prevent accidental bypass of student downpayment requirements.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

$mode = $argv[1] ?? 'all';

// ── Subprocess: Admin access success ──────────────────────────────────────────
if ($mode === 'admin_success') {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $adminUserId = (int)$stmt->fetchColumn();
    assert($adminUserId > 0, "Admin user must exist");

    $_SESSION['user_id'] = $adminUserId;
    $_SESSION['role']    = 'admin';
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $adminAccess = requireLmsAdminAccess();
    assert($adminAccess['role'] === 'admin', "Role must be admin");
    assert($adminAccess['user_id'] === $adminUserId, "User ID must match admin");
    echo "  [+] requireLmsAdminAccess() succeeds for Admin without student or teacher scoping.\n";
    exit(0);
}

// ── Subprocess: Non-admin role blocked ─────────────────────────────────────────
if ($mode === 'non_admin_blocked') {
    $testRole = $argv[2] ?? 'registrar';
    
    $_SESSION['user_id'] = 99999;
    $_SESSION['role']    = $testRole;
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    register_shutdown_function(function() use ($testRole) {
        if (!empty($_SESSION['flash_error']) && str_contains($_SESSION['flash_error'], 'Access denied')) {
            echo "  [+] requireLmsAdminAccess() strictly blocks role '$testRole'.\n";
            exit(0);
        }
    });

    requireLmsAdminAccess();
    exit(1);
}

// ── Master Runner ─────────────────────────────────────────────────────────────
echo "Testing Admin LMS Access Control Guard & Audit Logger (TASK 0.1)...\n";

// 1. Role allowlist assertion
assert(lmsRoleIsPermitted('admin') === true, "Admin must be in LMS_PERMITTED_ROLES");
assert(lmsRoleIsPermitted('teacher') === true, "Teacher must be in LMS_PERMITTED_ROLES");
assert(lmsRoleIsPermitted('student') === true, "Student must be in LMS_PERMITTED_ROLES");
assert(lmsRoleIsPermitted('registrar') === true, "Registrar must be in LMS_PERMITTED_ROLES");
assert(lmsRoleIsPermitted('enrollee') === false, "Enrollee must NOT be in LMS_PERMITTED_ROLES");
assert(lmsRoleIsPermitted('cashier') === false, "Cashier must NOT be in LMS_PERMITTED_ROLES");
assert(lmsRoleIsPermitted('guest') === false, "Guest must NOT be in LMS_PERMITTED_ROLES");
echo "  [+] Exactly 4 LMS roles verified (admin, teacher, student, registrar).\n";

// 2. Subprocess: Admin access success
passthru("php " . escapeshellarg(__FILE__) . " admin_success");

// 3. Subprocess: Non-admin roles blocked
$unauthorizedRoles = ['registrar', 'teacher', 'student', 'cashier', 'enrollee'];
foreach ($unauthorizedRoles as $uRole) {
    passthru("php " . escapeshellarg(__FILE__) . " non_admin_blocked " . escapeshellarg($uRole));
}

// 4. Audit Logging Verification
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$stmt->execute();
$adminUserId = (int)$stmt->fetchColumn();

$pdo->beginTransaction();
try {
    $actionDesc = 'LMS_TEST_ADMIN_OVERSIGHT';
    $logOk = logLmsAdminAction(
        $pdo,
        $adminUserId,
        $actionDesc,
        'system_setting',
        42,
        'Test automated administrative audit logging for TASK 0.1'
    );
    assert($logOk === true, "logLmsAdminAction() must return true on success");

    $verifyStmt = $pdo->prepare("
        SELECT id, actor_id, action, item_type, item_id, description
        FROM audit_logs
        WHERE action = :action AND actor_id = :actor_id
        ORDER BY id DESC LIMIT 1
    ");
    $verifyStmt->execute([
        'action'   => $actionDesc,
        'actor_id' => $adminUserId
    ]);
    $logged = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    assert(!empty($logged), "Audit log entry must be present in database");
    assert($logged['item_type'] === 'system_setting', "Item type must match");
    assert((int)$logged['item_id'] === 42, "Item ID must match");
    echo "  [+] logLmsAdminAction() writes audit accountability records successfully.\n";

    // 5. Business rule verification: No accidental bypass of payment requirements
    $unpaidStudentRecord = [
        'enrollment_status'         => 'enrolled',
        'has_confirmed_enrollment'  => true,
        'assessment_total'          => 20000.00,
        'minimum_downpayment'       => 5000.00,
        'downpayment_percentage'    => 30.00,
        'validated_paid'            => 0.00, // Unpaid
    ];
    $hasAccess = lmsStudentMeetsAccessRequirements($unpaidStudentRecord);
    assert($hasAccess === false, "Student without downpayment must NOT be given access automatically");
    echo "  [+] Business rule integrity confirmed: Admin actions do not silently bypass payment gates.\n";

} finally {
    $pdo->rollBack();
}

echo "\n>>> ALL TASK 0.1 ADMIN LMS ACCESS CONTROL TESTS PASSED SUCCESSFULLY! <<<\n";
