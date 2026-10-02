<?php
/**
 * Teacher LMS — Learning Material Action Handler (Phase 4)
 *
 * Actions handled (POST field: action):
 *   upload_material      — upload a new material file + DB insert
 *   edit_material        — update title/description/type/availability
 *                          (optional file replacement)
 *   delete_material      — delete DB row and stored file
 *   reorder_materials    — update display_order for multiple materials (AJAX JSON)
 *   toggle_availability  — flip is_available for one material (AJAX JSON)
 *
 * Security:
 *   - POST-only; 405 on any other method.
 *   - CSRF validated on every action.
 *   - Teacher ownership re-verified on every write via verifyTeacherOwnsSubject().
 *   - Files: MIME-validated, stored in private_uploads/lms_materials/ with
 *     random hex filename; original name saved in file_name column.
 *     .htaccess protection created automatically.
 *   - All DB mutations run inside transactions.
 *   - DomainException (user-facing) / Throwable (server error) caught separately.
 *
 * Data source:
 *   Writes to lms_materials — the EXACT table fetchLmsMaterialsForStudentSubject()
 *   reads from.  is_available = 0 immediately hides from students; = 1 shows.
 *   No cache layer exists; changes are live immediately.
 *
 * Flash + redirect:
 *   Non-AJAX actions → flash + Location header back to returnBase
 *   AJAX actions (reorder, toggle) → JSON {"ok":true} or {"ok":false,"error":"..."}
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
    ? resolveAppUrl('teacher/lms_materials?section_subject_id=' . $sectionSubjectId)
    : resolveAppUrl('teacher/lms_materials');

$isAjax = in_array($action, ['reorder_materials', 'toggle_availability'], true);

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Your session token expired. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}

// ── Ownership ─────────────────────────────────────────────────────────────────
if ($sectionSubjectId <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid subject.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Invalid subject.';
    header('Location: ' . resolveAppUrl('teacher/lms_materials'));
    exit;
}
$subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
if (!$subject) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Access denied.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
    header('Location: ' . resolveAppUrl('teacher/lms'));
    exit;
}

// ── Storage helpers ───────────────────────────────────────────────────────────

function getMaterialStorageRoot(): string
{
    $dir = __DIR__ . '/../private_uploads/lms_materials';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Material file storage is unavailable.');
    }
    $root = realpath($dir);
    if (!$root || !is_writable($root)) {
        throw new RuntimeException('Material file storage is unavailable.');
    }
    $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        file_put_contents(
            $ht,
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
 * MIME → [extension, material_type] map.
 * material_type maps to lms_materials.material_type ENUM.
 */
function materialMimeMap(): array
{
    return [
        // PDF
        'application/pdf' => ['pdf', 'pdf'],
        // Presentation
        'application/vnd.ms-powerpoint' => ['ppt', 'presentation'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'presentation'],
        // Document
        'application/msword' => ['doc', 'document'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'document'],
        'application/vnd.ms-excel' => ['xls', 'document'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'document'],
        'text/plain' => ['txt', 'document'],
        'text/csv'   => ['csv', 'document'],
        'application/rtf' => ['rtf', 'document'],
        'text/rtf'   => ['rtf', 'document'],
        // Image
        'image/jpeg' => ['jpg', 'image'],
        'image/png'  => ['png', 'image'],
        'image/gif'  => ['gif', 'image'],
        'image/webp' => ['webp', 'image'],
        // Video
        'video/mp4'  => ['mp4', 'video'],
        'video/webm' => ['webm', 'video'],
        'video/ogg'  => ['ogv', 'video'],
        // Other (zip, archive, etc.)
        'application/zip' => ['zip', 'other'],
        'application/x-zip-compressed' => ['zip', 'other'],
        'application/x-rar-compressed' => ['rar', 'other'],
        'application/octet-stream'     => ['bin', 'other'],
    ];
}

/**
 * Validate and store a material upload.
 * Returns ['file_name', 'file_path', 'material_type'].
 */
