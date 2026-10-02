<?php
/**
 * Render Health Check Endpoint
 * Returns HTTP 200 when service is running.
 * Also checks database connectivity.
 * ?debug=1&key=YOUR_KEY — shows user/password debug info
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$suppressDbDie = true;
$dbConnected = false;
$dbMessage = 'unconfigured';

try {
    require_once __DIR__ . '/config/database.php';
    if (isset($pdo)) {
        $pdo->query('SELECT 1');
        $dbConnected = true;
        $dbMessage = 'connected';
    }
} catch (Throwable $e) {
    $dbMessage = 'error: ' . $e->getMessage();
}

$isConfigured = (getenv('DB_HOST') !== false || getenv('DATABASE_URL') !== false || getenv('MYSQL_URL') !== false);
$isHealthy = $dbConnected || !$isConfigured;

$debugKey = getenv('SETUP_KEY') ?: getenv('ADMIN_SECRET') ?: 'debug123';

// Fix mode — resets demo passwords directly in TiDB
if (isset($_GET['fix']) && ($_GET['key'] ?? '') === $debugKey && $dbConnected) {
    $demoPassword = 'Demo@12345';
    $hash = password_hash($demoPassword, PASSWORD_DEFAULT);
    $results = [];
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $results['total_users_before'] = $total;

        // Update existing demo accounts
        $upd = $pdo->prepare("UPDATE users SET password_hash = :h, is_active = 1 WHERE username LIKE 'demo_%'");
        $upd->execute(['h' => $hash]);
        $results['demo_accounts_updated'] = $upd->rowCount();

        // If no demo users exist, insert them
        if ($upd->rowCount() === 0) {
            $accounts = [
                ['demo_admin',    'admin@ncst.demo',    'admin',     'Admin',    'Demo'],
                ['demo_registrar','reg@ncst.demo',      'registrar', 'Registrar','Demo'],
                ['demo_cashier',  'cash@ncst.demo',     'cashier',   'Cashier',  'Demo'],
                ['demo_teacher1', 'teacher1@ncst.demo', 'teacher',   'Teacher',  'One'],
                ['demo_student1', 'student1@ncst.demo', 'student',   'Student',  'One'],
                ['demo_student2', 'student2@ncst.demo', 'student',   'Student',  'Two'],
                ['demo_student3', 'student3@ncst.demo', 'student',   'Student',  'Three'],
            ];
            $ins = $pdo->prepare("INSERT IGNORE INTO users (username, email, password_hash, role, first_name, last_name, is_active) VALUES (?,?,?,?,?,?,1)");
            foreach ($accounts as $a) {
                $ins->execute([$a[0], $a[1], $hash, $a[2], $a[3], $a[4]]);
            }
            $results['accounts_inserted'] = count($accounts);
        }

        // Verify
        $stmt = $pdo->prepare("SELECT id, username, role, is_active, password_hash FROM users WHERE username LIKE 'demo_%' ORDER BY username LIMIT 30");
        $stmt->execute();
        $verified = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ok = password_verify($demoPassword, $r['password_hash']);
            $verified[] = [
                'username'  => $r['username'],
                'role'      => $r['role'],
                'is_active' => $r['is_active'],
                'pass_ok'   => $ok,
            ];
        }
        $results['verified'] = $verified;
        $results['status'] = 'done';
    } catch (Throwable $e) {
        $results['error'] = $e->getMessage();
    }
    echo json_encode(['fix_mode' => true, 'db_name' => getenv('DB_NAME'), 'results' => $results], JSON_PRETTY_PRINT);
    exit;
}

// Debug mode — shows user info
if (isset($_GET['debug']) && ($_GET['key'] ?? '') === $debugKey && $dbConnected) {
    $testUser = $_GET['u'] ?? 'demo_student1';
    $testPass = $_GET['p'] ?? 'Demo@12345';
    $debugInfo = [];
    try {
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $debugInfo['total_users'] = $totalUsers;
        $stmt = $pdo->prepare("SELECT id, username, role, is_active, password_hash FROM users WHERE username = :u LIMIT 1");
        $stmt->execute(['u' => $testUser]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $passOk = password_verify($testPass, $user['password_hash']);
            $debugInfo['user_found']     = true;
            $debugInfo['user_id']        = $user['id'];
            $debugInfo['username']       = $user['username'];
            $debugInfo['role']           = $user['role'];
            $debugInfo['is_active']      = $user['is_active'];
            $debugInfo['hash_prefix']    = substr($user['password_hash'], 0, 20) . '...';
            $debugInfo['password_verify'] = $passOk ? 'PASS' : 'FAIL';
        } else {
            $debugInfo['user_found'] = false;
            $debugInfo['note'] = "User '$testUser' not found in DB";
            // List all users
            $allUsers = $pdo->query("SELECT id, username, role FROM users LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
            $debugInfo['all_users'] = $allUsers;
        }
    } catch (Throwable $e) {
        $debugInfo['error'] = $e->getMessage();
    }

    echo json_encode([
        'debug'    => true,
        'database' => $dbMessage,
        'db_name'  => getenv('DB_NAME') ?: 'not set',
        'db_host'  => getenv('DB_HOST') ?: 'not set',
        'test_user'=> $testUser,
        'test_pass'=> $testPass,
        'result'   => $debugInfo,
    ], JSON_PRETTY_PRINT);
    exit;
}

http_response_code($isHealthy ? 200 : 503);
echo json_encode([
    'status'    => $isHealthy ? 'ok' : 'degraded',
    'app'       => 'NCST Maritime Academy Enrollment System',
    'database'  => $dbMessage,
    'details'   => [
        'host'     => getenv('DB_HOST') ?: 'not set',
        'port'     => getenv('DB_PORT') ?: '3306',
        'user'     => getenv('DB_USER') ?: 'not set',
        'database' => getenv('DB_NAME') ?: 'not set',
        'ssl'      => getenv('DB_SSL') ?: 'false',
    ],
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT);
exit;
