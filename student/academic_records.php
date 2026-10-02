<?php
require_once '../includes/auth_check.php';
checkRole(['student']);
require_once '../config/database.php';

$student = null;
$grades = [];
$units = 0.0;
$weighted = 0.0;
$error = null;
$downloadMode = isset($_GET['download']) && $_GET['download'] === '1';

try {
    $studentStmt = $pdo->prepare("SELECT s.id, s.first_name, s.middle_name, s.last_name, s.program_applying_for, s.year_level, u.username, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = :user_id LIMIT 1");
    $studentStmt->execute(['user_id' => (int)$_SESSION['user_id']]);
    $student = $studentStmt->fetch();

    if ($student) {
        $q = $pdo->prepare(
            "SELECT sg.id,
                    COALESCE(sub.subject_code, c.course_code, sec.section_name, 'Subject') AS course_code,
                    COALESCE(sub.subject_name, c.course_name, sec.section_name, 'Subject') AS course_name,
                    COALESCE(sub.units, c.units, 0) AS units,
                    t.school_year, t.semester,
                    sg.prelim_grade, sg.midterm_grade, sg.final_exam_grade, sg.final_grade, sg.remarks
             FROM student_grades sg
             JOIN grade_submissions gs ON gs.id = sg.grade_submission_id
               AND gs.status IN ('approved','locked')
             JOIN enrollments e ON e.id = sg.enrollment_id
             JOIN sections sec ON sec.id = e.section_id
             LEFT JOIN section_subjects ss ON ss.id = sg.section_subject_id
             LEFT JOIN subjects sub ON sub.id = ss.subject_id
             LEFT JOIN courses c ON c.id = sec.course_id
             JOIN academic_terms t ON t.id = gs.academic_term_id
             JOIN students s ON s.id = sg.student_id
             WHERE s.user_id = :user_id
             ORDER BY t.school_year DESC, t.semester DESC, course_code ASC"
        );

        $q->execute(['user_id' => (int)$_SESSION['user_id']]);
        $grades = $q->fetchAll();

        foreach ($grades as $grade) {
            if ($grade['final_grade'] !== null && $grade['final_grade'] !== '') {
                $units    += (float)$grade['units'];
                $weighted += (float)$grade['final_grade'] * (float)$grade['units'];
            }
        }
    }
} catch (PDOException $e) {
    error_log('Student academic records query failed: ' . $e->getMessage());
    $error = 'Academic records are temporarily unavailable.';
}

if ($downloadMode) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="student-academic-transcript.html"');
}

