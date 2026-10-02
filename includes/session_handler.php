<?php
/**
 * Database-backed PHP Session Handler
 *
 * Stores sessions in the `php_sessions` table instead of the filesystem.
 * This ensures sessions persist across container restarts and work correctly
 * on cloud platforms like Render where the filesystem is ephemeral.
 *
 * Usage: include this file BEFORE session_start() is called.
 * The startSecureSession() function in auth_check.php will call it automatically
 * when the DB is available.
 */

class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private int $lifetime;

    public function __construct(PDO $pdo, int $lifetime = 0)
    {
        $this->pdo      = $pdo;
        $this->lifetime = $lifetime > 0 ? $lifetime : (int)ini_get('session.gc_maxlifetime');
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

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
            error_log('[DbSessionHandler] read error: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + $this->lifetime);
            $stmt = $this->pdo->prepare(
                'INSERT INTO php_sessions (id, data, expires_at)
                 VALUES (:id, :data, :exp)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)'
            );
            $stmt->execute(['id' => $id, 'data' => $data, 'exp' => $expiresAt]);
            return true;
        } catch (Throwable $e) {
            error_log('[DbSessionHandler] write error: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE id = :id');
            $stmt->execute(['id' => $id]);
            return true;
        } catch (Throwable $e) {
            error_log('[DbSessionHandler] destroy error: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE expires_at <= NOW()');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[DbSessionHandler] gc error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Creates the php_sessions table if it doesn't exist and registers the handler.
 * Returns true on success, false on failure (falls back to file sessions gracefully).
 */
function registerDbSessionHandler(PDO $pdo): bool
{
    try {
        // Create sessions table if not present (idempotent)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS php_sessions (
                id         VARCHAR(128)  NOT NULL PRIMARY KEY,
                data       MEDIUMTEXT    NOT NULL,
                expires_at DATETIME      NOT NULL,
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $handler = new DbSessionHandler($pdo, 7200); // 2-hour session lifetime
        session_set_save_handler($handler, true);
        return true;
    } catch (Throwable $e) {
        error_log('[DbSessionHandler] Could not register DB session handler: ' . $e->getMessage());
        return false;
    }
}
