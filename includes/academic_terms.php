<?php

function getActiveAcademicTerm(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT id, school_year, semester, starts_on, ends_on,
                enrollment_starts_on, enrollment_ends_on, registration_deadline, late_registration_deadline, is_enrollment_open,
                payment_requirement_percent, downpayment_percentage, minimum_downpayment, max_units, is_active, is_archived
         FROM academic_terms
         WHERE is_active = 1 AND is_archived = 0
         ORDER BY starts_on DESC, id DESC
         LIMIT 1"
    );

    $term = $stmt->fetch();
    return $term ?: null;
}

function getTermDownpaymentRules(PDO $pdo, int $termId): array
{
    if ($termId <= 0) {
        return ['percentage' => 30.00, 'min_amount' => 0.00];
    }
    static $cache = [];
    if (isset($cache[$termId])) {
        return $cache[$termId];
    }
    $stmt = $pdo->prepare("SELECT downpayment_percentage, minimum_downpayment FROM academic_terms WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $termId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $res = [
        'percentage' => (float)($row['downpayment_percentage'] ?? 30.00),
        'min_amount' => (float)($row['minimum_downpayment'] ?? 0.00),
    ];
    $cache[$termId] = $res;
    return $res;
}

/**
 * Computes the real-time status of the active enrollment period and registration deadlines.
 *
 * @param array|null $term
 * @return array
 */
