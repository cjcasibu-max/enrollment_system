<?php
/**
 * Enrollee Apply Form
 * Captures comprehensive applicant profiles, academic choices, and mandatory credentials.
 * Secured to Enrollee role. Locks fields if application is processed.
 */

require_once '../includes/auth_check.php';
checkRole(['enrollee']);

require_once '../config/database.php';
global $pdo;

$userId = (int)$_SESSION['user_id'];

// Fetch user account email
try {
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id LIMIT 1");
    $userStmt->execute(['user_id' => $userId]);
    $userAccount = $userStmt->fetch();
    $userEmail = $userAccount ? $userAccount['email'] : '';
} catch (\PDOException $e) {
    error_log("Fetch user email failed: " . $e->getMessage());
    $userEmail = '';
}

// Fetch existing student profile details
$student = null;
$existingDocs = [];
$applicationStatus = 'draft';
$isLocked = false;

try {
    $studentStmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
    $studentStmt->execute(['user_id' => $userId]);
    $student = $studentStmt->fetch();

    if ($student) {
        $studentId = (int)$student['id'];
        $applicationStatus = $student['application_status'];
        $isLocked = !in_array($applicationStatus, ['draft', 'needs_revision'], true);

        // Fetch existing uploaded documents
        $docStmt = $pdo->prepare("SELECT * FROM documents WHERE student_id = :student_id");
        $docStmt->execute(['student_id' => $studentId]);
        $docs = $docStmt->fetchAll();
        foreach ($docs as $doc) {
            $existingDocs[$doc['document_type']] = $doc;
        }
    }
} catch (\PDOException $e) {
    error_log("Fetch student profile failed: " . $e->getMessage());
}

// If a previous submission failed server-side, preserve scalar POST values so the form can be re-populated.
if (isset($_SESSION['old_post']) && is_array($_SESSION['old_post'])) {
    $old = $_SESSION['old_post'];
    if (!is_array($student)) {
        $student = [];
    }
    foreach ($old as $k => $v) {
        if (is_scalar($v)) {
            $student[$k] = $v;
        }
    }
    unset($_SESSION['old_post']);
}


$page_title = "Admission Application Form";
require_once '../includes/header.php';
?>

<div class="admission-form-header mb-4">
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
        <div>
            <div class="upper-kicker">Enrollment Process</div>
            <h2 class="m-0 fw-bold text-navy-alt">Admission Application Form</h2>
        </div>
        <div class="application-meta d-flex flex-column flex-sm-row align-items-sm-center gap-2">
            <div class="meta-pill"><span class="meta-label">Application ID:</span> <strong>#NCST-<?php echo date('Y'); ?>-<?php echo str_pad((int)($student['id'] ?? 1), 4, '0', STR_PAD_LEFT); ?></strong></div>
            <div class="meta-pill meta-status"><span class="meta-label">Status:</span> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $applicationStatus))); ?></div>
        </div>
    </div>
</div>

<?php if ($isLocked): ?>
    <div class="alert alert-info border-info-subtle d-flex align-items-center gap-3 p-4 mb-4 rounded-3 shadow-sm">
        <i class="bi bi-shield-lock-fill fs-1 text-brand-primary"></i>
        <div>
            <h5 class="alert-heading fw-bold m-0 text-brand-primary">Application Locked</h5>
            <p class="m-0 small text-muted">Your application status is currently <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $applicationStatus))); ?></strong>. Modifications are disabled while the application is being audited by the Admissions Board.</p>
        </div>
    </div>
<?php endif; ?>