function saveMaterialFile(array $file, ?string $overrideType = null): array
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('Upload failed or no file was chosen.');
    }
    $maxBytes = 200 * 1024 * 1024; // 200 MB
    if ((int)$file['size'] < 1 || (int)$file['size'] > $maxBytes) {
        throw new DomainException('File must be between 1 byte and 200 MB.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new DomainException('The uploaded file could not be verified. Please try again.');
    }
    $mimeMap  = materialMimeMap();
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file((string)$file['tmp_name']);
    if (!isset($mimeMap[$mimeType])) {
        throw new DomainException(
            'Unsupported file type (' . htmlspecialchars($mimeType) . '). '
            . 'Upload a PDF, Office document, presentation, image, video, or ZIP.'
        );
    }
    [$ext, $detectedType] = $mimeMap[$mimeType];
    $materialType = $overrideType ?: $detectedType;
    // Validate that override is a permitted ENUM value
    $validTypes = ['pdf', 'presentation', 'document', 'image', 'video', 'other'];
    if (!in_array($materialType, $validTypes, true)) {
        $materialType = $detectedType;
    }

    $originalName = basename(str_replace('\\', '/', (string)$file['name']));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/', '_', $originalName) ?: 'material';
    $originalName = substr($originalName, 0, 255);

    $root      = getMaterialStorageRoot();
    $storedFile = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest       = $root . DIRECTORY_SEPARATOR . $storedFile;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        throw new RuntimeException('The file could not be saved. Please try again.');
    }
    return [
        'file_name'     => $originalName,
        'file_path'     => $storedFile,
        'material_type' => $materialType,
    ];
}

