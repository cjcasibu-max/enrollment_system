<?php
require_once '../includes/auth_check.php';
checkRole(['cashier']);
require_once '../config/database.php';

$user = null;
$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare('SELECT id, username, email, first_name, last_name, profile_picture FROM users WHERE id = :user_id AND role = "cashier" LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();

    if ($user && !empty($user['profile_picture'])) {
        $_SESSION['profile_picture'] = $user['profile_picture'];
    }
} catch (PDOException $e) {
    error_log('Cashier profile fetch failed: ' . $e->getMessage());
}

$page_title = 'Edit Profile';
require_once '../includes/header.php';

$avatarSrc = !empty($user['profile_picture']) 
    ? "../actions/view_avatar?uid={$userId}&v=" . (!empty($_SESSION['avatar_version']) ? (int)$_SESSION['avatar_version'] : time()) 
    : '';
$initials = strtoupper(substr($user['username'] ?? $_SESSION['username'] ?? 'C', 0, 2));
$hasPhoto = !empty($user['profile_picture']);
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h3 class="m-0 text-navy-alt fw-bold">Edit Profile</h3>
        <p class="text-muted small m-0">Update your cashier officer photo and personal name details.</p>
    </div>
    <a href="my_profile" class="btn btn-outline-secondary btn-sm px-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Profile
    </a>
</div>

<!-- Info banner -->
<div class="alert alert-info d-flex align-items-center gap-3 border-0 shadow-sm mb-4" style="background: rgba(0, 128, 128, 0.08); border-left: 4px solid var(--brand-primary, #008080) !important; border-radius: 8px;">
    <i class="bi bi-info-circle-fill text-brand-primary fs-4 flex-shrink-0"></i>
    <div class="small text-dark">
        <strong>Notice on Cashier Credentials:</strong> Account username, email, and cashier desk roles are controlled by the system administrator. Contact IT support to update credentials.
    </div>
</div>

<?php if (!$user): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold text-navy-alt">Account Not Found</h5>
            <p class="text-muted mb-0">Please contact administration.</p>
        </div>
    </div>
<?php else: ?>

<!-- Card 1: Profile Photo Uploader Section at Top -->
<div class="card card-premium shadow-sm mb-4">
    <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
        <h5 class="m-0 fw-semibold text-navy-alt fs-6">
            <i class="bi bi-camera text-brand-primary me-2"></i> Profile Photo
        </h5>
    </div>
    <div class="card-body card-body-premium p-4">
        <div class="d-flex align-items-center flex-wrap gap-4">
            <div class="position-relative" style="width: 100px; height: 100px; flex-shrink: 0;">
                <div style="width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--brand-primary, #008080); overflow: hidden; background: #e6f2f2; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,128,128,0.12);">
                    <?php if ($hasPhoto): ?>
                        <img src="<?php echo htmlspecialchars($avatarSrc); ?>" 
                             alt="Avatar" 
                             class="w-100 h-100" 
                             style="object-fit: cover; display: block;"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <span class="fw-bold fs-3 text-navy-alt" style="display: none; align-items: center; justify-content: center; width: 100%; height: 100%;">
                            <?php echo htmlspecialchars($initials); ?>
                        </span>
                    <?php else: ?>
                        <span class="fw-bold fs-3 text-navy-alt d-flex align-items-center justify-content-center w-100 h-100">
                            <?php echo htmlspecialchars($initials); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex-grow-1">
                <h6 class="fw-bold text-navy-alt mb-1">Cashier Avatar</h6>
                <p class="text-muted small mb-3">Upload a JPG, PNG, or WEBP image up to 2 MB. It will appear on your officer profile and the top bar.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-brand-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#photoUploadModal">
                        <i class="bi bi-cloud-arrow-up me-1"></i> Change Photo
                    </button>
                    <?php if ($hasPhoto): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" id="btnCashierRemovePhoto">
                            <i class="bi bi-trash3 me-1"></i> Remove Photo
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Edit Form for Cashier -->
<form action="../actions/user_profile_actions" method="POST" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="update_staff_profile">
    <input type="hidden" name="redirect_url" value="../cashier/profile_edit">

    <!-- Section 2: Account Credentials (Read-Only) -->
    <div class="card card-premium shadow-sm mb-4">
        <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
            <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                <i class="bi bi-shield-lock text-brand-primary me-2"></i> Account Credentials
            </h5>
            <span class="badge bg-secondary-subtle text-secondary small"><i class="bi bi-lock-fill me-1"></i> Read Only</span>
        </div>
        <div class="card-body card-body-premium p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center justify-content-between">
                        <span>Username</span>
                        <i class="bi bi-lock-fill text-muted" title="Locked"></i>
                    </label>
                    <input class="form-control" 
                           value="<?php echo htmlspecialchars($user['username']); ?>" 
                           style="background-color: #f1f5f9; cursor: not-allowed; border-color: #e2e8f0; color: #475569;" 
                           readonly 
                           tabindex="-1">
                    <div class="form-text small text-muted"><i class="bi bi-info-circle me-1"></i>Contact IT Administration to request a username change.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center justify-content-between">
                        <span>Email Address</span>
                        <i class="bi bi-lock-fill text-muted" title="Locked"></i>
                    </label>
                    <input class="form-control" 
                           value="<?php echo htmlspecialchars($user['email']); ?>" 
                           style="background-color: #f1f5f9; cursor: not-allowed; border-color: #e2e8f0; color: #475569;" 
                           readonly 
                           tabindex="-1">
                    <div class="form-text small text-muted"><i class="bi bi-info-circle me-1"></i>Official financial notifications are sent to this address.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Personal Information -->
    <div class="card card-premium shadow-sm mb-4">
        <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
            <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                <i class="bi bi-person text-brand-primary me-2"></i> Personal Details
            </h5>
        </div>
        <div class="card-body card-body-premium p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">
                        First Name <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="first_name" 
                           value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" 
                           placeholder="Enter first name" 
                           required>
                    <div class="invalid-feedback">First name is required.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">
                        Last Name <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="last_name" 
                           value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" 
                           placeholder="Enter last name" 
                           required>
                    <div class="invalid-feedback">Last name is required.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Action Bar -->
    <div class="card card-premium shadow-sm mb-4">
        <div class="card-body card-body-premium p-3 d-flex justify-content-end align-items-center gap-2">
            <a href="my_profile" class="btn btn-outline-secondary px-4">Cancel</a>
            <button type="submit" class="btn btn-brand-primary px-4 shadow-sm">
                <i class="bi bi-check-circle me-1"></i> Save Changes
            </button>
        </div>
    </div>
</form>

<?php 
// Include shared profile photo modal
require_once '../includes/profile_photo_modal.php'; 
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const pageRemoveBtn = document.getElementById('btnCashierRemovePhoto');
    const modalRemoveForm = document.getElementById('removePhotoForm');
    if (pageRemoveBtn && modalRemoveForm) {
        pageRemoveBtn.addEventListener('click', function() {
            if (confirm('Are you sure you want to remove your profile photo and revert to default initials?')) {
                modalRemoveForm.submit();
            }
        });
    }
});
</script>

<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
