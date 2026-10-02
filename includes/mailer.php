<?php
/**
 * System Email Helper
 *
 * Provides sendSystemEmail() — a soft-fail wrapper around PHPMailer.
 * Rules:
 *   - Never throws. A failed email is logged and returns false.
 *   - Never blocks or rolls back a database transaction.
 *   - Called AFTER $pdo->commit() in all action files.
 *   - SMTP credentials are read from config/mailer.php.
 */

function sendSystemEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainText = ''
): bool {
    // Guard: skip silently if email is empty or obviously invalid
    if ($toEmail === '' || strpos($toEmail, '@') === false) {
        error_log('[Mailer] Skipped: invalid recipient address "' . $toEmail . '".');
        return false;
    }

    // Load PHPMailer source files (manual install — no Composer)
    $phpmailerSrc = __DIR__ . '/vendor/phpmailer/src';
    $required = [
        $phpmailerSrc . '/Exception.php',
        $phpmailerSrc . '/PHPMailer.php',
        $phpmailerSrc . '/SMTP.php',
    ];
    foreach ($required as $file) {
        if (!file_exists($file)) {
            error_log('[Mailer] PHPMailer source file missing: ' . $file);
            return false;
        }
        require_once $file;
    }

    // Load SMTP configuration
    $configPath = __DIR__ . '/../config/mailer.php';
    if (!file_exists($configPath)) {
        error_log('[Mailer] config/mailer.php not found.');
        return false;
    }
    $cfg = require $configPath;

    // Skip sending if credentials are still the placeholder values
    if (
        empty($cfg['username']) ||
        $cfg['username'] === 'your_email@gmail.com' ||
        empty($cfg['password']) ||
        $cfg['password'] === 'your_app_password_here'
    ) {
        error_log('[Mailer] SMTP credentials not configured in config/mailer.php. Email to "' . $toEmail . '" was NOT sent.');
        return false;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['encryption'] === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$cfg['port'];
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 10; // seconds — prevent long hangs during web requests

        // SSL verification options:
        // Set verify_peer => false for local development / XAMPP on Windows or when
        // antivirus SSL scanning (Avast/AVG Mail Shield) intercepts outgoing TLS traffic.
        if (isset($cfg['ssl_options']) && is_array($cfg['ssl_options'])) {
            $mail->SMTPOptions = $cfg['ssl_options'];
        } elseif (empty($cfg['verify_peer'])) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        // Sender
        $mail->setFrom($cfg['from_address'], $cfg['from_name']);
        $mail->addReplyTo($cfg['from_address'], $cfg['from_name']);

        // Recipient
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainText !== ''
            ? $plainText
            : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
        return true;

    } catch (\Throwable $e) {
        error_log('[Mailer] Failed to send email to "' . $toEmail . '": ' . $e->getMessage());
        return false;
    }
}

/**
 * buildEmailHtml()
 * Wraps a plain title + body text into a clean branded HTML email shell.
 * Used by createNotification() to auto-generate email content from the
 * same title/message already shown in the in-app notification.
 */
function buildEmailHtml(string $recipientName, string $title, string $message): string
{
    $safeTitle    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage  = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeName     = htmlspecialchars($recipientName ?: 'Applicant', ENT_QUOTES, 'UTF-8');
    $year         = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:32px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

          <!-- Header -->
          <tr>
            <td style="background-color:#0f7b6c;padding:28px 32px;text-align:center;">
              <p style="margin:0;color:#ffffff;font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:0.85;">NCST Maritime Academy</p>
              <h1 style="margin:8px 0 0;color:#ffffff;font-size:22px;font-weight:700;line-height:1.3;">{$safeTitle}</h1>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px 32px 24px;">
              <p style="margin:0 0 16px;color:#374151;font-size:15px;">Dear {$safeName},</p>
              <p style="margin:0 0 24px;color:#374151;font-size:15px;line-height:1.7;">{$safeMessage}</p>
              <p style="margin:0;color:#374151;font-size:15px;">
                Please log in to the <a href="#" style="color:#0f7b6c;text-decoration:none;font-weight:600;">Maritime Academy Portal</a>
                for more details.
              </p>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding:0 32px;">
              <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;">
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:20px 32px;text-align:center;">
              <p style="margin:0;color:#9ca3af;font-size:12px;line-height:1.6;">
                This is an automated message from the NCST Maritime Academy Enrollment System.<br>
                Please do not reply directly to this email.<br>
                &copy; {$year} NCST Maritime Academy. All rights reserved.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}
