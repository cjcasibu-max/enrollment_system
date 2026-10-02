<?php
/**
 * Course Actions Processor
 * Handles CRUD requests for courses from the registrar/admin dashboard.
 * Secured with checkRole() and prepared statements.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

function validatePrerequisiteGraph(PDO $pdo, int $courseId, array $prerequisiteIds): void
{
    $adjacency = [];
    $rowStmt = $pdo->query('SELECT course_id, prerequisite_course_id FROM course_prerequisites');
    while ($row = $rowStmt->fetch(PDO::FETCH_ASSOC)) {
        $sourceId = (int)($row['course_id'] ?? 0);
        $targetId = (int)($row['prerequisite_course_id'] ?? 0);
        if ($sourceId > 0 && $targetId > 0) {
            $adjacency[$sourceId][] = $targetId;
        }
    }

    $adjacency[$courseId] = array_values(array_unique(array_merge($adjacency[$courseId] ?? [], array_map('intval', $prerequisiteIds))));

    $visited = [];
    $activePath = [];
    $visit = function (int $node) use (&$visit, $adjacency, &$visited, &$activePath, $courseId): bool {
        if (isset($activePath[$node])) {
            return true;
        }

        if (isset($visited[$node])) {
            return false;
        }

        $visited[$node] = true;
        $activePath[$node] = true;

        foreach ($adjacency[$node] ?? [] as $nextNode) {
            if ((int)$nextNode === $courseId) {
                return true;
            }

            if ($visit((int)$nextNode)) {
                return true;
            }
        }

        unset($activePath[$node]);
        return false;
    };

    if ($visit($courseId)) {
        throw new RuntimeException('This prerequisite configuration creates a circular dependency.');
    }
}

function savePrerequisites(PDO $pdo, int $courseId, array $prerequisiteIds): void
{
    // Filter: remove self-references and invalid IDs. Caller manages the transaction.
    $prerequisiteIds = array_values(array_unique(array_filter(array_map('intval', $prerequisiteIds), static fn(int $id): bool => $id > 0 && $id !== $courseId)));
    validatePrerequisiteGraph($pdo, $courseId, $prerequisiteIds);
    $deleteStmt = $pdo->prepare('DELETE FROM course_prerequisites WHERE course_id = :course_id');
    $deleteStmt->execute(['course_id' => $courseId]);
    if ($prerequisiteIds) {
        $insertStmt = $pdo->prepare('INSERT INTO course_prerequisites (course_id, prerequisite_course_id) VALUES (:course_id, :prerequisite_course_id)');
        foreach ($prerequisiteIds as $prerequisiteId) {
            $insertStmt->execute(['course_id' => $courseId, 'prerequisite_course_id' => $prerequisiteId]);
        }
    }
}

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

switch ($action) {
    case 'create':
        $courseCode = isset($_POST['course_code']) ? strtoupper(trim($_POST['course_code'])) : '';
        $courseName = isset($_POST['course_name']) ? trim($_POST['course_name']) : '';
        $units = isset($_POST['units']) ? (int)$_POST['units'] : 3;
        $prerequisiteIds = isset($_POST['prerequisite_course_ids']) && is_array($_POST['prerequisite_course_ids']) ? array_values(array_unique(array_filter(array_map('intval', $_POST['prerequisite_course_ids']), fn($id) => $id > 0))) : [];

        if (empty($courseCode) || empty($courseName) || $units < 1 || $units > 10) {
            $_SESSION['flash_error'] = "All fields are required. Units must be between 1 and 10.";
            header("Location: ../registrar/subjects");
            exit;
        }

        try {
            // Check for duplicate course code
            $checkStmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = :code LIMIT 1");
            $checkStmt->execute(['code' => $courseCode]);
            if ($checkStmt->fetch()) {
                $_SESSION['flash_error'] = "Course code '{$courseCode}' is already registered.";
                header("Location: ../registrar/subjects");
                exit;
            }

            // Wrap insert + prerequisites in a single atomic transaction
            $pdo->beginTransaction();
            $insertStmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, units) VALUES (:code, :name, :units)");
            $insertStmt->execute([
                'code' => $courseCode,
                'name' => $courseName,
                'units' => $units
            ]);
            $courseId = (int)$pdo->lastInsertId();
            savePrerequisites($pdo, $courseId, $prerequisiteIds);
            $pdo->commit();

            $_SESSION['flash_success'] = "Course '{$courseCode}' created successfully.";
            header("Location: ../registrar/subjects");
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Create course failed: " . $e->getMessage());
            $_SESSION['flash_error'] = $e instanceof \RuntimeException ? $e->getMessage() : "Database error: Failed to create course.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    case 'update':
        $courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
        $courseCode = isset($_POST['course_code']) ? strtoupper(trim($_POST['course_code'])) : '';
        $courseName = isset($_POST['course_name']) ? trim($_POST['course_name']) : '';
        $units = isset($_POST['units']) ? (int)$_POST['units'] : 3;
        $prerequisiteIds = isset($_POST['prerequisite_course_ids']) && is_array($_POST['prerequisite_course_ids']) ? array_values(array_unique(array_filter(array_map('intval', $_POST['prerequisite_course_ids']), fn($id) => $id > 0))) : [];

        if (empty($courseId) || empty($courseCode) || empty($courseName) || $units < 1 || $units > 10) {
            $_SESSION['flash_error'] = "All fields are required. Units must be between 1 and 10.";
            header("Location: ../registrar/subjects");
            exit;
        }

        try {
            // Check for duplicate course code in OTHER rows
            $checkStmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = :code AND id != :id LIMIT 1");
            $checkStmt->execute(['code' => $courseCode, 'id' => $courseId]);
            if ($checkStmt->fetch()) {
                $_SESSION['flash_error'] = "Course code '{$courseCode}' is already used by another course.";
                header("Location: ../registrar/subjects");
                exit;
            }

            // Wrap update + prerequisites in a single atomic transaction
            $pdo->beginTransaction();
            $updateStmt = $pdo->prepare("UPDATE courses SET course_code = :code, course_name = :name, units = :units WHERE id = :id");
            $updateStmt->execute([
                'code' => $courseCode,
                'name' => $courseName,
                'units' => $units,
                'id' => $courseId
            ]);
            savePrerequisites($pdo, $courseId, $prerequisiteIds);
            $pdo->commit();

            $_SESSION['flash_success'] = "Course details updated successfully.";
            header("Location: ../registrar/subjects");
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Update course failed: " . $e->getMessage());
            $_SESSION['flash_error'] = $e instanceof \RuntimeException ? $e->getMessage() : "Database error: Failed to update course.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    case 'delete':
        $courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;

        if (empty($courseId)) {
            $_SESSION['flash_error'] = "Course ID is required to delete.";
            header("Location: ../registrar/subjects");
            exit;
        }

        try {
            // Block delete if sections (and therefore enrollments) reference this course
            $sectionCountStmt = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE course_id = :course_id");
            $sectionCountStmt->execute(['course_id' => $courseId]);
            $sectionCount = (int)$sectionCountStmt->fetchColumn();
            if ($sectionCount > 0) {
                $_SESSION['flash_error'] = "Cannot delete this course: it has {$sectionCount} section(s) with student records. Remove all related sections first."; 
                header("Location: ../registrar/subjects");
                exit;
            }

            // Delete course
            $deleteStmt = $pdo->prepare("DELETE FROM courses WHERE id = :id");
            $deleteStmt->execute(['id' => $courseId]);

            $_SESSION['flash_success'] = "Course deleted successfully.";
            header("Location: ../registrar/subjects");
            exit;

        } catch (\PDOException $e) {
            error_log("Delete course failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to delete course.";
            header("Location: ../registrar/subjects");
            exit;
        }
        break;

    default:
        $_SESSION['flash_error'] = "Invalid course action specified.";
        header("Location: ../registrar/subjects");
        exit;
}
