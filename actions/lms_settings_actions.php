<?php
/**
 * LMS System Settings Action Processor (TASK 4.1)
 * Validates and saves administrative settings for:
 *   - Quiz defaults (time limit, attempts, passing score)
 *   - File upload limits & allowed extensions
 *   - Notification dispatches
 *   - Grading scale and passing score threshold
 *
 * Strict Admin-only access. Full audit logging.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/lms_access.php';
require_once __DIR__ . '/../includes/lms_settings.php';

// Strict Admin Access Check
$adminUser = requireLmsAdminAccess();
$adminUserId = (int)$adminUser['user_id'];

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Invalid request method.';
    header('Location: ../admin/lms_settings');
    exit;
}

// CSRF Validation
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Security validation failed. Please try again.';
    header('Location: ../admin/lms_settings');
    exit;
}

$action = trim($_POST['action'] ?? '');

if ($action !== 'save_settings') {
    $_SESSION['flash_error'] = 'Invalid settings action.';
    header('Location: ../admin/lms_settings');
    exit;
}

try {
    // ── 1. Quiz Defaults ───────────────────────────────────────────────────────
    $quizTimeLimit = filter_input(INPUT_POST, 'quiz_default_time_limit', FILTER_VALIDATE_INT);
    if ($quizTimeLimit === false || $quizTimeLimit < 1 || $quizTimeLimit > 600) {
        throw new DomainException('Default quiz time limit must be between 1 and 600 minutes.');
    }

    $quizAttempts = filter_input(INPUT_POST, 'quiz_default_allowed_attempts', FILTER_VALIDATE_INT);
    if ($quizAttempts === false || $quizAttempts < 1 || $quizAttempts > 50) {
        throw new DomainException('Default quiz allowed attempts must be between 1 and 50.');
    }

    $quizPassingScore = filter_input(INPUT_POST, 'quiz_default_passing_score', FILTER_VALIDATE_FLOAT);
    if ($quizPassingScore === false || $quizPassingScore < 0 || $quizPassingScore > 100) {
        throw new DomainException('Default quiz passing score must be between 0.00% and 100.00%.');
    }

    // ── 2. File Upload Limits & Allowed Types ──────────────────────────────────
    $matMaxMb = filter_input(INPUT_POST, 'materials_max_upload_mb', FILTER_VALIDATE_INT);
    if ($matMaxMb === false || $matMaxMb < 1 || $matMaxMb > 500) {
        throw new DomainException('Learning materials upload limit must be between 1 and 500 MB.');
    }

    $rawMatExts = trim((string)($_POST['materials_allowed_exts'] ?? ''));
    $cleanMatExts = array_unique(array_filter(array_map(function ($ext) {
        $e = strtolower(trim($ext, ". \t\n\r\0\x0B"));
        return preg_match('/^[a-z0-9]{1,10}$/', $e) ? $e : null;
    }, explode(',', $rawMatExts))));

    if (empty($cleanMatExts)) {
        throw new DomainException('You must specify at least one valid allowed file extension for Learning Materials.');
    }

    $assignMaxMb = filter_input(INPUT_POST, 'assignments_max_upload_mb', FILTER_VALIDATE_INT);
    if ($assignMaxMb === false || $assignMaxMb < 1 || $assignMaxMb > 200) {
        throw new DomainException('Assignment submissions upload limit must be between 1 and 200 MB.');
    }

    $rawAssignExts = trim((string)($_POST['assignments_allowed_exts'] ?? ''));
    $cleanAssignExts = array_unique(array_filter(array_map(function ($ext) {
        $e = strtolower(trim($ext, ". \t\n\r\0\x0B"));
        return preg_match('/^[a-z0-9]{1,10}$/', $e) ? $e : null;
    }, explode(',', $rawAssignExts))));

    if (empty($cleanAssignExts)) {
        throw new DomainException('You must specify at least one valid allowed file extension for Assignment Submissions.');
    }

    // ── 3. Notification Toggles ───────────────────────────────────────────────
    $notifyAssignment = !empty($_POST['notify_assignment_submission']) ? '1' : '0';
    $notifyGrade      = !empty($_POST['notify_grade_posted']) ? '1' : '0';
    $notifyAnnounce   = !empty($_POST['notify_announcement']) ? '1' : '0';
    $enableEmail      = !empty($_POST['enable_email_notifications']) ? '1' : '0';

    // ── 4. Grading Scale & Passing Threshold ──────────────────────────────────
    $gradeMin = filter_input(INPUT_POST, 'grading_scale_min', FILTER_VALIDATE_FLOAT);
    if ($gradeMin === false || $gradeMin < 0) {
        throw new DomainException('Minimum grading scale score cannot be negative.');
    }

    $gradeMax = filter_input(INPUT_POST, 'grading_scale_max', FILTER_VALIDATE_FLOAT);
    if ($gradeMax === false || $gradeMax <= $gradeMin) {
        throw new DomainException('Maximum grading scale score must be greater than minimum score.');
    }

    $passingThreshold = filter_input(INPUT_POST, 'passing_grade_threshold', FILTER_VALIDATE_FLOAT);
    if ($passingThreshold === false || $passingThreshold < $gradeMin || $passingThreshold > $gradeMax) {
        throw new DomainException('Passing score threshold must fall between minimum and maximum grade scores.');
    }

    // Prepare settings payload
    $settingsToSave = [
        'quiz_default_time_limit'       => (string)$quizTimeLimit,
        'quiz_default_allowed_attempts' => (string)$quizAttempts,
        'quiz_default_passing_score'    => number_format($quizPassingScore, 2, '.', ''),
        'materials_max_upload_mb'       => (string)$matMaxMb,
        'materials_allowed_exts'        => implode(',', $cleanMatExts),
        'assignments_max_upload_mb'     => (string)$assignMaxMb,
        'assignments_allowed_exts'      => implode(',', $cleanAssignExts),
        'notify_assignment_submission'  => $notifyAssignment,
        'notify_grade_posted'           => $notifyGrade,
        'notify_announcement'           => $notifyAnnounce,
        'enable_email_notifications'    => $enableEmail,
        'grading_scale_min'             => number_format($gradeMin, 2, '.', ''),
        'grading_scale_max'             => number_format($gradeMax, 2, '.', ''),
        'passing_grade_threshold'       => number_format($passingThreshold, 2, '.', ''),
    ];

    saveLmsSettings($pdo, $settingsToSave, $adminUserId);

    // Audit Log
    logLmsAdminAction(
        $pdo,
        $adminUserId,
        'update_lms_settings',
        'system_settings',
        null,
        "Updated general LMS system settings (quizzes, uploads, notifications, grading scale)"
    );

    $_SESSION['flash_success'] = 'LMS system settings have been successfully updated and applied.';
} catch (DomainException $de) {
    $_SESSION['flash_error'] = $de->getMessage();
} catch (Throwable $e) {
    error_log('LMS settings save error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred while saving system settings.';
}

header('Location: ../admin/lms_settings');
exit;
