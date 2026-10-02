<?php
/**
 * Emergency: Reset demo account passwords and create them if missing.
 * Protected by SETUP_KEY. Delete after use.
 */
$key = getenv('SETUP_KEY') ?: getenv('ADMIN_SECRET') ?: 'debug123';
if (($_GET['key'] ?? '') !== $key) {
    http_response_code(403);
    die("403 Forbidden. Add ?key=YOUR_SETUP_KEY to URL.");
}

header('Content-Type: text/plain; charset=utf-8');

// Load DB directly — bypass auth_check.php entirely
require_once __DIR__ . '/config/database.php';

if (!isset($pdo)) {
    die("FAIL: No DB connection.\n");
}
echo "OK: DB connected.\n\n";

$demoPassword = 'Demo@12345';
$hash = password_hash($demoPassword, PASSWORD_DEFAULT);

// Verify the hash we just created works
if (!password_verify($demoPassword, $hash)) {
    die("FAIL: password_hash/verify broken on this server.\n");
}
echo "OK: password_hash works. Hash prefix: " . substr($hash, 0, 20) . "...\n\n";

// All demo accounts
$demoAccounts = [
    ['username' => 'demo_admin',      'email' => 'admin@ncst.demo',      'role' => 'admin',     'first_name' => 'Admin',    'last_name' => 'Demo'],
    ['username' => 'demo_registrar',  'email' => 'registrar@ncst.demo',  'role' => 'registrar', 'first_name' => 'Registrar','last_name' => 'Demo'],
    ['username' => 'demo_cashier',    'email' => 'cashier@ncst.demo',    'role' => 'cashier',   'first_name' => 'Cashier',  'last_name' => 'Demo'],
    ['username' => 'demo_teacher1',   'email' => 'teacher1@ncst.demo',   'role' => 'teacher',   'first_name' => 'Teacher',  'last_name' => 'One'],
    ['username' => 'demo_student1',   'email' => 'student1@ncst.demo',   'role' => 'student',   'first_name' => 'Student',  'last_name' => 'One'],
    ['username' => 'demo_student2',   'email' => 'student2@ncst.demo',   'role' => 'student',   'first_name' => 'Student',  'last_name' => 'Two'],
    ['username' => 'demo_student3',   'email' => 'student3@ncst.demo',   'role' => 'student',   'first_name' => 'Student',  'last_name' => 'Three'],
];

echo "=== Fixing demo account passwords ===\n";
foreach ($demoAccounts as $acc) {
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1");
    $stmt->execute(['u' => $acc['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Verify current hash
        $currentOk = password_verify($demoPassword, $user['password_hash']);
        // Always update to fresh hash
        $upd = $pdo->prepare("UPDATE users SET password_hash = :h, is_active = 1 WHERE username = :u");
        $upd->execute(['h' => $hash, 'u' => $acc['username']]);
        echo ($currentOk ? "[WAS OK] " : "[FIXED]  ") . $acc['username'] . " — password reset to Demo@12345, is_active=1\n";
    } else {
        // Insert missing user
        $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active) VALUES (:u,:e,:h,:r,:f,:l,1)");
        $ins->execute([
            'u' => $acc['username'], 'e' => $acc['email'],
            'h' => $hash, 'r' => $acc['role'],
            'f' => $acc['first_name'], 'l' => $acc['last_name']
        ]);
        echo "[CREATED] " . $acc['username'] . " created with role=" . $acc['role'] . "\n";
    }
}

echo "\n=== Verifying final state ===\n";
$rows = $pdo->query("SELECT id, username, role, is_active FROM users WHERE username LIKE 'demo_%' ORDER BY role")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    // Re-check password
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
    $stmt->execute(['id' => $r['id']]);
    $h = $stmt->fetchColumn();
    $ok = password_verify($demoPassword, $h);
    echo "  " . ($ok ? "✓" : "✗") . " id={$r['id']} username={$r['username']} role={$r['role']} is_active={$r['is_active']} pass=" . ($ok ? "OK" : "WRONG") . "\n";
}

echo "\n=== Total users in DB: " . $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() . " ===\n";
echo "\nDONE. Try logging in at /auth/login with:\n";
echo "  Username: demo_student1\n";
echo "  Password: Demo@12345\n";
