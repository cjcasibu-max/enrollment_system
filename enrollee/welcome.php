<?php
/**
 * Welcome page — merged into dashboard.
 * This file is kept as a redirect shim so that any old bookmarks or
 * session redirects pointing here still land on the correct page.
 */
require_once '../includes/auth_check.php';
checkRole(['enrollee']);
header('Location: dashboard');
exit;
