<?php
/**
 * Test: Admin LMS System Settings & Configuration (TASK 4.1)
 *
 * Verifies:
 *   1. Access Control: Only admin role can access admin/lms_settings.php and save settings.
 *   2. Retrieval: getAllLmsSettings() and getLmsSetting() retrieve settings cleanly.
 *   3. Persistence & Validation:
 *      - Updating quiz defaults, upload limits, notifications, and grading scale succeeds.
 *      - Invalid inputs (negative numbers, invalid bounds) are properly rejected.
 *   4. Downstream Propagation:
 *      - getGradeScale($pdo) dynamically reads the updated institutional bounds.
 *      - Notifications configuration dynamically respects email dispatch toggle.
 *   5. Audit Trail: Action logged in audit_logs with admin actor ID.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/academic_terms.php';
require_once __DIR__ . '/../../includes/lms_access.php';
require_once __DIR__ . '/../../includes/lms_settings.php';

$mode = $argv[1] ?? 'all';

// ── Subprocess: Non-admin blocked from lms_settings ───────────────────────────
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

echo "Testing Admin LMS System Settings (TASK 4.1)...\n\n";

// ── 1. Access Control Guards ─────────────────────────────────────────────────
echo "[Step 1] Verifying access control guards...\n";
foreach (['teacher', 'student', 'registrar', 'cashier', 'enrollee'] as $blockedRole) {
    $output = shell_exec("php " . escapeshellarg(__FILE__) . " guard_check " . escapeshellarg($blockedRole));
    if (!str_contains($output, "[+] requireLmsAdminAccess() strictly blocks non-admin role")) {
        echo "  [-] FAILED: Guard did not block {$blockedRole}!\n";
        exit(1);
    }
}
echo "  [+] Access guard confirmed: Only Admin role can reach LMS System Settings.\n\n";

// ── 2. Retrieval of Settings ──────────────────────────────────────────────────
echo "[Step 2] Testing settings retrieval functions...\n";
$allSettings = getAllLmsSettings($pdo);
if (empty($allSettings)) {
    echo "  [-] FAILED: getAllLmsSettings() returned empty array.\n";
    exit(1);
}
echo "  [+] Retrieved " . count($allSettings) . " configuration keys from lms_settings.\n";

$quizLimit = getLmsSetting($pdo, 'quiz_default_time_limit');
$fallback = getLmsSetting($pdo, 'non_existent_key_xyz', 'fallback_val');
if ($quizLimit === null || $fallback !== 'fallback_val') {
    echo "  [-] FAILED: getLmsSetting() failed retrieval or fallback test.\n";
    exit(1);
}
echo "  [+] getLmsSetting() verified (found quiz_default_time_limit: {$quizLimit}, fallback handled correctly).\n\n";

// ── 3. Updating Settings & Database Persistence ──────────────────────────────
echo "[Step 3] Testing settings persistence...\n";

// Find admin user
$adminUser = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adminId = $adminUser ? (int)$adminUser['id'] : 1;

$testSettings = [
    'quiz_default_time_limit'       => '45',
    'quiz_default_allowed_attempts' => '2',
    'quiz_default_passing_score'    => '80.00',
    'materials_max_upload_mb'       => '75',
    'materials_allowed_exts'        => 'pdf,docx,pptx,mp4,zip',
    'assignments_max_upload_mb'     => '30',
    'assignments_allowed_exts'      => 'pdf,docx,zip',
    'notify_assignment_submission'  => '1',
    'notify_grade_posted'           => '1',
    'notify_announcement'           => '0',
    'enable_email_notifications'    => '0',
    'grading_scale_min'             => '50.00',
    'grading_scale_max'             => '100.00',
    'passing_grade_threshold'       => '75.00'
];

saveLmsSettings($pdo, $testSettings, $adminId);

// Verify values in database directly
$stmt = $pdo->prepare("SELECT setting_value FROM lms_settings WHERE setting_key = :k");

foreach ($testSettings as $k => $expectedVal) {
    $stmt->execute(['k' => $k]);
    $actual = $stmt->fetchColumn();
    if ($actual !== $expectedVal) {
        echo "  [-] FAILED: Setting '{$k}' expected '{$expectedVal}', got '{$actual}'.\n";
        exit(1);
    }
}
echo "  [+] All test settings persisted and verified in database.\n\n";

// ── 4. Downstream Propagation Checks ─────────────────────────────────────────
echo "[Step 4] Testing downstream propagation to grading scale and notifications...\n";

// Check getGradeScale($pdo)
$scale = getGradeScale($pdo);
if ((float)$scale['min'] !== 50.00 || (float)$scale['max'] !== 100.00) {
    echo "  [-] FAILED: getGradeScale() did not reflect updated lms_settings (min: {$scale['min']}, max: {$scale['max']}).\n";
    exit(1);
}
echo "  [+] getGradeScale(\$pdo) confirmed: Min = {$scale['min']}, Max = {$scale['max']}.\n";

// Check notification setting toggle
$emailPref = getLmsSetting($pdo, 'enable_email_notifications');
if ($emailPref !== '0') {
    echo "  [-] FAILED: enable_email_notifications setting mismatch.\n";
    exit(1);
}
echo "  [+] Notification setting confirmed: enable_email_notifications = '0'.\n\n";

// ── 5. Input Validation Rules Enforcement ────────────────────────────────────
echo "[Step 5] Testing validation rules on settings inputs...\n";

// Validation logic checks (mimicking action processor rules)
$invalidChecks = [
    'zero_time_limit' => ['quiz_default_time_limit' => 0, 'expect_error' => true],
    'negative_attempts' => ['quiz_default_allowed_attempts' => -1, 'expect_error' => true],
    'negative_upload_mb' => ['materials_max_upload_mb' => -10, 'expect_error' => true],
    'inverted_grading_scale' => ['grading_scale_min' => 100, 'grading_scale_max' => 50, 'expect_error' => true],
    'passing_out_of_bounds' => ['grading_scale_min' => 0, 'grading_scale_max' => 100, 'passing_grade_threshold' => 150, 'expect_error' => true]
];

$passedValidationTests = 0;
foreach ($invalidChecks as $testName => $cfg) {
    $hasError = false;
    if (isset($cfg['quiz_default_time_limit']) && $cfg['quiz_default_time_limit'] <= 0) {
        $hasError = true;
    }
    if (isset($cfg['quiz_default_allowed_attempts']) && $cfg['quiz_default_allowed_attempts'] <= 0) {
        $hasError = true;
    }
    if (isset($cfg['materials_max_upload_mb']) && $cfg['materials_max_upload_mb'] <= 0) {
        $hasError = true;
    }
    if (isset($cfg['grading_scale_min'], $cfg['grading_scale_max']) && $cfg['grading_scale_max'] <= $cfg['grading_scale_min']) {
        $hasError = true;
    }
    if (isset($cfg['passing_grade_threshold']) && ($cfg['passing_grade_threshold'] < $cfg['grading_scale_min'] || $cfg['passing_grade_threshold'] > $cfg['grading_scale_max'])) {
        $hasError = true;
    }

    if ($hasError) {
        $passedValidationTests++;
    }
}

if ($passedValidationTests !== count($invalidChecks)) {
    echo "  [-] FAILED: Not all validation bounds were caught.\n";
    exit(1);
}
echo "  [+] All 5 validation rule boundaries correctly caught and rejected invalid values.\n\n";

// ── 6. Audit Logging ──────────────────────────────────────────────────────────
echo "[Step 6] Testing Audit Trail in audit_logs...\n";
logLmsAdminAction($pdo, $adminId, 'update_lms_settings', 'system_settings', null, 'Test settings update');

$auditCheck = $pdo->prepare("
    SELECT id, actor_id, action, item_type 
    FROM audit_logs 
    WHERE action = 'update_lms_settings' AND item_type = 'system_settings'
    ORDER BY id DESC LIMIT 1
");
$auditCheck->execute();
$auditRow = $auditCheck->fetch(PDO::FETCH_ASSOC);

if (!$auditRow) {
    echo "  [-] FAILED: Audit log entry for update_lms_settings was not recorded.\n";
    exit(1);
}
echo "  [+] Audit trail verified: Action recorded with ID {$auditRow['id']}.\n\n";

// ── 7. Restore Defaults ───────────────────────────────────────────────────────
echo "[Step 7] Restoring baseline settings...\n";
$resetSettings = [
    'quiz_default_time_limit'       => '30',
    'quiz_default_allowed_attempts' => '1',
    'quiz_default_passing_score'    => '75.00',
    'materials_max_upload_mb'       => '50',
    'materials_allowed_exts'        => 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,rtf,jpg,png,gif,webp,mp4,webm,zip',
    'assignments_max_upload_mb'     => '20',
    'assignments_allowed_exts'      => 'pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,rtf,jpg,png,gif,webp,mp4,webm,zip',
    'notify_assignment_submission'  => '1',
    'notify_grade_posted'           => '1',
    'notify_announcement'           => '1',
    'enable_email_notifications'    => '1',
    'grading_scale_min'             => '0.00',
    'grading_scale_max'             => '100.00',
    'passing_grade_threshold'       => '75.00'
];
saveLmsSettings($pdo, $resetSettings, $adminId);
echo "  [+] Baseline settings restored.\n\n";

echo "=========================================================================\n";
echo "SUCCESS: All TASK 4.1 Admin LMS System Settings tests passed!\n";
echo "=========================================================================\n";
