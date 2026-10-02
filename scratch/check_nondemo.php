<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/lms_access.php';

$u = $pdo->query("SELECT id, username, email, role FROM users WHERE id = 5")->fetch(PDO::FETCH_ASSOC);
echo "User: {$u['username']} ({$u['role']})\n";
$rec = fetchLmsStudentAccessRecord($pdo, (int)$u['id']);
print_r($rec);
$meets = $rec ? lmsStudentMeetsAccessRequirements($rec) : false;
echo "Gate check result: " . ($meets ? "ALLOWED" : "BLOCKED") . "\n";
