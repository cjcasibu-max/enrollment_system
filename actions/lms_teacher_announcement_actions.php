<?php
/**
 * Teacher LMS - Announcement Action Handler (Phase 8)
 *
 * Actions handled:
 *   - create_announcement : Create announcement for a specific section_subject
 *   - edit_announcement   : Update title, message, publication date, publish status, importance
 *   - delete_announcement : Delete announcement row
 *   - toggle_publish      : Flip published status (supports standard POST and AJAX)
 *
 * Security:
 *   - POST-only request validation.
 *   - CSRF token validation.
 *   - Role gate via requireLmsTeacherAccess().
 *   - Subject assignment verified via verifyTeacherOwnsSubject().
 *   - Record ownership verified by scoping to section_subject_id.
 *
 * Data flow:
 *   - Writes to lms_announcements table.
 *   - Immediately visible to enrolled students via fetchLmsAnnouncementsForStudentSubject().
 *   - On publication (is_published = 1 AND published_at <= NOW()), triggers
 *     notifyLmsAnnouncementRecipients() to send in-app notifications.
 */

require_once '../includes/lms_access.php';

$teacher = requireLmsTeacherAccess();
$userId  = (int)$teacher['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$action           = trim((string)($_POST['action'] ?? ''));
$sectionSubjectId = (int)filter_input(INPUT_POST, 'section_subject_id', FILTER_VALIDATE_INT);
$announcementId   = (int)filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);

$isAjax = ($action === 'toggle_publish' && (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
));

$returnBase = $sectionSubjectId > 0
    ? resolveAppUrl('teacher/lms_announcements?section_subject_id=' . $sectionSubjectId)
    : resolveAppUrl('teacher/lms_announcements');

// ── CSRF Check ────────────────────────────────────────────────────────────────
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Session token expired. Please refresh the page and try again.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}

// ── Subject Ownership Check ───────────────────────────────────────────────────
if ($sectionSubjectId <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid subject selected.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Please select a valid subject.';
    header('Location: ' . resolveAppUrl('teacher/lms_announcements'));
    exit;
}

$subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
if (!$subject) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Access denied: you are not assigned to this subject.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Access denied: you are not assigned to this subject.';
    header('Location: ' . resolveAppUrl('teacher/lms_announcements'));
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function lmsTaParseDateTime(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $raw = trim($raw);
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw)
       ?: DateTime::createFromFormat('Y-m-d H:i', $raw)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $raw)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $raw);

    if (!$dt) {
        throw new DomainException('Invalid publication date and time format.');
    }
    return $dt->format('Y-m-d H:i:s');
}

function lmsTaVerifyAnnouncement(PDO $pdo, int $announcementId, int $sectionSubjectId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM lms_announcements WHERE id = :id AND section_subject_id = :ss LIMIT 1'
    );
    $stmt->execute(['id' => $announcementId, 'ss' => $sectionSubjectId]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$announcement) {
        throw new DomainException('Announcement not found or does not belong to this subject.');
    }
    return $announcement;
}

// ── Action Dispatcher ─────────────────────────────────────────────────────────

