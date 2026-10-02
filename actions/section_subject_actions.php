<?php
/**
 * Section Subject Actions Processor
 * Handles CRUD for section-subject schedule assignments.
 * Secured with checkRole() and prepared statements.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';
require_once '../includes/registration_rules.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../registrar/sections");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../registrar/sections");
    exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

switch ($action) {
    case 'create':
        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $instructorId = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? (int)$_POST['instructor_id'] : null;
        $dayOfWeek = isset($_POST['day_of_week']) ? trim($_POST['day_of_week']) : '';
        $startTime = isset($_POST['start_time']) ? trim($_POST['start_time']) : '';
        $endTime = isset($_POST['end_time']) ? trim($_POST['end_time']) : '';
        $room = isset($_POST['room']) ? trim($_POST['room']) : null;

        if ($sectionId <= 0 || $subjectId <= 0 || empty($dayOfWeek) || empty($startTime) || empty($endTime)) {
            $_SESSION['flash_error'] = "Section, Subject, Day, Start Time, and End Time are required.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;
        }

        if (strtotime($endTime) <= strtotime($startTime)) {
            $_SESSION['flash_error'] = "End time must be later than start time.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;
        }

        try {
            $secCheck = $pdo->prepare("SELECT id, status FROM sections WHERE id = :id LIMIT 1");
            $secCheck->execute(['id' => $sectionId]);
            $section = $secCheck->fetch();
            if (!$section) {
                $_SESSION['flash_error'] = "Section not found.";
                header("Location: ../registrar/sections");
                exit;
            }

            $subCheck = $pdo->prepare("SELECT id FROM subjects WHERE id = :id LIMIT 1");
            $subCheck->execute(['id' => $subjectId]);
            if (!$subCheck->fetch()) {
                $_SESSION['flash_error'] = "Selected subject does not exist.";
                header("Location: ../registrar/section_details?id=" . $sectionId);
                exit;
            }

            if ($instructorId !== null) {
                $instCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'teacher' AND is_active = 1 LIMIT 1");
                $instCheck->execute(['id' => $instructorId]);
                if (!$instCheck->fetch()) {
                    $_SESSION['flash_error'] = "Selected instructor is not valid.";
                    header("Location: ../registrar/section_details?id=" . $sectionId);
                    exit;
                }
            }

            $dupCheck = $pdo->prepare("SELECT id FROM section_subjects WHERE section_id = :section_id AND subject_id = :subject_id LIMIT 1");
            $dupCheck->execute(['section_id' => $sectionId, 'subject_id' => $subjectId]);
            if ($dupCheck->fetch()) {
                $_SESSION['flash_error'] = "This subject is already assigned to this section.";
                header("Location: ../registrar/section_details?id=" . $sectionId);
                exit;
            }

            $existingStmt = $pdo->prepare("SELECT ss.id, ss.day_of_week, ss.start_time, ss.end_time, ss.room, ss.instructor_id, u.username AS instructor_name FROM section_subjects ss LEFT JOIN users u ON ss.instructor_id = u.id WHERE ss.section_id = :section_id");
            $existingStmt->execute(['section_id' => $sectionId]);
            $existingSubjects = $existingStmt->fetchAll();

            $newStart = strtotime($startTime);
            $newEnd = strtotime($endTime);

            foreach ($existingSubjects as $ex) {
                if (strcasecmp($ex['day_of_week'], $dayOfWeek) === 0) {
                    $exStart = strtotime($ex['start_time']);
                    $exEnd = strtotime($ex['end_time']);

                    if ($room !== null && $ex['room'] !== null && strcasecmp($ex['room'], $room) === 0) {
                        if ($newStart < $exEnd && $newEnd > $exStart) {
                            $_SESSION['flash_error'] = "Schedule conflict: room '{$room}' is already booked on {$dayOfWeek} from " . date('g:i A', $exStart) . " - " . date('g:i A', $exEnd) . ".";
                            header("Location: ../registrar/section_details?id=" . $sectionId);
                            exit;
                        }
                    }

                    if ($instructorId !== null && !empty($ex['instructor_id']) && (int)$ex['instructor_id'] === (int)$instructorId) {
                        if ($newStart < $exEnd && $newEnd > $exStart) {
                            $_SESSION['flash_error'] = "Schedule conflict: instructor is already assigned on {$dayOfWeek} from " . date('g:i A', $exStart) . " - " . date('g:i A', $exEnd) . ".";
                            header("Location: ../registrar/section_details?id=" . $sectionId);
                            exit;
                        }
                    }
                }
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO section_subjects (section_id, subject_id, instructor_id, day_of_week, start_time, end_time, room)
                VALUES (:section_id, :subject_id, :instructor_id, :day_of_week, :start_time, :end_time, :room)
            ");
            $insertStmt->execute([
                'section_id' => $sectionId,
                'subject_id' => $subjectId,
                'instructor_id' => $instructorId,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'room' => $room
            ]);

            $_SESSION['flash_success'] = "Subject assigned to section successfully.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;

        } catch (\Throwable $e) {
            error_log("Create section subject failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to assign subject.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;
        }
        break;

    case 'update':
        $subjectScheduleId = isset($_POST['subject_schedule_id']) ? (int)$_POST['subject_schedule_id'] : 0;
        $instructorId = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? (int)$_POST['instructor_id'] : null;
        $dayOfWeek = isset($_POST['day_of_week']) ? trim($_POST['day_of_week']) : '';
        $startTime = isset($_POST['start_time']) ? trim($_POST['start_time']) : '';
        $endTime = isset($_POST['end_time']) ? trim($_POST['end_time']) : '';
        $room = isset($_POST['room']) ? trim($_POST['room']) : null;

        if ($subjectScheduleId <= 0 || empty($dayOfWeek) || empty($startTime) || empty($endTime)) {
            $_SESSION['flash_error'] = "All schedule fields are required.";
            header("Location: ../registrar/sections");
            exit;
        }

        if (strtotime($endTime) <= strtotime($startTime)) {
            $_SESSION['flash_error'] = "End time must be later than start time.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            // Verify the assignment exists and get section_id
            $checkStmt = $pdo->prepare("SELECT section_id FROM section_subjects WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $subjectScheduleId]);
            $assignment = $checkStmt->fetch();
            if (!$assignment) {
                $_SESSION['flash_error'] = "Subject assignment not found.";
                header("Location: ../registrar/sections");
                exit;
            }
            $sectionId = (int)$assignment['section_id'];

            // Verify instructor if provided
            if ($instructorId !== null) {
                $instCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'teacher' AND is_active = 1 LIMIT 1");
                $instCheck->execute(['id' => $instructorId]);
                if (!$instCheck->fetch()) {
                    $_SESSION['flash_error'] = "Selected instructor is not valid.";
                    header("Location: ../registrar/section_details?id=" . $sectionId);
                    exit;
                }
            }

            // Conflict checks excluding self
            $existingStmt = $pdo->prepare("SELECT ss.id, ss.day_of_week, ss.start_time, ss.end_time, ss.room, ss.instructor_id FROM section_subjects ss WHERE ss.section_id = :section_id AND ss.id != :id");
            $existingStmt->execute(['section_id' => $sectionId, 'id' => $subjectScheduleId]);
            $existingSubjects = $existingStmt->fetchAll();

            $newStart = strtotime($startTime);
            $newEnd = strtotime($endTime);

            foreach ($existingSubjects as $ex) {
                if (strcasecmp($ex['day_of_week'], $dayOfWeek) === 0) {
                    $exStart = strtotime($ex['start_time']);
                    $exEnd = strtotime($ex['end_time']);

                    if ($room !== null && $ex['room'] !== null && strcasecmp($ex['room'], $room) === 0) {
                        if ($newStart < $exEnd && $newEnd > $exStart) {
                            $_SESSION['flash_error'] = "Schedule conflict: room '{$room}' is already booked on {$dayOfWeek}.";
                            header("Location: ../registrar/section_details?id=" . $sectionId);
                            exit;
                        }
                    }

                    if ($instructorId !== null && !empty($ex['instructor_id']) && (int)$ex['instructor_id'] === (int)$instructorId) {
                        if ($newStart < $exEnd && $newEnd > $exStart) {
                            $_SESSION['flash_error'] = "Schedule conflict: instructor is already assigned on {$dayOfWeek}.";
                            header("Location: ../registrar/section_details?id=" . $sectionId);
                            exit;
                        }
                    }
                }
            }

            $updateStmt = $pdo->prepare("
                UPDATE section_subjects
                SET instructor_id = :instructor_id, day_of_week = :day_of_week, start_time = :start_time, end_time = :end_time, room = :room
                WHERE id = :id
            ");
            $updateStmt->execute([
                'instructor_id' => $instructorId,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'room' => $room,
                'id' => $subjectScheduleId
            ]);

            $_SESSION['flash_success'] = "Subject schedule updated successfully.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;

        } catch (\Throwable $e) {
            error_log("Update section subject failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to update subject schedule.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    case 'delete':
        $subjectScheduleId = isset($_POST['subject_schedule_id']) ? (int)$_POST['subject_schedule_id'] : 0;

        if (empty($subjectScheduleId)) {
            $_SESSION['flash_error'] = "Subject schedule ID is required.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            $checkStmt = $pdo->prepare("SELECT section_id FROM section_subjects WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $subjectScheduleId]);
            $assignment = $checkStmt->fetch();
            if (!$assignment) {
                $_SESSION['flash_error'] = "Subject assignment not found.";
                header("Location: ../registrar/sections");
                exit;
            }
            $sectionId = (int)$assignment['section_id'];

            $deleteStmt = $pdo->prepare("DELETE FROM section_subjects WHERE id = :id");
            $deleteStmt->execute(['id' => $subjectScheduleId]);

            $_SESSION['flash_success'] = "Subject removed from section successfully.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;

        } catch (\PDOException $e) {
            error_log("Delete section subject failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to remove subject.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    case 'generate_from_curriculum':
        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
        if ($sectionId <= 0) {
            $_SESSION['flash_error'] = "Valid Section ID is required.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            $secStmt = $pdo->prepare("
                SELECT s.*, t.semester AS term_semester
                FROM sections s
                LEFT JOIN academic_terms t ON t.id = s.academic_term_id
                WHERE s.id = :id
                LIMIT 1
            ");
            $secStmt->execute(['id' => $sectionId]);
            $section = $secStmt->fetch();
            if (!$section) {
                $_SESSION['flash_error'] = "Section not found.";
                header("Location: ../registrar/sections");
                exit;
            }

            $prog = $section['program'] ?: 'BSMT';
            $yl = $section['year_level'] ?: '1st Year';
            $termSem = $section['term_semester'] ?: '1st Semester';
            $semName = (stripos($termSem, '2nd') !== false) ? '2nd Semester' : ((stripos($termSem, 'summer') !== false) ? 'Summer' : '1st Semester');

            $currQuery = $pdo->prepare("
                SELECT cs.subject_id
                FROM curriculum_subjects cs
                JOIN curriculums c ON c.id = cs.curriculum_id
                JOIN programs p ON p.id = c.program_id
                WHERE p.program_code = :prog
                  AND c.is_active = 1
                  AND cs.year_level = :year_level
                  AND cs.semester = :semester
                ORDER BY cs.display_order ASC
            ");
            $currQuery->execute([
                'prog' => $prog,
                'year_level' => $yl,
                'semester' => $semName
            ]);
            $curriculumSubjects = $currQuery->fetchAll(PDO::FETCH_COLUMN);

            if (empty($curriculumSubjects)) {
                $_SESSION['flash_error'] = "No curriculum subjects found for {$prog} {$yl} {$semName}.";
                header("Location: ../registrar/section_details?id=" . $sectionId);
                exit;
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO section_subjects (section_id, subject_id)
                VALUES (?, ?)
            ");
            $inserted = 0;
            foreach ($curriculumSubjects as $sId) {
                // Check if already in section_subjects
                $existCheck = $pdo->prepare("SELECT id FROM section_subjects WHERE section_id = ? AND subject_id = ? LIMIT 1");
                $existCheck->execute([$sectionId, (int)$sId]);
                if (!$existCheck->fetch()) {
                    $insertStmt->execute([$sectionId, (int)$sId]);
                    $inserted++;
                }
            }

            if ($inserted > 0) {
                $_SESSION['flash_success'] = "Successfully generated {$inserted} subject(s) from the {$prog} curriculum.";
            } else {
                $_SESSION['flash_info'] = "All curriculum subjects for {$prog} {$yl} {$semName} are already assigned to this section.";
            }
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;

        } catch (\Throwable $e) {
            error_log("Generate curriculum subjects failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to generate curriculum subjects.";
            header("Location: ../registrar/section_details?id=" . $sectionId);
            exit;
        }
        break;

    default:
        $_SESSION['flash_error'] = "Invalid section subject action specified.";
        header("Location: ../registrar/sections");
        exit;
}
