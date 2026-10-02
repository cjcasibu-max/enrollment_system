<?php
/**
 * Teacher LMS — Module & Lesson Action Handler (Phase 3)
 *
 * Actions handled (POST field: action):
 *   Module CRUD:
 *     create_module  — create a new module for a section-subject
 *     edit_module    — update module title/description/published status
 *     delete_module  — delete module (cascades to lessons + progress)
 *     reorder_modules — update display_order for multiple modules at once
 *
 *   Lesson CRUD:
 *     create_lesson  — create a lesson inside a module (with optional file upload)
 *     edit_lesson    — update lesson fields (with optional file replacement)
 *     delete_lesson  — delete lesson and its stored file
 *     reorder_lessons — update display_order for multiple lessons in a module
 *
 * Security:
 *   - POST-only; 405 on any other method.
 *   - CSRF token validated on every action.
 *   - Teacher ownership re-verified on every write via verifyTeacherOwnsSubject().
 *   - File uploads: MIME-validated, stored in private_uploads/lms_lessons/ with
 *     a random hex filename and .htaccess protection; original name stored in DB.
 *   - All DB changes use PDO prepared statements; mutations are in transactions.
 *
 * Data source:
 *   Writes to lms_modules and lms_lessons — the EXACT tables the student side
 *   reads from (no cache, no duplicate structure).
 *
 * Flash + redirect:
 *   On success → $_SESSION['flash_success'] + Location header
 *   On failure → $_SESSION['flash_error']  + Location header
 */

require_once '../includes/lms_access.php';

$teacher = requireLmsTeacherAccess();
$userId  = $teacher['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$action           = trim((string)($_POST['action'] ?? ''));
$sectionSubjectId = (int)filter_input(INPUT_POST, 'section_subject_id', FILTER_VALIDATE_INT);
$returnBase       = $sectionSubjectId > 0
    ? resolveAppUrl('teacher/lms_subject?section_subject_id=' . $sectionSubjectId . '&section=modules')
    : resolveAppUrl('teacher/lms');

// ── CSRF check (common to all actions) ────────────────────────────────────────
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Your session token expired. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}

// ── Ownership check (common to all actions) ───────────────────────────────────
if ($sectionSubjectId <= 0) {
    $_SESSION['flash_error'] = 'Invalid subject.';
    header('Location: ' . resolveAppUrl('teacher/lms'));
    exit;
}

$subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
if (!$subject) {
    $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
    header('Location: ' . resolveAppUrl('teacher/lms'));
    exit;
}

// ── Private lesson file storage helpers ──────────────────────────────────────

/**
 * Return the validated, writable realpath for the lesson file storage root,
 * creating it (and its .htaccess guard) if needed.
 * Throws RuntimeException on failure.
 */
function getLessonStorageRoot(): string
{
    $dir = __DIR__ . '/../private_uploads/lms_lessons';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Lesson file storage is unavailable.');
    }
    $root = realpath($dir);
    if (!$root || !is_writable($root)) {
        throw new RuntimeException('Lesson file storage is unavailable.');
    }
    $htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
        );
    }
    return $root;
}

/**
 * Accepted lesson file MIME types per content_type.
 * Returns [mimeType => extension] for a given content_type, or [] for
 * types that don't use uploaded files (text, external_link).
 */
function lessonAllowedMimeMap(string $contentType): array
{
    return match ($contentType) {
        'image'        => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ],
        'pdf'          => [
            'application/pdf' => 'pdf',
        ],
        'presentation' => [
            'application/pdf'                                                        => 'pdf',
            'application/vnd.ms-powerpoint'                                         => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ],
        'video'        => [
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm',
            'video/ogg'  => 'ogv',
        ],
        default        => [],   // text, external_link — no file upload
    };
}

/**
 * Save an uploaded lesson file to private storage.
 * Returns the relative path for DB storage (e.g. "abc123.mp4").
 * Throws DomainException on validation errors, RuntimeException on I/O errors.
 */
