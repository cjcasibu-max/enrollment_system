<?php
// Buffer output so AJAX responses remain valid JSON even if an included file emits whitespace.
ob_start();
/**
 * Enrollee Actions Processor
 * Handles applicant submissions (apply/update) with file uploads,
 * registrar audits (approve/reject), and document verifications.
 * Secured with proper role-checks, MIME type validation, and database transactions.
 */

require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/notifications.php';
require_once '../includes/document_storage.php';

global $pdo;

if (!($pdo instanceof PDO)) {
    $_SESSION['flash_error'] = 'Database connection is not initialized.';
    header('Location: ../index');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../index");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../enrollee/apply");
    exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

switch ($action) {
    case 'apply':
        // Secured to Enrollee role only
        checkRole(['enrollee']);

        $userId = (int)$_SESSION['user_id'];

        // Preserve only an allowlisted set of scalar form inputs on validation/upload errors
        // so the UI can re-populate fields. Files are not saved in the session.
        $allow = [
            'first_name','middle_name','last_name','suffix','birthdate','age','place_of_birth',
            'gender','civil_status','nationality','religion',
            'address_street','address_barangay','address_city','address_province','address_zip_code','contact_number',
            'guardian_name','guardian_relationship','guardian_contact_number','guardian_address',
            'applicant_type','year_level','shs_track_strand','shs_name','shs_type','year_graduated','general_average',
            'program_applying_for','submission_mode','ack_submit_without_docs'
        ];
        $old = [];
        foreach ($allow as $k) {
            if (isset($_POST[$k]) && (is_scalar($_POST[$k]) || is_null($_POST[$k]))) {
                $old[$k] = $_POST[$k];
            }
        }
        if (!empty($old)) {
            $_SESSION['old_post'] = $old;
        } else {
            unset($_SESSION['old_post']);
        }
        
        // 1. Personal Information inputs
        $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
        $middleName = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : null;
        $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
        $suffix = isset($_POST['suffix']) ? trim($_POST['suffix']) : null;
        $birthdate = isset($_POST['birthdate']) ? trim($_POST['birthdate']) : '';
        // Age is always computed server-side from birthdate — never trusted from POST input (C-08 fix)
        $age = 0;
        $placeOfBirth = isset($_POST['place_of_birth']) ? trim($_POST['place_of_birth']) : '';
        $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
        $civilStatus = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : '';
        $nationality = isset($_POST['nationality']) ? trim($_POST['nationality']) : '';
        $religion = isset($_POST['religion']) ? trim($_POST['religion']) : null;

        // 2. Contact & Address inputs
        $addressStreet = isset($_POST['address_street']) ? trim($_POST['address_street']) : '';
        $addressBarangay = isset($_POST['address_barangay']) ? trim($_POST['address_barangay']) : '';
        $addressCity = isset($_POST['address_city']) ? trim($_POST['address_city']) : '';
        $addressProvince = isset($_POST['address_province']) ? trim($_POST['address_province']) : '';
        $addressZipCode = isset($_POST['address_zip_code']) ? trim($_POST['address_zip_code']) : '';
        $contactNumber = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        
        // Emergency contact block
        $guardianName = isset($_POST['guardian_name']) ? trim($_POST['guardian_name']) : '';
        $guardianRelationship = isset($_POST['guardian_relationship']) ? trim($_POST['guardian_relationship']) : '';
        $guardianContactNumber = isset($_POST['guardian_contact_number']) ? trim($_POST['guardian_contact_number']) : '';
        $guardianAddress = isset($_POST['guardian_address']) ? trim($_POST['guardian_address']) : '';

        // 3. Academic Background inputs
        $shsTrackStrand = isset($_POST['shs_track_strand']) ? trim($_POST['shs_track_strand']) : '';
        $shsName = isset($_POST['shs_name']) ? trim($_POST['shs_name']) : '';
        $shsType = isset($_POST['shs_type']) ? trim($_POST['shs_type']) : '';
        $yearGraduatedInput = isset($_POST['year_graduated']) ? trim($_POST['year_graduated']) : '';
        // Expecting format YYYY-MM. Capture both year and month for precise validation.
        $yearGraduated = 0;
        $yearGraduatedMonth = 0;
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearGraduatedInput, $yearMatches)) {
            $yearGraduated = (int)$yearMatches[1];
            $yearGraduatedMonth = (int)$yearMatches[2];
        }
        $generalAverageInput = isset($_POST['general_average']) ? trim($_POST['general_average']) : '';
        $generalAverage = is_numeric($generalAverageInput) ? (float)$generalAverageInput : 0.00;
        $applicantType = isset($_POST['applicant_type']) ? trim($_POST['applicant_type']) : '';
        $yearLevel = isset($_POST['year_level']) ? trim($_POST['year_level']) : '';
        // Mode 'save' (UI button) and legacy 'review' map to draft; explicit 'submit' or default form submissions map to final submit
        $submissionMode = (isset($_POST['submission_mode']) && in_array($_POST['submission_mode'], ['save', 'review'], true)) ? 'draft' : 'submit';
        $ackSubmitWithoutDocs = !empty($_POST['ack_submit_without_docs']) ? 1 : 0;
        $targetStatus = $submissionMode === 'draft' ? 'draft' : 'pending';

        // 4. Program Choice input
        $programApplyingFor = isset($_POST['program_applying_for']) ? trim($_POST['program_applying_for']) : '';

        // Validation - Core Required Fields
        if ($submissionMode === 'submit') {
            if (empty($firstName) || empty($lastName) || empty($birthdate) || empty($placeOfBirth) || 
                empty($gender) || empty($civilStatus) || empty($nationality) || 
                empty($addressStreet) || empty($addressBarangay) || empty($addressCity) || empty($addressProvince) || empty($addressZipCode) ||
                empty($contactNumber) || empty($guardianName) || empty($guardianRelationship) || empty($guardianContactNumber) || empty($guardianAddress) ||
                empty($shsTrackStrand) || empty($shsName) || empty($shsType) || empty($yearGraduated) || empty($generalAverage) ||
                empty($programApplyingFor) || empty($applicantType) || empty($yearLevel)) {
                
                $_SESSION['flash_error'] = "All mandatory fields are required to submit the application.";
                header("Location: ../enrollee/apply");
                exit;
            }
        } else {
            if (empty($firstName) || empty($lastName)) {
                $_SESSION['flash_error'] = "First Name and Last Name are required to save a draft.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        // Validate program choice matches candidates
        if ($submissionMode === 'submit' || !empty($programApplyingFor)) {
            $allowedPrograms = ['Bachelor of Science in Marine Engineering (BSMarE)', 'Bachelor of Science in Marine Transportation (BSMT)'];
            if (!in_array($programApplyingFor, $allowedPrograms)) {
                $_SESSION['flash_error'] = "Invalid academic program selected.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($applicantType)) {
            if (!in_array($applicantType, ['New Student', 'Transferee'])) {
                $_SESSION['flash_error'] = "Please select a valid applicant type.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($yearLevel)) {
            $allowedYearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
            if (!empty($yearLevel) && !in_array($yearLevel, $allowedYearLevels)) {
                $_SESSION['flash_error'] = "Please enter a valid year level.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        // New students always enter as first year. Only transferees may choose a higher year level.
        if ($applicantType === 'New Student') {
            $yearLevel = '1st Year';
        }

        if ($submissionMode === 'submit' || !empty($gender)) {
            $allowedGenders = ['Male', 'Female', 'Other'];
            if (!in_array($gender, $allowedGenders)) {
                $_SESSION['flash_error'] = "Invalid gender selected.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($civilStatus)) {
            $allowedCivilStatuses = ['Single', 'Married', 'Widowed', 'Separated'];
            if (!in_array($civilStatus, $allowedCivilStatuses)) {
                $_SESSION['flash_error'] = "Invalid civil status selected.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        // Religion: optional, but if provided enforce length and whitelist to match client options
        if ($submissionMode === 'submit' || !empty($religion)) {
            $religionLength = function_exists('mb_strlen') ? mb_strlen($religion, 'UTF-8') : strlen($religion);
            if (!empty($religion) && $religionLength > 50) {
                $_SESSION['flash_error'] = "Religion must not exceed 50 characters.";
                header("Location: ../enrollee/apply");
                exit;
            }

            $allowedReligions = ['Roman Catholic', 'Protestant', 'Islam', 'Iglesia ni Cristo', 'Other'];
            if (!empty($religion) && !in_array($religion, $allowedReligions, true)) {
                $_SESSION['flash_error'] = "Invalid religion selected.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($shsType)) {
            $allowedShsTypes = ['Public', 'Private'];
            if (!in_array($shsType, $allowedShsTypes)) {
                $_SESSION['flash_error'] = "Invalid SHS school type selected.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($birthdate)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) || !DateTimeImmutable::createFromFormat('Y-m-d', $birthdate)) {
                $_SESSION['flash_error'] = "Please enter a valid birthdate.";
                header("Location: ../enrollee/apply");
                exit;
            }

            $birthdateObj = DateTimeImmutable::createFromFormat('Y-m-d', $birthdate);
            $today = new DateTimeImmutable('today');

            if ($birthdateObj === false || $birthdateObj > $today) {
                $_SESSION['flash_error'] = "Birthdate must be a past date.";
                header("Location: ../enrollee/apply");
                exit;
            }

            $age = (int)$birthdateObj->diff($today)->y;
            $birthdateMonth = (int)$birthdateObj->format('n');
            $birthdateDay = (int)$birthdateObj->format('j');
            $todayMonth = (int)$today->format('n');
            $todayDay = (int)$today->format('j');
            if ($todayMonth < $birthdateMonth || ($todayMonth === $birthdateMonth && $todayDay < $birthdateDay)) {
                $age -= 1;
            }

            if ($age < 15) {
                $_SESSION['flash_error'] = "Applicants must be at least 15 years old to apply.";
                header("Location: ../enrollee/apply");
                exit;
            }

            // Server computes authoritative age from birthdate and does not reject
            // the submission based on the client-provided `age` field. The client
            // `age` input is for UX only and will be overwritten by server value.
        }

        if ($submissionMode === 'submit' || !empty($contactNumber) || !empty($guardianContactNumber)) {
            if ((!empty($contactNumber) && !preg_match('/^09\d{9}$/', $contactNumber)) || (!empty($guardianContactNumber) && !preg_match('/^09\d{9}$/', $guardianContactNumber))) {
                $_SESSION['flash_error'] = "Contact numbers must be valid 11-digit Philippine mobile numbers beginning with 09.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($yearGraduatedInput)) {
            $currentYear = (int)date('Y');
            $currentMonth = (int)date('n');
            if ($yearGraduated < 1900 || $yearGraduated > $currentYear) {
                $_SESSION['flash_error'] = "Please select a valid graduation month and year.";
                header("Location: ../enrollee/apply");
                exit;
            }
            if ($yearGraduatedMonth < 1 || $yearGraduatedMonth > 12) {
                $_SESSION['flash_error'] = "Please select a valid graduation month.";
                header("Location: ../enrollee/apply");
                exit;
            }

            $graduationMonthDate = DateTimeImmutable::createFromFormat('!Y-m', sprintf('%04d-%02d', $yearGraduated, $yearGraduatedMonth));
            $currentMonthDate = DateTimeImmutable::createFromFormat('!Y-m', sprintf('%04d-%02d', $currentYear, $currentMonth));

            if ($graduationMonthDate === false || $currentMonthDate === false) {
                $_SESSION['flash_error'] = "Please select a valid graduation month and year.";
                header("Location: ../enrollee/apply");
                exit;
            }

            if ($graduationMonthDate > $currentMonthDate) {
                $_SESSION['flash_error'] = "Graduation month cannot be in the future.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        if ($submissionMode === 'submit' || !empty($generalAverageInput)) {
            if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $generalAverageInput) || $generalAverage < 70 || $generalAverage > 100) {
                $_SESSION['flash_error'] = "General average must be between 70.00 and 100.00.";
                header("Location: ../enrollee/apply");
                exit;
            }
        }

        // Track files to delete on physical error rollback
        $uploadedFilesToClean = [];

        try {
            // Check if profile exists and if it is locked
            $checkStmt = $pdo->prepare("SELECT id, academic_term_id, application_status, enrollment_status FROM students WHERE user_id = :user_id LIMIT 1");
            $checkStmt->execute(['user_id' => $userId]);
            $existing = $checkStmt->fetch();

            if ($existing && !in_array($existing['application_status'], ['draft', 'needs_revision'], true)) {
                $_SESSION['flash_error'] = "Your submitted application is locked. It can only be edited after the Registrar requests revisions.";
                header("Location: ../enrollee/status");
                exit;
            }

            $programCode = null;
            if (strpos($programApplyingFor, 'BSMarE') !== false) {
                $programCode = 'BSMarE';
            } elseif (strpos($programApplyingFor, 'BSMT') !== false) {
                $programCode = 'BSMT';
            }

            // Begin Transaction
            $pdo->beginTransaction();

            $activeTerm = requireActiveAcademicTerm($pdo);

            $studentId = 0;
            // Legacy compound address for backward compatibility
            $compoundAddress = trim("$addressStreet, $addressBarangay, $addressCity, $addressProvince, $addressZipCode");

            if ($existing) {
                // Update profile, reset status to pending
                $studentId = (int)$existing['id'];

                $currentEnr = $existing['enrollment_status'] ?? 'draft';
                $advancedStates = ['section_chosen', 'walk_in_ready', 'paid', 'enrolled', 'approved', 'rejected'];
                $newEnrollmentStatus = in_array($currentEnr, $advancedStates, true)
                    ? $currentEnr
                    : $targetStatus;

                $updateStmt = $pdo->prepare("
                    UPDATE students 
                    SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, suffix = :suffix,
                        birthdate = :birthdate, place_of_birth = :place_of_birth, gender = :gender, civil_status = :civil_status,
                        nationality = :nationality, religion = :religion, contact_number = :contact_number,
                        address_street = :address_street, address_barangay = :address_barangay, address_city = :address_city,
                        address_province = :address_province, address_zip_code = :address_zip_code,
                        guardian_name = :guardian_name, guardian_relationship = :guardian_relationship, guardian_contact_number = :guardian_contact_number,
                        guardian_address = :guardian_address, shs_track_strand = :shs_track_strand, shs_name = :shs_name,
                        shs_type = :shs_type, year_graduated = :year_graduated, general_average = :general_average,
                        program_applying_for = :program_applying_for, program_code = :program_code, applicant_type = :applicant_type, age = :age,
                        year_level = :year_level, ack_submit_without_docs = :ack_submit_without_docs,
                        revision_notes = :revision_notes, academic_term_id = :academic_term_id,
                        application_status = :application_status, admission_status = :admission_status, enrollment_status = :enrollment_status
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'suffix' => $suffix,
                    'birthdate' => $birthdate,
                    'place_of_birth' => $placeOfBirth,
                    'gender' => $gender,
                    'civil_status' => $civilStatus,
                    'nationality' => $nationality,
                    'religion' => $religion,
                    'contact_number' => $contactNumber,
                    'address_street' => $addressStreet,
                    'address_barangay' => $addressBarangay,
                    'address_city' => $addressCity,
                    'address_province' => $addressProvince,
                    'address_zip_code' => $addressZipCode,
                    'guardian_name' => $guardianName,
                    'guardian_relationship' => $guardianRelationship,
                    'guardian_contact_number' => $guardianContactNumber,
                    'guardian_address' => $guardianAddress,
                    'shs_track_strand' => $shsTrackStrand,
                    'shs_name' => $shsName,
                    'shs_type' => $shsType,
                    'year_graduated' => $yearGraduated,
                    'general_average' => $generalAverage,
                    'program_applying_for' => $programApplyingFor,
                    'program_code' => $programCode,
                    'applicant_type' => $applicantType,
                    'age' => $age,
                    'year_level' => $yearLevel,
                    'ack_submit_without_docs' => $ackSubmitWithoutDocs,
                    'revision_notes' => (($targetStatus === 'pending') ? null : ($existing['revision_notes'] ?? null)),
                    'academic_term_id' => $existing['academic_term_id'] ?: $activeTerm['id'],
                    'application_status' => $targetStatus,
                    'admission_status' => $targetStatus,
                    'enrollment_status' => $newEnrollmentStatus,
                    'id' => $studentId
                ]);

                if ($targetStatus === 'pending' && $existing['application_status'] !== 'pending') {
                    createNotification($pdo, $userId, 'Application under review', 'Your admission application was resubmitted and is now under review by the Registrar\'s Office.', 'info');
                }
            } else {
                // Insert profile (new enrollee profile fallback)
                $insertStmt = $pdo->prepare("
                    INSERT INTO students (
                        user_id, first_name, middle_name, last_name, suffix, birthdate, place_of_birth, gender, civil_status,
                        nationality, religion, contact_number, address_street, address_barangay, address_city,
                        address_province, address_zip_code, guardian_name, guardian_relationship,
                        guardian_contact_number, guardian_address, shs_track_strand, shs_name, shs_type, year_graduated,
                        general_average, program_applying_for, program_code, applicant_type, age, year_level, ack_submit_without_docs,
                        revision_notes, academic_term_id, application_status, admission_status, enrollment_status
                    ) VALUES (
                        :user_id, :first_name, :middle_name, :last_name, :suffix, :birthdate, :place_of_birth, :gender, :civil_status,
                        :nationality, :religion, :contact_number, :address_street, :address_barangay, :address_city,
                        :address_province, :address_zip_code, :guardian_name, :guardian_relationship,
                        :guardian_contact_number, :guardian_address, :shs_track_strand, :shs_name, :shs_type, :year_graduated,
                        :general_average, :program_applying_for, :program_code, :applicant_type, :age, :year_level, :ack_submit_without_docs,
                        :revision_notes, :academic_term_id, :application_status, :admission_status, :enrollment_status
                    )
                ");
                $insertStmt->execute([
                    'user_id' => $userId,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'suffix' => $suffix,
                    'birthdate' => $birthdate,
                    'place_of_birth' => $placeOfBirth,
                    'gender' => $gender,
                    'civil_status' => $civilStatus,
                    'nationality' => $nationality,
                    'religion' => $religion,
                    'contact_number' => $contactNumber,
                    'address_street' => $addressStreet,
                    'address_barangay' => $addressBarangay,
                    'address_city' => $addressCity,
                    'address_province' => $addressProvince,
                    'address_zip_code' => $addressZipCode,
                    'guardian_name' => $guardianName,
                    'guardian_relationship' => $guardianRelationship,
                    'guardian_contact_number' => $guardianContactNumber,
                    'guardian_address' => $guardianAddress,
                    'shs_track_strand' => $shsTrackStrand,
                    'shs_name' => $shsName,
                    'shs_type' => $shsType,
                    'year_graduated' => $yearGraduated,
                    'general_average' => $generalAverage,
                    'program_applying_for' => $programApplyingFor,
                    'program_code' => $programCode,
                    'applicant_type' => $applicantType,
                    'age' => $age,
                    'year_level' => $yearLevel,
                    'ack_submit_without_docs' => $ackSubmitWithoutDocs,
                    'revision_notes' => null,
                    'academic_term_id' => $activeTerm['id'],
                    'application_status' => $targetStatus,
                    'admission_status' => $targetStatus,
                    'enrollment_status' => $targetStatus
                ]);
                $studentId = (int)$pdo->lastInsertId();
                if ($targetStatus === 'pending') {
                    createNotification($pdo, $userId, 'Application under review', 'Your admission application was submitted and is now under review by the Registrar\'s Office.', 'info');
                }
            }

            // -----------------------------------------------------------------
            // DOCUMENT UPLOADS PROCESSING (Section 4c)
            // -----------------------------------------------------------------
            $docTypes = ['form_137', 'shs_diploma', 'good_moral', 'birth_certificate', 'marriage_certificate', 'medical_clearance', 'id_photo'];

            // Pre-check: determine whether any required document is present (uploaded now or already on file).
            $anyDocsPresent = false;
            foreach ($docTypes as $dt) {
                if ($dt === 'marriage_certificate' && $civilStatus !== 'Married') {
                    continue;
                }
                $inputName = 'doc_' . $dt;
                if (isset($_FILES[$inputName]) && isset($_FILES[$inputName]['error']) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
                    $anyDocsPresent = true;
                    break;
                }
            }

            if (!$anyDocsPresent) {
                // Check DB for already uploaded documents
                $docCheckStmt = $pdo->prepare("SELECT id FROM documents WHERE student_id = :student_id AND document_type = :doc_type LIMIT 1");
                foreach ($docTypes as $dt) {
                    if ($dt === 'marriage_certificate' && $civilStatus !== 'Married') {
                        continue;
                    }
                    $docCheckStmt->execute(['student_id' => $studentId, 'doc_type' => $dt]);
                    if ($docCheckStmt->fetch()) {
                        $anyDocsPresent = true;
                        break;
                    }
                }
            }

            // If submitting and there are no documents present, require the acknowledgement checkbox.
            if ($submissionMode === 'submit' && !$anyDocsPresent) {
                if (empty($_POST['ack_submit_without_docs'])) {
                    $_SESSION['flash_error'] = "You must either upload all required documents or acknowledge that you are submitting without them.";
                    header("Location: ../enrollee/apply");
                    exit;
                }
            }

            // Store uploads in a structured folder hierarchy:
            //   private_uploads/student_documents/{student_id}/{document_type}/{file}
            // This keeps each student's documents isolated and easy to audit.
            $candidateRoots = getDocumentStorageRoots();
            $storageRoot = null;
            foreach ($candidateRoots as $candidateRoot) {
                $studentFolder = $candidateRoot . '/' . $studentId;
                if (!is_dir($studentFolder) && !@mkdir($studentFolder, 0755, true) && !is_dir($studentFolder)) {
                    continue;
                }
                if (is_writable($studentFolder)) {
                    $storageRoot = $candidateRoot;
                    break;
                }
            }

            if ($storageRoot === null) {
                throw new \RuntimeException('Document storage directory is not available for uploads. Please ensure the web server can write to the upload folder.');
            }

            // Protect the student root with .htaccess
            $htaccessPath = $storageRoot . '/.htaccess';
            if (!file_exists($htaccessPath)) {
                file_put_contents($htaccessPath, "Options -Indexes\nDeny from all\nRequire all denied\n");
            }

            foreach ($docTypes as $docType) {
                // Skip marriage certificate if civil status is NOT Married
                if ($docType === 'marriage_certificate' && $civilStatus !== 'Married') {
                    continue;
                }

                // Check if document already exists in DB
                $docCheckStmt = $pdo->prepare("SELECT id, file_path FROM documents WHERE student_id = :student_id AND document_type = :doc_type LIMIT 1");
                $docCheckStmt->execute(['student_id' => $studentId, 'doc_type' => $docType]);
                $existingDoc = $docCheckStmt->fetch();

                $inputName = 'doc_' . $docType;
                $fileUploaded = isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK;

                // If no new file uploaded for this doc, skip processing (existingDoc remains)
                if (!$fileUploaded) {
                    continue;
                }

                $file = $_FILES[$inputName];

                // Validate File Size: Max 5MB
                $maxSize = 5 * 1024 * 1024;
                if ($file['size'] > $maxSize) {
                    throw new \RuntimeException("Document " . strtoupper(str_replace('_', ' ', $docType)) . " exceeds maximum file size of 5MB.");
                }

                // Validate MIME Type (PDF / JPG / PNG)
                $mimeType = '';
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = (string)$finfo->file($file['tmp_name']);
                } elseif (function_exists('mime_content_type')) {
                    $mimeType = (string)mime_content_type($file['tmp_name']);
                } else {
                    // Fallback when Fileinfo is unavailable: infer by extension to avoid fatal errors.
                    $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                    $extMap = [
                        'pdf' => 'application/pdf',
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png'
                    ];
                    $mimeType = $extMap[$ext] ?? '';
                }

                // Accept common JPEG MIME variants (some clients report 'image/jpg' or 'image/pjpeg').
                $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'image/pjpeg'];
                if ($docType === 'id_photo') {
                    // ID photo strictly JPG/PNG (no PDF)
                    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/pjpeg'];
                }

                if (!in_array($mimeType, $allowedMimeTypes)) {
                    $errorText = ($docType === 'id_photo') ? "Recent ID photo must be a JPG or PNG image." : "Uploaded document format is invalid. Only PDF, JPG, and PNG are allowed.";
                    throw new \RuntimeException($errorText);
                }

                // Generate a non-guessable filename and derive the extension from actual detected MIME data,
                // never from the user-controlled original filename.
                $originalFilename = basename($file['name']);
                $extMap = [
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/jpg' => 'jpg',
                    'image/pjpeg' => 'jpg',
                    'image/png' => 'png'
                ];
                $extension = $extMap[$mimeType] ?? 'dat';

                $randomToken = bin2hex(random_bytes(16));
                $newFilename = $randomToken . '_' . $docType . '.' . strtolower($extension);

                // Build per-student per-doctype folder: {storageRoot}/{studentId}/{docType}/
                $docTypeFolder = $storageRoot . '/' . $studentId . '/' . $docType;
                if (!is_dir($docTypeFolder) && !@mkdir($docTypeFolder, 0755, true) && !is_dir($docTypeFolder)) {
                    throw new \RuntimeException("Failed to create storage folder for document type: " . $docType);
                }

                $targetPath = $docTypeFolder . '/' . $newFilename;

                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    throw new \RuntimeException("Failed to save uploaded file: " . $originalFilename);
                }

                // Track successfully uploaded file to clean in case of SQL exception
                $uploadedFilesToClean[] = $targetPath;

                if ($existingDoc) {
                    // Delete old physical file
                    $oldFilePath = $existingDoc['file_path'];
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }

                    // Update DB record, reset status to pending on replacement
                    $docUpdateStmt = $pdo->prepare("
                        UPDATE documents 
                        SET file_path = :file_path, original_filename = :original_filename, status = 'pending' 
                        WHERE id = :id
                    ");
                    $docUpdateStmt->execute([
                        'file_path' => $targetPath,
                        'original_filename' => $originalFilename,
                        'id' => $existingDoc['id']
                    ]);
                } else {
                    // Insert DB record
                    $docInsertStmt = $pdo->prepare("
                        INSERT INTO documents (student_id, document_type, file_path, original_filename, status) 
                        VALUES (:student_id, :document_type, :file_path, :original_filename, 'pending')
                    ");
                    $docInsertStmt->execute([
                        'student_id' => $studentId,
                        'document_type' => $docType,
                        'file_path' => $targetPath,
                        'original_filename' => $originalFilename
                    ]);
                }
            }

            // Sync session
            $_SESSION['student_id'] = $studentId;
            $_SESSION['enrollment_status'] = $newEnrollmentStatus ?? $targetStatus;

            $pdo->commit();
            // Clear preserved inputs on successful save/submit so the form no longer re-populates.
            if (isset($_SESSION['old_post'])) {
                unset($_SESSION['old_post']);
            }
            $_SESSION['flash_success'] = $submissionMode === 'draft'
                ? "Draft saved successfully. You can continue editing anytime."
                : "Application submitted successfully.";
            header("Location: " . ($submissionMode === 'draft' ? '../enrollee/apply' : '../enrollee/status'));
            exit;

        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($uploadedFilesToClean as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            $_SESSION['flash_error'] = "Submission Failed: " . $e->getMessage();
            header("Location: ../enrollee/apply");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($uploadedFilesToClean as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            error_log("Apply extended action failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "An unexpected error occurred. Please try again.";
            header("Location: ../enrollee/apply");
            exit;
        }
        break;

    case 'submit_application':
        require_once '../includes/auth_check.php';
        checkRole(['enrollee']);

        try {
            $studentStmt = $pdo->prepare("SELECT id, civil_status, application_status, enrollment_status, ack_submit_without_docs FROM students WHERE user_id = :user_id LIMIT 1");
            $studentStmt->execute(['user_id' => (int)$_SESSION['user_id']]);
            $student = $studentStmt->fetch();
            if (!$student || !in_array($student['application_status'], ['draft', 'needs_revision'], true)) {
                throw new \RuntimeException('Save your application for review before submitting it.');
            }

            $required = ['form_137', 'shs_diploma', 'good_moral', 'birth_certificate', 'medical_clearance', 'id_photo'];
            if ($student['civil_status'] === 'Married') {
                $required[] = 'marriage_certificate';
            }
            $docStmt = $pdo->prepare("SELECT document_type, status FROM documents WHERE student_id = :student_id");
            $docStmt->execute(['student_id' => $student['id']]);
            $docs = $docStmt->fetchAll();
            $uploadedByType = [];
            foreach ($docs as $doc) {
                if ($doc['status'] !== 'rejected') {
                    $uploadedByType[$doc['document_type']] = $doc['status'];
                }
            }

            $missingRequiredDocs = false;
            foreach ($required as $type) {
                if (!isset($uploadedByType[$type]) || $uploadedByType[$type] === 'rejected') {
                    $missingRequiredDocs = true;
                    break;
                }
            }

            if ($missingRequiredDocs && empty($student['ack_submit_without_docs'])) {
                throw new \RuntimeException('All required documents must be uploaded and accepted before submission.');
            }

            $currentEnr = $student['enrollment_status'] ?? 'draft';
            $advancedStates = ['section_chosen', 'walk_in_ready', 'paid', 'enrolled', 'approved', 'rejected'];
            $newEnrollmentStatus = in_array($currentEnr, $advancedStates, true) ? $currentEnr : 'pending';

            $submitStmt = $pdo->prepare("UPDATE students SET application_status = 'pending', admission_status = 'pending', enrollment_status = :enr_status, revision_notes = NULL WHERE id = :id");
            $submitStmt->execute(['enr_status' => $newEnrollmentStatus, 'id' => $student['id']]);
            createNotification($pdo, (int)$_SESSION['user_id'], 'Application under review', 'Your admission application was submitted and is now under review by the Registrar\'s Office.', 'info');
            $_SESSION['application_status'] = 'pending';
            $_SESSION['admission_status'] = 'pending';
            $_SESSION['enrollment_status'] = $newEnrollmentStatus;
            $_SESSION['flash_success'] = 'Application submitted successfully and is now awaiting review.';
            header('Location: ../enrollee/status');
            exit;
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: ../enrollee/review');
            exit;
        } catch (\Throwable $e) {
            error_log('Submit application failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
            header('Location: ../enrollee/review');
            exit;
        }

    case 'approve':
        // Secured to Registrar and Admin roles only
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);

        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $registrarOverride = !empty($_POST['registrar_override_missing_docs']) ? 1 : 0;

        if (empty($studentId)) {
            $_SESSION['flash_error'] = "Student ID is required.";
            header("Location: ../registrar/enrollee_applications");
            exit;
        }

        try {
            // Fetch student row to get associated user ID and verify status
            $studentStmt = $pdo->prepare("SELECT user_id, civil_status, application_status, enrollment_status, walk_in_notes FROM students WHERE id = :id LIMIT 1");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();

            if (!$student) {
                $_SESSION['flash_error'] = "Application record not found.";
                header("Location: ../registrar/enrollee_applications");
                exit;
            }

            if (!in_array($student['application_status'], ['pending', 'under_review'], true)) {
                $_SESSION['flash_error'] = "Only pending applications can be approved.";
                header("Location: ../registrar/enrollee_applications");
                exit;
            }

            // Ensure all required documents are verified (or not rejected)
            // Note: Registrars verify documents before approving overall application.
            $docsStmt = $pdo->prepare("SELECT document_type, status FROM documents WHERE student_id = :student_id");
            $docsStmt->execute(['student_id' => $studentId]);
            $docs = $docsStmt->fetchAll();

            $requiredDocuments = ['form_137', 'shs_diploma', 'good_moral', 'birth_certificate', 'medical_clearance', 'id_photo'];
            if ($student['civil_status'] === 'Married') {
                $requiredDocuments[] = 'marriage_certificate';
            }
            $documentStatuses = [];
            foreach ($docs as $doc) {
                $documentStatuses[$doc['document_type']] = $doc['status'];
            }
            $hasMissingRequiredDocument = false;
            foreach ($requiredDocuments as $documentType) {
                if (($documentStatuses[$documentType] ?? null) !== 'verified') {
                    $hasMissingRequiredDocument = true;
                    break;
                }
            }

            if ($hasMissingRequiredDocument && !$registrarOverride) {
                $_SESSION['flash_error'] = "Cannot approve application. Every required credential must be uploaded and verified first.";
                header("Location: ../registrar/enrollee_applications");
                exit;
            }

            $associatedUserId = (int)$student['user_id'];

            // Perform transaction: admission approval is separate from payment and student activation.
            $pdo->beginTransaction();

            // 1. Mark applicant as eligible to choose a section.
            //    They remain an 'enrollee' until payment is validated.
            if ($hasMissingRequiredDocument && $registrarOverride) {
                $overrideUsername = $_SESSION['username'] ?? 'registrar';
                $overrideUserId = $_SESSION['user_id'] ?? 'unknown';
                $timestamp = date('Y-m-d H:i:s');
                $auditEntry = "[{$timestamp}] Conditional admission authorized by registrar {$overrideUsername} (User ID #{$overrideUserId}) with unverified credentials. Physical documents to be presented at walk-in.";
                $existingNotes = trim($student['walk_in_notes'] ?? '');
                $newNotes = $existingNotes === '' ? $auditEntry : ($existingNotes . "\n" . $auditEntry);

                $statusUpdateStmt = $pdo->prepare("UPDATE students SET application_status = 'eligible_to_enroll', admission_status = 'approved', enrollment_status = 'pending', walk_in_notes = :walk_in_notes WHERE id = :id");
                $statusUpdateStmt->execute(['walk_in_notes' => $newNotes, 'id' => $studentId]);
            } else {
                $statusUpdateStmt = $pdo->prepare("UPDATE students SET application_status = 'eligible_to_enroll', admission_status = 'approved', enrollment_status = 'pending' WHERE id = :id");
                $statusUpdateStmt->execute(['id' => $studentId]);
            }

            createNotification($pdo, $associatedUserId, 'Application Approved', 'Congratulations! Your admission application has been approved. You may now log in and choose your class section to proceed with enrollment.', 'success');

            $pdo->commit();

            $_SESSION['flash_success'] = $hasMissingRequiredDocument
                ? "Application approved. The applicant is now eligible to choose a section. Follow-up documents must be presented during walk-in validation."
                : "Application approved. The applicant is now eligible to choose their class section.";
            header("Location: ../registrar/enrollee_applications");
            exit;

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Registrar approval failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to approve application.";
            header("Location: ../registrar/enrollee_applications");
            exit;
        }
        break;

    case 'reject':
        // Secured to Registrar and Admin roles only
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);

        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

        if (empty($studentId)) {
            $_SESSION['flash_error'] = "Student ID is required.";
            header("Location: ../registrar/enrollee_applications");
            exit;
        }

        try {
            // Verify application is awaiting a registrar decision
            $checkStmt = $pdo->prepare("SELECT user_id, application_status, enrollment_status FROM students WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $studentId]);
            $student = $checkStmt->fetch();

            if (!$student || !in_array($student['application_status'], ['pending', 'under_review'], true)) {
                $_SESSION['flash_error'] = "Only applications awaiting review can be rejected.";
                header("Location: ../registrar/enrollee_applications");
                exit;
            }

            // Update status to 'rejected'
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE students SET application_status = 'rejected', admission_status = 'rejected', enrollment_status = 'rejected' WHERE id = :id");
            $stmt->execute(['id' => $studentId]);
            createNotification($pdo, (int)$student['user_id'], 'Application rejected', 'Your admission application was rejected. Please contact the Registrar\'s Office for more information.', 'danger');
            $pdo->commit();

            $_SESSION['flash_success'] = "Application has been rejected.";
            header("Location: ../registrar/enrollee_applications");
            exit;

        } catch (\PDOException $e) {
            error_log("Registrar rejection failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to reject application.";
            header("Location: ../registrar/enrollee_applications");
            exit;
        }
        break;

    case 'verify_document':
        // Secured to Registrar and Admin roles only
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);

        $documentId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
        $status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        // This action is used exclusively by the same-page document-review modal.
        // Always return JSON; redirecting after a successful UPDATE caused fetch()
        // to receive the registrar HTML page and report a false save failure.
        $jsonResponse = function (array $payload, int $httpCode = 200): void {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        };

        if (empty($documentId) || !in_array($status, ['verified', 'rejected'], true)) {
            $jsonResponse([
                'success' => false,
                'message' => 'Invalid parameter arguments for verification.'
            ], 400);
        }

        if ($status === 'rejected' && $rejectionReason === '') {
            $jsonResponse([
                'success' => false,
                'message' => 'A rejection reason is required.'
            ], 422);
        }

        try {
            $documentStmt = $pdo->prepare(
                "SELECT d.student_id, s.application_status
                 FROM documents d
                 JOIN students s ON s.id = d.student_id
                 WHERE d.id = :id LIMIT 1"
            );
            $documentStmt->execute(['id' => $documentId]);
            $document = $documentStmt->fetch();
            if (!$document || !in_array($document['application_status'], ['pending', 'under_review'], true)) {
                $jsonResponse(['success' => false, 'message' => 'Only applications awaiting review can have documents verified.'], 422);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE documents SET status = :status, rejection_reason = :rejection_reason WHERE id = :id");
            $stmt->execute([
                'status' => $status,
                'rejection_reason' => $status === 'rejected' ? $rejectionReason : null,
                'id' => $documentId
            ]);
            $reviewStmt = $pdo->prepare("UPDATE students SET application_status = 'under_review' WHERE id = :student_id AND application_status = 'pending'");
            $reviewStmt->execute(['student_id' => $document['student_id']]);
            $pdo->commit();

            $text = ($status === 'verified') ? "verified" : "rejected";
            $_SESSION['flash_success'] = "Document status updated to " . $text . ".";
            $jsonResponse([
                'success' => true,
                'status' => $status,
                'message' => "Document status updated to " . $text . "."
            ]);

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Verify document status failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to verify document.";
            $jsonResponse([
                'success' => false,
                'message' => 'Database error: Failed to verify document.'
            ], 500);
        }
        break;

    case 'verify_all_documents':
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        try {
            $studentStmt = $pdo->prepare("SELECT application_status FROM students WHERE id = :id LIMIT 1");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();
            if (!$student || !in_array($student['application_status'], ['pending', 'under_review'], true)) {
                throw new \RuntimeException('Only applications awaiting review can have documents verified.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE documents SET status = 'verified' WHERE student_id = :student_id AND status != 'rejected'");
            $stmt->execute(['student_id' => $studentId]);
            $reviewStmt = $pdo->prepare("UPDATE students SET application_status = 'under_review' WHERE id = :id");
            $reviewStmt->execute(['id' => $studentId]);
            $pdo->commit();
            $_SESSION['flash_success'] = 'All non-rejected uploaded documents were marked as verified.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Verify all documents failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error: Failed to verify all documents.';
        }
        header('Location: ../registrar/enrollee_applications');
        exit;

    case 'verify_selected_documents':
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);

        $documentIds = $_POST['document_ids'] ?? [];
        $selectedDocumentIds = [];
        if (is_array($documentIds)) {
            foreach ($documentIds as $documentId) {
                $cleanId = (int)$documentId;
                if ($cleanId > 0) {
                    $selectedDocumentIds[] = $cleanId;
                }
            }
        }

        try {
            if (empty($selectedDocumentIds)) {
                throw new \RuntimeException('Select at least one document to verify.');
            }

            $placeholders = implode(',', array_fill(0, count($selectedDocumentIds), '?'));
            $studentStmt = $pdo->prepare("SELECT DISTINCT student_id, s.application_status FROM documents d JOIN students s ON s.id = d.student_id WHERE d.id IN ($placeholders) LIMIT 1");
            $studentStmt->execute($selectedDocumentIds);
            $student = $studentStmt->fetch();
            if (!$student || !in_array($student['application_status'], ['pending', 'under_review'], true)) {
                throw new \RuntimeException('Only applications awaiting review can have documents verified.');
            }

            $pdo->beginTransaction();
            $verifyStmt = $pdo->prepare("UPDATE documents SET status = 'verified', rejection_reason = NULL WHERE id IN ($placeholders) AND status != 'rejected'");
            $verifyStmt->execute($selectedDocumentIds);
            $reviewStmt = $pdo->prepare("UPDATE students SET application_status = 'under_review' WHERE id = :id");
            $reviewStmt->execute(['id' => (int)$student['student_id']]);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Selected documents were marked as verified.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Verify selected documents failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error: Failed to verify selected documents.';
        }
        header('Location: ../registrar/enrollee_applications');
        exit;

    case 'request_edits':
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);
        $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $revisionNotes = trim((string)($_POST['revision_notes'] ?? ''));
        try {
            $studentStmt = $pdo->prepare("SELECT user_id, application_status FROM students WHERE id = :id LIMIT 1");
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();
            if (!$student || !in_array($student['application_status'], ['pending', 'under_review'], true)) {
                throw new \RuntimeException('Only applications awaiting review can be returned for edits.');
            }
            if ($revisionNotes === '') {
                throw new \RuntimeException('Please provide revision notes so the applicant knows what to correct.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE students SET application_status = 'needs_revision', enrollment_status = 'needs_revision', revision_notes = :revision_notes WHERE id = :id AND application_status IN ('pending', 'under_review')");
            $stmt->execute(['id' => $studentId, 'revision_notes' => $revisionNotes]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                throw new \RuntimeException('Only applications awaiting review can be returned for edits.');
            }
            $notificationText = 'The Registrar\'s Office returned your admission application for edits. Review your application and resubmit it after making the requested corrections: ' . $revisionNotes;
            createNotification($pdo, (int)$student['user_id'], 'Edits requested', $notificationText, 'warning');
            $pdo->commit();
            $_SESSION['flash_success'] = 'The applicant can now edit and resubmit the application.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Request edits failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error: Failed to return application for edits.';
        }
        header('Location: ../registrar/enrollee_applications');
        exit;

    case 'add_remark':
        require_once '../includes/auth_check.php';
        checkRole(['registrar', 'admin']);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $remark = trim($_POST['remark'] ?? '');
        try {
            if ($studentId <= 0 || $remark === '') {
                throw new RuntimeException('A recipient and a remark are required.');
            }
            if (mb_strlen($remark) > 2000) {
                throw new RuntimeException('Remarks may not exceed 2,000 characters.');
            }
            $studentStmt = $pdo->prepare('SELECT user_id FROM students WHERE id = :id LIMIT 1');
            $studentStmt->execute(['id' => $studentId]);
            $student = $studentStmt->fetch();
            if (!$student) {
                throw new RuntimeException('Application record not found.');
            }
            $pdo->beginTransaction();
            $remarkStmt = $pdo->prepare('INSERT INTO application_remarks (student_id, author_id, remark) VALUES (:student_id, :author_id, :remark)');
            $remarkStmt->execute(['student_id' => $studentId, 'author_id' => (int)$_SESSION['user_id'], 'remark' => $remark]);
            createNotification($pdo, (int)$student['user_id'], 'Registrar remark', 'The Registrar\'s Office left a remark on your admission application. Open your application status to review it.', 'info');
            $pdo->commit();
            $_SESSION['flash_success'] = 'Registrar remark saved and sent to the applicant.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Application remark failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Database error: Failed to save registrar remark.';
        }
        header('Location: ../registrar/enrollee_applications');
        exit;

    default:
        $_SESSION['flash_error'] = "Invalid action specified.";
        header("Location: ../index");
        exit;
}
