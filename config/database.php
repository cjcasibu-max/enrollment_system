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
    ];

    // Optional SSL flags for cloud databases (Aiven, TiDB, Clever Cloud, etc.)
    if (getenv('DB_SSL') === 'true' || getenv('MYSQL_ATTR_SSL_CA') !== false) {
        $sslCa = getenv('MYSQL_ATTR_SSL_CA') ?: '';
        if ($sslCa !== '' && file_exists($sslCa)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        } else {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (\PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        if (php_sapi_name() === 'cli') {
            throw $e;
        }
        die("Database connection failed. Please contact the system administrator.");
    }
}