try {
    switch ($action) {

        /* ===== CREATE ANNOUNCEMENT ========================================== */
        case 'create_announcement': {
            $title       = trim((string)($_POST['title'] ?? ''));
            $message     = trim((string)($_POST['message'] ?? ''));
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $isImportant = !empty($_POST['is_important']) ? 1 : 0;
            $publishedAt = lmsTaParseDateTime($_POST['published_at'] ?? null);

            if ($title === '') {
                throw new DomainException('Announcement title is required.');
            }
            if (mb_strlen($title) > 150) {
                throw new DomainException('Announcement title cannot exceed 150 characters.');
            }
            if ($message === '') {
                throw new DomainException('Announcement message body is required.');
            }

            // If published but no date specified, default to now
            if ($isPublished && $publishedAt === null) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare(
                'INSERT INTO lms_announcements
                    (section_subject_id, author_id, title, message, published_at, is_published, is_important, created_at, updated_at)
                 VALUES
                    (:ss, :author, :title, :message, :published_at, :is_published, :is_important, NOW(), NOW())'
            );
            $insertStmt->execute([
                'ss'           => $sectionSubjectId,
                'author'       => $userId,
                'title'        => $title,
                'message'      => $message,
                'published_at' => $publishedAt,
                'is_published' => $isPublished,
                'is_important' => $isImportant,
            ]);

            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();

            // Trigger notification if published immediately
            if ($isPublished === 1 && $publishedAt !== null && strtotime($publishedAt) <= time()) {
                notifyLmsAnnouncementRecipients($pdo, $newId);
                $_SESSION['flash_success'] = 'Announcement posted successfully! Enrolled students have been notified.';
            } elseif ($isPublished === 1 && $publishedAt !== null && strtotime($publishedAt) > time()) {
                $formattedTime = date('M j, Y g:i A', strtotime($publishedAt));
                $_SESSION['flash_success'] = "Announcement scheduled successfully. It will automatically publish and notify students on {$formattedTime}.";
            } else {
                $_SESSION['flash_success'] = 'Announcement saved as a draft.';
            }

            header('Location: ' . resolveAppUrl('teacher/lms_announcements?section_subject_id=' . $sectionSubjectId . '&highlight=' . $newId));
            exit;
        }

        /* ===== EDIT ANNOUNCEMENT ============================================ */
        case 'edit_announcement': {
            if ($announcementId <= 0) {
                throw new DomainException('Invalid announcement ID.');
            }

            $existing = lmsTaVerifyAnnouncement($pdo, $announcementId, $sectionSubjectId);

            $title          = trim((string)($_POST['title'] ?? ''));
            $message        = trim((string)($_POST['message'] ?? ''));
            $isPublished    = !empty($_POST['is_published']) ? 1 : 0;
            $isImportant    = !empty($_POST['is_important']) ? 1 : 0;
            $publishedAt    = lmsTaParseDateTime($_POST['published_at'] ?? null);
            $notifyStudents = !empty($_POST['notify_students']);

            if ($title === '') {
                throw new DomainException('Announcement title is required.');
            }
            if (mb_strlen($title) > 150) {
                throw new DomainException('Announcement title cannot exceed 150 characters.');
            }
            if ($message === '') {
                throw new DomainException('Announcement message body is required.');
            }

            if ($isPublished && $publishedAt === null) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            $wasLive = ($existing['is_published'] == 1 &&
                        $existing['published_at'] !== null &&
                        strtotime($existing['published_at']) <= time());

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                'UPDATE lms_announcements
                 SET title = :title,
                     message = :message,
                     published_at = :published_at,
                     is_published = :is_published,
                     is_important = :is_important,
                     updated_at = NOW()
                 WHERE id = :id AND section_subject_id = :ss'
            );
            $updateStmt->execute([
                'title'        => $title,
                'message'      => $message,
                'published_at' => $publishedAt,
                'is_published' => $isPublished,
                'is_important' => $isImportant,
                'id'           => $announcementId,
                'ss'           => $sectionSubjectId,
            ]);

            $pdo->commit();

            $isNowLive = ($isPublished == 1 &&
                          $publishedAt !== null &&
                          strtotime($publishedAt) <= time());

            // Notify if newly transitioned to live OR explicit request to notify updates
            if ((!$wasLive && $isNowLive) || ($notifyStudents && $isNowLive)) {
                notifyLmsAnnouncementRecipients($pdo, $announcementId);
                $_SESSION['flash_success'] = 'Announcement updated successfully and notification sent to enrolled students.';
            } else {
                $_SESSION['flash_success'] = 'Announcement updated successfully.';
            }

            header('Location: ' . resolveAppUrl('teacher/lms_announcements?section_subject_id=' . $sectionSubjectId . '&highlight=' . $announcementId));
            exit;
        }

        /* ===== DELETE ANNOUNCEMENT ========================================== */
        case 'delete_announcement': {
            if ($announcementId <= 0) {
                throw new DomainException('Invalid announcement ID.');
            }

            lmsTaVerifyAnnouncement($pdo, $announcementId, $sectionSubjectId);

            $pdo->beginTransaction();
            $delStmt = $pdo->prepare(
                'DELETE FROM lms_announcements WHERE id = :id AND section_subject_id = :ss'
            );
            $delStmt->execute(['id' => $announcementId, 'ss' => $sectionSubjectId]);
            $pdo->commit();

            $_SESSION['flash_success'] = 'Announcement deleted successfully.';
            header('Location: ' . resolveAppUrl('teacher/lms_announcements?section_subject_id=' . $sectionSubjectId));
            exit;
        }

        /* ===== TOGGLE PUBLISH =============================================== */
        case 'toggle_publish': {
            if ($announcementId <= 0) {
                throw new DomainException('Invalid announcement ID.');
            }

            $existing = lmsTaVerifyAnnouncement($pdo, $announcementId, $sectionSubjectId);

            $newPublished = ((int)$existing['is_published'] === 1) ? 0 : 1;
            $publishedAt   = $existing['published_at'];

            // If toggling to published and no published_at was set, default to now
            if ($newPublished === 1 && ($publishedAt === null || trim($publishedAt) === '')) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            $pdo->beginTransaction();
            $toggleStmt = $pdo->prepare(
                'UPDATE lms_announcements
                 SET is_published = :pub,
                     published_at = :pat,
                     updated_at = NOW()
                 WHERE id = :id AND section_subject_id = :ss'
            );
            $toggleStmt->execute([
                'pub' => $newPublished,
                'pat' => $publishedAt,
                'id'  => $announcementId,
                'ss'  => $sectionSubjectId,
            ]);
            $pdo->commit();

            $isLive = ($newPublished === 1 && $publishedAt !== null && strtotime($publishedAt) <= time());
            if ($isLive) {
                notifyLmsAnnouncementRecipients($pdo, $announcementId);
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'           => true,
                    'is_published' => $newPublished,
                    'is_live'      => $isLive,
                    'status_label' => $newPublished === 1 ? ($isLive ? 'Live' : 'Scheduled') : 'Draft',
                    'message'      => $newPublished === 1 ? 'Announcement published.' : 'Announcement unpublished (saved as draft).'
                ]);
                exit;
            }

            $_SESSION['flash_success'] = $newPublished === 1
                ? ($isLive ? 'Announcement published and live to enrolled students.' : 'Announcement scheduled.')
                : 'Announcement unpublished (hidden from students).';

            header('Location: ' . resolveAppUrl('teacher/lms_announcements?section_subject_id=' . $sectionSubjectId . '&highlight=' . $announcementId));
            exit;
        }

        default:
            throw new DomainException('Unknown or missing action.');
    }

} catch (DomainException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: ' . $returnBase);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('LMS Teacher Announcement Actions error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'A system error occurred. Please try again later.']);
        exit;
    }
    $_SESSION['flash_error'] = 'An unexpected error occurred while processing your request.';
    header('Location: ' . $returnBase);
    exit;
}
