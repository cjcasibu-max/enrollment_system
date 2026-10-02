<?php
/**
 * Admin Curriculum Actions Processor
 * Handles complete administrative control for Curriculums, Curriculum Subjects, and Prerequisites.
 * Strictly protected with checkRole(['admin']) and CSRF token validation.
 */

require_once __DIR__ . '/../includes/auth_check.php';
checkRole(['admin']);

require_once __DIR__ . '/../config/database.php';

/**
 * Helper to record administrative accountability logs in audit_logs table
 */
function logCurriculumAudit(PDO $pdo, ?int $actorId, string $action, ?int $itemId, string $description): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (actor_id, action, item_type, item_id, description, created_at)
            VALUES (:actor_id, :action, 'curriculum', :item_id, :description, NOW())
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

/**
 * Validates prerequisite graph to prevent circular dependencies using DFS cycle detection.
 */
function validatePrerequisiteGraph(PDO $pdo, int $subjectId, array $prerequisiteIds): void
{
    foreach ($prerequisiteIds as $pid) {
        if ((int)$pid === $subjectId) {
            throw new RuntimeException('A course cannot be configured as its own prerequisite.');
        }
    }

    $stmt = $pdo->query("SELECT subject_id, prerequisite_subject_id FROM subject_prerequisites");
    $allEdges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $adjacency = [];
    foreach ($allEdges as $edge) {
        $u = (int)$edge['subject_id'];
        $v = (int)$edge['prerequisite_subject_id'];
        if ($u !== $subjectId) {
            $adjacency[$u][] = $v;
        }
    }

    $adjacency[$subjectId] = array_values(array_unique(array_map('intval', $prerequisiteIds)));

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

    if ($hasCycle($subjectId)) {
        throw new RuntimeException('Circular prerequisite detected! A subject cannot directly or indirectly require itself.');
    }

    foreach (array_keys($adjacency) as $node) {
        if (!isset($visited[$node]) || !$visited[$node]) {
            if ($hasCycle($node)) {
                throw new RuntimeException('Circular prerequisite detected in curriculum dependency tree.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (php_sapi_name() === 'cli') {
        return;
    }
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../admin/curriculum");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../admin/curriculum");
    exit;
}

$action = trim($_POST['action'] ?? '');
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

try {
    switch ($action) {
        case 'create_curriculum':
            $programId = (int)($_POST['program_id'] ?? 0);
            $curriculumName = trim($_POST['curriculum_name'] ?? '');
            $effectiveYear = trim($_POST['effective_year'] ?? '');
            $description = trim($_POST['description'] ?? '') ?: null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $cloneSourceId = (int)($_POST['clone_source_id'] ?? 0);

            if ($programId <= 0 || empty($curriculumName) || empty($effectiveYear)) {
                throw new RuntimeException("Academic program, curriculum name, and effective school year are required.");
            }

            // Verify program exists
            $progStmt = $pdo->prepare("SELECT program_code FROM programs WHERE id = :id");
            $progStmt->execute(['id' => $programId]);
            $program = $progStmt->fetch(PDO::FETCH_ASSOC);
            if (!$program) {
                throw new RuntimeException("Selected academic program does not exist.");
            }

            $pdo->beginTransaction();

            // If activating, deactivate other curriculums for this program
            if ($isActive === 1) {
                $deactStmt = $pdo->prepare("UPDATE curriculums SET is_active = 0 WHERE program_id = :pid");
                $deactStmt->execute(['pid' => $programId]);
            }

            $insStmt = $pdo->prepare("
                INSERT INTO curriculums (program_id, curriculum_name, effective_year, is_active, description)
                VALUES (:program_id, :curriculum_name, :effective_year, :is_active, :description)
            ");
            $insStmt->execute([
                'program_id' => $programId,
                'curriculum_name' => $curriculumName,
                'effective_year' => $effectiveYear,
                'is_active' => $isActive,
                'description' => $description
            ]);
            $newCurriculumId = (int)$pdo->lastInsertId();

            // Handle optional cloning of subjects from template
            $clonedCount = 0;
            if ($cloneSourceId > 0) {
                $srcSubjectsStmt = $pdo->prepare("
                    SELECT subject_id, year_level, semester, units, is_required, display_order, subject_type
                    FROM curriculum_subjects
                    WHERE curriculum_id = :src_id
                    ORDER BY id ASC
                ");
                $srcSubjectsStmt->execute(['src_id' => $cloneSourceId]);
                $srcSubjects = $srcSubjectsStmt->fetchAll(PDO::FETCH_ASSOC);

                $copyStmt = $pdo->prepare("
                    INSERT INTO curriculum_subjects (curriculum_id, subject_id, year_level, semester, units, is_required, display_order, subject_type)
                    VALUES (:curr_id, :subj_id, :year_level, :semester, :units, :is_required, :display_order, :subject_type)
                ");

                foreach ($srcSubjects as $sub) {
                    $copyStmt->execute([
                        'curr_id' => $newCurriculumId,
                        'subj_id' => $sub['subject_id'],
                        'year_level' => $sub['year_level'],
                        'semester' => $sub['semester'],
                        'units' => $sub['units'],
                        'is_required' => $sub['is_required'],
                        'display_order' => $sub['display_order'],
                        'subject_type' => $sub['subject_type']
                    ]);
                    $clonedCount++;
                }
            }

            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'create_curriculum', $newCurriculumId, "Created curriculum '{$curriculumName}' (AY {$effectiveYear}) for {$program['program_code']} with {$clonedCount} cloned subjects.");

            $_SESSION['flash_success'] = "Curriculum '{$curriculumName}' created successfully" . ($clonedCount > 0 ? " with {$clonedCount} mapped subjects." : ".");
            header("Location: ../admin/curriculum?program=" . urlencode($program['program_code']) . "&curriculum_id=" . $newCurriculumId);
            exit;

        case 'update_curriculum':
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);
            $curriculumName = trim($_POST['curriculum_name'] ?? '');
            $effectiveYear = trim($_POST['effective_year'] ?? '');
            $description = trim($_POST['description'] ?? '') ?: null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($curriculumId <= 0 || empty($curriculumName) || empty($effectiveYear)) {
                throw new RuntimeException("Curriculum ID, name, and effective school year are required.");
            }

            $currStmt = $pdo->prepare("SELECT c.*, p.program_code FROM curriculums c JOIN programs p ON p.id = c.program_id WHERE c.id = :id");
            $currStmt->execute(['id' => $curriculumId]);
            $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                throw new RuntimeException("Curriculum not found.");
            }

            $pdo->beginTransaction();

            if ($isActive === 1 && (int)$curr['is_active'] !== 1) {
                $deactStmt = $pdo->prepare("UPDATE curriculums SET is_active = 0 WHERE program_id = :pid");
                $deactStmt->execute(['pid' => $curr['program_id']]);
            }

            $updStmt = $pdo->prepare("
                UPDATE curriculums 
                SET curriculum_name = :name, effective_year = :year, description = :description, is_active = :is_active
                WHERE id = :id
            ");
            $updStmt->execute([
                'name' => $curriculumName,
                'year' => $effectiveYear,
                'description' => $description,
                'is_active' => $isActive,
                'id' => $curriculumId
            ]);

            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'update_curriculum', $curriculumId, "Updated curriculum details for '{$curriculumName}' (AY {$effectiveYear}).");

            $_SESSION['flash_success'] = "Curriculum details updated successfully.";
            header("Location: ../admin/curriculum?program=" . urlencode($curr['program_code']) . "&curriculum_id=" . $curriculumId);
            exit;

        case 'activate_curriculum':
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);
            if ($curriculumId <= 0) {
                throw new RuntimeException("Curriculum ID is required.");
            }

            $currStmt = $pdo->prepare("SELECT c.*, p.program_code FROM curriculums c JOIN programs p ON p.id = c.program_id WHERE c.id = :id");
            $currStmt->execute(['id' => $curriculumId]);
            $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                throw new RuntimeException("Curriculum not found.");
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE curriculums SET is_active = 0 WHERE program_id = :pid")->execute(['pid' => $curr['program_id']]);
            $pdo->prepare("UPDATE curriculums SET is_active = 1 WHERE id = :id")->execute(['id' => $curriculumId]);
            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'activate_curriculum', $curriculumId, "Set curriculum '{$curr['curriculum_name']}' as active for {$curr['program_code']}.");

            $_SESSION['flash_success'] = "Curriculum '{$curr['curriculum_name']}' is now the active curriculum.";
            header("Location: ../admin/curriculum?program=" . urlencode($curr['program_code']) . "&curriculum_id=" . $curriculumId);
            exit;

        case 'clone_curriculum':
            $sourceId = (int)($_POST['curriculum_id'] ?? 0);
            $newName = trim($_POST['new_curriculum_name'] ?? '');
            $newYear = trim($_POST['new_effective_year'] ?? '');
            $newDesc = trim($_POST['new_description'] ?? '') ?: null;
            $setActive = isset($_POST['set_active']) ? 1 : 0;

            if ($sourceId <= 0 || empty($newName) || empty($newYear)) {
                throw new RuntimeException("Source curriculum, new name, and effective year are required.");
            }

            $srcStmt = $pdo->prepare("SELECT c.*, p.program_code FROM curriculums c JOIN programs p ON p.id = c.program_id WHERE c.id = :id");
            $srcStmt->execute(['id' => $sourceId]);
            $srcCurr = $srcStmt->fetch(PDO::FETCH_ASSOC);
            if (!$srcCurr) {
                throw new RuntimeException("Source curriculum not found.");
            }

            $pdo->beginTransaction();

            if ($setActive === 1) {
                $pdo->prepare("UPDATE curriculums SET is_active = 0 WHERE program_id = :pid")->execute(['pid' => $srcCurr['program_id']]);
            }

            $cloneStmt = $pdo->prepare("
                INSERT INTO curriculums (program_id, curriculum_name, effective_year, is_active, description)
                VALUES (:pid, :name, :year, :active, :desc)
            ");
            $cloneStmt->execute([
                'pid' => $srcCurr['program_id'],
                'name' => $newName,
                'year' => $newYear,
                'active' => $setActive,
                'desc' => $newDesc ?: "Cloned from {$srcCurr['curriculum_name']}"
            ]);
            $clonedCurrId = (int)$pdo->lastInsertId();

            // Clone subjects
            $subsStmt = $pdo->prepare("SELECT subject_id, year_level, semester, units, is_required, display_order, subject_type FROM curriculum_subjects WHERE curriculum_id = :src_id");
            $subsStmt->execute(['src_id' => $sourceId]);
            $srcSubs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);

            $insSubStmt = $pdo->prepare("
                INSERT INTO curriculum_subjects (curriculum_id, subject_id, year_level, semester, units, is_required, display_order, subject_type)
                VALUES (:curr_id, :subj_id, :year_level, :semester, :units, :is_required, :display_order, :subject_type)
            ");

            foreach ($srcSubs as $sub) {
                $insSubStmt->execute([
                    'curr_id' => $clonedCurrId,
                    'subj_id' => $sub['subject_id'],
                    'year_level' => $sub['year_level'],
                    'semester' => $sub['semester'],
                    'units' => $sub['units'],
                    'is_required' => $sub['is_required'],
                    'display_order' => $sub['display_order'],
                    'subject_type' => $sub['subject_type']
                ]);
            }

            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'clone_curriculum', $clonedCurrId, "Duplicated curriculum '{$srcCurr['curriculum_name']}' into '{$newName}' with " . count($srcSubs) . " subjects.");

            $_SESSION['flash_success'] = "Successfully cloned curriculum into '{$newName}' (" . count($srcSubs) . " subjects copied).";
            header("Location: ../admin/curriculum?program=" . urlencode($srcCurr['program_code']) . "&curriculum_id=" . $clonedCurrId);
            exit;

        case 'delete_curriculum':
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);
            if ($curriculumId <= 0) {
                throw new RuntimeException("Curriculum ID is required.");
            }

            $currStmt = $pdo->prepare("SELECT c.*, p.program_code FROM curriculums c JOIN programs p ON p.id = c.program_id WHERE c.id = :id");
            $currStmt->execute(['id' => $curriculumId]);
            $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                throw new RuntimeException("Curriculum not found.");
            }

            // Check if this is the only curriculum for the program
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM curriculums WHERE program_id = :pid");
            $countStmt->execute(['pid' => $curr['program_id']]);
            $totalForProgram = (int)$countStmt->fetchColumn();

            if ($totalForProgram <= 1) {
                throw new RuntimeException("Cannot delete the only curriculum configured for {$curr['program_code']}. Add an alternate curriculum first.");
            }

            $pdo->beginTransaction();

            // Deleting the curriculum will CASCADE delete rows in curriculum_subjects
            $delStmt = $pdo->prepare("DELETE FROM curriculums WHERE id = :id");
            $delStmt->execute(['id' => $curriculumId]);

            // If deleted curriculum was active, activate the most recent alternate version
            if ((int)$curr['is_active'] === 1) {
                $altStmt = $pdo->prepare("
                    SELECT id FROM curriculums 
                    WHERE program_id = :pid 
                    ORDER BY effective_year DESC, id DESC LIMIT 1
                ");
                $altStmt->execute(['pid' => $curr['program_id']]);
                $altId = $altStmt->fetchColumn();
                if ($altId) {
                    $pdo->prepare("UPDATE curriculums SET is_active = 1 WHERE id = :id")->execute(['id' => $altId]);
                }
            }

            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'delete_curriculum', $curriculumId, "Deleted curriculum '{$curr['curriculum_name']}' (AY {$curr['effective_year']}) for {$curr['program_code']}.");

            $_SESSION['flash_success'] = "Curriculum '{$curr['curriculum_name']}' removed successfully.";
            header("Location: ../admin/curriculum?program=" . urlencode($curr['program_code']));
            exit;

        case 'add_curriculum_subject':
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);
            $yearLevel = trim($_POST['year_level'] ?? '');
            $semester = trim($_POST['semester'] ?? '');
            $subjectMode = trim($_POST['subject_mode'] ?? 'existing');
            $units = (float)($_POST['units'] ?? 3.0);
            $subjectType = trim($_POST['subject_type'] ?? 'General Education');
            $displayOrder = (int)($_POST['display_order'] ?? 1);
            $isRequired = isset($_POST['is_required']) ? 1 : 0;

            if ($curriculumId <= 0 || empty($yearLevel) || empty($semester)) {
                throw new RuntimeException("Curriculum, Year Level, and Semester are required.");
            }

            $currStmt = $pdo->prepare("SELECT c.*, p.program_code FROM curriculums c JOIN programs p ON p.id = c.program_id WHERE c.id = :id");
            $currStmt->execute(['id' => $curriculumId]);
            $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                throw new RuntimeException("Curriculum not found.");
            }

            $pdo->beginTransaction();

            $subjectId = 0;
            if ($subjectMode === 'new') {
                $subjectCode = strtoupper(trim($_POST['subject_code'] ?? ''));
                $subjectName = trim($_POST['subject_name'] ?? '');
                $description = trim($_POST['description'] ?? '') ?: null;

                if (empty($subjectCode) || empty($subjectName)) {
                    throw new RuntimeException("Subject code and name are required for creating a new course.");
                }

                // Check if code exists
                $existCheck = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = :code LIMIT 1");
                $existCheck->execute(['code' => $subjectCode]);
                $existingId = $existCheck->fetchColumn();

                if ($existingId) {
                    $subjectId = (int)$existingId;
                } else {
                    $subIns = $pdo->prepare("
                        INSERT INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name, description, status)
                        VALUES (:pid, :code, :name, :units, :type, :year, :sem, :desc, 'active')
                    ");
                    $subIns->execute([
                        'pid' => $curr['program_id'],
                        'code' => $subjectCode,
                        'name' => $subjectName,
                        'units' => $units,
                        'type' => $subjectType,
                        'year' => $yearLevel,
                        'sem' => $semester,
                        'desc' => $description
                    ]);
                    $subjectId = (int)$pdo->lastInsertId();
                }
            } else {
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                if ($subjectId <= 0) {
                    throw new RuntimeException("Please select a valid subject from the directory.");
                }
            }

            // Check if already mapped in this curriculum for this year and semester
            $dupCheck = $pdo->prepare("
                SELECT id FROM curriculum_subjects 
                WHERE curriculum_id = :curr_id AND subject_id = :subj_id AND year_level = :year AND semester = :sem
                LIMIT 1
            ");
            $dupCheck->execute([
                'curr_id' => $curriculumId,
                'subj_id' => $subjectId,
                'year' => $yearLevel,
                'sem' => $semester
            ]);
            if ($dupCheck->fetch()) {
                throw new RuntimeException("This subject is already assigned to {$yearLevel} - {$semester} in this curriculum.");
            }

            // Determine max display order if not specified
            if ($displayOrder <= 0) {
                $orderStmt = $pdo->prepare("
                    SELECT COALESCE(MAX(display_order), 0) + 1 
                    FROM curriculum_subjects 
                    WHERE curriculum_id = :curr_id AND year_level = :year AND semester = :sem
                ");
                $orderStmt->execute(['curr_id' => $curriculumId, 'year' => $yearLevel, 'sem' => $semester]);
                $displayOrder = (int)$orderStmt->fetchColumn();
            }

            $currSubIns = $pdo->prepare("
                INSERT INTO curriculum_subjects (curriculum_id, subject_id, year_level, semester, units, is_required, display_order, subject_type)
                VALUES (:curr_id, :subj_id, :year, :sem, :units, :req, :ord, :type)
            ");
            $currSubIns->execute([
                'curr_id' => $curriculumId,
                'subj_id' => $subjectId,
                'year' => $yearLevel,
                'sem' => $semester,
                'units' => $units,
                'req' => $isRequired,
                'ord' => $displayOrder,
                'type' => $subjectType
            ]);

            // Optional prerequisites assignment
            $prereqIds = isset($_POST['prerequisite_ids']) && is_array($_POST['prerequisite_ids'])
                ? array_values(array_filter(array_map('intval', $_POST['prerequisite_ids']), fn($id) => $id > 0))
                : [];
            if (!empty($prereqIds)) {
                validatePrerequisiteGraph($pdo, $subjectId, $prereqIds);
                $prereqIns = $pdo->prepare("INSERT IGNORE INTO subject_prerequisites (subject_id, prerequisite_subject_id) VALUES (?, ?)");
                foreach ($prereqIds as $pid) {
                    if ($pid !== $subjectId) {
                        $prereqIns->execute([$subjectId, $pid]);
                    }
                }
            }

            $pdo->commit();

            // Fetch subject code for nice flash
            $codeStmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE id = :id");
            $codeStmt->execute(['id' => $subjectId]);
            $subCode = $codeStmt->fetchColumn() ?: "Subject";

            logCurriculumAudit($pdo, $adminId, 'add_curriculum_subject', $curriculumId, "Added course '{$subCode}' to {$yearLevel} - {$semester} in curriculum '{$curr['curriculum_name']}'.");

            $_SESSION['flash_success'] = "Subject '{$subCode}' added to {$yearLevel} - {$semester}.";
            header("Location: ../admin/curriculum?program=" . urlencode($curr['program_code']) . "&curriculum_id=" . $curriculumId);
            exit;

        case 'update_curriculum_subject':
            $curriculumSubjectId = (int)($_POST['curriculum_subject_id'] ?? 0);
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);
            $yearLevel = trim($_POST['year_level'] ?? '');
            $semester = trim($_POST['semester'] ?? '');
            $units = (float)($_POST['units'] ?? 3.0);
            $subjectType = trim($_POST['subject_type'] ?? 'General Education');
            $displayOrder = (int)($_POST['display_order'] ?? 1);
            $isRequired = isset($_POST['is_required']) ? 1 : 0;

            if ($curriculumSubjectId <= 0 || $curriculumId <= 0) {
                throw new RuntimeException("Curriculum subject reference is required.");
            }

            $subCheck = $pdo->prepare("
                SELECT cs.*, s.subject_code, c.program_id, p.program_code, c.curriculum_name
                FROM curriculum_subjects cs
                JOIN subjects s ON s.id = cs.subject_id
                JOIN curriculums c ON c.id = cs.curriculum_id
                JOIN programs p ON p.id = c.program_id
                WHERE cs.id = :id AND cs.curriculum_id = :curr_id
            ");
            $subCheck->execute(['id' => $curriculumSubjectId, 'curr_id' => $curriculumId]);
            $csRow = $subCheck->fetch(PDO::FETCH_ASSOC);
            if (!$csRow) {
                throw new RuntimeException("Subject mapping not found in this curriculum.");
            }

            $pdo->beginTransaction();

            $updStmt = $pdo->prepare("
                UPDATE curriculum_subjects
                SET year_level = :year, semester = :sem, units = :units, subject_type = :type, display_order = :ord, is_required = :req
                WHERE id = :id
            ");
            $updStmt->execute([
                'year' => $yearLevel,
                'sem' => $semester,
                'units' => $units,
                'type' => $subjectType,
                'ord' => $displayOrder,
                'req' => $isRequired,
                'id' => $curriculumSubjectId
            ]);

            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'update_curriculum_subject', $curriculumId, "Updated subject '{$csRow['subject_code']}' in curriculum '{$csRow['curriculum_name']}' ({$yearLevel} - {$semester}, {$units} units).");

            $_SESSION['flash_success'] = "Updated subject '{$csRow['subject_code']}' configuration.";
            header("Location: ../admin/curriculum?program=" . urlencode($csRow['program_code']) . "&curriculum_id=" . $curriculumId);
            exit;

        case 'remove_curriculum_subject':
            $curriculumSubjectId = (int)($_POST['curriculum_subject_id'] ?? 0);
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);

            if ($curriculumSubjectId <= 0 || $curriculumId <= 0) {
                throw new RuntimeException("Invalid subject removal parameters.");
            }

            $subCheck = $pdo->prepare("
                SELECT cs.*, s.subject_code, p.program_code, c.curriculum_name
                FROM curriculum_subjects cs
                JOIN subjects s ON s.id = cs.subject_id
                JOIN curriculums c ON c.id = cs.curriculum_id
                JOIN programs p ON p.id = c.program_id
                WHERE cs.id = :id AND cs.curriculum_id = :curr_id
            ");
            $subCheck->execute(['id' => $curriculumSubjectId, 'curr_id' => $curriculumId]);
            $csRow = $subCheck->fetch(PDO::FETCH_ASSOC);
            if (!$csRow) {
                throw new RuntimeException("Curriculum subject entry not found.");
            }

            $pdo->beginTransaction();
            $delStmt = $pdo->prepare("DELETE FROM curriculum_subjects WHERE id = :id AND curriculum_id = :curr_id");
            $delStmt->execute(['id' => $curriculumSubjectId, 'curr_id' => $curriculumId]);
            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'remove_curriculum_subject', $curriculumId, "Removed subject '{$csRow['subject_code']}' from curriculum '{$csRow['curriculum_name']}'.");

            $_SESSION['flash_success'] = "Subject '{$csRow['subject_code']}' unlinked from curriculum.";
            header("Location: ../admin/curriculum?program=" . urlencode($csRow['program_code']) . "&curriculum_id=" . $curriculumId);
            exit;

        case 'manage_prerequisites':
            $curriculumId = (int)($_POST['curriculum_id'] ?? 0);
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $prereqIds = isset($_POST['prerequisite_ids']) && is_array($_POST['prerequisite_ids'])
                ? array_values(array_filter(array_map('intval', $_POST['prerequisite_ids']), fn($id) => $id > 0))
                : [];

            if ($subjectId <= 0 || $curriculumId <= 0) {
                throw new RuntimeException("Subject and curriculum references are required.");
            }

            $currStmt = $pdo->prepare("SELECT c.*, p.program_code FROM curriculums c JOIN programs p ON p.id = c.program_id WHERE c.id = :id");
            $currStmt->execute(['id' => $curriculumId]);
            $curr = $currStmt->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                throw new RuntimeException("Curriculum not found.");
            }

            $subStmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE id = :id");
            $subStmt->execute(['id' => $subjectId]);
            $subjectCode = $subStmt->fetchColumn() ?: "Subject";

            if (!empty($prereqIds)) {
                validatePrerequisiteGraph($pdo, $subjectId, $prereqIds);
            }

            $pdo->beginTransaction();

            // Clear old prerequisites for this subject
            $pdo->prepare("DELETE FROM subject_prerequisites WHERE subject_id = :id")->execute(['id' => $subjectId]);

            // Insert new prerequisites
            $insPrereq = $pdo->prepare("INSERT INTO subject_prerequisites (subject_id, prerequisite_subject_id) VALUES (?, ?)");
            foreach (array_unique($prereqIds) as $pid) {
                if ($pid !== $subjectId) {
                    $insPrereq->execute([$subjectId, $pid]);
                }
            }

            $pdo->commit();

            logCurriculumAudit($pdo, $adminId, 'manage_prerequisites', $curriculumId, "Updated prerequisites for '{$subjectCode}' (" . count($prereqIds) . " requirements assigned).");

            $_SESSION['flash_success'] = "Prerequisites updated for '{$subjectCode}'.";
            header("Location: ../admin/curriculum?program=" . urlencode($curr['program_code']) . "&curriculum_id=" . $curriculumId);
            exit;

        default:
            throw new RuntimeException("Invalid action requested.");
    }
} catch (\RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_error'] = $e->getMessage();
    $prog = isset($_POST['program_code']) ? trim($_POST['program_code']) : 'BSMT';
    $currId = isset($_POST['curriculum_id']) ? (int)$_POST['curriculum_id'] : 0;
    header("Location: ../admin/curriculum?program=" . urlencode($prog) . ($currId > 0 ? "&curriculum_id=" . $currId : ""));
    exit;
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Admin curriculum action unexpected failure: " . $e->getMessage());
    $_SESSION['flash_error'] = "An unexpected error occurred while processing the curriculum. Please try again.";
    $prog = isset($_POST['program_code']) ? trim($_POST['program_code']) : 'BSMT';
    $currId = isset($_POST['curriculum_id']) ? (int)$_POST['curriculum_id'] : 0;
    header("Location: ../admin/curriculum?program=" . urlencode($prog) . ($currId > 0 ? "&curriculum_id=" . $currId : ""));
    exit;
}
