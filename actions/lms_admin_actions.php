<?php
/**
 * LMS Admin Actions Processor
 * Handles user management, role assignments, activation/deactivation,
 * and teacher subject assignments for the LMS.
 * 
 * Strict Admin-only access. Full audit logging.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/lms_access.php';

// Strict Admin Access Check
$adminUser = requireLmsAdminAccess();
$adminUserId = (int)$adminUser['user_id'];

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../admin/lms_users");
    exit;
}

// CSRF Validation
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../admin/lms_users");
    exit;
}

$action = trim($_POST['action'] ?? '');
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || (isset($_POST['response_type']) && $_POST['response_type'] === 'json');

function sendResponse(bool $success, string $message, array $extra = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit;
    }
    if ($success) {
        $_SESSION['flash_success'] = $message;
    } else {
        $_SESSION['flash_error'] = $message;
    }
    $redirect = $_POST['redirect_to'] ?? '../admin/lms_users.php';
    header("Location: " . $redirect);
    exit;
}

switch ($action) {
    case 'create_user':
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = trim($_POST['role'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $programCode = trim($_POST['program_code'] ?? 'BSMT');
        $yearLevel   = trim($_POST['year_level'] ?? '1st Year');

        // Allow all four LMS roles
        $allowedRoles = ['student', 'teacher', 'registrar', 'admin'];
        if (!in_array($role, $allowedRoles, true)) {
            sendResponse(false, "Invalid role. Allowed roles are: Student, Teacher, Registrar, Admin.");
        }

        if (empty($username) || empty($email) || empty($password)) {
            sendResponse(false, "Username, email, and password are required.");
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            sendResponse(false, "Username must be 3-50 characters and contain only letters, numbers, dots, hyphens, or underscores.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, "Please provide a valid email address.");
        }

        if (strlen($password) < 6) {
            sendResponse(false, "Password must be at least 6 characters long.");
        }

        try {
            // Check uniqueness
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
            $checkStmt->execute(['username' => $username, 'email' => $email]);
            if ($checkStmt->fetchColumn() > 0) {
                sendResponse(false, "Username or email is already in use by another account.");
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, created_at, updated_at)
                VALUES (:username, :email, :password_hash, :role, :first_name, :last_name, 1, NOW(), NOW())
            ");
            $insertStmt->execute([
                'username'      => $username,
                'email'         => $email,
                'password_hash' => $passwordHash,
                'role'          => $role,
                'first_name'    => $firstName ?: null,
                'last_name'     => $lastName ?: null,
            ]);
            $newUserId = (int)$pdo->lastInsertId();

            // If creating a student, synchronize a record in the students table
            if ($role === 'student') {
                // Find active academic term
                $termStmt = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
                $activeTermId = $termStmt->fetchColumn() ?: null;

                $studentStmt = $pdo->prepare("
                    INSERT INTO students (user_id, academic_term_id, first_name, last_name, program_code, program_applying_for, year_level, enrollment_status)
                    VALUES (:user_id, :term_id, :first_name, :last_name, :program_code, :program_name, :year_level, 'enrolled')
                ");
                $programName = ($programCode === 'BSMarE') ? 'Bachelor of Science in Marine Engineering' : 'Bachelor of Science in Marine Transportation';
                $studentStmt->execute([
                    'user_id'      => $newUserId,
                    'term_id'      => $activeTermId,
                    'first_name'   => $firstName ?: $username,
                    'last_name'    => $lastName ?: 'Student',
                    'program_code' => $programCode,
                    'program_name' => $programName,
                    'year_level'   => $yearLevel
                ]);
            }

            // Log Admin Audit Record
            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_USER_CREATE',
                'user',
                $newUserId,
                "Admin created {$role} account '{$username}' ({$firstName} {$lastName}) for LMS access."
            );

            $pdo->commit();
            sendResponse(true, "User account '{$username}' created successfully as " . ucfirst($role) . ".");

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("LMS Admin create_user error: " . $e->getMessage());
            sendResponse(false, "Database error creating user: " . $e->getMessage());
        }
        break;

    case 'edit_user':
        $userId    = (int)($_POST['user_id'] ?? 0);
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = trim($_POST['role'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($userId <= 0) {
            sendResponse(false, "Invalid user identifier.");
        }

        $allowedRoles = ['student', 'teacher', 'registrar', 'admin'];
        if (!in_array($role, $allowedRoles, true)) {
            sendResponse(false, "Invalid role. Allowed roles are: Student, Teacher, Registrar, Admin.");
        }

        if (empty($username) || empty($email)) {
            sendResponse(false, "Username and email are required.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, "Please provide a valid email address.");
        }

        try {
            // Check uniqueness of username and email excluding current user
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = :username OR email = :email) AND id != :id");
            $checkStmt->execute(['username' => $username, 'email' => $email, 'id' => $userId]);
            if ($checkStmt->fetchColumn() > 0) {
                sendResponse(false, "Username or email is already taken by another account.");
            }

            $pdo->beginTransaction();

            if (!empty($password)) {
                if (strlen($password) < 6) {
                    sendResponse(false, "Password must be at least 6 characters long.");
                }
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, email = :email, role = :role, 
                        first_name = :first_name, last_name = :last_name, 
                        password_hash = :hash, updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'username'   => $username,
                    'email'      => $email,
                    'role'       => $role,
                    'first_name' => $firstName ?: null,
                    'last_name'  => $lastName ?: null,
                    'hash'       => password_hash($password, PASSWORD_DEFAULT),
                    'id'         => $userId
                ]);
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, email = :email, role = :role, 
                        first_name = :first_name, last_name = :last_name, updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'username'   => $username,
                    'email'      => $email,
                    'role'       => $role,
                    'first_name' => $firstName ?: null,
                    'last_name'  => $lastName ?: null,
                    'id'         => $userId
                ]);
            }

            // If user has a student record, update their name there as well
            $updateStudentStmt = $pdo->prepare("
                UPDATE students 
                SET first_name = :first_name, last_name = :last_name
                WHERE user_id = :user_id
            ");
            $updateStudentStmt->execute([
                'first_name' => $firstName ?: $username,
                'last_name'  => $lastName ?: 'Student',
                'user_id'    => $userId
            ]);

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_USER_UPDATE',
                'user',
                $userId,
                "Admin updated account #{$userId} '{$username}' (Role: {$role}, Name: {$firstName} {$lastName})."
            );

            $pdo->commit();
            sendResponse(true, "User account #{$userId} ('{$username}') updated successfully.");

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("LMS Admin edit_user error: " . $e->getMessage());
            sendResponse(false, "Database error updating user: " . $e->getMessage());
        }
        break;

    case 'toggle_status':
        $userId = (int)($_POST['user_id'] ?? 0);
        $status = isset($_POST['status']) ? (int)$_POST['status'] : null;

        if ($userId <= 0) {
            sendResponse(false, "Invalid user identifier.");
        }

        // Prevent admin from deactivating themselves
        if ($userId === $adminUserId) {
            sendResponse(false, "Security protection: You cannot deactivate your own active admin session.");
        }

        try {
            $userStmt = $pdo->prepare("SELECT id, username, role, is_active FROM users WHERE id = :id");
            $userStmt->execute(['id' => $userId]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                sendResponse(false, "User account not found.");
            }

            $newStatus = ($status !== null) ? ($status === 1 ? 1 : 0) : ((int)$targetUser['is_active'] === 1 ? 0 : 1);

            $updateStmt = $pdo->prepare("UPDATE users SET is_active = :status, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute(['status' => $newStatus, 'id' => $userId]);

            $actionWord = ($newStatus === 1) ? 'Reactivated' : 'Deactivated';
            $auditNote = "Admin {$actionWord} user #{$userId} '{$targetUser['username']}' (Role: {$targetUser['role']}).";
            if ($targetUser['role'] === 'teacher' && $newStatus === 0) {
                $auditNote .= " Teacher LMS access revoked; historical coursework, materials, and grades preserved.";
            }

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                ($newStatus === 1 ? 'LMS_USER_REACTIVATE' : 'LMS_USER_DEACTIVATE'),
                'user',
                $userId,
                $auditNote
            );

            sendResponse(true, "User account '{$targetUser['username']}' has been {$actionWord} successfully.");

        } catch (Throwable $e) {
            error_log("LMS Admin toggle_status error: " . $e->getMessage());
            sendResponse(false, "Database error toggling user status: " . $e->getMessage());
        }
        break;

    case 'assign_teacher_subject':
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $sectionSubjectId = (int)($_POST['section_subject_id'] ?? 0);

        if ($teacherId <= 0 || $sectionSubjectId <= 0) {
            sendResponse(false, "Teacher ID and Section Subject ID are both required.");
        }

        try {
            // Verify teacher exists and has role 'teacher'
            $teacherStmt = $pdo->prepare("SELECT id, username, first_name, last_name, role, is_active FROM users WHERE id = :id");
            $teacherStmt->execute(['id' => $teacherId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

            if (!$teacher || $teacher['role'] !== 'teacher') {
                sendResponse(false, "Target user is not a valid teacher.");
            }

            // Verify section_subject exists
            $ssStmt = $pdo->prepare("
                SELECT ss.id, ss.subject_id, ss.section_id, sub.subject_code, sub.subject_name, sec.section_name
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                JOIN sections sec ON sec.id = ss.section_id
                WHERE ss.id = :ss_id
            ");
            $ssStmt->execute(['ss_id' => $sectionSubjectId]);
            $sectionSubject = $ssStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sectionSubject) {
                sendResponse(false, "Section subject record not found.");
            }

            // Update instructor_id
            $updateStmt = $pdo->prepare("UPDATE section_subjects SET instructor_id = :teacher_id WHERE id = :id");
            $updateStmt->execute(['teacher_id' => $teacherId, 'id' => $sectionSubjectId]);

            $teacherName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')) ?: $teacher['username'];
            $description = "Assigned teacher {$teacherName} (#{$teacherId}) to subject {$sectionSubject['subject_code']} ({$sectionSubject['section_name']}).";

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_TEACHER_ASSIGN',
                'section_subject',
                $sectionSubjectId,
                $description
            );

            sendResponse(true, "Teacher {$teacherName} assigned to {$sectionSubject['subject_code']} - {$sectionSubject['section_name']}.");

        } catch (Throwable $e) {
            error_log("LMS Admin assign_teacher_subject error: " . $e->getMessage());
            sendResponse(false, "Database error assigning teacher: " . $e->getMessage());
        }
        break;

    case 'unassign_teacher_subject':
        $sectionSubjectId = (int)($_POST['section_subject_id'] ?? 0);

        if ($sectionSubjectId <= 0) {
            sendResponse(false, "Section Subject ID is required.");
        }

        try {
            $ssStmt = $pdo->prepare("
                SELECT ss.id, ss.instructor_id, sub.subject_code, sec.section_name, u.username
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                JOIN sections sec ON sec.id = ss.section_id
                LEFT JOIN users u ON u.id = ss.instructor_id
                WHERE ss.id = :ss_id
            ");
            $ssStmt->execute(['ss_id' => $sectionSubjectId]);
            $sectionSubject = $ssStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sectionSubject) {
                sendResponse(false, "Section subject record not found.");
            }

            $updateStmt = $pdo->prepare("UPDATE section_subjects SET instructor_id = NULL WHERE id = :id");
            $updateStmt->execute(['id' => $sectionSubjectId]);

            $description = "Unassigned instructor from {$sectionSubject['subject_code']} ({$sectionSubject['section_name']}).";

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_TEACHER_UNASSIGN',
                'section_subject',
                $sectionSubjectId,
                $description
            );

            sendResponse(true, "Instructor removed from {$sectionSubject['subject_code']} - {$sectionSubject['section_name']}.");

        } catch (Throwable $e) {
            error_log("LMS Admin unassign_teacher_subject error: " . $e->getMessage());
            sendResponse(false, "Database error unassigning teacher: " . $e->getMessage());
        }
        break;

    case 'get_teacher_assignments':
        $teacherId = (int)($_GET['teacher_id'] ?? ($_POST['teacher_id'] ?? 0));
        if ($teacherId <= 0) {
            sendResponse(false, "Teacher ID is required.");
        }

        try {
            $stmt = $pdo->prepare("
                SELECT ss.id AS section_subject_id, sub.subject_code, sub.subject_name, sub.units,
                       sec.section_name, sec.program, sec.year_level, at.school_year, at.semester
                FROM section_subjects ss
                JOIN sections sec ON sec.id = ss.section_id
                JOIN subjects sub ON sub.id = ss.subject_id
                LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
                WHERE ss.instructor_id = :teacher_id
                ORDER BY at.school_year DESC, at.semester, sub.subject_code
            ");
            $stmt->execute(['teacher_id' => $teacherId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'assignments' => $assignments]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        break;

    case 'create_program':
        $programCode = strtoupper(trim($_POST['program_code'] ?? ''));
        $programName = trim($_POST['program_name'] ?? '');

        if (empty($programCode) || empty($programName)) {
            sendResponse(false, "Program code and program name are required.");
        }

        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $programCode)) {
            sendResponse(false, "Program code must be 2-20 alphanumeric characters (e.g. BSMT, BSMarE).");
        }

        try {
            $checkStmt = $pdo->prepare("SELECT id FROM programs WHERE program_code = :code LIMIT 1");
            $checkStmt->execute(['code' => $programCode]);
            if ($checkStmt->fetch()) {
                sendResponse(false, "Academic program code '{$programCode}' already exists.");
            }

            $stmt = $pdo->prepare("INSERT INTO programs (program_code, program_name, created_at, updated_at) VALUES (:code, :name, NOW(), NOW())");
            $stmt->execute(['code' => $programCode, 'name' => $programName]);
            $newProgId = (int)$pdo->lastInsertId();

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_PROGRAM_CREATE',
                'program',
                $newProgId,
                "Admin created academic program '{$programCode}' - {$programName}"
            );

            sendResponse(true, "Academic program '{$programCode}' created successfully.");
        } catch (Throwable $e) {
            error_log("LMS Admin create_program error: " . $e->getMessage());
            sendResponse(false, "Database error creating program: " . $e->getMessage());
        }
        break;

    case 'create_subject':
        $programId    = (int)($_POST['program_id'] ?? 0);
        $subjectCode  = strtoupper(trim($_POST['subject_code'] ?? ''));
        $subjectName  = trim($_POST['subject_name'] ?? '');
        $units        = (float)($_POST['units'] ?? 3.0);
        $subjectType  = trim($_POST['subject_type'] ?? 'Professional');
        $yearLevel    = trim($_POST['year_level'] ?? '1st Year');
        $semesterName = trim($_POST['semester_name'] ?? '1st Semester');
        $description  = trim($_POST['description'] ?? '');

        if (empty($subjectCode) || empty($subjectName) || $programId <= 0) {
            sendResponse(false, "Program, Subject Code, and Subject Title are required.");
        }

        if ($units <= 0 || $units > 12) {
            sendResponse(false, "Units must be between 0.5 and 12.0.");
        }

        try {
            // Verify program exists
            $progStmt = $pdo->prepare("SELECT id, program_code FROM programs WHERE id = :id LIMIT 1");
            $progStmt->execute(['id' => $programId]);
            $prog = $progStmt->fetch(PDO::FETCH_ASSOC);
            if (!$prog) {
                sendResponse(false, "Selected academic program does not exist.");
            }

            // Check duplicate subject code
            $dupStmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = :code LIMIT 1");
            $dupStmt->execute(['code' => $subjectCode]);
            if ($dupStmt->fetch()) {
                sendResponse(false, "Subject code '{$subjectCode}' is already registered.");
            }

            // Find curriculum ID for this program if available
            $currStmt = $pdo->prepare("SELECT id FROM curriculums WHERE program_id = :pid AND is_active = 1 LIMIT 1");
            $currStmt->execute(['pid' => $programId]);
            $curriculumId = $currStmt->fetchColumn() ?: null;

            $insertStmt = $pdo->prepare("
                INSERT INTO subjects (program_id, curriculum_id, subject_code, subject_name, units, subject_type, year_level, semester_name, description, status, created_at)
                VALUES (:pid, :cid, :code, :name, :units, :type, :year, :sem, :desc, 'active', NOW())
            ");
            $insertStmt->execute([
                'pid'   => $programId,
                'cid'   => $curriculumId,
                'code'  => $subjectCode,
                'name'  => $subjectName,
                'units' => $units,
                'type'  => $subjectType,
                'year'  => $yearLevel,
                'sem'   => $semesterName,
                'desc'  => $description ?: null
            ]);
            $newSubjectId = (int)$pdo->lastInsertId();

            // Link to curriculum_subjects if curriculum exists
            if ($curriculumId) {
                $csStmt = $pdo->prepare("
                    INSERT INTO curriculum_subjects (curriculum_id, subject_id, year_level, semester, units, is_required, display_order, subject_type)
                    VALUES (:cid, :sid, :year, :sem, :units, 1, 99, :type)
                ");
                $csStmt->execute([
                    'cid'   => $curriculumId,
                    'sid'   => $newSubjectId,
                    'year'  => $yearLevel,
                    'sem'   => $semesterName,
                    'units' => $units,
                    'type'  => $subjectType
                ]);
            }

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_SUBJECT_CREATE',
                'subject',
                $newSubjectId,
                "Admin created subject '{$subjectCode}' ({$subjectName}) for {$prog['program_code']}"
            );

            sendResponse(true, "Subject '{$subjectCode} - {$subjectName}' created successfully.");
        } catch (Throwable $e) {
            error_log("LMS Admin create_subject error: " . $e->getMessage());
            sendResponse(false, "Database error creating subject: " . $e->getMessage());
        }
        break;

    case 'edit_subject':
        $subjectId    = (int)($_POST['subject_id'] ?? 0);
        $subjectCode  = strtoupper(trim($_POST['subject_code'] ?? ''));
        $subjectName  = trim($_POST['subject_name'] ?? '');
        $units        = (float)($_POST['units'] ?? 3.0);
        $subjectType  = trim($_POST['subject_type'] ?? 'Professional');
        $yearLevel    = trim($_POST['year_level'] ?? '1st Year');
        $semesterName = trim($_POST['semester_name'] ?? '1st Semester');
        $description  = trim($_POST['description'] ?? '');
        $status       = trim($_POST['status'] ?? 'active');

        if ($subjectId <= 0 || empty($subjectCode) || empty($subjectName)) {
            sendResponse(false, "Subject ID, Code, and Title are required.");
        }

        try {
            // Check subject exists
            $checkStmt = $pdo->prepare("SELECT id, subject_code FROM subjects WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $subjectId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                sendResponse(false, "Subject record not found.");
            }

            // Check duplicate code excluding current
            $dupStmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = :code AND id != :id LIMIT 1");
            $dupStmt->execute(['code' => $subjectCode, 'id' => $subjectId]);
            if ($dupStmt->fetch()) {
                sendResponse(false, "Subject code '{$subjectCode}' is already in use by another subject.");
            }

            $updateStmt = $pdo->prepare("
                UPDATE subjects 
                SET subject_code = :code, subject_name = :name, units = :units,
                    subject_type = :type, year_level = :year, semester_name = :sem,
                    description = :desc, status = :status
                WHERE id = :id
            ");
            $updateStmt->execute([
                'code'   => $subjectCode,
                'name'   => $subjectName,
                'units'  => $units,
                'type'   => $subjectType,
                'year'   => $yearLevel,
                'sem'    => $semesterName,
                'desc'   => $description ?: null,
                'status' => in_array($status, ['active', 'inactive'], true) ? $status : 'active',
                'id'     => $subjectId
            ]);

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_SUBJECT_UPDATE',
                'subject',
                $subjectId,
                "Admin updated subject #{$subjectId} '{$subjectCode}' ({$subjectName})"
            );

            sendResponse(true, "Subject '{$subjectCode}' updated successfully.");
        } catch (Throwable $e) {
            error_log("LMS Admin edit_subject error: " . $e->getMessage());
            sendResponse(false, "Database error updating subject: " . $e->getMessage());
        }
        break;

    case 'delete_subject':
        $subjectId = (int)($_POST['subject_id'] ?? 0);

        if ($subjectId <= 0) {
            sendResponse(false, "Subject ID is required.");
        }

        try {
            $checkStmt = $pdo->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $subjectId]);
            $sub = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub) {
                sendResponse(false, "Subject not found.");
            }

            // 1. Check prerequisite dependencies
            $depCheck = $pdo->prepare("
                SELECT s.subject_code 
                FROM subject_prerequisites sp 
                JOIN subjects s ON s.id = sp.subject_id 
                WHERE sp.prerequisite_subject_id = :id
            ");
            $depCheck->execute(['id' => $subjectId]);
            $deps = $depCheck->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($deps)) {
                sendResponse(false, "Cannot delete '{$sub['subject_code']}': It is required as a prerequisite by: " . implode(', ', $deps) . ". Deactivate the subject instead.");
            }

            // 2. Check active student enrollments
            $enrCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM section_subjects ss 
                JOIN enrollments e ON e.section_id = ss.section_id 
                WHERE ss.subject_id = :id AND e.status = 'enrolled'
            ");
            $enrCheck->execute(['id' => $subjectId]);
            if ((int)$enrCheck->fetchColumn() > 0) {
                sendResponse(false, "Cannot delete '{$sub['subject_code']}': Students are actively enrolled in sections offering this subject. Please deactivate the subject instead.");
            }

            // 3. Check historical grades
            $gradeCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM student_grades sg 
                JOIN section_subjects ss ON ss.id = sg.section_subject_id 
                WHERE ss.subject_id = :id
            ");
            $gradeCheck->execute(['id' => $subjectId]);
            if ((int)$gradeCheck->fetchColumn() > 0) {
                sendResponse(false, "Cannot delete '{$sub['subject_code']}': Academic grade records are attached to this subject. Deactivate the subject to maintain historical records integrity.");
            }

            // 4. Check LMS materials, assignments, or quizzes
            $lmsCheck = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM lms_materials lm JOIN section_subjects ss ON ss.id = lm.section_subject_id WHERE ss.subject_id = :id1) +
                    (SELECT COUNT(*) FROM lms_assignments la JOIN section_subjects ss ON ss.id = la.section_subject_id WHERE ss.subject_id = :id2) +
                    (SELECT COUNT(*) FROM lms_quizzes lq JOIN section_subjects ss ON ss.id = lq.section_subject_id WHERE ss.subject_id = :id3) AS lms_items
            ");
            $lmsCheck->execute(['id1' => $subjectId, 'id2' => $subjectId, 'id3' => $subjectId]);
            if ((int)$lmsCheck->fetchColumn() > 0) {
                sendResponse(false, "Cannot delete '{$sub['subject_code']}': Active LMS coursework (materials/assignments/quizzes) exists for this subject. Please deactivate it instead.");
            }

            $pdo->beginTransaction();

            // Clear prerequisites and section_subjects
            $pdo->prepare("DELETE FROM subject_prerequisites WHERE subject_id = :id")->execute(['id' => $subjectId]);
            $pdo->prepare("DELETE FROM curriculum_subjects WHERE subject_id = :id")->execute(['id' => $subjectId]);
            $pdo->prepare("DELETE FROM section_subjects WHERE subject_id = :id")->execute(['id' => $subjectId]);
            $pdo->prepare("DELETE FROM subjects WHERE id = :id")->execute(['id' => $subjectId]);

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_SUBJECT_DELETE',
                'subject',
                $subjectId,
                "Admin deleted subject '{$sub['subject_code']}' - {$sub['subject_name']}"
            );

            $pdo->commit();
            sendResponse(true, "Subject '{$sub['subject_code']}' deleted successfully.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("LMS Admin delete_subject error: " . $e->getMessage());
            sendResponse(false, "Database error deleting subject: " . $e->getMessage());
        }
        break;

    case 'toggle_subject_status':
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            sendResponse(false, "Subject ID is required.");
        }

        try {
            $stmt = $pdo->prepare("SELECT id, subject_code, status FROM subjects WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $subjectId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub) {
                sendResponse(false, "Subject not found.");
            }

            $newStatus = ($sub['status'] === 'active') ? 'inactive' : 'active';
            $updateStmt = $pdo->prepare("UPDATE subjects SET status = :status WHERE id = :id");
            $updateStmt->execute(['status' => $newStatus, 'id' => $subjectId]);

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_SUBJECT_STATUS_TOGGLE',
                'subject',
                $subjectId,
                "Admin changed status of subject '{$sub['subject_code']}' to " . ucfirst($newStatus)
            );

            sendResponse(true, "Subject '{$sub['subject_code']}' is now " . ucfirst($newStatus) . ".");
        } catch (Throwable $e) {
            error_log("LMS Admin toggle_subject_status error: " . $e->getMessage());
            sendResponse(false, "Database error updating subject status: " . $e->getMessage());
        }
        break;

    case 'create_section_offering':
        $sectionId   = (int)($_POST['section_id'] ?? 0);
        $subjectId   = (int)($_POST['subject_id'] ?? 0);
        $teacherId   = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $dayOfWeek   = trim($_POST['day_of_week'] ?? 'MWF');
        $startTime   = trim($_POST['start_time'] ?? '08:00:00');
        $endTime     = trim($_POST['end_time'] ?? '10:00:00');
        $room        = trim($_POST['room'] ?? 'Room 301');

        if ($sectionId <= 0 || $subjectId <= 0) {
            sendResponse(false, "Section and Subject are required.");
        }

        try {
            // Verify section and subject exist
            $secStmt = $pdo->prepare("SELECT id, section_name, program, year_level FROM sections WHERE id = :id LIMIT 1");
            $secStmt->execute(['id' => $sectionId]);
            $sec = $secStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sec) {
                sendResponse(false, "Selected section does not exist.");
            }

            $subStmt = $pdo->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE id = :id LIMIT 1");
            $subStmt->execute(['id' => $subjectId]);
            $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sub) {
                sendResponse(false, "Selected subject does not exist.");
            }

            // Check duplicate
            $dupStmt = $pdo->prepare("SELECT id FROM section_subjects WHERE section_id = :sec_id AND subject_id = :sub_id LIMIT 1");
            $dupStmt->execute(['sec_id' => $sectionId, 'sub_id' => $subjectId]);
            if ($dupStmt->fetch()) {
                sendResponse(false, "This subject is already offered in section '{$sec['section_name']}'.");
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO section_subjects (section_id, subject_id, instructor_id, day_of_week, start_time, end_time, room, created_at, updated_at)
                VALUES (:sec_id, :sub_id, :inst_id, :day, :start, :end, :room, NOW(), NOW())
            ");
            $insertStmt->execute([
                'sec_id'  => $sectionId,
                'sub_id'  => $subjectId,
                'inst_id' => $teacherId,
                'day'     => $dayOfWeek,
                'start'   => $startTime,
                'end'     => $endTime,
                'room'    => $room
            ]);
            $newSsId = (int)$pdo->lastInsertId();

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_SECTION_OFFERING_CREATE',
                'section_subject',
                $newSsId,
                "Admin added offering for {$sub['subject_code']} in section {$sec['section_name']}" . ($teacherId ? " (Teacher #{$teacherId})" : "")
            );

            sendResponse(true, "Section offering created: {$sub['subject_code']} in {$sec['section_name']}.");
        } catch (Throwable $e) {
            error_log("LMS Admin create_section_offering error: " . $e->getMessage());
            sendResponse(false, "Database error adding section offering: " . $e->getMessage());
        }
        break;

    case 'delete_section_offering':
        $sectionSubjectId = (int)($_POST['section_subject_id'] ?? 0);
        if ($sectionSubjectId <= 0) {
            sendResponse(false, "Section Subject ID is required.");
        }

        try {
            $ssStmt = $pdo->prepare("
                SELECT ss.id, sub.subject_code, sec.section_name 
                FROM section_subjects ss 
                JOIN subjects sub ON sub.id = ss.subject_id 
                JOIN sections sec ON sec.id = ss.section_id 
                WHERE ss.id = :id LIMIT 1
            ");
            $ssStmt->execute(['id' => $sectionSubjectId]);
            $ss = $ssStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ss) {
                sendResponse(false, "Section subject offering not found.");
            }

            // Check if student grades or coursework exist
            $courseworkCheck = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM lms_materials WHERE section_subject_id = :id1) +
                    (SELECT COUNT(*) FROM lms_assignments WHERE section_subject_id = :id2) +
                    (SELECT COUNT(*) FROM lms_quizzes WHERE section_subject_id = :id3) +
                    (SELECT COUNT(*) FROM student_grades WHERE section_subject_id = :id4) AS total_items
            ");
            $courseworkCheck->execute([
                'id1' => $sectionSubjectId,
                'id2' => $sectionSubjectId,
                'id3' => $sectionSubjectId,
                'id4' => $sectionSubjectId
            ]);
            if ((int)$courseworkCheck->fetchColumn() > 0) {
                sendResponse(false, "Cannot remove offering: active LMS coursework or student grades are associated with this class section.");
            }

            $pdo->prepare("DELETE FROM section_subjects WHERE id = :id")->execute(['id' => $sectionSubjectId]);

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_SECTION_OFFERING_DELETE',
                'section_subject',
                $sectionSubjectId,
                "Admin removed offering of {$ss['subject_code']} from {$ss['section_name']}"
            );

            sendResponse(true, "Section offering removed successfully.");
        } catch (Throwable $e) {
            error_log("LMS Admin delete_section_offering error: " . $e->getMessage());
            sendResponse(false, "Database error removing section offering: " . $e->getMessage());
        }
        break;

    case 'override_student_lms_access':
        $studentId = (int)($_POST['student_id'] ?? 0);
        $targetAction = trim($_POST['target_action'] ?? 'sync_auto');
        $reason = trim($_POST['reason'] ?? '');

        if ($studentId <= 0) {
            sendResponse(false, "Student ID is required.");
        }
        if ($reason === '') {
            sendResponse(false, "A reason for this administrative access override is required for the audit log.");
        }

        try {
            $sStmt = $pdo->prepare("
                SELECT s.id, s.user_id, s.first_name, s.last_name, s.academic_term_id, s.enrollment_status,
                       u.username, u.role, u.is_active
                FROM students s
                JOIN users u ON u.id = s.user_id
                WHERE s.id = :id
            ");
            $sStmt->execute(['id' => $studentId]);
            $student = $sStmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                sendResponse(false, "Student record not found.");
            }

            $beforeStatus = $student['enrollment_status'];
            $beforeRole   = $student['role'];
            $beforeTerm   = (int)$student['academic_term_id'];

            $pdo->beginTransaction();

            if ($targetAction === 'sync_auto') {
                $eStmt = $pdo->prepare("
                    SELECT academic_term_id, section_id, status 
                    FROM enrollments 
                    WHERE student_id = :id AND status = 'enrolled' 
                    ORDER BY id DESC LIMIT 1
                ");
                $eStmt->execute(['id' => $studentId]);
                $enrollment = $eStmt->fetch(PDO::FETCH_ASSOC);

                if (!$enrollment) {
                    $pdo->rollBack();
                    sendResponse(false, "Automated sync requires an existing confirmed section enrollment in the enrollments table. Use Manual Force Grant if granting emergency access.");
                }

                $newTermId = (int)$enrollment['academic_term_id'];
                $pdo->prepare("UPDATE students SET enrollment_status = 'enrolled', academic_term_id = :tid WHERE id = :id")
                    ->execute(['tid' => $newTermId, 'id' => $studentId]);

                if ($student['role'] !== 'student' && $student['role'] === 'enrollee') {
                    $pdo->prepare("UPDATE users SET role = 'student' WHERE id = :uid")->execute(['uid' => $student['user_id']]);
                }

                $description = "Admin synchronized student LMS access for {$student['first_name']} {$student['last_name']} (Term: {$newTermId}). Reason: {$reason}";
            } elseif ($targetAction === 'force_enrolled') {
                require_once __DIR__ . '/../includes/academic_terms.php';
                $activeTerm = getActiveAcademicTerm($pdo);
                $termIdToSet = $student['academic_term_id'] ?: ($activeTerm ? (int)$activeTerm['id'] : null);

                $pdo->prepare("UPDATE students SET enrollment_status = 'enrolled', academic_term_id = :tid WHERE id = :id")
                    ->execute(['tid' => $termIdToSet, 'id' => $studentId]);

                if ($student['role'] === 'enrollee') {
                    $pdo->prepare("UPDATE users SET role = 'student' WHERE id = :uid")->execute(['uid' => $student['user_id']]);
                }

                $description = "Admin manually forced active LMS enrolled access for {$student['first_name']} {$student['last_name']}. Reason: {$reason}";
            } elseif ($targetAction === 'revert_pending') {
                $pdo->prepare("UPDATE students SET enrollment_status = 'pending' WHERE id = :id")
                    ->execute(['id' => $studentId]);

                $description = "Admin reverted LMS enrolled access to pending for {$student['first_name']} {$student['last_name']}. Reason: {$reason}";
            } else {
                $pdo->rollBack();
                sendResponse(false, "Invalid target access action.");
            }

            $pdo->commit();

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_STUDENT_ACCESS_OVERRIDE',
                'student',
                $studentId,
                $description . " [Before: status={$beforeStatus}, role={$beforeRole}, term={$beforeTerm}]"
            );

            sendResponse(true, "Student LMS access record updated successfully.");

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("LMS Admin override_student_lms_access error: " . $e->getMessage());
            sendResponse(false, "Database error updating student access: " . $e->getMessage());
        }
        break;

    case 'reassign_teacher_oversight':
        $sectionSubjectId = (int)($_POST['section_subject_id'] ?? 0);
        $newTeacherId     = (int)($_POST['teacher_id'] ?? 0);
        $reason           = trim($_POST['reason'] ?? '');

        if ($sectionSubjectId <= 0) {
            sendResponse(false, "Section Subject ID is required.");
        }
        if ($reason === '') {
            sendResponse(false, "A reason for this administrative teacher reassignment is required for the audit log.");
        }

        try {
            $ssStmt = $pdo->prepare("
                SELECT ss.id, ss.instructor_id, sub.subject_code, sub.subject_name, sec.section_name,
                       u.username AS old_teacher_name
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                JOIN sections sec ON sec.id = ss.section_id
                LEFT JOIN users u ON u.id = ss.instructor_id
                WHERE ss.id = :id
            ");
            $ssStmt->execute(['id' => $sectionSubjectId]);
            $ss = $ssStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ss) {
                sendResponse(false, "Section subject offering not found.");
            }

            $oldInstructorId = $ss['instructor_id'];
            $oldTeacherName = $ss['old_teacher_name'] ?: 'None';

            if ($newTeacherId > 0) {
                $tStmt = $pdo->prepare("SELECT id, username, first_name, last_name, role, is_active FROM users WHERE id = :id AND role = 'teacher'");
                $tStmt->execute(['id' => $newTeacherId]);
                $newTeacher = $tStmt->fetch(PDO::FETCH_ASSOC);
                if (!$newTeacher) {
                    sendResponse(false, "Target user is not an active teacher.");
                }
                $newTeacherName = trim(($newTeacher['first_name'] ?? '') . ' ' . ($newTeacher['last_name'] ?? '')) ?: $newTeacher['username'];

                $pdo->prepare("UPDATE section_subjects SET instructor_id = :tid WHERE id = :id")
                    ->execute(['tid' => $newTeacherId, 'id' => $sectionSubjectId]);

                $logMsg = "Admin reassigned instructor for {$ss['subject_code']} ({$ss['section_name']}) from {$oldTeacherName} to {$newTeacherName}. Reason: {$reason}";
                $respMsg = "Instructor successfully reassigned to {$newTeacherName}.";
            } else {
                $pdo->prepare("UPDATE section_subjects SET instructor_id = NULL WHERE id = :id")
                    ->execute(['id' => $sectionSubjectId]);

                $logMsg = "Admin removed instructor {$oldTeacherName} from {$ss['subject_code']} ({$ss['section_name']}). Reason: {$reason}";
                $respMsg = "Instructor removed from {$ss['subject_code']} - {$ss['section_name']}.";
            }

            logLmsAdminAction(
                $pdo,
                $adminUserId,
                'LMS_TEACHER_OVERSIGHT_REASSIGN',
                'section_subject',
                $sectionSubjectId,
                $logMsg
            );

            sendResponse(true, $respMsg);

        } catch (Throwable $e) {
            error_log("LMS Admin reassign_teacher_oversight error: " . $e->getMessage());
            sendResponse(false, "Database error updating instructor assignment: " . $e->getMessage());
        }
        break;

    case 'get_subject_content_summary':
        $sectionSubjectId = (int)($_GET['section_subject_id'] ?? ($_POST['section_subject_id'] ?? 0));
        if ($sectionSubjectId <= 0) {
            sendResponse(false, "Section Subject ID is required.");
        }

        try {
            $sStmt = $pdo->prepare("
                SELECT ss.id, ss.section_id, ss.subject_id, ss.instructor_id,
                       sub.subject_code, sub.subject_name, sub.units,
                       sec.section_name, sec.program, sec.year_level,
                       at.school_year, at.semester,
                       u.username AS instructor_username,
                       CONCAT_WS(' ', u.first_name, u.last_name) AS instructor_name
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                JOIN sections sec ON sec.id = ss.section_id
                LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
                LEFT JOIN users u ON u.id = ss.instructor_id
                WHERE ss.id = :id
            ");
            $sStmt->execute(['id' => $sectionSubjectId]);
            $subject = $sStmt->fetch(PDO::FETCH_ASSOC);

            if (!$subject) {
                sendResponse(false, "Subject offering not found.");
            }

            $modulesStmt = $pdo->prepare("
                SELECT m.id, m.title, m.description, m.display_order, m.is_published,
                       (SELECT COUNT(*) FROM lms_lessons l WHERE l.module_id = m.id) AS lesson_count
                FROM lms_modules m
                WHERE m.section_subject_id = :id
                ORDER BY m.display_order ASC, m.id ASC
            ");
            $modulesStmt->execute(['id' => $sectionSubjectId]);
            $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);

            $matStmt = $pdo->prepare("
                SELECT id, title, material_type, file_name, file_size, is_available, created_at
                FROM lms_materials
                WHERE section_subject_id = :id
                ORDER BY display_order ASC, id DESC
            ");
            $matStmt->execute(['id' => $sectionSubjectId]);
            $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);

            $assignStmt = $pdo->prepare("
                SELECT a.id, a.title, a.due_at, a.max_score, a.is_published, a.created_at,
                       (SELECT COUNT(*) FROM lms_assignment_submissions s WHERE s.assignment_id = a.id) AS submission_count
                FROM lms_assignments a
                WHERE a.section_subject_id = :id
                ORDER BY a.created_at DESC
            ");
            $assignStmt->execute(['id' => $sectionSubjectId]);
            $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

            $quizStmt = $pdo->prepare("
                SELECT q.id, q.title, q.time_limit_minutes, q.allowed_attempts, q.passing_score, q.is_published,
                       (SELECT COUNT(*) FROM lms_quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
                       (SELECT COUNT(DISTINCT qa.student_id) FROM lms_quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_students_count
                FROM lms_quizzes q
                WHERE q.section_subject_id = :id
                ORDER BY q.display_order ASC, q.id DESC
            ");
            $quizStmt->execute(['id' => $sectionSubjectId]);
            $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);

            $annStmt = $pdo->prepare("
                SELECT an.id, an.title, an.content, an.created_at, u.username AS author_name
                FROM lms_announcements an
                LEFT JOIN users u ON u.id = an.created_by
                WHERE an.section_subject_id = :id
                ORDER BY an.created_at DESC
            ");
            $annStmt->execute(['id' => $sectionSubjectId]);
            $announcements = $annStmt->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, "Subject content loaded.", [
                'subject'       => $subject,
                'modules'       => $modules,
                'materials'     => $materials,
                'assignments'   => $assignments,
                'quizzes'       => $quizzes,
                'announcements' => $announcements,
            ]);

        } catch (Throwable $e) {
            error_log("LMS Admin get_subject_content_summary error: " . $e->getMessage());
            sendResponse(false, "Database error retrieving subject content: " . $e->getMessage());
        }
        break;

    default:
        sendResponse(false, "Unrecognized LMS Admin action.");
        break;
}

