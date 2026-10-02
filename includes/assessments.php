<?php

function calculateFeeDiscount(float $baseAmount, string $discountType, float $discountValue): float
{
    if ($discountType === 'none' || $discountValue <= 0) {
        return 0.0;
    }

    if ($discountType === 'fixed') {
        return min($baseAmount, round($discountValue, 2));
    }

    if ($discountType === 'percent') {
        return round($baseAmount * (min((float)$discountValue, 100.0) / 100), 2);
    }

    return 0.0;
}

function getAllowedPaymentMethods(?PDO $pdo = null): array
{
    if ($pdo === null) {
        global $pdo;
    }

    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT method_code, method_name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, method_name");
            $stmt->execute();
            $methods = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($methods)) {
                return array_change_key_case($methods, CASE_LOWER);
            }
        } catch (\Throwable $e) {
            error_log("Failed to fetch payment methods from DB: " . $e->getMessage());
        }
    }

    return [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'bank_transfer' => 'Bank Transfer',
        'other' => 'Other',
    ];
}

function getPaymentMethodLabel(string $methodCode, ?PDO $pdo = null): string
{
    $methodCode = strtolower(trim($methodCode));
    $methods = getAllowedPaymentMethods($pdo);

    return $methods[$methodCode] ?? ucfirst(str_replace('_', ' ', $methodCode));
}

function getAssessmentPaymentStatus(float $paidAmount, float $totalAmount): string
{
    $paidAmount = round($paidAmount, 2);
    $totalAmount = round($totalAmount, 2);

    if ($totalAmount <= 0 || $paidAmount <= 0) {
        return 'UNPAID';
    }

    if ($paidAmount >= $totalAmount) {
        return 'FULLY PAID';
    }

    return 'PARTIALLY PAID';
}

function resolveAssessmentTotal(float $totalAmount, ?float $finalizedAmount, ?float $calculatedAmount, float $itemTotal): float
{
    foreach ([$finalizedAmount, $totalAmount, $calculatedAmount, $itemTotal] as $candidate) {
        if ($candidate !== null && (float)$candidate > 0) {
            return round((float)$candidate, 2);
        }
    }

    return 0.0;
}

function calculateAssessmentBalance(float $totalAmount, float $validatedPaid): float
{
    return round(max(0.0, $totalAmount - $validatedPaid), 2);
}

function calculateRequiredDownpayment(float $assessmentTotal, float $minimumDownpayment, float $downpaymentPercentage): float
{
    $assessmentTotal = max(0.0, $assessmentTotal);
    return round(min($assessmentTotal, max(
        max(0.0, $minimumDownpayment),
        $assessmentTotal * max(0.0, $downpaymentPercentage) / 100
    )), 2);
}

function hasMetRequiredDownpayment(float $validatedPaid, float $assessmentTotal, float $minimumDownpayment, float $downpaymentPercentage): bool
{
    $requiredDownpayment = calculateRequiredDownpayment($assessmentTotal, $minimumDownpayment, $downpaymentPercentage);
    return round(max(0.0, $validatedPaid), 2) + 0.00001 >= $requiredDownpayment;
}

function isPaymentActivationEligibleApplicationStatus(string $applicationStatus): bool
{
    return in_array($applicationStatus, ['approved', 'eligible_to_enroll'], true);
}

function canActivateStudentAccount(
    string $applicationStatus,
    string $enrollmentStatus,
    float $validatedPaid,
    float $assessmentTotal,
    float $minimumDownpayment,
    float $downpaymentPercentage
): bool {
    return isPaymentActivationEligibleApplicationStatus($applicationStatus)
        && in_array($enrollmentStatus, ['approved', 'section_chosen', 'walk_in_ready', 'paid', 'enrolled'], true)
        && hasMetRequiredDownpayment($validatedPaid, $assessmentTotal, $minimumDownpayment, $downpaymentPercentage);
}

function paymentExceedsAssessmentBalance(float $paymentAmount, float $balance): bool
{
    return round($paymentAmount, 2) > round($balance, 2);
}

function updateStudentPaymentSummary(PDO $pdo, int $studentId): void
{
    $assessmentStmt = $pdo->prepare(
        "SELECT id FROM assessments
         WHERE student_id = :student_id AND status != 'cancelled'
         ORDER BY generated_at DESC, id DESC
         LIMIT 1"
    );
    $assessmentStmt->execute(['student_id' => $studentId]);
    $assessmentId = (int)($assessmentStmt->fetchColumn() ?: 0);

    if ($assessmentId <= 0) {
        $pdo->prepare('UPDATE students SET payment_status = :status, outstanding_balance = 0 WHERE id = :id')
            ->execute(['status' => 'unpaid', 'id' => $studentId]);
        return;
    }

    $totals = getAssessmentTotals($pdo, $assessmentId);
    $paidAmount = (float)($totals['validated_paid'] ?? 0.00);
    $totalAmount = (float)($totals['total_amount'] ?? 0.00);
    $balance = max(0.00, $totalAmount - $paidAmount);
    $status = getAssessmentPaymentStatus($paidAmount, $totalAmount);

    $stmt = $pdo->prepare('UPDATE students SET payment_status = :status, outstanding_balance = :balance WHERE id = :id');
    $stmt->execute([
        'status' => strtolower(str_replace(' ', '_', $status)),
        'balance' => round($balance, 2),
        'id' => $studentId,
    ]);
}

