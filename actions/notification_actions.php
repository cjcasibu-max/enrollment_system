<?php
require_once '../includes/auth_check.php';
checkRole(['admin', 'registrar', 'cashier', 'teacher', 'student', 'enrollee']);
require_once '../config/database.php';

$userRole = $_SESSION['role'] ?? 'enrollee';
$fallbackUrl = match ($userRole) {
    'enrollee' => '../enrollee/notifications',
    'student' => '../student/notifications',
    'teacher' => '../teacher/dashboard',
    'cashier' => '../cashier/dashboard',
    'registrar' => '../registrar/dashboard',
    'admin' => '../admin/dashboard',
    default => '../index',
};

// ── GET REQUEST: MARK INDIVIDUAL NOTIFICATION AS READ AND REDIRECT ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'read_and_redirect') {
    $id = (int)($_GET['id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($id && $userId) {
        try {
            // 1. Mark notification as read
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
            $stmt->execute(['id' => $id, 'user_id' => $userId]);

            // 2. Fetch the title to determine where to redirect
            $fetch = $pdo->prepare('SELECT title FROM notifications WHERE id = :id LIMIT 1');
            $fetch->execute(['id' => $id]);
            $notif = $fetch->fetch();

            $title = $notif ? strtolower(trim($notif['title'])) : '';

            // Redirect mapping logic
            if (in_array($title, ['application approved', 'application rejected', 'edits requested', 'registrar remark', 'application under review'], true)) {
                header('Location: ../enrollee/dashboard?open_status=1');
                exit;
            } elseif ($title === 'payment validated') {
                header('Location: ../enrollee/dashboard');
                exit;
            } elseif (in_array($title, ['registration submitted', 'registration approved', 'registration update'], true)) {
                header('Location: ../student/dashboard');
                exit;
            } elseif ($title === 'grades approved') {
                header('Location: ../student/academic_records');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Notification read_and_redirect error: ' . $e->getMessage());
        }
    }

    // Default fallback: return to appropriate notifications or dashboard
    header("Location: {$fallbackUrl}");
    exit;
}

// ── POST REQUEST: MARK ALL AS READ ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'mark_all_read') {
    $_SESSION['flash_error'] = 'Invalid notification action.';
    header("Location: {$fallbackUrl}");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Security validation failed. Please try again.';
    header("Location: {$fallbackUrl}");
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
    $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    $_SESSION['flash_success'] = 'All notifications marked as read.';
} catch (PDOException $e) {
    error_log('Notification update failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Unable to update notifications right now.';
}

header("Location: {$fallbackUrl}");
exit;
