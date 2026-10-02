<?php
/**
 * User Actions Processor
 * Handles CRUD actions (create, update, toggle status, delete) for user accounts.
 * Secured to Admin role only.
 */

require_once '../includes/auth_check.php';
// Secure to admin role
checkRole(['admin']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/notifications.php';

function writeAuditLog(PDO $pdo, int $actorId, string $action, string $itemType, ?int $itemId, string $description): void {
    $stmt = $pdo->prepare('INSERT INTO audit_logs (actor_id, action, item_type, item_id, description) VALUES (:actor_id, :action, :item_type, :item_id, :description)');
    $stmt->execute([
        'actor_id' => $actorId,
        'action' => $action,
        'item_type' => $itemType,
        'item_id' => $itemId,
        'description' => $description
    ]);
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_error'] = "Invalid request method.";
    header("Location: ../admin/manage_users");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    header("Location: ../admin/manage_users");
    exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

switch ($action) {
    case 'create':
        // Capture User inputs
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $role = isset($_POST['role']) ? trim($_POST['role']) : '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        // Capture Student Profile inputs (if role is student)
        $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
        $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
        $birthdate = isset($_POST['birthdate']) ? trim($_POST['birthdate']) : '';
        $addressStreet = isset($_POST['address']) ? trim($_POST['address']) : '';
        $contactNumber = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $enrollmentStatus = isset($_POST['enrollment_status']) ? trim($_POST['enrollment_status']) : 'pending';

        $isStudentOrEnrollee = in_array($role, ['student', 'enrollee'], true);

        // Validation: password is only required for non-student/non-enrollee roles (FUNC-006)
        if (empty($username) || empty($email) || empty($role) || (!$isStudentOrEnrollee && empty($password))) {
            $_SESSION['flash_error'] = "All core fields are required.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{4,50}$/', $username)) {
            $_SESSION['flash_error'] = "Username must be 4 to 50 characters long and contain only letters, numbers, dots, hyphens, or underscores.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = "Please enter a valid email address.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if (!empty($contactNumber) && !preg_match('/^09\d{9}$/', $contactNumber)) {
            $_SESSION['flash_error'] = "Contact number must be an 11-digit Philippine mobile number beginning with 09.";
            header("Location: ../admin/manage_users");
            exit;
        }

        // Validate role is one of the allowed staff roles creatable by admin.
        // Student and Enrollee accounts are created exclusively via self-registration.
        if (in_array($role, ['student', 'enrollee'], true)) {
            $_SESSION['flash_error'] = "Student and enrollee accounts must be created by applicants through self-registration.";
            header("Location: ../admin/manage_users");
            exit;
        }

        $validRoles = ['admin', 'registrar', 'cashier', 'teacher'];
        if (!in_array($role, $validRoles, true)) {
            $_SESSION['flash_error'] = "Invalid role selected.";
            header("Location: ../admin/manage_users");
            exit;
        }

        // Password policy: required and enforced for all creatable staff roles (admin/registrar/cashier/teacher)
        if (empty($password) || strlen($password) < 8 || !preg_match("/[A-Za-z]/", $password) || !preg_match("/[0-9]/", $password)) {
            $_SESSION['flash_error'] = "Password must be at least 8 characters long and contain both letters and numbers.";
            header("Location: ../admin/manage_users");
            exit;
        }

        // Additional profile validation for student accounts.
        if ($role === 'student') {
            if (empty($firstName) || empty($lastName) || empty($birthdate)) {
                $_SESSION['flash_error'] = "Student profile fields (First Name, Last Name, Birthdate) are required.";
                header("Location: ../admin/manage_users");
                exit;
            }
            // Validate birthdate format and ensure it is not today or in the future.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) || strtotime($birthdate) === false) {
                $_SESSION['flash_error'] = "Please enter a valid birthdate.";
                header("Location: ../admin/manage_users");
                exit;
            }
            $birthdateObj = new DateTime($birthdate);
            $today = new DateTime('today');
            if ($birthdateObj >= $today) {
                $_SESSION['flash_error'] = "Birthdate must be a past date.";
                header("Location: ../admin/manage_users");
                exit;
            }
            $age = (int)$birthdateObj->diff($today)->y;
            if ($age < 15) {
                $_SESSION['flash_error'] = "Student accounts must be at least 15 years old.";
                header("Location: ../admin/manage_users");
                exit;
            }
        }

        try {
            // Check username uniqueness
            $checkUserStmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
            $checkUserStmt->execute(['username' => $username]);
            if ($checkUserStmt->fetch()) {
                $_SESSION['flash_error'] = "Username is already taken.";
                header("Location: ../admin/manage_users");
                exit;
            }

            // Check email uniqueness
            $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $checkEmailStmt->execute(['email' => $email]);
            if ($checkEmailStmt->fetch()) {
                $_SESSION['flash_error'] = "Email is already in use.";
                header("Location: ../admin/manage_users");
                exit;
            }

            // Start SQL transaction
            $pdo->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Insert core user account
            $userInsertStmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, role, email, is_active) 
                VALUES (:username, :password_hash, :role, :email, :is_active)
            ");
            $userInsertStmt->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'role' => $role,
                'email' => $email,
                'is_active' => $isActive
            ]);

            $newUserId = $pdo->lastInsertId();

            // Insert student profile row if applicable
            if ($role === 'student') {
                $activeTerm = requireActiveAcademicTerm($pdo);
                $studentInsertStmt = $pdo->prepare("
                    INSERT INTO students (user_id, academic_term_id, first_name, last_name, birthdate, address_street, contact_number, application_status, enrollment_status) 
                    VALUES (:user_id, :academic_term_id, :first_name, :last_name, :birthdate, :address_street, :contact_number, :application_status, :enrollment_status)
                ");
                $studentInsertStmt->execute([
                    'user_id' => $newUserId,
                    'academic_term_id' => $activeTerm['id'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'birthdate' => $birthdate,
                    'address_street' => $addressStreet ?: null,
                    'contact_number' => $contactNumber ?: null,
                    'application_status' => 'approved',
                    'enrollment_status' => $enrollmentStatus
                ]);
            }

            $pdo->commit();
            $_SESSION['flash_success'] = "Account successfully created for " . htmlspecialchars($username) . ".";
            header("Location: ../admin/manage_users");
            exit;

        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Create user failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to create user account.";
            header("Location: ../admin/manage_users");
            exit;
        }
        break;

    case 'update':
        // Capture fields
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $role = isset($_POST['role']) ? trim($_POST['role']) : '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
        $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
        $birthdate = isset($_POST['birthdate']) ? trim($_POST['birthdate']) : '';
        $addressStreet = isset($_POST['address']) ? trim($_POST['address']) : '';
        $contactNumber = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $enrollmentStatus = isset($_POST['enrollment_status']) ? trim($_POST['enrollment_status']) : 'pending';

        if (empty($userId) || empty($username) || empty($email) || empty($role)) {
            $_SESSION['flash_error'] = "User ID, Username, Email and Role are required.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{4,50}$/', $username)) {
            $_SESSION['flash_error'] = "Username must be 4 to 50 characters long and contain only letters, numbers, dots, hyphens, or underscores.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = "Please enter a valid email address.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if (!empty($contactNumber) && !preg_match('/^09\d{9}$/', $contactNumber)) {
            $_SESSION['flash_error'] = "Contact number must be an 11-digit Philippine mobile number beginning with 09.";
            header("Location: ../admin/manage_users");
            exit;
        }

        $validRoles = ['admin', 'registrar', 'cashier', 'teacher', 'student', 'enrollee'];
        if (!in_array($role, $validRoles, true)) {
            $_SESSION['flash_error'] = "Invalid role selected.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if ($role === 'student' && !in_array($enrollmentStatus, ['paid', 'enrolled'], true)) {
            $_SESSION['flash_error'] = "A student account requires a completed payment before activation.";
            header("Location: ../admin/manage_users");
            exit;
        }

        if ($role === 'student') {
            if (empty($firstName) || empty($lastName) || empty($birthdate)) {
                $_SESSION['flash_error'] = "Student profile fields (First Name, Last Name, Birthdate) are required.";
                header("Location: ../admin/manage_users");
                exit;
            }
            // Validate birthdate format and ensure it is not today or in the future on update.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) || strtotime($birthdate) === false) {
                $_SESSION['flash_error'] = "Please enter a valid birthdate.";
                header("Location: ../admin/manage_users");
                exit;
            }
            $birthdateObj = new DateTime($birthdate);
            $today = new DateTime('today');
            if ($birthdateObj >= $today) {
                $_SESSION['flash_error'] = "Birthdate must be a past date.";
                header("Location: ../admin/manage_users");
                exit;
            }
            $age = (int)$birthdateObj->diff($today)->y;
            if ($age < 15) {
                $_SESSION['flash_error'] = "Student accounts must be at least 15 years old.";
                header("Location: ../admin/manage_users");
                exit;
            }
        }

        // Prevent self-lockout by deactivating oneself
        if ($userId === (int)$_SESSION['user_id'] && $isActive === 0) {
            $_SESSION['flash_error'] = "Safety Guard: You cannot deactivate your own administrative account.";
            header("Location: ../admin/manage_users");
            exit;
        }
        // Prevent self-role modification away from admin
        if ($userId === (int)$_SESSION['user_id'] && $role !== 'admin') {
            $_SESSION['flash_error'] = "Safety Guard: You cannot change your own role from Administrator.";
            header("Location: ../admin/manage_users");
            exit;
        }

        try {
            // Check username uniqueness (excluding current user)
            $checkUserStmt = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1");
            $checkUserStmt->execute(['username' => $username, 'id' => $userId]);
            if ($checkUserStmt->fetch()) {
                $_SESSION['flash_error'] = "Username is already taken by another account.";
                header("Location: ../admin/manage_users");
                exit;
            }

            // Check email uniqueness (excluding current user)
            $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $checkEmailStmt->execute(['email' => $email, 'id' => $userId]);
            if ($checkEmailStmt->fetch()) {
                $_SESSION['flash_error'] = "Email is already in use by another account.";
                header("Location: ../admin/manage_users");
                exit;
            }

            // Fetch old user details to check role transitions
            $oldUserStmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $oldUserStmt->execute(['id' => $userId]);
            $oldUser = $oldUserStmt->fetch();
            $oldRole = $oldUser ? $oldUser['role'] : '';

            $pdo->beginTransaction();

            // If student or enrollee, ignore password input so their self-registered password is never overwritten (FUNC-006)
            if (in_array($role, ['student', 'enrollee'], true)) {
                $password = '';
            }

            // Update user account details
            if (!empty($password)) {
                // Password policy: same as registration
                if (strlen($password) < 8 || !preg_match("/[A-Za-z]/", $password) || !preg_match("/[0-9]/", $password)) {
                    throw new \RuntimeException('Password must be at least 8 characters long and contain both letters and numbers.');
                }
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $updateUserStmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, email = :email, role = :role, is_active = :is_active, password_hash = :password_hash 
                    WHERE id = :id
                ");
                $updateUserStmt->execute([
                    'username' => $username,
                    'email' => $email,
                    'role' => $role,
                    'is_active' => $isActive,
                    'password_hash' => $passwordHash,
                    'id' => $userId
                ]);
            } else {
                $updateUserStmt = $pdo->prepare("
                    UPDATE users 
                    SET username = :username, email = :email, role = :role, is_active = :is_active 
                    WHERE id = :id
                ");
                $updateUserStmt->execute([
                    'username' => $username,
                    'email' => $email,
                    'role' => $role,
                    'is_active' => $isActive,
                    'id' => $userId
                ]);
            }

            // Handle Profile Row transitions
            // Active term is only required for student/enrollee roles
            $oldWasStudentOrEnrollee = in_array($oldRole, ['student', 'enrollee']);
            $newIsStudentOrEnrollee = in_array($role, ['student', 'enrollee']);

            if ($newIsStudentOrEnrollee) {
                $activeTerm = requireActiveAcademicTerm($pdo);
                // If enrollee, status must default to pending (as per Section 4)
                $finalStatus = ($role === 'enrollee') ? 'pending' : $enrollmentStatus;
                $finalApplicationStatus = ($role === 'enrollee') ? 'pending' : 'approved';

                // Check if profile already exists
                $profileCheckStmt = $pdo->prepare("SELECT id, academic_term_id, enrollment_status, application_status FROM students WHERE user_id = :user_id LIMIT 1");
                $profileCheckStmt->execute(['user_id' => $userId]);
                $existingProfile = $profileCheckStmt->fetch();

                if ($existingProfile) {
                    // Update existing profile; preserve original academic_term_id unless explicitly changed
                    $profileUpdateStmt = $pdo->prepare("
                        UPDATE students 
                        SET first_name = :first_name, last_name = :last_name, birthdate = :birthdate, 
                            address_street = :address_street, contact_number = :contact_number, academic_term_id = :academic_term_id,
                            application_status = :application_status, enrollment_status = :enrollment_status 
                        WHERE user_id = :user_id
                    ");
                    $profileUpdateStmt->execute([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'birthdate' => $birthdate,
                        'address_street' => $addressStreet ?: null,
                        'contact_number' => $contactNumber ?: null,
                        'academic_term_id' => $existingProfile['academic_term_id'] ?? $activeTerm['id'],
                        'application_status' => $finalApplicationStatus,
                        'enrollment_status' => $finalStatus,
                        'user_id' => $userId
                    ]);
                    if ($existingProfile['enrollment_status'] !== $finalStatus || $existingProfile['application_status'] !== $finalApplicationStatus) {
                        $notificationDetails = [
                            'pending' => ['Application under review', 'Your admission application is now under review by the Registrar\'s Office.', 'info'],
                            'approved' => ['Application approved', 'Your admission application was approved.', 'success'],
                            'paid' => ['Payment confirmed', 'Your admission status was updated to paid.', 'success'],
                            'enrolled' => ['Enrollment confirmed', 'Your admission status was updated to enrolled.', 'success'],
                            'rejected' => ['Application rejected', 'Your admission application was rejected. Please contact the Registrar\'s Office for more information.', 'danger'],
                            'needs_revision' => ['Edits requested', 'Your admission application was returned for edits. Review and resubmit it after making the requested corrections.', 'warning'],
                        ][$finalStatus] ?? ['Admission status updated', 'Your admission application status was updated to ' . $finalStatus . '.', 'info'];
                        createNotification($pdo, $userId, $notificationDetails[0], $notificationDetails[1], $notificationDetails[2]);
                    }
                } else {
                    // Insert new student profile (migrated role)
                    if (empty($firstName) || empty($lastName) || empty($birthdate)) {
                        throw new \RuntimeException("Required student profile fields are missing.");
                    }
                    $profileInsertStmt = $pdo->prepare("
                        INSERT INTO students (user_id, academic_term_id, first_name, last_name, birthdate, address_street, contact_number, application_status, enrollment_status) 
                        VALUES (:user_id, :academic_term_id, :first_name, :last_name, :birthdate, :address_street, :contact_number, :application_status, :enrollment_status)
                    ");
                    $profileInsertStmt->execute([
                        'user_id' => $userId,
                        'academic_term_id' => $activeTerm['id'],
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'birthdate' => $birthdate,
                        'address_street' => $addressStreet ?: null,
                        'contact_number' => $contactNumber ?: null,
                        'application_status' => $finalApplicationStatus,
                        'enrollment_status' => $finalStatus
                    ]);
                }
            } elseif ($oldWasStudentOrEnrollee) {
                // User role changed from student/enrollee to staff, delete student profile row to avoid orphans
                $profileDeleteStmt = $pdo->prepare("DELETE FROM students WHERE user_id = :user_id");
                $profileDeleteStmt->execute(['user_id' => $userId]);
            }

            $pdo->commit();
            $_SESSION['flash_success'] = "Account successfully updated for " . htmlspecialchars($username) . ".";
            header("Location: ../admin/manage_users");
            exit;

        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = $e->getMessage();
            header("Location: ../admin/manage_users");
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Update user failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Update failed: An unexpected error occurred. Please try again.";
            header("Location: ../admin/manage_users");
            exit;
        }
        break;

    case 'toggle_status':
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;

        if (empty($userId)) {
            $_SESSION['flash_error'] = "User ID is required.";
            header("Location: ../admin/manage_users");
            exit;
        }

        // Prevent self lockout
        if ($userId === (int)$_SESSION['user_id'] && $isActive === 0) {
            $_SESSION['flash_error'] = "Safety Guard: You cannot deactivate your own administrative account.";
            header("Location: ../admin/manage_users");
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET is_active = :is_active WHERE id = :id");
            $stmt->execute([
                'is_active' => $isActive,
                'id' => $userId
            ]);

            $statusText = $isActive ? "activated" : "deactivated";
            $_SESSION['flash_success'] = "User account successfully " . $statusText . ".";
            header("Location: ../admin/manage_users");
            exit;
        } catch (\PDOException $e) {
            error_log("Toggle status failed: " . $e->getMessage());
            $_SESSION['flash_error'] = "Database error: Failed to modify status.";
            header("Location: ../admin/manage_users");
            exit;
        }
        break;

    case 'delete':
        $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        if (empty($userId)) {
            $_SESSION['flash_error'] = "User ID is required.";
            header("Location: ../admin/manage_users");
            exit;
        }

        // Prevent self deletion
        if ($userId === (int)$_SESSION['user_id']) {
            $_SESSION['flash_error'] = "Safety Guard: You cannot delete your own administrative account.";
            header("Location: ../admin/manage_users");
            exit;
        }

        try {
            $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute(['id' => $userId]);
            $deletedUser = $userStmt->fetch();
            if (!$deletedUser) {
                throw new \Exception('User account was not found.');
            }
            $profileStmt = $pdo->prepare('SELECT * FROM students WHERE user_id = :user_id LIMIT 1');
            $profileStmt->execute(['user_id' => $userId]);
            $snapshot = ['user' => $deletedUser, 'student' => $profileStmt->fetch() ?: null];

            $pdo->beginTransaction();
            $trashStmt = $pdo->prepare('INSERT INTO deleted_items (item_type, original_id, display_name, deleted_by, snapshot) VALUES (:item_type, :original_id, :display_name, :deleted_by, :snapshot)');
            $trashStmt->execute([
                'item_type' => 'user',
                'original_id' => $userId,
                'display_name' => $deletedUser['username'],
                'deleted_by' => (int)$_SESSION['user_id'],
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)
            ]);

            // Delete user - related student records cascade with it.
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            writeAuditLog($pdo, (int)$_SESSION['user_id'], 'deleted', 'user', $userId, 'Deleted user account ' . $deletedUser['username'] . '.');
            $pdo->commit();

            $_SESSION['flash_success'] = "User account moved to the Trash Bin.";
            header("Location: ../admin/manage_users");
            exit;

        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Check for foreign key constraint violation (SQLSTATE 23000)
            if ($e->getCode() == '23000') {
                $_SESSION['flash_error'] = "Cannot delete this user because they have registered transaction history (e.g. recorded payments or course assignments). Deactivate the account instead.";
            } else {
                error_log("Delete user failed: " . $e->getMessage());
                $_SESSION['flash_error'] = "Database error: Failed to delete user.";
            }
            header("Location: ../admin/manage_users");
            exit;
        }
        break;

    case 'restore':
        $trashId = isset($_POST['trash_id']) ? (int)$_POST['trash_id'] : 0;
        try {
            $trashStmt = $pdo->prepare("SELECT * FROM deleted_items WHERE id = :id AND item_type = 'user' AND restored_at IS NULL LIMIT 1");
            $trashStmt->execute(['id' => $trashId]);
            $trash = $trashStmt->fetch();
            if (!$trash) {
                throw new \RuntimeException('Trash Bin item was not found or has already been restored.');
            }
            $snapshot = json_decode($trash['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $user = $snapshot['user'] ?? null;
            if (!$user) {
                throw new \RuntimeException('The deleted user snapshot is incomplete.');
            }

            $pdo->beginTransaction();
            $duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
            $duplicateStmt->execute(['username' => $user['username'], 'email' => $user['email']]);
            if ($duplicateStmt->fetch()) {
                throw new \RuntimeException('A user with the same username or email already exists.');
            }
            $restoreUser = $pdo->prepare('INSERT INTO users (username, password_hash, role, email, is_active, created_at) VALUES (:username, :password_hash, :role, :email, :is_active, :created_at)');
            $restoreUser->execute([
                'username' => $user['username'], 'password_hash' => $user['password_hash'], 'role' => $user['role'],
                'email' => $user['email'], 'is_active' => $user['is_active'], 'created_at' => $user['created_at']
            ]);
            $newUserId = (int)$pdo->lastInsertId();
            if (!empty($snapshot['student'])) {
                $student = $snapshot['student'];
                $activeTerm = requireActiveAcademicTerm($pdo);
                unset($student['id'], $student['user_id'], $student['created_at'], $student['updated_at']);
                $student['user_id'] = $newUserId;
                $student['academic_term_id'] = $activeTerm ? (int)$activeTerm['id'] : null;
                $student['application_status'] = in_array($student['application_status'] ?? null, ['approved', 'pending', 'under_review', 'needs_revision'], true) ? ($student['application_status'] ?? 'draft') : 'draft';
                $student['enrollment_status'] = in_array($student['enrollment_status'] ?? null, ['approved', 'paid', 'enrolled', 'pending', 'needs_revision'], true) ? ($student['enrollment_status'] ?? 'draft') : 'draft';
                $columns = array_keys($student);
                $params = array_map(static fn($column) => ':' . $column, $columns);
                $restoreStudent = $pdo->prepare('INSERT INTO students (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $params) . ')');
                $restoreStudent->execute($student);
            }
            $markRestored = $pdo->prepare('UPDATE deleted_items SET restored_at = CURRENT_TIMESTAMP, restored_by = :restored_by WHERE id = :id');
            $markRestored->execute(['restored_by' => (int)$_SESSION['user_id'], 'id' => $trashId]);
            writeAuditLog($pdo, (int)$_SESSION['user_id'], 'restored', 'user', $newUserId, 'Restored user account ' . $user['username'] . ' from the Trash Bin.');
            $pdo->commit();
            $_SESSION['flash_success'] = 'User account restored successfully.';
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = 'Restore failed: ' . $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Restore user failed: " . $e->getMessage());
            $_SESSION['flash_error'] = 'Restore failed: An unexpected error occurred. Please try again.';
        }
        header('Location: ../admin/trash_bin');
        exit;

    default:
        $_SESSION['flash_error'] = "Invalid action specified.";
        header("Location: ../admin/manage_users");
        exit;
}
