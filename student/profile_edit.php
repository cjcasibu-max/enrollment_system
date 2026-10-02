<?php
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';

$profile = null;
$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare('SELECT s.*, u.username, u.email, u.profile_picture 
                           FROM students s 
                           JOIN users u ON u.id = s.user_id 
                           WHERE s.user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $profile = $stmt->fetch();

    if ($profile && !empty($profile['profile_picture'])) {
        $_SESSION['profile_picture'] = $profile['profile_picture'];
    }
} catch (PDOException $e) {
    error_log('Student editable profile fetch failed: ' . $e->getMessage());
}

$page_title = 'Edit Contact Details';
require_once '../includes/header.php';

$avatarSrc = !empty($profile['profile_picture']) 
    ? "../actions/view_avatar?uid={$userId}&v=" . (!empty($_SESSION['avatar_version']) ? (int)$_SESSION['avatar_version'] : time()) 
    : '';
$initials = strtoupper(substr($profile['username'] ?? $_SESSION['username'] ?? 'U', 0, 2));
$hasPhoto = !empty($profile['profile_picture']);
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h3 class="m-0 text-navy-alt fw-bold">Edit Contact Details</h3>
        <p class="text-muted small m-0">Update your contact numbers, address, and guardian information.</p>
    </div>
    <a href="my_profile" class="btn btn-outline-secondary btn-sm px-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Profile
    </a>
</div>

<!-- Info banner indicating registrar-controlled records -->
<div class="alert alert-info d-flex align-items-center gap-3 border-0 shadow-sm mb-4" style="background: rgba(0, 128, 128, 0.08); border-left: 4px solid var(--brand-primary, #008080) !important; border-radius: 8px;">
    <i class="bi bi-info-circle-fill text-brand-primary fs-4 flex-shrink-0"></i>
    <div class="small text-dark">
        <strong>Notice on Student Records:</strong> Admission and academic records remain strictly registrar-controlled. Only your contact information, address details, and guardian information can be modified directly from this portal.
    </div>
</div>

