<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store');

echo "=== NCST Enrollment System - Cloud Database Diagnostics ===\n\n";

$host = getenv('DB_HOST') ?: 'not set';
$port = (int)(getenv('DB_PORT') ?: 3306);
$db   = getenv('DB_NAME') ?: 'not set';
$user = getenv('DB_USER') ?: 'not set';
$ssl  = getenv('DB_SSL') ?: 'false';
$passLen = (getenv('DB_PASS') !== false) ? strlen(getenv('DB_PASS')) : 0;

echo "1. Environment Variables Configured:\n";
echo "   DB_HOST : {$host}\n";
echo "   DB_PORT : {$port}\n";
echo "   DB_NAME : {$db}\n";
echo "   DB_USER : {$user}\n";
echo "   DB_PASS : " . ($passLen > 0 ? "Provided ({$passLen} characters)" : "NOT SET or EMPTY") . "\n";
echo "   DB_SSL  : {$ssl}\n";
echo "   AUTO_INIT: " . (getenv('DB_AUTO_INIT') ?: 'not set') . "\n\n";

if ($host === 'not set' || $user === 'not set') {
    echo "[!] DB_HOST or DB_USER is not configured in Render Environment Variables.\n";
    echo "    Please go to Render Dashboard -> Your Service -> Environment, and enter them.\n";
    exit;
}

echo "2. Testing DNS resolution for {$host}...\n";
$ip = gethostbyname($host);
echo "   Resolved IP: {$ip}\n\n";

echo "3. Testing TCP socket connection to {$host}:{$port}...\n";
$socketTimeout = 5;
$fp = @fsockopen($host, $port, $errno, $errstr, $socketTimeout);
if (!$fp) {
    echo "   [ERROR] Cannot open TCP connection to {$host}:{$port} (Error {$errno}: {$errstr})\n";
    echo "   Possible causes:\n";
    echo "     - Wrong DB_HOST or DB_PORT\n";
    echo "     - TiDB Cloud firewall: IP Access List does not allow 0.0.0.0/0\n";
    echo "     - Cluster is paused or sleeping\n\n";
} else {
    echo "   [OK] TCP connection to {$host}:{$port} succeeded!\n\n";
    fclose($fp);
}

echo "4. Testing MySQL PDO connection...\n";
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 7,
];

if ($ssl === 'true') {
    if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        echo "   [+] Using system CA bundle: /etc/ssl/certs/ca-certificates.crt\n";
    } else {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        echo "   [*] Using SSL without explicit CA bundle\n";
    }
}

try {
    $pdo = new PDO($dsn, $user, getenv('DB_PASS') ?: '', $options);
    echo "   [SUCCESS] Connected to database successfully!\n\n";
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "5. Tables in database '{$db}' (" . count($tables) . " tables found):\n";
    foreach (array_slice($tables, 0, 15) as $t) {
        echo "   - {$t}\n";
    }
    
    // Check record counts
    $userCount = 0;
    $termCount = 0;
    $subjCount = 0;
    if (in_array('users', $tables, true)) {
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }
    if (in_array('academic_terms', $tables, true)) {
        $termCount = (int)$pdo->query("SELECT COUNT(*) FROM academic_terms")->fetchColumn();
    }
    if (in_array('subjects', $tables, true)) {
        $subjCount = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
    }
    echo "\n6. Record Counts:\n";
    echo "   Users          : {$userCount}\n";
    echo "   Academic Terms : {$termCount}\n";
    echo "   Subjects       : {$subjCount}\n";

    if (isset($_GET['action']) && $_GET['action'] === 'seed') {
        echo "\n[*] Running database/setup_cloud_db.php directly...\n";
        require_once __DIR__ . '/database/setup_cloud_db.php';
    } elseif ($userCount === 0) {
        echo "\n[TIP] Database is connected! To seed curriculum, subjects, and all demo accounts,\n";
        echo "      open: https://enrollment-system-0bz0.onrender.com/db_test.php?action=seed\n";
    }
} catch (Throwable $e) {
    echo "   [ERROR] PDO connection failed:\n";
    echo "   " . $e->getMessage() . "\n\n";
    echo "   Troubleshooting:\n";
    if (str_contains($e->getMessage(), 'Access denied')) {
        echo "   -> Access denied: Double check DB_USER (remember cluster prefix, e.g. xxx.root) and DB_PASS.\n";
    } elseif (str_contains($e->getMessage(), 'Unknown database')) {
        echo "   -> Unknown database: Change DB_NAME to 'test' or create the database in TiDB Cloud.\n";
    } elseif (str_contains($e->getMessage(), 'Connection timed out') || str_contains($e->getMessage(), 'Operation timed out')) {
        echo "   -> Network timeout: In TiDB Cloud, check IP Access List and allow 0.0.0.0/0.\n";
    } elseif (str_contains($e->getMessage(), 'SSL')) {
        echo "   -> SSL error: Ensure DB_SSL=true.\n";
    }
}
