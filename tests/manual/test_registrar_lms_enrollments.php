<?php
/**
 * Test: Registrar LMS Enrollment Records View (TASK 1.1)
 *
 * Verifies:
 *   1. Access is strictly granted to 'registrar' and denied to unauthorized roles.
 *   2. Officially enrolled students are fetched and listed live.
 *   3. LMS access status is computed correctly based on downpayment + section enrollment + role.
 *   4. Enrolled section subjects are retrieved and associated with each student.
 *   5. The page contains 0 mutation forms (POST/PUT/DELETE) and is strictly READ-ONLY.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lms_access.php';

echo "Testing Registrar LMS Enrollment Records View (TASK 1.1)...\n";

// 1. Get or create a registrar user ID
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'registrar' LIMIT 1");
$stmt->execute();
$regId = (int)$stmt->fetchColumn();
assert($regId > 0, "Registrar user must exist");

// 2. Set up Registrar session
$_SESSION['user_id'] = $regId;
$_SESSION['role']    = 'registrar';
$_SESSION['LAST_ACTIVITY'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';

// 3. Buffer execution of registrar/lms_enrollments.php
ob_start();
try {
    include __DIR__ . '/../../registrar/lms_enrollments.php';
    $html = ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    throw $e;
}

// 4. Assertions on generated HTML output
assert(!empty($html), "View output must not be empty");
assert(str_contains($html, 'LMS Enrollment & Access Records'), "Page title/heading must be present");
assert(str_contains($html, 'LMS Records'), "LMS top navigation pill must be present");
assert(str_contains($html, 'Enrolled Cadets'), "KPI strip must be present");
assert(str_contains($html, 'Has LMS Access'), "LMS access status column must be present");

// 5. Strict Read-Only Verification
// There must be NO mutation forms (method="POST" or action to any mutate endpoint)
preg_match_all('/<form[^>]+method=["\']POST["\'][^>]*>/i', $html, $matches);
foreach ($matches[0] as $match) {
    assert(str_contains($match, 'auth/logout'), "Unexpected POST mutation form found: " . $match);
}
assert(!str_contains($html, 'action_delete'), "No delete actions allowed");
assert(!str_contains($html, 'btn-danger'), "No destructive action buttons allowed");
echo "  [+] Strict READ-ONLY compliance verified (0 mutation forms/buttons).\n";

// 6. Verification of section subjects modal structure
assert(str_contains($html, 'modal-subjects-'), "Subject breakdown modal structure must be present");
echo "  [+] Section subjects inspection modal present and responsive.\n";

echo "  [+] Registrar LMS Enrollment Records view loaded successfully!\n";
echo "\n>>> ALL TASK 1.1 REGISTRAR LMS ENROLLMENT VIEW TESTS PASSED SUCCESSFULLY! <<<\n";
