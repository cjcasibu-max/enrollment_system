<?php
/**
 * Subject Actions Processor
 * Handles CRUD for subjects, prerequisite management, and deletion protection.
 * Secured with checkRole() and prepared statements.
 */

require_once __DIR__ . '/../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../registrar/subjects");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../registrar/subjects");
    exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

/**
 * Calculates a numerical chronological rank for Year Level and Semester
 * 1st Year 1st Sem = 1 ... 4th Year 2nd Sem = 8
 */
function getTermRank(string $yearLevel, string $semesterName): int
{
    $yearMap = [
        '1st Year' => 1,
        '2nd Year' => 2,
        '3rd Year' => 3,
        '4th Year' => 4
    ];
    $semMap = [
        '1st Semester' => 1,
        '2nd Semester' => 2,
        'Summer' => 3
    ];

    $y = $yearMap[$yearLevel] ?? 1;
    $s = $semMap[$semesterName] ?? 1;

    return ($y * 10) + $s;
}

/**
 * Validates prerequisite graph to prevent circular dependencies using DFS cycle detection.
 */
function validatePrerequisiteGraph(PDO $pdo, int $subjectId, array $prerequisiteIds): void
{
    // 1. Check self-prerequisite
    foreach ($prerequisiteIds as $pid) {
        if ((int)$pid === $subjectId) {
            throw new RuntimeException('This subject cannot be used as its own prerequisite.');
        }
    }

    // 2. Fetch all existing prerequisite edges from database
    $stmt = $pdo->query("SELECT subject_id, prerequisite_subject_id FROM subject_prerequisites");
    $allEdges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build adjacency list: node -> list of prerequisites it depends on
    // In our dependency graph: Subject A -> Prerequisite B means to take A, you need B.
    // A cycle exists if following prerequisites leads back to A.
    $adjacency = [];
    foreach ($allEdges as $edge) {
        $u = (int)$edge['subject_id'];
        $v = (int)$edge['prerequisite_subject_id'];
        if ($u !== $subjectId) { // Exclude old edges of current subject if updating
            $adjacency[$u][] = $v;
        }
    }

    // Add the new proposed edges for subjectId
    $adjacency[$subjectId] = array_values(array_unique(array_map('intval', $prerequisiteIds)));

    // DFS Cycle Detection
    $visited = [];
    $recStack = [];

    $hasCycle = function ($node) use (&$hasCycle, &$adjacency, &$visited, &$recStack) {
        $visited[$node] = true;
        $recStack[$node] = true;

        if (isset($adjacency[$node])) {
            foreach ($adjacency[$node] as $neighbor) {
                if (!isset($visited[$neighbor]) || !$visited[$neighbor]) {
                    if ($hasCycle($neighbor)) {
                        return true;
                    }
                } elseif (isset($recStack[$neighbor]) && $recStack[$neighbor]) {
                    return true;
                }
            }
        }

        $recStack[$node] = false;
        return false;
    };

    // Check from subjectId first
    if ($hasCycle($subjectId)) {
        throw new RuntimeException('Cannot assign this prerequisite because it creates a circular prerequisite.');
    }

    // Check all nodes in adjacency graph
    foreach (array_keys($adjacency) as $node) {
        if (!isset($visited[$node]) || !$visited[$node]) {
            if ($hasCycle($node)) {
                throw new RuntimeException('Cannot assign this prerequisite because it creates a circular prerequisite.');
            }
        }
    }
}

