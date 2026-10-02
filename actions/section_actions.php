<?php
/**
 * Section Actions Processor
 * Handles CRUD requests for course sections from the registrar/admin dashboard.
 * Secured with checkRole() and prepared statements.
 */

require_once __DIR__ . '/../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/registration_rules.php';

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
        $program = isset($_POST['program']) ? trim($_POST['program']) : '';
        $yearLevel = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';
        $sectionType = isset($_POST['section_type']) ? trim($_POST['section_type']) : '';
        $sectionNumber = isset($_POST['section_number']) ? trim($_POST['section_number']) : '';
        $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 40;
        $sectionName = isset($_POST['section_name']) ? trim($_POST['section_name']) : '';

        $yearMap = ['1st Year'=>'1', '2nd Year'=>'2', '3rd Year'=>'3', '4th Year'=>'4'];
        $yearNum = $yearMap[$yearLevel] ?? '1';

        if (empty($program)) {
            $program = 'BSMT';
        }

        if (empty($sectionName)) {
            $sectionName = strtoupper($program . ' ' . $yearNum . '-' . ($sectionType ?: 'M') . ($sectionNumber ?: '1'));
        }

        $missing = [];
        if (empty($yearLevel)) $missing[] = 'Year Level';
        if ($capacity < 1 || $capacity > 150) $missing[] = 'Capacity (1-150)';

        if (!empty($missing)) {
            $_SESSION['flash_error'] = "Missing required fields: " . implode(', ', $missing) . ".";
            header("Location: ../registrar/sections?program=" . urlencode($program));
            exit;
        }

        try {
            $activeTerm = requireActiveAcademicTerm($pdo);

            $dupNameCheck = $pdo->prepare("SELECT id FROM sections WHERE section_name = :section_name AND academic_term_id = :term_id LIMIT 1");
            $dupNameCheck->execute(['section_name' => $sectionName, 'term_id' => $activeTerm['id']]);
            if ($dupNameCheck->fetch()) {
                $_SESSION['flash_error'] = "A section named '{$sectionName}' already exists for the active academic term.";
                header("Location: ../registrar/sections?program=" . urlencode($program));
                exit;
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO sections (section_name, academic_term_id, capacity, year_level, program, status)
                VALUES (:section_name, :academic_term_id, :capacity, :year_level, :program, :status)
            ");
            $insertStmt->execute([
                'section_name' => $sectionName,
                'academic_term_id' => $activeTerm['id'],
                'capacity' => $capacity,
                'year_level' => $yearLevel,
                'program' => $program,
                'status' => 'active'
            ]);

            $sectionId = (int)$pdo->lastInsertId();

            // Automatic Section Subject Generation from matching active curriculum
            $termSem = $activeTerm['semester'] ?? '1st';
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
                'prog' => $program,
                'year_level' => $yearLevel,
                'semester' => $semName
            ]);
            $curriculumSubjects = $currQuery->fetchAll(PDO::FETCH_COLUMN);

            $secSubInsert = $pdo->prepare("INSERT INTO section_subjects (section_id, subject_id) VALUES (?, ?)");
            $genCount = 0;
            foreach ($curriculumSubjects as $sId) {
                $secSubInsert->execute([$sectionId, (int)$sId]);
                $genCount++;
            }

            $_SESSION['flash_success'] = "Section '{$sectionName}' created successfully with {$genCount} curriculum subjects assigned.";
            header("Location: ../registrar/sections?program=" . urlencode($program) . "&year_level=" . urlencode($yearLevel));
            exit;

        } catch (\Throwable $e) {
            error_log("Create section failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to create section.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    case 'update':
        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
        $program = isset($_POST['program']) ? trim($_POST['program']) : '';
        $yearLevel = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';
        $sectionName = isset($_POST['section_name']) ? trim($_POST['section_name']) : '';
        $capacity = isset($_POST['capacity']) ? (int)$_POST['capacity'] : 40;
        $status = isset($_POST['status']) && in_array($_POST['status'], ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if ($sectionId <= 0 || empty($sectionName) || empty($yearLevel)) {
            $_SESSION['flash_error'] = "All required fields must be filled.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            $activeTerm = requireActiveAcademicTerm($pdo);
            $secCheck = $pdo->prepare("SELECT id, academic_term_id, program FROM sections WHERE id = :id LIMIT 1");
            $secCheck->execute(['id' => $sectionId]);
            $existingSection = $secCheck->fetch();
            if (!$existingSection) { 
                $_SESSION['flash_error'] = "Section record not found."; 
                header("Location: ../registrar/sections"); 
                exit; 
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE section_id = :section_id AND status != 'dropped'");
            $countStmt->execute(['section_id' => $sectionId]);
            $currentEnrolled = (int)$countStmt->fetchColumn();
            if ($capacity < $currentEnrolled) { 
                $_SESSION['flash_error'] = "Capacity cannot be lower than the current registered student count ({$currentEnrolled})."; 
                header("Location: ../registrar/sections"); 
                exit; 
            }

            $sectionTermId = $existingSection['academic_term_id'] ?? $activeTerm['id'];

            $dupNameCheck = $pdo->prepare("SELECT id FROM sections WHERE section_name = :section_name AND academic_term_id = :term_id AND id != :id LIMIT 1");
            $dupNameCheck->execute(['section_name' => $sectionName, 'term_id' => $sectionTermId, 'id' => $sectionId]);
            if ($dupNameCheck->fetch()) {
                $_SESSION['flash_error'] = "A section named '{$sectionName}' already exists for this academic term.";
                header("Location: ../registrar/sections");
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE sections
                SET section_name = :section_name, capacity = :capacity, 
                    year_level = :year_level, program = :program, status = :status
                WHERE id = :id
            ");
            $updateStmt->execute([
                'section_name' => $sectionName,
                'capacity' => $capacity,
                'year_level' => $yearLevel,
                'program' => $program ?: $existingSection['program'],
                'status' => $status,
                'id' => $sectionId
            ]);

            $_SESSION['flash_success'] = "Section '{$sectionName}' updated successfully.";
            header("Location: ../registrar/sections?program=" . urlencode($program ?: 'BSMT'));
            exit;

        } catch (\Throwable $e) {
            error_log("Update section failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to update section.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    case 'toggle_status':
        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
        if ($sectionId <= 0) {
            $_SESSION['flash_error'] = "Section ID is required.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, section_name, status, program FROM sections WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $sectionId]);
            $sec = $stmt->fetch();
            if (!$sec) {
                $_SESSION['flash_error'] = "Section not found.";
                header("Location: ../registrar/sections");
                exit;
            }

            $newStatus = ($sec['status'] === 'active') ? 'inactive' : 'active';
            $updateStmt = $pdo->prepare("UPDATE sections SET status = :status WHERE id = :id");
            $updateStmt->execute(['status' => $newStatus, 'id' => $sectionId]);

            $_SESSION['flash_success'] = "Section '{$sec['section_name']}' status changed to " . ucfirst($newStatus) . ".";
            header("Location: ../registrar/sections?program=" . urlencode($sec['program'] ?? 'BSMT'));
            exit;
        } catch (\Throwable $e) {
            error_log("Toggle section status failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Failed to update section status.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    case 'delete':
        $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;

        if ($sectionId <= 0) {
            $_SESSION['flash_error'] = "Section ID is required to delete.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, section_name, program FROM sections WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $sectionId]);
            $sec = $stmt->fetch();
            if (!$sec) {
                $_SESSION['flash_error'] = "Section not found.";
                header("Location: ../registrar/sections");
                exit;
            }

            // Block delete if active enrollments exist
            $activeEnrollStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE section_id = :section_id AND status != 'dropped'");
            $activeEnrollStmt->execute(['section_id' => $sectionId]);
            $activeCount = (int)$activeEnrollStmt->fetchColumn();
            if ($activeCount > 0) {
                $_SESSION['flash_error'] = "Cannot delete '{$sec['section_name']}': {$activeCount} student(s) currently have active registrations in it. Please deactivate the section or drop the enrollments first.";
                header("Location: ../registrar/sections?program=" . urlencode($sec['program'] ?? 'BSMT'));
                exit;
            }

            // Delete section_subjects
            $pdo->prepare("DELETE FROM section_subjects WHERE section_id = :id")->execute(['id' => $sectionId]);

            // Delete section
            $deleteStmt = $pdo->prepare("DELETE FROM sections WHERE id = :id");
            $deleteStmt->execute(['id' => $sectionId]);

            $_SESSION['flash_success'] = "Section '{$sec['section_name']}' deleted successfully.";
            header("Location: ../registrar/sections?program=" . urlencode($sec['program'] ?? 'BSMT'));
            exit;

        } catch (\PDOException $e) {
            error_log("Delete section failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to delete section.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    case 'remove_student':
        $enrollmentId = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;

        if ($enrollmentId <= 0) {
            $_SESSION['flash_error'] = "Enrollment ID is required.";
            header("Location: ../registrar/sections");
            exit;
        }

        try {
            $enrCheck = $pdo->prepare("SELECT e.section_id, s.section_name FROM enrollments e JOIN sections s ON s.id = e.section_id WHERE e.id = :id LIMIT 1");
            $enrCheck->execute(['id' => $enrollmentId]);
            $enrollment = $enrCheck->fetch();
            if (!$enrollment) {
                $_SESSION['flash_error'] = "Enrollment record not found.";
                header("Location: ../registrar/sections");
                exit;
            }
            $targetSectionId = (int)$enrollment['section_id'];

            $updateStmt = $pdo->prepare("UPDATE enrollments SET status = 'dropped', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status != 'dropped'");
            $updateStmt->execute(['id' => $enrollmentId]);

            $_SESSION['flash_success'] = "Student removed from section successfully.";
            header("Location: ../registrar/section_details?id=" . $targetSectionId);
            exit;

        } catch (\PDOException $e) {
            error_log("Remove student from section failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to remove student from section.";
            header("Location: ../registrar/sections");
            exit;
        }
        break;

    default:
        $_SESSION['flash_error'] = "Invalid section action specified.";
        header("Location: ../registrar/sections");
        exit;
}
