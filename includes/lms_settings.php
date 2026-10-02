<?php
/**
 * LMS Settings Helper Module (TASK 4.1)
 * Provides centralized retrieval and caching of LMS system settings,
 * including quiz defaults, file upload limits, notification toggles,
 * and institutional grading scales.
 */

if (!function_exists('getAllLmsSettings')) {
    /**
     * Retrieve all LMS settings from database as an associative key => value map.
     *
     * @param PDO $pdo
     * @return array<string, string>
     */
    function getAllLmsSettings(PDO $pdo, bool $refresh = false): array
    {
        static $cached = null;
        if ($cached !== null && !$refresh) {
            return $cached;
        }

        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM lms_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $cached = is_array($rows) ? $rows : [];
            return $cached;
        } catch (Throwable $e) {
            error_log("Failed to fetch LMS settings: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getLmsSetting')) {
    /**
     * Retrieve a specific LMS setting value by key with optional fallback.
     *
     * @param PDO $pdo
     * @param string $key
     * @param mixed $default
     * @param bool $refresh
     * @return mixed
     */
    function getLmsSetting(PDO $pdo, string $key, mixed $default = null, bool $refresh = false): mixed
    {
        $all = getAllLmsSettings($pdo, $refresh);
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}

if (!function_exists('saveLmsSettings')) {
    /**
     * Persist multiple LMS settings in a transaction.
     *
     * @param PDO $pdo
     * @param array<string, string> $settings
     * @param int|null $adminId
     * @return void
     */
    function saveLmsSettings(PDO $pdo, array $settings, ?int $adminId = null): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO lms_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (:key, :value, :admin_id, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");

        $pdo->beginTransaction();
        try {
            foreach ($settings as $key => $value) {
                $stmt->execute([
                    'key' => (string)$key,
                    'value' => (string)$value,
                    'admin_id' => $adminId
                ]);
            }
            $pdo->commit();
            // Invalidate in-memory cache
            getAllLmsSettings($pdo, true);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
