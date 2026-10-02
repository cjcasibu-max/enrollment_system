<?php
/**
 * Database-backed PHP Session Handler
 *
 * Stores sessions in the `php_sessions` table instead of the filesystem.
 * This ensures sessions persist across container restarts on cloud platforms.
 *
 * IMPORTANT: This file must be included BEFORE session_start() is called,
 * and from GLOBAL SCOPE (not inside a function), because database.php uses
 * `global $pdo` which requires global scope to work.
 *
 * It is included from config/database.php on non-localhost environments.
 */

// Only register if $pdo is available (set by database.php) and session not started
if (!isset($pdo) || !($pdo instanceof PDO) || session_status() !== PHP_SESSION_NONE) {
    return;
}

class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private int $lifetime;

    public function __construct(PDO $pdo, int $lifetime = 7200)
    {
        $this->pdo      = $pdo;
        $this->lifetime = $lifetime;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT data FROM php_sessions WHERE id = :id AND expires_at > NOW() LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['data'] : '';
        } catch (Throwable $e) {
            error_log('[DbSession] read error: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $exp = date('Y-m-d H:i:s', time() + $this->lifetime);
            $this->pdo->prepare(
                'INSERT INTO php_sessions (id, data, expires_at) VALUES (:id, :data, :exp)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)'
            )->execute(['id' => $id, 'data' => $data, 'exp' => $exp]);
            return true;
        } catch (Throwable $e) {
            error_log('[DbSession] write error: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->pdo->prepare('DELETE FROM php_sessions WHERE id = :id')->execute(['id' => $id]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE expires_at <= NOW()');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) { return false; }
    }
}

// Create the sessions table if missing (safe to run every boot)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS php_sessions (
            id         VARCHAR(128)  NOT NULL PRIMARY KEY,
            data       MEDIUMTEXT    NOT NULL,
            expires_at DATETIME      NOT NULL,
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    session_set_save_handler(new DbSessionHandler($pdo), true);
} catch (Throwable $e) {
    error_log('[DbSession] Could not register handler: ' . $e->getMessage());
    // Falls back to file-based sessions automatically
}
