<?php
/**
 * Sections Management Portal
 * Allows Registrars to manage academic sections, schedules, courses/subjects, and enrolled cadets.
 * Organized by program (BSMT / BSMarE), year level, and active academic term.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';

/** @var PDO $pdo */
$activeTerm = getActiveAcademicTerm($pdo);

$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : 'BSMT';
if (!in_array($selectedProgram, ['BSMT', 'BSMarE'], true)) {
    $selectedProgram = 'BSMT';
}

$selectedYear = isset($_GET['year_level']) ? trim($_GET['year_level']) : '';
$selectedSemester = isset($_GET['semester']) ? trim($_GET['semester']) : '';
$selectedStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $stmtPrograms = $pdo->query("SELECT id, program_code, program_name FROM programs WHERE program_code IN ('BSMT', 'BSMarE') ORDER BY program_code ASC");
    $programs = $stmtPrograms->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch programs failed: " . $e->getMessage());
    $programs = [];
}

// Fetch sections for current program and filters
$query = "
    SELECT s.*, 
           (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id AND e.status != 'dropped') AS enrollment_count,
           (SELECT COUNT(*) FROM section_subjects ss WHERE ss.section_id = s.id) AS subject_count,
           (SELECT COALESCE(SUM(sub.units), 0) FROM section_subjects ss JOIN subjects sub ON sub.id = ss.subject_id WHERE ss.section_id = s.id) AS total_units,
           t.school_year, t.semester
    FROM sections s
    LEFT JOIN academic_terms t ON t.id = s.academic_term_id
    WHERE s.academic_term_id = " . ($activeTerm ? (int)$activeTerm['id'] : 0) . "
      AND (s.program = :program OR (s.program IS NULL AND s.section_name LIKE :prog_prefix))
";
$params = [
    'program' => $selectedProgram,
    'prog_prefix' => ($selectedProgram === 'BSMarE' ? 'BSMarE%' : 'BSMT%')
];

if ($selectedYear !== '') {
    $query .= " AND s.year_level = :year_level";
    $params['year_level'] = $selectedYear;
}
if ($selectedSemester !== '') {
    $query .= " AND t.semester = :semester";
    $params['semester'] = $selectedSemester;
}
if ($selectedStatus !== '') {
    $query .= " AND s.status = :status";
    $params['status'] = $selectedStatus;
}
if ($search !== '') {
    $query .= " AND s.section_name LIKE :term";
    $params['term'] = '%' . $search . '%';
}

$query .= " ORDER BY s.year_level ASC, s.section_name ASC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sections = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch sections failed: " . $e->getMessage());
    $sections = [];
}

