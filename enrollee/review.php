<?php
/**
 * Review Application — merged into Application Form.
 * This shim redirects any old bookmarks to apply.php which now
 * contains the full Preview modal inline.
 */
require_once '../includes/auth_check.php';
checkRole(['enrollee']);
header('Location: apply');
exit;
