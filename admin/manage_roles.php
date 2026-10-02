<?php
require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';

$roles = ['admin', 'registrar', 'cashier', 'teacher', 'student', 'enrollee'];
$roleDescriptions = [
    'admin' => 'Full system access, user management, configuration, and recovery.',
    'registrar' => 'Reviews applications and manages students, courses, sections, and enrollment records.',
    'cashier' => 'Records student payments and issues official receipts.',
    'teacher' => 'Views assigned classes and student class lists.',
    'student' => 'Views enrollment, payments, profile information, and available subjects.',
    'enrollee' => 'Completes an application and monitors its review status.'
];
$counts = [];
$users = [];
$error = null;
$selectedRole = $_GET['role'] ?? '';
if (!in_array($selectedRole, $roles, true)) $selectedRole = '';
try {
    $stmt = $pdo->query("SELECT role, COUNT(*) AS total, SUM(is_active = 1) AS active_total FROM users GROUP BY role");
    foreach ($stmt->fetchAll() as $row) $counts[$row['role']] = $row;
    $sql = "SELECT id, username, email, role, is_active, created_at FROM users";
    $params = [];
    if ($selectedRole !== '') { $sql .= " WHERE role = :role"; $params['role'] = $selectedRole; }
    $sql .= " ORDER BY role, username";
    $userStmt = $pdo->prepare($sql); $userStmt->execute($params); $users = $userStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Role management fetch failed: ' . $e->getMessage());
    $error = 'Role information is temporarily unavailable.';
}

$page_title = 'Manage Roles';
require_once '../includes/header.php';
?>
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h3 class="m-0 text-navy-alt">Role Management</h3><p class="text-muted small m-0">Review portal roles and account assignments. Use User Management to edit roles.</p></div><a href="<?php echo $base_path; ?>admin/manage_users" class="btn btn-brand-primary"><i class="bi bi-people me-1"></i> Manage Users</a></div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<div class="row g-4 mb-4">
<?php foreach ($roles as $role): $total = (int)($counts[$role]['total'] ?? 0); $active = (int)($counts[$role]['active_total'] ?? 0); $isSelected = $selectedRole === $role; ?><div class="col-12 col-md-6 col-xl-4"><a href="?role=<?php echo urlencode($role); ?>" class="text-decoration-none"><div class="card card-premium shadow-sm h-100 <?php echo $isSelected ? 'border-primary' : ''; ?>"><div class="card-body card-body-premium"><div class="d-flex justify-content-between align-items-start"><div><span class="text-muted small uppercase fw-bold">Portal Role</span><h4 class="text-navy-alt text-capitalize mb-2"><?php echo htmlspecialchars($role); ?></h4></div><div class="stat-icon bg-primary-soft"><i class="bi bi-shield-check"></i></div></div><p class="text-muted small mb-3"><?php echo htmlspecialchars($roleDescriptions[$role]); ?></p><div class="d-flex justify-content-between border-top pt-3"><span class="text-muted">Accounts</span><strong><?php echo $total; ?></strong><span class="text-success"><i class="bi bi-check-circle me-1"></i><?php echo $active; ?> active</span></div></div></div></a></div><?php endforeach; ?>
</div>
<div class="card card-premium shadow-sm border-0 rounded-4 overflow-hidden mb-4" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-list-check"></i> <?php echo $selectedRole ? htmlspecialchars(ucfirst($selectedRole)) . ' Accounts' : 'All Account Assignments'; ?>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 220px;">
                <span class="input-group-text bg-white bg-opacity-25 border-0 text-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control form-control-sm border-0 bg-white bg-opacity-10 text-white placeholder-white" placeholder="Search accounts...">
            </div>
            <?php if ($selectedRole): ?>
                <a href="manage_roles" class="btn btn-xs btn-light text-navy fw-bold" style="min-height: 32px; padding: 4px 12px; font-size: 0.78rem;">Show All</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body card-body-premium p-0">
        <div>
            <table class="table table-hover table-maritime align-middle mb-0" id="rolesTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="user">User</th>
                        <th tabulator-field="email">Email</th>
                        <th tabulator-field="role" style="width: 130px;">Role</th>
                        <th tabulator-field="status" style="width: 120px;">Status</th>
                        <th class="pe-4" tabulator-field="created" style="width: 140px;">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users): foreach ($users as $user): ?>
                        <tr>
                            <td class="ps-4">
                                <strong class="text-navy"><?php echo htmlspecialchars($user['username']); ?></strong>
                                <small class="d-block text-muted">#<?php echo (int)$user['id']; ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><span class="badge bg-primary-subtle text-primary text-uppercase"><?php echo htmlspecialchars($user['role']); ?></span></td>
                            <td>
                                <?php if ((int)$user['is_active'] === 1): ?>
                                    <span class="badge-status status-approved">Active</span>
                                <?php else: ?>
                                    <span class="badge-status status-rejected">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-muted small"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="alert alert-info mt-4"><i class="bi bi-info-circle me-2"></i>Role changes should be performed through <a href="<?php echo $base_path; ?>admin/manage_users" class="alert-link">Manage Users</a> so the existing account validation and audit workflow remains in one place.</div>

<script>
    function stripHtml(html) {
        if (!html) return '';
        var tmp = document.createElement('DIV');
        tmp.innerHTML = html;
        return (tmp.textContent || tmp.innerText || '').trim();
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Tabulator === 'undefined') return;

        var rolesTable = new Tabulator("#rolesTable", {
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
            pagination: "local",
            paginationSize: 25,
            paginationSizeSelector: [10, 25, 50, 100],
            placeholder: "<div class='text-center text-muted py-4'><i class='bi bi-inbox fs-2 d-block mb-2'></i>No accounts found.</div>",
            columns: [
                { title: "User", field: "user", minWidth: 180, formatter: "html" },
                { title: "Email", field: "email", minWidth: 200, formatter: "html" },
                { title: "Role", field: "role", width: 130, formatter: "html" },
                { title: "Status", field: "status", width: 120, formatter: "html" },
                { title: "Created", field: "created", width: 140, formatter: "html" }
            ]
        });

        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    rolesTable.clearFilter();
                } else {
                    rolesTable.setFilter(function(data) {
                        return stripHtml(data.user).toLowerCase().includes(term) ||
                               stripHtml(data.email).toLowerCase().includes(term) ||
                               stripHtml(data.role).toLowerCase().includes(term) ||
                               stripHtml(data.status).toLowerCase().includes(term) ||
                               stripHtml(data.created).toLowerCase().includes(term);
                    });
                }
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