function getPreviousUnpaidAssessmentBalance(PDO $pdo, int $studentId, int $currentTermId): ?array
{
    $termStmt = $pdo->prepare(
        'SELECT id, school_year, semester FROM academic_terms
         WHERE id != :current_term_id
         ORDER BY starts_on DESC, id DESC
         LIMIT 1'
    );
    $termStmt->execute(['current_term_id' => $currentTermId]);
    $previousTerm = $termStmt->fetch();

    if (!$previousTerm) {
        return null;
    }

    $assessmentStmt = $pdo->prepare(
        'SELECT a.id, a.total_amount,
                COALESCE(SUM(CASE WHEN p.or_status = "validated" THEN pa.amount ELSE 0 END), 0) AS validated_paid
         FROM assessments a
         LEFT JOIN assessment_items ai ON ai.assessment_id = a.id
         LEFT JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
         LEFT JOIN payments p ON p.id = pa.payment_id
         WHERE a.student_id = :student_id
           AND a.academic_term_id = :term_id
           AND a.status != "cancelled"
         GROUP BY a.id, a.total_amount
         LIMIT 1'
    );
    $assessmentStmt->execute(['student_id' => $studentId, 'term_id' => (int)$previousTerm['id']]);
    $assessment = $assessmentStmt->fetch();

    if (!$assessment) {
        return null;
    }

    $totalAmount = (float)($assessment['total_amount'] ?? 0.00);
    $validatedPaid = (float)($assessment['validated_paid'] ?? 0.00);
    $outstanding = max(0.00, $totalAmount - $validatedPaid);

    if ($outstanding <= 0) {
        return null;
    }

    return [
        'academic_term_id' => (int)$previousTerm['id'],
        'school_year' => $previousTerm['school_year'],
        'semester' => $previousTerm['semester'],
        'outstanding_balance' => round($outstanding, 2),
    ];
}

