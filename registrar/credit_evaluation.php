<?php
/**
 * Transferee Credit Evaluation Portal
 * Allows Registrars to review transferee documents (TOR, honorable dismissal)
 * and evaluate previous subject credits with equivalent subject mapping.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';
global $pdo;

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$student = null;
$evaluations = [];
$studentDocs = [];
$availableSubjects = [];

// Fetch list of all transferee students for selector / list view
try {
    $transfereesStmt = $pdo->query("
        SELECT s.id, s.first_name, s.middle_name, s.last_name, s.application_no, s.student_no,
               s.program_code, s.program_applying_for, s.year_level, s.application_status, s.enrollment_status,
               u.email,
               (SELECT COUNT(*) FROM transferee_evaluations te WHERE te.student_id = s.id) AS total_evals,
               (SELECT COALESCE(SUM(te.previous_units), 0) FROM transferee_evaluations te WHERE te.student_id = s.id AND te.status = 'credited') AS credited_units,
               (SELECT COUNT(*) FROM transferee_evaluations te WHERE te.student_id = s.id AND te.status = 'pending') AS pending_evals
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.applicant_type = 'Transferee'
        ORDER BY s.last_name ASC, s.first_name ASC
    ");
    $transfereeList = $transfereesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('Error fetching transferees: ' . $e->getMessage());
    $transfereeList = [];
}

// If a student is selected, load their details
if ($studentId > 0) {
    try {
        $stuStmt = $pdo->prepare("
            SELECT s.*, u.username, u.email
            FROM students s
            JOIN users u ON u.id = s.user_id
            WHERE s.id = :id AND s.applicant_type = 'Transferee'
            LIMIT 1
        ");
        $stuStmt->execute(['id' => $studentId]);
        $student = $stuStmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            // Fetch uploaded documents
            $docStmt = $pdo->prepare("
                SELECT id, document_type, original_filename, file_path, status, uploaded_at
                FROM documents
                WHERE student_id = :sid
                ORDER BY uploaded_at DESC
            ");
            $docStmt->execute(['sid' => $studentId]);
            $studentDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch evaluations
            $evalStmt = $pdo->prepare("
                SELECT te.*, sub.subject_code AS equiv_code, sub.subject_name AS equiv_name, sub.units AS equiv_units,
                       u.first_name AS eval_first, u.last_name AS eval_last
                FROM transferee_evaluations te
                LEFT JOIN subjects sub ON sub.id = te.equivalent_subject_id
                LEFT JOIN users u ON u.id = te.evaluated_by
                WHERE te.student_id = :sid
                ORDER BY te.id ASC
            ");
            $evalStmt->execute(['sid' => $studentId]);
            $evaluations = $evalStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch subjects for equivalent mapping
            $prog = $student['program_code'] ?: 'BSMT';
            $subStmt = $pdo->prepare("
                SELECT id, subject_code, subject_name, units
                FROM subjects
                ORDER BY subject_code ASC
            ");
            $subStmt->execute();
            $availableSubjects = $subStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        error_log('Error loading transferee profile: ' . $e->getMessage());
        $student = null;
    }
}

$page_title = 'Transferee Subject Credit Evaluation';
require_once '../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header Breadcrumb & Actions -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <div class="page-eyebrow"><i class="bi bi-patch-check-fill me-1"></i> Academic Records &amp; Admissions</div>
            <h3 class="m-0 fw-bold text-navy-alt">Transferee Subject Credit Evaluation</h3>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($student): ?>
                <a href="credit_evaluation" class="btn btn-outline-secondary">
                    <i class="bi bi-people me-1"></i> All Transferees
                </a>
            <?php endif; ?>
            <a href="students" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Student Registry
            </a>
        </div>
    </div>

    <!-- Quick Transferee Selector Bar -->
    <div class="card shadow-sm border-0 mb-4" style="border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0 !important;">
        <div class="card-body p-3">
            <form method="GET" action="credit_evaluation" class="row g-2 align-items-center">
                <div class="col-auto">
                    <label class="fw-bold text-dark small"><i class="bi bi-person-bounding-box me-1 text-primary"></i> Select Transferee Student:</label>
                </div>
                <div class="col-12 col-md-5">
                    <select name="student_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">— Choose a Transferee Applicant / Student —</option>
                        <?php foreach ($transfereeList as $t): ?>
                            <?php 
                                $tName = trim($t['last_name'] . ', ' . $t['first_name'] . ' ' . ($t['middle_name'] ? $t['middle_name'] . '.' : ''));
                                $tCode = $t['student_no'] ?: ($t['application_no'] ?: 'ID #' . $t['id']);
                                $tProg = $t['program_code'] ?: ($t['program_applying_for'] ?: 'BSMT');
                                $isSel = ($studentId === (int)$t['id']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo $isSel; ?>>
                                <?php echo htmlspecialchars($tName . ' (' . $tCode . ' - ' . $tProg . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-brand-primary">
                        <i class="bi bi-search me-1"></i> Load Records
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$student): ?>
        <!-- =========================================================================
             VIEW 1: ALL TRANSFEREE STUDENTS LIST
             ========================================================================= -->
        <div class="card shadow-sm border-0" style="border-radius:16px; overflow:hidden; border:1px solid #e0eded !important;">
            <div class="card-header text-white" style="background:linear-gradient(135deg,var(--brand-primary) 0%,var(--brand-dark) 100%); padding:16px 22px; border:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="fw-bold fs-5"><i class="bi bi-arrow-left-right me-2"></i> Transferee Students Pending Evaluation</div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="width: 220px;">
                            <span class="input-group-text bg-white bg-opacity-25 border-0 text-white"><i class="bi bi-search"></i></span>
                            <input type="text" id="transfereeSearchInput" class="form-control form-control-sm border-0 bg-white bg-opacity-10 text-white placeholder-white" placeholder="Search transferees...">
                        </div>
                        <span class="badge bg-white text-dark fw-bold px-3 py-1.5"><?php echo count($transfereeList); ?> Transferees</span>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="transfereesTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" tabulator-field="student">Student / Applicant</th>
                                <th tabulator-field="program">Program</th>
                                <th tabulator-field="year">Year Level</th>
                                <th class="text-center" tabulator-field="total_evals">Evaluated Subjects</th>
                                <th class="text-center" tabulator-field="credited_units">Credited Units</th>
                                <th class="text-center" tabulator-field="pending_evals">Pending Decisions</th>
                                <th class="pe-4 text-end" tabulator-field="action">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transfereeList)): ?>
                                <?php foreach ($transfereeList as $t): ?>
                                    <?php 
                                        $tName = trim($t['last_name'] . ', ' . $t['first_name'] . ' ' . ($t['middle_name'] ? $t['middle_name'] . '.' : ''));
                                        $tNo = $t['student_no'] ?: ($t['application_no'] ?: '—');
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-navy"><?php echo htmlspecialchars($tName); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($tNo); ?> &bull; <?php echo htmlspecialchars($t['email']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1 fw-bold">
                                                <?php echo htmlspecialchars($t['program_code'] ?: ($t['program_applying_for'] ?: 'BSMT')); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($t['year_level'] ?: '1st Year'); ?></td>
                                        <td class="text-center fw-semibold"><?php echo (int)$t['total_evals']; ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold">
                                                <?php echo (float)$t['credited_units']; ?> units
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$t['pending_evals'] > 0): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1">
                                                    <?php echo (int)$t['pending_evals']; ?> pending
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border px-2 py-1">0 pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <a href="credit_evaluation?student_id=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-brand-primary">
                                                <i class="bi bi-patch-check me-1"></i> Evaluate Subjects
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- =========================================================================
             VIEW 2: SPECIFIC TRANSFEREE CREDIT EVALUATION WORKSPACE
             ========================================================================= -->
        <?php
            $studentFullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
            $totalCreditedUnits = 0.0;
            $totalSubjects = count($evaluations);
            $pendingCount = 0;
            $creditedCount = 0;
            $rejectedCount = 0;
            foreach ($evaluations as $e) {
                if ($e['status'] === 'credited') {
                    $totalCreditedUnits += (float)$e['previous_units'];
                    $creditedCount++;
                } elseif ($e['status'] === 'rejected') {
                    $rejectedCount++;
                } else {
                    $pendingCount++;
                }
            }
        ?>

        <!-- Student Profile & Credit Metrics Banner -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden; border:1px solid #e0eded !important;">
            <div class="card-header text-white" style="background:linear-gradient(135deg,var(--brand-primary) 0%,var(--brand-dark) 100%); border:none; padding:18px 24px;">
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning text-dark fw-bold px-2.5 py-1">Transferee</span>
                            <h4 class="m-0 fw-bold"><?php echo htmlspecialchars($studentFullName); ?></h4>
                        </div>
                        <div class="small opacity-75 mt-1">
                            Student / App No: <strong><?php echo htmlspecialchars($student['student_no'] ?: ($student['application_no'] ?: '—')); ?></strong> &bull;
                            Email: <?php echo htmlspecialchars($student['email']); ?> &bull;
                            Contact: <?php echo htmlspecialchars($student['contact_number'] ?: '—'); ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-white text-navy px-3 py-2 fw-bold fs-6">
                            <?php echo htmlspecialchars($student['program_code'] ?: ($student['program_applying_for'] ?: 'BSMT')); ?> — <?php echo htmlspecialchars($student['year_level'] ?: '1st Year'); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="card-body p-4 bg-white">
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 bg-light border border-light-subtle">
                            <div class="small text-muted text-uppercase fw-bold mb-1">Previous Subjects</div>
                            <div class="fs-4 fw-bold text-dark"><?php echo $totalSubjects; ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-success-subtle" style="background:#f0fdf4;">
                            <div class="small text-success text-uppercase fw-bold mb-1">Credited Units</div>
                            <div class="fs-4 fw-bold text-success"><?php echo $totalCreditedUnits; ?> Units</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-warning-subtle" style="background:#fffbeb;">
                            <div class="small text-warning-emphasis text-uppercase fw-bold mb-1">Pending Evaluation</div>
                            <div class="fs-4 fw-bold text-warning-emphasis"><?php echo $pendingCount; ?> Subjects</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 border border-danger-subtle" style="background:#fef2f2;">
                            <div class="small text-danger text-uppercase fw-bold mb-1">Rejected / Non-Credited</div>
                            <div class="fs-4 fw-bold text-danger"><?php echo $rejectedCount; ?> Subjects</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- LEFT: Uploaded Documents for Review (TOR, Honorable Dismissal) -->
            <div class="col-12 col-xl-5">
                <div class="card shadow-sm border-0 h-100" style="border-radius:16px; overflow:hidden; border:1px solid #e0eded !important;">
                    <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center" style="padding:16px 20px;">
                        <div>
                            <div class="fw-bold text-navy-alt"><i class="bi bi-file-earmark-medical-fill me-1.5 text-primary"></i> Transferee Documents</div>
                            <div class="small text-muted">Review Transcript of Records &amp; clearance documents</div>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">
                            <?php echo count($studentDocs); ?> Uploaded
                        </span>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($studentDocs)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-file-earmark-x fs-1 d-block mb-2 text-muted-light"></i>
                                No uploaded credentials found for this transferee applicant.
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2.5">
                                <?php foreach ($studentDocs as $doc): ?>
                                    <?php 
                                        $docType = $doc['document_type'];
                                        $isTor = stripos($docType, 'Transcript') !== false || stripos($docType, 'tor') !== false || stripos($docType, 'Honorable') !== false;
                                        $docId = (int)$doc['id'];
                                        $streamUrl = "../actions/view_document?id=" . $docId;
                                    ?>
                                    <div class="p-3 rounded-3 border <?php echo $isTor ? 'border-primary bg-primary-subtle' : 'border-light-subtle bg-light'; ?> d-flex justify-content-between align-items-center gap-2">
                                        <div class="overflow-hidden">
                                            <div class="fw-bold text-dark text-truncate" style="font-size:0.9rem;">
                                                <i class="bi <?php echo $isTor ? 'bi-file-earmark-text-fill text-primary' : 'bi-file-earmark-check'; ?> me-1"></i>
                                                <?php echo htmlspecialchars($docType); ?>
                                            </div>
                                             <div class="small text-muted text-truncate" style="font-size:0.75rem;">
                                                 <?php echo htmlspecialchars($doc['original_filename']); ?>
                                             </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                                            <button type="button" class="btn btn-sm btn-light border shadow-2xs" onclick="previewDocModal(<?php echo $docId; ?>, '<?php echo htmlspecialchars(addslashes($docType)); ?>')">
                                                <i class="bi bi-eye text-primary"></i> Preview
                                            </button>
                                            <a href="<?php echo $streamUrl; ?>" target="_blank" class="btn btn-sm btn-outline-secondary shadow-2xs" title="Open in new tab">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Add Subject Credit Evaluation Form -->
            <div class="col-12 col-xl-7">
                <div class="card shadow-sm border-0 h-100" style="border-radius:16px; overflow:hidden; border:1px solid #e0eded !important;">
                    <div class="card-header bg-light border-0" style="padding:16px 20px;">
                        <div class="fw-bold text-navy-alt"><i class="bi bi-plus-circle-fill me-1.5 text-primary"></i> Evaluate Previous Subject</div>
                        <div class="small text-muted">Record previous subject taken and map to equivalent academy subject</div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="../actions/credit_evaluation_actions">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="add_evaluation">
                            <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label small fw-bold text-dark">Previous School / Institution</label>
                                    <input type="text" name="previous_school" class="form-control form-control-sm" placeholder="e.g. Batangas State University / FEATI">
                                </div>

                                <div class="col-12 col-md-5">
                                    <label class="form-label small fw-bold text-dark">Previous Subject Code <span class="text-danger">*</span></label>
                                    <input type="text" name="previous_subject_code" class="form-control form-control-sm" placeholder="e.g. ENG 101" required>
                                </div>

                                <div class="col-12 col-md-7">
                                    <label class="form-label small fw-bold text-dark">Previous Subject Title <span class="text-danger">*</span></label>
                                    <input type="text" name="previous_subject_title" class="form-control form-control-sm" placeholder="e.g. Purposive Communication" required>
                                </div>

                                <div class="col-6 col-md-4">
                                    <label class="form-label small fw-bold text-dark">Units <span class="text-danger">*</span></label>
                                    <input type="number" step="0.5" min="0.5" max="15" name="previous_units" class="form-control form-control-sm" value="3.0" required>
                                </div>

                                <div class="col-6 col-md-4">
                                    <label class="form-label small fw-bold text-dark">Grade Received</label>
                                    <input type="text" name="previous_grade" class="form-control form-control-sm" placeholder="e.g. 1.50 / 88 / A">
                                </div>

                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-bold text-dark">Evaluation Decision <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select form-select-sm" required>
                                        <option value="credited" selected>Credited (Approved)</option>
                                        <option value="pending">Pending Further Review</option>
                                        <option value="rejected">Rejected (Not Credited)</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-bold text-dark">Equivalent Academy Curriculum Subject</label>
                                    <select name="equivalent_subject_id" class="form-select form-select-sm">
                                        <option value="">— Select Equivalent Curriculum Subject (Optional) —</option>
                                        <?php foreach ($availableSubjects as $as): ?>
                                            <option value="<?php echo (int)$as['id']; ?>">
                                                <?php echo htmlspecialchars($as['subject_code'] . ' - ' . $as['subject_name'] . ' (' . $as['units'] . ' units)'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small text-muted">Maps this credited previous course to an existing subject in our curriculum.</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-bold text-dark">Remarks / Justification</label>
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="e.g. Substantially equivalent syllabus; Validated against official TOR">
                                </div>

                                <div class="col-12 text-end pt-2">
                                    <button type="submit" class="btn btn-brand-primary btn-sm px-4 fw-semibold shadow-sm">
                                        <i class="bi bi-check2-circle me-1"></i> Save Subject Evaluation
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Evaluated Subjects Master Table -->
        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden; border:1px solid #e0eded !important;">
            <div class="card-header bg-light border-0 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding:16px 20px;">
                <div>
                    <div class="fw-bold text-navy-alt"><i class="bi bi-table me-1.5 text-primary"></i> Evaluated Subject Records</div>
                    <div class="small text-muted">History of all credit evaluations performed for this transferee student</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="width: 200px;">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="evaluationSearchInput" class="form-control form-control-sm border-start-0 ps-0" placeholder="Search records...">
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 fw-bold">
                        <?php echo $totalCreditedUnits; ?> Total Credited Units
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="evaluationsTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" tabulator-field="previous_subject">Previous Subject</th>
                                <th tabulator-field="previous_school">Previous School</th>
                                <th class="text-center" tabulator-field="units_grade">Units &amp; Grade</th>
                                <th tabulator-field="equiv_subject">Equivalent Curriculum Subject</th>
                                <th class="text-center" tabulator-field="decision">Decision</th>
                                <th tabulator-field="remarks_evaluator">Remarks &amp; Evaluator</th>
                                <th class="pe-4 text-end" tabulator-field="action">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($evaluations)): ?>
                                <?php foreach ($evaluations as $eval): ?>
                                    <?php 
                                        $evalId = (int)$eval['id'];
                                        $statusBadge = match($eval['status']) {
                                            'credited' => '<span class="badge bg-success-subtle text-success border border-success px-2.5 py-1 fw-bold"><i class="bi bi-check-circle-fill me-1"></i>Credited</span>',
                                            'rejected' => '<span class="badge bg-danger-subtle text-danger border border-danger px-2.5 py-1 fw-bold"><i class="bi bi-x-circle-fill me-1"></i>Rejected</span>',
                                            default => '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2.5 py-1 fw-bold"><i class="bi bi-clock me-1"></i>Pending</span>',
                                        };
                                        $evaluatorName = trim(($eval['eval_first'] ?? '') . ' ' . ($eval['eval_last'] ?? '')) ?: 'Registrar';
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($eval['previous_subject_code']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($eval['previous_subject_title']); ?></div>
                                        </td>
                                        <td>
                                            <span class="small text-dark"><?php echo htmlspecialchars($eval['previous_school'] ?: '—'); ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border px-2 py-1 font-monospace fw-bold">
                                                <?php echo (float)$eval['previous_units']; ?> units
                                            </span>
                                            <?php if (!empty($eval['previous_grade'])): ?>
                                                <div class="small text-muted mt-0.5">Grade: <?php echo htmlspecialchars($eval['previous_grade']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($eval['equiv_code'])): ?>
                                                <div class="fw-semibold text-navy"><?php echo htmlspecialchars($eval['equiv_code']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($eval['equiv_name'] ?? ''); ?> (<?php echo (float)($eval['equiv_units'] ?? 0); ?> units)</div>
                                            <?php else: ?>
                                                <span class="text-muted small">No equivalent mapped</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php echo $statusBadge; ?>
                                        </td>
                                        <td>
                                            <div class="small text-dark"><?php echo htmlspecialchars($eval['remarks'] ?: '—'); ?></div>
                                            <div class="small text-muted" style="font-size:0.72rem;">
                                                By <?php echo htmlspecialchars($evaluatorName); ?> &bull; <?php echo date('M d, Y', strtotime($eval['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <form method="POST" action="../actions/credit_evaluation_actions" class="d-inline" onsubmit="return confirm('Remove this subject evaluation record?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                                                <input type="hidden" name="action" value="delete_evaluation">
                                                <input type="hidden" name="evaluation_id" value="<?php echo $evalId; ?>">
                                                <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger shadow-2xs" title="Delete evaluation">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Document Preview Modal (Embedded Viewer) -->
<div class="modal fade" id="docPreviewModal" tabindex="-1" aria-labelledby="docPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header text-white" style="background:linear-gradient(135deg,var(--brand-primary) 0%,var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold" id="docPreviewModalLabel"><i class="bi bi-file-earmark-text me-2"></i> Document Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="height:70vh; background:#525659;">
                <iframe id="docPreviewIframe" src="about:blank" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
function previewDocModal(docId, docTitle) {
    const modalEl = document.getElementById('docPreviewModal');
    const titleEl = document.getElementById('docPreviewModalLabel');
    const iframe = document.getElementById('docPreviewIframe');
    if (titleEl) titleEl.innerHTML = '<i class="bi bi-file-earmark-text me-2"></i> Document Preview: ' + docTitle;
    if (iframe) iframe.src = '../actions/view_document?id=' + docId;
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function stripHtml(html) {
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

document.addEventListener("DOMContentLoaded", function() {
    if (typeof Tabulator === 'undefined') return;

    // Transferees List Table
    var transfereesTableEl = document.getElementById('transfereesTable');
    if (transfereesTableEl) {
        var transfereesTable = new Tabulator("#transfereesTable", {
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
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-folder-x fs-1 d-block mb-2 text-muted-light'></i>No transferee students found in the system.</div>",
            columns: [
                { title: "Student / Applicant", field: "student", widthGrow: 2.8, minWidth: 180, formatter: "html" },
                { title: "Program", field: "program", widthGrow: 1.2, minWidth: 100, formatter: "html" },
                { title: "Year Level", field: "year", widthGrow: 1.1, minWidth: 90, formatter: "html" },
                { title: "Evaluated Subjects", field: "total_evals", widthGrow: 1.2, minWidth: 100, hozAlign: "center", formatter: "html" },
                { title: "Credited Units", field: "credited_units", widthGrow: 1.1, minWidth: 90, hozAlign: "center", formatter: "html" },
                { title: "Pending Decisions", field: "pending_evals", widthGrow: 1.2, minWidth: 100, hozAlign: "center", formatter: "html" },
                { title: "Action", field: "action", widthGrow: 1.4, minWidth: 130, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });

        var tSearch = document.getElementById('transfereeSearchInput');
        if (tSearch) {
            tSearch.addEventListener('input', function() {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    transfereesTable.clearFilter();
                } else {
                    transfereesTable.setFilter(function(data) {
                        return stripHtml(data.student).toLowerCase().includes(term) ||
                               stripHtml(data.program).toLowerCase().includes(term) ||
                               stripHtml(data.year).toLowerCase().includes(term);
                    });
                }
            });
        }
    }

    // Evaluated Subjects Master Table
    var evaluationsTableEl = document.getElementById('evaluationsTable');
    if (evaluationsTableEl) {
        var evaluationsTable = new Tabulator("#evaluationsTable", {
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
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-journal-x fs-1 d-block mb-2 text-muted-light'></i>No subjects have been evaluated for this transferee student yet.</div>",
            columns: [
                { title: "Previous Subject", field: "previous_subject", widthGrow: 2.2, minWidth: 150, formatter: "html" },
                { title: "Previous School", field: "previous_school", widthGrow: 1.8, minWidth: 120, formatter: "html" },
                { title: "Units & Grade", field: "units_grade", widthGrow: 1.0, minWidth: 90, hozAlign: "center", formatter: "html" },
                { title: "Equivalent Curriculum Subject", field: "equiv_subject", widthGrow: 2.5, minWidth: 160, formatter: "html" },
                { title: "Decision", field: "decision", widthGrow: 1.0, minWidth: 90, hozAlign: "center", formatter: "html" },
                { title: "Remarks & Evaluator", field: "remarks_evaluator", widthGrow: 1.8, minWidth: 120, formatter: "html" },
                { title: "Action", field: "action", widthGrow: 1.0, minWidth: 85, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
            ]
        });

        var eSearch = document.getElementById('evaluationSearchInput');
        if (eSearch) {
            eSearch.addEventListener('input', function() {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    evaluationsTable.clearFilter();
                } else {
                    evaluationsTable.setFilter(function(data) {
                        return stripHtml(data.previous_subject).toLowerCase().includes(term) ||
                               stripHtml(data.previous_school).toLowerCase().includes(term) ||
                               stripHtml(data.equiv_subject).toLowerCase().includes(term) ||
                               stripHtml(data.remarks_evaluator).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
