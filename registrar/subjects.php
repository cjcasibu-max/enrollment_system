<?php
/**
 * Subject Management Portal
 * Allows Registrars to manage academic subjects organized by program, year level, and semester.
 * Features checkbox-based prerequisite management, circular dependency protection, and downstream dependency tracking.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

require_once '../config/database.php';

$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : 'BSMT';
if (!in_array($selectedProgram, ['BSMT', 'BSMarE'], true)) {
    $selectedProgram = 'BSMT';
}

$selectedYear = isset($_GET['year_level']) ? trim($_GET['year_level']) : '';
$selectedSemester = isset($_GET['semester']) ? trim($_GET['semester']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $stmtPrograms = $pdo->query("SELECT id, program_code, program_name FROM programs ORDER BY program_code ASC");
    $programs = $stmtPrograms->fetchAll();
} catch (\PDOException $e) {
    $programs = [];
}

if (!function_exists('getCurriculumTypeBadge')) {
    function getCurriculumTypeBadge($type) {
        $t = trim($type ?? '');
        switch ($t) {
            case 'Navigation':
                return '<span class="badge shadow-2xs" style="background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-compass me-1"></i>Navigation</span>';
            case 'Seamanship':
                return '<span class="badge shadow-2xs" style="background:#ecfeff; color:#0e7490; border:1px solid #67e8f9; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-water me-1"></i>Seamanship</span>';
            case 'Marine Engineering':
                return '<span class="badge shadow-2xs" style="background:#f0fdfa; color:#0f766e; border:1px solid #5eead4; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-gear-wide-connected me-1"></i>Marine Eng.</span>';
            case 'Electrical':
                return '<span class="badge shadow-2xs" style="background:#faf5ff; color:#7e22ce; border:1px solid #d8b4fe; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-lightning-charge me-1"></i>Electrical</span>';
            case 'Safety':
                return '<span class="badge shadow-2xs" style="background:#f0fdf4; color:#15803d; border:1px solid #86efac; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-shield-check me-1"></i>Safety</span>';
            case 'Management':
                return '<span class="badge shadow-2xs" style="background:#fff1f2; color:#be123c; border:1px solid #fda4af; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-people me-1"></i>Management</span>';
            case 'General Education':
                return '<span class="badge shadow-2xs" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-book me-1"></i>Gen. Ed.</span>';
            case 'Physical Education':
                return '<span class="badge shadow-2xs" style="background:#fdf2f8; color:#be185d; border:1px solid #f472b6; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-activity me-1"></i>PE / PATHFIT</span>';
            case 'NSTP':
                return '<span class="badge shadow-2xs" style="background:#fefce8; color:#a16207; border:1px solid #fde047; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-flag me-1"></i>NSTP</span>';
            case 'Shipboard Training':
            case 'Practical Training':
                return '<span class="badge shadow-2xs" style="background:#0f172a; color:#facc15; border:1px solid #334155; font-size:0.68rem; font-weight:700; padding:0.2rem 0.45rem;"><i class="bi bi-ship me-1"></i>Shipboard OBT</span>';
            default:
                return '<span class="badge shadow-2xs" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;">' . htmlspecialchars($t ?: 'Academic') . '</span>';
        }
    }
}

// Fetch subjects for current filter (accurately loading active curriculum and standalone subjects)
try {
    $where = ["p.program_code = :program"];
    $params = ['program' => $selectedProgram];

    if ($selectedYear !== '') {
        $where[] = "COALESCE(cs.year_level, s.year_level) = :year_level";
        $params['year_level'] = $selectedYear;
    }

    if ($selectedSemester !== '') {
        $where[] = "COALESCE(cs.semester, s.semester_name) = :semester";
        $params['semester'] = $selectedSemester;
    }

    if ($search !== '') {
        $where[] = "(s.subject_code LIKE :search1 OR s.subject_name LIKE :search2 OR s.description LIKE :search3)";
        $params['search1'] = '%' . $search . '%';
        $params['search2'] = '%' . $search . '%';
        $params['search3'] = '%' . $search . '%';
    }

    $sql = "
        SELECT s.id as s_id, s.id, s.subject_code, s.subject_name,
               COALESCE(cs.units, s.units) as units,
               COALESCE(cs.subject_type, s.subject_type, 'General Education') as subject_type,
               s.description, s.status,
               COALESCE(cs.year_level, s.year_level) as year_level,
               COALESCE(cs.semester, s.semester_name) as semester_name,
               p.id as program_id, p.program_code, p.program_name,
               c.curriculum_name, c.effective_year, cs.id as cs_id
        FROM subjects s
        JOIN programs p ON p.id = s.program_id
        LEFT JOIN (
            curriculum_subjects cs
            JOIN curriculums c ON c.id = cs.curriculum_id AND c.is_active = 1
        ) ON cs.subject_id = s.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY 
            CASE COALESCE(cs.year_level, s.year_level)
                WHEN '1st Year' THEN 1
                WHEN '2nd Year' THEN 2
                WHEN '3rd Year' THEN 3
                WHEN '4th Year' THEN 4
                ELSE 5
            END,
            CASE COALESCE(cs.semester, s.semester_name)
                WHEN '1st Semester' THEN 1
                WHEN '2nd Semester' THEN 2
                WHEN 'Summer' THEN 3
                ELSE 4
            END,
            COALESCE(cs.display_order, 999) ASC,
            s.subject_code ASC
    ";

    $stmtSubjects = $pdo->prepare($sql);
    $stmtSubjects->execute($params);
    $subjects = $stmtSubjects->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch subjects failed: " . $e->getMessage());
    $subjects = [];
}

// Fetch all prerequisite relationships
try {
    $stmtPrereqs = $pdo->query("
        SELECT sp.subject_id, sp.prerequisite_subject_id, 
               s.subject_code, s.subject_name, s.year_level, s.semester_name, s.units
        FROM subject_prerequisites sp
        JOIN subjects s ON s.id = sp.prerequisite_subject_id
    ");
    $prerequisiteRows = $stmtPrereqs->fetchAll();
    $prerequisiteMap = [];
    foreach ($prerequisiteRows as $row) {
        $prerequisiteMap[(int)$row['subject_id']][] = [
            'id' => (int)$row['prerequisite_subject_id'],
            'code' => $row['subject_code'],
            'name' => $row['subject_name'],
            'year_level' => $row['year_level'],
            'semester_name' => $row['semester_name'],
            'units' => $row['units']
        ];
    }
} catch (\PDOException $e) {
    error_log("Fetch prerequisites failed: " . $e->getMessage());
    $prerequisiteMap = [];
}

// Fetch reverse prerequisite relationships (Subjects that require this subject)
try {
    $stmtDependents = $pdo->query("
        SELECT sp.prerequisite_subject_id, sp.subject_id, 
               s.subject_code, s.subject_name, s.year_level, s.semester_name, s.units
        FROM subject_prerequisites sp
        JOIN subjects s ON s.id = sp.subject_id
    ");
    $dependentRows = $stmtDependents->fetchAll();
    $dependentMap = [];
    foreach ($dependentRows as $row) {
        $dependentMap[(int)$row['prerequisite_subject_id']][] = [
            'id' => (int)$row['subject_id'],
            'code' => $row['subject_code'],
            'name' => $row['subject_name'],
            'year_level' => $row['year_level'],
            'semester_name' => $row['semester_name'],
            'units' => $row['units']
        ];
    }
} catch (\PDOException $e) {
    error_log("Fetch dependent subjects failed: " . $e->getMessage());
    $dependentMap = [];
}

// Fetch all subjects for the prerequisite selector (grouped by program)
try {
    $stmtAllSubjects = $pdo->query("
        SELECT s.id, s.subject_code, s.subject_name, s.year_level, s.semester_name, s.units, s.program_id, p.program_code
        FROM subjects s
        LEFT JOIN programs p ON s.program_id = p.id
        WHERE s.status = 'active'
        ORDER BY p.program_code ASC, s.year_level ASC, s.semester_name ASC, s.subject_code ASC
    ");
    $allSubjects = $stmtAllSubjects->fetchAll();
} catch (\PDOException $e) {
    error_log("Fetch all subjects for prerequisite select failed: " . $e->getMessage());
    $allSubjects = [];
}

// Fetch curriculum usages for each subject
try {
    $stmtCurrUsage = $pdo->query("
        SELECT cs.subject_id, c.curriculum_name, c.effective_year, p.program_code, cs.year_level, cs.semester, cs.units, cs.is_required
        FROM curriculum_subjects cs
        JOIN curriculums c ON c.id = cs.curriculum_id
        JOIN programs p ON p.id = c.program_id
        ORDER BY p.program_code ASC, cs.year_level ASC, cs.semester ASC
    ");
    $curriculumUsageMap = [];
    foreach ($stmtCurrUsage->fetchAll() as $row) {
        $curriculumUsageMap[(int)$row['subject_id']][] = [
            'curriculum_name' => $row['curriculum_name'],
            'effective_year' => $row['effective_year'],
            'program_code' => $row['program_code'],
            'year_level' => $row['year_level'],
            'semester' => $row['semester'],
            'units' => $row['units'],
            'is_required' => $row['is_required']
        ];
    }
} catch (\PDOException $e) {
    error_log("Fetch curriculum usage failed: " . $e->getMessage());
    $curriculumUsageMap = [];
}

$page_title = "Manage Subjects";
require_once '../includes/header.php';
?>

<style>
#subjectsTable td .btn-group-sm > .btn {
    min-height: 40px;
    min-width: 40px;
    padding: 0.35rem 0.65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
}
</style>

<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-book-half me-1"></i> Curriculum & Prerequisites</div>
        <h3 class="m-0 text-navy-alt fw-bold">Subject Management</h3>
        <p class="text-muted small m-0">Manage maritime academic subjects, units, curriculum mappings, and prerequisites for BSMT and BSMarE.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="curriculum?program=<?php echo urlencode($selectedProgram); ?>" class="btn btn-outline-primary d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2 fw-semibold">
            <i class="bi bi-journal-album"></i> View Curriculum Roadmap
        </a>
        <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-2 shadow-sm px-4 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
            <i class="bi bi-plus-lg"></i> Add New Subject
        </button>
    </div>
</div>

<!-- =========================================================================
     UNIFIED MARITIME CONTROL & FILTER BAR
     ========================================================================= -->
<div class="maritime-control-bar mb-4">
    <!-- Top Row: Program Selector Tabs -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 border-bottom border-light-subtle">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-uppercase text-muted me-2"><i class="bi bi-mortarboard me-1"></i> Program:</span>
            <a href="subjects?program=BSMT<?php echo $selectedYear ? '&year_level=' . urlencode($selectedYear) : ''; ?><?php echo $selectedSemester ? '&semester=' . urlencode($selectedSemester) : ''; ?>" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMT' ? 'active' : ''; ?>">
                <i class="bi bi-compass"></i> BSMT
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMT' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Transportation</span>
            </a>
            <a href="subjects?program=BSMarE<?php echo $selectedYear ? '&year_level=' . urlencode($selectedYear) : ''; ?><?php echo $selectedSemester ? '&semester=' . urlencode($selectedSemester) : ''; ?>" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMarE' ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i> BSMarE
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMarE' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Engineering</span>
            </a>
        </div>

        <div class="text-muted small">
            Active Program: <strong class="text-navy"><?php echo $selectedProgram === 'BSMT' ? 'Bachelor of Science in Marine Transportation' : 'Bachelor of Science in Marine Engineering'; ?></strong>
        </div>
    </div>

    <!-- Bottom Row: Year Filters, Semester Pills, and Search Input -->
    <form method="GET" action="subjects" class="row g-3 align-items-center pt-3">
        <input type="hidden" name="program" value="<?php echo htmlspecialchars($selectedProgram); ?>">
        
        <!-- Year Level Filter Pills -->
        <div class="col-12 col-lg-5">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Year Level</label>
            <div class="d-flex flex-wrap gap-1">
                <a href="subjects?program=<?php echo urlencode($selectedProgram); ?>&semester=<?php echo urlencode($selectedSemester); ?>&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $selectedYear === '' ? 'active' : ''; ?>">
                    All Years
                </a>
                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                    <a href="subjects?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($yl); ?>&semester=<?php echo urlencode($selectedSemester); ?>&search=<?php echo urlencode($search); ?>" 
                       class="filter-pill <?php echo $selectedYear === $yl ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($yl); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Semester Filter Pills -->
        <div class="col-12 col-md-5 col-lg-3">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Semester</label>
            <div class="d-flex flex-wrap gap-1">
                <a href="subjects?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($selectedYear); ?>&search=<?php echo urlencode($search); ?>" 
                   class="filter-pill <?php echo $selectedSemester === '' ? 'active' : ''; ?>">
                    All
                </a>
                <?php foreach (['1st Semester', '2nd Semester', 'Summer'] as $sem): ?>
                    <a href="subjects?program=<?php echo urlencode($selectedProgram); ?>&year_level=<?php echo urlencode($selectedYear); ?>&semester=<?php echo urlencode($sem); ?>&search=<?php echo urlencode($search); ?>" 
                       class="filter-pill <?php echo $selectedSemester === $sem ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($sem); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Search Input -->
        <div class="col-12 col-md-7 col-lg-4">
            <label class="form-label small fw-bold text-muted mb-1.5 d-block">Search Subject</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Code, name, keywords..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button type="submit" class="btn btn-brand-primary px-3"><i class="bi bi-funnel"></i></button>
                <?php if ($search !== '' || $selectedYear !== '' || $selectedSemester !== ''): ?>
                    <a href="subjects?program=<?php echo urlencode($selectedProgram); ?>" class="btn btn-outline-secondary px-3" title="Reset Filters"><i class="bi bi-arrow-clockwise"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- =========================================================================
     SUBJECTS TABLE
     ========================================================================= -->
<div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden; border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
        <h5 class="m-0 fw-bold text-white d-flex align-items-center gap-2" style="font-size: 1rem;">
            <i class="bi bi-book"></i> <?php echo htmlspecialchars($selectedProgram); ?> Curriculum Subjects
            <?php if ($selectedYear): ?> <span style="opacity: 0.85; font-weight: 500; font-size: 0.85rem;">&bull; <?php echo htmlspecialchars($selectedYear); ?></span><?php endif; ?>
            <?php if ($selectedSemester): ?> <span style="opacity: 0.85; font-weight: 500; font-size: 0.85rem;">&bull; <?php echo htmlspecialchars($selectedSemester); ?></span><?php endif; ?>
        </h5>
        <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 14px; font-size: 0.75rem; font-weight: 700;">
            <?php echo count($subjects); ?> Subject<?php echo count($subjects) !== 1 ? 's' : ''; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <!-- Mobile Scroll Affordance Hint -->
        <div class="d-md-none px-3 py-2 bg-light border-bottom text-muted small d-flex align-items-center gap-2">
            <i class="bi bi-arrows-expand-vertical text-brand-primary"></i>
            <span>Scroll horizontally to review subject details and prerequisites</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-maritime align-middle m-0" id="subjectsTable" style="width: 100%;">
                <thead>
                    <tr>
                        <th class="ps-4" tabulator-field="code">Code</th>
                        <th tabulator-field="name">Subject Name</th>
                        <th tabulator-field="year">Year Level</th>
                        <th tabulator-field="semester">Semester</th>
                        <th tabulator-field="units">Units</th>
                        <th tabulator-field="prereqs">Prerequisites</th>
                        <th tabulator-field="status">Status</th>
                        <th class="pe-4 text-end" tabulator-field="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($subjects)): ?>
                        <?php foreach ($subjects as $s): 
                            $prereqs = $prerequisiteMap[(int)$s['id']] ?? [];
                            $dependents = $dependentMap[(int)$s['id']] ?? [];
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-navy mb-1">
                                        <span class="badge font-monospace fw-bold" style="background: #f1f5f9; color: #0f172a; border: 1.5px solid #cbd5e1; font-size: 0.82rem; padding: 0.25rem 0.55rem; letter-spacing: 0.4px;">
                                            <?php echo htmlspecialchars($s['subject_code']); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <?php echo getCurriculumTypeBadge($s['subject_type']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 0.88rem;"><?php echo htmlspecialchars($s['subject_name']); ?></div>
                                    <?php if (!empty($s['description'])): ?>
                                        <div class="text-muted small mt-0.5" style="font-size: 0.75rem; line-height: 1.35;" title="<?php echo htmlspecialchars($s['description']); ?>">
                                            <?php echo htmlspecialchars($s['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border px-2.5 py-1 fw-semibold" style="font-size: 0.75rem;"><?php echo htmlspecialchars($s['year_level']); ?></span></td>
                                <td><span class="text-muted small fw-semibold" style="font-size: 0.75rem;"><?php echo htmlspecialchars($s['semester_name']); ?></span></td>
                                <td>
                                    <span class="badge font-monospace fw-bold shadow-2xs" 
                                          style="background: #ecfdf5; color: #047857; border: 1.5px solid #a7f3d0; font-size: 0.78rem; padding: 0.3rem 0.5rem;">
                                        <?php echo number_format($s['units'], 1); ?> u
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($prereqs)): ?>
                                        <div class="d-flex flex-wrap gap-1.5">
                                            <?php foreach ($prereqs as $p): ?>
                                                <span class="badge font-monospace shadow-2xs d-inline-flex align-items-center" 
                                                      style="background-color: #ffedd5; color: #9a3412; border: 1.5px solid #fb923c; font-size: 0.72rem; font-weight: 700; padding: 0.25rem 0.5rem; border-radius: 6px;"
                                                      title="<?php echo htmlspecialchars($p['name']); ?>">
                                                    <i class="bi bi-link-45deg me-0.5 text-danger" style="font-size: 0.75rem;"></i><?php echo htmlspecialchars($p['code']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.7rem; font-weight: 500;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary px-2 py-1 text-uppercase fw-bold" style="font-size: 0.65rem;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php 
                                        $currUsages = $curriculumUsageMap[(int)$s['id']] ?? []; 
                                        ?>
                                        <button type="button" class="btn btn-outline-primary" onclick="viewSubject(<?php echo htmlspecialchars(json_encode($s)); ?>, <?php echo htmlspecialchars(json_encode($prereqs)); ?>, <?php echo htmlspecialchars(json_encode($dependents)); ?>, <?php echo htmlspecialchars(json_encode($currUsages)); ?>)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="openEditSubject(<?php echo htmlspecialchars(json_encode($s)); ?>, <?php echo htmlspecialchars(json_encode(array_column($prereqs, 'id'))); ?>)" title="Edit Subject">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-warning" onclick="toggleSubjectStatus(<?php echo $s['id']; ?>, '<?php echo addslashes($s['subject_code']); ?>', '<?php echo $s['status']; ?>')" title="Toggle Active/Inactive">
                                            <i class="bi <?php echo $s['status'] === 'active' ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted'; ?>"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="confirmSubjectDelete(<?php echo $s['id']; ?>, '<?php echo addslashes($s['subject_code']); ?>')" title="Delete Subject">
                                            <i class="bi bi-trash"></i>
                                        </button>
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
     MODAL: ADD NEW SUBJECT
     ========================================================================= -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-labelledby="addSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="addSubjectModalLabel">
                    <i class="bi bi-journal-plus me-2 text-white"></i>Add New Subject
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/subject_actions" method="POST" class="needs-validation" novalidate id="addSubjectForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="add_subject_code" class="form-label fw-semibold">Subject Code <span class="text-danger">*</span></label>
                            <input type="text" name="subject_code" id="add_subject_code" class="form-control text-uppercase font-monospace text-navy fw-bold" placeholder="e.g. NAV 201" required autocomplete="off">
                            <div class="invalid-feedback">Subject code is required.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="add_subject_name" class="form-label fw-semibold">Subject Name <span class="text-danger">*</span></label>
                            <input type="text" name="subject_name" id="add_subject_name" class="form-control" placeholder="e.g. Terrestrial Navigation" required autocomplete="off">
                            <div class="invalid-feedback">Subject name is required.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="add_program_id" class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                            <select name="program_id" id="add_program_id" class="form-select" required onchange="filterPrereqCheckboxes('add')">
                                <option value="" disabled>Select...</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-code="<?php echo htmlspecialchars($p['program_code']); ?>" <?php echo $selectedProgram === $p['program_code'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['program_code'] . ' - ' . $p['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a program.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="add_year_level" class="form-label fw-semibold">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="add_year_level" class="form-select" required onchange="filterPrereqCheckboxes('add')">
                                <option value="" disabled>Select...</option>
                                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                                    <option value="<?php echo $yl; ?>" <?php echo ($selectedYear === $yl || (!$selectedYear && $yl === '1st Year')) ? 'selected' : ''; ?>><?php echo $yl; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a year level.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="add_semester_name" class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                            <select name="semester_name" id="add_semester_name" class="form-select" required onchange="filterPrereqCheckboxes('add')">
                                <option value="" disabled>Select...</option>
                                <?php foreach (['1st Semester', '2nd Semester'] as $sem): ?>
                                    <option value="<?php echo $sem; ?>" <?php echo ($selectedSemester === $sem || (!$selectedSemester && $sem === '1st Semester')) ? 'selected' : ''; ?>><?php echo $sem; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a semester.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="add_units" class="form-label fw-semibold">Units <span class="text-danger">*</span></label>
                            <input type="number" name="units" id="add_units" class="form-control" min="0.5" max="30" step="0.5" value="3.0" required>
                            <div class="invalid-feedback">Units must be between 0.5 and 30.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="add_status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" id="add_status" class="form-select" required>
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="add_description" class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="add_description" class="form-control" rows="2" placeholder="Brief subject description, topics covered, or laboratory requirements..."></textarea>
                    </div>

                    <!-- Relational Checkbox-based Prerequisite Selector -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-semibold m-0">Prerequisites</label>
                            <span class="badge bg-brand-primary text-white" id="add_selected_prereq_badge">Selected: 0</span>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="add_prereq_search" class="form-control border-start-0 ps-0" placeholder="Search available subjects..." oninput="filterPrereqCheckboxes('add')">
                        </div>
                        <div class="border rounded-3 p-2 bg-light" style="max-height: 220px; overflow-y: auto;" id="add_prereq_container">
                            <?php foreach ($allSubjects as $sub): ?>
                                <div class="form-check prereq-item py-1 px-3 mb-1 bg-white rounded border border-light" 
                                     data-id="<?php echo $sub['id']; ?>"
                                     data-program="<?php echo htmlspecialchars($sub['program_code']); ?>"
                                     data-program-id="<?php echo $sub['program_id']; ?>"
                                     data-year="<?php echo htmlspecialchars($sub['year_level']); ?>"
                                     data-semester="<?php echo htmlspecialchars($sub['semester_name']); ?>"
                                     data-code="<?php echo htmlspecialchars(strtoupper($sub['subject_code'])); ?>"
                                     data-name="<?php echo htmlspecialchars(strtoupper($sub['subject_name'])); ?>">
                                    <input class="form-check-input" type="checkbox" name="prerequisite_subject_ids[]" value="<?php echo $sub['id']; ?>" id="add_prereq_<?php echo $sub['id']; ?>" onchange="updatePrereqCount('add')">
                                    <label class="form-check-label d-flex justify-content-between align-items-center cursor-pointer w-100" for="add_prereq_<?php echo $sub['id']; ?>">
                                        <div>
                                            <strong class="text-navy font-monospace"><?php echo htmlspecialchars($sub['subject_code']); ?></strong> - <?php echo htmlspecialchars($sub['subject_name']); ?>
                                        </div>
                                        <span class="badge bg-secondary-subtle text-dark ms-2" style="font-size: 0.65rem;">
                                            <?php echo htmlspecialchars($sub['year_level'] . ' • ' . $sub['semester_name'] . ' • ' . $sub['units'] . 'u'); ?>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center text-muted small py-3 d-none" id="add_prereq_empty">
                                No eligible prerequisite subjects found for this program and term.
                            </div>
                        </div>
                        <div class="form-text small text-muted">Prerequisites must belong to the same program and occur in earlier or concurrent terms. Select multiple as needed.</div>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary px-4 fw-semibold"><i class="bi bi-save me-1"></i> Create Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT SUBJECT
     ========================================================================= -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-labelledby="editSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="editSubjectModalLabel">
                    <i class="bi bi-pencil-square me-2 text-white"></i>Edit Subject
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="../actions/subject_actions" method="POST" class="needs-validation" novalidate id="editSubjectForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="subject_id" id="edit_subject_id">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="edit_subject_code" class="form-label fw-semibold">Subject Code <span class="text-danger">*</span></label>
                            <input type="text" name="subject_code" id="edit_subject_code" class="form-control text-uppercase font-monospace text-navy fw-bold" required autocomplete="off">
                            <div class="invalid-feedback">Subject code is required.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_subject_name" class="form-label fw-semibold">Subject Name <span class="text-danger">*</span></label>
                            <input type="text" name="subject_name" id="edit_subject_name" class="form-control" required autocomplete="off">
                            <div class="invalid-feedback">Subject name is required.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="edit_program_id" class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                            <select name="program_id" id="edit_program_id" class="form-select" required onchange="filterPrereqCheckboxes('edit')">
                                <option value="" disabled>Select...</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-code="<?php echo htmlspecialchars($p['program_code']); ?>">
                                        <?php echo htmlspecialchars($p['program_code'] . ' - ' . $p['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a program.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_year_level" class="form-label fw-semibold">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="edit_year_level" class="form-select" required onchange="filterPrereqCheckboxes('edit')">
                                <option value="" disabled>Select...</option>
                                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yl): ?>
                                    <option value="<?php echo $yl; ?>"><?php echo $yl; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a year level.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_semester_name" class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                            <select name="semester_name" id="edit_semester_name" class="form-select" required onchange="filterPrereqCheckboxes('edit')">
                                <option value="" disabled>Select...</option>
                                <?php foreach (['1st Semester', '2nd Semester'] as $sem): ?>
                                    <option value="<?php echo $sem; ?>"><?php echo $sem; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a semester.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="edit_units" class="form-label fw-semibold">Units <span class="text-danger">*</span></label>
                            <input type="number" name="units" id="edit_units" class="form-control" min="0.5" max="30" step="0.5" required>
                            <div class="invalid-feedback">Units must be between 0.5 and 30.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                    </div>

                    <!-- Relational Checkbox-based Prerequisite Selector -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-semibold m-0">Prerequisites</label>
                            <span class="badge bg-brand-primary text-white" id="edit_selected_prereq_badge">Selected: 0</span>
                        </div>
                        <div class="input-group mb-2">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="edit_prereq_search" class="form-control border-start-0 ps-0" placeholder="Search available subjects..." oninput="filterPrereqCheckboxes('edit')">
                        </div>
                        <div class="border rounded-3 p-2 bg-light" style="max-height: 220px; overflow-y: auto;" id="edit_prereq_container">
                            <?php foreach ($allSubjects as $sub): ?>
                                <div class="form-check prereq-item py-1 px-3 mb-1 bg-white rounded border border-light" 
                                     data-id="<?php echo $sub['id']; ?>"
                                     data-program="<?php echo htmlspecialchars($sub['program_code']); ?>"
                                     data-program-id="<?php echo $sub['program_id']; ?>"
                                     data-year="<?php echo htmlspecialchars($sub['year_level']); ?>"
                                     data-semester="<?php echo htmlspecialchars($sub['semester_name']); ?>"
                                     data-code="<?php echo htmlspecialchars(strtoupper($sub['subject_code'])); ?>"
                                     data-name="<?php echo htmlspecialchars(strtoupper($sub['subject_name'])); ?>">
                                    <input class="form-check-input" type="checkbox" name="prerequisite_subject_ids[]" value="<?php echo $sub['id']; ?>" id="edit_prereq_<?php echo $sub['id']; ?>" onchange="updatePrereqCount('edit')">
                                    <label class="form-check-label d-flex justify-content-between align-items-center cursor-pointer w-100" for="edit_prereq_<?php echo $sub['id']; ?>">
                                        <div>
                                            <strong class="text-navy font-monospace"><?php echo htmlspecialchars($sub['subject_code']); ?></strong> - <?php echo htmlspecialchars($sub['subject_name']); ?>
                                        </div>
                                        <span class="badge bg-secondary-subtle text-dark ms-2" style="font-size: 0.65rem;">
                                            <?php echo htmlspecialchars($sub['year_level'] . ' • ' . $sub['semester_name'] . ' • ' . $sub['units'] . 'u'); ?>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center text-muted small py-3 d-none" id="edit_prereq_empty">
                                No eligible prerequisite subjects found for this program and term.
                            </div>
                        </div>
                        <div class="form-text small text-muted">Prerequisites must belong to the same program and occur in earlier or concurrent terms. Select multiple as needed.</div>
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

<!-- =========================================================================
     MODAL: VIEW SUBJECT DETAILS (Includes Downstream Dependencies)
     ========================================================================= -->
<div class="modal fade" id="viewSubjectModal" tabindex="-1" aria-labelledby="viewSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg border-0 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                <h5 class="modal-title fw-bold text-white" id="viewSubjectModalLabel">
                    <i class="bi bi-journal-text me-2 text-white"></i>Subject Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="viewSubjectModalBody">
                <!-- Dynamically populated -->
            </div>
            <div class="modal-footer p-3 px-4 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden action forms -->
<form id="deleteSubjectForm" action="../actions/subject_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="subject_id" id="delete_subject_id">
</form>

<form id="toggleSubjectForm" action="../actions/subject_actions" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="subject_id" id="toggle_subject_id">
</form>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(m => document.body.appendChild(m));
    document.body.appendChild(document.getElementById('deleteSubjectForm'));
    document.body.appendChild(document.getElementById('toggleSubjectForm'));

    filterPrereqCheckboxes('add');
});

function getTermRankValue(yearLevel, semesterName) {
    const yMap = { '1st Year': 1, '2nd Year': 2, '3rd Year': 3, '4th Year': 4 };
    const sMap = { '1st Semester': 1, '2nd Semester': 2, 'Summer': 3 };
    const y = yMap[yearLevel] || 1;
    const s = sMap[semesterName] || 1;
    return (y * 10) + s;
}

function filterPrereqCheckboxes(prefix) {
    const programSelect = document.getElementById(prefix + '_program_id');
    const yearSelect = document.getElementById(prefix + '_year_level');
    const semSelect = document.getElementById(prefix + '_semester_name');
    const searchInput = document.getElementById(prefix + '_prereq_search');
    const container = document.getElementById(prefix + '_prereq_container');
    const emptyMsg = document.getElementById(prefix + '_prereq_empty');
    const currentSubjectId = prefix === 'edit' ? parseInt(document.getElementById('edit_subject_id').value || 0) : 0;

    if (!programSelect || !container) return;

    const selectedProgId = programSelect.value;
    const selectedYear = yearSelect ? yearSelect.value : '';
    const selectedSem = semSelect ? semSelect.value : '';
    const maxRank = (selectedYear && selectedSem) ? getTermRankValue(selectedYear, selectedSem) : 999;
    const term = (searchInput ? searchInput.value : '').toLowerCase().trim();

    let visibleCount = 0;
    const items = container.querySelectorAll('.prereq-item');

    items.forEach(function(item) {
        const itemProgId = item.getAttribute('data-program-id');
        const itemYear = item.getAttribute('data-year');
        const itemSem = item.getAttribute('data-semester');
        const itemCode = (item.getAttribute('data-code') || '').toLowerCase();
        const itemName = (item.getAttribute('data-name') || '').toLowerCase();
        const itemId = parseInt(item.getAttribute('data-id'));
        const itemRank = getTermRankValue(itemYear, itemSem);

        // Self-prerequisite exclusion
        if (currentSubjectId > 0 && itemId === currentSubjectId) {
            item.style.display = 'none';
            const cb = item.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = false;
            return;
        }

        // Program mismatch filter
        if (selectedProgId && itemProgId !== selectedProgId) {
            item.style.display = 'none';
            return;
        }

        // Chronological order filter (prerequisites must be from earlier or concurrent terms)
        if (itemRank > maxRank) {
            item.style.display = 'none';
            return;
        }

        // Search text filter
        if (term !== '' && !itemCode.includes(term) && !itemName.includes(term)) {
            item.style.display = 'none';
            return;
        }

        item.style.display = 'flex';
        visibleCount++;
    });

    if (emptyMsg) {
        emptyMsg.classList.toggle('d-none', visibleCount > 0);
    }

    updatePrereqCount(prefix);
}

function updatePrereqCount(prefix) {
    const container = document.getElementById(prefix + '_prereq_container');
    const badge = document.getElementById(prefix + '_selected_prereq_badge');
    if (!container || !badge) return;
    const count = container.querySelectorAll('input[type="checkbox"]:checked').length;
    badge.textContent = `Selected: ${count}`;
}

function openEditSubject(subject, selectedPrereqIds) {
    document.getElementById('edit_subject_id').value = subject.id;
    document.getElementById('edit_subject_code').value = subject.subject_code;
    document.getElementById('edit_subject_name').value = subject.subject_name;
    document.getElementById('edit_program_id').value = subject.program_id;
    document.getElementById('edit_year_level').value = subject.year_level;
    document.getElementById('edit_semester_name').value = subject.semester_name;
    document.getElementById('edit_units').value = subject.units;
    document.getElementById('edit_status').value = subject.status;
    document.getElementById('edit_description').value = subject.description || '';

    // Check prerequisite checkboxes
    const container = document.getElementById('edit_prereq_container');
    if (container) {
        const checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            cb.checked = selectedPrereqIds.includes(parseInt(cb.value));
        });
    }

    filterPrereqCheckboxes('edit');

    const modal = new bootstrap.Modal(document.getElementById('editSubjectModal'));
    modal.show();
}

function viewSubject(subject, prerequisites, dependents, curriculums) {
    const programName = subject.program_code + ' - ' + subject.program_name;
    let html = '<div class="text-center mb-4">';
    html += '<h2 class="fw-bold text-navy-alt mb-1 font-monospace">' + escapeHtml(subject.subject_code) + '</h2>';
    html += '<h5 class="text-dark fw-bold">' + escapeHtml(subject.subject_name) + '</h5>';
    html += '<div class="mt-2 d-flex justify-content-center align-items-center gap-2 flex-wrap">';
    html += '<span class="badge bg-navy-subtle text-navy border px-3 py-1.5 fs-6 font-monospace fw-bold">' + escapeHtml(subject.units) + ' Academic Units</span> ';
    html += '<span class="badge ' + (subject.status === 'active' ? 'bg-success-subtle text-success border border-success' : 'bg-secondary-subtle text-secondary border border-secondary') + ' px-2.5 py-1.5 text-uppercase fw-bold">' + escapeHtml(subject.status) + '</span>';
    if (subject.subject_type) {
        html += '<span class="badge bg-light text-dark border px-2.5 py-1.5 fw-semibold">' + escapeHtml(subject.subject_type) + '</span>';
    }
    html += '</div></div>';

    html += '<div class="row g-3 mb-4">';
    html += '<div class="col-md-6"><div class="p-3 bg-light rounded-3 border"><small class="text-muted d-block mb-1 text-uppercase fw-bold" style="font-size:0.7rem;">Primary Program</small><strong>' + escapeHtml(programName) + '</strong></div></div>';
    html += '<div class="col-md-3"><div class="p-3 bg-light rounded-3 border"><small class="text-muted d-block mb-1 text-uppercase fw-bold" style="font-size:0.7rem;">Year Level</small><strong>' + escapeHtml(subject.year_level) + '</strong></div></div>';
    html += '<div class="col-md-3"><div class="p-3 bg-light rounded-3 border"><small class="text-muted d-block mb-1 text-uppercase fw-bold" style="font-size:0.7rem;">Semester</small><strong>' + escapeHtml(subject.semester_name) + '</strong></div></div>';
    html += '</div>';

    // Curriculum Mapping Section
    html += '<div class="mb-4">';
    html += '<h6 class="fw-bold text-navy mb-2"><i class="bi bi-journal-album me-1 text-brand-primary"></i> Curriculum Edition & Placement</h6>';
    if (curriculums && curriculums.length > 0) {
        html += '<ul class="list-group shadow-2xs rounded-3 overflow-hidden">';
        curriculums.forEach(function(c) {
            html += '<li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">';
            html += '<div><i class="bi bi-mortarboard me-1.5 text-brand-primary"></i><strong class="text-navy">' + escapeHtml(c.curriculum_name) + '</strong> <span class="badge bg-light text-dark border ms-1 font-monospace" style="font-size:0.7rem;">AY ' + escapeHtml(c.effective_year) + '</span></div>';
            html += '<div class="d-flex align-items-center gap-1.5"><span class="badge bg-secondary-subtle text-dark border" style="font-size:0.72rem;">' + escapeHtml(c.year_level + ' • ' + c.semester) + '</span><span class="badge bg-success-subtle text-success border border-success-subtle font-monospace fw-bold" style="font-size:0.72rem;">' + escapeHtml(c.units) + ' Units</span></div>';
            html += '</li>';
        });
        html += '</ul>';
    } else {
        html += '<div class="alert alert-light border text-muted small m-0"><i class="bi bi-info-circle me-1 text-primary"></i> Standalone subject (not currently assigned to an active curriculum).</div>';
    }
    html += '</div>';

    if (subject.description) {
        html += '<div class="mb-4 p-3 bg-light rounded-3 border"><h6 class="fw-bold text-navy mb-1">Subject Description</h6><p class="text-muted m-0 small">' + escapeHtml(subject.description) + '</p></div>';
    }

    // Prerequisites section
    html += '<div class="mb-4">';
    html += '<h6 class="fw-bold text-navy mb-2"><i class="bi bi-arrow-left-circle me-1 text-danger"></i> Prerequisites Required for this Subject</h6>';
    if (prerequisites && prerequisites.length > 0) {
        html += '<ul class="list-group shadow-2xs rounded-3 overflow-hidden">';
        prerequisites.forEach(function(p) {
            html += '<li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">';
            html += '<div class="d-flex align-items-center gap-2"><span class="badge font-monospace" style="background-color:#ffedd5; color:#9a3412; border:1.5px solid #fb923c; font-size:0.8rem; font-weight:700; padding:0.3rem 0.55rem;"><i class="bi bi-link-45deg me-0.5 text-danger"></i>' + escapeHtml(p.code) + '</span><span class="fw-semibold text-dark">' + escapeHtml(p.name) + '</span></div>';
            html += '<span class="badge bg-secondary-subtle text-dark border font-monospace" style="font-size:0.72rem;">' + escapeHtml(p.year_level + ' • ' + p.semester_name + ' • ' + p.units + ' Units') + '</span>';
            html += '</li>';
        });
        html += '</ul>';
    } else {
        html += '<div class="alert alert-light border text-muted small m-0"><i class="bi bi-info-circle me-1 text-primary"></i> No prerequisites required for this subject.</div>';
    }
    html += '</div>';

    // Downstream Dependent Subjects section
    html += '<div>';
    html += '<h6 class="fw-bold text-navy mb-2"><i class="bi bi-arrow-right-circle me-1 text-primary"></i> Subjects that Require this Subject as a Prerequisite</h6>';
    if (dependents && dependents.length > 0) {
        html += '<ul class="list-group shadow-2xs rounded-3 overflow-hidden">';
        dependents.forEach(function(d) {
            html += '<li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">';
            html += '<div class="d-flex align-items-center gap-2"><span class="badge font-monospace" style="background-color:#e0f2fe; color:#0369a1; border:1.5px solid #7dd3fc; font-size:0.8rem; font-weight:700; padding:0.3rem 0.55rem;"><i class="bi bi-arrow-right-short me-0.5 text-primary"></i>' + escapeHtml(d.code) + '</span><span class="fw-semibold text-dark">' + escapeHtml(d.name) + '</span></div>';
            html += '<span class="badge bg-secondary-subtle text-dark border font-monospace" style="font-size:0.72rem;">' + escapeHtml(d.year_level + ' • ' + d.semester_name + ' • ' + d.units + ' Units') + '</span>';
            html += '</li>';
        });
        html += '</ul>';
    } else {
        html += '<div class="alert alert-light border text-muted small m-0"><i class="bi bi-info-circle me-1 text-primary"></i> No higher-level subjects currently require this subject.</div>';
    }
    html += '</div>';

    document.getElementById('viewSubjectModalBody').innerHTML = html;
    const modal = new bootstrap.Modal(document.getElementById('viewSubjectModal'));
    modal.show();
}

function toggleSubjectStatus(subjectId, subjectCode, currentStatus) {
    const newStatus = currentStatus === 'active' ? 'Inactive' : 'Active';
    Swal.fire({
        title: 'Change Subject Status?',
        text: 'Change status of ' + subjectCode + ' to ' + newStatus + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: 'var(--brand-primary)',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, change to ' + newStatus,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('toggle_subject_id').value = subjectId;
            document.getElementById('toggleSubjectForm').submit();
        }
    });
}

function confirmSubjectDelete(subjectId, subjectCode) {
    Swal.fire({
        title: 'Delete Subject?',
        text: 'Are you sure you want to delete ' + subjectCode + '? This will be checked for dependent prerequisites and enrollment records.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('delete_subject_id').value = subjectId;
            document.getElementById('deleteSubjectForm').submit();
        }
    });
}

function stripHtml(html) {
    if (!html) return '';
    var tmp = document.createElement('DIV');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

let subjectsTable = null;
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Tabulator === 'undefined') return;

    subjectsTable = new Tabulator("#subjectsTable", {
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
        placeholder: "<div class='text-center py-5 text-muted'><div class='mb-3 fs-1 text-muted-light'><i class='bi bi-journal-x'></i></div><h6 class='fw-bold text-dark'>No subjects found</h6><p class='small text-muted mb-3'>No subjects match the selected program, year level, or search filter.</p></div>",
        columns: [
            { title: "Code", field: "code", widthGrow: 1.1, minWidth: 100, formatter: "html" },
            { title: "Subject Name", field: "name", widthGrow: 3.5, minWidth: 180, formatter: "html" },
            { title: "Year Level", field: "year", widthGrow: 1.1, minWidth: 90, formatter: "html" },
            { title: "Semester", field: "semester", widthGrow: 1.1, minWidth: 90, formatter: "html" },
            { title: "Units", field: "units", widthGrow: 0.8, minWidth: 70, formatter: "html" },
            { title: "Prerequisites", field: "prereqs", widthGrow: 2.2, minWidth: 130, formatter: "html" },
            { title: "Status", field: "status", widthGrow: 0.9, minWidth: 80, formatter: "html" },
            { title: "Actions", field: "actions", widthGrow: 1.4, minWidth: 130, hozAlign: "center", headerSort: false, formatter: "html", responsive: 0 }
        ]
    });

    var sInput = document.getElementById('searchInput');
    if (sInput) {
        sInput.addEventListener('input', function() {
            var term = this.value.trim().toLowerCase();
            if (!term) {
                subjectsTable.clearFilter();
            } else {
                subjectsTable.setFilter(function(data) {
                    return stripHtml(data.code).toLowerCase().includes(term) ||
                           stripHtml(data.name).toLowerCase().includes(term) ||
                           stripHtml(data.year).toLowerCase().includes(term) ||
                           stripHtml(data.semester).toLowerCase().includes(term) ||
                           stripHtml(data.units).toLowerCase().includes(term) ||
                           stripHtml(data.prereqs).toLowerCase().includes(term) ||
                           stripHtml(data.status).toLowerCase().includes(term);
                });
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