<div class="card card-premium shadow-sm admission-shell">
    <div class="card-header card-header-premium">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <h5 class="card-title m-0 fw-semibold text-navy-alt">NCST Maritime Cadetship Application Form</h5>
        </div>
    </div>
    
    <div class="card-body card-body-premium p-4">
        <form action="../actions/enrollee_actions" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate id="applicationForm">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <div class="required-note mb-4">
                <i class="bi bi-info-circle-fill"></i>
                <span>Please fill out all fields marked with an asterisk (<span class="text-danger">*</span>).</span>
            </div>
            
            <!-- ===============================================================
                 SECTION 1: PERSONAL INFORMATION
                 =============================================================== -->
            <div class="section-block">
                <div class="section-header">
                    <div>
                        <h5 class="section-title"><i class="bi bi-person-badge"></i> Personal Information</h5>
                    </div>
                </div>
                <div class="row g-3">
                <div class="col-12 col-sm-3">
                    <label for="first_name" class="form-label fw-medium">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" id="first_name" class="form-control" 
                           value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">First name is required.</div>
                </div>
                
                <div class="col-12 col-sm-3">
                    <label for="middle_name" class="form-label fw-medium">Middle Name</label>
                    <input type="text" name="middle_name" id="middle_name" class="form-control" 
                           value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>" <?php echo $isLocked ? 'disabled' : ''; ?>>
                </div>
                
                <div class="col-12 col-sm-3">
                    <label for="last_name" class="form-label fw-medium">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" id="last_name" class="form-control" 
                           value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Last name is required.</div>
                </div>
                
                <div class="col-12 col-sm-3">
                    <label for="suffix" class="form-label fw-medium">Suffix <span class="text-muted small">(Jr, III, etc.)</span></label>
                    <input type="text" name="suffix" id="suffix" class="form-control" placeholder="e.g. Jr."
                           value="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>" <?php echo $isLocked ? 'disabled' : ''; ?>>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="birthdate" class="form-label fw-medium">Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" name="birthdate" id="birthdate" class="form-control" 
                           value="<?php echo htmlspecialchars($student['birthdate'] ?? ''); ?>" min="1900-01-01" max="" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div id="birthdate-error" class="invalid-feedback" aria-live="polite">Birthdate must be a valid past date and the applicant must be at least 15 years old.</div>
                </div>

                <div class="col-12 col-sm-2">
                    <label for="age" class="form-label fw-medium">Age <span class="text-danger">*</span></label>
                    <input type="text" name="age" id="age" class="form-control" min="15" max="100"
                           value="<?php echo htmlspecialchars($student['age'] ?? ''); ?>" required readonly <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Applicants must be at least 15 years old.</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="place_of_birth" class="form-label fw-medium">Place of Birth <span class="text-danger">*</span></label>
                    <input type="text" name="place_of_birth" id="place_of_birth" class="form-control" placeholder="City / Province"
                           value="<?php echo htmlspecialchars($student['place_of_birth'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Place of birth is required.</div>
                </div>

                <div class="col-12 col-sm-3">
                    <label for="gender" class="form-label fw-medium">Gender <span class="text-danger">*</span></label>
                    <select name="gender" id="gender" class="form-select" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo !isset($student['gender']) ? 'selected' : ''; ?>>Select...</option>
                        <option value="Male" <?php echo (isset($student['gender']) && $student['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (isset($student['gender']) && $student['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (isset($student['gender']) && $student['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                    <div class="invalid-feedback">Gender selection is required.</div>
                </div>

                <div class="col-12 col-sm-3">
                    <label for="civil_status" class="form-label fw-medium">Civil Status <span class="text-danger">*</span></label>
                    <select name="civil_status" id="civil_status" class="form-select" required onchange="toggleMarriageCertificate(this.value)" <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo !isset($student['civil_status']) ? 'selected' : ''; ?>>Select...</option>
                        <option value="Single" <?php echo (isset($student['civil_status']) && $student['civil_status'] === 'Single') ? 'selected' : ''; ?>>Single</option>
                        <option value="Married" <?php echo (isset($student['civil_status']) && $student['civil_status'] === 'Married') ? 'selected' : ''; ?>>Married</option>
                        <option value="Widowed" <?php echo (isset($student['civil_status']) && $student['civil_status'] === 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                        <option value="Separated" <?php echo (isset($student['civil_status']) && $student['civil_status'] === 'Separated') ? 'selected' : ''; ?>>Separated</option>
                    </select>
                    <div class="invalid-feedback">Civil status is required.</div>
                </div>

                <div class="col-12 col-sm-3">
                    <label for="nationality" class="form-label fw-medium">Nationality <span class="text-danger">*</span></label>
                    <input type="text" name="nationality" id="nationality" class="form-control" 
                           value="<?php echo htmlspecialchars($student['nationality'] ?? 'Filipino'); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Nationality is required.</div>
                </div>

                <div class="col-12 col-sm-3">
                    <label for="religion" class="form-label fw-medium">Religion</label>
                    <input type="text" name="religion" id="religion" class="form-control" list="religion_options" maxlength="50" placeholder="Optional"
                           value="<?php echo htmlspecialchars($student['religion'] ?? ''); ?>" <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <datalist id="religion_options">
                        <option value="Roman Catholic">
                        <option value="Protestant">
                        <option value="Islam">
                        <option value="Iglesia ni Cristo">
                        <option value="Other">
                    </datalist>
                </div>
            </div>
            </div>

            <!-- ===============================================================
                 SECTION 2: CONTACT & ADDRESS INFORMATION
                 =============================================================== -->
            <div class="section-block">
                <div class="section-header">
                    <div>
                        <h5 class="section-title"><i class="bi bi-geo-alt"></i> Contact & Address Information</h5>
                    </div>
                </div>
                
                <h6 class="fw-bold text-navy small mb-2 section-subtitle">Permanent Address</h6>
                <div class="row g-3 mb-4">
                <div class="col-12 col-sm-4">
                    <label for="address_street" class="form-label fw-medium">Street / Block / Lot <span class="text-danger">*</span></label>
                    <input type="text" name="address_street" id="address_street" class="form-control" placeholder="123 Naval Street"
                           value="<?php echo htmlspecialchars($student['address_street'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Street address is required.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <style>
                        .city-search-results {
                            display: none;
                            position: absolute;
                            top: calc(100% + 4px);
                            left: 0;
                            right: 0;
                            max-height: 240px;
                            overflow-y: auto;
                            background: #fff;
                            border: 1px solid #ced4da;
                            border-radius: .5rem;
                            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15);
                            z-index: 1050;
                        }
                        .city-option {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            gap: .75rem;
                            width: 100%;
                            padding: .5rem .85rem;
                            border: 0;
                            background: transparent;
                            text-align: left;
                            font-size: .9rem;
                            cursor: pointer;
                        }
                        .city-option .city-option-province {
                            color: #6c757d;
                            font-size: .78rem;
                            white-space: nowrap;
                        }
                        .city-option:hover,
                        .city-option.active {
                            background: rgba(13, 122, 122, .12);
                        }
                        .city-option-empty { color: #6c757d; cursor: default; }
                    </style>
                    <label for="address_city_search" class="form-label fw-medium">City / Municipality <span class="text-danger">*</span></label>
                    <div class="position-relative" id="city_combobox">
                        <input type="text" id="address_city_search" class="form-control" placeholder="Type to search city / municipality..."
                               autocomplete="off" <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <input type="hidden" name="address_city" id="address_city"
                               value="<?php echo htmlspecialchars($student['address_city'] ?? ''); ?>">
                        <div id="city_search_results" class="city-search-results"></div>
                    </div>
                    <div class="invalid-feedback">Select a city or municipality from the list.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="address_barangay" class="form-label fw-medium">Barangay <span class="text-danger">*</span></label>
                    <select name="address_barangay" id="address_barangay" class="form-select" required <?php echo $isLocked ? 'disabled' : ''; ?> disabled>
                        <option value="" disabled selected>Select city first...</option>
                    </select>
                    <div class="invalid-feedback">Barangay is required.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="address_province" class="form-label fw-medium">Province <span class="text-danger">*</span></label>
                    <input type="text" name="address_province" id="address_province" class="form-control bg-light"
                           value="<?php echo htmlspecialchars($student['address_province'] ?? ''); ?>" required readonly <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Province is required.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="address_zip_code" class="form-label fw-medium">Zip Code <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-pin-map"></i></span>
                        <input type="text" name="address_zip_code" id="address_zip_code" class="form-control"
                               value="<?php echo htmlspecialchars($student['address_zip_code'] ?? ''); ?>" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                               placeholder="e.g. 4114" <?php echo $isLocked ? 'disabled' : ''; ?>>
                    </div>
                    <div class="invalid-feedback">Zip code is required.</div>
                </div>

                <!-- Hidden pre-saved values so JS can restore selection on page load -->
                <input type="hidden" id="saved_address_city" value="<?php echo htmlspecialchars($student['address_city'] ?? ''); ?>">
                <input type="hidden" id="saved_address_barangay" value="<?php echo htmlspecialchars($student['address_barangay'] ?? ''); ?>">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6">
                    <label for="contact_number" class="form-label fw-medium">Contact Number <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input type="tel" name="contact_number" id="contact_number" class="form-control" placeholder="09XXXXXXXXX" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11"
                               value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <div class="invalid-feedback">Use an 11-digit Philippine mobile number beginning with 09.</div>
                    </div>
                </div>
                
                <div class="col-12 col-sm-6">
                    <label for="email" class="form-label fw-medium">Email Address <span class="text-muted small">(Read-Only)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($userEmail); ?>" disabled>
                    </div>
                </div>
            </div>

            <!-- Emergency contact block -->
            <h6 class="fw-bold text-navy small mb-2 border-bottom pb-1">Emergency Contact Details</h6>
            <div class="row g-3 mb-5">
                <div class="col-12 col-sm-4">
                    <label for="guardian_name" class="form-label fw-medium">Guardian Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="guardian_name" id="guardian_name" class="form-control" placeholder="Primary contact name"
                           value="<?php echo htmlspecialchars($student['guardian_name'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Guardian name is required.</div>
                </div>
                
                <div class="col-12 col-sm-4">
                    <label for="guardian_relationship" class="form-label fw-medium">Relationship <span class="text-danger">*</span></label>
                    <input type="text" name="guardian_relationship" id="guardian_relationship" class="form-control" placeholder="e.g. Father, Mother"
                           value="<?php echo htmlspecialchars($student['guardian_relationship'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Relationship is required.</div>
                </div>
                
                <div class="col-12 col-sm-4">
                    <label for="guardian_contact_number" class="form-label fw-medium">Contact Number <span class="text-danger">*</span></label>
                    <input type="tel" name="guardian_contact_number" id="guardian_contact_number" class="form-control" placeholder="09XXXXXXXXX" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11"
                           value="<?php echo htmlspecialchars($student['guardian_contact_number'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Use an 11-digit Philippine mobile number beginning with 09.</div>
                </div>

                <div class="col-12">
                    <label for="guardian_address" class="form-label fw-medium">Guardian Home Address <span class="text-danger">*</span></label>
                    <input type="text" name="guardian_address" id="guardian_address" class="form-control" placeholder="Street, Barangay, City, Province"
                           value="<?php echo htmlspecialchars($student['guardian_address'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Guardian address is required.</div>
                </div>
            </div>
            </div>

            <!-- ===============================================================
                 SECTION 3: ACADEMIC BACKGROUND
                 =============================================================== -->
            <div class="section-block">
                <div class="section-header">
                    <div>
                        <h5 class="section-title"><i class="bi bi-book"></i> Academic Background</h5>
                    </div>
                </div>
                <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label for="applicant_type" class="form-label fw-medium">Applicant Type <span class="text-danger">*</span></label>
                    <select name="applicant_type" id="applicant_type" class="form-select" required onchange="syncYearLevel(this.value)" <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo empty($student['applicant_type']) ? 'selected' : ''; ?>>Select applicant type...</option>
                        <option value="New Student" <?php echo (($student['applicant_type'] ?? '') === 'New Student') ? 'selected' : ''; ?>>New Student</option>
                        <option value="Transferee" <?php echo (($student['applicant_type'] ?? '') === 'Transferee') ? 'selected' : ''; ?>>Transferee</option>
                    </select>
                    <div class="invalid-feedback">Applicant type is required.</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="year_level" class="form-label fw-medium">Year Level <span class="text-danger">*</span></label>
                    <?php $displayYearLevel = (($student['applicant_type'] ?? '') === 'New Student') ? '1st Year' : ($student['year_level'] ?? ''); ?>
                    <select id="year_level" class="form-select" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo empty($displayYearLevel) ? 'selected' : ''; ?>>Select year level...</option>
                        <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $level): ?>
                            <option value="<?php echo $level; ?>" <?php echo ($displayYearLevel === $level) ? 'selected' : ''; ?>><?php echo $level; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="year_level_fixed" class="form-control" value="1st Year" readonly aria-readonly="true" <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <input type="hidden" name="year_level" id="year_level_value" value="<?php echo htmlspecialchars($displayYearLevel); ?>">
                    <div class="invalid-feedback">Year level is required.</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="shs_track_strand" class="form-label fw-medium">Senior High School Track/Strand <span class="text-danger">*</span></label>
                    <select name="shs_track_strand" id="shs_track_strand" class="form-select" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo !isset($student['shs_track_strand']) ? 'selected' : ''; ?>>Select Strand...</option>
                        <option value="STEM" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'STEM') ? 'selected' : ''; ?>>STEM (Science, Technology, Engineering, Mathematics)</option>
                        <option value="TVL-Maritime" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'TVL-Maritime') ? 'selected' : ''; ?>>TVL-Maritime Academy Specialized</option>
                        <option value="ABM" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'ABM') ? 'selected' : ''; ?>>ABM (Accountancy, Business, Management)</option>
                        <option value="HUMSS" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'HUMSS') ? 'selected' : ''; ?>>HUMSS (Humanities and Social Sciences)</option>
                        <option value="GAS" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'GAS') ? 'selected' : ''; ?>>GAS (General Academic Strand)</option>
                        <option value="TVL-ICT" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'TVL-ICT') ? 'selected' : ''; ?>>TVL-ICT (Information & Communications Tech)</option>
                        <option value="TVL-Others" <?php echo (isset($student['shs_track_strand']) && $student['shs_track_strand'] === 'TVL-Others') ? 'selected' : ''; ?>>TVL-Other Vocational Strands</option>
                    </select>
                    <div class="invalid-feedback">SHS Track/Strand is required.</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="shs_name" class="form-label fw-medium">Senior High School Name <span class="text-danger">*</span></label>
                    <input type="text" name="shs_name" id="shs_name" class="form-control" placeholder="e.g. NCST High"
                           value="<?php echo htmlspecialchars($student['shs_name'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">School name is required.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="shs_type" class="form-label fw-medium">School Type <span class="text-danger">*</span></label>
                    <select name="shs_type" id="shs_type" class="form-select" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo !isset($student['shs_type']) ? 'selected' : ''; ?>>Select...</option>
                        <option value="Public" <?php echo (isset($student['shs_type']) && $student['shs_type'] === 'Public') ? 'selected' : ''; ?>>Public</option>
                        <option value="Private" <?php echo (isset($student['shs_type']) && $student['shs_type'] === 'Private') ? 'selected' : ''; ?>>Private</option>
                    </select>
                    <div class="invalid-feedback">School type is required.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="year_graduated" class="form-label fw-medium">Year Graduated <span class="text-danger">*</span></label>
                    <input type="month" name="year_graduated" id="year_graduated" class="form-control" min="1900-01" max="<?php echo date('Y-m'); ?>"
                           value="<?php
                                $ygVal = '';
                                if (!empty($student['year_graduated'])) {
                                    if (preg_match('/^\d{4}-\d{2}$/', $student['year_graduated'])) {
                                        $ygVal = $student['year_graduated'];
                                    } elseif (preg_match('/^\d{4}$/', $student['year_graduated'])) {
                                        $ygVal = $student['year_graduated'] . '-01';
                                    }
                                }
                                echo htmlspecialchars($ygVal);
                           ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">Year graduated is required.</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="general_average" class="form-label fw-medium">General Average (GAA) <span class="text-danger">*</span></label>
                    <input type="number" name="general_average" id="general_average" class="form-control" placeholder="90.00" step="0.01" min="70" max="100" inputmode="decimal"
                           value="<?php echo htmlspecialchars($student['general_average'] ?? ''); ?>" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <div class="invalid-feedback">General average must be a decimal value.</div>
                </div>
            </div>
            </div>

            <!-- ===============================================================
                 SECTION 4: PROGRAM CHOICE
                 =============================================================== -->
            <div class="section-block">
                <div class="section-header">
                    <div>
                        <h5 class="section-title"><i class="bi bi-flag"></i> Program Choice</h5>
                    </div>
                </div>
                <div class="row g-3">
                <div class="col-12">
                    <label for="program_applying_for" class="form-label fw-medium">Select Academy Major Program <span class="text-danger">*</span></label>
                    <select name="program_applying_for" id="program_applying_for" class="form-select select-premium" required <?php echo $isLocked ? 'disabled' : ''; ?>>
                        <option value="" disabled <?php echo !isset($student['program_applying_for']) ? 'selected' : ''; ?>>Select Maritime Major...</option>
                        <option value="Bachelor of Science in Marine Engineering (BSMarE)" <?php echo (isset($student['program_applying_for']) && $student['program_applying_for'] === 'Bachelor of Science in Marine Engineering (BSMarE)') ? 'selected' : ''; ?>>Bachelor of Science in Marine Engineering (BSMarE) — Engine Officer Path</option>
                        <option value="Bachelor of Science in Marine Transportation (BSMT)" <?php echo (isset($student['program_applying_for']) && $student['program_applying_for'] === 'Bachelor of Science in Marine Transportation (BSMT)') ? 'selected' : ''; ?>>Bachelor of Science in Marine Transportation (BSMT) — Deck Officer Path</option>
                    </select>
                    <div class="invalid-feedback">Please select a program.</div>
                </div>
            </div>
            </div>

            <!-- ===============================================================
                 SECTION 5: DOCUMENT UPLOADS (Section 4c)
                 =============================================================== -->
            <div class="section-block">
                <div class="section-header">
                    <div>
                        <h5 class="section-title"><i class="bi bi-file-earmark-arrow-up"></i> Mandatory Credentials (MIME verified)</h5>
                    </div>
                </div>
                <p class="text-muted small mb-3 px-1">Please upload clear scanned copies or high-resolution photos of the following documents. Allowed formats: PDF, JPG, PNG. Max file size: 5MB per file.</p>
                
                <div class="row g-4 mb-4 document-grid">
                <!-- Form 137 -->
                <div class="col-12 col-md-6">
                    <div class="upload-card">
                        <div class="upload-head">
                            <div class="upload-icon icon-1"><i class="bi bi-file-earmark-text"></i></div>
                            <div class="upload-copy">
                                <h6>Form 137 / SF-10 Record <span class="text-danger">*</span></h6>
                                <small>Original transcript of records</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_form_137" data-required-doc="true" <?php echo !isset($existingDocs['form_137']) ? 'required' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['form_137'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['form_137']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['form_137']['original_filename'] ?? 'form_137'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['form_137']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['form_137']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['form_137']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Diploma -->
                <div class="col-12 col-md-6">
                    <div class="upload-card">
                        <div class="upload-head">
                            <div class="upload-icon icon-2"><i class="bi bi-mortarboard"></i></div>
                            <div class="upload-copy">
                                <h6>SHS Diploma / Cert <span class="text-danger">*</span></h6>
                                <small>Proof of completion</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_shs_diploma" data-required-doc="true" <?php echo !isset($existingDocs['shs_diploma']) ? 'required' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['shs_diploma'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['shs_diploma']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['shs_diploma']['original_filename'] ?? 'shs_diploma'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['shs_diploma']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['shs_diploma']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['shs_diploma']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Good Moral -->
                <div class="col-12 col-md-6">
                    <div class="upload-card">
                        <div class="upload-head">
                            <div class="upload-icon icon-3"><i class="bi bi-shield-check"></i></div>
                            <div class="upload-copy">
                                <h6>Good Moral Character <span class="text-danger">*</span></h6>
                                <small>Issued by previous school</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_good_moral" data-required-doc="true" <?php echo !isset($existingDocs['good_moral']) ? 'required' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['good_moral'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['good_moral']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['good_moral']['original_filename'] ?? 'good_moral'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['good_moral']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['good_moral']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['good_moral']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Birth Cert -->
                <div class="col-12 col-md-6">
                    <div class="upload-card">
                        <div class="upload-head">
                            <div class="upload-icon icon-4"><i class="bi bi-person-badge"></i></div>
                            <div class="upload-copy">
                                <h6>PSA Birth Certificate <span class="text-danger">*</span></h6>
                                <small>Clear authenticated copy</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_birth_certificate" data-required-doc="true" <?php echo !isset($existingDocs['birth_certificate']) ? 'required' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['birth_certificate'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['birth_certificate']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['birth_certificate']['original_filename'] ?? 'birth_certificate'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['birth_certificate']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['birth_certificate']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['birth_certificate']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Marriage Cert (Conditional) -->
                <div class="col-12 col-md-6" id="marriage_cert_container" style="display: none;">
                    <div class="upload-card upload-card-alt">
                        <div class="upload-head">
                            <div class="upload-icon icon-5"><i class="bi bi-heart-pulse"></i></div>
                            <div class="upload-copy">
                                <h6>PSA Marriage Certificate <span class="text-danger">*</span></h6>
                                <small>Required for married applicants</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_marriage_certificate" id="doc_marriage_certificate" <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['marriage_certificate'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['marriage_certificate']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['marriage_certificate']['original_filename'] ?? 'marriage_certificate'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['marriage_certificate']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['marriage_certificate']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['marriage_certificate']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Medical Clearance -->
                <div class="col-12 col-md-6">
                    <div class="upload-card">
                        <div class="upload-head">
                            <div class="upload-icon icon-6"><i class="bi bi-file-medical"></i></div>
                            <div class="upload-copy">
                                <h6>Medical Screening Clearance <span class="text-danger">*</span></h6>
                                <small>Includes visual acuity requirements</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_medical_clearance" data-required-doc="true" <?php echo !isset($existingDocs['medical_clearance']) ? 'required' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['medical_clearance'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['medical_clearance']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['medical_clearance']['original_filename'] ?? 'medical_clearance'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['medical_clearance']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['medical_clearance']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['medical_clearance']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2x2 Photo -->
                <div class="col-12 col-md-6">
                    <div class="upload-card">
                        <div class="upload-head">
                            <div class="upload-icon icon-7"><i class="bi bi-person-square"></i></div>
                            <div class="upload-copy">
                                <h6>Recent 2x2 ID Photo <span class="text-danger">*</span></h6>
                                <small>White background, formal attire</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-stretch gap-2 w-100">
                            <label class="upload-button mb-0 flex-grow-1">
                                <input type="file" name="doc_id_photo" accept="image/png, image/jpeg" data-required-doc="true" <?php echo !isset($existingDocs['id_photo']) ? 'required' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                <span><i class="bi bi-upload"></i> Upload File</span>
                            </label>
                            <?php if (isset($existingDocs['id_photo'])): ?>
                                <button type="button" class="btn doc-view-link flex-shrink-0 d-inline-flex align-items-center justify-content-center gap-1" style="border-radius:0.8rem;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.85rem;font-weight:700;padding:0 1rem;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$existingDocs['id_photo']['id']; ?>" data-doc-name="<?php echo htmlspecialchars($existingDocs['id_photo']['original_filename'] ?? 'id_photo'); ?>" data-doc-mime="<?php echo htmlspecialchars($existingDocs['id_photo']['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($existingDocs['id_photo']) && $applicationStatus !== 'draft'): ?>
                            <div class="mt-1">
                                <span class="badge text-uppercase" style="border-radius:999px;font-size:.6rem;font-weight:750;letter-spacing:.04em;background:#e3f4f3;color:#0b7070;border:1px solid #b3dedd;"><?php echo htmlspecialchars($existingDocs['id_photo']['status']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>

            <!-- Submit Section -->
            <?php if (!$isLocked): ?>
                <div class="mb-3 alert alert-warning border-warning-subtle d-flex align-items-start gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ack_submit_without_docs" id="ack_submit_without_docs" value="1" <?php echo (isset($student['ack_submit_without_docs']) && $student['ack_submit_without_docs']) ? 'checked' : ''; ?>>
                    </div>
                    <div>
                        <label for="ack_submit_without_docs" class="form-label mb-1"><strong>I acknowledge that I have not uploaded all required documents and wish to submit my application for review.</strong></label>
                        <div class="small text-muted">By checking this box you acknowledge that you are submitting the application without uploading all required documents. You will be required to present these documents when requested by the Registrar's Office.</div>
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 border-top mt-4">
                    <button type="button" id="btnPreviewApplication" class="btn btn-outline-secondary px-4 fw-bold">
                        <i class="bi bi-eye me-1"></i> Preview My Application
                    </button>
                    <div class="d-flex gap-2">
                        <button type="submit" name="submission_mode" value="save" class="btn btn-brand-secondary px-4 fw-bold shadow-sm">
                            <i class="bi bi-floppy me-1"></i> Save Draft
                        </button>
                        <button type="submit" name="submission_mode" value="submit" class="btn btn-brand-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-send-fill me-1"></i> Submit Application
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- =========================================================================
     APPLICATION PREVIEW MODAL (Read-only summary of all saved data)
     ========================================================================= -->
<?php if ($student): ?>
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:860px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden;">

            <!-- Modal Header -->
            <div class="modal-header border-0 px-5 py-4" style="background:linear-gradient(135deg,#0b4f5c 0%,#0d7a7a 60%,#12a89e 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:.6rem .75rem;">
                        <i class="bi bi-file-person-fill fs-4 text-white"></i>
                    </div>
                    <div>
                        <div style="font-size:.65rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:rgba(255,255,255,.6);">Application Preview</div>
                        <div style="font-size:1.1rem;font-weight:800;color:#fff;line-height:1.2;">NCST Maritime Academy</div>
                        <div style="font-size:.78rem;color:rgba(255,255,255,.7);">Admission Application — <?php echo htmlspecialchars(ucwords(str_replace('_',' ',$applicationStatus))); ?></div>
                    </div>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <div style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:999px;color:#fff;font-size:.7rem;font-weight:700;padding:.3rem .85rem;">
                        #NCST-<?php echo date('Y'); ?>-<?php echo str_pad((int)($student['id'] ?? 1),4,'0',STR_PAD_LEFT); ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="modal-body px-5 py-4" style="background:#f8fafb;">

                <?php
                function previewVal(mixed $v): string {
                    $v = trim((string)$v);
                    return $v !== '' ? htmlspecialchars($v) : '<span class="text-muted">—</span>';
                }

                function previewSection(string $icon, string $title): void {
                    echo '<div class="d-flex align-items-center gap-2 mb-3 mt-4" style="border-left:4px solid #0d7a7a;padding-left:.75rem;">';
                    echo '<i class="bi bi-' . htmlspecialchars($icon) . ' text-brand-primary fs-5"></i>';
                    echo '<h6 class="m-0 fw-bold text-navy" style="font-size:.88rem;letter-spacing:.01em;">' . htmlspecialchars($title) . '</h6>';
                    echo '</div>';
                }

                function previewField(string $label, string $value): void {
                    echo '<div class="col-12 col-sm-6">';
                    echo '<div class="preview-field">';
                    echo '<div class="preview-label">' . htmlspecialchars($label) . '</div>';
                    echo '<div class="preview-value">' . $value . '</div>';
                    echo '</div></div>';
                }
                ?>

                <style>
                    .preview-field { background:#fff; border:1px solid #e5eced; border-radius:10px; padding:.65rem .9rem; height:100%; }
                    .preview-label { color:#7a9499; font-size:.67rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; margin-bottom:.2rem; }
                    .preview-value { color:#143f47; font-size:.88rem; font-weight:600; word-break:break-word; }
                </style>

                <!-- Section 1: Personal Information -->
                <?php previewSection('person-badge', 'Personal Information'); ?>
                <div class="row g-2 mb-1">
                    <?php previewField('First Name', previewVal($student['first_name'] ?? '')); ?>
                    <?php previewField('Middle Name', previewVal($student['middle_name'] ?? '')); ?>
                    <?php previewField('Last Name', previewVal($student['last_name'] ?? '')); ?>
                    <?php previewField('Suffix', previewVal($student['suffix'] ?? '')); ?>
                    <?php previewField('Date of Birth', previewVal($student['birthdate'] ?? '')); ?>
                    <?php previewField('Age', previewVal($student['age'] ?? '')); ?>
                    <?php previewField('Place of Birth', previewVal($student['place_of_birth'] ?? '')); ?>
                    <?php previewField('Gender', previewVal($student['gender'] ?? '')); ?>
                    <?php previewField('Civil Status', previewVal($student['civil_status'] ?? '')); ?>
                    <?php previewField('Nationality', previewVal($student['nationality'] ?? '')); ?>
                    <?php previewField('Religion', previewVal($student['religion'] ?? '')); ?>
                </div>

                <!-- Section 2: Contact & Address -->
                <?php previewSection('geo-alt', 'Contact & Address Information'); ?>
                <div class="row g-2 mb-1">
                    <?php previewField('Email Address', previewVal($userEmail)); ?>
                    <?php previewField('Contact Number', previewVal($student['contact_number'] ?? '')); ?>
                    <?php previewField('Street / Block / Lot', previewVal($student['address_street'] ?? '')); ?>
                    <?php previewField('Barangay', previewVal($student['address_barangay'] ?? '')); ?>
                    <?php previewField('City / Municipality', previewVal($student['address_city'] ?? '')); ?>
                    <?php previewField('Province', previewVal($student['address_province'] ?? '')); ?>
                    <?php previewField('Zip Code', previewVal($student['address_zip_code'] ?? '')); ?>
                </div>

                <!-- Section 3: Emergency / Guardian -->
                <?php previewSection('people-fill', 'Guardian / Emergency Contact'); ?>
                <div class="row g-2 mb-1">
                    <?php previewField('Guardian Name', previewVal($student['guardian_name'] ?? '')); ?>
                    <?php previewField('Relationship', previewVal($student['guardian_relationship'] ?? '')); ?>
                    <?php previewField('Contact Number', previewVal($student['guardian_contact_number'] ?? '')); ?>
                    <?php previewField('Guardian Address', previewVal($student['guardian_address'] ?? '')); ?>
                </div>

                <!-- Section 4: Academic Background -->
                <?php previewSection('book', 'Academic Background'); ?>
                <div class="row g-2 mb-1">
                    <?php previewField('Applicant Type', previewVal($student['applicant_type'] ?? '')); ?>
                    <?php previewField('Year Level', previewVal($student['year_level'] ?? '')); ?>
                    <?php previewField('SHS Track / Strand', previewVal($student['shs_track_strand'] ?? '')); ?>
                    <?php previewField('SHS School Name', previewVal($student['shs_name'] ?? '')); ?>
                    <?php previewField('School Type', previewVal($student['shs_type'] ?? '')); ?>
                    <?php previewField('Year Graduated', previewVal($student['year_graduated'] ?? '')); ?>
                    <?php previewField('General Average', previewVal($student['general_average'] ?? '')); ?>
                </div>

                <!-- Section 5: Program Choice -->
                <?php previewSection('flag', 'Program Choice'); ?>
                <div class="row g-2 mb-1">
                    <div class="col-12">
                        <div class="preview-field" style="border-color:#b3dedd;background:#f0fafa;">
                            <div class="preview-label">Selected Program</div>
                            <div class="preview-value" style="color:#0b6570;"><?php echo previewVal($student['program_applying_for'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Section 6: Uploaded Documents -->
                <?php if (!empty($existingDocs)): ?>
                    <?php previewSection('file-earmark-arrow-up', 'Uploaded Documents'); ?>
                    <div class="row g-2 mb-1">
                        <?php foreach ($existingDocs as $docType => $doc): ?>
                            <div class="col-12 col-sm-6">
                                <div class="d-flex align-items-center gap-2 p-2" style="background:#fff;border:1px solid #e5eced;border-radius:10px;">
                                    <div style="width:36px;height:36px;border-radius:8px;background:#e3f4f3;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <i class="bi bi-file-earmark-check text-brand-primary"></i>
                                    </div>
                                    <div style="min-width:0;flex-grow:1;margin-right:0.5rem;">
                                        <div style="font-size:.67rem;font-weight:700;color:#7a9499;text-transform:uppercase;letter-spacing:.06em;"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$docType))); ?></div>
                                        <div style="font-size:.8rem;font-weight:600;color:#143f47;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
                                    </div>
                                    <button type="button" class="btn btn-sm doc-view-link flex-shrink-0" style="border-radius:999px;border:1.5px solid #0d7a7a;color:#0d7a7a;font-size:.7rem;font-weight:700;padding:.15rem .6rem;line-height:1.2;background:#fff;transition:all .15s;" data-doc-id="<?php echo (int)$doc['id']; ?>" data-doc-name="<?php echo htmlspecialchars($doc['original_filename'] ?? $docType); ?>" data-doc-mime="<?php echo htmlspecialchars($doc['mime_type'] ?? ''); ?>"><i class="bi bi-eye"></i> View</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div><!-- /modal-body -->

            <!-- Modal Footer -->
            <div class="modal-footer border-0 px-5 py-3" style="background:#f0f4f5;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-pencil-square me-1"></i> Edit Application
                </button>
                <?php if (!$isLocked): ?>
                <form action="../actions/enrollee_actions" method="POST" class="d-inline">
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="submission_mode" value="submit">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <button type="submit" class="btn btn-brand-primary px-4 fw-bold">
                        <i class="bi bi-send-fill me-1"></i> Submit Application
                    </button>
                </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
    document.getElementById('btnPreviewApplication') && document.getElementById('btnPreviewApplication').addEventListener('click', function () {
        var modal = new bootstrap.Modal(document.getElementById('previewModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<!-- =========================================================================
     JAVASCRIPT HELPERS
     ========================================================================= -->
<script>
    const hasExistingMarriageCertificate = <?php echo isset($existingDocs['marriage_certificate']) ? 'true' : 'false'; ?>;
    const applicationControls = {
        marriageContainer: document.getElementById('marriage_cert_container'),
        marriageInput: document.getElementById('doc_marriage_certificate'),
        birthdate: document.getElementById('birthdate'),
        age: document.getElementById('age'),
        civilStatus: document.getElementById('civil_status'),
        applicantType: document.getElementById('applicant_type'),
        yearLevel: document.getElementById('year_level'),
        fixedYearLevel: document.getElementById('year_level_fixed'),
        yearLevelValue: document.getElementById('year_level_value'),
        acknowledgement: document.getElementById('ack_submit_without_docs')
    };

    function syncDocumentRequiredState() {
        const allowedWithoutDocs = !!(applicationControls.acknowledgement && applicationControls.acknowledgement.checked);
        document.querySelectorAll('input[type="file"][name^="doc_"]').forEach(function (input) {
            const shouldRequire = input.dataset.requiredDoc === 'true';
            input.required = shouldRequire && !allowedWithoutDocs;
        });
    }

    // Toggle Marriage Certificate input field dynamically.
    function toggleMarriageCertificate(status) {
        const container = applicationControls.marriageContainer;
        const input = applicationControls.marriageInput;
        if (!container || !input) return;

        const isMarried = status === 'Married';
        container.style.display = isMarried ? 'block' : 'none';
        if (isMarried && !hasExistingMarriageCertificate) {
            input.required = true;
        } else if (!isMarried) {
            input.required = false;
        }
    }

    function formatLocalDateInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function calculateLocalAge(birthdateValue) {
        if (!birthdateValue) return null;

        const dob = new Date(birthdateValue + 'T00:00:00');
        if (Number.isNaN(dob.getTime())) return null;

        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const hasBirthdayThisYear = today.getMonth() > dob.getMonth()
            || (today.getMonth() === dob.getMonth() && today.getDate() >= dob.getDate());

        if (!hasBirthdayThisYear) {
            age -= 1;
        }

        return age;
    }

    function validateBirthdateField() {
        const birthdate = applicationControls.birthdate;
        const ageInput = applicationControls.age;
        const errorBox = document.getElementById('birthdate-error');
        if (!birthdate || !ageInput || !errorBox) return true;

        const value = birthdate.value;
        if (!value) {
            birthdate.setCustomValidity('Birthdate is required.');
            errorBox.textContent = 'Birthdate is required.';
            ageInput.value = '';
            return false;
        }

        const dob = new Date(value + 'T00:00:00');
        if (Number.isNaN(dob.getTime())) {
            birthdate.setCustomValidity('Birthdate is invalid.');
            errorBox.textContent = 'Birthdate must be a valid date.';
            ageInput.value = '';
            return false;
        }

        const today = new Date();
        const todayLocal = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const dobLocal = new Date(dob.getFullYear(), dob.getMonth(), dob.getDate());

        if (dobLocal > todayLocal) {
            birthdate.setCustomValidity('Birthdate cannot be in the future.');
            errorBox.textContent = 'Birthdate cannot be in the future.';
            ageInput.value = '';
            return false;
        }

        const age = calculateLocalAge(value);
        if (age === null || age < 15) {
            birthdate.setCustomValidity('Applicant must be at least 15 years old.');
            errorBox.textContent = 'Applicant must be at least 15 years old.';
            ageInput.value = age === null ? '' : String(age);
            return false;
        }

        birthdate.setCustomValidity('');
        errorBox.textContent = 'Birthdate must be a valid past date and the applicant must be at least 15 years old.';
        ageInput.value = String(age);
        return true;
    }

    function updateApplicationAge() {
        const birthdate = applicationControls.birthdate;
        const age = applicationControls.age;
        if (!birthdate || !age) return;

        const minimumAgeDate = new Date();
        minimumAgeDate.setFullYear(minimumAgeDate.getFullYear() - 15);
        birthdate.max = formatLocalDateInput(minimumAgeDate);

        if (!birthdate.value) {
            age.value = '';
            return;
        }

        const dob = new Date(birthdate.value + 'T00:00:00');
        if (Number.isNaN(dob.getTime())) {
            age.value = '';
            return;
        }

        const calculatedAge = calculateLocalAge(birthdate.value);
        age.value = calculatedAge === null ? '' : String(calculatedAge);
        validateBirthdateField();
    }

    // New students use a fixed first-year field; transferees get the year-level dropdown.
    function syncYearLevel(applicantType) {
        const { yearLevel, fixedYearLevel, yearLevelValue } = applicationControls;
        if (!yearLevel || !fixedYearLevel || !yearLevelValue) return;

        const isNewStudent = applicantType === 'New Student';
        if (isNewStudent) {
            yearLevel.value = '1st Year';
            yearLevel.style.display = 'none';
            fixedYearLevel.style.display = 'block';
            fixedYearLevel.value = '1st Year';
            yearLevelValue.value = '1st Year';
        } else {
            yearLevel.style.display = 'block';
            fixedYearLevel.style.display = 'none';
            yearLevelValue.value = yearLevel.value;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const { birthdate, civilStatus, applicantType, yearLevel, yearLevelValue } = applicationControls;

        document.querySelectorAll('input[type="file"]').forEach(function (input) {
            const uploadButton = input.closest('.upload-button');
            if (!uploadButton) return;

            const labelText = uploadButton.querySelector('span');
            if (!labelText) return;

            const defaultText = labelText.innerHTML.trim();

            function syncSelectedFileLabel() {
                if (input.files && input.files.length > 0) {
                    const fileName = input.files[0].name;
                    const shortName = fileName.length > 32 ? fileName.substring(0, 29) + '...' : fileName;
                    labelText.innerHTML = '<i class="bi bi-paperclip"></i> ' + shortName;
                    uploadButton.classList.add('has-file');
                    return;
                }

                labelText.innerHTML = defaultText;
                uploadButton.classList.remove('has-file');
            }

            input.addEventListener('change', syncSelectedFileLabel);
            syncSelectedFileLabel();
        });

        if (birthdate) {
            updateApplicationAge();
            birthdate.addEventListener('change', updateApplicationAge);
        }
        const applicationForm = document.querySelector('form');
        if (applicationForm) {
            applicationForm.addEventListener('submit', function (event) {
                const isValid = validateBirthdateField();
                if (!isValid) {
                    event.preventDefault();
                    event.stopPropagation();
                    applicationForm.reportValidity();
                    return;
                }
                applicationForm.classList.add('was-validated');
            });
        }
        if (applicationControls.acknowledgement) {
            applicationControls.acknowledgement.addEventListener('change', syncDocumentRequiredState);
        }
        syncDocumentRequiredState();
        document.querySelectorAll('input[name="contact_number"], input[name="guardian_contact_number"]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D/g, '').slice(0, 11);
            }, { passive: true });
        });
        if (yearLevel && yearLevelValue) {
            yearLevel.addEventListener('change', function () {
                if (applicantType && applicantType.value === 'Transferee') {
                    yearLevelValue.value = yearLevel.value;
                }
            });
        }
        if (civilStatus) toggleMarriageCertificate(civilStatus.value);
        if (applicantType) syncYearLevel(applicantType.value);

        // ── Smart Address Auto-fill ─────────────────────────────────────────
        initAddressAutofill();
    });

    // =========================================================================
    // PHILIPPINE ADDRESS DATA — Cavite municipalities + barangays + zip codes
    // =========================================================================

    /**
     * Enforce 4-digit numeric-only input on the ZIP code field.
     * Blocks any non-digit keystrokes and strips non-digits on paste/input.
     */
    (function () {
        var zipInput = document.getElementById('address_zip_code');
        if (!zipInput) return;

        zipInput.addEventListener('keydown', function (e) {
            var allowedKeys = ['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'];
            if (allowedKeys.indexOf(e.key) !== -1) return;
            if ((e.ctrlKey || e.metaKey) && ['a','c','v','x'].indexOf(e.key.toLowerCase()) !== -1) return;
            if (!/^\d$/.test(e.key)) {
                e.preventDefault();
            }
        });

        zipInput.addEventListener('input', function () {
            var cleaned = zipInput.value.replace(/\D/g, '').slice(0, 4);
            if (zipInput.value !== cleaned) {
                zipInput.value = cleaned;
            }
        }, { passive: true });
    })();

    function initAddressAutofill() {
        var citySearch  = document.getElementById('address_city_search');
        var cityHidden  = document.getElementById('address_city');
        var resultsBox  = document.getElementById('city_search_results');
        var combobox    = document.getElementById('city_combobox');
        var brgySelect  = document.getElementById('address_barangay');
        var provInput   = document.getElementById('address_province');
        var savedCity   = document.getElementById('saved_address_city');
        var savedBrgy   = document.getElementById('saved_address_barangay');
        var isLocked    = <?php echo $isLocked ? 'true' : 'false'; ?>;

        if (!citySearch || !cityHidden || !resultsBox || !brgySelect) return;

        var allCities      = [];
        var cityByName     = {};
        var currentMatches = [];
        var activeIndex    = -1;

        function escapeHtml(text) {
            return String(text).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        function clearResults() {
            resultsBox.innerHTML = '';
            resultsBox.style.display = 'none';
            currentMatches = [];
            activeIndex = -1;
        }

        function setActiveOption(index) {
            var options = resultsBox.querySelectorAll('button.city-option');
            activeIndex = index;
            options.forEach(function (btn, i) {
                btn.classList.toggle('active', i === activeIndex);
                if (i === activeIndex) btn.scrollIntoView({ block: 'nearest' });
            });
        }

        function selectCity(city) {
            cityHidden.value = city.name;
            citySearch.value = city.name + ' — ' + city.province;
            citySearch.classList.remove('is-invalid');
            if (provInput) provInput.value = city.province;
            clearResults();
            populateBarangays(city.code, '');
            brgySelect.classList.remove('is-invalid', 'is-valid');
        }

        function filterCities(rawQuery) {
            var query = rawQuery.trim().toLowerCase();
            if (!query) { clearResults(); return; }

            var startsWith = [], contains = [];
            allCities.forEach(function (city) {
                var name = city.name.toLowerCase();
                var province = city.province.toLowerCase();
                if (name.indexOf(query) === 0) {
                    startsWith.push(city);
                } else if (name.indexOf(query) !== -1 || province.indexOf(query) !== -1) {
                    contains.push(city);
                }
            });

            renderResults(startsWith.concat(contains).slice(0, 50));
        }

        function renderResults(matches) {
            resultsBox.innerHTML = '';
            currentMatches = matches;
            activeIndex = -1;

            if (!matches.length) {
                var none = document.createElement('div');
                none.className = 'city-option city-option-empty';
                none.textContent = 'No matching city or municipality.';
                resultsBox.appendChild(none);
            } else {
                matches.forEach(function (city) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'city-option';
                    btn.innerHTML = '<span>' + escapeHtml(city.name) + '</span>' +
                                    '<span class="city-option-province">' + escapeHtml(city.province) + '</span>';
                    btn.addEventListener('mousedown', function (e) {
                        e.preventDefault();   // keep focus in the search input
                        selectCity(city);
                    });
                    resultsBox.appendChild(btn);
                });
            }

            resultsBox.style.display = 'block';
        }

        // ── Populate barangay dropdown for a given PSGC code ──────────────────
        function populateBarangays(psgcCode, restoreValue) {
            brgySelect.innerHTML = '';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.disabled = true;
            placeholder.selected = true;
            placeholder.textContent = 'Loading barangays...';
            brgySelect.appendChild(placeholder);
            if (!isLocked) brgySelect.disabled = true;

            if (!psgcCode) {
                placeholder.textContent = 'Select city/municipality first...';
                return;
            }

            fetch('../actions/get_barangays?code=' + encodeURIComponent(psgcCode))
                .then(function (res) {
                    if (!res.ok) throw new Error('Barangay fetch failed: ' + res.status);
                    return res.json();
                })
                .then(function (barangays) {
                    brgySelect.innerHTML = '';

                    if (!Array.isArray(barangays) || barangays.length === 0) {
                        var noData = document.createElement('option');
                        noData.value = '';
                        noData.disabled = true;
                        noData.selected = true;
                        noData.textContent = 'No barangays found for selected city.';
                        brgySelect.appendChild(noData);
                        return;
                    }

                    var blank = document.createElement('option');
                    blank.value = '';
                    blank.disabled = true;
                    if (!restoreValue) blank.selected = true;
                    blank.textContent = 'Select barangay...';
                    brgySelect.appendChild(blank);

                    barangays.forEach(function (brgy) {
                        var opt = document.createElement('option');
                        opt.value = brgy;
                        opt.textContent = brgy;
                        if (brgy === restoreValue) opt.selected = true;
                        brgySelect.appendChild(opt);
                    });

                    if (!isLocked) brgySelect.disabled = false;
                    brgySelect.classList.remove('is-invalid', 'is-valid');
                })
                .catch(function () {
                    // Barangay fetch failed — fallback message shown to user below.
                    brgySelect.innerHTML = '';
                    var errOpt = document.createElement('option');
                    errOpt.value = '';
                    errOpt.disabled = true;
                    errOpt.selected = true;
                    errOpt.textContent = 'Could not load barangays — please refresh.';
                    brgySelect.appendChild(errOpt);
                });
        }

        // ── Load all cities from PSGC JSON and restore saved selection ────────
        fetch('../assets/data/ph/cities.json')
            .then(function (res) {
                if (!res.ok) throw new Error('cities.json fetch failed: ' + res.status);
                return res.json();
            })
            .then(function (cities) {
                cities.sort(function (a, b) {
                    return a.name.localeCompare(b.name, 'en', { sensitivity: 'base' });
                });

                allCities = cities;
                cities.forEach(function (city) { cityByName[city.name] = city; });

                var savedCityVal = savedCity ? savedCity.value : '';
                var savedBrgyVal = savedBrgy ? savedBrgy.value : '';
                if (savedCityVal) {
                    var match = cityByName[savedCityVal];
                    if (match) {
                        citySearch.value = match.name + ' — ' + match.province;
                        if (provInput) provInput.value = match.province;
                        populateBarangays(match.code, savedBrgyVal);
                    } else {
                        // Legacy value not present in the dataset — display it as-is
                        citySearch.value = savedCityVal;
                        if (savedBrgyVal) {
                            brgySelect.innerHTML = '';
                            var opt = document.createElement('option');
                            opt.value = savedBrgyVal;
                            opt.textContent = savedBrgyVal;
                            opt.selected = true;
                            brgySelect.appendChild(opt);
                            if (!isLocked) brgySelect.disabled = false;
                        }
                    }
                }
            })
            .catch(function () {
                // Cities fetch failed — fallback placeholder shown to user below.
                citySearch.placeholder = 'Could not load city list — please refresh.';
            });

        // ── Combobox behavior: typing, keyboard navigation, outside click ─────
        citySearch.addEventListener('input', function () {
            if (isLocked) return;
            cityHidden.value = '';
            filterCities(citySearch.value);
        });

        citySearch.addEventListener('keydown', function (e) {
            if (resultsBox.style.display !== 'block') return;

            var options = resultsBox.querySelectorAll('button.city-option');
            if (e.key === 'ArrowDown' && options.length) {
                e.preventDefault();
                setActiveOption(Math.min(activeIndex + 1, options.length - 1));
            } else if (e.key === 'ArrowUp' && options.length) {
                e.preventDefault();
                setActiveOption(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && currentMatches[activeIndex]) {
                    selectCity(currentMatches[activeIndex]);
                } else if (currentMatches.length === 1) {
                    selectCity(currentMatches[0]);
                }
            } else if (e.key === 'Escape') {
                clearResults();
            }
        });

        document.addEventListener('click', function (e) {
            if (!combobox.contains(e.target)) clearResults();
        });

        // ── Block submit while no city is selected ────────────────────────────
        var applicationFormEl = document.getElementById('applicationForm');
        if (applicationFormEl && !isLocked) {
            applicationFormEl.addEventListener('submit', function (event) {
                if (!cityHidden.value) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    citySearch.classList.add('is-invalid');
                    citySearch.focus();
                }
            });
        }
    }
</script>

<?php
require_once '../includes/footer.php';
?>

<!-- =========================================================================
     DOCUMENT VIEWER MODAL — Premium with zoom in/out/reset
     ========================================================================= -->
<div class="modal fade" id="documentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:92vw;">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">

            <!-- Header toolbar -->
            <div class="modal-header border-0 px-4 py-3" style="background:linear-gradient(135deg,#0b4f5c,#0d7a7a);color:#fff;">
                <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                    <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:.4rem .55rem;flex-shrink:0;">
                        <i class="bi bi-file-earmark-text fs-5"></i>
                    </div>
                    <div class="min-w-0">
                        <div style="font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;opacity:.6;line-height:1;">Document Viewer</div>
                        <div id="docViewerFileName" style="font-size:.9rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px;" title="">—</div>
                    </div>
                </div>

                <!-- Zoom toolbar -->
                <div class="d-flex align-items-center gap-2 me-3 flex-shrink-0">
                    <button type="button" id="docZoomOut" class="btn btn-sm" title="Zoom Out"
                        style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:8px;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;transition:background .15s;">
                        <i class="bi bi-zoom-out"></i>
                    </button>
                    <span id="docZoomLabel" style="font-size:.78rem;font-weight:700;min-width:42px;text-align:center;opacity:.9;">100%</span>
                    <button type="button" id="docZoomIn" class="btn btn-sm" title="Zoom In"
                        style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:8px;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;transition:background .15s;">
                        <i class="bi bi-zoom-in"></i>
                    </button>
                    <button type="button" id="docZoomReset" class="btn btn-sm" title="Reset zoom"
                        style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:8px;padding:.2rem .55rem;font-size:.72rem;font-weight:700;transition:background .15s;">
                        Reset
                    </button>
                    <a id="docDownloadBtn" href="#" download
                        style="background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.28);color:#fff;border-radius:8px;padding:.3rem .7rem;font-size:.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:.35rem;transition:background .15s;">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>

                <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Viewer body -->
            <div class="modal-body p-0" style="background:#ffffff;min-height:75vh;position:relative;display:flex;align-items:flex-start;justify-content:center;overflow:auto;" id="docViewerBody">

                <!-- Image viewer container (shown for images) -->
                <div id="docImageContainer" style="display:none;overflow:auto;width:100%;height:78vh;align-items:center;justify-content:center;display:none;">
                    <img id="docViewerImage" src="" alt="Document preview"
                         style="transform-origin:top center;transition:transform .2s ease;max-width:100%;display:block;margin:auto;border-radius:4px;box-shadow:0 2px 16px rgba(0,0,0,.12);">
                </div>

                <!-- PDF / other iframe viewer (shown for PDFs and other files) -->
                <iframe id="documentViewerFrame" src="about:blank"
                        style="width:100%;height:78vh;border:0;display:none;"
                        title="Document Viewer"></iframe>

                <!-- Loading spinner -->
                <div id="docViewerSpinner" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#6b8387;gap:1rem;">
                    <div class="spinner-border" style="width:2.5rem;height:2.5rem;border-width:3px;color:#b0c8cc;" role="status"></div>
                    <div style="font-size:.8rem;font-weight:600;letter-spacing:.04em;">Loading document…</div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    var ZOOM_STEP = 0.2;
    var ZOOM_MIN  = 0.4;
    var ZOOM_MAX  = 4.0;
    var currentZoom = 1.0;
    var isImage = false;

    var modalEl     = document.getElementById('documentModal');
    var iframe      = document.getElementById('documentViewerFrame');
    var imgEl       = document.getElementById('docViewerImage');
    var imgCon      = document.getElementById('docImageContainer');
    var spinner     = document.getElementById('docViewerSpinner');
    var zoomLabel   = document.getElementById('docZoomLabel');
    var zoomInBtn   = document.getElementById('docZoomIn');
    var zoomOutBtn  = document.getElementById('docZoomOut');
    var zoomReset   = document.getElementById('docZoomReset');
    var fileLabel   = document.getElementById('docViewerFileName');
    var downloadBtn = document.getElementById('docDownloadBtn');

    var bsModal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true });

    /* ── Zoom helpers ──────────────────────────────────────── */
    function applyZoom(level) {
        currentZoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, level));
        zoomLabel.textContent = Math.round(currentZoom * 100) + '%';

        if (isImage) {
            imgEl.style.transform = 'scale(' + currentZoom + ')';
            // Adjust container height so scrollbar appears correctly
            imgEl.style.marginTop = currentZoom > 1 ? '2rem' : 'auto';
        } else {
            // For PDFs: reload iframe with zoom hint (works in most browsers)
            var currentSrc = iframe.getAttribute('data-base-src') || '';
            if (currentSrc) {
                var zoomPct = Math.round(currentZoom * 100);
                iframe.src = currentSrc + '#zoom=' + zoomPct;
            }
        }

        zoomOutBtn.disabled  = (currentZoom <= ZOOM_MIN);
        zoomInBtn.disabled   = (currentZoom >= ZOOM_MAX);
    }

    function resetViewer() {
        currentZoom = 1.0;
        isImage = false;
        spinner.style.display  = 'flex';
        iframe.style.display   = 'none';
        imgCon.style.display   = 'none';
        iframe.src             = 'about:blank';
        iframe.removeAttribute('data-base-src');
        imgEl.src              = '';
        imgEl.style.transform  = 'scale(1)';
        zoomLabel.textContent  = '100%';
        fileLabel.textContent  = '—';
        fileLabel.title        = '';
        downloadBtn.href       = '#';
        zoomInBtn.disabled     = false;
        zoomOutBtn.disabled    = false;
    }

    /* ── Open modal from any .doc-view-link ────────────────── */
    document.querySelectorAll('.doc-view-link').forEach(function (el) {
        el.addEventListener('click', function (ev) {
            ev.preventDefault();

            var docId    = el.getAttribute('data-doc-id');
            var docName  = el.getAttribute('data-doc-name') || 'Document';
            var docMime  = el.getAttribute('data-doc-mime') || '';

            if (!docId) return;

            resetViewer();

            var baseUrl = '../actions/view_document?id=' + encodeURIComponent(docId);

            fileLabel.textContent = docName;
            fileLabel.title       = docName;
            downloadBtn.href      = baseUrl;

            bsModal.show();

            /* Determine if it's an image type we can render natively */
            var imgMimes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','image/bmp'];
            if (imgMimes.indexOf(docMime) !== -1 || /\.(jpe?g|png|gif|webp|svg|bmp)$/i.test(docName)) {
                isImage = true;
                imgEl.onload = function () {
                    spinner.style.display = 'none';
                    imgCon.style.display  = 'flex';
                    imgEl.style.display   = 'block';
                };
                imgEl.onerror = function () {
                    spinner.innerHTML = '<i class="bi bi-exclamation-triangle fs-2" style="opacity:.4;"></i><div style="font-size:.8rem;opacity:.5;">Unable to load image.</div>';
                };
                imgEl.src = baseUrl;
            } else {
                /* PDF or other — use iframe */
                iframe.setAttribute('data-base-src', baseUrl);
                iframe.onload = function () {
                    if (iframe.src !== 'about:blank') {
                        spinner.style.display = 'none';
                        iframe.style.display  = 'block';
                    }
                };
                iframe.src = baseUrl + '#zoom=100';
            }
        });
    });

    /* ── Zoom button events ─────────────────────────────────── */
    zoomInBtn.addEventListener('click',  function () { applyZoom(currentZoom + ZOOM_STEP); });
    zoomOutBtn.addEventListener('click', function () { applyZoom(currentZoom - ZOOM_STEP); });
    zoomReset.addEventListener('click',  function () { applyZoom(1.0); });

    /* Hover glow on toolbar buttons */
    [zoomInBtn, zoomOutBtn, zoomReset].forEach(function(btn) {
        btn.addEventListener('mouseenter', function () { btn.style.background = 'rgba(255,255,255,.28)'; });
        btn.addEventListener('mouseleave', function () { btn.style.background = btn === zoomReset ? 'rgba(255,255,255,.12)' : 'rgba(255,255,255,.15)'; });
    });

    /* ── Mouse‐wheel zoom on image ─────────────────────────── */
    imgCon.addEventListener('wheel', function (e) {
        if (!isImage) return;
        e.preventDefault();
        applyZoom(currentZoom + (e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP));
    }, { passive: false });

    /* ── Keyboard shortcuts ─────────────────────────────────── */
    modalEl.addEventListener('keydown', function (e) {
        if (e.key === '+' || e.key === '=') applyZoom(currentZoom + ZOOM_STEP);
        if (e.key === '-')                   applyZoom(currentZoom - ZOOM_STEP);
        if (e.key === '0')                   applyZoom(1.0);
    });

    /* ── Clean up on close ──────────────────────────────────── */
    modalEl.addEventListener('hidden.bs.modal', function () {
        resetViewer();
    });
})();
</script>