switch ($action) {
    case 'create':
        $subjectCode = isset($_POST['subject_code']) ? strtoupper(trim($_POST['subject_code'])) : '';
        $subjectName = isset($_POST['subject_name']) ? trim($_POST['subject_name']) : '';
        $programId = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
        $yearLevel = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';
        $semesterName = isset($_POST['semester_name']) ? trim($_POST['semester_name']) : '';
        $units = isset($_POST['units']) ? (float)$_POST['units'] : 3.0;
        $description = isset($_POST['description']) ? trim($_POST['description']) : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'active';
        $prerequisiteIds = isset($_POST['prerequisite_subject_ids']) && is_array($_POST['prerequisite_subject_ids']) 
            ? array_values(array_filter(array_map('intval', $_POST['prerequisite_subject_ids']), fn($id) => $id > 0)) 
            : [];

        if (empty($subjectCode) || empty($subjectName) || $programId <= 0 || empty($yearLevel) || empty($semesterName) || $units < 0.5 || $units > 30) {
            $_SESSION['flash_error'] = "All required fields must be filled correctly. Units must be valid.";
            header("Location: ../registrar/subjects");
            exit;
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        try {
            // Verify Program
            $programCheck = $pdo->prepare("SELECT id, program_code FROM programs WHERE id = :id LIMIT 1");
            $programCheck->execute(['id' => $programId]);
            $program = $programCheck->fetch();
            if (!$program) {
                $_SESSION['flash_error'] = "Selected academic program does not exist.";
                header("Location: ../registrar/subjects");
                exit;
            }

            // Check Duplicate Subject Code within same program
            $dupCheck = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = :code AND program_id = :program_id LIMIT 1");
            $dupCheck->execute(['code' => $subjectCode, 'program_id' => $programId]);
            if ($dupCheck->fetch()) {
                $_SESSION['flash_error'] = "Subject code '{$subjectCode}' already exists for {$program['program_code']}.";
                header("Location: ../registrar/subjects?program=" . urlencode($program['program_code']));
                exit;
            }

            // Validate Prerequisites Program and Chronological Constraints
            if (!empty($prerequisiteIds)) {
                $subjectRank = getTermRank($yearLevel, $semesterName);
                $placeholders = implode(',', array_fill(0, count($prerequisiteIds), '?'));
                $prereqCheckStmt = $pdo->prepare("SELECT id, subject_code, subject_name, program_id, year_level, semester_name FROM subjects WHERE id IN ($placeholders)");
                $prereqCheckStmt->execute($prerequisiteIds);
                $prereqSubjects = $prereqCheckStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($prereqSubjects as $pSub) {
                    if ((int)$pSub['program_id'] !== $programId) {
                        throw new RuntimeException("Prerequisite '{$pSub['subject_code']}' does not belong to the same program ({$program['program_code']}).");
                    }
                    $pRank = getTermRank($pSub['year_level'], $pSub['semester_name']);
                    if ($pRank > $subjectRank) {
                        throw new RuntimeException("Prerequisite '{$pSub['subject_code']}' ({$pSub['year_level']} - {$pSub['semester_name']}) occurs after this subject ({$yearLevel} - {$semesterName}). Prerequisites must be taken in earlier or concurrent terms.");
                    }
                }
            }

            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO subjects (curriculum_id, subject_code, subject_name, program_id, year_level, semester_name, units, description, status)
                VALUES (NULL, :subject_code, :subject_name, :program_id, :year_level, :semester_name, :units, :description, :status)
            ");
            $insertStmt->execute([
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'program_id' => $programId,
                'year_level' => $yearLevel,
                'semester_name' => $semesterName,
                'units' => $units,
                'description' => $description,
                'status' => $status
            ]);
            $subjectId = (int)$pdo->lastInsertId();

            if (!empty($prerequisiteIds)) {
                validatePrerequisiteGraph($pdo, $subjectId, $prerequisiteIds);

                $prereqStmt = $pdo->prepare("
                    INSERT INTO subject_prerequisites (subject_id, prerequisite_subject_id)
                    VALUES (:subject_id, :prerequisite_subject_id)
                ");
                foreach (array_unique($prerequisiteIds) as $pid) {
                    if ((int)$pid === $subjectId) continue;
                    $prereqStmt->execute([
                        'subject_id' => $subjectId,
                        'prerequisite_subject_id' => (int)$pid
                    ]);
                }
            }

            $pdo->commit();
            $_SESSION['flash_success'] = "Subject '{$subjectCode} - {$subjectName}' created successfully.";
            header("Location: ../registrar/subjects?program=" . urlencode($program['program_code']) . "&year_level=" . urlencode($yearLevel) . "&semester=" . urlencode($semesterName));
            exit;

        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Create subject validation error: " . $e->getMessage());
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: ../registrar/subjects");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Create subject unexpected error: " . $e->getMessage());
            $_SESSION['flash_error'] = "An unexpected error occurred while creating the subject. Please try again.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    case 'update':
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $subjectCode = isset($_POST['subject_code']) ? strtoupper(trim($_POST['subject_code'])) : '';
        $subjectName = isset($_POST['subject_name']) ? trim($_POST['subject_name']) : '';
        $programId = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
        $yearLevel = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';
        $semesterName = isset($_POST['semester_name']) ? trim($_POST['semester_name']) : '';
        $units = isset($_POST['units']) ? (float)$_POST['units'] : 3.0;
        $description = isset($_POST['description']) ? trim($_POST['description']) : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'active';
        $prerequisiteIds = isset($_POST['prerequisite_subject_ids']) && is_array($_POST['prerequisite_subject_ids']) 
            ? array_values(array_filter(array_map('intval', $_POST['prerequisite_subject_ids']), fn($id) => $id > 0)) 
            : [];

        if ($subjectId <= 0 || empty($subjectCode) || empty($subjectName) || $programId <= 0 || empty($yearLevel) || empty($semesterName) || $units < 0.5 || $units > 30) {
            $_SESSION['flash_error'] = "All required fields must be filled correctly.";
            header("Location: ../registrar/subjects");
            exit;
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        try {
            $subjectCheck = $pdo->prepare("SELECT id, subject_code, program_id FROM subjects WHERE id = :id LIMIT 1");
            $subjectCheck->execute(['id' => $subjectId]);
            $existingSubject = $subjectCheck->fetch();
            if (!$existingSubject) {
                $_SESSION['flash_error'] = "Subject not found.";
                header("Location: ../registrar/subjects");
                exit;
            }

            $programCheck = $pdo->prepare("SELECT id, program_code FROM programs WHERE id = :id LIMIT 1");
            $programCheck->execute(['id' => $programId]);
            $program = $programCheck->fetch();
            if (!$program) {
                $_SESSION['flash_error'] = "Selected program does not exist.";
                header("Location: ../registrar/subjects");
                exit;
            }

            $dupCheck = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = :code AND program_id = :program_id AND id != :id LIMIT 1");
            $dupCheck->execute(['code' => $subjectCode, 'program_id' => $programId, 'id' => $subjectId]);
            if ($dupCheck->fetch()) {
                $_SESSION['flash_error'] = "Subject code '{$subjectCode}' already exists for {$program['program_code']}.";
                header("Location: ../registrar/subjects?program=" . urlencode($program['program_code']));
                exit;
            }

            // Validate Prerequisites Program and Chronological Constraints
            if (!empty($prerequisiteIds)) {
                $subjectRank = getTermRank($yearLevel, $semesterName);
                $placeholders = implode(',', array_fill(0, count($prerequisiteIds), '?'));
                $prereqCheckStmt = $pdo->prepare("SELECT id, subject_code, subject_name, program_id, year_level, semester_name FROM subjects WHERE id IN ($placeholders)");
                $prereqCheckStmt->execute($prerequisiteIds);
                $prereqSubjects = $prereqCheckStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($prereqSubjects as $pSub) {
                    if ((int)$pSub['id'] === $subjectId) {
                        throw new RuntimeException("This subject cannot be used as its own prerequisite.");
                    }
                    if ((int)$pSub['program_id'] !== $programId) {
                        throw new RuntimeException("Prerequisite '{$pSub['subject_code']}' does not belong to the same program ({$program['program_code']}).");
                    }
                    $pRank = getTermRank($pSub['year_level'], $pSub['semester_name']);
                    if ($pRank > $subjectRank) {
                        throw new RuntimeException("Prerequisite '{$pSub['subject_code']}' ({$pSub['year_level']} - {$pSub['semester_name']}) occurs after this subject ({$yearLevel} - {$semesterName}). Prerequisites must be taken in earlier or concurrent terms.");
                    }
                }
            }

            // Check Circular Dependencies
            validatePrerequisiteGraph($pdo, $subjectId, $prerequisiteIds);

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE subjects
                SET subject_code = :subject_code, subject_name = :subject_name, program_id = :program_id,
                    year_level = :year_level, semester_name = :semester_name, units = :units,
                    description = :description, status = :status
                WHERE id = :id
            ");
            $updateStmt->execute([
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'program_id' => $programId,
                'year_level' => $yearLevel,
                'semester_name' => $semesterName,
                'units' => $units,
                'description' => $description,
                'status' => $status,
                'id' => $subjectId
            ]);

            // Replace prerequisite records
            $deleteStmt = $pdo->prepare("DELETE FROM subject_prerequisites WHERE subject_id = :subject_id");
            $deleteStmt->execute(['subject_id' => $subjectId]);

            if (!empty($prerequisiteIds)) {
                $prereqStmt = $pdo->prepare("
                    INSERT INTO subject_prerequisites (subject_id, prerequisite_subject_id)
                    VALUES (:subject_id, :prerequisite_subject_id)
                ");
                foreach (array_unique($prerequisiteIds) as $pid) {
                    if ((int)$pid === $subjectId) continue;
                    $prereqStmt->execute([
                        'subject_id' => $subjectId,
                        'prerequisite_subject_id' => (int)$pid
                    ]);
                }
            }

            $pdo->commit();
            $_SESSION['flash_success'] = "Subject '{$subjectCode}' updated successfully.";
            header("Location: ../registrar/subjects?program=" . urlencode($program['program_code']));
            exit;

        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Update subject validation error: " . $e->getMessage());
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: ../registrar/subjects");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Update subject unexpected error: " . $e->getMessage());
            $_SESSION['flash_error'] = "An unexpected error occurred while updating the subject. Please try again.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    case 'toggle_status':
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        if ($subjectId <= 0) {
            $_SESSION['flash_error'] = "Subject ID is required.";
            header("Location: ../registrar/subjects");
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, subject_code, status, (SELECT program_code FROM programs WHERE id = subjects.program_id) as program_code FROM subjects WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $subjectId]);
            $subject = $stmt->fetch();
            if (!$subject) {
                $_SESSION['flash_error'] = "Subject not found.";
                header("Location: ../registrar/subjects");
                exit;
            }

            $newStatus = ($subject['status'] === 'active') ? 'inactive' : 'active';
            $updateStmt = $pdo->prepare("UPDATE subjects SET status = :status WHERE id = :id");
            $updateStmt->execute(['status' => $newStatus, 'id' => $subjectId]);

            $_SESSION['flash_success'] = "Subject '{$subject['subject_code']}' status changed to " . ucfirst($newStatus) . ".";
            header("Location: ../registrar/subjects?program=" . urlencode($subject['program_code'] ?? 'BSMT'));
            exit;
        } catch (\Throwable $e) {
            error_log("Toggle subject status failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Failed to update subject status.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    case 'delete':
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;

        if ($subjectId <= 0) {
            $_SESSION['flash_error'] = "Subject ID is required.";
            header("Location: ../registrar/subjects");
            exit;
        }

        try {
            $subjectCheck = $pdo->prepare("
                SELECT s.id, s.subject_code, s.subject_name, p.program_code 
                FROM subjects s 
                JOIN programs p ON p.id = s.program_id 
                WHERE s.id = :id LIMIT 1
            ");
            $subjectCheck->execute(['id' => $subjectId]);
            $subject = $subjectCheck->fetch();
            if (!$subject) {
                $_SESSION['flash_error'] = "Subject not found.";
                header("Location: ../registrar/subjects");
                exit;
            }

            // 1. Check if this subject is used as a prerequisite for OTHER subjects
            $depCheck = $pdo->prepare("
                SELECT s.subject_code, s.subject_name 
                FROM subject_prerequisites sp 
                JOIN subjects s ON s.id = sp.subject_id 
                WHERE sp.prerequisite_subject_id = :id
            ");
            $depCheck->execute(['id' => $subjectId]);
            $dependentSubjects = $depCheck->fetchAll();

            if (!empty($dependentSubjects)) {
                $depCodes = array_column($dependentSubjects, 'subject_code');
                $_SESSION['flash_error'] = "Cannot delete '{$subject['subject_code']}' because it is required as a prerequisite by: " . implode(', ', $depCodes) . ". Please remove those prerequisite requirements or deactivate this subject instead.";
                header("Location: ../registrar/subjects?program=" . urlencode($subject['program_code']));
                exit;
            }

            // 2. Check if students are enrolled in sections offering this subject
            $enrollCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM section_subjects ss 
                JOIN enrollments e ON e.section_id = ss.section_id 
                WHERE ss.subject_id = :id AND e.status != 'dropped'
            ");
            $enrollCheck->execute(['id' => $subjectId]);
            if ((int)$enrollCheck->fetchColumn() > 0) {
                $_SESSION['flash_error'] = "Cannot delete '{$subject['subject_code']}' because students are actively enrolled in sections offering this subject. Please deactivate the subject instead.";
                header("Location: ../registrar/subjects?program=" . urlencode($subject['program_code']));
                exit;
            }

            // 3. Check historical grades
            $gradeCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM student_grades sg
                JOIN enrollments e ON e.id = sg.enrollment_id
                JOIN section_subjects ss ON ss.section_id = e.section_id
                WHERE ss.subject_id = :id
            ");
            $gradeCheck->execute(['id' => $subjectId]);
            if ((int)$gradeCheck->fetchColumn() > 0) {
                $_SESSION['flash_error'] = "Cannot delete '{$subject['subject_code']}' because student grade records are linked to it. Please deactivate the subject instead.";
                header("Location: ../registrar/subjects?program=" . urlencode($subject['program_code']));
                exit;
            }

            $pdo->beginTransaction();

            // Delete prerequisites where this subject is the parent
            $deletePrereqStmt = $pdo->prepare("DELETE FROM subject_prerequisites WHERE subject_id = :id");
            $deletePrereqStmt->execute(['id' => $subjectId]);

            // Delete any section_subjects mappings
            $deleteSecSubStmt = $pdo->prepare("DELETE FROM section_subjects WHERE subject_id = :id");
            $deleteSecSubStmt->execute(['id' => $subjectId]);

            // Delete subject
            $deleteSubjectStmt = $pdo->prepare("DELETE FROM subjects WHERE id = :id");
            $deleteSubjectStmt->execute(['id' => $subjectId]);

            $pdo->commit();
            $_SESSION['flash_success'] = "Subject '{$subject['subject_code']}' deleted successfully.";
            header("Location: ../registrar/subjects?program=" . urlencode($subject['program_code']));
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Delete subject failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to delete subject.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    default:
        $_SESSION['flash_error'] = "Invalid action specified.";
        header("Location: ../registrar/subjects");
        exit;
}