<?php if (!$profile): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold text-navy-alt">Profile Not Found</h5>
            <p class="text-muted mb-0">Please contact the registrar to activate or configure your student record.</p>
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
                <h6 class="fw-bold text-navy-alt mb-1">Your Avatar</h6>
                <p class="text-muted small mb-3">Upload a JPG, PNG, or WEBP image up to 2 MB. It will appear across your portals and in the top bar.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-brand-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#photoUploadModal">
                        <i class="bi bi-cloud-arrow-up me-1"></i> Change Photo
                    </button>
                    <?php if ($hasPhoto): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" id="btnPageRemovePhoto">
                            <i class="bi bi-trash3 me-1"></i> Remove Photo
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Edit Form -->
<form action="../actions/student_actions" method="POST" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="update_profile">

    <!-- Section 2: Account (Read-Only & Locked) -->
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
                           value="<?php echo htmlspecialchars($profile['username']); ?>" 
                           style="background-color: #f1f5f9; cursor: not-allowed; border-color: #e2e8f0; color: #475569;" 
                           readonly 
                           tabindex="-1">
                    <div class="form-text small text-muted"><i class="bi bi-info-circle me-1"></i>Contact the registrar to request a username modification.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary d-flex align-items-center justify-content-between">
                        <span>Email Address</span>
                        <i class="bi bi-lock-fill text-muted" title="Locked"></i>
                    </label>
                    <input class="form-control" 
                           value="<?php echo htmlspecialchars($profile['email']); ?>" 
                           style="background-color: #f1f5f9; cursor: not-allowed; border-color: #e2e8f0; color: #475569;" 
                           readonly 
                           tabindex="-1">
                    <div class="form-text small text-muted"><i class="bi bi-info-circle me-1"></i>Official academy notices will be sent to this email.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Contact and Address (2-Column & 3-Column Balanced Grid) -->
    <div class="card card-premium shadow-sm mb-4">
        <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
            <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                <i class="bi bi-geo-alt text-brand-primary me-2"></i> Contact & Address
            </h5>
        </div>
        <div class="card-body card-body-premium p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">
                        Contact Number <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="contact_number" 
                           value="<?php echo htmlspecialchars($profile['contact_number'] ?? ''); ?>" 
                           pattern="^09[0-9]{9}$" 
                           maxlength="11" 
                           placeholder="09XXXXXXXXX" 
                           required>
                    <div class="invalid-feedback">Please enter a valid 11-digit mobile number starting with 09.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Barangay</label>
                    <input class="form-control bg-white" 
                           name="address_barangay" 
                           value="<?php echo htmlspecialchars($profile['address_barangay'] ?? ''); ?>" 
                           placeholder="Barangay name or subdivision">
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">Street / Building Address</label>
                    <input class="form-control bg-white" 
                           name="address_street" 
                           value="<?php echo htmlspecialchars($profile['address_street'] ?? ''); ?>" 
                           placeholder="House / Unit / Block / Lot / Street">
                </div>

                <!-- Balanced 3-column row for City, Province, and ZIP -->
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold text-secondary">
                        City / Municipality <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="address_city" 
                           value="<?php echo htmlspecialchars($profile['address_city'] ?? ''); ?>" 
                           placeholder="City or Municipality" 
                           required>
                    <div class="invalid-feedback">City/Municipality is required.</div>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold text-secondary">
                        Province <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="address_province" 
                           value="<?php echo htmlspecialchars($profile['address_province'] ?? ''); ?>" 
                           placeholder="Province" 
                           required>
                    <div class="invalid-feedback">Province is required.</div>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold text-secondary">ZIP Code</label>
                    <input class="form-control bg-white" 
                           name="address_zip_code" 
                           value="<?php echo htmlspecialchars($profile['address_zip_code'] ?? ''); ?>" 
                           maxlength="10" 
                           placeholder="e.g. 4114">
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Guardian Information -->
    <div class="card card-premium shadow-sm mb-4">
        <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
            <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                <i class="bi bi-people text-brand-primary me-2"></i> Guardian Information
            </h5>
        </div>
        <div class="card-body card-body-premium p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">
                        Guardian Name <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="guardian_name" 
                           value="<?php echo htmlspecialchars($profile['guardian_name'] ?? ''); ?>" 
                           placeholder="Full name of parent or legal guardian" 
                           required>
                    <div class="invalid-feedback">Guardian Name is required.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">
                        Relationship to Student <span class="text-danger">*</span>
                    </label>
                    <input class="form-control bg-white" 
                           name="guardian_relationship" 
                           value="<?php echo htmlspecialchars($profile['guardian_relationship'] ?? ''); ?>" 
                           placeholder="e.g. Mother, Father, Aunt, Legal Guardian" 
                           required>
                    <div class="invalid-feedback">Guardian Relationship is required.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Guardian Contact Number</label>
                    <input class="form-control bg-white" 
                           name="guardian_contact_number" 
                           value="<?php echo htmlspecialchars($profile['guardian_contact_number'] ?? ''); ?>" 
                           pattern="^09[0-9]{9}$" 
                           maxlength="11" 
                           placeholder="09XXXXXXXXX">
                    <div class="invalid-feedback">Guardian Contact must be an 11-digit mobile number starting with 09.</div>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold text-secondary">
                        Guardian Address <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control bg-white" 
                              name="guardian_address" 
                              rows="2" 
                              placeholder="Complete residential address of guardian" 
                              required><?php echo htmlspecialchars($profile['guardian_address'] ?? ''); ?></textarea>
                    <div class="invalid-feedback">Guardian Address is required.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 5: Personal (Religion) -->
    <div class="card card-premium shadow-sm mb-4">
        <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
            <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                <i class="bi bi-person text-brand-primary me-2"></i> Personal Details
            </h5>
        </div>
        <div class="card-body card-body-premium p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold text-secondary">Religion</label>
                    <input class="form-control bg-white" 
                           name="religion" 
                           value="<?php echo htmlspecialchars($profile['religion'] ?? ''); ?>" 
                           placeholder="e.g. Roman Catholic, Christian, etc.">
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Action Bar: Cancel (Outlined) & Save Changes (Primary Teal) Grouped on Right -->
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
    const pageRemoveBtn = document.getElementById('btnPageRemovePhoto');
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