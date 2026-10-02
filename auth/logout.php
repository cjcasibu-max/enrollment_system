<?php
/**
 * Logout Page
 * Requires a CSRF-verified POST request. Gracefully destroys the session,
 * removes session cookies, and redirects to the login page.
 */

require_once '../includes/auth_check.php';

// Only accept POST with a valid CSRF token — blocks logout CSRF via <img> / GET links (Fix-C).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? null)) {
    // Silently redirect to login; attacker learns nothing useful.
    header('Location: ' . resolveAppUrl('auth/login'));
    exit;
}

// 1. Unset all session variables
$_SESSION = array();

// 2. Destroy the session cookie if active
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destroy the actual session on the server
session_destroy();

// 4. Start a fresh, clean session to pass a logout success flash message
startSecureSession();
$_SESSION['flash_success'] = "You have been successfully logged out.";

// 5. Redirect back to login screen
header("Location: " . resolveAppUrl('auth/login'));
exit;
