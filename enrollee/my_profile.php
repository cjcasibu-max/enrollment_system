<?php
require_once '../includes/auth_check.php';
checkRole(['enrollee']);
require_once '../config/database.php';

$profile = null;
$error = null;
$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT s.*, u.username, u.email, u.is_active, u.profile_picture 
                           FROM students s 
                           JOIN users u ON u.id = s.user_id 
                           WHERE s.user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $profile = $stmt->fetch();

    if ($profile && !empty($profile['profile_picture'])) {
        $_SESSION['profile_picture'] = $profile['profile_picture'];
    }
} catch (PDOException $e) {
    error_log('Enrollee profile fetch failed: ' . $e->getMessage());
    $error = 'Profile information is temporarily unavailable.';
}

function enrolleeProfileValue(?array $profile, string $key): string {
    if (!$profile) return '—';
    $value = $profile[$key] ?? null;
    return htmlspecialchars(($value === null || trim((string)$value) === '') ? '—' : (string)$value, ENT_QUOTES, 'UTF-8');
}

$page_title = 'My Profile';
require_once '../includes/header.php';

$avatarSrc = !empty($profile['profile_picture']) 
    ? "../actions/view_avatar?uid={$userId}&v=" . (!empty($_SESSION['avatar_version']) ? (int)$_SESSION['avatar_version'] : time()) 
    : '';
$initials = strtoupper(substr($profile['username'] ?? $_SESSION['username'] ?? 'A', 0, 2));
?>

<!-- Header with Action Buttons Grouped on Right -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h3 class="m-0 text-navy-alt fw-bold">My Profile</h3>
        <p class="text-muted small m-0">View your applicant credentials and enrollment admission details.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="profile_edit" class="btn btn-brand-primary btn-sm px-3 shadow-sm">
            <i class="bi bi-pencil-square me-1"></i> Edit Contact Details
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
<?php elseif (!$profile): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold text-navy-alt">Applicant Profile Not Found</h5>
            <p class="text-muted mb-0">Please start your application form to populate your admissions profile.</p>
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

                <h5 class="fw-bold text-navy-alt mb-1">
                    <?php echo enrolleeProfileValue($profile, 'first_name') . ' ' . enrolleeProfileValue($profile, 'last_name'); ?>
                </h5>
                <p class="text-muted small mb-3">@<?php echo enrolleeProfileValue($profile, 'username'); ?></p>

                <!-- Status Badges -->
                <div class="d-flex flex-wrap gap-2 justify-content-center mb-3">
                    <?php
                        $admStatus = $profile['admission_status'] ?? $profile['application_status'] ?? 'draft';
                    ?>
                    <span class="badge bg-primary-subtle text-primary px-3 py-2 fw-semibold" style="border-radius: 6px;">
                        <i class="bi bi-person-badge me-1"></i> Applicant Portal
                    </span>
                    <span class="badge bg-info-subtle text-info px-3 py-2 fw-semibold" style="border-radius: 6px;">
                        <i class="bi bi-file-earmark-check me-1"></i> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $admStatus))); ?>
                    </span>
                </div>

                <!-- Tidy Details List -->
                <div class="border-top pt-3 text-start small">
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="text-muted">Applicant ID</span>
                        <strong class="text-navy-alt">#<?php echo (int)$profile['id']; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="text-muted">Account Status</span>
                        <span class="badge <?php echo ((int)$profile['is_active'] === 1) ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>">
                            <?php echo ((int)$profile['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span class="text-muted">Program</span>
                        <strong class="text-dark text-truncate ms-2" style="max-width: 170px;" title="<?php echo enrolleeProfileValue($profile, 'program_applying_for'); ?>">
                            <?php echo enrolleeProfileValue($profile, 'program_applying_for'); ?>
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <span class="text-muted">Applicant Type</span>
                        <strong class="text-dark"><?php echo enrolleeProfileValue($profile, 'applicant_type'); ?></strong>
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

    <!-- Right Column: Personal, Contact & Application Information Cards -->
    <div class="col-12 col-lg-8 col-xl-8">
        <!-- Section 1: Personal Information -->
        <div class="card card-premium shadow-sm mb-4">
            <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
                <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                    <i class="bi bi-person-vcard text-brand-primary me-2"></i> Personal Information
                </h5>
            </div>
            <div class="card-body card-body-premium p-4">
                <div class="row g-3">
                    <div class="col-12 col-sm-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">First Name</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'first_name'); ?></div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Middle Name</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'middle_name'); ?></div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Last Name</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'last_name'); ?></div>
                    </div>

                    <div class="col-12 col-sm-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Birthdate</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'birthdate'); ?></div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Gender</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'gender'); ?></div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Civil Status</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'civil_status'); ?></div>
                    </div>

                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Nationality</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'nationality'); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Religion</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'religion'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Contact and Address -->
        <div class="card card-premium shadow-sm mb-4">
            <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
                <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                    <i class="bi bi-geo-alt text-brand-primary me-2"></i> Contact & Address
                </h5>
            </div>
            <div class="card-body card-body-premium p-4">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Email Address</span>
                        <div class="fw-bold text-dark text-break"><?php echo enrolleeProfileValue($profile, 'email'); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Contact Number</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'contact_number'); ?></div>
                    </div>

                    <?php 
                        $addressParts = array_filter([
                            $profile['address_street'] ?? '',
                            $profile['address_barangay'] ?? '',
                            $profile['address_city'] ?? '',
                            $profile['address_province'] ?? '',
                            $profile['address_zip_code'] ?? ''
                        ]);
                        $composedAddress = implode(', ', $addressParts);
                    ?>
                    <div class="col-12">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Residential Address</span>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($composedAddress ?: '—', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>

                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Guardian Name</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'guardian_name'); ?></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Guardian Contact</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'guardian_contact_number'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Academic Application Information -->
        <div class="card card-premium shadow-sm">
            <div class="card-header card-header-premium d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, rgba(0,128,128,0.06), rgba(0,76,76,0.02)); border-bottom: 1px solid rgba(0,128,128,0.12);">
                <h5 class="m-0 fw-semibold text-navy-alt fs-6">
                    <i class="bi bi-mortarboard text-brand-primary me-2"></i> Application & Background
                </h5>
            </div>
            <div class="card-body card-body-premium p-4">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Intended Degree Program</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'program_applying_for'); ?></div>
                    </div>
                    <div class="col-12 col-sm-3">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Applicant Classification</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'applicant_type'); ?></div>
                    </div>
                    <div class="col-12 col-sm-3">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Target Year Level</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'year_level'); ?></div>
                    </div>

                    <div class="col-12 col-sm-6">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Origin Senior High School</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'shs_name'); ?></div>
                    </div>
                    <div class="col-12 col-sm-3">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Track / Strand</span>
                        <div class="fw-bold text-dark"><?php echo enrolleeProfileValue($profile, 'shs_track_strand'); ?></div>
                    </div>
                    <div class="col-12 col-sm-3">
                        <span class="text-muted small text-uppercase fw-semibold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">General Average</span>
                        <div class="fw-bold text-dark">
                            <?php echo ($profile['general_average'] !== null && $profile['general_average'] !== '') 
                                ? number_format((float)$profile['general_average'], 2) 
                                : '—'; ?>
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