function deleteMaterialFile(?string $filePath): void
{
    if ($filePath === null || trim($filePath) === '') return;
    $root = realpath(__DIR__ . '/../private_uploads/lms_materials');
    if (!$root) return;
    $filePath = str_replace('\\', '/', trim($filePath));
    if ($filePath === '' || str_starts_with($filePath, '/') || str_contains($filePath, '..')) return;
    $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filePath));
    if ($full && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full)) {
        @unlink($full);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$newFilePath = null; // track for cleanup on DB failure

try {
    switch ($action) {

        // ── upload_material ───────────────────────────────────────────────
        case 'upload_material': {
            $title        = trim((string)($_POST['title'] ?? ''));
            $description  = trim((string)($_POST['description'] ?? ''));
            $manualType   = trim((string)($_POST['material_type'] ?? ''));
            $isAvailable  = !empty($_POST['is_available']) ? 1 : 0;

            if ($title === '' || mb_strlen($title) > 150) {
                throw new DomainException('Title is required and must be 150 characters or fewer.');
            }
            $file = $_FILES['material_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new DomainException('Please choose a file to upload.');
            }
            $saved       = saveMaterialFile($file, $manualType ?: null);
            $newFilePath = $saved['file_path'];

            $orderStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(display_order), -1) + 1
                 FROM lms_materials WHERE section_subject_id = :ss'
            );
            $orderStmt->execute(['ss' => $sectionSubjectId]);
            $nextOrder = (int)$orderStmt->fetchColumn();

            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO lms_materials
                    (section_subject_id, title, description, material_type, file_name, file_path, display_order, is_available)
                 VALUES
                    (:ss, :title, :description, :material_type, :file_name, :file_path, :display_order, :is_available)'
            )->execute([
                'ss'            => $sectionSubjectId,
                'title'         => $title,
                'description'   => $description !== '' ? $description : null,
                'material_type' => $saved['material_type'],
                'file_name'     => $saved['file_name'],
                'file_path'     => $saved['file_path'],
                'display_order' => $nextOrder,
                'is_available'  => $isAvailable,
            ]);
            $pdo->commit();
            $newFilePath = null;
            $_SESSION['flash_success'] = '"' . htmlspecialchars($title) . '" uploaded successfully.';
            break;
        }

        // ── edit_material ─────────────────────────────────────────────────
        case 'edit_material': {
            $materialId  = (int)filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            $title       = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $manualType  = trim((string)($_POST['material_type'] ?? ''));
            $isAvailable = !empty($_POST['is_available']) ? 1 : 0;

            if ($materialId <= 0) {
                throw new DomainException('Invalid material.');
            }
            if ($title === '' || mb_strlen($title) > 150) {
                throw new DomainException('Title is required and must be 150 characters or fewer.');
            }
            $validTypes = ['pdf', 'presentation', 'document', 'image', 'video', 'other'];
            if (!in_array($manualType, $validTypes, true)) {
                throw new DomainException('Invalid material type selected.');
            }

            // Confirm ownership
            $check = $pdo->prepare(
                'SELECT id, file_path FROM lms_materials
                 WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $check->execute(['id' => $materialId, 'ss' => $sectionSubjectId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new DomainException('Material not found or access denied.');
            }

            // Optional file replacement
            $file    = $_FILES['material_file'] ?? null;
            $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $newFileName = null;
            $newFilePathVal = null;
            $newType = $manualType;

            if ($hasFile) {
                $saved        = saveMaterialFile($file, $manualType ?: null);
                $newFilePath  = $saved['file_path'];
                $newFileName  = $saved['file_name'];
                $newFilePathVal = $saved['file_path'];
                $newType      = $saved['material_type'];
            }

            $pdo->beginTransaction();
            if ($hasFile) {
                $pdo->prepare(
                    'UPDATE lms_materials
                     SET title = :title, description = :description, material_type = :material_type,
                         file_name = :file_name, file_path = :file_path, is_available = :is_available
                     WHERE id = :id AND section_subject_id = :ss'
                )->execute([
                    'title'         => $title,
                    'description'   => $description !== '' ? $description : null,
                    'material_type' => $newType,
                    'file_name'     => $newFileName,
                    'file_path'     => $newFilePathVal,
                    'is_available'  => $isAvailable,
                    'id'            => $materialId,
                    'ss'            => $sectionSubjectId,
                ]);
            } else {
                $pdo->prepare(
                    'UPDATE lms_materials
                     SET title = :title, description = :description,
                         material_type = :material_type, is_available = :is_available
                     WHERE id = :id AND section_subject_id = :ss'
                )->execute([
                    'title'         => $title,
                    'description'   => $description !== '' ? $description : null,
                    'material_type' => $manualType,
                    'is_available'  => $isAvailable,
                    'id'            => $materialId,
                    'ss'            => $sectionSubjectId,
                ]);
            }
            $pdo->commit();

            // Delete old file after successful commit
            if ($hasFile && $existing['file_path']) {
                deleteMaterialFile($existing['file_path']);
            }
            $newFilePath = null;
            $_SESSION['flash_success'] = 'Material updated.';
            break;
        }

        // ── delete_material ───────────────────────────────────────────────
        case 'delete_material': {
            $materialId = (int)filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            if ($materialId <= 0) {
                throw new DomainException('Invalid material.');
            }
            $check = $pdo->prepare(
                'SELECT id, file_path FROM lms_materials
                 WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $check->execute(['id' => $materialId, 'ss' => $sectionSubjectId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new DomainException('Material not found or access denied.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lms_materials WHERE id = :id AND section_subject_id = :ss')
                ->execute(['id' => $materialId, 'ss' => $sectionSubjectId]);
            $pdo->commit();

            deleteMaterialFile($existing['file_path']);
            $_SESSION['flash_success'] = 'Material deleted.';
            break;
        }

        // ── reorder_materials (AJAX) ──────────────────────────────────────
        case 'reorder_materials': {
            $ids = array_filter(
                array_map('intval', (array)($_POST['material_ids'] ?? [])),
                fn($id) => $id > 0
            );
            if (empty($ids)) {
                throw new DomainException('Nothing to reorder.');
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ownCheck = $pdo->prepare(
                "SELECT COUNT(*) FROM lms_materials WHERE id IN ($in) AND section_subject_id = ?"
            );
            $ownCheck->execute([...$ids, $sectionSubjectId]);
            if ((int)$ownCheck->fetchColumn() !== count($ids)) {
                throw new DomainException('One or more materials do not belong to this subject.');
            }
            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                'UPDATE lms_materials SET display_order = :ord WHERE id = :id AND section_subject_id = :ss'
            );
            foreach (array_values($ids) as $order => $id) {
                $upd->execute(['ord' => $order, 'id' => $id, 'ss' => $sectionSubjectId]);
            }
            $pdo->commit();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── toggle_availability (AJAX) ────────────────────────────────────
        case 'toggle_availability': {
            $materialId = (int)filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            if ($materialId <= 0) {
                throw new DomainException('Invalid material.');
            }
            $check = $pdo->prepare(
                'SELECT id, is_available FROM lms_materials
                 WHERE id = :id AND section_subject_id = :ss LIMIT 1'
            );
            $check->execute(['id' => $materialId, 'ss' => $sectionSubjectId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new DomainException('Material not found or access denied.');
            }
            $newAvail = (int)$existing['is_available'] === 1 ? 0 : 1;
            $pdo->prepare(
                'UPDATE lms_materials SET is_available = :avail WHERE id = :id AND section_subject_id = :ss'
            )->execute(['avail' => $newAvail, 'id' => $materialId, 'ss' => $sectionSubjectId]);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'is_available' => $newAvail]);
            exit;
        }

        default:
            throw new DomainException('Unknown action.');
    }
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($newFilePath !== null) deleteMaterialFile($newFilePath);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $_SESSION['flash_error'] = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($newFilePath !== null) deleteMaterialFile($newFilePath);
    error_log('LMS material action failed [' . $action . ']: ' . $e->getMessage());
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'The operation could not be completed. Please try again.']);
        exit;
    }
    $_SESSION['flash_error'] = 'The operation could not be completed. Please try again.';
}

header('Location: ' . $returnBase);
exit;
