*<?php
require_once __DIR__ . '/../config/database.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "=== ALL TABLES IN DB (" . count($tables) . ") ===\n";
echo implode(', ', $tables) . "\n\n";

$inspect = [
    'users',
    'students',
    'programs',
    'courses',
    'sections',
    'subjects',
    'section_subjects',
    'enrollments',
    'schedules',
    'lms_assignment_submissions',
    'assignment_submissions'
];

foreach ($inspect as $t) {
    if (in_array($t, $tables)) {
        echo "=== TABLE: $t ===\n";
        $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo sprintf("  %-25s %-20s %-6s %-6s %-10s %s\n", $c['Field'], $c['Type'], $c['Null'], $c['Key'], $c['Default'] ?? 'NULL', $c['Extra']);
        }
    } else {
        echo "=== TABLE: $t (NOT IN DB) ===\n";
    }
}
