<?php
/**
 * Render Health Check Endpoint
 * 
 * Returns HTTP 200 when service is running.
 * Also checks database connectivity.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$dbConnected = false;
$dbMessage = 'unconfigured';
$suppressDbDie = true;

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

http_response_code($isHealthy ? 200 : 503);
echo json_encode([
    'status'    => $isHealthy ? 'ok' : 'degraded',
    'app'       => 'NCST Maritime Academy Enrollment System',
    'database'  => $dbMessage,
    'details'   => [
        'host' => getenv('DB_HOST') ?: 'not set',
        'port' => getenv('DB_PORT') ?: '3306',
        'user' => getenv('DB_USER') ?: 'not set',
        'database' => getenv('DB_NAME') ?: 'not set',
        'ssl' => getenv('DB_SSL') ?: 'false',
    ],
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT);
exit;
