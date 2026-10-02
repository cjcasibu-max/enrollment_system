<?php
require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = 'Invalid request method.';
    header('Location: ../admin/academic_terms');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header('Location: ../admin/academic_terms');
    exit;
}

$redirectTo = trim($_POST['redirect_to'] ?? '');
if (!in_array($redirectTo, ['../admin/academic_terms', '../admin/lms_terms', 'academic_terms', 'lms_terms'], true)) {
    $redirectTo = '../admin/academic_terms';
}

$action = trim($_POST['action'] ?? '');
$termId = (int)($_POST['term_id'] ?? 0);

$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

function logTermAudit(PDO $pdo, ?int $actorId, string $action, ?int $itemId, string $description): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (actor_id, action, item_type, item_id, description, created_at)
            VALUES (:actor_id, :action, 'academic_term', :item_id, :description, NOW())
        ");
        $stmt->execute([
            'actor_id' => $actorId,
            'action' => $action,
            'item_id' => $itemId,
            'description' => substr($description, 0, 250)
        ]);
    } catch (\Throwable $e) {
        error_log("Audit log recording failed: " . $e->getMessage());
    }
}

try {
    if ($action === 'activate') {
        if ($termId <= 0) {
            throw new RuntimeException('Academic term is required.');
        }

        // Check if term is archived
        $checkStmt = $pdo->prepare('SELECT id, is_archived FROM academic_terms WHERE id = :id');
        $checkStmt->execute(['id' => $termId]);
        $targetTerm = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetTerm) {
            throw new RuntimeException('Academic term was not found.');
        }
        if (!empty($targetTerm['is_archived'])) {
            throw new RuntimeException('Cannot activate an archived/closed academic term. Please unarchive it first.');
        }

        $pdo->beginTransaction();
        $pdo->exec('UPDATE academic_terms SET is_active = 0');
        $stmt = $pdo->prepare('UPDATE academic_terms SET is_active = 1 WHERE id = :id');
        $stmt->execute(['id' => $termId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Academic term was not found.');
        }
        $pdo->commit();

        logTermAudit($pdo, $adminId, 'activate_term', $termId, "Activated academic term ID: {$termId}");
        $_SESSION['flash_success'] = 'Academic term activated as the current LMS term.';
    } elseif ($action === 'archive_term') {
        if ($termId <= 0) {
            throw new RuntimeException('Academic term is required.');
        }

        $stmt = $pdo->prepare('SELECT id, school_year, semester, is_active, is_archived FROM academic_terms WHERE id = :id');
        $stmt->execute(['id' => $termId]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$term) {
            throw new RuntimeException('Academic term was not found.');
        }

        // Archive: set is_archived = 1, is_enrollment_open = 0. Deactivate if it was active.
        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE academic_terms SET is_archived = 1, is_enrollment_open = 0, is_active = 0 WHERE id = :id');
        $upd->execute(['id' => $termId]);
        $pdo->commit();

        logTermAudit($pdo, $adminId, 'archive_term', $termId, "Archived/closed academic term {$term['school_year']} {$term['semester']} Semester. LMS records preserved read-only.");
        $_SESSION['flash_success'] = "Academic term {$term['school_year']} " . ucfirst($term['semester']) . " Semester has been archived/closed. All LMS coursework, grades, and records are retained read-only.";
    } elseif ($action === 'unarchive_term') {
        if ($termId <= 0) {
            throw new RuntimeException('Academic term is required.');
        }

        $stmt = $pdo->prepare('SELECT id, school_year, semester, is_archived FROM academic_terms WHERE id = :id');
        $stmt->execute(['id' => $termId]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$term) {
            throw new RuntimeException('Academic term was not found.');
        }

        $upd = $pdo->prepare('UPDATE academic_terms SET is_archived = 0 WHERE id = :id');
        $upd->execute(['id' => $termId]);

        logTermAudit($pdo, $adminId, 'unarchive_term', $termId, "Unarchived academic term {$term['school_year']} {$term['semester']} Semester.");
        $_SESSION['flash_success'] = "Academic term {$term['school_year']} " . ucfirst($term['semester']) . " Semester has been unarchived.";
    } elseif ($action === 'toggle_enrollment') {
        if ($termId <= 0) {
            throw new RuntimeException('Academic term is required.');
        }

        $stmt = $pdo->prepare('SELECT id, school_year, semester, is_enrollment_open, is_archived FROM academic_terms WHERE id = :id');
        $stmt->execute(['id' => $termId]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$term) {
            throw new RuntimeException('Academic term was not found.');
        }
        if (!empty($term['is_archived'])) {
            throw new RuntimeException('Cannot open enrollment on an archived/closed academic term.');
        }

        $newStatus = (int)$term['is_enrollment_open'] === 1 ? 0 : 1;
        $upd = $pdo->prepare('UPDATE academic_terms SET is_enrollment_open = :status WHERE id = :id');
        $upd->execute(['status' => $newStatus, 'id' => $termId]);

        $statusLabel = $newStatus === 1 ? 'OPEN' : 'CLOSED';
        logTermAudit($pdo, $adminId, 'toggle_enrollment', $termId, "Changed enrollment status to {$statusLabel} for {$term['school_year']} {$term['semester']} Semester");

        $_SESSION['flash_success'] = "Enrollment status set to {$statusLabel} for {$term['school_year']} " . ucfirst($term['semester']) . " Semester.";
    } elseif (in_array($action, ['create', 'update'], true)) {
        $schoolYear = trim($_POST['school_year'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $startsOn = trim($_POST['starts_on'] ?? '') ?: null;
        $endsOn = trim($_POST['ends_on'] ?? '') ?: null;
        $enrollmentStartsOn = trim($_POST['enrollment_starts_on'] ?? '') ?: null;
        $enrollmentEndsOn = trim($_POST['enrollment_ends_on'] ?? '') ?: null;
        $registrationDeadline = trim($_POST['registration_deadline'] ?? '') ?: null;
        $lateRegistrationDeadline = trim($_POST['late_registration_deadline'] ?? '') ?: null;
        $isEnrollmentOpen = isset($_POST['is_enrollment_open']) ? 1 : 0;

        $paymentRequirementPercent = (float)($_POST['payment_requirement_percent'] ?? 100);
        $downpaymentPercentage = (float)($_POST['downpayment_percentage'] ?? 30);
        $minimumDownpayment = (float)($_POST['minimum_downpayment'] ?? 0);
        $maxUnits = (float)($_POST['max_units'] ?? 24);

        if (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || !in_array($semester, ['1st', '2nd', 'summer'], true)) {
            throw new RuntimeException('Enter a valid school year and semester.');
        }
        if ($startsOn && $endsOn && $startsOn > $endsOn) {
            throw new RuntimeException('The term end date must be after its start date.');
        }
        if ($enrollmentStartsOn && $enrollmentEndsOn && $enrollmentStartsOn > $enrollmentEndsOn) {
            throw new RuntimeException('The enrollment period end date must be after the enrollment start date.');
        }
        if ($registrationDeadline && $enrollmentStartsOn && $registrationDeadline < $enrollmentStartsOn) {
            throw new RuntimeException('The registration deadline cannot be earlier than the enrollment start date.');
        }
        if ($registrationDeadline && $lateRegistrationDeadline && $registrationDeadline > $lateRegistrationDeadline) {
            throw new RuntimeException('The late registration deadline must be on or after the regular registration deadline.');
        }
        if ($paymentRequirementPercent <= 0 || $paymentRequirementPercent > 100) {
            throw new RuntimeException('Payment requirement must be greater than 0 and no more than 100 percent.');
        }
        if ($downpaymentPercentage < 0 || $downpaymentPercentage > 100) {
            throw new RuntimeException('Downpayment percentage must be between 0 and 100 percent.');
        }
        if ($minimumDownpayment < 0) {
            throw new RuntimeException('Minimum downpayment cannot be negative.');
        }
        if ($maxUnits <= 0 || $maxUnits > 60) {
            throw new RuntimeException('Maximum unit load must be greater than 0 and no more than 60 units.');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('
                INSERT INTO academic_terms (
                    school_year, semester, starts_on, ends_on,
                    enrollment_starts_on, enrollment_ends_on, registration_deadline, late_registration_deadline, is_enrollment_open,
                    payment_requirement_percent, downpayment_percentage, minimum_downpayment, max_units, is_active, is_archived
                ) VALUES (
                    :school_year, :semester, :starts_on, :ends_on,
                    :enrollment_starts_on, :enrollment_ends_on, :registration_deadline, :late_registration_deadline, :is_enrollment_open,
                    :payment_requirement_percent, :downpayment_percentage, :minimum_downpayment, :max_units, 0, 0
                )
            ');
            $stmt->execute([
                'school_year' => $schoolYear,
                'semester' => $semester,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'enrollment_starts_on' => $enrollmentStartsOn,
                'enrollment_ends_on' => $enrollmentEndsOn,
                'registration_deadline' => $registrationDeadline,
                'late_registration_deadline' => $lateRegistrationDeadline,
                'is_enrollment_open' => $isEnrollmentOpen,
                'payment_requirement_percent' => $paymentRequirementPercent,
                'downpayment_percentage' => $downpaymentPercentage,
                'minimum_downpayment' => $minimumDownpayment,
                'max_units' => $maxUnits
            ]);
            $newId = (int)$pdo->lastInsertId();
            logTermAudit($pdo, $adminId, 'create_term', $newId, "Created academic term {$schoolYear} {$semester} Semester with enrollment deadline: " . ($registrationDeadline ?: 'None'));
            $_SESSION['flash_success'] = 'Academic term created successfully. Activate it when ready to make it current.';
        } else {
            if ($termId <= 0) {
                throw new RuntimeException('Academic term is required.');
            }
            $stmt = $pdo->prepare('
                UPDATE academic_terms SET
                    school_year = :school_year,
                    semester = :semester,
                    starts_on = :starts_on,
                    ends_on = :ends_on,
                    enrollment_starts_on = :enrollment_starts_on,
                    enrollment_ends_on = :enrollment_ends_on,
                    registration_deadline = :registration_deadline,
                    late_registration_deadline = :late_registration_deadline,
                    is_enrollment_open = :is_enrollment_open,
                    payment_requirement_percent = :payment_requirement_percent,
                    downpayment_percentage = :downpayment_percentage,
                    minimum_downpayment = :minimum_downpayment,
                    max_units = :max_units
                WHERE id = :id
            ');
            $stmt->execute([
                'school_year' => $schoolYear,
                'semester' => $semester,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'enrollment_starts_on' => $enrollmentStartsOn,
                'enrollment_ends_on' => $enrollmentEndsOn,
                'registration_deadline' => $registrationDeadline,
                'late_registration_deadline' => $lateRegistrationDeadline,
                'is_enrollment_open' => $isEnrollmentOpen,
                'payment_requirement_percent' => $paymentRequirementPercent,
                'downpayment_percentage' => $downpaymentPercentage,
                'minimum_downpayment' => $minimumDownpayment,
                'max_units' => $maxUnits,
                'id' => $termId
            ]);
            logTermAudit($pdo, $adminId, 'update_term', $termId, "Updated term {$schoolYear} {$semester} (Enrollment: " . ($isEnrollmentOpen ? 'Open' : 'Closed') . ", Deadline: " . ($registrationDeadline ?: 'None') . ")");
            $_SESSION['flash_success'] = 'Academic term configuration updated.';
        }
    } else {
        throw new RuntimeException('Invalid academic term action.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Academic term action failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = $e->getCode() === '23000'
        ? 'That school year and semester already exists.'
        : $e->getMessage();
}

header('Location: ' . $redirectTo);
exit;