function ensureAssessmentReferenceNumber(PDO $pdo, int $assessmentId): string
{
    $stmt = $pdo->prepare('SELECT reference_number FROM assessments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $assessmentId]);
    $referenceNumber = trim((string)($stmt->fetchColumn() ?: ''));

    if ($referenceNumber !== '') {
        return $referenceNumber;
    }

    do {
        $referenceNumber = 'AS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $checkStmt = $pdo->prepare('SELECT id FROM assessments WHERE reference_number = :reference_number LIMIT 1');
        $checkStmt->execute(['reference_number' => $referenceNumber]);
    } while ($checkStmt->fetch());

    $updateStmt = $pdo->prepare('UPDATE assessments SET reference_number = :reference_number WHERE id = :id AND reference_number IS NULL');
    $updateStmt->execute(['reference_number' => $referenceNumber, 'id' => $assessmentId]);

    return $referenceNumber;
}

function getOrCreateAssessment(PDO $pdo, int $studentId, int $academicTermId): int
{
    $existingStmt = $pdo->prepare(
        "SELECT id, total_amount, finalized_amount, calculated_amount, status FROM assessments
         WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND status != 'cancelled'
         LIMIT 1"
    );
    $existingStmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
    $existingAssessment = $existingStmt->fetch();
    if ($existingAssessment) {
        $existingId = (int)$existingAssessment['id'];
        ensureAssessmentReferenceNumber($pdo, $existingId);

        if (($existingAssessment['status'] ?? 'open') === 'open') {
            $itemTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM assessment_items WHERE assessment_id = :id');
            $itemTotalStmt->execute(['id' => $existingId]);
            $effectiveTotal = resolveAssessmentTotal(
                (float)($existingAssessment['total_amount'] ?? 0),
                isset($existingAssessment['finalized_amount']) && $existingAssessment['finalized_amount'] !== null
                    ? (float)$existingAssessment['finalized_amount']
                    : null,
                isset($existingAssessment['calculated_amount']) && $existingAssessment['calculated_amount'] !== null
                    ? (float)$existingAssessment['calculated_amount']
                    : null,
                (float)$itemTotalStmt->fetchColumn()
            );

            if ($effectiveTotal > (float)($existingAssessment['total_amount'] ?? 0)) {
                $pdo->prepare('UPDATE assessments SET total_amount = :total WHERE id = :id')
                    ->execute(['total' => $effectiveTotal, 'id' => $existingId]);
            }
        }

        return $existingId;
    }

    $studentStmt = $pdo->prepare('SELECT program_applying_for, program_code, year_level FROM students WHERE id = :id LIMIT 1');
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student) {
        throw new RuntimeException('Student profile was not found for assessment generation.');
    }

    $feeStmt = $pdo->prepare(
                "SELECT id, fee_name, calculation_method, amount, discount_type, discount_value, program_applying_for, program_code
                 FROM fee_configurations
                 WHERE academic_term_id = :academic_term_id
                     AND is_active = 1
                     AND (program_code IS NULL OR program_code = :program_code)
                     AND (year_level IS NULL OR year_level = :year_level)
                 ORDER BY id ASC"
    );
    $feeStmt->execute([
        'academic_term_id' => $academicTermId,
        'program_code' => $student['program_code'] ?? null,
        'year_level' => $student['year_level'],
    ]);
    $fees = $feeStmt->fetchAll();
    if (!$fees) {
        throw new RuntimeException('No active fee configuration exists for this term, program, and year level.');
    }

    $unitsStmt = $pdo->prepare(
        // DB-010: pivot off courses.units to support block-cohort sections (course_id = NULL).
        // Uses section_subjects JOIN subjects so both legacy and block-cohort enrolled units are counted.
        "SELECT COALESCE(SUM(sub.units), 0)
         FROM enrollments e
         JOIN sections s ON s.id = e.section_id
         LEFT JOIN section_subjects ss ON ss.section_id = s.id
         LEFT JOIN subjects sub ON sub.id = ss.subject_id
         WHERE e.student_id = :student_id
           AND e.academic_term_id = :academic_term_id
           AND e.status != 'dropped'"
    );
    $unitsStmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
    $courseUnits = (float)$unitsStmt->fetchColumn();

    $pdo->prepare('INSERT INTO assessments (student_id, academic_term_id, total_amount, discount_amount) VALUES (:student_id, :academic_term_id, 0, 0)')
        ->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);

    $assessmentId = (int)$pdo->lastInsertId();
    $grossTotal = 0.00;
    $discountTotal = 0.00;

    $itemStmt = $pdo->prepare(
        'INSERT INTO assessment_items (assessment_id, fee_configuration_id, description, quantity, unit_amount, amount)
         VALUES (:assessment_id, :fee_configuration_id, :description, :quantity, :unit_amount, :amount)'
    );
    foreach ($fees as $fee) {
        $quantity = $fee['calculation_method'] === 'per_unit' ? $courseUnits : 1.00;
        if ($quantity <= 0) {
            continue;
        }
        $baseAmount = round($quantity * (float)$fee['amount'], 2);
        $discount = calculateFeeDiscount($baseAmount, (string)($fee['discount_type'] ?? 'none'), (float)($fee['discount_value'] ?? 0));
        $amount = round($baseAmount - $discount, 2);
        $itemStmt->execute([
            'assessment_id' => $assessmentId,
            'fee_configuration_id' => $fee['id'],
            'description' => $fee['fee_name'],
            'quantity' => $quantity,
            'unit_amount' => $fee['amount'],
            'amount' => $amount,
        ]);
        // Snapshot any fee-level discount applied to this assessment item
        if (!empty($discount) && $discount > 0) {
            $discStmt = $pdo->prepare(
                'INSERT INTO assessment_discounts (assessment_id, discount_id, discount_name, discount_type, discount_value, amount_applied)
                 VALUES (:assessment_id, NULL, :discount_name, :discount_type, :discount_value, :amount_applied)'
            );
            $discStmt->execute([
                'assessment_id' => $assessmentId,
                'discount_name' => $fee['fee_name'],
                'discount_type' => $fee['discount_type'] ?? 'fixed',
                'discount_value' => $fee['discount_value'] ?? 0,
                'amount_applied' => $discount,
            ]);
        }
        $grossTotal += $baseAmount;
        $discountTotal += $discount;
    }

    $netTotal = round($grossTotal - $discountTotal, 2);
    if ($netTotal <= 0) {
        throw new RuntimeException('The assessment has no billable fee items.');
    }

    try {
        $updateStmt = $pdo->prepare('UPDATE assessments SET total_amount = :total, calculated_amount = :calc, discount_amount = :discount WHERE id = :id');
        $updateStmt->execute(['total' => $netTotal, 'calc' => $netTotal, 'discount' => $discountTotal, 'id' => $assessmentId]);
    } catch (\Throwable $e) {
        $updateStmt = $pdo->prepare('UPDATE assessments SET total_amount = :total, discount_amount = :discount WHERE id = :id');
        $updateStmt->execute(['total' => $netTotal, 'discount' => $discountTotal, 'id' => $assessmentId]);
    }
    ensureAssessmentReferenceNumber($pdo, (int)$assessmentId);
    return $assessmentId;
}

