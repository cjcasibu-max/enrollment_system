<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/lms_access.php';
require_once __DIR__ . '/../includes/assessments.php';

// Test fix on student 41 (demo_student1)
$studentId = 41;
$userId = 69;

// 1. Get assessment
$aid = getOrCreateAssessment($pdo, $studentId, 1);
$assessment = getAssessmentTotals($pdo, $aid);
$total = (float)$assessment['total_amount'];

echo "Student $studentId Total Assessment: $total\n";

// Update payments for student 41 to match full total
$pdo->prepare("UPDATE payments SET amount = :amt WHERE student_id = :sid")->execute([
    'amt' => $total,
    'sid' => $studentId
]);

// Update assessment status
$pdo->prepare("UPDATE assessments SET status = 'paid' WHERE id = :id")->execute(['id' => $aid]);
$pdo->prepare("UPDATE students SET payment_status = 'fully_paid', outstanding_balance = 0.00 WHERE id = :sid")->execute(['sid' => $studentId]);

// Check access
$access = fetchLmsStudentAccessRecord($pdo, $userId);
print_r($access);
$meets = lmsStudentMeetsAccessRequirements($access);
echo "Meets requirements: " . ($meets ? "TRUE" : "FALSE") . "\n";