// Fetch all courses for section creation (with program identifier)
try {
    $stmtCourses = $pdo->query("
        SELECT c.id, c.course_code, c.course_name, c.units, c.program_id, p.program_code
        FROM courses c
        LEFT JOIN programs p ON p.id = c.program_id
        ORDER BY c.course_code ASC
    ");
    $allCourses = $stmtCourses->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch courses for select failed: " . $e->getMessage());
    $allCourses = [];
}

// Fetch teachers
try {
    $stmtTeachers = $pdo->query("SELECT id, username, email FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY username ASC");
    $teachers = $stmtTeachers->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch teachers for select failed: " . $e->getMessage());
    $teachers = [];
}

$page_title = "Manage Sections";
require_once '../includes/header.php';
?>

<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-grid-3x3-gap me-1"></i> Academic Management</div>
        <h3 class="m-0 text-navy-alt fw-bold">Sections Management</h3>
        <p class="text-muted small m-0">Manage maritime class sections, schedules, classroom allocations, and cadet enrollments.</p>
    </div>
    <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-2 shadow-sm px-4 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#addSectionModal">
        <i class="bi bi-plus-lg"></i> Add New Section
    </button>
</div>

<!-- =========================================================================
     UNIFIED MARITIME CONTROL & FILTER BAR
     ========================================================================= -->
<div class="maritime-control-bar mb-4">
    <!-- Top Row: Program Selector Tabs & Active Term Note -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 border-bottom border-light-subtle">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-uppercase text-muted me-2"><i class="bi bi-mortarboard me-1"></i> Program:</span>
            <a href="sections?program=BSMT<?php echo $selectedYear ? '&year_level=' . urlencode($selectedYear) : ''; ?><?php echo $selectedSemester ? '&semester=' . urlencode($selectedSemester) : ''; ?><?php echo $selectedStatus ? '&status=' . urlencode($selectedStatus) : ''; ?>" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMT' ? 'active' : ''; ?>">
                <i class="bi bi-compass"></i> BSMT
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMT' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Transportation</span>
            </a>
            <a href="sections?program=BSMarE<?php echo $selectedYear ? '&year_level=' . urlencode($selectedYear) : ''; ?><?php echo $selectedSemester ? '&semester=' . urlencode($selectedSemester) : ''; ?><?php echo $selectedStatus ? '&status=' . urlencode($selectedStatus) : ''; ?>" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMarE' ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i> BSMarE
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMarE' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Engineering</span>
            </a>
        </div>

        <div class="d-flex align-items-center gap-2 text-muted small">
            <i class="bi bi-calendar-check text-brand-primary"></i>
            <span>Active Term: <strong class="text-navy"><?php echo $activeTerm ? htmlspecialchars($activeTerm['school_year'] . ' - ' . ucfirst($activeTerm['semester']) . ' Sem') : 'No Active Term'; ?></strong></span>
        </div>
    </div>

    <!-- Bottom Row: Year Filters, Status Pills, and Search Input -->
    <form method="GET" action="sections" class="row g-3 align-items-center pt-3">
        <input type="hidden" name="program" value="<?php echo htmlspecialchars($selectedProgram); ?>">
        
        <!-- Year Level Filter Pills -->
        <div class="col-12 col-lg-5">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Year Level</label>
            <div class="d-flex flex-wrap gap-1">
                <a href="sections?program=<?php echo urlencode($selectedProgram); ?>&semester=<?php echo urlencode($selectedSemester); ?>&status=<?php echo urlencode($selectedStatus); ?>&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $selectedYear === '' ? 'active' : ''; ?>">
                    All Years
                </a>
                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                    <a href="sections?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($yl); ?>&semester=<?php echo urlencode($selectedSemester); ?>&status=<?php echo urlencode($selectedStatus); ?>&search=<?php echo urlencode($search); ?>" 
                       class="filter-pill <?php echo $selectedYear === $yl ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($yl); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Status Filter Pills -->
        <div class="col-12 col-md-5 col-lg-3">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Status</label>
            <div class="d-flex flex-wrap gap-1">
                <a href="sections?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($selectedYear); ?>&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $selectedStatus === '' ? 'active' : ''; ?>">
                    All
                </a>
                <a href="sections?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($selectedYear); ?>&status=active&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $selectedStatus === 'active' ? 'active' : ''; ?>">
                    Active
                </a>
                <a href="sections?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($selectedYear); ?>&status=inactive&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $selectedStatus === 'inactive' ? 'active' : ''; ?>">
                    Inactive
                </a>
            </div>
        </div>

        <!-- Live Search Group -->
        <div class="col-12 col-md-7 col-lg-4">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Search Section</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Section, subject, room, teacher..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button type="submit" class="btn btn-brand-primary px-3"><i class="bi bi-funnel"></i></button>
                <?php if ($search !== '' || $selectedYear !== '' || $selectedStatus !== ''): ?>
                    <a href="sections?program=<?php echo urlencode($selectedProgram); ?>" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="bi bi-arrow-clockwise"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- =========================================================================
     SECTIONS TABLE CARD
     ========================================================================= -->
<div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-grid-3x3-gap"></i> <?php echo htmlspecialchars($selectedProgram); ?> Class Sections
            <?php if ($selectedYear): ?> <span style="opacity: 0.85; font-weight: 500; font-size: 0.85rem;">&bull; <?php echo htmlspecialchars($selectedYear); ?></span><?php endif; ?>
        </h5>
        <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
            <?php echo count($sections); ?> Section<?php echo count($sections) !== 1 ? 's' : ''; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="sectionsTable" style="width: 100%;">
                <thead>
                    <tr>
                        <th class="ps-4" tabulator-field="section">Section</th>
                        <th tabulator-field="subjects">Assigned Subjects</th>
                        <th tabulator-field="capacity">Cadet Capacity</th>
                        <th tabulator-field="status">Status</th>
                        <th class="pe-4 text-end" tabulator-field="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sections)): foreach ($sections as $s): 
                        $enrolled = (int)$s['enrollment_count'];
                        $cap = (int)$s['capacity'];
                        $pct = $cap > 0 ? min(100, round(($enrolled / $cap) * 100)) : 0;
                        $progressColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#0b9b98');
                    ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($s['section_name'] ?? ('Section #' . $s['id'])); ?></div>
                                <div class="d-flex align-items-center gap-1 mt-1">
                                    <span class="badge bg-light text-navy border px-2 py-0.5" style="font-size: 0.7rem;"><?php echo htmlspecialchars($s['year_level'] ?? '1st Year'); ?></span>
                                    <span class="badge bg-secondary-subtle text-dark" style="font-size: 0.7rem;">AY <?php echo htmlspecialchars($s['school_year'] ?? ''); ?></span>
                                </div>
                            </td>
                            <td>
                                <a href="section_details?id=<?php echo $s['id']; ?>" class="text-decoration-none">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-semibold">
                                        <i class="bi bi-book-half me-1"></i><?php echo (int)$s['subject_count']; ?> Subjects
                                    </span>
                                </a>
                                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                                    <?php echo (float)$s['total_units']; ?> Total Units
                                </div>
                            </td>
                            <td>
                                <div class="capacity-progress-wrap">
                                    <div class="d-flex justify-content-between small fw-bold mb-1">
                                        <span class="text-dark"><?php echo $enrolled; ?> / <?php echo $cap; ?></span>
                                        <span class="text-muted" style="font-size: 0.72rem;"><?php echo $pct; ?>%</span>
                                    </div>
                                    <div class="capacity-progress-bar">
                                        <div class="capacity-progress-fill" style="width: <?php echo $pct; ?>%; background-color: <?php echo $progressColor; ?>;"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (($s['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end">
                                <div class="btn-group btn-group-sm shadow-sm" role="group">
                                    <a href="section_details?id=<?php echo $s['id']; ?>" class="btn btn-outline-primary" title="View Section & Enrolled Students" aria-label="View Section & Enrolled Students">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-secondary" onclick="openEditSection(<?php echo htmlspecialchars(json_encode($s)); ?>)" title="Edit Section" aria-label="Edit Section">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" onclick="toggleSectionStatus(<?php echo $s['id']; ?>, '<?php echo addslashes($s['section_name'] ?? ''); ?>', '<?php echo $s['status'] ?? 'active'; ?>')" title="Toggle Active/Inactive" aria-label="Toggle Section Status">
                                        <i class="bi <?php echo ($s['status'] ?? 'active') === 'active' ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted'; ?>"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" onclick="confirmSectionDelete(<?php echo $s['id']; ?>, '<?php echo addslashes($s['section_name'] ?? ''); ?>')" title="Delete Section" aria-label="Delete Section">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: ADD NEW SECTION
     ========================================================================= -->
<div class="modal fade" id="addSectionModal" tabindex="-1" aria-labelledby="addSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addSectionModalLabel"><i class="bi bi-calendar-plus me-2 text-white"></i>Add Section</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/section_actions" method="POST" class="needs-validation" novalidate id="addSectionForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="add_program_select" class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                            <select name="program" id="add_program_select" class="form-select" required>
                                <option value="BSMT" <?php echo $selectedProgram === 'BSMT' ? 'selected' : ''; ?>>BSMT - Marine Transportation</option>
                                <option value="BSMarE" <?php echo $selectedProgram === 'BSMarE' ? 'selected' : ''; ?>>BSMarE - Marine Engineering</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="add_year_level" class="form-label fw-semibold">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="add_year_level" class="form-select" required>
                                <option value="1st Year" selected>1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="add_section_name" class="form-label fw-semibold">Section Name <span class="text-danger">*</span></label>
                            <input type="text" name="section_name" id="add_section_name" class="form-control fw-bold font-monospace text-navy" placeholder="e.g. BSMT 1-A" required autocomplete="off">
                            <div class="form-text small">Suggested name auto-generated</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="add_capacity" class="form-label fw-semibold">Capacity (Cadets) <span class="text-danger">*</span></label>
                            <input type="number" name="capacity" id="add_capacity" class="form-control" value="40" min="1" max="150" required>
                        </div>
                        <div class="col-md-4">
                            <label for="add_status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" id="add_status" class="form-select" required>
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary px-4 fw-semibold"><i class="bi bi-save me-1"></i> Create Section</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT SECTION
     ========================================================================= -->
<div class="modal fade" id="editSectionModal" tabindex="-1" aria-labelledby="editSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 shadow-lg border-0 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="editSectionModalLabel"><i class="bi bi-pencil-square me-2 text-white"></i>Edit Section</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/section_actions" method="POST" class="needs-validation" novalidate id="editSectionForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="section_id" id="edit_section_id">
                <input type="hidden" name="program" id="edit_program">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="edit_section_name" class="form-label fw-semibold">Section Name <span class="text-danger">*</span></label>
                            <input type="text" name="section_name" id="edit_section_name" class="form-control fw-bold font-monospace text-navy" required autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_year_level" class="form-label fw-semibold">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="edit_year_level" class="form-select" required>
                                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                                    <option value="<?php echo $yl; ?>"><?php echo $yl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>



                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_capacity" class="form-label fw-semibold">Capacity (Cadets) <span class="text-danger">*</span></label>
                            <input type="number" name="capacity" id="edit_capacity" class="form-control" min="1" max="150" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary px-4 fw-semibold"><i class="bi bi-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden action forms -->
<form id="deleteSectionForm" action="../actions/section_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="section_id" id="delete_section_id">
</form>

<form id="toggleSectionForm" action="../actions/section_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="section_id" id="toggle_section_id">
</form>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(m => document.body.appendChild(m));
    document.body.appendChild(document.getElementById('deleteSectionForm'));
    document.body.appendChild(document.getElementById('toggleSectionForm'));

    updateSectionPreview('add');
});

function onProgramChange(prefix) {
    const progSelect = document.getElementById(prefix + '_program_select');
    const progHidden = document.getElementById(prefix + '_program');
    if (progSelect && progHidden) {
        progHidden.value = progSelect.value;
    }
    updateSectionPreview(prefix);
}

function updateSectionPreview(prefix) {
    const progSelect = document.getElementById(prefix + '_program_select');
    const yearSelect = document.getElementById(prefix + '_year_level');
    const typeSelect = document.getElementById(prefix + '_section_type');
    const numInput = document.getElementById(prefix + '_section_number');
    const nameInput = document.getElementById(prefix + '_section_name');

    if (!progSelect || !yearSelect || !typeSelect || !numInput || !nameInput) return;

    const prog = progSelect.value || 'BSMT';
    const yMap = { '1st Year': '1', '2nd Year': '2', '3rd Year': '3', '4th Year': '4' };
    const yNum = yMap[yearSelect.value] || '1';
    const type = typeSelect.value || 'M';
    const num = numInput.value || '1';

    nameInput.value = `${prog} ${yNum}-${type}${num}`;
}

function openEditSection(section) {
    document.getElementById('edit_section_id').value = section.id;
    document.getElementById('edit_section_name').value = section.section_name || '';
    document.getElementById('edit_program').value = section.program || 'BSMT';
    document.getElementById('edit_year_level').value = section.year_level || '1st Year';
    document.getElementById('edit_capacity').value = section.capacity || 40;
    document.getElementById('edit_status').value = section.status || 'active';

    const modal = new bootstrap.Modal(document.getElementById('editSectionModal'));
    modal.show();
}

function toggleSectionStatus(sectionId, sectionName, currentStatus) {
    const newStatus = currentStatus === 'active' ? 'Inactive' : 'Active';
    Swal.fire({
        title: 'Change Section Status?',
        text: 'Change status of ' + sectionName + ' to ' + newStatus + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: 'var(--brand-primary)',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, change to ' + newStatus,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('toggle_section_id').value = sectionId;
            document.getElementById('toggleSectionForm').submit();
        }
    });
}

function confirmSectionDelete(sectionId, sectionName) {
    Swal.fire({
        title: 'Delete Section?',
        text: 'Are you sure you want to delete ' + sectionName + '? This action cannot be undone if no students are registered.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('delete_section_id').value = sectionId;
            document.getElementById('deleteSectionForm').submit();
        }
    });
}
function stripHtml(html) {
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

let sectionsTable = null;
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Tabulator === 'undefined') return;

    sectionsTable = new Tabulator("#sectionsTable", {
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
        placeholder: "<div class='text-center py-5 text-muted'><div class='mb-3 fs-1 text-muted-light'><i class='bi bi-calendar-x'></i></div><h6 class='fw-bold text-dark'>No sections found</h6><p class='small text-muted mb-0'>No sections match your active term or filter criteria.</p></div>",
        columns: [
            { title: "Section", field: "section", widthGrow: 2.5, minWidth: 180, formatter: "html" },
            { title: "Assigned Subjects", field: "subjects", widthGrow: 2.8, minWidth: 150, formatter: "html" },
            { title: "Cadet Capacity", field: "capacity", widthGrow: 1.8, minWidth: 140, formatter: "html" },
            { title: "Status", field: "status", widthGrow: 1.0, minWidth: 90, formatter: "html" },
            { title: "Actions", field: "actions", widthGrow: 1.4, minWidth: 130, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    var sInput = document.getElementById('searchInput');
    if (sInput) {
        sInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                sectionsTable.clearFilter();
            } else {
                sectionsTable.setFilter(function(data) {
                    return stripHtml(data.section).toLowerCase().includes(term) ||
                           stripHtml(data.subjects).toLowerCase().includes(term) ||
                           stripHtml(data.capacity).toLowerCase().includes(term) ||
                           stripHtml(data.status).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
