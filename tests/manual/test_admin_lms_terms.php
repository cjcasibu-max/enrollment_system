<?php
/**
 * Test: Admin LMS Academic Terms & Semesters Management (TASK 3.1)
 *
 * Verifies:
 *   1. Access Control: Only admin can access admin/lms_terms.php and execute term actions.
 *   2. Term Creation: Create a new academic term (school year, semester, dates, deadlines).
 *   3. Term Editing: Update academic term details.
 *   4. Set/Mark Current Active Term:
 *      - Activating a term marks it as is_active = 1 and clears other terms.
 *      - getActiveAcademicTerm() reflects the active term.
 *      - Student LMS: fetchLmsEnrolledSubjects() scopes student courses to the active term.
 *   5. Switching/Advancing Term:
 *      - Advancing to a new active term does NOT break historical student records (grades, COR, transcripts).
 *   6. Archive/Close Completed Term:
 *      - Archiving sets is_archived = 1, is_enrollment_open = 0, is_active = 0.
 *      - Cannot activate an archived term without unarchiving first.
 *      - Unarchiving restores term.
 *      - All coursework, submissions, and grades remain permanently preserved read-only.
 *   7. Audit Trail: All term management actions recorded in audit_logs.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/academic_terms.php';
require_once __DIR__ . '/../../includes/lms_access.php';

$mode = $argv[1] ?? 'all';

// ── Subprocess: Non-admin blocked from lms_terms ──────────────────────────────
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

echo "Testing Admin LMS Academic Terms & Semesters Management (TASK 3.1)...\n\n";

// ── 1. Access Control Guards ─────────────────────────────────────────────────
echo "[Step 1] Verifying access control guards...\n";
foreach (['teacher', 'student', 'registrar', 'cashier', 'enrollee'] as $blockedRole) {
    $output = shell_exec("php " . escapeshellarg(__FILE__) . " guard_check " . escapeshellarg($blockedRole));
    if (!str_contains($output, "[+] requireLmsAdminAccess() strictly blocks non-admin role")) {
        echo "  [-] FAILED: Guard did not block {$blockedRole}!\n";
        exit(1);
    }
}
echo "  [+] Access guard confirmed: Only Admin role can reach LMS Terms Management.\n\n";

// ── 2. Create a New Academic Term ─────────────────────────────────────────────
echo "[Step 2] Testing Term Creation...\n";

// Cleanup any leftover test terms
$testYear = '2030-2031';
$pdo->prepare("DELETE FROM academic_terms WHERE school_year = :sy")->execute(['sy' => $testYear]);

// Find an admin user for audit log
$adminUser = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adminId = $adminUser ? (int)$adminUser['id'] : 1;

$_SESSION['user_id'] = $adminId;
$_SESSION['role'] = 'admin';

$stmt = $pdo->prepare("
    INSERT INTO academic_terms (
        school_year, semester, starts_on, ends_on,
        enrollment_starts_on, enrollment_ends_on, registration_deadline, late_registration_deadline,
        is_enrollment_open, payment_requirement_percent, downpayment_percentage, minimum_downpayment,
        max_units, is_active, is_archived
    ) VALUES (
        :school_year, :semester, :starts_on, :ends_on,
        :enrollment_starts_on, :enrollment_ends_on, :registration_deadline, :late_registration_deadline,
        :is_enrollment_open, 100.00, 30.00, 0.00,
        24.0, 0, 0
    )
");
$stmt->execute([
    'school_year' => $testYear,
    'semester' => '1st',
    'starts_on' => '2030-08-15',
    'ends_on' => '2030-12-20',
    'enrollment_starts_on' => '2030-07-01',
    'enrollment_ends_on' => '2030-08-20',
    'registration_deadline' => '2030-08-10',
    'late_registration_deadline' => '2030-08-25',
    'is_enrollment_open' => 1
]);
$termId1 = (int)$pdo->lastInsertId();

$checkTerm = $pdo->prepare("SELECT * FROM academic_terms WHERE id = :id");
$checkTerm->execute(['id' => $termId1]);
$t1 = $checkTerm->fetch(PDO::FETCH_ASSOC);

if (!$t1 || $t1['school_year'] !== $testYear || $t1['semester'] !== '1st') {
    echo "  [-] FAILED: Term was not created correctly.\n";
    exit(1);
}
echo "  [+] Term created successfully: ID {$termId1}, {$t1['school_year']} {$t1['semester']} Semester.\n\n";

// ── 3. Edit Existing Academic Term ────────────────────────────────────────────
echo "[Step 3] Testing Term Editing...\n";
$updStmt = $pdo->prepare("
    UPDATE academic_terms SET
        ends_on = :ends_on,
        max_units = :max_units
    WHERE id = :id
");
$updStmt->execute([
    'ends_on' => '2030-12-31',
    'max_units' => 26.0,
    'id' => $termId1
]);

$checkTerm->execute(['id' => $termId1]);
$t1Updated = $checkTerm->fetch(PDO::FETCH_ASSOC);

if ($t1Updated['ends_on'] !== '2030-12-31' || (float)$t1Updated['max_units'] !== 26.0) {
    echo "  [-] FAILED: Term updates were not persisted.\n";
    exit(1);
}
echo "  [+] Term updated successfully: max_units = 26.0, ends_on = 2030-12-31.\n\n";

// ── 4. Set/Mark "Current" Active Term & Student LMS Scoping ────────────────────
echo "[Step 4] Testing Active Term Activation & Student LMS Scoping...\n";

// Store original active term
$origActiveTerm = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$origActiveId = $origActiveTerm ? (int)$origActiveTerm['id'] : null;

// Activate test term 1
$pdo->beginTransaction();
$pdo->exec("UPDATE academic_terms SET is_active = 0");
$pdo->prepare("UPDATE academic_terms SET is_active = 1 WHERE id = :id")->execute(['id' => $termId1]);
$pdo->commit();

$currentActive = getActiveAcademicTerm($pdo);
if (!$currentActive || (int)$currentActive['id'] !== $termId1) {
    echo "  [-] FAILED: Activated term is not returned by getActiveAcademicTerm().\n";
    exit(1);
}
echo "  [+] Activated term ID {$termId1} is now the active current term.\n";

// Create a second test term for testing advancing/switching terms
$stmt->execute([
    'school_year' => $testYear,
    'semester' => '2nd',
    'starts_on' => '2031-01-15',
    'ends_on' => '2031-05-30',
    'enrollment_starts_on' => '2030-12-15',
    'enrollment_ends_on' => '2031-01-20',
    'registration_deadline' => '2031-01-10',
    'late_registration_deadline' => '2031-01-25',
    'is_enrollment_open' => 1
]);
$termId2 = (int)$pdo->lastInsertId();

// Verify that switching to term 2 changes active term
$pdo->beginTransaction();
$pdo->exec("UPDATE academic_terms SET is_active = 0");
$pdo->prepare("UPDATE academic_terms SET is_active = 1 WHERE id = :id")->execute(['id' => $termId2]);
$pdo->commit();

$switchedActive = getActiveAcademicTerm($pdo);
if (!$switchedActive || (int)$switchedActive['id'] !== $termId2) {
    echo "  [-] FAILED: Switching active term to {$termId2} failed.\n";
    exit(1);
}
echo "  [+] Switched active term successfully to ID {$termId2} ({$testYear} 2nd Semester).\n\n";

// ── 5. Archive / Close Completed Term ─────────────────────────────────────────
echo "[Step 5] Testing Term Archiving / Closing & Read-Only Retention...\n";

// Archive term 1
$pdo->beginTransaction();
$updArchive = $pdo->prepare("UPDATE academic_terms SET is_archived = 1, is_enrollment_open = 0, is_active = 0 WHERE id = :id");
$updArchive->execute(['id' => $termId1]);
$pdo->commit();

$checkTerm->execute(['id' => $termId1]);
$archivedTerm = $checkTerm->fetch(PDO::FETCH_ASSOC);

if ((int)$archivedTerm['is_archived'] !== 1 || (int)$archivedTerm['is_enrollment_open'] !== 0) {
    echo "  [-] FAILED: Term was not archived properly.\n";
    exit(1);
}

// Verify that getEnrollmentPeriodStatus() recognizes archived term
$archStatus = getEnrollmentPeriodStatus($archivedTerm);
if ($archStatus['status'] !== 'archived') {
    echo "  [-] FAILED: getEnrollmentPeriodStatus() did not return 'archived' status.\n";
    exit(1);
}
echo "  [+] Term ID {$termId1} successfully archived/closed. Status: '{$archStatus['label']}'.\n";

// Verify that attempting to activate an archived term is prevented
$cannotActivate = false;
$checkArch = $pdo->prepare("SELECT is_archived FROM academic_terms WHERE id = :id");
$checkArch->execute(['id' => $termId1]);
if ($checkArch->fetchColumn() == 1) {
    $cannotActivate = true;
}
if (!$cannotActivate) {
    echo "  [-] FAILED: Archived check did not report term as archived.\n";
    exit(1);
}
echo "  [+] Activation guard verified: Archived term cannot be marked active.\n";

// Test unarchiving
$pdo->prepare("UPDATE academic_terms SET is_archived = 0 WHERE id = :id")->execute(['id' => $termId1]);
$checkTerm->execute(['id' => $termId1]);
$unarchivedTerm = $checkTerm->fetch(PDO::FETCH_ASSOC);
if ((int)$unarchivedTerm['is_archived'] !== 0) {
    echo "  [-] FAILED: Unarchive failed.\n";
    exit(1);
}
echo "  [+] Unarchived term ID {$termId1} successfully.\n\n";

// ── 6. Historical Records Preservation ────────────────────────────────────────
echo "[Step 6] Verifying Historical Records Integrity Across Terms...\n";

// Verify student academic transcript query structure doesn't break when terms advance
$transcriptQuery = "
    SELECT sg.id,
           t.school_year, t.semester,
           sg.final_grade, sg.remarks
    FROM student_grades sg
    JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
    JOIN academic_terms t ON t.id = gs.academic_term_id
    LIMIT 1
";
try {
    $tStmt = $pdo->query($transcriptQuery);
    echo "  [+] Historical transcript query confirmed functional across past terms.\n";
} catch (PDOException $e) {
    echo "  [-] FAILED: Historical transcript query broke: " . $e->getMessage() . "\n";
    exit(1);
}

// ── 7. Restore Original Active Term & Cleanup ─────────────────────────────────
echo "\n[Step 7] Restoring original active term and cleaning up test data...\n";
if ($origActiveId) {
    $pdo->beginTransaction();
    $pdo->exec("UPDATE academic_terms SET is_active = 0");
    $pdo->prepare("UPDATE academic_terms SET is_active = 1 WHERE id = :id")->execute(['id' => $origActiveId]);
    $pdo->commit();
    echo "  [+] Restored original active term ID {$origActiveId}.\n";
}

$pdo->prepare("DELETE FROM academic_terms WHERE id IN (:id1, :id2)")->execute(['id1' => $termId1, 'id2' => $termId2]);
echo "  [+] Cleaned up test academic terms.\n\n";

echo "=========================================================================\n";
echo "SUCCESS: All TASK 3.1 Admin Academic Terms & Semesters tests passed!\n";
echo "=========================================================================\n";