function generateAssessmentFromFinalizedSubjects(PDO $pdo, int $studentId, int $academicTermId): int
{
    $studentStmt = $pdo->prepare('SELECT program_code, year_level FROM students WHERE id = :id LIMIT 1');
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student) {
        throw new RuntimeException('Student profile was not found for finalized-subject assessment generation.');
    }

    $subjectStmt = $pdo->prepare(
        "SELECT subject_id, subject_code, subject_name, units
         FROM student_selected_subjects
         WHERE student_id = :student_id
           AND is_finalized = 1
         ORDER BY subject_code ASC, subject_name ASC"
    );
    $subjectStmt->execute(['student_id' => $studentId]);
    $subjects = $subjectStmt->fetchAll();
    if (!$subjects) {
        throw new RuntimeException('No finalized subject list exists for this student.');
    }

    $totalUnits = 0.0;
    foreach ($subjects as $subject) {
        $totalUnits += (float)($subject['units'] ?? 0.0);
    }
    $totalUnits = round($totalUnits, 2);
    if ($totalUnits <= 0) {
        throw new RuntimeException('Finalized subject list has zero billable units.');
    }

    $existingStmt = $pdo->prepare(
        "SELECT id FROM assessments WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND status != 'cancelled' LIMIT 1"
    );
    $existingStmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
    $assessmentId = $existingStmt->fetchColumn();

    if (!$assessmentId) {
        $pdo->prepare('INSERT INTO assessments (student_id, academic_term_id, total_amount, discount_amount) VALUES (:student_id, :academic_term_id, 0, 0)')
            ->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
        $assessmentId = (int)$pdo->lastInsertId();
    }

    // If an assessment already exists, ensure no validated payments or
    // existing payment allocations are present before we clear and
    // rebuild items/discounts. Regenerating an assessment after payments
    // have been allocated would sever the link between payments and
    // assessment items.
    if ($assessmentId) {
        $validatedStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM payments WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND or_status = 'validated'"
        );
        $validatedStmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
        $validatedCount = (int)$validatedStmt->fetchColumn();

        $allocStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM payment_allocations pa JOIN assessment_items ai ON pa.assessment_item_id = ai.id WHERE ai.assessment_id = :assessment_id"
        );
        $allocStmt->execute(['assessment_id' => $assessmentId]);
        $allocCount = (int)$allocStmt->fetchColumn();

        if ($validatedCount > 0 || $allocCount > 0) {
            throw new RuntimeException('Cannot regenerate assessment: validated payments or allocations exist for this assessment.');
        }
    }

    ensureAssessmentReferenceNumber($pdo, (int)$assessmentId);

    $pdo->prepare("DELETE FROM payment_allocations WHERE assessment_item_id IN (SELECT id FROM assessment_items WHERE assessment_id = :assessment_id)")
        ->execute(['assessment_id' => $assessmentId]);
    $pdo->prepare('DELETE FROM assessment_items WHERE assessment_id = :assessment_id')
        ->execute(['assessment_id' => $assessmentId]);
    $pdo->prepare('DELETE FROM assessment_discounts WHERE assessment_id = :assessment_id')
        ->execute(['assessment_id' => $assessmentId]);

    $programApplyingFor = trim((string)($student['program_applying_for'] ?? '')) ?: trim((string)($student['program_code'] ?? ''));
    $programCode = trim((string)($student['program_code'] ?? ''));

        // Use unique named placeholders to avoid PDO errors when emulation is disabled.
        $feeStmt = $pdo->prepare(
                "SELECT id, fee_name, fee_code, calculation_method, amount, discount_type, discount_value, program_applying_for, program_code, year_level
                 FROM fee_configurations
                 WHERE academic_term_id = :academic_term_id
                     AND is_active = 1
                     AND (year_level IS NULL OR year_level = :year_level)
                     AND (
                             program_code = :program_code_w
                             OR program_applying_for = :program_applying_for_w
                             OR (program_code IS NULL AND program_applying_for IS NULL)
                     )
                 ORDER BY
                     CASE
                         WHEN program_code = :program_code_o THEN 0
                         WHEN program_applying_for = :program_applying_for_o THEN 1
                         WHEN program_code IS NULL AND program_applying_for IS NULL THEN 2
                         ELSE 3
                     END,
                     id ASC"
        );
        $feeStmt->execute([
                'academic_term_id' => $academicTermId,
                'program_code_w' => $programCode !== '' ? $programCode : null,
                'program_applying_for_w' => $programApplyingFor !== '' ? $programApplyingFor : null,
                'program_code_o' => $programCode !== '' ? $programCode : null,
                'program_applying_for_o' => $programApplyingFor !== '' ? $programApplyingFor : null,
                'year_level' => $student['year_level'] ?? null,
        ]);
    $fees = $feeStmt->fetchAll();
    if (!$fees) {
        throw new RuntimeException('No active fee configuration exists for this term, program, and year level.');
    }

    $specificMatches = array_filter($fees, static function (array $fee) use ($programCode, $programApplyingFor): bool {
        $feeProgramCode = trim((string)($fee['program_code'] ?? ''));
        $feeProgramApplyingFor = trim((string)($fee['program_applying_for'] ?? ''));

        return ($feeProgramCode !== '' && $feeProgramCode === $programCode)
            || ($feeProgramApplyingFor !== '' && $feeProgramApplyingFor === $programApplyingFor);
    });

    if (!empty($specificMatches)) {
        $fees = array_values($specificMatches);
    } else {
        $fees = array_values(array_filter($fees, static function (array $fee): bool {
            $feeProgramCode = trim((string)($fee['program_code'] ?? ''));
            $feeProgramApplyingFor = trim((string)($fee['program_applying_for'] ?? ''));
            return $feeProgramCode === '' && $feeProgramApplyingFor === '';
        }));
    }

    if (!$fees) {
        throw new RuntimeException('No active fee configuration exists for this term, program, and year level.');
    }

    $seenFeeKeys = [];
    $fees = array_values(array_filter($fees, function (array $fee) use (&$seenFeeKeys): bool {
        $key = implode('|', [
            (string)($fee['fee_code'] ?? ''),
            (string)($fee['fee_name'] ?? ''),
            (string)($fee['program_code'] ?? ''),
            (string)($fee['program_applying_for'] ?? ''),
            (string)($fee['year_level'] ?? ''),
            (string)($fee['calculation_method'] ?? ''),
        ]);
        if ($key === '' || isset($seenFeeKeys[$key])) {
            return false;
        }
        $seenFeeKeys[$key] = true;
        return true;
    }));

    $grossTotal = 0.00;
    $discountTotal = 0.00;
    $itemStmt = $pdo->prepare(
        'INSERT INTO assessment_items (assessment_id, fee_configuration_id, description, quantity, unit_amount, amount)
         VALUES (:assessment_id, :fee_configuration_id, :description, :quantity, :unit_amount, :amount)'
    );

    foreach ($fees as $fee) {
        $quantity = $fee['calculation_method'] === 'per_unit' ? $totalUnits : 1.00;
        if ($quantity <= 0) {
            continue;
        }
        $baseAmount = round($quantity * (float)$fee['amount'], 2);
        $discount = calculateFeeDiscount($baseAmount, (string)($fee['discount_type'] ?? 'none'), (float)($fee['discount_value'] ?? 0));
        $amount = round($baseAmount - $discount, 2);

        $itemStmt->execute([
            'assessment_id' => $assessmentId,
            'fee_configuration_id' => $fee['id'],
            'description' => $fee['fee_name'],
            'quantity' => $quantity,
            'unit_amount' => $fee['amount'],
            'amount' => $amount,
        ]);

        if (!empty($discount) && $discount > 0) {
            $discStmt = $pdo->prepare(
                'INSERT INTO assessment_discounts (assessment_id, discount_id, discount_name, discount_type, discount_value, amount_applied)
                 VALUES (:assessment_id, NULL, :discount_name, :discount_type, :discount_value, :amount_applied)'
            );
            $discStmt->execute([
                'assessment_id' => $assessmentId,
                'discount_name' => $fee['fee_name'],
                'discount_type' => $fee['discount_type'] ?? 'fixed',
                'discount_value' => $fee['discount_value'] ?? 0,
                'amount_applied' => $discount,
            ]);
        }

        $grossTotal += $baseAmount;
        $discountTotal += $discount;
    }

    $netTotal = round($grossTotal - $discountTotal, 2);
    if ($netTotal <= 0) {
        throw new RuntimeException('The finalized-subject assessment has no billable fee items.');
    }

    try {
        $pdo->prepare('UPDATE assessments SET total_amount = :total, calculated_amount = :calc, discount_amount = :discount WHERE id = :id')
            ->execute(['total' => $netTotal, 'calc' => $netTotal, 'discount' => $discountTotal, 'id' => $assessmentId]);
    } catch (\Throwable $e) {
        $pdo->prepare('UPDATE assessments SET total_amount = :total, discount_amount = :discount WHERE id = :id')
            ->execute(['total' => $netTotal, 'discount' => $discountTotal, 'id' => $assessmentId]);
    }

    return (int)$assessmentId;
}