function saveLessonFile(array $file, string $contentType): string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('Upload failed or no file was provided.');
    }
    $maxBytes = 100 * 1024 * 1024; // 100 MB limit for lesson content
    if ((int)$file['size'] < 1 || (int)$file['size'] > $maxBytes) {
        throw new DomainException('The file must be between 1 byte and 100 MB.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new DomainException('The uploaded file could not be verified. Please try again.');
    }
    $mimeMap  = lessonAllowedMimeMap($contentType);
    if (empty($mimeMap)) {
        throw new DomainException('This lesson type does not support file uploads.');
    }
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file((string)$file['tmp_name']);
    if (!isset($mimeMap[$mimeType])) {
        throw new DomainException('Unsupported file type for this lesson content type.');
    }
    $ext      = $mimeMap[$mimeType];
    $root     = getLessonStorageRoot();
    $stored   = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest     = $root . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        throw new RuntimeException('The file could not be saved. Please try again.');
    }
    return $stored; // stored as-is (no subdirectory needed — already in private root)
}

/**
 * Delete a lesson's old file from private storage (best-effort; no throw on fail).
 */
function deleteLessonFile(?string $relativePath): void
{
    if ($relativePath === null || trim($relativePath) === '') {
        return;
    }
    $root = realpath(__DIR__ . '/../private_uploads/lms_lessons');
    if (!$root) {
        return;
    }
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
        return;
    }
    $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($full && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full)) {
        @unlink($full);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$newFilePath = null; // track so we can clean up on DB failure

try {
    switch ($action) {

        // ────────────────────────────────────────────────────────────────────
        // MODULE ACTIONS
        // ────────────────────────────────────────────────────────────────────

        case 'create_module': {
            $title       = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;

            if ($title === '' || mb_strlen($title) > 150) {
                throw new DomainException('Module title is required and must be 150 characters or fewer.');
            }

            // Set display_order to max + 1 for this section_subject
            $orderStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(display_order), -1) + 1
                 FROM lms_modules WHERE section_subject_id = :ss'
            );
            $orderStmt->execute(['ss' => $sectionSubjectId]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO lms_modules (section_subject_id, title, description, display_order, is_published)
                 VALUES (:ss, :title, :description, :display_order, :is_published)'
            );
            $stmt->execute([
                'ss'            => $sectionSubjectId,
                'title'         => $title,
                'description'   => $description !== '' ? $description : null,
                'display_order' => $nextOrder,
                'is_published'  => $isPublished,
            ]);
            $_SESSION['flash_success'] = 'Module "' . htmlspecialchars($title) . '" created.';
            break;
        }

        case 'edit_module': {
            $moduleId    = (int)filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
            $title       = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;

            if ($moduleId <= 0) {
                throw new DomainException('Invalid module.');
            }
            if ($title === '' || mb_strlen($title) > 150) {
                throw new DomainException('Module title is required and must be 150 characters or fewer.');
            }

            // Confirm the module belongs to this section_subject (ownership)
            $checkStmt = $pdo->prepare(
                'SELECT id FROM lms_modules WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $checkStmt->execute(['id' => $moduleId, 'ss' => $sectionSubjectId]);
            if (!$checkStmt->fetch()) {
                throw new DomainException('Module not found or access denied.');
            }

            $stmt = $pdo->prepare(
                'UPDATE lms_modules
                 SET title = :title, description = :description, is_published = :is_published
                 WHERE id = :id AND section_subject_id = :ss'
            );
            $stmt->execute([
                'title'        => $title,
                'description'  => $description !== '' ? $description : null,
                'is_published' => $isPublished,
                'id'           => $moduleId,
                'ss'           => $sectionSubjectId,
            ]);
            $_SESSION['flash_success'] = 'Module updated.';
            break;
        }

        case 'delete_module': {
            $moduleId = (int)filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
            if ($moduleId <= 0) {
                throw new DomainException('Invalid module.');
            }

            // Confirm ownership
            $checkStmt = $pdo->prepare(
                'SELECT id FROM lms_modules WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $checkStmt->execute(['id' => $moduleId, 'ss' => $sectionSubjectId]);
            if (!$checkStmt->fetch()) {
                throw new DomainException('Module not found or access denied.');
            }

            // Collect lesson file paths before cascading delete
            $filesStmt = $pdo->prepare(
                "SELECT content_path FROM lms_lessons WHERE module_id = :mid
                 AND content_type IN ('image','presentation','pdf','video')
                 AND content_path IS NOT NULL AND content_path != ''"
            );
            $filesStmt->execute(['mid' => $moduleId]);
            $lessonFilePaths = $filesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lms_modules WHERE id = :id AND section_subject_id = :ss')
                ->execute(['id' => $moduleId, 'ss' => $sectionSubjectId]);
            $pdo->commit();

            // Best-effort cleanup of orphaned lesson files
            foreach ($lessonFilePaths as $fp) {
                deleteLessonFile($fp);
            }

            $_SESSION['flash_success'] = 'Module and all its lessons deleted.';
            break;
        }

        case 'reorder_modules': {
            // Expects: module_ids[] = ordered array of module IDs for this section_subject
            $moduleIds = array_filter(
                array_map('intval', (array)($_POST['module_ids'] ?? [])),
                fn($id) => $id > 0
            );
            if (empty($moduleIds)) {
                throw new DomainException('Nothing to reorder.');
            }

            // Verify all IDs belong to this section_subject
            $in = implode(',', array_fill(0, count($moduleIds), '?'));
            $ownCheck = $pdo->prepare(
                "SELECT COUNT(*) FROM lms_modules WHERE id IN ($in) AND section_subject_id = ?"
            );
            $ownCheck->execute([...$moduleIds, $sectionSubjectId]);
            if ((int)$ownCheck->fetchColumn() !== count($moduleIds)) {
                throw new DomainException('One or more modules do not belong to this subject.');
            }

            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                'UPDATE lms_modules SET display_order = :ord WHERE id = :id AND section_subject_id = :ss'
            );
            foreach (array_values($moduleIds) as $order => $id) {
                $upd->execute(['ord' => $order, 'id' => $id, 'ss' => $sectionSubjectId]);
            }
            $pdo->commit();

            // AJAX reorder — return JSON instead of redirect
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }

        // ────────────────────────────────────────────────────────────────────
        // LESSON ACTIONS
        // ────────────────────────────────────────────────────────────────────

        case 'create_lesson': {
            $moduleId    = (int)filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
            $title       = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $contentType = trim((string)($_POST['content_type'] ?? 'text'));
            $contentBody = trim((string)($_POST['content_body'] ?? ''));
            $externalUrl = trim((string)($_POST['external_url'] ?? ''));
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;

            $validTypes = ['text', 'image', 'presentation', 'pdf', 'video', 'external_link'];
            if (!in_array($contentType, $validTypes, true)) {
                throw new DomainException('Invalid content type.');
            }
            if ($moduleId <= 0) {
                throw new DomainException('Invalid module.');
            }
            if ($title === '' || mb_strlen($title) > 150) {
                throw new DomainException('Lesson title is required and must be 150 characters or fewer.');
            }

            // Confirm module belongs to this section_subject
            $modCheck = $pdo->prepare(
                'SELECT id FROM lms_modules WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $modCheck->execute(['id' => $moduleId, 'ss' => $sectionSubjectId]);
            if (!$modCheck->fetch()) {
                throw new DomainException('Module not found or access denied.');
            }

            // Handle content by type
            $dbContentBody = null;
            $dbContentPath = null;

            if ($contentType === 'text') {
                $dbContentBody = $contentBody !== '' ? $contentBody : null;
            } elseif ($contentType === 'external_link') {
                if ($externalUrl === '') {
                    throw new DomainException('A URL is required for external link lessons.');
                }
                if (!filter_var($externalUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new DomainException('The external URL must be a valid http:// or https:// address.');
                }
                $dbContentPath = $externalUrl;
            } else {
                // File upload required for image/pdf/presentation/video
                $file = $_FILES['lesson_file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new DomainException('A file is required for this lesson type.');
                }
                $storedFile  = saveLessonFile($file, $contentType);
                $newFilePath = $storedFile;
                $dbContentPath = $storedFile;
            }

            // Next display_order
            $orderStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(display_order), -1) + 1 FROM lms_lessons WHERE module_id = :mid'
            );
            $orderStmt->execute(['mid' => $moduleId]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO lms_lessons
                    (module_id, title, description, content_type, content_body, content_path, display_order, is_published)
                 VALUES
                    (:module_id, :title, :description, :content_type, :content_body, :content_path, :display_order, :is_published)'
            );
            $stmt->execute([
                'module_id'     => $moduleId,
                'title'         => $title,
                'description'   => $description !== '' ? $description : null,
                'content_type'  => $contentType,
                'content_body'  => $dbContentBody,
                'content_path'  => $dbContentPath,
                'display_order' => $nextOrder,
                'is_published'  => $isPublished,
            ]);
            $pdo->commit();
            $newFilePath = null; // committed — don't clean up
            $_SESSION['flash_success'] = 'Lesson "' . htmlspecialchars($title) . '" added.';
            break;
        }

        case 'edit_lesson': {
            $lessonId    = (int)filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT);
            $title       = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $contentType = trim((string)($_POST['content_type'] ?? 'text'));
            $contentBody = trim((string)($_POST['content_body'] ?? ''));
            $externalUrl = trim((string)($_POST['external_url'] ?? ''));
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;

            $validTypes = ['text', 'image', 'presentation', 'pdf', 'video', 'external_link'];
            if (!in_array($contentType, $validTypes, true)) {
                throw new DomainException('Invalid content type.');
            }
            if ($lessonId <= 0) {
                throw new DomainException('Invalid lesson.');
            }
            if ($title === '' || mb_strlen($title) > 150) {
                throw new DomainException('Lesson title is required and must be 150 characters or fewer.');
            }

            // Confirm lesson belongs to a module owned by this section_subject
            $lessonCheck = $pdo->prepare(
                'SELECT l.id, l.content_type, l.content_path
                 FROM lms_lessons l
                 JOIN lms_modules m ON m.id = l.module_id
                 WHERE l.id = :lid AND m.section_subject_id = :ss
                 LIMIT 1'
            );
            $lessonCheck->execute(['lid' => $lessonId, 'ss' => $sectionSubjectId]);
            $existingLesson = $lessonCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existingLesson) {
                throw new DomainException('Lesson not found or access denied.');
            }

            $dbContentBody = null;
            $dbContentPath = null;
            $oldFilePath   = $existingLesson['content_path'];

            if ($contentType === 'text') {
                $dbContentBody = $contentBody !== '' ? $contentBody : null;
                // Discard any previously uploaded file if type changed to text
                if (!in_array($existingLesson['content_type'], ['text', 'external_link'], true)) {
                    deleteLessonFile($oldFilePath);
                    $oldFilePath = null;
                }
            } elseif ($contentType === 'external_link') {
                if ($externalUrl === '') {
                    throw new DomainException('A URL is required for external link lessons.');
                }
                if (!filter_var($externalUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new DomainException('The external URL must be a valid http:// or https:// address.');
                }
                $dbContentPath = $externalUrl;
                // Discard uploaded file if type changed away from file type
                if (!in_array($existingLesson['content_type'], ['text', 'external_link'], true)) {
                    deleteLessonFile($oldFilePath);
                    $oldFilePath = null;
                }
            } else {
                // File type — optional file replacement
                $file = $_FILES['lesson_file'] ?? null;
                $hasNewFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

                if ($hasNewFile) {
                    $storedFile  = saveLessonFile($file, $contentType);
                    $newFilePath = $storedFile;
                    $dbContentPath = $storedFile;
                } else {
                    // Keep existing file path (even if content type changed within file types)
                    $dbContentPath = $existingLesson['content_path'];
                    $oldFilePath   = null; // nothing to delete
                }

                if ($hasNewFile && $oldFilePath !== null && !in_array($existingLesson['content_type'], ['text', 'external_link'], true)) {
                    // Will delete old file AFTER successful commit below
                }
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE lms_lessons
                 SET title = :title, description = :description, content_type = :content_type,
                     content_body = :content_body, content_path = :content_path, is_published = :is_published
                 WHERE id = :id'
            );
            $stmt->execute([
                'title'        => $title,
                'description'  => $description !== '' ? $description : null,
                'content_type' => $contentType,
                'content_body' => $dbContentBody,
                'content_path' => $dbContentPath,
                'is_published' => $isPublished,
                'id'           => $lessonId,
            ]);
            $pdo->commit();

            // Post-commit: delete replaced/discarded file
            if ($newFilePath !== null && $oldFilePath !== null) {
                deleteLessonFile($oldFilePath);
            }
            $newFilePath = null; // committed — no cleanup needed

            $_SESSION['flash_success'] = 'Lesson updated.';
            break;
        }

        case 'delete_lesson': {
            $lessonId = (int)filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT);
            if ($lessonId <= 0) {
                throw new DomainException('Invalid lesson.');
            }

            $lessonCheck = $pdo->prepare(
                'SELECT l.id, l.content_type, l.content_path
                 FROM lms_lessons l
                 JOIN lms_modules m ON m.id = l.module_id
                 WHERE l.id = :lid AND m.section_subject_id = :ss
                 LIMIT 1'
            );
            $lessonCheck->execute(['lid' => $lessonId, 'ss' => $sectionSubjectId]);
            $existingLesson = $lessonCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existingLesson) {
                throw new DomainException('Lesson not found or access denied.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lms_lessons WHERE id = :id')
                ->execute(['id' => $lessonId]);
            $pdo->commit();

            deleteLessonFile($existingLesson['content_path']);
            $_SESSION['flash_success'] = 'Lesson deleted.';
            break;
        }

        case 'reorder_lessons': {
            // Expects: lesson_ids[] = ordered array of lesson IDs within a module
            $moduleId  = (int)filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT);
            $lessonIds = array_filter(
                array_map('intval', (array)($_POST['lesson_ids'] ?? [])),
                fn($id) => $id > 0
            );
            if ($moduleId <= 0 || empty($lessonIds)) {
                throw new DomainException('Nothing to reorder.');
            }

            // Confirm module belongs to this section_subject
            $modCheck = $pdo->prepare(
                'SELECT id FROM lms_modules WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $modCheck->execute(['id' => $moduleId, 'ss' => $sectionSubjectId]);
            if (!$modCheck->fetch()) {
                throw new DomainException('Module not found or access denied.');
            }

            // Confirm all lesson IDs belong to this module
            $in = implode(',', array_fill(0, count($lessonIds), '?'));
            $ownCheck = $pdo->prepare(
                "SELECT COUNT(*) FROM lms_lessons WHERE id IN ($in) AND module_id = ?"
            );
            $ownCheck->execute([...$lessonIds, $moduleId]);
            if ((int)$ownCheck->fetchColumn() !== count($lessonIds)) {
                throw new DomainException('One or more lessons do not belong to this module.');
            }

            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                'UPDATE lms_lessons SET display_order = :ord WHERE id = :id AND module_id = :mid'
            );
            foreach (array_values($lessonIds) as $order => $id) {
                $upd->execute(['ord' => $order, 'id' => $id, 'mid' => $moduleId]);
            }
            $pdo->commit();

            // AJAX reorder — return JSON
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }

        default:
            throw new DomainException('Unknown action.');
    }
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newFilePath !== null) {
        deleteLessonFile($newFilePath);
    }
    $_SESSION['flash_error'] = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newFilePath !== null) {
        deleteLessonFile($newFilePath);
    }
    error_log('LMS module/lesson action failed [' . $action . ']: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'The operation could not be completed. Please try again.';
}

header('Location: ' . $returnBase);
exit;
