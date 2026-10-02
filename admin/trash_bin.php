<?php
/**
 * Administrator Portal — Trash Bin
 * NCST Maritime Academy Design System
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';

try {
    $stmt = $pdo->query("
        SELECT d.*, u.username AS deleted_by_name 
        FROM deleted_items d 
        LEFT JOIN users u ON u.id = d.deleted_by 
        WHERE d.restored_at IS NULL 
        ORDER BY d.deleted_at DESC
    ");
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    $items = [];
    error_log('Trash Bin query failed: ' . $e->getMessage());
}

$page_title = 'Trash Bin';
require_once '../includes/header.php';
?>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-trash3-fill me-1"></i> System Maintenance</div>
        <h3 class="m-0 text-navy-alt fw-bold">Trash Bin</h3>
        <p class="text-muted small m-0">Review archived records and restore deleted user accounts or configurations.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="manage_users" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1.5" style="min-height: 40px; padding: 6px 14px;">
            <i class="bi bi-people"></i> Manage Users
        </a>
    </div>
</div>

<!-- Trash Bin Table Card -->
<div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <!-- Dark Teal Card Header with Record Counter -->
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-archive-fill"></i> Archived Deleted Records
        </h5>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 220px;">
                <span class="input-group-text bg-white bg-opacity-25 border-0 text-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control form-control-sm border-0 bg-white bg-opacity-10 text-white placeholder-white" placeholder="Search trash bin...">
            </div>
            <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
                <?php echo count($items); ?> Record<?php echo count($items) !== 1 ? 's' : ''; ?>
            </span>
        </div>
    </div>

    <div class="card-body p-0">
        <div>
            <table class="table table-hover table-maritime align-middle m-0" id="trashBinTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="entity">Item / Entity</th>
                        <th tabulator-field="type" style="width: 160px;">Item Type</th>
                        <th tabulator-field="archived_by" style="width: 180px;">Archived By</th>
                        <th tabulator-field="archived_date" style="width: 180px;">Archived Date</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): foreach ($items as $item): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-navy"><?php echo htmlspecialchars($item['display_name']); ?></div>
                                <div class="text-muted small font-monospace">Record #<?php echo (int)$item['id']; ?></div>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--surface-tint); color: var(--brand-dark); border: 1px solid var(--brand-primary-soft); font-size: 0.74rem; font-weight: 600; padding: 4px 10px; border-radius: 999px;">
                                    <?php echo htmlspecialchars(ucfirst($item['item_type'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($item['deleted_by_name'] ?? 'System Admin'); ?></div>
                                <div class="text-muted small">ID #<?php echo (int)$item['deleted_by']; ?></div>
                            </td>
                            <td>
                                <div class="small text-dark"><?php echo date('M d, Y', strtotime($item['deleted_at'])); ?></div>
                                <div class="text-muted small"><?php echo date('h:i A', strtotime($item['deleted_at'])); ?></div>
                            </td>
                            <td class="pe-4 text-end">
                                <form action="../actions/user_actions" method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="trash_id" value="<?php echo (int)$item['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success d-inline-flex align-items-center justify-content-center gap-1" style="min-height: 40px; padding: 6px 14px; font-weight: 600;" title="Restore this record">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function stripHtml(html) {
        var tmp = document.createElement("DIV");
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || "";
    }

    let trashBinTable = null;
    document.addEventListener("DOMContentLoaded", function() {
        if (!document.getElementById("trashBinTable")) return;

        trashBinTable = new Tabulator("#trashBinTable", {
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
            placeholder: '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 text-muted-light"></i>No archived items match your search.</div>',
            columns: [
                {
                    title: "Item / Entity",
                    field: "entity",
                    formatter: "html",
                    minWidth: 160,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 0
                },
                {
                    title: "Item Type",
                    field: "type",
                    formatter: "html",
                    width: 160,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 1
                },
                {
                    title: "Archived By",
                    field: "archived_by",
                    formatter: "html",
                    width: 180,
                    sorter: function(a, b) {
                        return stripHtml(a).localeCompare(stripHtml(b));
                    },
                    responsive: 2
                },
                {
                    title: "Archived Date",
                    field: "archived_date",
                    formatter: "html",
                    width: 180,
                    sorter: function(a, b) {
                        const dateA = new Date(stripHtml(a)).getTime() || 0;
                        const dateB = new Date(stripHtml(b)).getTime() || 0;
                        return dateA - dateB;
                    },
                    responsive: 1
                },
                {
                    title: "Actions",
                    field: "actions",
                    formatter: "html",
                    headerSort: false,
                    hozAlign: "right",
                    width: 140,
                    responsive: 0
                }
            ]
        });

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const val = (this.value || '').toLowerCase().trim();
                if (!val) {
                    trashBinTable.clearFilter();
                } else {
                    trashBinTable.setFilter(function(data) {
                        return stripHtml(data.entity || '').toLowerCase().includes(val) ||
                               stripHtml(data.type || '').toLowerCase().includes(val) ||
                               stripHtml(data.archived_by || '').toLowerCase().includes(val);
                    });
                }
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