function getAssessmentTotals(PDO $pdo, int $assessmentId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT a.total_amount,
                    a.discount_amount,
                    a.calculated_amount,
                    a.finalized_amount,
                    a.is_finalized,
                    a.finalized_at,
                    a.finalized_by,
                    a.finalization_notes,
                    COALESCE((SELECT SUM(item.amount) FROM assessment_items item WHERE item.assessment_id = a.id), 0) AS item_total,
                    COALESCE(SUM(CASE WHEN p.or_status = 'validated' THEN pa.amount ELSE 0 END), 0)
                    + COALESCE((
                        SELECT SUM(unallocated_payment.amount)
                        FROM payments unallocated_payment
                        WHERE unallocated_payment.student_id = a.student_id
                          AND unallocated_payment.academic_term_id = a.academic_term_id
                          AND unallocated_payment.or_status = 'validated'
                          AND NOT EXISTS (
                              SELECT 1 FROM payment_allocations existing_allocation
                              WHERE existing_allocation.payment_id = unallocated_payment.id
                          )
                    ), 0) AS validated_paid,
                    COALESCE(SUM(pa.amount), 0) AS allocated_total
             FROM assessments a
             LEFT JOIN assessment_items ai ON ai.assessment_id = a.id
             LEFT JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
             LEFT JOIN payments p ON p.id = pa.payment_id
             WHERE a.id = :id
             GROUP BY a.id, a.total_amount, a.discount_amount, a.calculated_amount, a.finalized_amount, a.is_finalized, a.finalized_at, a.finalized_by, a.finalization_notes"
        );
        $stmt->execute(['id' => $assessmentId]);
        $totals = $stmt->fetch();
    } catch (\Throwable $e) {
        $stmt = $pdo->prepare(
            "SELECT a.total_amount,
                    a.discount_amount,
                    COALESCE((SELECT SUM(item.amount) FROM assessment_items item WHERE item.assessment_id = a.id), 0) AS item_total,
                    COALESCE(SUM(CASE WHEN p.or_status = 'validated' THEN pa.amount ELSE 0 END), 0)
                    + COALESCE((
                        SELECT SUM(unallocated_payment.amount)
                        FROM payments unallocated_payment
                        WHERE unallocated_payment.student_id = a.student_id
                          AND unallocated_payment.academic_term_id = a.academic_term_id
                          AND unallocated_payment.or_status = 'validated'
                          AND NOT EXISTS (
                              SELECT 1 FROM payment_allocations existing_allocation
                              WHERE existing_allocation.payment_id = unallocated_payment.id
                          )
                    ), 0) AS validated_paid,
                    COALESCE(SUM(pa.amount), 0) AS allocated_total
             FROM assessments a
             LEFT JOIN assessment_items ai ON ai.assessment_id = a.id
             LEFT JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
             LEFT JOIN payments p ON p.id = pa.payment_id
             WHERE a.id = :id
             GROUP BY a.id, a.total_amount, a.discount_amount"
        );
        $stmt->execute(['id' => $assessmentId]);
        $totals = $stmt->fetch();
    }

    if (!$totals) {
        throw new RuntimeException('Assessment was not found.');
    }
    $totals['discount_amount'] = (float)($totals['discount_amount'] ?? 0.00);
    $totals['total_amount'] = resolveAssessmentTotal(
        (float)($totals['total_amount'] ?? 0.00),
        isset($totals['finalized_amount']) && $totals['finalized_amount'] !== null
            ? (float)$totals['finalized_amount']
            : null,
        isset($totals['calculated_amount']) && $totals['calculated_amount'] !== null
            ? (float)$totals['calculated_amount']
            : null,
        (float)($totals['item_total'] ?? 0.00)
    );
    $totals['calculated_amount'] = isset($totals['calculated_amount']) ? (float)$totals['calculated_amount'] : $totals['total_amount'];
    $totals['finalized_amount'] = isset($totals['finalized_amount']) && $totals['finalized_amount'] !== null ? (float)$totals['finalized_amount'] : null;
    $totals['is_finalized'] = !empty($totals['is_finalized']);
    $totals['balance'] = calculateAssessmentBalance((float)$totals['total_amount'], (float)$totals['validated_paid']);
    return $totals;
}

