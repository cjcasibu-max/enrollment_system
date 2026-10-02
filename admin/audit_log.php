<?php
/**
 * Administrator Portal — System Audit Log
 * NCST Maritime Academy Design System
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';

// Retrieve distinct actors and actions for filter dropdowns
try {
    $actorsStmt = $pdo->query("
        SELECT DISTINCT a.actor_id, COALESCE(u.username, 'System') AS username 
        FROM audit_logs a 
        LEFT JOIN users u ON u.id = a.actor_id 
        ORDER BY username ASC
    ");
    $availableActors = $actorsStmt->fetchAll(PDO::FETCH_ASSOC);

    $actionsStmt = $pdo->query("
        SELECT DISTINCT action 
        FROM audit_logs 
        ORDER BY action ASC
    ");
    $availableActions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $availableActors = [];
    $availableActions = [];
    error_log('Audit log filter lookups failed: ' . $e->getMessage());
}

// Filter inputs
$selectedActor = isset($_GET['actor_id']) && $_GET['actor_id'] !== '' ? (int)$_GET['actor_id'] : null;
$selectedAction = trim($_GET['action_type'] ?? '');
$selectedFromDate = trim($_GET['date_from'] ?? '');
$selectedToDate = trim($_GET['date_to'] ?? '');
$searchKeyword = trim($_GET['search'] ?? '');

// Build query
$where = [];
$params = [];

if ($selectedActor !== null) {
    $where[] = "a.actor_id = :actor_id";
    $params['actor_id'] = $selectedActor;
}

if ($selectedAction !== '') {
    $where[] = "a.action = :action_type";
    $params['action_type'] = $selectedAction;
}

if ($selectedFromDate !== '') {
    $where[] = "DATE(a.created_at) >= :date_from";
    $params['date_from'] = $selectedFromDate;
}

if ($selectedToDate !== '') {
    $where[] = "DATE(a.created_at) <= :date_to";
    $params['date_to'] = $selectedToDate;
}

if ($searchKeyword !== '') {
    $where[] = "(a.description LIKE :search OR a.item_type LIKE :search OR a.action LIKE :search OR u.username LIKE :search OR CAST(a.item_id AS CHAR) LIKE :search)";
    $params['search'] = '%' . $searchKeyword . '%';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $sql = "
        SELECT a.*, COALESCE(u.username, 'System') AS username 
        FROM audit_logs a 
        LEFT JOIN users u ON u.id = a.actor_id 
        {$whereClause} 
        ORDER BY a.created_at DESC 
        LIMIT 250
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
    error_log('Audit log query failed: ' . $e->getMessage());
}

$isFiltered = ($selectedActor !== null || $selectedAction !== '' || $selectedFromDate !== '' || $selectedToDate !== '' || $searchKeyword !== '');

$page_title = 'Audit Log';
require_once '../includes/header.php';
?>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-shield-check me-1"></i> Security & Compliance</div>
        <h3 class="m-0 text-navy-alt fw-bold">Audit Log</h3>
        <p class="text-muted small m-0">Review system activity, role modifications, record restorations, and security events.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="dashboard" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1.5" style="min-height: 40px; padding: 6px 14px;">
            <i class="bi bi-grid"></i> Administration Desk
        </a>
    </div>
</div>

<!-- Filter Control Bar -->
<div class="card shadow-sm border-0 rounded-4 mb-4 p-3.5 bg-white" style="border: 1px solid #e0eded !important; box-shadow: 0 2px 14px rgba(11,155,152,0.06) !important;">
    <form method="GET" action="audit_log" class="row g-2.5 align-items-end">
        <!-- Actor Filter -->
        <div class="col-12 col-sm-6 col-lg-2">
            <label class="form-label small fw-bold text-muted mb-1 d-block" style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;">Actor / User</label>
            <select name="actor_id" class="form-select form-select-sm" style="min-height: 42px;">
                <option value="">All Actors</option>
                <?php foreach ($availableActors as $act): ?>
                    <option value="<?php echo htmlspecialchars($act['actor_id'] ?? ''); ?>" <?php echo ($selectedActor !== null && (int)$act['actor_id'] === $selectedActor) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($act['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Action Type Filter -->
        <div class="col-12 col-sm-6 col-lg-2">
            <label class="form-label small fw-bold text-muted mb-1 d-block" style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;">Action Type</label>
            <select name="action_type" class="form-select form-select-sm" style="min-height: 42px;">
                <option value="">All Actions</option>
                <?php foreach ($availableActions as $actName): ?>
                    <option value="<?php echo htmlspecialchars($actName); ?>" <?php echo ($selectedAction === $actName) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $actName))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- From Date -->
        <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label small fw-bold text-muted mb-1 d-block" style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;">From Date</label>
            <input type="date" name="date_from" class="form-control form-control-sm" style="min-height: 42px;" value="<?php echo htmlspecialchars($selectedFromDate); ?>">
        </div>

        <!-- To Date -->
        <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label small fw-bold text-muted mb-1 d-block" style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;">To Date</label>
            <input type="date" name="date_to" class="form-control form-control-sm" style="min-height: 42px;" value="<?php echo htmlspecialchars($selectedToDate); ?>">
        </div>

        <!-- Keyword Search -->
        <div class="col-12 col-lg-4">
            <label class="form-label small fw-bold text-muted mb-1 d-block" style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em;">Search Keyword</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="searchInput" name="search" class="form-control border-start-0 ps-0" placeholder="Search description, item, or ID..." value="<?php echo htmlspecialchars($searchKeyword); ?>" autocomplete="off">
                <button type="submit" class="btn btn-brand-primary px-3" title="Apply Filters"><i class="bi bi-funnel"></i></button>
                <?php if ($isFiltered): ?>
                    <a href="audit_log" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="bi bi-arrow-clockwise"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Audit Log Table Card -->
<div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <!-- Dark Teal Card Header with Record Counter -->
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-clock-history"></i> System Activity Records
        </h5>
        <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
            <?php echo count($logs); ?> Event<?php echo count($logs) !== 1 ? 's' : ''; ?>
        </span>
    </div>

    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <!-- Polished Design-System Empty State Component -->
            <div class="text-center py-5 px-3">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 76px; height: 76px; background: rgba(11, 155, 152, 0.08); color: var(--brand-primary); border: 1px solid rgba(11, 155, 152, 0.2);">
                    <i class="bi bi-shield-check fs-1"></i>
                </div>
                <h5 class="fw-bold text-navy-alt mb-1">No Activity Records Found</h5>
                <p class="text-muted small mb-3" style="max-width: 440px; margin: 0 auto; line-height: 1.5;">
                    <?php echo $isFiltered ? 'No audit entries match the selected filter criteria. Try adjusting your dates, actor, or search terms.' : 'No audit entries have been logged by system administrators or automated processes yet.'; ?>
                </p>
                <?php if ($isFiltered): ?>
                    <a href="audit_log" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5" style="min-height: 40px; padding: 6px 18px;">
                        <i class="bi bi-arrow-clockwise"></i> Clear Active Filters
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Mobile Scroll Affordance Banner -->
            <div class="mobile-scroll-hint d-md-none bg-light text-muted px-3 py-2 small border-bottom d-flex align-items-center gap-1.5">
                <i class="bi bi-arrows-expand text-brand-primary"></i> Scroll horizontally to view complete audit trail
            </div>

            <div>
                <table class="table table-hover table-maritime align-middle m-0" id="auditLogTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" tabulator-field="timestamp" style="width: 180px;">Timestamp</th>
                            <th tabulator-field="actor" style="width: 180px;">Administrator / Actor</th>
                            <th tabulator-field="action" style="width: 150px;">Action Performed</th>
                            <th tabulator-field="item" style="width: 160px;">Affected Entity</th>
                            <th class="pe-4" tabulator-field="details">Event Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $act = strtolower($log['action']);
                            $badgeColor = 'bg-secondary-subtle text-secondary border';
                            $rowClass = '';

                            // Badge color by action category
                            if (str_contains($act, 'delete')) {
                                $badgeColor = 'bg-danger-subtle text-danger border border-danger-subtle';
                            } elseif (str_contains($act, 'restore') || str_contains($act, 'approve')) {
                                $badgeColor = 'bg-success-subtle text-success border border-success-subtle';
                            } elseif (str_contains($act, 'create') || str_contains($act, 'add')) {
                                $badgeColor = 'bg-primary-subtle text-primary border border-primary-subtle';
                            } elseif (str_contains($act, 'toggle') || str_contains($act, 'update')) {
                                $badgeColor = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                            }

                            // Security-critical events get row-level accent
                            $isCritical = (
                                str_contains($act, 'login') ||
                                str_contains($act, 'password') ||
                                str_contains($act, 'role') ||
                                str_contains($act, 'permission') ||
                                str_contains($act, 'access') ||
                                str_contains($act, 'auth')
                            );
                            if ($isCritical) {
                                $rowClass = 'audit-critical-row';
                                $badgeColor = 'bg-danger text-white border border-danger';
                            }
                        ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="ps-4">
                                    <div class="small fw-semibold text-dark"><?php echo date('M d, Y', strtotime($log['created_at'])); ?></div>
                                    <div class="text-muted small font-monospace" style="font-size: 0.72rem;"><?php echo date('h:i:s A', strtotime($log['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold shadow-2xs" style="width: 30px; height: 30px; min-width: 30px; font-size: 0.72rem; background: var(--surface-tint); color: var(--brand-dark);">
                                            <?php echo strtoupper(substr($log['username'], 0, 2)); ?>
                                        </div>
                                        <div>
                                            <div class="small fw-bold text-navy"><?php echo htmlspecialchars($log['username']); ?></div>
                                            <div class="text-muted small font-monospace" style="font-size: 0.7rem;">ID #<?php echo (int)($log['actor_id'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badgeColor; ?> px-2.5 py-1 text-uppercase" style="font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; border-radius: 999px;">
                                        <?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['item_type']))); ?></div>
                                    <div class="text-muted small font-monospace" style="font-size: 0.72rem;">Entity #<?php echo (int)$log['item_id']; ?></div>
                                </td>
                                <td class="pe-4">
                                    <div class="small text-dark" style="min-width: 220px; line-height: 1.45; word-break: break-word;">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function stripHtml(html) {
        var tmp = document.createElement("DIV");
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || "";
    }

    let auditLogTable = null;
    document.addEventListener("DOMContentLoaded", function() {
        if (!document.getElementById("auditLogTable")) return;

        auditLogTable = new Tabulator("#auditLogTable", {
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
            placeholder: '<div class="text-center py-5 text-muted"><i class="bi bi-shield-check fs-1 d-block mb-2 text-muted-light"></i>No audit entries match the criteria.</div>',
            columns: [
                {
                    title: "Timestamp",
                    field: "timestamp",
                    formatter: "html",
                    minWidth: 160,
                    sorter: function(a, b) {
                        const dateA = new Date(stripHtml(a)).getTime() || 0;
                        const dateB = new Date(stripHtml(b)).getTime() || 0;
                        return dateA - dateB;
                    },
                    responsive: 1
                },
                {
                    title: "Administrator / Actor",
                    field: "actor",
                    formatter: "html",
                    minWidth: 160,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 0
                },
                {
                    title: "Action Performed",
                    field: "action",
                    formatter: "html",
                    minWidth: 130,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 0
                },
                {
                    title: "Affected Entity",
                    field: "item",
                    formatter: "html",
                    minWidth: 140,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 2
                },
                {
                    title: "Event Details",
                    field: "details",
                    formatter: "html",
                    minWidth: 200,
                    headerSort: false,
                    responsive: 0
                }
            ]
        });

        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const val = (this.value || '').toLowerCase().trim();
                if (!val) {
                    auditLogTable.clearFilter();
                } else {
                    auditLogTable.setFilter(function(data) {
                        return stripHtml(data.actor || '').toLowerCase().includes(val) ||
                               stripHtml(data.action || '').toLowerCase().includes(val) ||
                               stripHtml(data.item || '').toLowerCase().includes(val) ||
                               stripHtml(data.details || '').toLowerCase().includes(val);
                    });
                }
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
