<?php
/**
 * Database Connection Configuration
 * Uses PDO for secure database access.
 * 
 * Connection-only: Schema migrations belong exclusively in
 * database/migrate.php (CLI) or initial database/schema.sql.
 */

require_once __DIR__ . '/env.php';

global $pdo;

if (!isset($pdo)) {
    // Support DATABASE_URL or MYSQL_URL if provided by cloud hosting (Render, Railway, Aiven, etc.)
    $dbUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: '';
    if ($dbUrl !== '') {
        $parsed = parse_url($dbUrl);
        if ($parsed && isset($parsed['host'])) {
            $host = $parsed['host'];
            $port = (int)($parsed['port'] ?? 3306);
            $dbname = ltrim($parsed['path'] ?? '', '/');
            $username = isset($parsed['user']) ? urldecode($parsed['user']) : 'root';
            $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
        }
    }

    if (!isset($host)) {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $dbname = getenv('DB_NAME') ?: 'enrollment_system';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    }

    $charset = 'utf8mb4';
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 8,
    ];

    // Optional SSL flags for cloud databases (TiDB Cloud, Aiven, Clever Cloud, etc.)
    if (getenv('DB_SSL') === 'true' || getenv('MYSQL_ATTR_SSL_CA') !== false) {
        $sslCa = getenv('MYSQL_ATTR_SSL_CA') ?: '';
        if ($sslCa !== '' && file_exists($sslCa)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        } elseif (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
            // Standard Debian/Ubuntu CA bundle inside Linux Docker containers
            $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        } else {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    global $dbConnectionError;
    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (\PDOException $e) {
        $dbConnectionError = $e->getMessage();
        error_log("Database connection failed: " . $e->getMessage());
        if (php_sapi_name() === 'cli' || !empty($suppressDbDie)) {
            throw $e;
        }
        if (getenv('APP_DEBUG') === 'true') {
            die("<h3>Database connection failed:</h3><p style='color:red;font-family:monospace;'>" 
                . htmlspecialchars($e->getMessage()) . "</p>"
                . "<p><strong>Configured Target:</strong> " . htmlspecialchars($host) . ":" . htmlspecialchars((string)$port) 
                . " | DB: " . htmlspecialchars($dbname) . " | User: " . htmlspecialchars($username) . "</p>"
                . "<p>Check your Render Environment Variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSL).</p>");
        }
        die("Database connection failed. Please contact the system administrator.");
    }
}