/**
 * Finalizes tuition assessment amount and records registrar confirmation.
 *
 * @param PDO $pdo
 * @param int $assessmentId
 * @param float $finalizedAmount
 * @param int $registrarUserId
 * @param string $notes
 * @return bool
 */
function finalizeAssessmentTuition(PDO $pdo, int $assessmentId, float $finalizedAmount, int $registrarUserId, string $notes = ''): bool
{
    $checkStmt = $pdo->prepare("SELECT id, student_id FROM assessments WHERE id = :id LIMIT 1");
    $checkStmt->execute(['id' => $assessmentId]);
    $assessment = $checkStmt->fetch();
    if (!$assessment) {
        throw new RuntimeException('Assessment not found.');
    }

    $finalizedAmount = round($finalizedAmount, 2);
    $trimmedNotes = trim($notes);

    try {
        $updateStmt = $pdo->prepare("
            UPDATE assessments 
            SET finalized_amount = :fam,
                total_amount = :tam,
                is_finalized = 1,
                finalized_at = NOW(),
                finalized_by = :fby,
                finalization_notes = :notes
            WHERE id = :id
        ");
        $updateStmt->execute([
            'fam' => $finalizedAmount,
            'tam' => $finalizedAmount,
            'fby' => $registrarUserId,
            'notes' => $trimmedNotes !== '' ? $trimmedNotes : null,
            'id' => $assessmentId,
        ]);

        // Keep assessment_items synchronized with finalized amount so payment allocation succeeds
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM assessment_items WHERE assessment_id = :id");
        $sumStmt->execute(['id' => $assessmentId]);
        $currentItemsSum = round((float)$sumStmt->fetchColumn(), 2);
        $diff = round($finalizedAmount - $currentItemsSum, 2);

        if (abs($diff) > 0.01) {
            $tStmt = $pdo->prepare("SELECT id, amount FROM assessment_items WHERE assessment_id = :id AND description LIKE '%Tuition%' ORDER BY id ASC LIMIT 1");
            $tStmt->execute(['id' => $assessmentId]);
            $tItem = $tStmt->fetch();
            if ($tItem) {
                $newTAmount = max(0, round((float)$tItem['amount'] + $diff, 2));
                $pdo->prepare("UPDATE assessment_items SET amount = :amt, unit_amount = :uamt WHERE id = :id")
                    ->execute(['amt' => $newTAmount, 'uamt' => $newTAmount, 'id' => (int)$tItem['id']]);
            } else {
                $pdo->prepare("INSERT INTO assessment_items (assessment_id, description, quantity, unit_amount, amount) VALUES (:aid, 'Tuition Adjustment', 1, :uamt, :amt)")
                    ->execute(['aid' => $assessmentId, 'uamt' => $diff, 'amt' => $diff]);
            }
        }

        updateStudentPaymentSummary($pdo, (int)$assessment['student_id']);
        return true;
    } catch (\Throwable $e) {
        error_log("finalizeAssessmentTuition error: " . $e->getMessage());
        return false;
    }
}

function allocatePayment(PDO $pdo, int $paymentId, int $assessmentId, float $amount, bool $allowOverpayment = false): void
{
    $itemStmt = $pdo->prepare(
        "SELECT ai.id, ai.amount,
                COALESCE(SUM(CASE WHEN p.or_status IN ('validated', 'pending') THEN pa.amount ELSE 0 END), 0) AS allocated_total
         FROM assessment_items ai
         LEFT JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
         LEFT JOIN payments p ON p.id = pa.payment_id
         WHERE ai.assessment_id = :assessment_id
         GROUP BY ai.id, ai.amount
         HAVING ai.amount > COALESCE(SUM(CASE WHEN p.or_status IN ('validated', 'pending') THEN pa.amount ELSE 0 END), 0)
         ORDER BY ai.id ASC
         FOR UPDATE"
    );
    $itemStmt->execute(['assessment_id' => $assessmentId]);
    $remaining = round($amount, 2);
    $insertStmt = $pdo->prepare('INSERT INTO payment_allocations (payment_id, assessment_item_id, amount) VALUES (:payment_id, :assessment_item_id, :amount)');

    foreach ($itemStmt->fetchAll() as $item) {
        if ($remaining <= 0) {
            break;
        }
        $itemBalance = round((float)$item['amount'] - (float)$item['allocated_total'], 2);
        $allocation = min($remaining, $itemBalance);
        if ($allocation > 0) {
            try {
                $insertStmt->execute(['payment_id' => $paymentId, 'assessment_item_id' => $item['id'], 'amount' => $allocation]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062 || $e->getCode() === '23000') {
                    throw new RuntimeException("Payment #{$paymentId} is already allocated to assessment item #{$item['id']}.");
                }
                throw $e;
            }
            $remaining = round($remaining - $allocation, 2);
        }
    }

    // If remaining amount exists but does not exceed the overall assessment balance,
    // dynamically reconcile the assessment items (e.g. customized subjects or finalized tuition)
    if ($remaining > 0.01) {
        $aStmt = $pdo->prepare("SELECT total_amount FROM assessments WHERE id = :id LIMIT 1");
        $aStmt->execute(['id' => $assessmentId]);
        $totalAssessment = (float)($aStmt->fetchColumn() ?: 0);

        $allocStmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN p.or_status IN ('validated', 'pending') THEN pa.amount ELSE 0 END), 0)
            FROM assessment_items ai
            JOIN payment_allocations pa ON pa.assessment_item_id = ai.id
            JOIN payments p ON p.id = pa.payment_id
            WHERE ai.assessment_id = :aid
        ");
        $allocStmt->execute(['aid' => $assessmentId]);
        $totalAllocated = (float)$allocStmt->fetchColumn();
        $assessmentRemainingBalance = max(0.00, round($totalAssessment - $totalAllocated, 2));

        if ($remaining <= ($assessmentRemainingBalance + 0.05)) {
            // Absorb remaining amount into an assessment item
            $lastItemStmt = $pdo->prepare("SELECT id, amount FROM assessment_items WHERE assessment_id = :aid ORDER BY id DESC LIMIT 1");
            $lastItemStmt->execute(['aid' => $assessmentId]);
            $lastItem = $lastItemStmt->fetch();

            if ($lastItem) {
                $newAmt = round((float)$lastItem['amount'] + $remaining, 2);
                $pdo->prepare("UPDATE assessment_items SET amount = :amt, unit_amount = :uamt WHERE id = :id")
                    ->execute(['amt' => $newAmt, 'uamt' => $newAmt, 'id' => (int)$lastItem['id']]);
                $insertStmt->execute(['payment_id' => $paymentId, 'assessment_item_id' => (int)$lastItem['id'], 'amount' => $remaining]);
                $remaining = 0.00;
            } else {
                $pdo->prepare("INSERT INTO assessment_items (assessment_id, description, quantity, unit_amount, amount) VALUES (:aid, 'Tuition & Fees', 1, :uamt, :amt)")
                    ->execute(['aid' => $assessmentId, 'uamt' => $remaining, 'amt' => $remaining]);
                $newItemId = (int)$pdo->lastInsertId();
                $insertStmt->execute(['payment_id' => $paymentId, 'assessment_item_id' => $newItemId, 'amount' => $remaining]);
                $remaining = 0.00;
            }
        }
    }

    if ($remaining > 0.05 && !$allowOverpayment) {
        throw new RuntimeException('Payment exceeds the remaining assessment balance.');
    }
}

