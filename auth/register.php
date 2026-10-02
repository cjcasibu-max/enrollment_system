<?php
/**
 * Backward-compatibility redirect stub for legacy registration bookmarks.
 * Direct application links now route directly to auth/login?mode=register.
 */
header('Location: login?mode=register');
exit;
