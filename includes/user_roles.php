<?php
/**
 * User role utilities.
 * Provides small helpers to promote users to student and sync session role from DB.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Do not start session here by default; caller may start session when appropriate.
}

/**
 * Promote a user to the 'student' role if they are currently an 'enrollee'.
 * Returns true if the update affected a row, false otherwise.
 */
function promoteUserToStudent(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE users SET role = 'student' WHERE id = :id AND role = 'enrollee'");
    $stmt->execute(['id' => $userId]);
    return $stmt->rowCount() === 1;
}

/**
 * Sync the current PHP session's role with the DB for the specified user id.
 * Returns the role string from DB or null if not found.
 */
function syncSessionRoleFromDb(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $role = $stmt->fetchColumn();
    if ($role !== false && session_status() !== PHP_SESSION_NONE) {
        $_SESSION['role'] = $role;
    }

    return $role !== false ? (string)$role : null;
}
