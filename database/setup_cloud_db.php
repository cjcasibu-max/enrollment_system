<?php
/**
 * Cloud Database Initializer & Migration Runner
 * 
 * Safely initializes a fresh cloud MySQL database (Render, Aiven, Railway, TiDB, etc.)
 * by importing schema.sql (without hardcoded CREATE/USE statements), running migrations,
 * and optionally seeding curriculum and demo accounts.
 * 
 * Usage:
 *   CLI: php database/setup_cloud_db.php [--seed-demo]
 *   Web: https://your-app.onrender.com/database/setup_cloud_db.php?key=YOUR_SETUP_KEY
 */

$isCli = (php_sapi_name() === 'cli');

// If accessed via web, require SETUP_KEY or DB_AUTO_INIT
if (!$isCli) {
    $expectedKey = getenv('SETUP_KEY') ?: getenv('ADMIN_SECRET') ?: '';
    $providedKey = $_GET['key'] ?? '';
    if ($expectedKey === '' || !hash_equals($expectedKey, (string)$providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die("Access denied. Please run this script via Render Shell (CLI) or provide the correct ?key= parameter.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "========================================================\n";
echo " NCST Maritime Academy - Cloud Database Setup\n";
echo "========================================================\n";

require_once __DIR__ . '/../config/database.php';

if (!isset($pdo)) {
    die("Error: Could not connect to database. Please verify DB_HOST, DB_NAME, DB_USER, DB_PASS, or DATABASE_URL.\n");
}

try {
    echo "[*] Connected to database successfully.\n";

    // 1. Check if tables already exist
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $hasUsersTable = ($stmt->rowCount() > 0);

    if (!$hasUsersTable) {
        echo "[*] Database appears to be fresh. Initializing schema from database/schema.sql...\n";
        $schemaFile = __DIR__ . '/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new RuntimeException("schema.sql not found at {$schemaFile}");
        }

        $schemaSql = file_get_contents($schemaFile);
        
        // Remove CREATE DATABASE and USE statements so cloud-assigned database names aren't rejected
        $schemaSql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+[`\w]+[^;]*;/i', '', $schemaSql);
        $schemaSql = preg_replace('/USE\s+[`\w]+;/i', '', $schemaSql);

        // Execute statements
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 0);
        $pdo->exec($schemaSql);
        echo "    -> Base schema created successfully.\n";
    } else {
        echo "[*] Existing tables detected. Skipping base schema creation.\n";
    }

    // 2. Run migrations
    echo "[*] Checking and applying migrations...\n";
    $migrateFile = __DIR__ . '/migrate.php';
    if (file_exists($migrateFile)) {
        // Run migration in a subprocess or include
        ob_start();
        include $migrateFile;
        $migrateOutput = ob_get_clean();
        echo "    -> Migrations finished.\n";
    }

    // 3. Seed curriculum & subjects if subjects table is empty
    $subjCount = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
    if ($subjCount === 0) {
        echo "[*] Subjects table is empty. Seeding curriculum & subject courses...\n";
        $seedCurriculum = __DIR__ . '/seed_curriculum.php';
        if (file_exists($seedCurriculum)) {
            ob_start();
            include $seedCurriculum;
            ob_get_clean();
            echo "    -> Curriculum and subjects seeded.\n";
        }
    } else {
        echo "[*] Subjects already populated ({$subjCount} subjects found).\n";
    }

    // 4. Seed demo accounts & LMS demo data if requested or if no users exist
    $seedDemo = false;
    if ($isCli) {
        $argvList = $argv ?? [];
        $seedDemo = in_array('--seed-demo', $argvList, true) || (getenv('SEED_DEMO_DATA') === 'true');
    } else {
        $seedDemo = (isset($_GET['seed_demo']) && $_GET['seed_demo'] === '1') || (getenv('SEED_DEMO_DATA') === 'true');
    }

    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount === 0 || $seedDemo) {
        echo "[*] Seeding demo accounts and LMS data...\n";
        $seedLms = __DIR__ . '/seed_lms_demo.php';
        if (file_exists($seedLms)) {
            // Force CLI context for seed_lms_demo.php guard
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            ob_start();
            include $seedLms;
            $demoOutput = ob_get_clean();
            echo $demoOutput;
            echo "    -> Demo accounts and LMS data seeded successfully.\n";
        }
    } else {
        echo "[*] Users table already has {$userCount} accounts.\n";
    }

    echo "\n[✓] Database setup completed successfully!\n";
    echo "========================================================\n";

} catch (Throwable $e) {
    echo "\n[✗] Setup error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
