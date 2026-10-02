<?php
/**
 * Migration: Payment Proof and Review Status
 * NCST Maritime Academy Enrollment System
 *
 * Ensures payments table has proof_file column and or_status includes 'rejected'.
 * Also verifies validated_at, validated_by, and validation_notes columns exist.
 * Idempotent: safe to run multiple times without changing existing data or schemas.
 *
 * Usage:
 *   php database/migrate_payment_review.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Error: This migration script may only be executed from the command line.\n");
}

echo "=== NCST Maritime Academy - Payment Review Schema Migration ===\n";

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

try {
    $changesMade = 0;

    // 1. Check and add proof_file column
    echo "[*] Step 1: Checking payments.proof_file column...\n";
    $proofCheck = $pdo->query("SHOW COLUMNS FROM payments LIKE 'proof_file'")->fetch(PDO::FETCH_ASSOC);
    if (!$proofCheck) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN proof_file VARCHAR(255) NULL AFTER notes");
        echo "    -> Added proof_file column to payments.\n";
        $changesMade++;
    } else {
        echo "    -> payments.proof_file already exists. No change needed.\n";
    }

    // 2. Check and update or_status enum
    echo "[*] Step 2: Checking payments.or_status enum definition...\n";
    $statusCheck = $pdo->query("SHOW COLUMNS FROM payments LIKE 'or_status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCheck) {
        if (strpos($statusCheck['Type'], "'rejected'") === false) {
            $pdo->exec("ALTER TABLE payments MODIFY COLUMN or_status ENUM('pending','validated','voided','rejected') NOT NULL DEFAULT 'pending'");
            echo "    -> Updated payments.or_status enum to ('pending','validated','voided','rejected').\n";
            $changesMade++;
        } else {
            echo "    -> payments.or_status already contains 'rejected'. No change needed.\n";
        }
    } else {
        throw new Exception("payments.or_status column does not exist!");
    }

    // 3. Check review audit columns (validated_at, validated_by, validation_notes)
    echo "[*] Step 3: Checking review audit columns...\n";
    $hasValidatedAt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'validated_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasValidatedAt) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN validated_at TIMESTAMP NULL AFTER or_status");
        echo "    -> Added validated_at column to payments.\n";
        $changesMade++;
    } else {
        echo "    -> payments.validated_at already exists.\n";
    }

    $hasValidatedBy = $pdo->query("SHOW COLUMNS FROM payments LIKE 'validated_by'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasValidatedBy) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN validated_by INT UNSIGNED NULL AFTER validated_at");
        $pdo->exec("ALTER TABLE payments ADD CONSTRAINT fk_payments_validated_by FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "    -> Added validated_by column and FK to payments.\n";
        $changesMade++;
    } else {
        echo "    -> payments.validated_by already exists.\n";
    }

    $hasValidationNotes = $pdo->query("SHOW COLUMNS FROM payments LIKE 'validation_notes'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasValidationNotes) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN validation_notes TEXT NULL AFTER validated_by");
        echo "    -> Added validation_notes column to payments.\n";
        $changesMade++;
    } else {
        echo "    -> payments.validation_notes already exists.\n";
    }

    echo "\n[+] Migration completed successfully. Total schema changes applied: {$changesMade}.\n";

} catch (\Throwable $e) {
    echo "[-] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
