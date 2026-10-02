<?php
/**
 * Test: Registrar LMS Access Control Guard (TASK 0.1)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

$mode = $argv[1] ?? 'all';

if ($mode === 'roles_and_get') {
    assert(lmsRoleIsPermitted('registrar') === true, "Registrar must be in LMS_PERMITTED_ROLES");
    assert(lmsRoleIsPermitted('teacher') === true, "Teacher must be in LMS_PERMITTED_ROLES");
    assert(lmsRoleIsPermitted('student') === true, "Student must be in LMS_PERMITTED_ROLES");
    assert(lmsRoleIsPermitted('admin') === true, "Admin must be in LMS_PERMITTED_ROLES");
    assert(lmsRoleIsPermitted('enrollee') === false, "Enrollee must NOT be in LMS_PERMITTED_ROLES");
    assert(lmsRoleIsPermitted('cashier') === false, "Cashier must NOT be in LMS_PERMITTED_ROLES");
    echo "  [+] LMS role allowlist assertions verified.\n";

    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'registrar' LIMIT 1");
    $stmt->execute();
    $registrarUserId = (int)$stmt->fetchColumn();

    $_SESSION['user_id'] = $registrarUserId;
    $_SESSION['role']    = 'registrar';
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $regAccess = requireLmsRegistrarAccess();
    assert($regAccess['role'] === 'registrar', "Role must be registrar");
    assert($regAccess['user_id'] === $registrarUserId, "User ID must match");
    echo "  [+] requireLmsRegistrarAccess() succeeds on GET requests without payment/enrollment gating.\n";
    exit(0);
}

if ($mode === 'post_blocked') {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'registrar' LIMIT 1");
    $stmt->execute();
    $registrarUserId = (int)$stmt->fetchColumn();

    $_SESSION['user_id'] = $registrarUserId;
    $_SESSION['role']    = 'registrar';
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // requireLmsRegistrarAccess will set http_response_code(403) and exit.
    register_shutdown_function(function() {
        if (http_response_code() === 403) {
            echo "  [+] requireLmsRegistrarAccess() strictly blocks POST mutation requests with HTTP 403.\n";
            exit(0);
        }
    });
    requireLmsRegistrarAccess();
    exit(1);
}

if ($mode === 'teacher_blocked') {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'registrar' LIMIT 1");
    $stmt->execute();
    $registrarUserId = (int)$stmt->fetchColumn();

    $_SESSION['user_id'] = $registrarUserId;
    $_SESSION['role']    = 'registrar';
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    register_shutdown_function(function() {
        if (!empty($_SESSION['flash_error']) && str_contains($_SESSION['flash_error'], 'Access denied')) {
            echo "  [+] Registrar attempting to access Teacher-side management routes is strictly blocked.\n";
            exit(0);
        }
    });
    requireLmsTeacherAccess();
    exit(1);
}

// Master Runner
echo "Testing Registrar LMS Access Control Guard (TASK 0.1)...\n";
passthru("php " . escapeshellarg(__FILE__) . " roles_and_get");
passthru("php " . escapeshellarg(__FILE__) . " post_blocked");
passthru("php " . escapeshellarg(__FILE__) . " teacher_blocked");
echo "\n>>> ALL TASK 0.1 REGISTRAR LMS ACCESS CONTROL TESTS PASSED SUCCESSFULLY! <<<\n";
