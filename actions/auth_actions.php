<?php
/**
 * Auth Actions Processor
 * Handles post request login validations, user self-registration, and session state creation.
 * Follows AI_GUIDE.md guidelines and uses secure PDO prepared statements.
 */

require_once '../includes/auth_check.php';

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../auth/login");
    exit;
}

require_once '../config/database.php';
require_once '../includes/mailer.php';

// Helper function for registration error redirects (retains input data)
function redirectWithRegisterError($errorMsg, $firstName, $lastName, $contactNumber, $username, $email) {
    $_SESSION['flash_error'] = $errorMsg;
    $_SESSION['form_data'] = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'contact_number' => $contactNumber,
        'username' => $username,
        'email' => $email
    ];
    header("Location: ../auth/login?mode=register");
    exit;
}

// Helper function for login error redirects (retains username)
function redirectWithLoginError($errorMsg, $usernameInput) {
    $_SESSION['flash_error'] = $errorMsg;
    $_SESSION['form_data'] = [
        'username' => $usernameInput
    ];
    header("Location: ../auth/login");
    exit;
}

// --- Dual-Key (Account + IP flood) brute-force protection (DB-backed) -------

const LOGIN_MAX_USER_ATTEMPTS = 5;   // Max attempts per specific account
const LOGIN_MAX_IP_ATTEMPTS   = 30;  // Max attempts per IP (protects against spray, prevents shared NAT DoS)
const LOGIN_LOCKOUT_SECS      = 300; // 5-minute window

function getLoginIpHash(): string {
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|login_ip');
}

function getLoginUserHash(string $identifier): string {
    return hash('sha256', strtolower(trim($identifier)) . '|login_user');
}

function readLoginAttemptState(string $identifier): array {
    global $pdo;
    $ipHash      = getLoginIpHash();
    $userHash    = getLoginUserHash($identifier);
    $windowStart = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_SECS);

    // 1. Check account-specific lockout (5 failed attempts for this username/email)
    try {
        $stmtUser = $pdo->prepare(
            'SELECT COUNT(*) AS attempt_count, MAX(attempted_at) AS last_attempt
             FROM login_attempts
             WHERE user_hash = :user_hash AND attempted_at >= :window'
        );
        $stmtUser->execute(['user_hash' => $userHash, 'window' => $windowStart]);
        $userRow = $stmtUser->fetch();
        $userCount = (int)($userRow['attempt_count'] ?? 0);
        if ($userCount >= LOGIN_MAX_USER_ATTEMPTS && !empty($userRow['last_attempt'])) {
            $lockedUntil = strtotime($userRow['last_attempt']) + LOGIN_LOCKOUT_SECS;
            if ($lockedUntil > time()) {
                return ['locked' => true, 'locked_until' => $lockedUntil, 'scope' => 'account'];
            }
        }
    } catch (\Throwable $e) {
        // Safe fallback if user_hash column is not yet queried
    }

    // 2. Check IP flood lockout (30 failed attempts across all accounts from this IP)
    $stmtIp = $pdo->prepare(
        'SELECT COUNT(*) AS attempt_count, MAX(attempted_at) AS last_attempt
         FROM login_attempts
         WHERE ip_hash = :ip AND attempted_at >= :window'
    );
    $stmtIp->execute(['ip' => $ipHash, 'window' => $windowStart]);
    $ipRow = $stmtIp->fetch();
    $ipCount = (int)($ipRow['attempt_count'] ?? 0);
    if ($ipCount >= LOGIN_MAX_IP_ATTEMPTS && !empty($ipRow['last_attempt'])) {
        $lockedUntil = strtotime($ipRow['last_attempt']) + LOGIN_LOCKOUT_SECS;
        if ($lockedUntil > time()) {
            return ['locked' => true, 'locked_until' => $lockedUntil, 'scope' => 'ip'];
        }
    }

    return ['locked' => false, 'locked_until' => 0, 'scope' => ''];
}

function markFailedLoginAttempt(string $identifier): void {
    global $pdo;
    $ipHash   = getLoginIpHash();
    $userHash = getLoginUserHash($identifier);
    try {
        $pdo->prepare('INSERT INTO login_attempts (ip_hash, user_hash) VALUES (:ip, :user)')
            ->execute(['ip' => $ipHash, 'user' => $userHash]);
    } catch (\Throwable $e) {
        $pdo->prepare('INSERT INTO login_attempts (ip_hash) VALUES (:ip)')
            ->execute(['ip' => $ipHash]);
    }
}

function clearLoginAttempts(string $identifier): void {
    global $pdo;
    $ipHash   = getLoginIpHash();
    $userHash = getLoginUserHash($identifier);
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE user_hash = :user OR ip_hash = :ip')
            ->execute(['user' => $userHash, 'ip' => $ipHash]);
    } catch (\Throwable $e) {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = :ip')
            ->execute(['ip' => $ipHash]);
    }
}

