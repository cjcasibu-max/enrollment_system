<?php
/**
 * LMS User & Role Management (TASK 1.1)
 * Administrative portal view to manage LMS users across all 4 permitted roles:
 * Student, Teacher, Registrar, and Admin.
 *
 * Provides:
 *   - User account creation for all 4 LMS roles (with student profile sync)
 *   - Profile editing (display names, email, role, optional password reset)
 *   - Account deactivation / reactivation (preserves historical coursework & grades)
 *   - Teacher-to-subject assignment / reassignment (upstream source of truth for teacher LMS)
 *   - Strict admin-only access enforcement
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/academic_terms.php';
require_once __DIR__ . '/../includes/lms_access.php';

// Strict Admin Gate
$adminSession = requireLmsAdminAccess();
$adminUserId  = (int)$adminSession['user_id'];

ensureCsrfToken();

// Fetch Active Academic Term
/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

// ── Fetch All LMS Users ────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.role, u.first_name, u.last_name, u.is_active, u.created_at,
               s.id AS student_record_id, s.program_code, s.year_level, s.enrollment_status,
               (SELECT COUNT(*) FROM section_subjects ss WHERE ss.instructor_id = u.id) AS assigned_subjects_count
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        WHERE u.role IN ('student', 'teacher', 'registrar', 'admin')
        ORDER BY u.id DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to fetch LMS users: " . $e->getMessage());
    $users = [];
}

// ── Fetch Section Subjects Available for Assignment ────────────────────────────
try {
    $ssStmt = $pdo->query("
        SELECT ss.id AS section_subject_id, ss.section_id, ss.subject_id, ss.instructor_id,
               sub.subject_code, sub.subject_name, sub.units,
               sec.section_name, sec.program, sec.year_level,
               at.school_year, at.semester,
               u_inst.first_name AS current_inst_fn, u_inst.last_name AS current_inst_ln, u_inst.username AS current_inst_un
        FROM section_subjects ss
        JOIN sections sec ON sec.id = ss.section_id
        JOIN subjects sub ON sub.id = ss.subject_id
        LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
        LEFT JOIN users u_inst ON u_inst.id = ss.instructor_id
        WHERE sec.status = 'active'
        ORDER BY at.school_year DESC, at.semester DESC, sec.section_name ASC, sub.subject_code ASC
    ");
    $availableSectionSubjects = $ssStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to fetch available section subjects: " . $e->getMessage());
    $availableSectionSubjects = [];
}

// ── Compute KPI Metrics ───────────────────────────────────────────────────────
$stats = [
    'total'            => count($users),
    'teachers_total'   => 0,
    'teachers_active'  => 0,
    'students_total'   => 0,
    'students_active'  => 0,
    'staff_total'      => 0,
    'total_assignments'=> 0,
];

foreach ($users as $u) {
    $isActive = (int)$u['is_active'] === 1;
    if ($u['role'] === 'teacher') {
        $stats['teachers_total']++;
        if ($isActive) $stats['teachers_active']++;
        $stats['total_assignments'] += (int)$u['assigned_subjects_count'];
    } elseif ($u['role'] === 'student') {
        $stats['students_total']++;
        if ($isActive) $stats['students_active']++;
    } elseif (in_array($u['role'], ['registrar', 'admin'], true)) {
        $stats['staff_total']++;
    }
}

$page_title = 'LMS User & Role Management — Administrator';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── ADMIN LMS NAVIGATION BAR ─────────────────────────────────────────── -->
<nav class="card shadow-sm mb-4 border-0" style="background:var(--sidebar-bg,#0b4f5c);" aria-label="Admin LMS navigation">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="text-white opacity-75 small fw-semibold me-2 text-nowrap" style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;">
                <i class="bi bi-shield-lock-fill me-1"></i>LMS Administration
            </span>
            <a href="lms_users" class="btn btn-sm text-white fw-bold active text-nowrap" style="background:rgba(255,255,255,.2);border-radius:6px;opacity:1;" aria-current="page">
                <i class="bi bi-people-fill me-1"></i>Users & Roles
            </a>
            <a href="lms_courses" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-journal-bookmark-fill me-1"></i>Courses & Offerings
            </a>
            <a href="lms_terms" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-calendar3-range-fill me-1"></i>Academic Terms
            </a>
            <a href="lms_settings" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-sliders me-1"></i>System Settings
            </a>
            <a href="lms_oversight" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-eye-fill me-1"></i>Oversight Console
            </a>
            <a href="../registrar/lms_enrollments" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-person-check-fill me-1"></i>LMS Enrollments
            </a>
            <a href="../registrar/lms_subjects" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-layers-fill me-1"></i>Subjects & Sections
            </a>
            <a href="../registrar/lms_grades" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-mortarboard-fill me-1"></i>Academic Grades
            </a>
            <a href="lms_reports" class="btn btn-sm text-white text-nowrap" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'">
                <i class="bi bi-file-earmark-bar-graph-fill me-1"></i>LMS Reports
            </a>
            <a href="manage_users" class="btn btn-sm text-white text-nowrap ms-auto" style="opacity:.85;" onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.85'" title="Full System User Accounts">
                <i class="bi bi-box-arrow-up-right me-1"></i>System User Console
            </a>
        </div>
    </div>
</nav>

<!-- ── BREADCRUMB ──────────────────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-brand-primary"><i class="bi bi-shield-shaded me-1"></i>Administrator</a></li>
        <li class="breadcrumb-item active" aria-current="page">LMS User & Role Management</li>
    </ol>
</nav>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-navy-alt mb-1">
            <i class="bi bi-person-gear me-2 text-brand-primary"></i>LMS User & Role Management
        </h1>
        <p class="text-muted mb-0" style="font-size:.9rem;">
            Create LMS accounts across all 4 roles, configure teacher subject assignments, and manage access privileges.
        </p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-brand-primary d-inline-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill"></i> Add LMS User
        </button>
    </div>
</div>

<!-- ── METRIC CARDS ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">LMS Accounts</span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">Total</span>
                </div>
                <div class="h3 fw-bold text-navy-alt mb-0"><?php echo number_format($stats['total']); ?></div>
                <div class="small text-muted mt-1">4 core LMS roles</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Teachers</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill"><?php echo $stats['teachers_active']; ?> Active</span>
                </div>
                <div class="h3 fw-bold text-success mb-0"><?php echo number_format($stats['teachers_total']); ?></div>
                <div class="small text-muted mt-1"><?php echo $stats['total_assignments']; ?> assigned offerings</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Cadets / Students</span>
                    <span class="badge bg-warning-subtle text-warning-dark border border-warning-subtle rounded-pill"><?php echo $stats['students_active']; ?> Active</span>
                </div>
                <div class="h3 fw-bold text-navy-alt mb-0"><?php echo number_format($stats['students_total']); ?></div>
                <div class="small text-muted mt-1">Enrolled & course learners</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-premium shadow-sm h-100 border-0">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Staff & Admins</span>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">Privileged</span>
                </div>
                <div class="h3 fw-bold text-danger mb-0"><?php echo number_format($stats['staff_total']); ?></div>
                <div class="small text-muted mt-1">Registrars & Admins</div>
            </div>
        </div>
    </div>
</div>

<!-- ── SEARCH & FILTER TOOLBAR ─────────────────────────────────────────────── -->
<div class="card card-premium shadow-sm mb-4 border-0">
    <div class="card-body card-body-premium py-3">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="userSearchInput" class="form-control" placeholder="Search by name, username, or email..." oninput="filterUsersTable()">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-person-badge text-muted"></i></span>
                    <select id="userRoleFilter" class="form-select" onchange="filterUsersTable()">
                        <option value="">All LMS Roles</option>
                        <option value="teacher">Teacher / Instructor</option>
                        <option value="student">Student / Cadet</option>
                        <option value="registrar">Registrar</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-toggle-on text-muted"></i></span>
                    <select id="userStatusFilter" class="form-select" onchange="filterUsersTable()">
                        <option value="">All Statuses</option>
                        <option value="active">Active Only</option>
                        <option value="deactivated">Deactivated</option>
                    </select>
                </div>
            </div>
            <div class="col-12 col-md-2 text-md-end">
                <button type="button" class="btn btn-outline-secondary w-100" onclick="resetUserFilters()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── USERS TABLE CARD ────────────────────────────────────────────────────── -->
<div class="card card-premium shadow-sm border-0">
    <div class="card-body card-body-premium p-0">
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="lmsUsersTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width: 80px;">ID</th>
                        <th>User Identity & Name</th>
                        <th style="width: 140px;">LMS Role</th>
                        <th>LMS Assignment / Profile</th>
                        <th style="width: 130px;">Account Status</th>
                        <th style="width: 130px;">Joined</th>
                        <th class="pe-4 text-end" style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-people display-6 d-block mb-2 text-muted opacity-50"></i>
                                No LMS user accounts found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u):
                            $userId      = (int)$u['id'];
                            $role        = $u['role'];
                            $isActive    = (int)$u['is_active'] === 1;
                            $isSelf      = ($userId === $adminUserId);
                            $displayName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            if (empty($displayName)) {
                                $displayName = $u['username'];
                            }

                            $roleBadgeClasses = [
                                'admin'     => 'bg-danger-subtle text-danger border-danger',
                                'registrar' => 'bg-brand-primary-subtle text-brand-primary border-brand-primary',
                                'teacher'   => 'bg-success-subtle text-success border-success',
                                'student'   => 'bg-warning-subtle text-warning-dark border-warning',
                            ];
                            $badgeClass = $roleBadgeClasses[$role] ?? 'bg-light text-dark';
                        ?>
                            <tr class="lms-user-row"
                                data-id="<?php echo $userId; ?>"
                                data-username="<?php echo htmlspecialchars(strtolower($u['username'])); ?>"
                                data-name="<?php echo htmlspecialchars(strtolower($displayName)); ?>"
                                data-email="<?php echo htmlspecialchars(strtolower($u['email'])); ?>"
                                data-role="<?php echo htmlspecialchars($role); ?>"
                                data-status="<?php echo $isActive ? 'active' : 'deactivated'; ?>">
                                
                                <td class="ps-4 text-muted small">#<?php echo $userId; ?></td>
                                
                                <td>
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="avatar-circle rounded-circle bg-light border text-brand-primary d-flex align-items-center justify-content-center fw-bold" style="width:36px;height:36px;font-size:0.85rem;">
                                            <?php echo strtoupper(substr($displayName, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-navy-alt d-flex align-items-center gap-1.5">
                                                <?php echo htmlspecialchars($displayName); ?>
                                                <?php if ($isSelf): ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill" style="font-size: 0.65rem; padding: 0.15rem 0.45rem;">(You)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                <span class="text-darker fw-medium">@<?php echo htmlspecialchars($u['username']); ?></span> &bull; <?php echo htmlspecialchars($u['email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge border <?php echo $badgeClass; ?> px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.3px;">
                                        <?php echo htmlspecialchars($role); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($role === 'teacher'): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-light text-navy-alt border px-2 py-1">
                                                <i class="bi bi-journal-check me-1 text-success"></i>
                                                <?php echo (int)$u['assigned_subjects_count']; ?> Assigned Subject(s)
                                            </span>
                                            <button type="button" class="btn btn-sm btn-outline-brand-primary py-0 px-2" style="font-size:0.75rem;" 
                                                    onclick="openTeacherAssignmentModal(<?php echo $userId; ?>, '<?php echo htmlspecialchars(addslashes($displayName)); ?>')">
                                                <i class="bi bi-pencil-square me-1"></i>Manage
                                            </button>
                                        </div>
                                    <?php elseif ($role === 'student'): ?>
                                        <div class="small">
                                            <span class="fw-semibold text-navy-alt"><?php echo htmlspecialchars($u['program_code'] ?: 'BSMT'); ?></span>
                                            <span class="text-muted">&bull; <?php echo htmlspecialchars($u['year_level'] ?: '1st Year'); ?></span>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle ms-1" style="font-size:0.65rem;"><?php echo htmlspecialchars($u['enrollment_status'] ?: 'enrolled'); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small"><i class="bi bi-check2-circle text-success me-1"></i>System Staff Privileges</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded-pill">
                                            <i class="bi bi-check-circle-fill me-1"></i>Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 rounded-pill">
                                            <i class="bi bi-slash-circle-fill me-1"></i>Deactivated
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-muted small">
                                    <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                                </td>

                                <td class="pe-4 text-end">
                                    <div class="d-inline-flex gap-1">
                                        <!-- Edit User Button -->
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit LMS User"
                                                onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode([
                                                    'id'         => $userId,
                                                    'username'   => $u['username'],
                                                    'email'      => $u['email'],
                                                    'first_name' => $u['first_name'],
                                                    'last_name'  => $u['last_name'],
                                                    'role'       => $role
                                                ])); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- Teacher Assign Subjects Shortcut -->
                                        <?php if ($role === 'teacher'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" title="Assign / Reassign Subjects"
                                                    onclick="openTeacherAssignmentModal(<?php echo $userId; ?>, '<?php echo htmlspecialchars(addslashes($displayName)); ?>')">
                                                <i class="bi bi-mortarboard"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Status Toggle (Deactivate / Reactivate) -->
                                        <?php if ($isSelf): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary opacity-50" disabled title="Cannot deactivate self">
                                                <i class="bi bi-slash-circle"></i>
                                            </button>
                                        <?php else: ?>
                                            <?php if ($isActive): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning" title="Deactivate LMS Access"
                                                        onclick="confirmStatusToggle(<?php echo $userId; ?>, '<?php echo htmlspecialchars(addslashes($displayName)); ?>', '<?php echo $role; ?>', 0)">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" title="Reactivate LMS Access"
                                                        onclick="confirmStatusToggle(<?php echo $userId; ?>, '<?php echo htmlspecialchars(addslashes($displayName)); ?>', '<?php echo $role; ?>', 1)">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
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
     MODAL: ADD LMS USER
     ========================================================================= -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addUserModalLabel">
                    <i class="bi bi-person-plus-fill me-2 text-white"></i>Create New LMS User Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="create_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="modal-body p-4">
                    <p class="text-muted small mb-4">
                        Create an authenticated user account across any of the 4 LMS roles. Student accounts will automatically be synchronized with the academic student directory.
                    </p>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="add_role" class="form-label fw-semibold">LMS Role <span class="text-danger">*</span></label>
                            <select name="role" id="add_role" class="form-select" required onchange="toggleStudentFields(this.value)">
                                <option value="">-- Select LMS Role --</option>
                                <option value="teacher">Teacher / Instructor</option>
                                <option value="student">Student / Cadet</option>
                                <option value="registrar">Registrar</option>
                                <option value="admin">Administrator</option>
                            </select>
                            <div class="invalid-feedback">Please select a valid LMS role.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="add_username" class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="add_username" class="form-control" required placeholder="e.g. jdoe_instructor" pattern="[a-zA-Z0-9_.-]{3,50}">
                            <div class="invalid-feedback">Valid username (3-50 letters/numbers/dots/hyphens) required.</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="add_first_name" class="form-label fw-semibold">First Name</label>
                            <input type="text" name="first_name" id="add_first_name" class="form-control" placeholder="First Name">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="add_last_name" class="form-label fw-semibold">Last Name</label>
                            <input type="text" name="last_name" id="add_last_name" class="form-control" placeholder="Last Name">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="add_email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="add_email" class="form-control" required placeholder="user@academy.edu.ph">
                            <div class="invalid-feedback">A valid email address is required.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="add_password" class="form-label fw-semibold">Initial Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="add_password" class="form-control" required minlength="6" placeholder="Minimum 6 characters">
                            <div class="invalid-feedback">Password must be at least 6 characters.</div>
                        </div>
                    </div>

                    <!-- Dynamic Student Specific Fields -->
                    <div id="studentSpecificFields" class="p-3 bg-light rounded-3 border mb-3" style="display: none;">
                        <h6 class="fw-bold text-navy-alt mb-2"><i class="bi bi-mortarboard-fill me-1 text-brand-primary"></i>Student Profile Details</h6>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="add_program" class="form-label fw-semibold">Academic Program</label>
                                <select name="program_code" id="add_program" class="form-select">
                                    <option value="BSMT">BS Marine Transportation (BSMT)</option>
                                    <option value="BSMarE">BS Marine Engineering (BSMarE)</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="add_year_level" class="form-label fw-semibold">Year Level</label>
                                <select name="year_level" id="add_year_level" class="form-select">
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                </select>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary d-inline-flex align-items-center gap-1">
                        <i class="bi bi-check-circle-fill"></i> Create LMS Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT LMS USER
     ========================================================================= -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="editUserModalLabel">
                    <i class="bi bi-pencil-square me-2 text-white"></i>Edit LMS User Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/lms_admin_actions" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="edit_role" class="form-label fw-semibold">LMS Role <span class="text-danger">*</span></label>
                            <select name="role" id="edit_role" class="form-select" required>
                                <option value="teacher">Teacher / Instructor</option>
                                <option value="student">Student / Cadet</option>
                                <option value="registrar">Registrar</option>
                                <option value="admin">Administrator</option>
                            </select>
                            <div class="invalid-feedback">Role is required.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="edit_username" class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                            <div class="invalid-feedback">Username is required.</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="edit_first_name" class="form-label fw-semibold">First Name</label>
                            <input type="text" name="first_name" id="edit_first_name" class="form-control">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="edit_last_name" class="form-label fw-semibold">Last Name</label>
                            <input type="text" name="last_name" id="edit_last_name" class="form-control">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="edit_email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                            <div class="invalid-feedback">Valid email is required.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="edit_password" class="form-label fw-semibold">Change Password <span class="text-muted small fw-normal">(Leave blank to keep current)</span></label>
                            <input type="password" name="password" id="edit_password" class="form-control" placeholder="••••••••" minlength="6">
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

<!-- =========================================================================
     MODAL: TEACHER SUBJECT ASSIGNMENTS
     ========================================================================= -->
<div class="modal fade" id="assignTeacherModal" tabindex="-1" aria-labelledby="assignTeacherModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="assignTeacherModalLabel">
                    <i class="bi bi-mortarboard-fill me-2 text-white"></i>Teacher Subject Assignments
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Instructor Name</div>
                        <h5 class="fw-bold text-navy-alt mb-0" id="assignTeacherModalName">—</h5>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1">Role: Teacher</span>
                </div>

                <!-- Form to assign a new subject -->
                <form action="../actions/lms_admin_actions" method="POST" class="card bg-light border p-3 mb-4 shadow-none">
                    <input type="hidden" name="action" value="assign_teacher_subject">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="teacher_id" id="assignTeacherModalId">
                    
                    <label for="assign_section_subject" class="form-label fw-bold text-navy-alt mb-1">
                        <i class="bi bi-plus-circle me-1 text-success"></i>Assign to Active Course / Section Offering
                    </label>
                    <div class="input-group">
                        <select name="section_subject_id" id="assign_section_subject" class="form-select" required>
                            <option value="">-- Choose Subject & Section Offering --</option>
                            <?php foreach ($availableSectionSubjects as $ss): 
                                $currTeacherNote = '';
                                if (!empty($ss['instructor_id'])) {
                                    $currTeacher = trim(($ss['current_inst_fn'] ?? '') . ' ' . ($ss['current_inst_ln'] ?? '')) ?: $ss['current_inst_un'];
                                    $currTeacherNote = " [Current: {$currTeacher}]";
                                } else {
                                    $currTeacherNote = " [Unassigned]";
                                }
                            ?>
                                <option value="<?php echo (int)$ss['section_subject_id']; ?>">
                                    <?php echo htmlspecialchars($ss['subject_code'] . ' - ' . $ss['subject_name'] . ' (' . $ss['section_name'] . ' &bull; ' . $ss['program'] . ' ' . $ss['year_level'] . ')' . $currTeacherNote); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-brand-primary">Assign Teacher</button>
                    </div>
                    <div class="form-text small text-muted">
                        Assigning an instructor updates the upstream <code>section_subjects.instructor_id</code> record that populates the Teacher LMS "My Subjects" workspace.
                    </div>
                </form>

                <h6 class="fw-bold text-navy-alt mb-2">
                    <i class="bi bi-list-check me-1 text-brand-primary"></i>Currently Assigned Course Offerings
                </h6>
                <div class="table-responsive border rounded-3">
                    <table class="table table-hover align-middle mb-0" id="teacherAssignedSubjectsTable">
                        <thead class="table-light small">
                            <tr>
                                <th>Subject Code</th>
                                <th>Course Title</th>
                                <th>Section & Term</th>
                                <th class="text-center">Units</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody id="teacherAssignedSubjectsBody">
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Loading assigned subjects...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
            <div class="modal-footer bg-light py-2.5">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     HIDDEN FORMS FOR SWEETALERT CONFIRMATIONS
     ========================================================================= -->
<form id="statusToggleForm" action="../actions/lms_admin_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="user_id" id="toggle_user_id">
    <input type="hidden" name="status" id="toggle_new_status">
</form>

<form id="unassignSubjectForm" action="../actions/lms_admin_actions" method="POST" style="display: none;">
    <input type="hidden" name="action" value="unassign_teacher_subject">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="section_subject_id" id="unassign_section_subject_id">
</form>

<script>
function toggleStudentFields(role) {
    const studentBox = document.getElementById('studentSpecificFields');
    if (role === 'student') {
        studentBox.style.display = 'block';
    } else {
        studentBox.style.display = 'none';
    }
}

function openEditUserModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_first_name').value = user.first_name || '';
    document.getElementById('edit_last_name').value = user.last_name || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_password').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function openTeacherAssignmentModal(teacherId, teacherName) {
    document.getElementById('assignTeacherModalId').value = teacherId;
    document.getElementById('assignTeacherModalName').textContent = teacherName;
    
    const tbody = document.getElementById('teacherAssignedSubjectsBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading assigned courses...</td></tr>';
    
    // Fetch assignments via AJAX
    fetch('../actions/lms_admin_actions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'get_teacher_assignments',
            teacher_id: teacherId,
            csrf_token: '<?php echo ensureCsrfToken(); ?>'
        })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success || !data.assignments || data.assignments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><i class="bi bi-info-circle me-1"></i>No courses currently assigned to this teacher.</td></tr>';
            return;
        }
        
        let html = '';
        data.assignments.forEach(item => {
            html += `
                <tr>
                    <td class="fw-bold text-navy-alt">${escapeHtml(item.subject_code)}</td>
                    <td>${escapeHtml(item.subject_name)}</td>
                    <td class="small text-muted">
                        <span class="fw-semibold text-dark">${escapeHtml(item.section_name)}</span> &bull; 
                        ${escapeHtml(item.school_year || '')} ${escapeHtml(item.semester || '')}
                    </td>
                    <td class="text-center">${escapeHtml(item.units || '3.0')}</td>
                    <td class="text-end pe-3">
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.75rem;" 
                                onclick="confirmUnassignSubject(${item.section_subject_id}, '${escapeHtml(item.subject_code)}', '${escapeHtml(item.section_name)}')">
                            <i class="bi bi-x-circle me-1"></i>Unassign
                        </button>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    })
    .catch(err => {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Error loading teacher assignments.</td></tr>';
    });

    const modal = new bootstrap.Modal(document.getElementById('assignTeacherModal'));
    modal.show();
}

function confirmStatusToggle(userId, username, role, newStatus) {
    const isDeactivating = (newStatus === 0);
    const actionTitle = isDeactivating ? 'Deactivate LMS Access?' : 'Reactivate LMS Access?';
    
    let textWarning = isDeactivating 
        ? `Are you sure you want to deactivate ${username}? They will immediately lose login access.`
        : `Are you sure you want to reactivate ${username}? They will immediately regain LMS login access.`;
        
    if (role === 'teacher' && isDeactivating) {
        textWarning += '\n\nNote: All historical coursework, quizzes, materials, and student grades created by this teacher will remain fully intact.';
    }

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: actionTitle,
            text: textWarning,
            icon: isDeactivating ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonColor: isDeactivating ? '#dc3545' : '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: isDeactivating ? 'Yes, Deactivate' : 'Yes, Reactivate'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('toggle_user_id').value = userId;
                document.getElementById('toggle_new_status').value = newStatus;
                document.getElementById('statusToggleForm').submit();
            }
        });
    } else {
        if (confirm(textWarning)) {
            document.getElementById('toggle_user_id').value = userId;
            document.getElementById('toggle_new_status').value = newStatus;
            document.getElementById('statusToggleForm').submit();
        }
    }
}

function confirmUnassignSubject(sectionSubjectId, subjectCode, sectionName) {
    const promptText = `Are you sure you want to unassign this teacher from ${subjectCode} (${sectionName})?`;
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Unassign Course Offering?',
            text: promptText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Unassign'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('unassign_section_subject_id').value = sectionSubjectId;
                document.getElementById('unassignSubjectForm').submit();
            }
        });
    } else {
        if (confirm(promptText)) {
            document.getElementById('unassign_section_subject_id').value = sectionSubjectId;
            document.getElementById('unassignSubjectForm').submit();
        }
    }
}

function filterUsersTable() {
    const searchVal = (document.getElementById('userSearchInput').value || '').toLowerCase().trim();
    const roleVal   = (document.getElementById('userRoleFilter').value || '').toLowerCase().trim();
    const statusVal = (document.getElementById('userStatusFilter').value || '').toLowerCase().trim();

    const rows = document.querySelectorAll('#lmsUsersTable tbody tr.lms-user-row');
    rows.forEach(row => {
        const username = row.getAttribute('data-username') || '';
        const name     = row.getAttribute('data-name') || '';
        const email    = row.getAttribute('data-email') || '';
        const role     = row.getAttribute('data-role') || '';
        const status   = row.getAttribute('data-status') || '';

        const matchesSearch = !searchVal || username.includes(searchVal) || name.includes(searchVal) || email.includes(searchVal);
        const matchesRole   = !roleVal || role === roleVal;
        const matchesStatus = !statusVal || status === statusVal;

        if (matchesSearch && matchesRole && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function resetUserFilters() {
    document.getElementById('userSearchInput').value = '';
    document.getElementById('userRoleFilter').value = '';
    document.getElementById('userStatusFilter').value = '';
    filterUsersTable();
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
