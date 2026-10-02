<?php
require_once __DIR__ . '/../../includes/lms_access.php';

$cases = [
    'applicant without payment' => [
        ['enrollment_status' => 'approved', 'assessment_total' => 1000, 'minimum_downpayment' => 100, 'downpayment_percentage' => 30, 'validated_paid' => 0, 'has_confirmed_enrollment' => false],
        false,
    ],
    'paid but not confirmed in a section' => [
        ['enrollment_status' => 'paid', 'assessment_total' => 1000, 'minimum_downpayment' => 100, 'downpayment_percentage' => 30, 'validated_paid' => 300, 'has_confirmed_enrollment' => false],
        false,
    ],
    'confirmed enrolled student with validated downpayment' => [
        ['enrollment_status' => 'enrolled', 'assessment_total' => 1000, 'minimum_downpayment' => 100, 'downpayment_percentage' => 30, 'validated_paid' => 300, 'has_confirmed_enrollment' => true],
        true,
    ],
    'enrolled status without validated downpayment' => [
        ['enrollment_status' => 'enrolled', 'assessment_total' => 1000, 'minimum_downpayment' => 100, 'downpayment_percentage' => 30, 'validated_paid' => 0, 'has_confirmed_enrollment' => true],
        false,
    ],
];

foreach ($cases as $name => [$record, $expected]) {
    if (lmsStudentMeetsAccessRequirements($record) !== $expected) {
        throw new RuntimeException('LMS eligibility check failed: ' . $name);
    }
}

foreach (['student', 'teacher', 'registrar', 'admin'] as $role) {
    if (!lmsRoleIsPermitted($role)) {
        throw new RuntimeException('Permitted LMS role was rejected: ' . $role);
    }
}

foreach (['enrollee', 'cashier', 'unknown'] as $role) {
    if (lmsRoleIsPermitted($role)) {
        throw new RuntimeException('Non-permitted LMS role was accepted: ' . $role);
    }
}

foreach ([[-1, 0], [0, 0], [1, 33], [2, 67], [3, 100], [5, 100]] as [$checkpoints, $expectedPercent]) {
    if (lmsCourseProgressPercent($checkpoints) !== $expectedPercent) {
        throw new RuntimeException('LMS course progress calculation failed for ' . $checkpoints . ' checkpoints.');
    }
}

$quizScore = scoreLmsQuizResponses(
    [
        ['question_id' => 1, 'question_type' => 'multiple_choice', 'points' => 2, 'correct_choice_id' => 11],
        ['question_id' => 2, 'question_type' => 'true_false', 'points' => 1, 'correct_choice_id' => 22],
        ['question_id' => 3, 'question_type' => 'identification', 'points' => 3, 'correct_answer' => 'North Star'],
    ],
    [
        1 => ['choice_id' => 11],
        2 => ['choice_id' => 21],
        3 => ['answer_text' => '  NORTH   star '],
    ]
);
if ($quizScore['score'] !== 5.0 || $quizScore['total_points'] !== 6.0) {
    throw new RuntimeException('LMS quiz scoring failed for multiple choice, true/false, or identification.');
}

require_once __DIR__ . '/../../config/database.php';
if (fetchLmsStudentAccessRecord($pdo, 0) !== null) {
    throw new RuntimeException('LMS access lookup returned a record for an unknown user.');
}
if (fetchLmsEnrolledSubjects($pdo, 0) !== []) {
    throw new RuntimeException('LMS course list returned subjects for an unknown student.');
}
if (fetchLmsSubjectForStudent($pdo, 0, 1) !== null) {
    throw new RuntimeException('LMS subject lookup returned a course for a non-owning student.');
}
if (fetchLmsCourseGrades($pdo, 0, 1) !== []) {
    throw new RuntimeException('LMS grade lookup returned grades for a non-owning student.');
}
if (fetchLmsModulesForStudentSubject($pdo, 0, 1) !== []) {
    throw new RuntimeException('LMS module lookup returned lessons for a non-owning student.');
}
if (fetchLmsLessonForStudentSubject($pdo, 0, 1, 1) !== null) {
    throw new RuntimeException('LMS lesson lookup returned content for a non-owning student.');
}

echo "LMS access checks passed.\n";