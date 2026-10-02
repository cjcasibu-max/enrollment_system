<?php
/**
 * Administrator Portal — Fee Setup
 * NCST Maritime Academy Design System
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);
require_once '../config/database.php';

$fees = [];
$terms = [];
$feeComponents = [];
$paymentMethods = [];
$error = null;

try {
    $fees = $pdo->query(
        "SELECT f.*, t.school_year, t.semester
         FROM fee_configurations f
         JOIN academic_terms t ON t.id = f.academic_term_id
         ORDER BY t.starts_on DESC, f.program_applying_for, f.year_level, f.fee_name"
    )->fetchAll();
    $terms = $pdo->query('SELECT id, school_year, semester FROM academic_terms ORDER BY starts_on DESC, id DESC')->fetchAll();
    $feeComponents = $pdo->query('SELECT * FROM fee_components ORDER BY sort_order, component_name')->fetchAll();
    $paymentMethods = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order, method_name')->fetchAll();
} catch (PDOException $e) {
    error_log('Fee configuration fetch failed: ' . $e->getMessage());
    $error = 'Fee configuration is temporarily unavailable.';
}

$programs = [
    'BSMarE' => 'Bachelor of Science in Marine Engineering (BSMarE)',
    'BSMT'  => 'Bachelor of Science in Marine Transportation (BSMT)',
];
$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$page_title = 'Fee Setup';
require_once '../includes/header.php';
?>

<style>
#feesTable, #componentsTable, #methodsTable {
    width: 100% !important;
    min-width: 0 !important;
}

.table-maritime td .btn-sm,
.table-hover td .btn-sm,
.table td .btn {
    min-height: 40px;
    min-width: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-cash-stack me-1"></i> Finance & Operations</div>
        <h3 class="m-0 text-navy-alt fw-bold">Fee Setup</h3>
        <p class="text-muted small m-0">Configure itemized fees by academic term, degree program, and year level.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-brand-primary d-inline-flex align-items-center gap-1.5 shadow-sm" style="min-height: 40px; padding: 6px 16px;" data-bs-toggle="modal" data-bs-target="#addFeeModal">
            <i class="bi bi-plus-circle"></i> Add Fee Rule
        </button>
    </div>
</div>

<!-- Flash Notifications -->
<?php if (isset($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Fee Configurations Table Card -->
<div class="card card-premium shadow-sm border-0 rounded-4 overflow-hidden mb-4" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-receipt"></i> Term Fee Schedules & Rates
        </h5>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 220px;">
                <span class="input-group-text bg-white bg-opacity-25 border-0 text-white"><i class="bi bi-search"></i></span>
                <input type="text" id="feesSearchInput" class="form-control form-control-sm border-0 bg-white bg-opacity-10 text-white placeholder-white" placeholder="Search fee rules...">
            </div>
            <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
                <?php echo count($fees); ?> Configured Rule<?php echo count($fees) !== 1 ? 's' : ''; ?>
            </span>
        </div>
    </div>
    <div class="card-body card-body-premium p-0">
        <div>
            <table class="table table-hover table-maritime align-middle mb-0" id="feesTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" tabulator-field="fee_name">Fee Name & Code</th>
                        <th tabulator-field="term_scope">Academic Term & Scope</th>
                        <th tabulator-field="calc_method" style="width: 130px;">Calculation</th>
                        <th tabulator-field="amount" style="width: 130px;">Amount</th>
                        <th tabulator-field="status" style="width: 110px;">Status</th>
                        <th class="pe-4 text-end" tabulator-field="actions" style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($fees)): foreach ($fees as $fee): ?>
                        <tr>
                            <td class="ps-4">
                                <strong class="text-navy"><?php echo htmlspecialchars($fee['fee_name']); ?></strong>
                                <small class="d-block text-muted font-monospace"><?php echo htmlspecialchars($fee['fee_code']); ?></small>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($fee['school_year'] . ' · ' . ucfirst($fee['semester']) . ' Sem'); ?></div>
                                <div class="text-muted small"><?php echo htmlspecialchars($fee['program_applying_for'] ?: 'All Programs'); ?> · <?php echo htmlspecialchars($fee['year_level'] ?: 'All Years'); ?></div>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--surface-tint); color: var(--brand-dark); border: 1px solid var(--brand-primary-soft); font-size: 0.74rem;">
                                    <?php echo $fee['calculation_method'] === 'per_unit' ? 'Per Unit' : 'Fixed Rate'; ?>
                                </span>
                            </td>
                            <td class="fw-bold text-navy">₱<?php echo number_format((float)$fee['amount'], 2); ?></td>
                            <td>
                                <?php if ((int)$fee['is_active'] === 1): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem; padding: 4px 8px; border-radius: 999px;">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.72rem; padding: 4px 8px; border-radius: 999px;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end">
                                <form action="../actions/fee_actions" method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="fee_id" value="<?php echo (int)$fee['id']; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="<?php echo (int)$fee['is_active'] === 1 ? 'Deactivate Fee Rule' : 'Activate Fee Rule'; ?>" aria-label="<?php echo (int)$fee['is_active'] === 1 ? 'Deactivate Fee Rule' : 'Activate Fee Rule'; ?>">
                                        <i class="bi <?php echo (int)$fee['is_active'] === 1 ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted'; ?> fs-6"></i>
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

<!-- Secondary Tables: Fee Components & Payment Methods -->
<div class="row g-4 mb-4">
    <!-- Fee Components -->
    <div class="col-12 col-lg-6">
        <div class="card card-premium shadow-sm border-0 rounded-4 overflow-hidden h-100" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                    <i class="bi bi-puzzle"></i> Fee Components
                </h5>
                <button type="button" class="btn btn-xs btn-light text-navy fw-bold d-inline-flex align-items-center gap-1 shadow-sm" style="min-height: 32px; padding: 4px 12px; font-size: 0.78rem;" data-bs-toggle="modal" data-bs-target="#addFeeComponentModal">
                    <i class="bi bi-plus-lg"></i> Add Component
                </button>
            </div>
            <div class="card-body card-body-premium p-0">
                <div class="mobile-scroll-hint table-scroll-hint d-md-none bg-light text-muted px-3 py-1.5 small border-bottom">
                    <i class="bi bi-arrows-expand text-brand-primary"></i> Scroll horizontally
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-maritime align-middle mb-0" id="componentsTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" tabulator-field="component_name">Component Name</th>
                                <th tabulator-field="component_code" style="width: 25%;">Code</th>
                                <th tabulator-field="requirement" style="width: 20%;">Requirement</th>
                                <th class="pe-4 text-end" tabulator-field="actions" style="width: 15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($feeComponents): foreach ($feeComponents as $component): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-navy"><?php echo htmlspecialchars($component['component_name']); ?></td>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($component['component_code']); ?></span></td>
                                    <td>
                                        <?php if ((int)$component['is_required'] === 1): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">Required</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border" style="font-size: 0.72rem;">Optional</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <form action="../actions/fee_actions" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="toggle_component">
                                            <input type="hidden" name="component_id" value="<?php echo (int)$component['id']; ?>">
                                            <button class="btn btn-sm btn-outline-secondary" title="Toggle Required / Optional" aria-label="Toggle Required or Optional">
                                                <i class="bi <?php echo (int)$component['is_required'] === 1 ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted'; ?> fs-6"></i>
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
    </div>

    <!-- Payment Methods -->
    <div class="col-12 col-lg-6">
        <div class="card card-premium shadow-sm border-0 rounded-4 overflow-hidden h-100" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 0.95rem;">
                    <i class="bi bi-credit-card"></i> Payment Channels
                </h5>
                <button type="button" class="btn btn-xs btn-light text-navy fw-bold d-inline-flex align-items-center gap-1 shadow-sm" style="min-height: 32px; padding: 4px 12px; font-size: 0.78rem;" data-bs-toggle="modal" data-bs-target="#addPaymentMethodModal">
                    <i class="bi bi-plus-lg"></i> Add Method
                </button>
            </div>
            <div class="card-body card-body-premium p-0">
                <div class="mobile-scroll-hint table-scroll-hint d-md-none bg-light text-muted px-3 py-1.5 small border-bottom">
                    <i class="bi bi-arrows-expand text-brand-primary"></i> Scroll horizontally
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-maritime align-middle mb-0" id="methodsTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" tabulator-field="method_name">Method Name</th>
                                <th tabulator-field="method_code" style="width: 25%;">Code</th>
                                <th tabulator-field="status" style="width: 20%;">Status</th>
                                <th class="pe-4 text-end" tabulator-field="actions" style="width: 15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($paymentMethods): foreach ($paymentMethods as $method): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-navy"><?php echo htmlspecialchars($method['method_name']); ?></td>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($method['method_code']); ?></span></td>
                                    <td>
                                        <?php if ((int)$method['is_active'] === 1): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.72rem;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <form action="../actions/fee_actions" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="toggle_payment_method">
                                            <input type="hidden" name="method_id" value="<?php echo (int)$method['id']; ?>">
                                            <button class="btn btn-sm btn-outline-secondary" title="<?php echo (int)$method['is_active'] === 1 ? 'Deactivate Method' : 'Activate Method'; ?>" aria-label="<?php echo (int)$method['is_active'] === 1 ? 'Deactivate Method' : 'Activate Method'; ?>">
                                                <i class="bi <?php echo (int)$method['is_active'] === 1 ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted'; ?> fs-6"></i>
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
    </div>
</div>

<!-- =========================================================================
     MODALS WITH SIGNATURE MARITIME TEAL GRADIENT HEADERS
     ========================================================================= -->

<!-- Modal: Add Fee Rule -->
<div class="modal fade" id="addFeeModal" tabindex="-1" aria-labelledby="addFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <form action="../actions/fee_actions" method="POST">
                <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                    <h5 class="modal-title fw-bold text-white" id="addFeeModalLabel">
                        <i class="bi bi-plus-circle me-2 text-white"></i>Add New Fee Rule
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Academic Term</label>
                            <select class="form-select" name="academic_term_id" required>
                                <option value="">Select academic term</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo (int)$term['id']; ?>">
                                        <?php echo htmlspecialchars($term['school_year'] . ' · ' . ucfirst($term['semester']) . ' Semester'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Fee Code</label>
                            <input class="form-control" name="fee_code" maxlength="40" placeholder="e.g. TUITION_BSMT" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Fee Name</label>
                            <input class="form-control" name="fee_name" maxlength="150" placeholder="e.g. Standard Tuition Fee" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Calculation Method</label>
                            <select class="form-select" name="calculation_method">
                                <option value="fixed">Fixed Rate Amount</option>
                                <option value="per_unit">Per Enrolled Credit Unit</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Degree Program</label>
                            <select class="form-select" name="program_applying_for">
                                <option value="">All Programs (Shared)</option>
                                <?php foreach ($programs as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Year Level</label>
                            <select class="form-select" name="year_level">
                                <option value="">All Year Levels</option>
                                <?php foreach ($yearLevels as $level): ?>
                                    <option value="<?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($level); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Fee Amount (₱)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 fw-bold text-navy">₱</span>
                                <input type="number" class="form-control border-start-0 ps-0" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Discount Type</label>
                            <select class="form-select" name="discount_type">
                                <option value="none">No Discount</option>
                                <option value="fixed">Fixed Amount (₱)</option>
                                <option value="percent">Percentage (%)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Discount Value</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">₱/%</span>
                                <input type="number" class="form-control border-start-0 ps-0" name="discount_value" min="0" step="0.01" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary d-inline-flex align-items-center gap-1.5" style="min-height: 40px;">
                        <i class="bi bi-check-circle"></i> Save Fee Rule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Fee Component -->
<div class="modal fade" id="addFeeComponentModal" tabindex="-1" aria-labelledby="addFeeComponentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <form action="../actions/fee_actions" method="POST">
                <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                    <h5 class="modal-title fw-bold text-white" id="addFeeComponentModalLabel">
                        <i class="bi bi-puzzle me-2 text-white"></i>Add Fee Component
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create_component">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Component Name</label>
                        <input class="form-control" name="component_name" placeholder="e.g. Laboratory Fee" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Component Code</label>
                        <input class="form-control text-uppercase font-monospace" name="component_code" maxlength="50" placeholder="e.g. LAB_FEE" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Optional details regarding this fee breakdown component..."></textarea>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_required" id="is_required_check" value="1" checked>
                        <label class="form-check-label fw-medium" for="is_required_check">Mandatory fee component across assessments</label>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary d-inline-flex align-items-center gap-1.5" style="min-height: 40px;">
                        <i class="bi bi-check-circle"></i> Save Component
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Payment Method -->
<div class="modal fade" id="addPaymentMethodModal" tabindex="-1" aria-labelledby="addPaymentMethodModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0">
            <form action="../actions/fee_actions" method="POST">
                <div class="modal-header text-white rounded-top-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                    <h5 class="modal-title fw-bold text-white" id="addPaymentMethodModalLabel">
                        <i class="bi bi-credit-card me-2 text-white"></i>Add Payment Channel
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                    <input type="hidden" name="action" value="create_payment_method">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Payment Method Name</label>
                        <input class="form-control" name="method_name" placeholder="e.g. GCash / Maya" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Method Code</label>
                        <input class="form-control text-lowercase font-monospace" name="method_code" maxlength="20" placeholder="e.g. gcash" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Instructions / Description</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Payment instructions or account account number..."></textarea>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="requires_reference" id="req_ref_check" value="1">
                        <label class="form-check-label fw-medium" for="req_ref_check">Requires reference number / transaction receipt</label>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary d-inline-flex align-items-center gap-1.5" style="min-height: 40px;">
                        <i class="bi bi-check-circle"></i> Save Payment Method
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function stripHtml(html) {
        if (!html) return '';
        var tmp = document.createElement('DIV');
        tmp.innerHTML = html;
        return (tmp.textContent || tmp.innerText || '').trim();
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Tabulator === 'undefined') return;

        // Fees Table
        var feesTable = new Tabulator("#feesTable", {
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
            paginationSize: 10,
            paginationSizeSelector: [10, 25, 50, 100],
            placeholder: "<div class='text-center text-muted py-4'><i class='bi bi-inbox fs-2 d-block mb-2'></i>No fee schedules configured.</div>",
            columns: [
                { title: "Fee Name & Code", field: "fee_name", minWidth: 200, formatter: "html" },
                { title: "Academic Term & Scope", field: "term_scope", minWidth: 180, formatter: "html" },
                { title: "Calculation", field: "calc_method", width: 130, formatter: "html" },
                { 
                    title: "Amount", 
                    field: "amount", 
                    width: 130, 
                    formatter: "html",
                    sorter: function(a, b) {
                        var na = parseFloat(a.replace(/[^0-9.-]+/g, "")) || 0;
                        var nb = parseFloat(b.replace(/[^0-9.-]+/g, "")) || 0;
                        return na - nb;
                    }
                },
                { title: "Status", field: "status", width: 110, formatter: "html" },
                { title: "Actions", field: "actions", width: 90, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });

        var feesSearch = document.getElementById('feesSearchInput');
        if (feesSearch) {
            feesSearch.addEventListener('input', function() {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    feesTable.clearFilter();
                } else {
                    feesTable.setFilter(function(data) {
                        return stripHtml(data.fee_name).toLowerCase().includes(term) ||
                               stripHtml(data.term_scope).toLowerCase().includes(term) ||
                               stripHtml(data.calc_method).toLowerCase().includes(term) ||
                               stripHtml(data.amount).toLowerCase().includes(term);
                    });
                }
            });
        }

        // Components Table
        var componentsTable = new Tabulator("#componentsTable", {
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
            paginationSize: 10,
            paginationSizeSelector: [10, 25, 50],
            placeholder: "<div class='text-center text-muted py-4'><i class='bi bi-inbox fs-2 d-block mb-2'></i>No fee components configured.</div>",
            columns: [
                { title: "Component Name", field: "component_name", minWidth: 160, formatter: "html" },
                { title: "Code", field: "component_code", width: 130, formatter: "html" },
                { title: "Requirement", field: "requirement", width: 120, formatter: "html" },
                { title: "Actions", field: "actions", width: 90, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });

        // Payment Methods Table
        var methodsTable = new Tabulator("#methodsTable", {
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
            paginationSize: 10,
            paginationSizeSelector: [10, 25, 50],
            placeholder: "<div class='text-center text-muted py-4'><i class='bi bi-inbox fs-2 d-block mb-2'></i>No payment methods configured.</div>",
            columns: [
                { title: "Method Name", field: "method_name", minWidth: 160, formatter: "html" },
                { title: "Code", field: "method_code", width: 130, formatter: "html" },
                { title: "Status", field: "status", width: 120, formatter: "html" },
                { title: "Actions", field: "actions", width: 90, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
