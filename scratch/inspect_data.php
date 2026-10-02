<?php
require_once __DIR__ . '/../config/database.php';
echo "=== LMS_MATERIALS ===\n";
foreach ($pdo->query("DESCRIBE lms_materials")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
echo "=== LMS_ASSIGNMENTS ===\n";
foreach ($pdo->query("DESCRIBE lms_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
