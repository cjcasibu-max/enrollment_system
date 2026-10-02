<?php
/**
 * Login Diagnostic — shows exactly why login fails.
 * Protected by SETUP_KEY env var.
 * DELETE this file after debugging!
 */

// Access guard
$key = getenv('SETUP_KEY') ?: getenv('ADMIN_SECRET') ?: 'debug123';
if (($_GET['key'] ?? '') !== $key) {
    http_response_code(403);
    die("403 Forbidden. Add ?key=YOUR_SETUP_KEY to the URL.");
}

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/database.php';

$username = $_GET['u'] ?? 'demo_student1';
$password = $_GET['p'] ?? 'Demo@12345';

echo "=== LOGIN DIAGNOSTIC ===\n";
echo "Testing user: $username\n";
echo "Testing password: $password\n\n";

// 1. DB connection
if (!isset($pdo)) {
    die("FAIL: No DB connection.\n");
}
echo "OK: DB connected.\n";

// 2. Check users table exists
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "OK: users table has $cnt rows.\n";
} catch (Throwable $e) {
    echo "FAIL: Cannot query users table: " . $e->getMessage() . "\n";
    die();
}

// 3. Find the user
try {
    $stmt = $pdo->prepare("SELECT id, username, email, password_hash, role, is_active FROM users WHERE username = :u OR email = :e LIMIT 1");
    $stmt->execute(['u' => $username, 'e' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "FAIL: Query error: " . $e->getMessage() . "\n";
    die();
}

if (!$user) {
    echo "FAIL: User '$username' NOT FOUND in users table.\n";
    echo "\nAll usernames in DB:\n";
    foreach ($pdo->query("SELECT id, username, role, is_active FROM users LIMIT 30")->fetchAll() as $row) {
        echo "  id={$row['id']} username={$row['username']} role={$row['role']} is_active={$row['is_active']}\n";
    }
    die();
}

echo "OK: User found.\n";
echo "  id       = {$user['id']}\n";
echo "  username = {$user['username']}\n";
echo "  email    = {$user['email']}\n";
echo "  role     = {$user['role']}\n";
echo "  is_active= {$user['is_active']}\n";
echo "  hash     = " . substr($user['password_hash'], 0, 20) . "...\n\n";

// 4. Verify password
$ok = password_verify($password, $user['password_hash']);
echo ($ok ? "OK" : "FAIL") . ": password_verify('$password', hash) => " . ($ok ? "true" : "false") . "\n";

if (!$ok) {
    // Try common alternatives
    foreach (['Demo@12345', 'demo@12345', 'Demo@123', 'password', '12345678'] as $alt) {
        if (password_verify($alt, $user['password_hash'])) {
            echo "  NOTE: password '$alt' DOES match!\n";
        }
    }
}

// 5. is_active check
if ((int)$user['is_active'] !== 1) {
    echo "FAIL: User is_active = {$user['is_active']} (must be 1).\n";
} else {
    echo "OK: is_active = 1.\n";
}

// 6. Session write test
session_start();
$_SESSION['test_diag'] = 'works_' . time();
session_write_close();
echo "\nOK: Session write completed (session_id=" . session_id() . ").\n";

// 7. php_sessions table
try {
    $cnt2 = $pdo->query("SELECT COUNT(*) FROM php_sessions")->fetchColumn();
    echo "OK: php_sessions table exists with $cnt2 rows.\n";
} catch (Throwable $e) {
    echo "NOTE: php_sessions table does not exist yet: " . $e->getMessage() . "\n";
    echo "  (It will be created automatically on first login after the fix is deployed.)\n";
}

echo "\n=== DIAGNOSIS COMPLETE ===\n";
echo "If password_verify passed AND is_active=1, the bug is in session/CSRF handling.\n";
echo "If user not found, the seed did not run — go to /database/setup_cloud_db.php?key=KEY&seed_demo=1\n";