$page_title = 'Academic Records';
if (!$downloadMode) {
    require_once '../includes/header.php';
}
?>
<?php if ($downloadMode): ?>
<style>
    body { font-family: Arial, sans-serif; color: #1a1a1a; margin: 24px; }
    .transcript-document { max-width: 960px; margin: 0 auto; }
    .header { border-bottom: 2px solid #d9d9d9; padding-bottom: 16px; margin-bottom: 24px; text-align: center; }
    h1, h2, h3 { margin: 0; }
    .meta { display: grid; grid-template-columns: repeat(2, minmax(180px, 1fr)); gap: 12px; margin: 20px 0 26px; font-size: 14px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border: 1px solid #d0d0d0; padding: 8px 10px; text-align: left; font-size: 13px; }
    th { background: #f3f4f6; }
    .summary { display: flex; justify-content: space-between; gap: 16px; margin-top: 18px; font-weight: 600; }
    @media print { body { margin: 0; } }
</style>
<?php endif; ?>

<?php if (!$downloadMode): ?>
<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h3 class="m-0 text-navy-alt">Academic Records</h3>
        <p class="text-muted small m-0">Only Registrar-approved and locked academic results appear in this record.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($grades)): ?>
            <a href="academic_records?download=1" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Download Transcript</a>
            <button type="button" class="btn btn-brand-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / Save PDF</button>
        <?php else: ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="No approved academic records to download"><i class="bi bi-download me-1"></i>Download Transcript</button>
            <button type="button" class="btn btn-secondary btn-sm opacity-50" disabled title="No approved academic records to print"><i class="bi bi-printer me-1"></i>Print / Save PDF</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php elseif (!$student): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted d-block mb-3"></i>
            <h5>Student record not found</h5>
            <p class="text-muted mb-0">Your academic record cannot be displayed because no linked student profile was found.</p>
        </div>
    </div>
<?php elseif (empty($grades)): ?>
    <div class="card card-premium shadow-sm">
        <div class="card-body card-body-premium text-center py-5">
            <i class="bi bi-journal-x fs-1 text-muted d-block mb-3"></i>
            <h5>No approved academic results yet</h5>
            <p class="text-muted mb-0">Approved or locked grade entries will appear here once the Registrar finalizes them.</p>
        </div>
    </div>
<?php else: ?>
    <?php $gwa = $units > 0 ? $weighted / $units : null; ?>
    <article class="transcript-document card card-premium shadow-sm<?php echo $downloadMode ? '' : ' mb-4'; ?>">
        <div class="card-body card-body-premium p-4 p-md-5">
            <div class="header">
                <h2>NCST Maritime Academy</h2>
                <div class="text-uppercase text-muted small fw-bold" style="letter-spacing:0.08em;">Official Academic Transcript</div>
            </div>

            <div class="meta">
                <div><strong>Student:</strong> <?php echo htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></div>
                <div><strong>Student ID:</strong> #<?php echo (int)$student['id']; ?></div>
                <div><strong>Program:</strong> <?php echo htmlspecialchars($student['program_applying_for'] ?: '—'); ?></div>
                <div><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level'] ?: '—'); ?></div>
                <div><strong>Email:</strong> <?php echo htmlspecialchars($student['email'] ?: '—'); ?></div>
                <div><strong>Username:</strong> <?php echo htmlspecialchars($student['username'] ?: '—'); ?></div>
            </div>

            <?php if (!$downloadMode): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card card-premium shadow-sm">
                        <div class="card-body card-body-premium">
                            <span class="text-muted small">Cumulative GWA</span>
                            <h3 class="m-0 text-navy-alt"><?php echo $gwa !== null ? number_format((float)$gwa, 2) : '—'; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card card-premium shadow-sm">
                        <div class="card-body card-body-premium">
                            <span class="text-muted small">Completed units</span>
                            <h3 class="m-0 text-navy-alt"><?php echo number_format($units, 2); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="summary">
                <span>Cumulative GWA: <?php echo $gwa !== null ? number_format((float)$gwa, 2) : '—'; ?></span>
                <span>Completed units: <?php echo number_format($units, 2); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!$downloadMode): ?>
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h6 class="fw-bold text-navy-alt m-0"><i class="bi bi-journal-text me-1"></i> Course Grades Breakdown</h6>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="academicRecordsSearch" class="form-control form-control-sm border-start-0 ps-0" placeholder="Filter courses or term...">
                </div>
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table align-middle mb-0" id="academicRecordsTable" style="width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" tabulator-field="term" style="width: 140px;">Term</th>
                            <th tabulator-field="course">Subject / Course</th>
                            <th class="text-center" tabulator-field="prelim" style="width: 100px;">Prelim</th>
                            <th class="text-center" tabulator-field="midterm" style="width: 100px;">Midterm</th>
                            <th class="text-center" tabulator-field="final_exam" style="width: 110px;">Final Exam</th>
                            <th class="text-center" tabulator-field="final_grade" style="width: 110px;">Final Grade</th>
                            <th class="pe-4 text-center" tabulator-field="remark" style="width: 120px;">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($grades)): ?>
                            <?php foreach ($grades as $grade): ?>
                                <tr>
                                    <td class="ps-4 text-nowrap"><?php echo htmlspecialchars($grade['school_year'] . ' / ' . ucfirst($grade['semester'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($grade['course_code']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($grade['course_name']); ?></small></td>
                                    <td class="text-center"><?php echo $grade['prelim_grade'] !== null ? number_format((float)$grade['prelim_grade'], 2) : '&mdash;'; ?></td>
                                    <td class="text-center"><?php echo $grade['midterm_grade'] !== null ? number_format((float)$grade['midterm_grade'], 2) : '&mdash;'; ?></td>
                                    <td class="text-center"><?php echo $grade['final_exam_grade'] !== null ? number_format((float)$grade['final_exam_grade'], 2) : '&mdash;'; ?></td>
                                    <td class="text-center"><strong><?php echo $grade['final_grade'] !== null ? number_format((float)$grade['final_grade'], 2) : '&mdash;'; ?></strong></td>
                                    <td class="pe-4 text-center"><?php echo htmlspecialchars($grade['remarks'] ?: '&mdash;'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </article>
<?php endif; ?>

<?php if (!$downloadMode): ?>
<style>
.tabulator {
    font-family: inherit;
    border: none;
    background-color: transparent;
    width: 100% !important;
    min-width: 0 !important;
}
.tabulator .tabulator-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.tabulator .tabulator-header .tabulator-col {
    background: transparent;
    border-right: none;
    padding: 8px 4px;
}
.tabulator .tabulator-row {
    border-bottom: 1px solid #f1f5f9;
    min-height: 48px;
    background: #ffffff;
}
.tabulator .tabulator-row:hover {
    background-color: #f8fafc !important;
}
.tabulator .tabulator-row .tabulator-cell {
    padding: 10px 12px;
    border-right: none;
    vertical-align: middle;
    font-size: 0.86rem;
    display: inline-flex;
    align-items: center;
}
.tabulator-row.tabulator-responsive-collapse {
    background: #f8fafc !important;
    padding: 8px 16px !important;
}
.tabulator .tabulator-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 8px 16px;
}
.tabulator-responsive-collapse table {
    width: 100%;
    font-size: 0.85rem;
}
.tabulator-responsive-collapse td {
    padding: 4px 8px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var academicRecordsTableEl = document.getElementById('academicRecordsTable');
    if (academicRecordsTableEl) {
        function stripHtml(html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || '';
        }

        var academicRecordsTable = new Tabulator("#academicRecordsTable", {
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
            placeholder: "<div class='text-center py-5 text-muted'><i class='bi bi-journal-x fs-1 d-block mb-2 text-muted-light'></i>No approved academic records found.</div>",
            columns: [
                { title: "Term", field: "term", width: 140, formatter: "html" },
                { title: "Subject / Course", field: "course", minWidth: 200, formatter: "html" },
                { 
                    title: "Prelim", 
                    field: "prelim", 
                    width: 100, 
                    hozAlign: "center", 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a)) || 999;
                        var bNum = parseFloat(stripHtml(b)) || 999;
                        return aNum - bNum;
                    }
                },
                { 
                    title: "Midterm", 
                    field: "midterm", 
                    width: 100, 
                    hozAlign: "center", 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a)) || 999;
                        var bNum = parseFloat(stripHtml(b)) || 999;
                        return aNum - bNum;
                    }
                },
                { 
                    title: "Final Exam", 
                    field: "final_exam", 
                    width: 110, 
                    hozAlign: "center", 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a)) || 999;
                        var bNum = parseFloat(stripHtml(b)) || 999;
                        return aNum - bNum;
                    }
                },
                { 
                    title: "Final Grade", 
                    field: "final_grade", 
                    width: 110, 
                    hozAlign: "center", 
                    formatter: "html",
                    sorter: function(a, b) {
                        var aNum = parseFloat(stripHtml(a)) || 999;
                        var bNum = parseFloat(stripHtml(b)) || 999;
                        return aNum - bNum;
                    }
                },
                { title: "Remark", field: "remark", width: 120, hozAlign: "center", formatter: "html" }
            ]
        });

        var searchInput = document.getElementById('academicRecordsSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var term = this.value.trim().toLowerCase();
                if (!term) {
                    academicRecordsTable.clearFilter();
                } else {
                    academicRecordsTable.setFilter(function (data) {
                        return stripHtml(data.term).toLowerCase().includes(term) ||
                               stripHtml(data.course).toLowerCase().includes(term) ||
                               stripHtml(data.remark).toLowerCase().includes(term);
                    });
                }
            });
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
<?php endif; ?>
