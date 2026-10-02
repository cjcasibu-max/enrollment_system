<?php
require_once '../includes/auth_check.php';
checkRole(['registrar']);
require_once '../config/database.php';

$user = null;
$error = null;
$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT id, username, email, role, is_active, created_at, first_name, last_name, profile_picture 
                           FROM users 
                           WHERE id = :user_id AND role = 'registrar' 
                           LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();

    if ($user && !empty($user['profile_picture'])) {
        $_SESSION['profile_picture'] = $user['profile_picture'];
    }
} catch (PDOException $e) {
    error_log('Registrar profile fetch failed: ' . $e->getMessage());
    $error = 'Profile information is temporarily unavailable.';
}

$page_title = 'My Profile';
require_once '../includes/header.php';

$avatarSrc = !empty($user['profile_picture']) 
    ? "../actions/view_avatar?uid={$userId}&v=" . (!empty($_SESSION['avatar_version']) ? (int)$_SESSION['avatar_version'] : time()) 
    : '';
$initials = strtoupper(substr($user['username'] ?? $_SESSION['username'] ?? 'R', 0, 2));
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = $user['username'] ?? 'Registrar Officer';
}
?>

<!-- Header with Action Buttons Grouped on Right -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h3 class="m-0 text-navy-alt fw-bold">My Profile</h3>
        <p class="text-muted small m-0">View your registrar officer credentials and administrative role.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="profile_edit" class="btn btn-brand-primary btn-sm px-3 shadow-sm">
            <i class="bi bi-pencil-square me-1"></i> Edit Profile
        </a>
        <a href="dashboard" class="btn btn-outline-secondary btn-sm px-3">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
    </div>
<?php elseif (!$user): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold text-navy-alt">Account Not Found</h5>
            <p class="text-muted mb-0">Please contact the system administrator to verify your credentials.</p>
        </div>
    </div>
<?php else: ?>

<div class="row g-4 align-items-start">
    <!-- Left Column: Sticky Profile Card (Compact & Content-Sized) -->
    <div class="col-12 col-lg-4 col-xl-4">
        <div class="card card-premium shadow-sm text-center" style="position: sticky; top: 1.5rem; z-index: 5;">
            <div class="card-body card-body-premium p-4">
                <!-- 120px Avatar with Teal Ring & Camera Edit Button -->
                <div class="position-relative d-inline-block mx-auto mb-3">
                    <div style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid var(--brand-primary, #008080); overflow: hidden; background: #e6f2f2; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(0,128,128,0.18);">
                        <?php if (!empty($avatarSrc)): ?>
                            <img src="<?php echo htmlspecialchars($avatarSrc); ?>" 
                                 alt="Avatar" 
                                 class="w-100 h-100" 
                                 style="object-fit: cover; display: block;"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="fw-bold fs-2 text-navy-alt" style="display: none; align-items: center; justify-content: center; width: 100%; height: 100%;">
                                <?php echo htmlspecialchars($initials); ?>
                            </span>
                        <?php else: ?>
                            <span class="fw-bold fs-2 text-navy-alt d-flex align-items-center justify-content-center w-100 h-100">
                                <?php echo htmlspecialchars($initials); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <button type="button" 
                            class="btn btn-brand-primary rounded-circle position-absolute bottom-0 end-0 shadow-sm" 
                            style="width: 36px; height: 36px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: 2px solid #ffffff; transform: translate(6px, 6px);" 
                            title="Update Profile Photo" 
                            data-bs-toggle="modal" 
                            data-bs-target="#photoUploadModal">
                        <i class="bi bi-camera-fill fs-6"></i>
                    </button>
                </div>

                <h5 class="fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($fullName); ?></h5>
                <p class="text-muted small mb-3">@<?php echo htmlspecialchars($user['username']); ?></p>

                <!-- Role and Status Badges -->
                <div class="d-flex flex-wrap gap-2 justify-content-center mb-3">
                    <span class="badge bg-primary-subtle text-primary px-3 py-2 fw-semibold" style="border-radius: 6px;">
                        <i class="bi bi-person-badge me-1"></i> Registrar Officer
                    </span>
                    <span class="badge <?php echo ((int)$user['is_active'] === 1) ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?> px-3 py-2 fw-semibold" style="border-radius: 6px;">
                        <i class="bi bi-shield-check me-1"></i> <?php echo ((int)$user['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>

                <!-- Tidy Details List -->
                <div class="border-top pt-3 text-start small">
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="text-muted">Staff ID</span>
                        <strong class="text-navy-alt">#<?php echo (int)$user['id']; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="text-muted">Department</span>
                        <strong class="text-dark">Office of the Registrar</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <span class="text-muted">Member Since</span>
                        <strong class="text-dark"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></strong>
                    </div>
                </div>

                <div class="mt-3 pt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#photoUploadModal">
                        <i class="bi bi-camera me-1"></i> Change Photo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Account Details and Fast Operations -->
    <div class="col-12 col-lg-8 col-xl-8">
        <!-- Section 1: Officer Information -->
        <div class="card card-premium shadow-sm mb-4">
            <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
                <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                    <i class="bi bi-person-vcard text-brand-primary me-2"></i> Officer Information
                </h5>
            </div>
            <div class="card-body card-body-premium p-4">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">First Name</span>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($user['first_name'] ?: '—'); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Last Name</span>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($user['last_name'] ?: '—'); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Username</span>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($user['username']); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Email Address</span>
                        <div class="fw-bold text-dark text-break"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Authorized Role</span>
                        <div class="fw-bold text-dark">Registrar Official</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Account Created</span>
                        <div class="fw-bold text-dark"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Administrative Overview & Desk Shortcuts -->
        <div class="card card-premium shadow-sm">
            <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
                <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                    <i class="bi bi-briefcase text-brand-primary me-2"></i> Registrar Desk Responsibilities
                </h5>
            </div>
            <div class="card-body card-body-premium p-4">
                <p class="text-muted small mb-3">Your account is granted administrative access to the following registrar desk operations:</p>
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <div class="p-3 rounded border bg-light d-flex align-items-center gap-3">
                            <i class="bi bi-file-earmark-person fs-3 text-brand-primary"></i>
                            <div>
                                <strong class="d-block text-dark">Admissions & Applications</strong>
                                <small class="text-muted">Review, verify, and approve enrollees</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="p-3 rounded border bg-light d-flex align-items-center gap-3">
                            <i class="bi bi-mortarboard fs-3 text-brand-primary"></i>
                            <div>
                                <strong class="d-block text-dark">Enrollment & CORs</strong>
                                <small class="text-muted">Assign sections, validate curriculum</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="p-3 rounded border bg-light d-flex align-items-center gap-3">
                            <i class="bi bi-book fs-3 text-brand-primary"></i>
                            <div>
                                <strong class="d-block text-dark">Curriculum Management</strong>
                                <small class="text-muted">Manage BSMT / BSMarE course offerings</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="p-3 rounded border bg-light d-flex align-items-center gap-3">
                            <i class="bi bi-people fs-3 text-brand-primary"></i>
                            <div>
                                <strong class="d-block text-dark">Master Student Directory</strong>
                                <small class="text-muted">Academic records and evaluation</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Include shared profile photo modal
require_once '../includes/profile_photo_modal.php'; 
?>

<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