function recalculateAssessment(PDO $pdo, int $studentId, int $academicTermId): void
{
    // Find the active assessment
    $stmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND status != 'cancelled' LIMIT 1");
    $stmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
    $assessmentId = $stmt->fetchColumn();
    if (!$assessmentId) {
        return;
    }

    // Do not modify assessments that are not open; preserve historical/paid assessments.
    $statusStmt = $pdo->prepare('SELECT status, finalized_amount FROM assessments WHERE id = :id LIMIT 1');
    $statusStmt->execute(['id' => $assessmentId]);
    $assessmentState = $statusStmt->fetch();
    $status = $assessmentState['status'] ?? null;
    if ($status !== 'open') {
        // Assessment is closed (paid or cancelled); do not mutate historical records.
        return;
    }

    $paymentStateStmt = $pdo->prepare(
        "SELECT
             EXISTS (
                 SELECT 1 FROM payments
                 WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND or_status = 'validated'
             ) AS has_validated_payment,
             EXISTS (
                 SELECT 1
                 FROM payment_allocations pa
                 JOIN assessment_items ai ON ai.id = pa.assessment_item_id
                 WHERE ai.assessment_id = :assessment_id
             ) AS has_payment_allocation"
    );
    $paymentStateStmt->execute([
        'student_id' => $studentId,
        'academic_term_id' => $academicTermId,
        'assessment_id' => (int)$assessmentId,
    ]);
    $paymentState = $paymentStateStmt->fetch();
    if ((($assessmentState['finalized_amount'] ?? null) !== null && (float)$assessmentState['finalized_amount'] > 0)
        || !empty($paymentState['has_validated_payment'])
        || !empty($paymentState['has_payment_allocation'])) {
        return;
    }

    $studentStmt = $pdo->prepare('SELECT program_applying_for, program_code, year_level FROM students WHERE id = :id LIMIT 1');
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student) {
        return;
    }

    $feeStmt = $pdo->prepare(
            "SELECT id, fee_name, calculation_method, amount, discount_type, discount_value, program_applying_for, program_code
             FROM fee_configurations
             WHERE academic_term_id = :academic_term_id
                 AND is_active = 1
                 AND (program_code IS NULL OR program_code = :program_code)
                 AND (year_level IS NULL OR year_level = :year_level)
             ORDER BY id ASC"
    );
    $feeStmt->execute([
            'academic_term_id' => $academicTermId,
            'program_code' => $student['program_code'] ?? null,
            'year_level' => $student['year_level'],
    ]);
    $fees = $feeStmt->fetchAll();

    $unitsStmt = $pdo->prepare(
        // DB-010: pivot off courses.units to support block-cohort sections (course_id = NULL).
        // Uses section_subjects JOIN subjects so both legacy and block-cohort enrolled units are counted.
        "SELECT COALESCE(SUM(sub.units), 0)
         FROM enrollments e
         JOIN sections s ON s.id = e.section_id
         LEFT JOIN section_subjects ss ON ss.section_id = s.id
         LEFT JOIN subjects sub ON sub.id = ss.subject_id
         WHERE e.student_id = :student_id
           AND e.academic_term_id = :academic_term_id
           AND e.status != 'dropped'"
    );
    $unitsStmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
    $courseUnits = (float)$unitsStmt->fetchColumn();

    // Fetch all validated payments for this student/term to re-allocate

    $payStmt = $pdo->prepare("SELECT id, amount FROM payments WHERE student_id = :student_id AND academic_term_id = :academic_term_id AND or_status = 'validated'");
    $payStmt->execute(['student_id' => $studentId, 'academic_term_id' => $academicTermId]);
    $payments = $payStmt->fetchAll();

    // Secure transaction handling
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        // Fetch total before update for validation
        $beforeTotals = getAssessmentTotals($pdo, $assessmentId);
        $beforeNet = (float)($beforeTotals['total_amount'] ?? 0.00);

        // Delete existing allocations, items, and discounts
        $pdo->prepare("DELETE FROM payment_allocations WHERE assessment_item_id IN (SELECT id FROM assessment_items WHERE assessment_id = :assessment_id)")
            ->execute(['assessment_id' => $assessmentId]);
            
        $pdo->prepare("DELETE FROM assessment_items WHERE assessment_id = :assessment_id")
            ->execute(['assessment_id' => $assessmentId]);

        $pdo->prepare("DELETE FROM assessment_discounts WHERE assessment_id = :assessment_id")
            ->execute(['assessment_id' => $assessmentId]);

        $grossTotal = 0.00;
        $discountTotal = 0.00;
        $itemStmt = $pdo->prepare(
            'INSERT INTO assessment_items (assessment_id, fee_configuration_id, description, quantity, unit_amount, amount)
             VALUES (:assessment_id, :fee_configuration_id, :description, :quantity, :unit_amount, :amount)'
        );

        foreach ($fees as $fee) {
            $quantity = $fee['calculation_method'] === 'per_unit' ? $courseUnits : 1.00;
            if ($quantity <= 0) {
                continue;
            }
            $baseAmount = round($quantity * (float)$fee['amount'], 2);
            $discount = calculateFeeDiscount($baseAmount, (string)($fee['discount_type'] ?? 'none'), (float)($fee['discount_value'] ?? 0));
            $amount = round($baseAmount - $discount, 2);
            $itemStmt->execute([
                'assessment_id' => $assessmentId,
                'fee_configuration_id' => $fee['id'],
                'description' => $fee['fee_name'],
                'quantity' => $quantity,
                'unit_amount' => $fee['amount'],
                'amount' => $amount,
            ]);
            // Snapshot any fee-level discount applied to this assessment item
            if (!empty($discount) && $discount > 0) {
                $discStmt = $pdo->prepare(
                    'INSERT INTO assessment_discounts (assessment_id, discount_id, discount_name, discount_type, discount_value, amount_applied)
                     VALUES (:assessment_id, NULL, :discount_name, :discount_type, :discount_value, :amount_applied)'
                );
                $discStmt->execute([
                    'assessment_id' => $assessmentId,
                    'discount_name' => $fee['fee_name'],
                    'discount_type' => $fee['discount_type'] ?? 'fixed',
                    'discount_value' => $fee['discount_value'] ?? 0,
                    'amount_applied' => $discount,
                ]);
            }
            $grossTotal += $baseAmount;
            $discountTotal += $discount;
        }

        $netTotal = round($grossTotal - $discountTotal, 2);
        
        // Validate that net total is non-negative
        if ($netTotal < 0) {
            throw new RuntimeException("Recalculation error: Net assessment total cannot be negative.");
        }

        $updateStmt = $pdo->prepare('UPDATE assessments SET total_amount = :total, discount_amount = :discount WHERE id = :id');
        $updateStmt->execute(['total' => $netTotal, 'discount' => $discountTotal, 'id' => $assessmentId]);

        // Re-allocate validated payments without creating an overpayment state.
        $remainingAssessmentTotal = $netTotal;
        foreach ($payments as $payment) {
            if ($remainingAssessmentTotal <= 0) {
                break;
            }

            $allocatableAmount = min((float)$payment['amount'], $remainingAssessmentTotal);
            if ($allocatableAmount <= 0) {
                continue;
            }

            allocatePayment($pdo, (int)$payment['id'], (int)$assessmentId, $allocatableAmount, false);
            $remainingAssessmentTotal = round($remainingAssessmentTotal - $allocatableAmount, 2);
        }

        // Fetch total after update for validation & logging
        $afterTotals = getAssessmentTotals($pdo, $assessmentId);
        $afterNet = (float)($afterTotals['total_amount'] ?? 0.00);
        error_log("Assessment recalculation successful for ID {$assessmentId}: Net total went from ₱{$beforeNet} to ₱{$afterNet}");

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Assessment recalculation failed: " . $e->getMessage());
        throw $e;
    }
}