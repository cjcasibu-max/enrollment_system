<?php
/**
 * Registrar Grade Approvals
 * Review and approve submitted teacher gradebooks.
 * Unified NCST Maritime Academy Theme.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);
ensureCsrfToken();
require_once '../config/database.php';

try {
    $q = $pdo->query("
        SELECT gs.id, gs.status, gs.submitted_at,
               s.section_name,
               COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
               COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
               t.school_year, t.semester, u.username teacher_name, COUNT(sg.id) grade_count,
               (SELECT COUNT(*) FROM section_subjects WHERE section_id = s.id) AS subject_count
        FROM grade_submissions gs 
        JOIN sections s ON s.id = gs.section_id 
        LEFT JOIN courses c ON c.id = s.course_id 
        JOIN academic_terms t ON t.id = gs.academic_term_id 
        JOIN users u ON u.id = gs.teacher_id 
        LEFT JOIN student_grades sg ON sg.grade_submission_id = gs.id 
        WHERE gs.status = 'submitted' 
        GROUP BY gs.id, s.section_name, c.course_code, c.course_name, t.school_year, t.semester, u.username 
        ORDER BY gs.submitted_at ASC
    ");

    $submissions = $q->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch grade submissions failed: " . $e->getMessage());
    $submissions = [];
}

$page_title = 'Grade Approvals';
require_once '../includes/header.php';
?>

<!-- Page Heading -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-award me-1"></i> Academic Records</div>
        <h3 class="m-0 text-navy-alt fw-bold">Grade Approvals</h3>
        <p class="text-muted small m-0">Review submitted gradebooks. Approval permanently publishes grades and locks teacher editing.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge" style="background: var(--surface-tint); color: var(--brand-dark); border: 1px solid var(--brand-primary-soft); padding: 8px 14px; font-size: 0.8rem; font-weight: 700; border-radius: 999px;">
            <i class="bi bi-hourglass-split me-1 text-brand-primary"></i> <?php echo count($submissions); ?> Pending Approval
        </span>
    </div>
</div>

<!-- Grade Submissions Table Card -->
<div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-check2-circle"></i> Gradebook Submissions Queue
        </h5>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 220px;">
                <span class="input-group-text bg-white bg-opacity-25 border-0 text-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control form-control-sm border-0 bg-white bg-opacity-10 text-white placeholder-white" placeholder="Search submissions...">
            </div>
            <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
                <?php echo count($submissions); ?> Gradebook<?php echo count($submissions) !== 1 ? 's' : ''; ?>
            </span>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="gradeApprovalsTable" style="width: 100%;">
                <thead>
                    <tr>
                        <th class="ps-4" tabulator-field="subject_course">Subject & Course</th>
                        <th tabulator-field="term">Academic Term</th>
                        <th tabulator-field="instructor">Instructor</th>
                        <th tabulator-field="cadet_grades">Cadet Grades</th>
                        <th tabulator-field="submitted">Submission Date</th>
                        <th class="pe-4 text-end" tabulator-field="decision">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($submissions)): foreach ($submissions as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <?php if ((int)$row['subject_count'] > 0): ?>
                                    <div class="fw-bold text-navy"><?php echo htmlspecialchars($row['section_name']); ?></div>
                                    <div class="text-muted small"><?php echo (int)$row['subject_count']; ?> Subjects</div>
                                <?php else: ?>
                                    <div class="fw-bold text-navy"><?php echo htmlspecialchars($row['course_code']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($row['course_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-navy border px-2 py-1" style="font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($row['school_year'] . ' • ' . ucfirst($row['semester']) . ' Sem'); ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white shadow-sm flex-shrink-0" 
                                         style="width: 32px; height: 32px; font-size: 0.75rem; background: linear-gradient(135deg, var(--brand-primary), var(--brand-dark));">
                                        <?php echo strtoupper(substr($row['teacher_name'], 0, 2)); ?>
                                    </div>
                                    <span class="fw-semibold text-dark"><?php echo htmlspecialchars($row['teacher_name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-dark border px-2.5 py-1 font-monospace fw-bold" style="font-size: 0.75rem;">
                                    <?php echo (int)$row['grade_count']; ?> Cadets
                                </span>
                            </td>
                            <td>
                                <div class="small text-dark fw-semibold"><?php echo date('M d, Y', strtotime($row['submitted_at'])); ?></div>
                                <div class="text-muted small" style="font-size: 0.7rem;"><?php echo date('g:i A', strtotime($row['submitted_at'])); ?></div>
                            </td>
                            <td class="pe-4 text-end">
                                <form method="post" action="../actions/academic_actions" class="d-inline" onsubmit="return confirm('Are you sure you want to approve and lock this gradebook? This cannot be undone.');">
                                    <input type="hidden" name="action" value="approve_grades">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="submission_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-brand-primary px-3 fw-semibold shadow-sm">
                                        <i class="bi bi-check2-circle me-1"></i> Approve &amp; Lock
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
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

let gradeApprovalsTable = null;
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Tabulator === 'undefined') return;

    gradeApprovalsTable = new Tabulator("#gradeApprovalsTable", {
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
        placeholder: "<div class='text-center py-5 text-muted'><div class='mb-3 fs-1 text-muted-light'><i class='bi bi-patch-check'></i></div><h6 class='fw-bold text-dark'>All gradebooks are up to date</h6><p class='small text-muted mb-0'>No grade submissions are currently awaiting registrar approval.</p></div>",
        columns: [
            { title: "Subject & Course", field: "subject_course", widthGrow: 2.8, minWidth: 180, formatter: "html" },
            { title: "Academic Term", field: "term", widthGrow: 1.3, minWidth: 110, formatter: "html" },
            { title: "Instructor", field: "instructor", widthGrow: 2.0, minWidth: 130, formatter: "html" },
            { title: "Cadet Grades", field: "cadet_grades", widthGrow: 1.0, minWidth: 90, formatter: "html" },
            { title: "Submission Date", field: "submitted", widthGrow: 1.2, minWidth: 105, formatter: "html" },
            { title: "Decision", field: "decision", widthGrow: 1.4, minWidth: 120, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    var sInput = document.getElementById('searchInput');
    if (sInput) {
        sInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                gradeApprovalsTable.clearFilter();
            } else {
                gradeApprovalsTable.setFilter(function(data) {
                    return stripHtml(data.subject_course).toLowerCase().includes(term) ||
                           stripHtml(data.term).toLowerCase().includes(term) ||
                           stripHtml(data.instructor).toLowerCase().includes(term) ||
                           stripHtml(data.cadet_grades).toLowerCase().includes(term) ||
                           stripHtml(data.submitted).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
