<?php
/**
 * Application Status Page — merged into Applicant Dashboard.
 * This shim redirects any old links or bookmarks to dashboard.php
 * which now hosts the status view modal.
 */
require_once '../includes/auth_check.php';
checkRole(['enrollee']);
header('Location: dashboard');
exit;