function getEnrollmentPeriodStatus(?array $term = null): array
{
    if (!$term) {
        return [
            'status' => 'no_term',
            'is_open' => false,
            'is_late' => false,
            'label' => 'No Active Academic Term',
            'badge_html' => '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1.5 fw-semibold"><i class="bi bi-calendar-x me-1"></i> No Active Term</span>',
            'deadline_date' => null,
            'deadline_label' => null,
            'days_remaining' => null,
            'period_text' => 'Not configured'
        ];
    }

    // Check if the term is archived / completed
    if (!empty($term['is_archived'])) {
        return [
            'status' => 'archived',
            'is_open' => false,
            'is_late' => false,
            'label' => 'Term Completed & Archived (Read-Only)',
            'badge_html' => '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1.5 fw-bold"><i class="bi bi-archive-fill me-1"></i> Archived / Closed</span>',
            'deadline_date' => null,
            'deadline_label' => 'Historical Records Retained',
            'days_remaining' => 0,
            'period_text' => 'Term Completed'
        ];
    }

    $today = date('Y-m-d');
    $isManualOpen = (int)($term['is_enrollment_open'] ?? 1) === 1;
    $enrollStarts = !empty($term['enrollment_starts_on']) ? $term['enrollment_starts_on'] : null;
    $enrollEnds = !empty($term['enrollment_ends_on']) ? $term['enrollment_ends_on'] : null;
    $regDeadline = !empty($term['registration_deadline']) ? $term['registration_deadline'] : null;
    $lateDeadline = !empty($term['late_registration_deadline']) ? $term['late_registration_deadline'] : null;

    $periodText = ($enrollStarts ? date('M j, Y', strtotime($enrollStarts)) : 'Open') . ' to ' . 
                 ($enrollEnds ? date('M j, Y', strtotime($enrollEnds)) : ($term['ends_on'] ? date('M j, Y', strtotime($term['ends_on'])) : 'TBD'));

    // If manual toggle is explicitly turned off
    if (!$isManualOpen) {
        return [
            'status' => 'closed',
            'is_open' => false,
            'is_late' => false,
            'label' => 'Enrollment Manually Closed',
            'badge_html' => '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 fw-bold"><i class="bi bi-x-circle-fill me-1"></i> Enrollment Closed</span>',
            'deadline_date' => $regDeadline,
            'deadline_label' => 'Closed by Administrator',
            'days_remaining' => 0,
            'period_text' => $periodText
        ];
    }

    // Check if enrollment start date is in the future
    if ($enrollStarts && $today < $enrollStarts) {
        $daysUntil = (int)ceil((strtotime($enrollStarts) - strtotime($today)) / 86400);
        return [
            'status' => 'upcoming',
            'is_open' => false,
            'is_late' => false,
            'label' => 'Upcoming Enrollment',
            'badge_html' => '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2.5 py-1.5 fw-bold"><i class="bi bi-clock-history me-1"></i> Upcoming Enrollment</span>',
            'deadline_date' => $regDeadline,
            'deadline_label' => "Opens in {$daysUntil} day" . ($daysUntil === 1 ? '' : 's') . ' (' . date('M j, Y', strtotime($enrollStarts)) . ')',
            'days_remaining' => $daysUntil,
            'period_text' => $periodText
        ];
    }

    // Check if past late registration deadline (or enrollment end date if no late deadline)
    $finalCutoff = $lateDeadline ?: $enrollEnds;
    if ($finalCutoff && $today > $finalCutoff) {
        return [
            'status' => 'closed',
            'is_open' => false,
            'is_late' => false,
            'label' => 'Enrollment Deadline Passed',
            'badge_html' => '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 fw-bold"><i class="bi bi-calendar-x-fill me-1"></i> Enrollment Closed</span>',
            'deadline_date' => $finalCutoff,
            'deadline_label' => 'Deadline passed on ' . date('M j, Y', strtotime($finalCutoff)),
            'days_remaining' => 0,
            'period_text' => $periodText
        ];
    }

    // Check if past regular deadline but within late registration window
    if ($regDeadline && $today > $regDeadline && $lateDeadline && $today <= $lateDeadline) {
        $daysUntilLate = (int)ceil((strtotime($lateDeadline) - strtotime($today)) / 86400);
        return [
            'status' => 'late',
            'is_open' => true,
            'is_late' => true,
            'label' => 'Late Registration Window',
            'badge_html' => '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1.5 fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Late Registration</span>',
            'deadline_date' => $lateDeadline,
            'deadline_label' => "Late Deadline: " . date('M j, Y', strtotime($lateDeadline)) . " ({$daysUntilLate} day" . ($daysUntilLate === 1 ? '' : 's') . ' remaining)',
            'days_remaining' => $daysUntilLate,
            'period_text' => $periodText
        ];
    }

    // Regular active enrollment open
    $daysLeft = null;
    $deadlineLabel = 'No regular deadline set';
    if ($regDeadline) {
        $daysLeft = (int)ceil((strtotime($regDeadline) - strtotime($today)) / 86400);
        $deadlineLabel = 'Regular Deadline: ' . date('M j, Y', strtotime($regDeadline)) . ' (' . ($daysLeft === 0 ? 'Last Day!' : "{$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ' left') . ')';
    } elseif ($enrollEnds) {
        $daysLeft = (int)ceil((strtotime($enrollEnds) - strtotime($today)) / 86400);
        $deadlineLabel = 'Ends on ' . date('M j, Y', strtotime($enrollEnds)) . " ({$daysLeft} days left)";
    }

    return [
        'status' => 'open',
        'is_open' => true,
        'is_late' => false,
        'label' => 'Active Enrollment Open',
        'badge_html' => '<span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Enrollment Open</span>',
        'deadline_date' => $regDeadline ?: $enrollEnds,
        'deadline_label' => $deadlineLabel,
        'days_remaining' => $daysLeft,
        'period_text' => $periodText
    ];
}

function requireActiveAcademicTerm(PDO $pdo): array
{
    $term = getActiveAcademicTerm($pdo);
    if (!$term) {
        throw new RuntimeException('No active academic term is configured.');
    }

    return $term;
}

/**
 * Returns the configured grade scale for the application.
 * Priority: environment variables `GRADE_MIN`/`GRADE_MAX` if set, otherwise defaults to 0-100.
 * Accepts optional PDO for future DB-backed overrides (not required now).
 *
 * @param PDO|null $pdo
 * @return array{min:float,max:float}
 */
function getGradeScale(?PDO $pdo = null): array
{
    if ($pdo !== null) {
        try {
            require_once __DIR__ . '/lms_settings.php';
            $dbMin = getLmsSetting($pdo, 'grading_scale_min');
            $dbMax = getLmsSetting($pdo, 'grading_scale_max');
            if ($dbMin !== null && $dbMax !== null && is_numeric($dbMin) && is_numeric($dbMax)) {
                return ['min' => (float)$dbMin, 'max' => (float)$dbMax];
            }
        } catch (Throwable $e) {
            // fallback to env or defaults
        }
    }

    $min = getenv('GRADE_MIN');
    $max = getenv('GRADE_MAX');

    $minVal = is_numeric($min) ? (float)$min : 0.0;
    $maxVal = is_numeric($max) ? (float)$max : 100.0;

    return ['min' => $minVal, 'max' => $maxVal];
}