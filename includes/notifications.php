<?php
/**
 * Notification persistence helpers.
 *
 * createNotification() writes an in-app notification row AND sends a
 * PHPMailer email to the same user as a supplementary channel.
 *
 * Email rules (enforced in includes/mailer.php):
 *  - Soft-fail only: a failed send is error_log()'d and never throws.
 *  - Always called AFTER the DB row is written so email cannot affect
 *    any in-progress database transaction.
 *  - Skipped automatically when config/mailer.php credentials are still
 *    the placeholder defaults (local dev safety net).
 */

require_once __DIR__ . '/mailer.php';

function createNotification(PDO $pdo, int $userId, string $title, string $message, string $type = 'info'): void
{
    $allowedTypes = ['info', 'success', 'warning', 'danger'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }

    $duplicateCheck = $pdo->prepare(
        'SELECT 1 FROM notifications
         WHERE user_id = :user_id
           AND title = :title
           AND message = :message
           AND type = :type
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         LIMIT 1'
    );
    $duplicateCheck->execute([
        'user_id' => $userId,
        'title'   => $title,
        'message' => $message,
        'type'    => $type,
    ]);

    if ($duplicateCheck->fetch()) {
        return;
    }

    // 1. Write in-app notification row (unchanged behaviour)
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'title'   => $title,
        'message' => $message,
        'type'    => $type,
    ]);

    // 2. Send PHPMailer email (supplementary — soft-fail, never affects DB)
    try {
        require_once __DIR__ . '/lms_settings.php';
        if (getLmsSetting($pdo, 'enable_email_notifications', '1') === '0') {
            return;
        }

        $userStmt = $pdo->prepare(
            'SELECT u.email,
                    COALESCE(NULLIF(TRIM(u.first_name), ""), s.first_name, u.username) AS first_name,
                    COALESCE(NULLIF(TRIM(u.last_name), ""), s.last_name, "") AS last_name
             FROM users u
             LEFT JOIN students s ON s.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $userStmt->execute(['id' => $userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($userRow && !empty($userRow['email'])) {
            $recipientName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
            $htmlBody = buildEmailHtml($recipientName, $title, $message);
            sendSystemEmail(
                $userRow['email'],
                $recipientName ?: $userRow['email'],
                $title . ' — NCST Maritime Academy',
                $htmlBody
            );
        }
    } catch (\Throwable $e) {
        // Email failure must never surface to the user or affect the request flow.
        error_log('[Mailer] createNotification email dispatch error for user_id=' . $userId . ': ' . $e->getMessage());
    }
}

/**
 * Notify all active registrars and admins that a student has completed payment
 * and is ready for final registrar enrollment confirmation.
 */
function notifyRegistrarsPaymentReceived(PDO $pdo, string $studentName = ''): void
{
    $studentLabel = trim($studentName);
    $msg = $studentLabel !== ''
        ? 'Payment received for ' . $studentLabel . ': Student has completed payment and is ready for final registrar enrollment confirmation.'
        : 'Student has completed payment and is ready for final registrar enrollment confirmation.';

    $registrarStmt = $pdo->query("SELECT id FROM users WHERE role IN ('registrar', 'admin') AND is_active = 1");
    foreach ($registrarStmt->fetchAll(PDO::FETCH_COLUMN) as $registrarUserId) {
        createNotification(
            $pdo,
            (int)$registrarUserId,
            'Payment received',
            $msg,
            'info'
        );
    }
}

