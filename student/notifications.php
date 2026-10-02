<?php
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$notifications = [];
$error = null;

try {
    $stmt = $pdo->prepare(
        'SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC'
     );
    $stmt->execute(['user_id' => $userId]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Notification fetch failed: ' . $e->getMessage());
    $error = 'Notifications are temporarily unavailable.';
}

$page_title = 'Notifications';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Notifications</h3>
        <p class="text-muted small m-0">Updates about your class enrollments, grades, and academic records.</p>
    </div>
    <?php if (!$error && array_filter($notifications, static fn(array $notification): bool => (int)$notification['is_read'] === 0)): ?>
        <form action="../actions/notification_actions" method="POST">
            <input type="hidden" name="action" value="mark_all_read">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-check2-all me-1"></i>Mark all as read
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$notifications): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-bell-slash fs-1 text-muted d-block mb-3"></i>
            <h5>No notifications yet</h5>
            <p class="text-muted mb-0">Academic updates and grade approvals will appear here when posted by instructors or the Registrar.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card card-premium shadow-sm overflow-hidden" style="border-radius: 14px;">
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $notification):
                $type = in_array($notification['type'], ['info', 'success', 'warning', 'danger'], true) ? $notification['type'] : 'info';
                $icon = ['info' => 'bi-info-circle-fill', 'success' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'danger' => 'bi-x-circle-fill'][$type];
                
                $titleLower = strtolower(trim($notification['title']));
                $hasTarget = in_array($titleLower, [
                    'application approved', 'application rejected', 'edits requested', 'registrar remark', 
                    'application under review', 'payment validated', 'registration submitted', 
                    'registration approved', 'registration update', 'grades approved'
                ], true);
            ?>
                <a href="../actions/notification_actions?action=read_and_redirect&id=<?php echo (int)$notification['id']; ?>" 
                   class="list-group-item list-group-item-action px-4 py-4 <?php echo (int)$notification['is_read'] === 0 ? 'bg-light' : ''; ?>"
                   style="text-decoration: none; color: inherit; transition: all 0.15s ease;">
                    <div class="d-flex gap-3 align-items-start">
                        <i class="bi <?php echo $icon; ?> text-<?php echo $type; ?> fs-4 mt-0.5"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <h5 class="mb-1 <?php echo (int)$notification['is_read'] === 0 ? 'fw-bold text-navy' : 'fw-semibold text-muted-alt'; ?>" style="font-size: 0.98rem;">
                                    <?php echo htmlspecialchars($notification['title']); ?>
                                </h5>
                                <div class="d-flex gap-2 align-items-center">
                                    <?php if ((int)$notification['is_read'] === 0): ?>
                                        <span class="badge bg-primary text-white" style="font-size: 0.65rem; border-radius: 4px; padding: 0.25rem 0.4rem;">New</span>
                                    <?php endif; ?>
                                    <?php if ($hasTarget): ?>
                                        <span class="text-brand-primary small fw-semibold" style="font-size: 0.72rem;">View Details <i class="bi bi-chevron-right"></i></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="text-muted small mb-2 mt-1" style="line-height: 1.5;"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                            <small class="text-muted-alt" style="font-size: 0.72rem;"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($notification['created_at']))); ?></small>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
