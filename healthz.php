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

// Debug mode — only when ?debug=1&key=KEY is provided
$debugKey = getenv('SETUP_KEY') ?: getenv('ADMIN_SECRET') ?: 'debug123';
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
