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
    $host = getenv('DB_HOST') ?: 'localhost';
    $dbname = getenv('DB_NAME') ?: 'enrollment_system';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (\PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed. Please contact the system administrator.");
    }
}