$action = isset($_POST['action']) ? trim($_POST['action']) : 'login';

if ($action === 'register') {
    // -------------------------------------------------------------------------
    // SELF-REGISTRATION FOR ENROLLEES
    // -------------------------------------------------------------------------
    $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $contactNumber = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    // Validation checks
    if (empty($firstName) || empty($lastName) || empty($contactNumber) || empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
        redirectWithRegisterError("All fields are required for registration.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // First Name: Text-only (letters and spaces)
    if (!preg_match("/^[a-zA-Z\s]+$/", $firstName)) {
        redirectWithRegisterError("First name must contain only letters and spaces.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // Last Name: Text-only (letters and spaces)
    if (!preg_match("/^[a-zA-Z\s]+$/", $lastName)) {
        redirectWithRegisterError("Last name must contain only letters and spaces.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // Contact Number: Philippine mobile format (numbers only, starts with 09, 11 digits)
    if (!preg_match("/^09[0-9]{9}$/", $contactNumber)) {
        redirectWithRegisterError("Please enter a valid Philippine mobile number (e.g., 09171234567).", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // Username: Alphanumeric only (letters and numbers, no special characters or spaces)
    if (!preg_match("/^[a-zA-Z0-9]+$/", $username)) {
        redirectWithRegisterError("Username must contain only letters and numbers.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // CSRF protection for registration to prevent automated or forged account creation.
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        redirectWithRegisterError("Security validation failed. Please try again.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    if (strlen($username) < 4) {
        redirectWithRegisterError("Username must be at least 4 characters long.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // Email address validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectWithRegisterError("Please enter a valid email address.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // Password validation: Minimum 8 characters, must contain at least one letter and at least one number
    if (strlen($password) < 8 || !preg_match("/[A-Za-z]/", $password) || !preg_match("/[0-9]/", $password)) {
        redirectWithRegisterError("Password must be at least 8 characters long and contain both letters and numbers.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    // Password confirmation check
    if ($password !== $confirmPassword) {
        redirectWithRegisterError("Passwords do not match.", $firstName, $lastName, $contactNumber, $username, $email);
    }

    try {
        // Check username uniqueness
        $checkUser = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
        $checkUser->execute(['username' => $username]);
        if ($checkUser->fetch()) {
            redirectWithRegisterError("Username is already taken.", $firstName, $lastName, $contactNumber, $username, $email);
        }

        // Check email uniqueness
        $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkEmail->execute(['email' => $email]);
        if ($checkEmail->fetch()) {
            redirectWithRegisterError("Email is already registered.", $firstName, $lastName, $contactNumber, $username, $email);
        }

        // Begin transaction
        $pdo->beginTransaction();

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // 1. Insert into users as 'enrollee'
        $stmtUser = $pdo->prepare("
            INSERT INTO users (username, password_hash, role, email, first_name, last_name, is_active) 
            VALUES (:username, :password_hash, 'enrollee', :email, :first_name, :last_name, 1)
        ");
        $stmtUser->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        // Create matching student profile linked to the new user (Bug 2.1)
        require_once '../includes/academic_terms.php';
        $activeTerm = getActiveAcademicTerm($pdo);
        $activeTermId = $activeTerm ? (int)$activeTerm['id'] : null;

        $stmtStudent = $pdo->prepare("
            INSERT INTO students (user_id, academic_term_id, first_name, last_name, contact_number, application_status, admission_status, enrollment_status) 
            VALUES (:user_id, :academic_term_id, :first_name, :last_name, :contact_number, 'draft', 'draft', 'draft')
        ");
        $stmtStudent->execute([
            'user_id' => $newUserId,
            'academic_term_id' => $activeTermId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'contact_number' => $contactNumber
        ]);
        $newStudentId = (int)$pdo->lastInsertId();

        $pdo->commit();

        // Send welcome email — soft-fail, never blocks registration
        try {
            $welcomeSubject = 'Welcome to NCST Maritime Academy — Account Created';
            $welcomeMessage = "Hello {$firstName} {$lastName},\n\n"
                . "Your enrollment account has been successfully created.\n\n"
                . "Username: {$username}\n\n"
                . "You may now log in and complete your admission application. "
                . "Please prepare the required documents (Form 137, SHS Diploma, Good Moral Certificate, "
                . "Birth Certificate, Medical Clearance, and a recent 2x2 ID photo) before submitting.\n\n"
                . "If you have any questions, please contact the Registrar's Office.";
            $htmlBody = buildEmailHtml(
                $firstName . ' ' . $lastName,
                'Welcome to NCST Maritime Academy',
                $welcomeMessage
            );
            sendSystemEmail(
                $email,
                $firstName . ' ' . $lastName,
                $welcomeSubject,
                $htmlBody
            );
        } catch (\Throwable $mailErr) {
            error_log('[Mailer] Welcome email failed for new user "' . $username . '": ' . $mailErr->getMessage());
        }

        // 2. Auto-login new user.
        session_regenerate_id(true);
        regenerateCsrfToken();
        $_SESSION['LAST_ACTIVITY'] = time(); // BUG-003: stamp idle timer at login so first-request bypass is closed
        $_SESSION['user_id'] = $newUserId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'enrollee';
        $_SESSION['student_id'] = $newStudentId;
        $_SESSION['application_status'] = 'draft';
        $_SESSION['admission_status'] = 'draft';
        $_SESSION['enrollment_status'] = 'draft';

        $_SESSION['flash_success'] = "Registration successful! Your account is ready to start an application.";
        header("Location: ../enrollee/dashboard");
        exit;

    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Self-registration failed: " . $e->getMessage());
        redirectWithRegisterError("A database error occurred during registration. Please try again.", $firstName, $lastName, $contactNumber, $username, $email);
    }

} else {
    // -------------------------------------------------------------------------
    // LOGIN AUTHENTICATION
    // -------------------------------------------------------------------------
    $usernameInput = isset($_POST['username']) ? trim($_POST['username']) : '';
    $passwordInput = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($usernameInput) || empty($passwordInput)) {
        redirectWithLoginError("Please fill in all fields.", $usernameInput);
    }

    // CSRF validation for login (Fix-B)
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        redirectWithLoginError("Security validation failed. Please try again.", $usernameInput);
    }

    try {
        $attemptState = readLoginAttemptState($usernameInput);
        if ($attemptState['locked']) {
            $waitSeconds = max(1, (int)$attemptState['locked_until'] - time());
            if ($attemptState['scope'] === 'account') {
                redirectWithLoginError("Too many failed login attempts for this account. Please wait {$waitSeconds} seconds and try again.", $usernameInput);
            } else {
                redirectWithLoginError("Too many failed login attempts from your network. Please wait {$waitSeconds} seconds and try again.", $usernameInput);
            }
        }

        // Fetch user by either the username or email shown on the unified login screen.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1");
        $stmt->execute([
            'username' => $usernameInput,
            'email' => $usernameInput
        ]);
        $user = $stmt->fetch();
        
        // Validate password
        if (!$user || !password_verify($passwordInput, $user['password_hash'])) {
            markFailedLoginAttempt($usernameInput);
            redirectWithLoginError("Invalid username or password.", $usernameInput);
        }

        // Check active status
        if ((int)$user['is_active'] !== 1) {
            markFailedLoginAttempt($usernameInput);
            redirectWithLoginError("Your account is deactivated. Please contact the administration.", $usernameInput);
        }

        clearLoginAttempts($usernameInput);
        
        session_regenerate_id(true);
        regenerateCsrfToken();
        $_SESSION['LAST_ACTIVITY'] = time(); // BUG-003: stamp idle timer at login so first-request bypass is closed
        
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
        $_SESSION['avatar_version'] = time();
        $_SESSION['application_status'] = 'draft';
        $_SESSION['admission_status'] = 'draft';
        $_SESSION['enrollment_status'] = 'draft';
        
        // Load student profile details if enrollee/student
        if (in_array($user['role'], ['student', 'enrollee'])) {
            $studentStmt = $pdo->prepare("SELECT id, application_status, admission_status, enrollment_status FROM students WHERE user_id = :user_id LIMIT 1");
            $studentStmt->execute(['user_id' => $user['id']]);
            $student = $studentStmt->fetch();
            
            if ($student) {
                $_SESSION['student_id'] = (int)$student['id'];
                $_SESSION['application_status'] = $student['application_status'] ?? 'draft';
                $_SESSION['admission_status'] = $student['admission_status'] ?? $student['application_status'] ?? 'draft';
                $_SESSION['enrollment_status'] = $student['enrollment_status'];
            } else {
                $_SESSION['application_status'] = 'draft';
                $_SESSION['admission_status'] = 'draft';
                $_SESSION['enrollment_status'] = 'draft';
            }
        }
        
        // Route dashboard
        $dashboards = [
            'admin'     => '../admin/dashboard',
            'registrar' => '../registrar/dashboard',
            'cashier'   => '../cashier/dashboard',
            'teacher'   => '../teacher/dashboard',
            'student'   => '../student/dashboard',
            'enrollee'  => '../enrollee/dashboard'
        ];
        
        $redirectTarget = isset($dashboards[$user['role']]) ? $dashboards[$user['role']] : '../index';
        
        $_SESSION['flash_success'] = "Welcome back, " . htmlspecialchars($user['username']) . "!";
        header("Location: " . $redirectTarget);
        exit;

    } catch (\PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        redirectWithLoginError("A database error occurred during login. Please try again.", $usernameInput);
    }
}
?>
