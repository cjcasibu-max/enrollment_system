<?php
/**
 * Temporary Section Reservation Utilities
 * Handles 48-hour temporary slot hold, capacity counting, and lifecycle expiration.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Session not automatically started here
}

require_once __DIR__ . '/notifications.php';

/**
 * Sweeps expired reservations across the system.
 * Converts status to 'expired', cleans up unfinalized draft states, and notifies students.
 *
 * @param PDO $pdo
 * @return int Number of reservations expired
 */
function sweepExpiredReservations(PDO $pdo): int
{
    try {
        // Find active reservations that have passed their expiration timestamp
        $findStmt = $pdo->prepare("
            SELECT sr.id, sr.student_id, sr.section_id, s.user_id, sec.section_name
            FROM section_reservations sr
            JOIN students s ON s.id = sr.student_id
            LEFT JOIN sections sec ON sec.id = sr.section_id
            WHERE sr.status = 'active' AND sr.expires_at <= NOW()
        ");
        $findStmt->execute();
        $expiredList = $findStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expiredList)) {
            return 0;
        }

        $count = 0;
        $expireStmt = $pdo->prepare("UPDATE section_reservations SET status = 'expired' WHERE id = :id AND status = 'active'");
        $resetStudentStmt = $pdo->prepare("UPDATE students SET enrollment_status = 'draft' WHERE id = :id AND enrollment_status = 'section_chosen'");
        $deletePendingEnrollmentStmt = $pdo->prepare("DELETE FROM enrollments WHERE student_id = :sid AND section_id = :sec_id AND status = 'pending'");
        $deleteSelectedSubjectsStmt = $pdo->prepare("DELETE FROM student_selected_subjects WHERE student_id = :sid AND section_id = :sec_id AND is_finalized = 0");

        foreach ($expiredList as $item) {
            $expireStmt->execute(['id' => (int)$item['id']]);
            if ($expireStmt->rowCount() > 0) {
                $count++;
                $studentId = (int)$item['student_id'];
                $sectionId = (int)$item['section_id'];

                // Revert student status if they were in section_chosen
                $resetStudentStmt->execute(['id' => $studentId]);
                $deletePendingEnrollmentStmt->execute(['sid' => $studentId, 'sec_id' => $sectionId]);
                $deleteSelectedSubjectsStmt->execute(['sid' => $studentId, 'sec_id' => $sectionId]);

                // Notify student
                $userId = (int)($item['user_id'] ?? 0);
                if ($userId > 0) {
                    $secName = htmlspecialchars($item['section_name'] ?? 'selected section');
                    createNotification(
                        $pdo,
                        $userId,
                        'Slot Reservation Expired',
                        "Your 48-hour temporary reservation for {$secName} has expired because required physical documents were not submitted in time. You may select a section again.",
                        'warning'
                    );
                }
            }
        }

        return $count;
    } catch (\Throwable $e) {
        error_log("sweepExpiredReservations failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Returns available slot metrics for a given section.
 * Takes into account both confirmed/pending enrollments and active unexpired reservations.
 *
 * @param PDO $pdo
 * @param int $sectionId
 * @param int $excludeStudentId Optional student ID to exclude from reservation count (e.g. current user)
 * @return array
 */
function getSectionAvailableSlots(PDO $pdo, int $sectionId, int $excludeStudentId = 0): array
{
    // First sweep any expired reservations
    sweepExpiredReservations($pdo);

    $secStmt = $pdo->prepare("SELECT id, section_name, capacity FROM sections WHERE id = :id LIMIT 1");
    $secStmt->execute(['id' => $sectionId]);
    $section = $secStmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        return [
            'capacity' => 0,
            'enrolled' => 0,
            'reserved' => 0,
            'total_taken' => 0,
            'available_slots' => 0,
            'is_full' => true,
        ];
    }

    $capacity = (int)($section['capacity'] ?? 0);

    // Count enrolled students (approved or enrolled)
    $enrStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE section_id = :sid AND status IN ('approved', 'enrolled')");
    $enrStmt->execute(['sid' => $sectionId]);
    $enrolledCount = (int)$enrStmt->fetchColumn();

    // Count active unexpired reservations (excluding specified student if any)
    $resSql = "SELECT COUNT(*) FROM section_reservations WHERE section_id = :sid AND status = 'active' AND expires_at > NOW()";
    $params = ['sid' => $sectionId];
    if ($excludeStudentId > 0) {
        $resSql .= " AND student_id != :ex_sid";
        $params['ex_sid'] = $excludeStudentId;
    }
    $resStmt = $pdo->prepare($resSql);
    $resStmt->execute($params);
    $reservedCount = (int)$resStmt->fetchColumn();

    $totalTaken = $enrolledCount + $reservedCount;
    $available = max(0, $capacity - $totalTaken);

    return [
        'capacity' => $capacity,
        'enrolled' => $enrolledCount,
        'reserved' => $reservedCount,
        'total_taken' => $totalTaken,
        'available_slots' => $available,
        'is_full' => ($totalTaken >= $capacity),
    ];
}

/**
 * Fetches the active reservation for a student, if one exists and is not expired.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @return array|null
 */
function getActiveStudentReservation(PDO $pdo, int $studentId): ?array
{
    // First sweep expired reservations
    sweepExpiredReservations($pdo);

    $stmt = $pdo->prepare("
        SELECT sr.*,
               TIMESTAMPDIFF(SECOND, NOW(), sr.expires_at) AS seconds_left,
               sec.section_name, sec.program, sec.year_level, sec.capacity,
               c.course_name, c.course_code
        FROM section_reservations sr
        JOIN sections sec ON sec.id = sr.section_id
        LEFT JOIN courses c ON c.id = sec.course_id
        WHERE sr.student_id = :student_id
          AND sr.status = 'active'
          AND sr.expires_at > NOW()
        ORDER BY sr.id DESC
        LIMIT 1
    ");
    $stmt->execute(['student_id' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Creates a temporary 48-hour section reservation.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @param int $sectionId
 * @param int $holdHours Default 48 hours
 * @return array Reservation data
 * @throws RuntimeException
 */
function createTemporaryReservation(PDO $pdo, int $studentId, int $sectionId, int $holdHours = 48): array
{
    if ($studentId <= 0 || $sectionId <= 0) {
        throw new RuntimeException('Invalid student or section.');
    }

    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        // Lock section row for capacity check
        $secStmt = $pdo->prepare("SELECT id, section_name, capacity FROM sections WHERE id = :id LIMIT 1 FOR UPDATE");
        $secStmt->execute(['id' => $sectionId]);
        $section = $secStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            throw new RuntimeException('Selected section was not found.');
        }

        $capacity = (int)$section['capacity'];

        // Enrolled count
        $enrStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE section_id = :sid AND status IN ('approved', 'enrolled')");
        $enrStmt->execute(['sid' => $sectionId]);
        $enrolledCount = (int)$enrStmt->fetchColumn();

        // Active unexpired reservations by OTHER students
        $resStmt = $pdo->prepare("
            SELECT COUNT(*) FROM section_reservations 
            WHERE section_id = :sid 
              AND status = 'active' 
              AND expires_at > NOW() 
              AND student_id != :student_id
        ");
        $resStmt->execute(['sid' => $sectionId, 'student_id' => $studentId]);
        $activeReservations = (int)$resStmt->fetchColumn();

        if (($enrolledCount + $activeReservations) >= $capacity) {
            throw new RuntimeException('This section has reached full capacity (enrolled + active reservations). Please choose another section.');
        }

        // Cancel any existing active reservation for this student
        $cancelStmt = $pdo->prepare("UPDATE section_reservations SET status = 'cancelled' WHERE student_id = :sid AND status = 'active'");
        $cancelStmt->execute(['sid' => $studentId]);

        // Insert new active reservation
        try {
            $insStmt = $pdo->prepare("
                INSERT INTO section_reservations (student_id, section_id, reserved_at, expires_at, status)
                VALUES (:student_id, :section_id, NOW(), DATE_ADD(NOW(), INTERVAL :hours HOUR), 'active')
            ");
            $insStmt->execute([
                'student_id' => $studentId,
                'section_id' => $sectionId,
                'hours' => $holdHours,
            ]);
        } catch (\PDOException $e) {
            // Specifically catch unique constraint violation on uq_sec_res_student_section_active
            if ($e->getCode() === '23000' && (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) && str_contains($e->getMessage(), 'uq_sec_res_student_section_active')) {
                throw new RuntimeException('You already have an active reservation for this section.');
            }
            throw $e;
        }

        $resId = (int)$pdo->lastInsertId();

        // Fetch inserted reservation
        $fetchStmt = $pdo->prepare("
            SELECT sr.*, TIMESTAMPDIFF(SECOND, NOW(), sr.expires_at) AS seconds_left
            FROM section_reservations sr
            WHERE sr.id = :id
        ");
        $fetchStmt->execute(['id' => $resId]);
        $reservation = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inTx) {
            $pdo->commit();
        }

        return $reservation;

    } catch (\Throwable $e) {
        if (!$inTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Cancels any active reservation for a student.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @return bool
 */
function cancelStudentReservation(PDO $pdo, int $studentId): bool
{
    try {
        $stmt = $pdo->prepare("UPDATE section_reservations SET status = 'cancelled' WHERE student_id = :sid AND status = 'active'");
        $stmt->execute(['sid' => $studentId]);
        return true;
    } catch (\Throwable $e) {
        error_log("cancelStudentReservation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Marks a student's reservation as converted to official enrollment.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @param int $sectionId
 * @return bool
 */
function convertReservationToEnrollment(PDO $pdo, int $studentId, int $sectionId): bool
{
    try {
        $stmt = $pdo->prepare("
            UPDATE section_reservations 
            SET status = 'converted' 
            WHERE student_id = :sid AND section_id = :sec_id AND status = 'active'
        ");
        $stmt->execute(['sid' => $studentId, 'sec_id' => $sectionId]);

        // Insert official enrollment record if it doesn't exist yet
        $checkStmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = :sid AND section_id = :sec_id LIMIT 1");
        $checkStmt->execute(['sid' => $studentId, 'sec_id' => $sectionId]);
        if (!$checkStmt->fetchColumn()) {
            $secStmt = $pdo->prepare("
                SELECT se.academic_term_id, at.school_year AS term_school_year, at.semester AS term_semester 
                FROM sections se
                LEFT JOIN academic_terms at ON at.id = se.academic_term_id
                WHERE se.id = :id LIMIT 1
            ");
            $secStmt->execute(['id' => $sectionId]);
            $sec = $secStmt->fetch(PDO::FETCH_ASSOC);

            // Derive term, school year, and semester directly from linked academic term
            if (!$sec || empty($sec['academic_term_id']) || empty($sec['term_school_year'])) {
                $termStmt = $pdo->query("SELECT id, school_year, semester FROM academic_terms WHERE is_active = 1 LIMIT 1");
                $activeTerm = $termStmt->fetch(PDO::FETCH_ASSOC);
                $termId = $activeTerm ? (int)$activeTerm['id'] : null;
                $sy = $activeTerm ? $activeTerm['school_year'] : '2025-2026';
                $sem = $activeTerm ? $activeTerm['semester'] : '1st';
            } else {
                $termId = (int)$sec['academic_term_id'];
                $sy = $sec['term_school_year'];
                $sem = $sec['term_semester'];
            }

            $insEnr = $pdo->prepare("
                INSERT INTO enrollments (student_id, section_id, academic_term_id, school_year, semester, status)
                VALUES (:student_id, :section_id, :term_id, :school_year, :semester, 'approved')
            ");
            $insEnr->execute([
                'student_id'  => $studentId,
                'section_id'  => $sectionId,
                'term_id'     => $termId,
                'school_year' => $sy,
                'semester'    => $sem,
            ]);
        }

        return true;
    } catch (\Throwable $e) {
        error_log("convertReservationToEnrollment failed: " . $e->getMessage());
        return false;
    }
}
