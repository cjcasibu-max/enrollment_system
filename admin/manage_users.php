<?php
/**
 * Manage Users Page
 * Provides full CRUD operations for user accounts and associated student profiles.
 * Secured to Admin role only. Integrates SweetAlert2.
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);

require_once '../config/database.php';
ensureCsrfToken();

// Fetch all users with their optional student profile information
try {
    $stmt = $pdo->query("
        SELECT u.*, 
               s.first_name, s.last_name, s.birthdate, s.address_street, s.contact_number, s.enrollment_status
        FROM users u 
        LEFT JOIN students s ON u.id = s.user_id 
        ORDER BY u.id DESC
    ");
    $users = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch users failed: " . $e->getMessage());
    $users = [];
}

$page_title = "Manage Users";
require_once '../includes/header.php';
?>

<style>
#usersTable {
    width: 100% !important;
    min-width: 0 !important;
}
#usersTable .btn-sm,
#usersTable .btn {
    min-height: 32px;
    min-width: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">User Management</h3>
        <p class="text-muted small m-0">Create, edit, deactivate, or delete user accounts across all 6 portal roles.</p>
    </div>
    <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus-fill fs-5"></i> Add New User
    </button>
</div>

<!-- Search & Filter Card -->
<div class="card card-premium shadow-sm mb-4">
    <div class="card-body card-body-premium py-3">
        <div class="row g-3 align-items-center">
            <!-- Search Bar -->
            <div class="col-12 col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchInput" class="form-control" placeholder="Search by username, email, or name..." oninput="scheduleUserFilter()">
                </div>
            </div>
            
            <!-- Filter by Role -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-shield-check"></i></span>
                    <select id="roleFilter" class="form-select" onchange="filterUsersTable()">
                        <option value="">All Roles</option>
                        <option value="admin">Administrator</option>
                        <option value="registrar">Registrar</option>
                        <option value="cashier">Cashier</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                        <option value="enrollee">Enrollee</option>
                    </select>
                </div>
            </div>
            
            <!-- Filter by Status -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-check-circle"></i></span>
                    <select id="statusFilter" class="form-select" onchange="filterUsersTable()">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="deactivated">Deactivated</option>
                    </select>
                </div>
            </div>
            
            <!-- Reset Button -->
            <div class="col-12 col-md-2 text-md-end">
                <button type="button" class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Users List -->
<div class="card card-premium shadow-sm">
    <div class="card-body card-body-premium p-0">
        <!-- Mobile Scroll Affordance Banner -->
        <div class="mobile-scroll-hint table-scroll-hint d-md-none bg-light text-muted px-3 py-2 small border-bottom d-flex align-items-center gap-1.5">
            <i class="bi bi-arrows-expand text-brand-primary"></i> Scroll horizontally to view complete user management details
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="id" style="width: 85px;">ID</th>
                        <th tabulator-field="identity">User Identity</th>
                        <th tabulator-field="role" style="width: 140px;">Role</th>
                        <th tabulator-field="profile">Associated Profile</th>
                        <th tabulator-field="status" style="width: 130px;">Status</th>
                        <th tabulator-field="created_at" style="width: 140px;">Created At</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $u): 
                            $roleBadges = [
                                'admin'     => 'bg-danger-subtle text-danger border-danger',
                                'registrar' => 'bg-brand-primary-subtle text-brand-primary border-brand-primary',
                                'cashier'   => 'bg-success-subtle text-success border-success',
                                'teacher'   => 'bg-brand-primary-subtle text-brand-primary border-brand-primary',
                                'student'   => 'bg-warning-subtle text-warning-dark border-warning',
                                'enrollee'  => 'bg-secondary-subtle text-secondary border-secondary'
                            ];
                            $badgeClass = isset($roleBadges[$u['role']]) ? $roleBadges[$u['role']] : 'bg-light text-dark';
                            
                            $fullName = '—';
                            if (in_array($u['role'], ['student', 'enrollee']) && !empty($u['first_name'])) {
                                $fullName = htmlspecialchars($u['first_name'] . ' ' . $u['last_name']);
                            }
                            
                            $isActive = (int)$u['is_active'] === 1;
                            $isSelf = (int)$u['id'] === (int)($_SESSION['user_id'] ?? 0);
                        ?>
                            <tr class="user-row" 
                                data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                data-email="<?php echo htmlspecialchars($u['email']); ?>"
                                data-role="<?php echo htmlspecialchars($u['role']); ?>"
                                data-fullname="<?php echo strtolower($fullName); ?>"
                                data-status="<?php echo $isActive ? 'active' : 'deactivated'; ?>">
                                
                                <td class="ps-4 text-muted small">#<?php echo $u['id']; ?></td>
                                <td>
                                    <div class="fw-semibold text-darker d-flex align-items-center gap-2">
                                        <?php echo htmlspecialchars($u['username']); ?>
                                        <?php if ($isSelf): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill" style="font-size: 0.65rem; padding: 0.2rem 0.5rem;">(You)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($u['email']); ?></div>
                                </td>
                                <td>
                                    <span class="badge border <?php echo $badgeClass; ?> px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.3px;">
                                        <?php echo htmlspecialchars($u['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($fullName !== '—'): ?>
                                        <div class="fw-medium text-dark"><?php echo $fullName; ?></div>
                                        <div class="text-muted small text-capitalize" style="font-size: 0.75rem;">
                                            Status: <span class="fw-semibold"><?php echo htmlspecialchars($u['enrollment_status']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success-subtle text-success px-2 py-1 small rounded-pill">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger px-2 py-1 small rounded-pill">Deactivated</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                <td class="pe-4 text-end">
                                    <div class="d-inline-flex gap-2">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-secondary"
                                                title="Edit Account"
                                                data-user-id="<?php echo (int)$u['id']; ?>"
                                                onclick="openEditModal(this.dataset.userId)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        
                                        <!-- Status Toggle Action -->
                                        <?php if ($isSelf): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-secondary opacity-50" 
                                                    disabled 
                                                    title="Cannot deactivate your own active session" 
                                                    style="cursor: not-allowed;">
                                                <i class="bi bi-slash-circle"></i>
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-secondary opacity-50" 
                                                    disabled 
                                                    title="Cannot delete your own active session" 
                                                    style="cursor: not-allowed;">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        <?php else: ?>
                                            <?php if ($isActive): ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-warning" 
                                                        title="Deactivate Account"
                                                        data-user-id="<?php echo (int)$u['id']; ?>"
                                                        data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-active="0"
                                                        onclick="confirmStatusToggle(this.dataset.userId, this.dataset.username, 0)">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-success" 
                                                        title="Activate Account"
                                                        data-user-id="<?php echo (int)$u['id']; ?>"
                                                        data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-active="1"
                                                        onclick="confirmStatusToggle(this.dataset.userId, this.dataset.username, 1)">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <!-- Delete Action -->
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger" 
                                                    title="Delete Account"
                                                    data-user-id="<?php echo (int)$u['id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="confirmDelete(this.dataset.userId, this.dataset.username)">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: ADD USER
     ========================================================================= -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addUserModalLabel">
                    <i class="bi bi-person-plus-fill me-2 text-white"></i>Create New User Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/user_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-body p-4">
                    
                    <h6 class="text-navy-alt fw-bold mb-3 border-bottom pb-1">Core Account details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6">
                            <label for="add_username" class="form-label fw-medium">Username</label>
                            <input type="text" name="username" id="add_username" class="form-control" required autocomplete="username">
                            <div class="invalid-feedback">Username is required.</div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="add_email" class="form-label fw-medium">Email Address</label>
                            <input type="email" name="email" id="add_email" class="form-control" required autocomplete="email">
                            <div class="invalid-feedback">Provide a valid email address.</div>
                        </div>
                        <div class="col-12 col-sm-6" id="add_password_wrapper">
                            <label for="add_password" class="form-label fw-medium">Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="add_password" class="form-control" required autocomplete="new-password">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="add_password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                                <div class="invalid-feedback">Password is required.</div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="add_role" class="form-label fw-medium">User Role</label>
                            <select name="role" id="add_role" class="form-select" required onchange="toggleProfileFields(this.value, 'add')">
                                <option value="" selected disabled>Select role...</option>
                                <option value="admin">Administrator</option>
                                <option value="registrar">Registrar</option>
                                <option value="cashier">Cashier</option>
                                <option value="teacher">Teacher</option>
                            </select>
                            <div class="invalid-feedback">Select an account role.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="add_is_active" checked value="1">
                                <label class="form-check-label fw-semibold" for="add_is_active">Set account as Active</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dynamic Student Profile Fields Section -->
                    <div id="add_student_profile_fields" style="display: none;">
                        <h6 class="text-navy-alt fw-bold mb-3 border-bottom pb-1">Student Profile Details</h6>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label for="add_first_name" class="form-label fw-medium">First Name</label>
                                <input type="text" name="first_name" id="add_first_name" class="form-control">
                                <div class="invalid-feedback">First name is required for students.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="add_last_name" class="form-label fw-medium">Last Name</label>
                                <input type="text" name="last_name" id="add_last_name" class="form-control">
                                <div class="invalid-feedback">Last name is required for students.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="add_birthdate" class="form-label fw-medium">Birthdate</label>
                                <input type="date" name="birthdate" id="add_birthdate" class="form-control" max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
                                <div class="invalid-feedback">Birthdate is required for students.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="add_contact_number" class="form-label fw-medium">Contact Number</label>
                                <input type="tel" name="contact_number" id="add_contact_number" class="form-control" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="col-12" id="add_enrollment_status_wrapper">
                                <label for="add_enrollment_status" class="form-label fw-medium">Enrollment Status</label>
                                <select name="enrollment_status" id="add_enrollment_status" class="form-select">
                                    <option value="pending">Pending Application</option>
                                    <option value="approved" selected>Approved (Ready for Payment)</option>
                                    <option value="paid">Paid</option>
                                    <option value="enrolled">Enrolled</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="add_address" class="form-label fw-medium">Home Address</label>
                                <textarea name="address" id="add_address" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT USER
     ========================================================================= -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="editUserModalLabel">
                    <i class="bi bi-pencil-square me-2 text-white"></i>Edit User Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/user_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body p-4">
                    
                    <h6 class="text-navy-alt fw-bold mb-3 border-bottom pb-1">Core Account details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6">
                            <label for="edit_username" class="form-label fw-medium">Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required autocomplete="username">
                            <div class="invalid-feedback">Username is required.</div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="edit_email" class="form-label fw-medium">Email Address</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required autocomplete="email">
                            <div class="invalid-feedback">Provide a valid email address.</div>
                        </div>
                        <div class="col-12 col-sm-6" id="edit_password_wrapper">
                            <label for="edit_password" class="form-label fw-medium">New Password <span class="text-muted small fw-normal">(Leave blank to keep current)</span></label>
                            <div class="input-group">
                                <input type="password" name="password" id="edit_password" class="form-control" placeholder="••••••••" autocomplete="new-password">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="edit_password" aria-label="Toggle password visibility">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="edit_role" class="form-label fw-medium">User Role</label>
                            <select name="role" id="edit_role" class="form-select" required onchange="toggleProfileFields(this.value, 'edit')">
                                <option value="admin">Administrator</option>
                                <option value="registrar">Registrar</option>
                                <option value="cashier">Cashier</option>
                                <option value="teacher">Teacher</option>
                                <option value="student">Student</option>
                                <option value="enrollee">Enrollee</option>
                            </select>
                            <div class="invalid-feedback">Select an account role.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label fw-semibold" for="edit_is_active">Set account as Active</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dynamic Student Profile Fields Section -->
                    <div id="edit_student_profile_fields" style="display: none;">
                        <h6 class="text-navy-alt fw-bold mb-3 border-bottom pb-1">Student Profile Details</h6>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label for="edit_first_name" class="form-label fw-medium">First Name</label>
                                <input type="text" name="first_name" id="edit_first_name" class="form-control">
                                <div class="invalid-feedback">First name is required for students.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="edit_last_name" class="form-label fw-medium">Last Name</label>
                                <input type="text" name="last_name" id="edit_last_name" class="form-control">
                                <div class="invalid-feedback">Last name is required for students.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="edit_birthdate" class="form-label fw-medium">Birthdate</label>
                                <input type="date" name="birthdate" id="edit_birthdate" class="form-control" max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
                                <div class="invalid-feedback">Birthdate is required for students.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="edit_contact_number" class="form-label fw-medium">Contact Number</label>
                                <input type="tel" name="contact_number" id="edit_contact_number" class="form-control" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="col-12" id="edit_enrollment_status_wrapper">
                                <label for="edit_enrollment_status" class="form-label fw-medium">Enrollment Status</label>
                                <select name="enrollment_status" id="edit_enrollment_status" class="form-select">
                                    <option value="pending">Pending Application</option>
                                    <option value="approved">Approved</option>
                                    <option value="paid">Paid</option>
                                    <option value="enrolled">Enrolled</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="edit_address" class="form-label fw-medium">Home Address</label>
                                <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden forms for processing status toggle and delete -->
<form id="statusToggleForm" action="../actions/user_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="user_id" id="status_user_id">
    <input type="hidden" name="is_active" id="status_is_active">
</form>

<form id="deleteForm" action="../actions/user_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<!-- =========================================================================
     JAVASCRIPT LOGIC
     ========================================================================= -->
<script>
    // Move modals to body on load to prevent parent stacking contexts (like .animated-fade-in) from trapping backdrops
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('input[name="contact_number"]').forEach(function(input) {
            input.addEventListener('input', function() { input.value = input.value.replace(/\D/g, '').slice(0, 11); });
        });
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            document.body.appendChild(modal);
        });
    });

    // Toggles the visibility of student-specific profile fields and password input dynamically
    function toggleProfileFields(role, prefix) {
        const profileFields = document.getElementById(prefix + '_student_profile_fields');
        const firstName = document.getElementById(prefix + '_first_name');
        const lastName = document.getElementById(prefix + '_last_name');
        const birthdate = document.getElementById(prefix + '_birthdate');
        const statusWrapper = document.getElementById(prefix + '_enrollment_status_wrapper');
        const passwordWrapper = document.getElementById(prefix + '_password_wrapper');
        const passwordInput = document.getElementById(prefix + '_password');

        const isStudentOrEnrollee = (role === 'student' || role === 'enrollee');

        // Toggle Password visibility and required constraint (FUNC-006)
        if (passwordWrapper && passwordInput) {
            if (isStudentOrEnrollee) {
                passwordWrapper.style.display = 'none';
                passwordInput.required = false;
                passwordInput.value = '';
            } else {
                passwordWrapper.style.display = '';
                if (prefix === 'add') {
                    passwordInput.required = true;
                } else {
                    passwordInput.required = false;
                }
            }
        }

        // Toggle Student Profile Fields
        if (isStudentOrEnrollee) {
            if (profileFields) profileFields.style.display = 'block';
            if (firstName) firstName.required = true;
            if (lastName) lastName.required = true;
            if (birthdate) birthdate.required = true;
            if (statusWrapper) statusWrapper.style.display = 'block';
        } else {
            if (profileFields) profileFields.style.display = 'none';
            if (firstName) firstName.required = false;
            if (lastName) lastName.required = false;
            if (birthdate) birthdate.required = false;
        }
    }

    // Opens edit modal — fetches full user data from user_detail_api to avoid
    // embedding PII in the HTML source. Accepts a numeric user ID.
    function openEditModal(userId) {
        fetch('user_detail_api?user_id=' + encodeURIComponent(userId), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (!json.success || !json.data) throw new Error(json.message || 'Not found.');
            const userData = json.data;
            document.getElementById('edit_user_id').value = userData.id;
            document.getElementById('edit_username').value = userData.username;
            document.getElementById('edit_email').value = userData.email;
            document.getElementById('edit_role').value = userData.role;
            document.getElementById('edit_password').value = ''; // Keep blank

            document.getElementById('edit_is_active').checked = parseInt(userData.is_active) === 1;

            // Populate profile fields
            document.getElementById('edit_first_name').value = userData.first_name || '';
            document.getElementById('edit_last_name').value = userData.last_name || '';
            document.getElementById('edit_birthdate').value = userData.birthdate || '';
            document.getElementById('edit_contact_number').value = userData.contact_number || '';
            document.getElementById('edit_address').value = userData.address_street || '';
            document.getElementById('edit_enrollment_status').value = userData.enrollment_status || 'pending';

            // Update display states
            toggleProfileFields(userData.role, 'edit');

            // Show modal
            var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
            editModal.show();
        })
        .catch(function () {
            Swal.fire({
                icon: 'error',
                title: 'Unable to load user',
                text: 'The user details could not be fetched. Please try again.',
                iconColor: '#d9535f',
                confirmButtonColor: '#6c757d',
                background: '#ffffff',
                color: '#1f2937'
            });
        });
    }

    // Trigger SweetAlert2 dialog to confirm account activation/deactivation status toggle
    function confirmStatusToggle(userId, username, isActive) {
        const actionText = isActive ? "activate" : "deactivate";
        const themeColor = isActive ? "var(--color-success)" : "var(--brand-accent-orange)";
        
        Swal.fire({
            title: 'Modify Account Status?',
            text: `Are you sure you want to ${actionText} the user account "${username}"?`,
            icon: 'warning',
            iconColor: '#d99a1d',
            showCancelButton: true,
            confirmButtonColor: themeColor,
            cancelButtonColor: '#6c757d',
            confirmButtonText: `Yes, ${actionText} it!`,
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#1f2937'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('status_user_id').value = userId;
                document.getElementById('status_is_active').value = isActive;
                document.getElementById('statusToggleForm').submit();
            }
        });
    }

    // Trigger SweetAlert2 confirmation dialog before deleting an account
    function confirmDelete(userId, username) {
        Swal.fire({
            title: 'Delete Account Permanently?',
            text: `You are about to delete user "${username}". This will also remove their associated profile and cannot be undone!`,
            icon: 'question',
            iconColor: '#d9535f',
            showCancelButton: true,
            confirmButtonColor: 'var(--color-danger)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete permanently!',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#1f2937'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_user_id').value = userId;
                document.getElementById('deleteForm').submit();
            }
        });
    }

    // Helper function to extract plain text from HTML string for clean sorting/searching
    function stripHtml(html) {
        if (!html) return '';
        var tmp = document.createElement('DIV');
        tmp.innerHTML = html;
        return (tmp.textContent || tmp.innerText || '').trim();
    }

    // Tabulator.js Initialization
    let usersTable = null;

    document.addEventListener("DOMContentLoaded", function() {
        usersTable = new Tabulator("#usersTable", {
            layout: "fitColumns",
            responsiveLayout: "collapse",
            responsiveLayoutCollapseStartOpen: false,
            rowHeader: {
                formatter: "responsiveCollapse",
                width: 36,
                minWidth: 36,
                hozAlign: "center",
                resizable: false,
                headerSort: false
            },
            pagination: true,
            paginationSize: 25,
            paginationSizeSelector: [10, 25, 50, 100],
            paginationCounter: "rows",
            placeholder: '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 text-muted-light"></i>No user accounts found.</div>',
            columns: [
                {
                    title: "ID",
                    field: "id",
                    formatter: "html",
                    width: 85,
                    minWidth: 70,
                    sorter: function(a, b) {
                        const numA = parseInt(String(stripHtml(a)).replace(/\D/g, ''), 10) || 0;
                        const numB = parseInt(String(stripHtml(b)).replace(/\D/g, ''), 10) || 0;
                        return numA - numB;
                    },
                    responsive: 3
                },
                {
                    title: "User Identity",
                    field: "identity",
                    formatter: "html",
                    minWidth: 160,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 0
                },
                {
                    title: "Role",
                    field: "role",
                    formatter: "html",
                    width: 140,
                    minWidth: 100,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 2
                },
                {
                    title: "Associated Profile",
                    field: "profile",
                    formatter: "html",
                    minWidth: 150,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 2
                },
                {
                    title: "Status",
                    field: "status",
                    formatter: "html",
                    width: 130,
                    minWidth: 90,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 2
                },
                {
                    title: "Created At",
                    field: "created_at",
                    formatter: "html",
                    width: 140,
                    minWidth: 110,
                    sorter: function(a, b) {
                        const dateA = new Date(stripHtml(a)).getTime() || 0;
                        const dateB = new Date(stripHtml(b)).getTime() || 0;
                        return dateA - dateB;
                    },
                    responsive: 4
                },
                {
                    title: "Actions",
                    field: "actions",
                    formatter: "html",
                    headerSort: false,
                    hozAlign: "right",
                    width: 160,
                    minWidth: 150,
                    responsive: 0
                }
            ]
        });
    });

    // Client-side search and filtering functionality with Tabulator
    let userFilterFrame = 0;

    function scheduleUserFilter() {
        if (userFilterFrame) return;
        userFilterFrame = window.requestAnimationFrame(function () {
            userFilterFrame = 0;
            filterUsersTable();
        });
    }

    function filterUsersTable() {
        if (!usersTable) return;
        
        const searchInput = (document.getElementById('searchInput').value || '').toLowerCase().trim();
        const roleFilter = (document.getElementById('roleFilter').value || '').toLowerCase().trim();
        const statusFilter = (document.getElementById('statusFilter').value || '').toLowerCase().trim();

        if (!searchInput && !roleFilter && !statusFilter) {
            usersTable.clearFilter();
            return;
        }

        usersTable.setFilter(function(data) {
            const idText = stripHtml(data.id || '').toLowerCase();
            const identityText = stripHtml(data.identity || '').toLowerCase();
            const roleText = stripHtml(data.role || '').toLowerCase();
            const profileText = stripHtml(data.profile || '').toLowerCase();
            const statusText = stripHtml(data.status || '').toLowerCase();

            // 1. Text Search across username, email, full name, ID, role
            const matchesSearch = !searchInput || 
                identityText.includes(searchInput) || 
                profileText.includes(searchInput) ||
                roleText.includes(searchInput) ||
                idText.includes(searchInput);

            // 2. Role filter
            const matchesRole = !roleFilter || roleText === roleFilter;

            // 3. Status filter
            const matchesStatus = !statusFilter || statusText === statusFilter;

            return matchesSearch && matchesRole && matchesStatus;
        });
    }

    // Reset all filter options
    function resetFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('roleFilter').value = '';
        document.getElementById('statusFilter').value = '';
        if (usersTable) {
            usersTable.clearFilter();
        }
    }
</script>

<?php
require_once '../includes/footer.php';
?>
