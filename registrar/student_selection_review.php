<?php
require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);
require_once '../config/database.php';
require_once '../includes/academic_terms.php';
require_once '../includes/assessments.php';
global $pdo;

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($studentId <= 0) {
    $_SESSION['flash_error'] = 'A valid student record is required.';
    header('Location: students');
    exit;
}

$student = null;
$latestChoice = null;
$selectedSubjects = [];
$availableSubjects = [];
$estimatedFees = [];
$autoCalculatedTotal = 0.00;
$existingAssessment = null;

try {
    $studentStmt = $pdo->prepare("SELECT s.*, u.username, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = :id LIMIT 1");
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();

    if (!$student) {
        throw new RuntimeException('Student was not found.');
    }

    $choiceStmt = $pdo->prepare("
        SELECT e.id AS enrollment_id, e.section_id, e.school_year, e.semester, e.status,
               sec.section_name, sec.program, sec.year_level, sec.schedule, sec.room,
               c.course_code, c.course_name, c.units AS course_units
        FROM enrollments e
        JOIN sections sec ON sec.id = e.section_id
        LEFT JOIN courses c ON c.id = sec.course_id
        WHERE e.student_id = :student_id
          AND e.status IN ('pending', 'approved', 'enrolled')
        ORDER BY e.id DESC
        LIMIT 1
    ");
    $choiceStmt->execute(['student_id' => $studentId]);
    $latestChoice = $choiceStmt->fetch();

    if (!$latestChoice) {
        $resChoiceStmt = $pdo->prepare("
            SELECT NULL AS enrollment_id, sr.section_id, at.school_year, at.semester, 'reserved' AS status,
                   sec.section_name, sec.program, sec.year_level, sec.schedule, sec.room,
                   c.course_code, c.course_name, c.units AS course_units
            FROM section_reservations sr
            JOIN sections sec ON sec.id = sr.section_id
            LEFT JOIN academic_terms at ON at.id = sec.academic_term_id
            LEFT JOIN courses c ON c.id = sec.course_id
            WHERE sr.student_id = :student_id
              AND sr.status = 'active'
              AND sr.expires_at > NOW()
            ORDER BY sr.id DESC
            LIMIT 1
        ");
        $resChoiceStmt->execute(['student_id' => $studentId]);
        $latestChoice = $resChoiceStmt->fetch();
    }

    if ($latestChoice) {
        $selectedStmt = $pdo->prepare("
            SELECT ss.subject_id, ss.subject_code, ss.subject_name, ss.units, ss.is_finalized, ss.finalized_at
            FROM student_selected_subjects ss
            WHERE ss.student_id = :student_id AND ss.section_id = :section_id
            ORDER BY ss.subject_code ASC, ss.subject_name ASC
        ");
        $selectedStmt->execute(['student_id' => $studentId, 'section_id' => (int)$latestChoice['section_id']]);
        $selectedSubjects = $selectedStmt->fetchAll();

        if (empty($selectedSubjects)) {
            $subjectQuery = $pdo->prepare("
                SELECT ss.subject_id, sub.subject_code, sub.subject_name, sub.units
                FROM section_subjects ss
                JOIN subjects sub ON sub.id = ss.subject_id
                WHERE ss.section_id = :section_id
                ORDER BY sub.subject_code ASC
            ");
            $subjectQuery->execute(['section_id' => (int)$latestChoice['section_id']]);
            $selectedSubjects = $subjectQuery->fetchAll();
            foreach ($selectedSubjects as &$subjectRow) {
                $subjectRow['subject_id'] = (int)$subjectRow['subject_id'];
                $subjectRow['is_finalized'] = 0;
            }
            unset($subjectRow);
        }

        $availableStmt = $pdo->prepare("
            SELECT ss.subject_id, sub.subject_code, sub.subject_name, sub.units
            FROM section_subjects ss
            JOIN subjects sub ON sub.id = ss.subject_id
            WHERE ss.section_id = :section_id
            ORDER BY sub.subject_code ASC
        ");
        $availableStmt->execute(['section_id' => (int)$latestChoice['section_id']]);
        $availableSubjects = $availableStmt->fetchAll();

        // Calculate total selected units
        $totalSelectedUnits = 0.0;
        foreach ($selectedSubjects as $s) {
            $totalSelectedUnits += (float)($s['units'] ?? 0);
        }

        // Assessment lookup / calculation
        $termId = (int)($student['academic_term_id'] ?? 0);
        if ($termId <= 0) {
            $activeTerm = getActiveAcademicTerm($pdo);
            $termId = $activeTerm ? (int)$activeTerm['id'] : 0;
        }

        $perUnitRate = 1500.00; // default fallback

        if ($termId > 0) {
            $programCode = trim((string)($student['program_code'] ?? ''));
            $programApp = trim((string)($student['program_applying_for'] ?? ''));
            $feeStmt = $pdo->prepare(
                "SELECT id, fee_name, calculation_method, amount, discount_type, discount_value
                 FROM fee_configurations
                 WHERE academic_term_id = :academic_term_id
                   AND is_active = 1
                   AND (year_level IS NULL OR year_level = :year_level)
                   AND (
                       program_code = :program_code
                       OR program_applying_for = :program_app
                       OR (program_code IS NULL AND program_applying_for IS NULL)
                   )
                 ORDER BY id ASC"
            );
            $feeStmt->execute([
                'academic_term_id' => $termId,
                'year_level' => $student['year_level'] ?? null,
                'program_code' => $programCode ?: null,
                'program_app' => $programApp ?: null,
            ]);
            $configuredFees = $feeStmt->fetchAll();

            // Find per-unit tuition rate from fee configurations
            foreach ($configuredFees as $cf) {
                if (($cf['calculation_method'] ?? '') === 'per_unit') {
                    $perUnitRate = (float)$cf['amount'];
                    break;
                }
            }

            // Build estimated fee rows based on total selected units
            $estimatedFees = [];
            $autoCalculatedTotal = 0.00;
            foreach ($configuredFees as $f) {
                $isPerUnit = ($f['calculation_method'] === 'per_unit');
                $qty = $isPerUnit ? max(0, $totalSelectedUnits) : 1.00;
                $base = round($qty * (float)$f['amount'], 2);
                $disc = calculateFeeDiscount($base, $f['discount_type'] ?? 'none', (float)($f['discount_value'] ?? 0));
                $net = round($base - $disc, 2);
                $estimatedFees[] = [
                    'description' => $f['fee_name'],
                    'calculation_method' => $f['calculation_method'],
                    'quantity' => $qty,
                    'unit_amount' => $f['amount'],
                    'amount' => $net,
                ];
                $autoCalculatedTotal += $net;
            }

            // Check if existing assessment already has finalized custom amount
            $assStmt = $pdo->prepare("SELECT id FROM assessments WHERE student_id = :sid AND academic_term_id = :term_id AND status != 'cancelled' LIMIT 1");
            $assStmt->execute(['sid' => $studentId, 'term_id' => $termId]);
            $assessmentId = (int)($assStmt->fetchColumn() ?: 0);
            if ($assessmentId > 0) {
                $existingAssessment = getAssessmentTotals($pdo, $assessmentId);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Student selection review failed: ' . $e->getMessage());
    $student = null;
    $latestChoice = null;
    $selectedSubjects = [];
    $availableSubjects = [];
}

if (!$student || !$latestChoice) {
    $_SESSION['flash_error'] = 'No section selection is available to review for this student.';
    header('Location: students');
    exit;
}

$selectedIds = array_map('intval', array_column($selectedSubjects, 'subject_id'));
$studentName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
$page_title = 'Review Selected Subjects';
require_once '../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <div class="page-eyebrow"><i class="bi bi-journal-check me-1"></i> Registrar Review</div>
            <h3 class="m-0 fw-bold text-navy-alt">Selected Section & Subject Review</h3>
        </div>
        <a href="students" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Student Registry
        </a>
    </div>

    <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden;">
        <div class="card-header text-white" style="background:linear-gradient(135deg,var(--brand-primary) 0%,var(--brand-dark) 100%); border:none; padding:16px 20px;">
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <div class="fw-bold fs-5"><?php echo htmlspecialchars($studentName); ?></div>
                    <div class="small opacity-75">@<?php echo htmlspecialchars($student['username']); ?> · <?php echo htmlspecialchars($student['email']); ?></div>
                </div>
                <span class="badge bg-white text-dark px-3 py-2 fw-bold"><?php echo htmlspecialchars($latestChoice['section_name']); ?></span>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Program</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($latestChoice['program'] ?: $student['program_code'] ?: '—'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Year Level</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($latestChoice['year_level'] ?: $student['year_level'] ?: '—'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Course</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($latestChoice['course_code'] ?: '—'); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Schedule</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($latestChoice['schedule'] ?: '—'); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Room</div>
                    <div class="fw-semibold"><?php echo htmlspecialchars($latestChoice['room'] ?: '—'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="../actions/walk_in_actions">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
        <input type="hidden" name="action" value="finalize_walk_in">
        <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
        <input type="hidden" name="section_id" value="<?php echo (int)$latestChoice['section_id']; ?>">

        <!-- =========================================================================
             SUBJECT LIST WITH PRICE & REAL-TIME RECALCULATION
             ========================================================================= -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden;">
            <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center flex-wrap gap-3" style="padding:16px 20px;">
                <div>
                    <div class="fw-bold text-navy-alt">Subject List & Course Pricing</div>
                    <div class="small text-muted">Review the subjects chosen for this section. Uncheck any subject to exclude it—pricing and tuition assessment update automatically in real time.</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="selected-subjects-badge" class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fw-semibold">
                        <?php echo count($selectedIds); ?> of <?php echo count($availableSubjects); ?> selected
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="subjects-review-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" style="width:8%;">Keep</th>
                                <th style="width:16%;">Code</th>
                                <th>Subject Title</th>
                                <th style="width:12%;" class="text-center">Units</th>
                                <th style="width:18%;" class="pe-4 text-end">Subject Fee</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($availableSubjects)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        No subjects are assigned to this section.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($availableSubjects as $subject): ?>
                                    <?php
                                        $subjectId = (int)$subject['subject_id'];
                                        $isChecked = in_array($subjectId, $selectedIds, true);
                                        $units = (float)($subject['units'] ?? 0);
                                        $subjectPrice = round($units * $perUnitRate, 2);
                                    ?>
                                    <tr class="subject-row <?php echo !$isChecked ? 'table-light text-muted' : ''; ?>" id="subject-row-<?php echo $subjectId; ?>" style="<?php echo !$isChecked ? 'opacity:0.55;' : ''; ?>">
                                        <td class="ps-4">
                                            <input class="form-check-input subject-checkbox" type="checkbox" name="subject_ids[]" value="<?php echo $subjectId; ?>"
                                                   data-units="<?php echo $units; ?>"
                                                   data-price="<?php echo $subjectPrice; ?>"
                                                   <?php echo $isChecked ? 'checked' : ''; ?>>
                                        </td>
                                        <td class="fw-semibold text-navy"><?php echo htmlspecialchars($subject['subject_code'] ?? '—'); ?></td>
                                        <td>
                                            <span class="subject-name-text"><?php echo htmlspecialchars($subject['subject_name'] ?? '—'); ?></span>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle subject-status-badge ms-2 <?php echo $isChecked ? 'd-none' : ''; ?>">
                                                Excluded
                                            </span>
                                        </td>
                                        <td class="text-center fw-medium"><?php echo number_format($units, 1); ?></td>
                                        <td class="pe-4 text-end fw-semibold text-dark subject-price-cell">
                                            ₱<?php echo number_format($subjectPrice, 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light border-top">
                            <tr>
                                <td colspan="3" class="ps-4 fw-bold text-dark">
                                    <i class="bi bi-calculator me-1 text-primary"></i> Total Selected Subjects / Units:
                                </td>
                                <td class="text-center fw-bold text-navy">
                                    <span id="total-selected-units"><?php echo number_format($totalSelectedUnits, 1); ?></span> units
                                </td>
                                <td class="pe-4 text-end fw-bold text-navy fs-6">
                                    <span id="total-subject-price">₱<?php echo number_format($totalSelectedUnits * $perUnitRate, 2); ?></span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             TUITION ASSESSMENT & FINALIZATION CONFIRMATION CARD
             ========================================================================= -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden;">
            <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding:16px 20px;">
                <div>
                    <div class="fw-bold text-navy-alt"><i class="bi bi-cash-coin me-1.5 text-primary"></i> Tuition Assessment & Finalization</div>
                    <div class="small text-muted">Auto-calculated fee basis updates dynamically based on active subject selection.</div>
                </div>
                <?php if (!empty($existingAssessment['is_finalized'])): ?>
                    <span class="badge bg-success-subtle text-success border border-success px-3 py-1.5 fw-bold">
                        <i class="bi bi-patch-check-fill me-1"></i> Already Finalized
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-3 py-1.5 fw-bold">
                        <i class="bi bi-clock me-1"></i> Pending Finalization
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="assessment-fees-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Fee Component</th>
                                <th style="width: 18%;" class="text-center">Calculation Method</th>
                                <th style="width: 15%;" class="text-end">Unit Rate</th>
                                <th style="width: 18%;" class="pe-4 text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($estimatedFees)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        No active fee configuration found for this student's program and year level.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($estimatedFees as $fee): ?>
                                    <?php
                                        $isPerUnit = ($fee['calculation_method'] === 'per_unit');
                                        $unitRate = (float)($fee['unit_amount'] ?? $fee['amount']);
                                        $initialAmount = (float)$fee['amount'];
                                    ?>
                                    <tr class="fee-component-row"
                                        data-calc-method="<?php echo htmlspecialchars($fee['calculation_method']); ?>"
                                        data-unit-rate="<?php echo $unitRate; ?>"
                                        data-fixed-amount="<?php echo $isPerUnit ? 0 : $initialAmount; ?>">
                                        <td class="ps-4 fw-medium text-dark"><?php echo htmlspecialchars($fee['description']); ?></td>
                                        <td class="text-center text-muted small fee-calc-text">
                                            <?php echo $isPerUnit ? htmlspecialchars((string)$fee['quantity']) . ' units' : 'Fixed Fee'; ?>
                                        </td>
                                        <td class="text-end text-muted">₱<?php echo number_format($unitRate, 2); ?></td>
                                        <td class="pe-4 text-end fw-semibold fee-amount-cell">₱<?php echo number_format($initialAmount, 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-light border-top">
                                    <td colspan="3" class="ps-4 fw-bold text-dark">Auto-Calculated Assessment Basis Total:</td>
                                    <td class="pe-4 text-end fw-bold text-navy fs-6">
                                        <span id="auto-calculated-total-display">₱<?php echo number_format($autoCalculatedTotal, 2); ?></span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-4 bg-light-subtle border-top">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold text-dark d-flex align-items-center justify-content-between">
                                <span>Finalized Tuition Amount (₱) <span class="text-danger">*</span></span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle small fw-normal py-1">
                                    <i class="bi bi-lightning-charge-fill me-1"></i>Real-time Synced
                                </span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white fw-bold text-navy">₱</span>
                                <?php 
                                    $currentFinalAmount = ($existingAssessment && $existingAssessment['finalized_amount'] !== null) 
                                        ? $existingAssessment['finalized_amount'] 
                                        : $autoCalculatedTotal;
                                ?>
                                <input type="number" step="0.01" min="0" name="finalized_amount" id="finalized_amount_input"
                                       class="form-control fw-bold text-dark" style="font-size:1.05rem;"
                                       value="<?php echo htmlspecialchars(number_format((float)$currentFinalAmount, 2, '.', '')); ?>" required>
                                <button type="button" class="btn btn-outline-secondary" id="btn-use-calculated" title="Reset input to live auto-calculated amount">
                                    Use Calculated
                                </button>
                            </div>
                            <div class="form-text small text-muted">
                                This official amount is charged to the enrollee and viewable on their portal and Cashier ledger.
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold text-dark">Tuition / Assessment Notes</label>
                            <input type="text" name="finalization_notes" class="form-control"
                                   placeholder="e.g. Standard 1st Semester tuition confirmed; partial units applied"
                                   value="<?php echo htmlspecialchars($existingAssessment['finalization_notes'] ?? ''); ?>">
                            <div class="form-text small text-muted">
                                Notes or justification for any manual adjustments or discounts.
                            </div>
                        </div>
                    </div>

                    <div id="zero-subject-alert" class="alert alert-danger d-none mt-3 mb-0" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>At least one subject must be selected.</strong> You cannot finalize an enrollment with zero subjects.
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden;">
            <div class="card-header bg-light border-0" style="padding:16px 20px;">
                <div class="fw-bold text-navy-alt">Registrar Walk-in Notes</div>
            </div>
            <div class="card-body p-4">
                <textarea name="walk_in_notes" rows="3" class="form-control" placeholder="Optional internal walk-in or document verification notes."><?php echo htmlspecialchars($student['walk_in_notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="students" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" id="btn-finalize-submit" class="btn btn-brand-primary px-4 py-2 fw-semibold shadow-sm">
                <i class="bi bi-check-circle me-1"></i> Save Finalized Subjects & Confirm Tuition
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkboxes = document.querySelectorAll('.subject-checkbox');
    const unitsDisplay = document.getElementById('total-selected-units');
    const subjectPriceDisplay = document.getElementById('total-subject-price');
    const badgeDisplay = document.getElementById('selected-subjects-badge');
    const autoTotalDisplay = document.getElementById('auto-calculated-total-display');
    const finalAmountInput = document.getElementById('finalized_amount_input');
    const btnUseCalc = document.getElementById('btn-use-calculated');
    const zeroAlert = document.getElementById('zero-subject-alert');
    const submitBtn = document.getElementById('btn-finalize-submit');
    const feeRows = document.querySelectorAll('.fee-component-row');

    const perUnitRate = <?php echo (float)$perUnitRate; ?>;
    let latestCalculatedTotal = <?php echo (float)$autoCalculatedTotal; ?>;

    function formatCurrency(amount) {
        return '₱' + Number(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function recalculateAll() {
        let totalSelectedUnits = 0;
        let selectedCount = 0;
        const totalAvailable = checkboxes.length;

        checkboxes.forEach(function (cb) {
            const row = cb.closest('tr');
            const statusBadge = row.querySelector('.subject-status-badge');
            const units = parseFloat(cb.dataset.units) || 0;

            if (cb.checked) {
                totalSelectedUnits += units;
                selectedCount++;
                row.classList.remove('table-light', 'text-muted');
                row.style.opacity = '1';
                if (statusBadge) statusBadge.classList.add('d-none');
            } else {
                row.classList.add('table-light', 'text-muted');
                row.style.opacity = '0.55';
                if (statusBadge) statusBadge.classList.remove('d-none');
            }
        });

        // 1. Update Subject List Table Footer & Badge
        if (unitsDisplay) {
            unitsDisplay.textContent = totalSelectedUnits.toFixed(1);
        }
        const totalSubjectPrice = totalSelectedUnits * perUnitRate;
        if (subjectPriceDisplay) {
            subjectPriceDisplay.textContent = formatCurrency(totalSubjectPrice);
        }
        if (badgeDisplay) {
            badgeDisplay.textContent = selectedCount + ' of ' + totalAvailable + ' selected';
        }

        // 2. Update Assessment Fees Table Rows
        let grandAssessmentTotal = 0;
        feeRows.forEach(function (row) {
            const method = row.dataset.calcMethod;
            const unitRate = parseFloat(row.dataset.unitRate) || 0;
            const fixedAmount = parseFloat(row.dataset.fixedAmount) || 0;
            const calcText = row.querySelector('.fee-calc-text');
            const amountCell = row.querySelector('.fee-amount-cell');

            let rowAmount = 0;
            if (method === 'per_unit') {
                rowAmount = Math.round(totalSelectedUnits * unitRate * 100) / 100;
                if (calcText) {
                    calcText.textContent = totalSelectedUnits.toFixed(1) + ' units';
                }
            } else {
                rowAmount = fixedAmount;
            }

            if (amountCell) {
                amountCell.textContent = formatCurrency(rowAmount);
            }
            grandAssessmentTotal += rowAmount;
        });

        grandAssessmentTotal = Math.round(grandAssessmentTotal * 100) / 100;
        latestCalculatedTotal = grandAssessmentTotal;

        // 3. Update Auto-Calculated Assessment Total Display
        if (autoTotalDisplay) {
            autoTotalDisplay.textContent = formatCurrency(latestCalculatedTotal);
        }

        // 4. Update Finalized Tuition Input Field
        if (finalAmountInput) {
            finalAmountInput.value = latestCalculatedTotal.toFixed(2);
        }

        // 5. Zero Subjects Validation
        if (selectedCount === 0) {
            if (zeroAlert) zeroAlert.classList.remove('d-none');
            if (submitBtn) submitBtn.disabled = true;
        } else {
            if (zeroAlert) zeroAlert.classList.add('d-none');
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    // Attach real-time change event listeners to each subject checkbox
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', recalculateAll);
    });

    // "Use Calculated" button resets finalized input to current calculated total
    if (btnUseCalc && finalAmountInput) {
        btnUseCalc.addEventListener('click', function () {
            finalAmountInput.value = latestCalculatedTotal.toFixed(2);
        });
    }

    // Initial calculation on page load
    recalculateAll();
});
</script>

<?php require_once '../includes/footer.php'; ?>

