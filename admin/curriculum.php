<?php
/**
 * Administrator Curriculum Management Portal
 * Comprehensive academic curriculum administration for BSMT, BSMarE, and maritime programs.
 * Provides full CRUD operations for curricula, subject mappings, prerequisite chains, and versioning.
 * Strictly adheres to NCST Maritime Academy Design System & Theme.
 */

require_once '../includes/auth_check.php';
checkRole(['admin']);

require_once '../config/database.php';
require_once '../includes/academic_terms.php';

$activeTerm = getActiveAcademicTerm($pdo);

// Helper for consistent maritime theme category badges
if (!function_exists('getCurriculumTypeBadge')) {
    function getCurriculumTypeBadge($type) {
        $t = trim($type ?? '');
        switch ($t) {
            case 'Navigation':
            case 'Seamanship':
            case 'Marine Engineering':
                return '<span class="badge" style="background:#eaf4f5; color:#064b55; border:1px solid #b2d8d8; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-compass me-1 text-brand-primary"></i>' . htmlspecialchars($t) . '</span>';
            case 'Electrical':
                return '<span class="badge" style="background:#f0fdfa; color:#0b6570; border:1px solid #99f6e4; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-lightning-charge me-1"></i>Electrical</span>';
            case 'Safety':
                return '<span class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-shield-check me-1"></i>Safety</span>';
            case 'Management':
                return '<span class="badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-people me-1"></i>Management</span>';
            case 'General Education':
                return '<span class="badge" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-book me-1"></i>Gen. Ed.</span>';
            case 'Physical Education':
                return '<span class="badge" style="background:#fdf2f8; color:#be185d; border:1px solid #fbcfe8; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-activity me-1"></i>PE / PATHFIT</span>';
            case 'NSTP':
                return '<span class="badge" style="background:#fefce8; color:#854d0e; border:1px solid #fde047; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;"><i class="bi bi-flag me-1"></i>NSTP</span>';
            case 'Shipboard Training':
            case 'Practical Training':
                return '<span class="badge" style="background:#064b55; color:#facc15; border:1px solid #0b6570; font-size:0.68rem; font-weight:700; padding:0.2rem 0.45rem;"><i class="bi bi-ship me-1"></i>Shipboard OBT</span>';
            default:
                return '<span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.68rem; font-weight:600; padding:0.2rem 0.45rem;">' . htmlspecialchars($t ?: 'Academic') . '</span>';
        }
    }
}

// Fetch all academic programs
try {
    $programs = $pdo->query("SELECT id, program_code, program_name FROM programs ORDER BY program_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $programs = [];
}

$programCodes = array_column($programs, 'program_code');
$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : (!empty($programCodes) ? $programCodes[0] : 'BSMT');
if (!in_array($selectedProgram, $programCodes, true) && !empty($programCodes)) {
    $selectedProgram = $programCodes[0];
}

$currentProgramInfo = null;
foreach ($programs as $p) {
    if ($p['program_code'] === $selectedProgram) {
        $currentProgramInfo = $p;
        break;
    }
}

// Fetch all curriculums for the selected program
try {
    $currStmt = $pdo->prepare("
        SELECT c.*, p.program_code, p.program_name
        FROM curriculums c
        JOIN programs p ON p.id = c.program_id
        WHERE p.program_code = :prog
        ORDER BY c.is_active DESC, c.effective_year DESC, c.id DESC
    ");
    $currStmt->execute(['prog' => $selectedProgram]);
    $curriculums = $currStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $curriculums = [];
}

// Select active curriculum or specifically requested curriculum_id
$curriculumId = isset($_GET['curriculum_id']) ? (int)$_GET['curriculum_id'] : 0;
$activeCurriculum = null;
if (!empty($curriculums)) {
    if ($curriculumId > 0) {
        foreach ($curriculums as $c) {
            if ((int)$c['id'] === $curriculumId) {
                $activeCurriculum = $c;
                break;
            }
        }
    }
    if (!$activeCurriculum) {
        $activeCurriculum = $curriculums[0];
    }
}

// Sorting and filtering state for curriculum progression
$yearSort = isset($_GET['year_sort']) && strtolower($_GET['year_sort']) === 'desc' ? 'desc' : 'asc';
$yearFilter = isset($_GET['year_filter']) && in_array($_GET['year_filter'], ['1st Year', '2nd Year', '3rd Year', '4th Year'], true) ? $_GET['year_filter'] : 'all';

// Fetch all subjects in master directory for dropdowns and mappings
try {
    $masterSubjects = $pdo->query("
        SELECT id, subject_code, subject_name, units, subject_type, description
        FROM subjects
        WHERE status = 'active'
        ORDER BY subject_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $masterSubjects = [];
}

// Fetch all prerequisites mapping
$allPrereqs = [];
try {
    $prereqStmt = $pdo->query("
        SELECT sp.subject_id, sp.prerequisite_subject_id, p.subject_code AS prereq_code, p.subject_name AS prereq_name
        FROM subject_prerequisites sp
        JOIN subjects p ON p.id = sp.prerequisite_subject_id
    ");
    foreach ($prereqStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $allPrereqs[(int)$pr['subject_id']][] = [
            'id' => (int)$pr['prerequisite_subject_id'],
            'code' => $pr['prereq_code'],
            'name' => $pr['prereq_name']
        ];
    }
} catch (Exception $e) {
    $allPrereqs = [];
}

// Fetch curriculum subjects and compute summary stats
$curriculumSubjects = [];
$stats = [
    'total_subjects' => 0,
    'total_units' => 0.0,
    'gen_ed_units' => 0.0,
    'professional_units' => 0.0,
    'prereq_rules' => 0,
    'by_year' => []
];

if ($activeCurriculum) {
    try {
        $csStmt = $pdo->prepare("
            SELECT cs.id AS curriculum_subject_id, cs.curriculum_id, cs.subject_id, cs.year_level, cs.semester,
                   cs.units, cs.is_required, cs.display_order, cs.subject_type,
                   s.subject_code, s.subject_name, s.description
            FROM curriculum_subjects cs
            JOIN subjects s ON s.id = cs.subject_id
            WHERE cs.curriculum_id = :curr_id
            ORDER BY 
                CASE cs.year_level 
                    WHEN '1st Year' THEN 1 
                    WHEN '2nd Year' THEN 2 
                    WHEN '3rd Year' THEN 3 
                    WHEN '4th Year' THEN 4 
                    ELSE 5 
                END,
                CASE cs.semester 
                    WHEN '1st Semester' THEN 1 
                    WHEN '2nd Semester' THEN 2 
                    WHEN 'Summer' THEN 3 
                    ELSE 4 
                END,
                cs.display_order ASC,
                s.subject_code ASC
        ");
        $csStmt->execute(['curr_id' => $activeCurriculum['id']]);
        $rows = $csStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $y = $row['year_level'];
            $sem = $row['semester'];
            $prList = $allPrereqs[(int)$row['subject_id']] ?? [];
            $row['prerequisites'] = $prList;
            $curriculumSubjects[$y][$sem][] = $row;
            
            $u = (float)$row['units'];
            $stats['total_subjects']++;
            $stats['total_units'] += $u;
            $stats['prereq_rules'] += count($prList);

            if (in_array($row['subject_type'], ['General Education', 'NSTP', 'Physical Education'], true)) {
                $stats['gen_ed_units'] += $u;
            } else {
                $stats['professional_units'] += $u;
            }

            if (!isset($stats['by_year'][$y])) {
                $stats['by_year'][$y] = ['subjects' => 0, 'units' => 0.0];
            }
            $stats['by_year'][$y]['subjects']++;
            $stats['by_year'][$y]['units'] += $u;
        }
    } catch (Exception $e) {
        error_log("Admin fetch curriculum subjects failed: " . $e->getMessage());
    }
}

// Fetch all curriculums across programs for cloning source dropdown
try {
    $allCurriculumsList = $pdo->query("
        SELECT c.id, c.curriculum_name, c.effective_year, p.program_code,
               (SELECT COUNT(*) FROM curriculum_subjects cs WHERE cs.curriculum_id = c.id) AS subject_count
        FROM curriculums c
        JOIN programs p ON p.id = c.program_id
        ORDER BY p.program_code ASC, c.effective_year DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allCurriculumsList = [];
}

$page_title = "Curriculum Administration";
$page_class = 'page-curriculum-admin';
require_once '../includes/header.php';
?>

<!-- =========================================================================
     PAGE HEADING & MASTER ACTION CONTROLS
     ========================================================================= -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-shield-lock me-1"></i> Administration Desk • Maritime Academics</div>
        <h3 class="m-0 text-navy-alt fw-bold">Curriculum Administration</h3>
        <p class="text-muted small m-0">CHED & MARINA compliant curriculum architectures, course matrices, and prerequisite governance.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#newCurriculumModal">
            <i class="bi bi-plus-circle-fill"></i> New Curriculum
        </button>
        <?php if ($activeCurriculum): ?>
            <button type="button" class="btn btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#editCurriculumModal">
                <i class="bi bi-pencil-square"></i> Edit Details
            </button>
            <button type="button" class="btn btn-outline-primary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#cloneCurriculumModal" title="Duplicate this curriculum with all subjects and prerequisites">
                <i class="bi bi-copy"></i> Duplicate Edition
            </button>
            <button type="button" class="btn btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Matrix
            </button>
        <?php endif; ?>
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

<!-- =========================================================================
     MARITIME CONTROL BAR & PROGRAM SELECTOR
     ========================================================================= -->
<div class="maritime-control-bar mb-4 p-3 bg-white rounded-4 border border-light-subtle shadow-sm">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <!-- Program Selector Pills -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-uppercase text-muted me-1"><i class="bi bi-mortarboard me-1"></i> Program:</span>
            <?php foreach ($programs as $prog): ?>
                <a href="curriculum?program=<?php echo urlencode($prog['program_code']); ?>" 
                   class="program-pill-btn <?php echo $selectedProgram === $prog['program_code'] ? 'active' : ''; ?>">
                    <i class="bi <?php echo $prog['program_code'] === 'BSMT' ? 'bi-compass' : 'bi-gear-wide-connected'; ?>"></i> 
                    <?php echo htmlspecialchars($prog['program_code']); ?>
                    <span class="badge ms-1 <?php echo $selectedProgram === $prog['program_code'] ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">
                        <?php echo $prog['program_code'] === 'BSMT' ? 'Marine Transportation' : 'Marine Engineering'; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Curriculum Version Controls -->
        <?php if (!empty($curriculums)): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <label class="form-label m-0 fw-semibold text-muted small text-nowrap"><i class="bi bi-layers me-1 text-brand-primary"></i> Curriculum Edition:</label>
                <select class="form-select form-select-sm fw-semibold text-navy border-light-subtle shadow-none" style="min-width: 240px;" onchange="location.href='curriculum?program=<?php echo urlencode($selectedProgram); ?>&curriculum_id=' + this.value">
                    <?php foreach ($curriculums as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ((int)$c['id'] === (int)($activeCurriculum['id'] ?? 0)) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['curriculum_name']); ?> (AY <?php echo htmlspecialchars($c['effective_year']); ?>) <?php echo (int)$c['is_active'] === 1 ? '★ Active' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($activeCurriculum): ?>
                    <?php if ((int)$activeCurriculum['is_active'] === 1): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 fw-bold d-inline-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-1"></i> Active
                        </span>
                    <?php else: ?>
                        <form action="../actions/curriculum_actions" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                            <input type="hidden" name="action" value="activate_curriculum">
                            <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success fw-semibold">
                                <i class="bi bi-check-lg me-1"></i> Set Active
                            </button>
                        </form>
                    <?php endif; ?>

                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteCurriculumModal" title="Delete this curriculum edition">
                        <i class="bi bi-trash3"></i>
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeCurriculum): ?>
    <!-- =========================================================================
         ACTIVE CURRICULUM DESCRIPTION & QUICK STATS
         ========================================================================= -->
    <?php if (!empty($activeCurriculum['description'])): ?>
        <div class="alert alert-light border border-light-subtle rounded-3 p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 text-muted small">
                <i class="bi bi-info-circle-fill text-brand-primary fs-5"></i>
                <span><strong>Scope:</strong> <?php echo htmlspecialchars($activeCurriculum['description']); ?></span>
            </div>
            <div class="small text-muted font-monospace">
                Effective Academic Year: <span class="badge bg-secondary"><?php echo htmlspecialchars($activeCurriculum['effective_year']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- =========================================================================
         KPI METRICS ROW (4 Cards in Cohesive Maritime Theme)
         ========================================================================= -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card card-stat stat-primary h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.3px;">Total Subjects</div>
                        <div class="fs-3 fw-bold text-darker mt-1"><?php echo $stats['total_subjects']; ?></div>
                        <div class="small text-muted mt-0.5">Curriculum courses</div>
                    </div>
                    <div class="stat-icon bg-primary-soft">
                        <i class="bi bi-journal-bookmark"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card card-stat stat-success h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.3px;">Academic Units</div>
                        <div class="fs-3 fw-bold text-darker mt-1"><?php echo number_format($stats['total_units'], 1); ?></div>
                        <div class="small text-muted mt-0.5">Total credit load</div>
                    </div>
                    <div class="stat-icon bg-success-soft">
                        <i class="bi bi-award"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card card-stat stat-accent h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.3px;">Major / Marine Units</div>
                        <div class="fs-3 fw-bold text-darker mt-1"><?php echo number_format($stats['professional_units'], 1); ?></div>
                        <div class="small text-muted mt-0.5">Specialized courses</div>
                    </div>
                    <div class="stat-icon bg-accent-soft">
                        <i class="bi bi-shield-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card card-stat stat-secondary h-100 shadow-sm">
                <div class="card-body d-flex align-items-center justify-content-between p-3.5">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.3px;">Prerequisite Links</div>
                        <div class="fs-3 fw-bold text-darker mt-1"><?php echo $stats['prereq_rules']; ?></div>
                        <div class="small text-muted mt-0.5">Progression rules</div>
                    </div>
                    <div class="stat-icon bg-secondary-soft">
                        <i class="bi bi-diagram-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         CURRICULUM NAVIGATION, FILTERING & SORTING TOOLBAR
         ========================================================================= -->
    <style>
        #curriculumToolbar .year-filter-btn {
            padding: 0.38rem 0.85rem !important;
            font-size: 0.82rem !important;
            border-radius: 0.55rem !important;
            gap: 0.35rem !important;
        }
        #curriculumToolbar .form-select-sm {
            font-size: 0.82rem;
            padding-top: 0.35rem;
            padding-bottom: 0.35rem;
        }
        #curriculumToolbar #curriculumSearchCounter {
            margin-left: 0.75rem;
        }
    </style>
    <div class="card border border-light-subtle rounded-4 shadow-sm mb-4 bg-white overflow-hidden" id="curriculumToolbar">
        <div class="card-body p-3">
            <div class="d-flex flex-column flex-xxl-row justify-content-between align-items-xxl-center gap-3">
                <!-- Left: Year Level Filter Pills -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="small fw-bold text-uppercase text-muted me-1 text-nowrap">
                        <i class="bi bi-funnel me-1 text-brand-primary"></i> Year Level:
                    </span>
                    <button type="button" class="program-pill-btn year-filter-btn <?php echo $yearFilter === 'all' ? 'active' : ''; ?>" data-year="all">
                        All Years
                        <span class="badge ms-2 <?php echo $yearFilter === 'all' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.72rem; padding: 0.25em 0.55em;"><?php echo $stats['total_subjects']; ?></span>
                    </button>
                    <?php 
                    $yearNumberMap = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
                    foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yName): 
                        $yCount = $stats['by_year'][$yName]['subjects'] ?? 0;
                        $isActive = ($yearFilter === $yName);
                    ?>
                        <button type="button" class="program-pill-btn year-filter-btn <?php echo $isActive ? 'active' : ''; ?> <?php echo ($yName === '4th Year') ? 'fw-bold' : ''; ?>" data-year="<?php echo $yName; ?>">
                            <?php echo $yName; ?>
                            <span class="badge ms-2 <?php echo $isActive ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.72rem; padding: 0.25em 0.55em;">
                                <?php echo $yCount; ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Right: Sorting Controls (Year Level Order & Course Sort) -->
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <!-- Year Level Order (Asc vs Desc) -->
                    <div class="d-flex align-items-center gap-2">
                        <label for="yearSortSelect" class="form-label m-0 fw-semibold text-muted small text-nowrap">
                            <i class="bi bi-arrow-down-up me-1 text-brand-primary"></i> Year Order:
                        </label>
                        <select id="yearSortSelect" class="form-select form-select-sm fw-semibold text-navy border-light-subtle shadow-none" style="min-width: 175px;">
                            <option value="asc" <?php echo $yearSort === 'asc' ? 'selected' : ''; ?>>1st → 4th Year (Ascending)</option>
                            <option value="desc" <?php echo $yearSort === 'desc' ? 'selected' : ''; ?>>4th → 1st Year (Senior First)</option>
                        </select>
                    </div>

                    <!-- Course Sort inside tables -->
                    <div class="d-flex align-items-center gap-2">
                        <label for="courseSortSelect" class="form-label m-0 fw-semibold text-muted small text-nowrap">
                            <i class="bi bi-sort-alpha-down me-1 text-brand-primary"></i> Course Sort:
                        </label>
                        <select id="courseSortSelect" class="form-select form-select-sm fw-semibold text-navy border-light-subtle shadow-none" style="min-width: 170px;">
                            <option value="default">Default Matrix Order</option>
                            <option value="code_asc">Course Code (A → Z)</option>
                            <option value="code_desc">Course Code (Z → A)</option>
                            <option value="name_asc">Course Name (A → Z)</option>
                            <option value="units_desc">Units (High → Low)</option>
                            <option value="units_asc">Units (Low → High)</option>
                        </select>
                    </div>
                </div>
            </div>

            <hr class="my-2 text-light-subtle opacity-50">

            <!-- Sub-row: Search Bar and Quick Jump Links -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <!-- Search Input & Counter -->
                <div class="d-flex align-items-center gap-3 flex-grow-1" style="min-width: 280px; max-width: 640px;">
                    <div class="input-group input-group-sm flex-grow-1">
                        <span class="input-group-text bg-light border-light-subtle text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" id="curriculumSearchInput" class="form-control border-light-subtle shadow-none" placeholder="Filter courses (e.g. NAV, OBT, 4th)..." style="font-size: 0.85rem;">
                        <button class="btn btn-outline-secondary btn-sm" type="button" id="clearCurriculumSearch" style="display:none;" title="Clear search" aria-label="Clear search">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <span id="curriculumSearchCounter" class="badge bg-light text-navy border px-3 py-2 text-nowrap fw-semibold" style="font-size: 0.74rem;">
                        Showing all <?php echo $stats['total_subjects']; ?> courses
                    </span>
                </div>

                <!-- Quick Jump Buttons -->
                <div class="d-flex align-items-center gap-2 flex-wrap ms-md-auto">
                    <span class="small text-muted me-1 fw-semibold text-nowrap"><i class="bi bi-geo-alt me-1 text-brand-primary"></i>Jump:</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 fw-semibold jump-to-year-btn" data-target="year-card-1">1st Year</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 fw-semibold jump-to-year-btn" data-target="year-card-2">2nd Year</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 fw-semibold jump-to-year-btn" data-target="year-card-3">3rd Year</button>
                    <button type="button" class="btn btn-sm btn-brand-primary py-1 px-3 fw-bold jump-to-year-btn" data-target="year-card-4">
                        <i class="bi bi-arrow-down-short"></i> 4th Year
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         4-YEAR ACADEMIC PROGRESSION ROADMAP WITH DIRECT ADMIN CONTROLS
         ========================================================================= -->
    <div class="row g-4" id="curriculumRoadmapContainer">
        <?php 
        $allYearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
        $yearLevels = $allYearLevels;
        if ($yearSort === 'desc') {
            $yearLevels = array_reverse($yearLevels);
        }
        foreach ($yearLevels as $year): 
            $yearIndex = $yearNumberMap[$year] ?? 1;
            $semesters = $curriculumSubjects[$year] ?? [];
            $yearSubs = $stats['by_year'][$year]['subjects'] ?? 0;
            $yearUnits = $stats['by_year'][$year]['units'] ?? 0.0;
            $isYearHidden = ($yearFilter !== 'all' && $yearFilter !== $year);
        ?>
            <div class="col-12 year-level-card <?php echo $isYearHidden ? 'd-none' : ''; ?>" 
                 data-year-level="<?php echo htmlspecialchars($year); ?>" 
                 data-year-order="<?php echo $yearIndex; ?>"
                 id="year-card-<?php echo $yearIndex; ?>">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-2" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
                    <!-- Year Level Banner Header -->
                    <div class="py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold shadow-sm" 
                                 style="width: 36px; height: 36px; min-width: 36px; font-size: 1rem; background: #ffffff; color: var(--brand-dark, #064b55);">
                                <?php echo $yearIndex; ?>
                            </div>
                            <div>
                                <h5 class="fw-bold text-white m-0" style="font-size: 1.05rem;"><?php echo htmlspecialchars($year); ?></h5>
                                <div style="color: rgba(255,255,255,0.85); font-size: 0.78rem;">
                                    Academic Level <?php echo $yearIndex; ?> of 4 • <?php echo htmlspecialchars($selectedProgram); ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 12px; font-size: 0.75rem; font-weight: 700;">
                                <i class="bi bi-book me-1"></i><?php echo $yearSubs; ?> Subjects
                            </span>
                            <span class="badge" style="background: rgba(255,255,255,0.22); color: #ffffff !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 999px; padding: 5px 12px; font-size: 0.75rem; font-weight: 700;">
                                <i class="bi bi-award me-1"></i><?php echo number_format($yearUnits, 1); ?> Total Units
                            </span>
                        </div>
                    </div>

                    <!-- Year Level Body: Semesters Grid -->
                    <div class="card-body p-3 p-lg-4 bg-light-subtle">
                        <div class="row g-3 g-lg-4">
                            <?php 
                            $semOrder = ['1st Semester', '2nd Semester', 'Summer'];
                            foreach ($semOrder as $semName):
                                $subs = $semesters[$semName] ?? [];
                                $semUnits = !empty($subs) ? array_sum(array_column($subs, 'units')) : 0.0;
                                $isSummer = ($semName === 'Summer');
                                if (empty($subs) && $isSummer) {
                                    continue; // Skip empty summer terms to keep view tidy
                                }
                            ?>
                                <div class="col-12">
                                    <div class="card border border-light-subtle rounded-3 shadow-sm h-100 bg-white overflow-hidden">
                                        <!-- Semester Sub-Header -->
                                        <div class="card-header py-2.5 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2"
                                             style="background-color: <?php echo $isSummer ? '#fefce8' : 'var(--surface-tint)'; ?>;">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi <?php echo $isSummer ? 'bi-sun text-warning' : 'bi-calendar3 text-brand-primary'; ?>"></i>
                                                <span class="fw-bold small text-navy"><?php echo htmlspecialchars($semName); ?></span>
                                                <span class="badge bg-white text-dark border px-2 py-0.5" style="font-size: 0.7rem;"><?php echo count($subs); ?> Subjects</span>
                                                <span class="badge text-white px-2 py-0.5 font-monospace" style="font-size: 0.7rem; background-color: var(--brand-dark);">
                                                    <?php echo number_format($semUnits, 1); ?> Units
                                                </span>
                                            </div>
                                            <button type="button" class="btn btn-xs btn-brand-primary d-inline-flex align-items-center gap-1 py-1 px-2.5" 
                                                    style="font-size: 0.74rem;" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#addSubjectModal"
                                                    data-year="<?php echo htmlspecialchars($year); ?>"
                                                    data-sem="<?php echo htmlspecialchars($semName); ?>">
                                                <i class="bi bi-plus-lg"></i> Add Course
                                            </button>
                                        </div>

                                        <!-- Semester Subjects Table -->
                                        <div class="table-responsive">
                                            <table class="table table-hover table-maritime align-middle mb-0" style="font-size: 0.82rem; min-width: 680px;">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="ps-3" style="width: 20%;">Code & Category</th>
                                                        <th style="width: 40%;">Subject Name</th>
                                                        <th style="width: 8%;" class="text-center">Units</th>
                                                        <th style="width: 20%;">Prerequisites</th>
                                                        <th class="pe-3 text-end" style="width: 12%;">Controls</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="semester-subjects-tbody">
                                                    <?php if (empty($subs)): ?>
                                                        <tr class="empty-term-row">
                                                            <td colspan="5" class="text-center py-4 text-muted small">
                                                                <i class="bi bi-journal-x d-block fs-3 mb-1 text-muted-light"></i>
                                                                No subjects mapped for this term. Click "Add Course" above.
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php 
                                                        $subIndex = 0;
                                                        foreach ($subs as $s): 
                                                            $subIndex++;
                                                            $prList = $s['prerequisites'] ?? [];
                                                            $prCodes = implode(' ', array_column($prList, 'code'));
                                                            $prNames = implode(' ', array_column($prList, 'name'));
                                                            $searchBlob = strtolower($s['subject_code'] . ' ' . $s['subject_name'] . ' ' . ($s['subject_type'] ?? '') . ' ' . $prCodes . ' ' . $prNames . ' ' . $year . ' ' . $semName);
                                                        ?>
                                                            <tr class="course-row"
                                                                data-default-order="<?php echo $subIndex; ?>"
                                                                data-code="<?php echo htmlspecialchars(strtolower($s['subject_code'])); ?>"
                                                                data-name="<?php echo htmlspecialchars(strtolower($s['subject_name'])); ?>"
                                                                data-units="<?php echo (float)$s['units']; ?>"
                                                                data-type="<?php echo htmlspecialchars(strtolower($s['subject_type'] ?? '')); ?>"
                                                                data-search-text="<?php echo htmlspecialchars($searchBlob); ?>">
                                                                <td class="ps-3">
                                                                    <div class="d-flex align-items-center gap-1.5 mb-1">
                                                                        <span class="badge font-monospace fw-bold" 
                                                                              style="background: #f7f9fa; color: var(--text-navy); border: 1.5px solid var(--gray-300); font-size: 0.8rem; padding: 0.25rem 0.5rem; letter-spacing: 0.4px;">
                                                                            <?php echo htmlspecialchars($s['subject_code']); ?>
                                                                        </span>
                                                                    </div>
                                                                    <div>
                                                                        <?php echo getCurriculumTypeBadge($s['subject_type']); ?>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <div class="fw-bold text-dark" style="font-size: 0.85rem;" title="<?php echo htmlspecialchars($s['subject_name']); ?>">
                                                                        <?php echo htmlspecialchars($s['subject_name']); ?>
                                                                    </div>
                                                                    <?php if (!empty($s['description'])): ?>
                                                                        <div class="text-muted small text-truncate mt-0.5" style="max-width: 200px; font-size: 0.72rem;" title="<?php echo htmlspecialchars($s['description']); ?>">
                                                                            <?php echo htmlspecialchars($s['description']); ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <span class="badge font-monospace fw-bold shadow-2xs" 
                                                                          style="background: var(--surface-tint); color: var(--brand-dark); border: 1.5px solid var(--brand-primary-soft); font-size: 0.78rem; padding: 0.3rem 0.5rem;">
                                                                        <?php echo number_format($s['units'], 1); ?> u
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($s['prerequisites'])): ?>
                                                                        <div class="d-flex flex-wrap gap-1">
                                                                            <?php foreach ($s['prerequisites'] as $pr): ?>
                                                                                <span class="badge font-monospace shadow-2xs d-inline-flex align-items-center" 
                                                                                      style="background-color: #fef3c7; color: #b45309; border: 1.5px solid #f59e0b; font-size: 0.68rem; font-weight: 700; padding: 0.2rem 0.4rem; border-radius: 5px;"
                                                                                      title="<?php echo htmlspecialchars($pr['name']); ?>">
                                                                                    <?php echo htmlspecialchars($pr['code']); ?>
                                                                                </span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-light text-muted border px-2 py-0.5" style="font-size: 0.68rem;">None</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="pe-3 text-end">
                                                                    <div class="btn-group btn-group-sm">
                                                                        <!-- Edit Subject Mapping -->
                                                                        <button type="button" class="btn btn-outline-secondary btn-sm p-1 px-2" 
                                                                                title="Edit Subject Configuration"
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#editSubjectModal"
                                                                                data-cs-id="<?php echo (int)$s['curriculum_subject_id']; ?>"
                                                                                data-code="<?php echo htmlspecialchars($s['subject_code']); ?>"
                                                                                data-name="<?php echo htmlspecialchars($s['subject_name']); ?>"
                                                                                data-year="<?php echo htmlspecialchars($s['year_level']); ?>"
                                                                                data-sem="<?php echo htmlspecialchars($s['semester']); ?>"
                                                                                data-units="<?php echo htmlspecialchars($s['units']); ?>"
                                                                                data-type="<?php echo htmlspecialchars($s['subject_type']); ?>"
                                                                                data-order="<?php echo (int)$s['display_order']; ?>"
                                                                                data-required="<?php echo (int)$s['is_required']; ?>">
                                                                            <i class="bi bi-pencil"></i>
                                                                        </button>
                                                                        <!-- Manage Prerequisites -->
                                                                        <button type="button" class="btn btn-outline-warning btn-sm p-1 px-2 text-dark" 
                                                                                title="Manage Prerequisites"
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#managePrereqModal"
                                                                                data-subject-id="<?php echo (int)$s['subject_id']; ?>"
                                                                                data-code="<?php echo htmlspecialchars($s['subject_code']); ?>"
                                                                                data-name="<?php echo htmlspecialchars($s['subject_name']); ?>"
                                                                                data-prereq-ids="<?php echo htmlspecialchars(json_encode(array_column($s['prerequisites'], 'id'))); ?>">
                                                                            <i class="bi bi-diagram-3-fill text-warning"></i>
                                                                        </button>
                                                                        <!-- Remove Subject -->
                                                                        <button type="button" class="btn btn-outline-danger btn-sm p-1 px-2" 
                                                                                title="Unlink from Curriculum"
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#removeSubjectModal"
                                                                                data-cs-id="<?php echo (int)$s['curriculum_subject_id']; ?>"
                                                                                data-code="<?php echo htmlspecialchars($s['subject_code']); ?>"
                                                                                data-name="<?php echo htmlspecialchars($s['subject_name']); ?>">
                                                                            <i class="bi bi-x-lg"></i>
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
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <!-- Empty State when no curriculum is configured for this program -->
    <div class="card card-premium shadow-sm border-0 rounded-4 text-center py-5">
        <div class="card-body">
            <i class="bi bi-journal-plus fs-1 text-brand-primary mb-3 d-block"></i>
            <h5 class="fw-bold text-dark">No Curriculum Configured for <?php echo htmlspecialchars($selectedProgram); ?></h5>
            <p class="text-muted small mb-4">Initialize the official curriculum structure for this program to establish course roadmaps and automated enrollment matrices.</p>
            <button type="button" class="btn btn-brand-primary px-4 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#newCurriculumModal">
                <i class="bi bi-plus-circle me-1"></i> Create Initial Curriculum
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- =========================================================================
     MODAL: NEW CURRICULUM
     ========================================================================= -->
<div class="modal fade" id="newCurriculumModal" tabindex="-1" aria-labelledby="newCurriculumModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create_curriculum">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy" id="newCurriculumModalLabel">
                        <i class="bi bi-journal-plus me-1 text-brand-primary"></i> Create New Curriculum
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Academic Program <span class="text-danger">*</span></label>
                        <select name="program_id" class="form-select" required>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['id']; ?>" <?php echo $prog['program_code'] === $selectedProgram ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Curriculum Edition Name <span class="text-danger">*</span></label>
                        <input type="text" name="curriculum_name" class="form-control" placeholder="e.g. BSMT Curriculum 2026-2027" required>
                        <div class="form-text small">Descriptive title used across registrar and student portals.</div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Effective School Year <span class="text-danger">*</span></label>
                            <input type="text" name="effective_year" class="form-control font-monospace" placeholder="2026-2027" pattern="\d{4}-\d{4}" required>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActiveCheck" value="1" checked>
                                <label class="form-check-label small fw-semibold" for="isActiveCheck">Set as Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Clone Subjects from Existing Curriculum (Optional)</label>
                        <select name="clone_source_id" class="form-select">
                            <option value="0">-- Start with Blank Roadmap --</option>
                            <?php foreach ($allCurriculumsList as $cSrc): ?>
                                <option value="<?php echo $cSrc['id']; ?>">
                                    [<?php echo htmlspecialchars($cSrc['program_code']); ?>] <?php echo htmlspecialchars($cSrc['curriculum_name']); ?> (<?php echo $cSrc['subject_count']; ?> subjects)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Allows instantly copying all subjects, credit units, and sequencing.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold small text-muted">Description / Regulatory Memo</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="e.g. Standard CHED CMO 67 s. 2017 & STCW compliant curriculum."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Create Curriculum</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($activeCurriculum): ?>
<!-- =========================================================================
     MODAL: EDIT CURRICULUM DETAILS
     ========================================================================= -->
<div class="modal fade" id="editCurriculumModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="update_curriculum">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy">
                        <i class="bi bi-pencil-square me-1 text-brand-primary"></i> Edit Curriculum Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Program</label>
                        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($selectedProgram . ' - ' . ($currentProgramInfo['program_name'] ?? '')); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Curriculum Edition Name <span class="text-danger">*</span></label>
                        <input type="text" name="curriculum_name" class="form-control" value="<?php echo htmlspecialchars($activeCurriculum['curriculum_name']); ?>" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Effective School Year <span class="text-danger">*</span></label>
                            <input type="text" name="effective_year" class="form-control font-monospace" value="<?php echo htmlspecialchars($activeCurriculum['effective_year']); ?>" required>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="editIsActiveCheck" value="1" <?php echo (int)$activeCurriculum['is_active'] === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-semibold" for="editIsActiveCheck">Active Curriculum</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold small text-muted">Description / Regulatory Notes</label>
                        <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($activeCurriculum['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: DUPLICATE / CLONE CURRICULUM
     ========================================================================= -->
<div class="modal fade" id="cloneCurriculumModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="clone_curriculum">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy">
                        <i class="bi bi-copy me-1 text-brand-primary"></i> Duplicate Curriculum Edition
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">
                        This creates an independent copy of <strong><?php echo htmlspecialchars($activeCurriculum['curriculum_name']); ?></strong> along with all <strong><?php echo $stats['total_subjects']; ?> subjects</strong>, credit units, categories, and progression sequence.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">New Curriculum Name <span class="text-danger">*</span></label>
                        <input type="text" name="new_curriculum_name" class="form-control" value="<?php echo htmlspecialchars($activeCurriculum['curriculum_name']); ?> (Copy)" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Target School Year <span class="text-danger">*</span></label>
                            <input type="text" name="new_effective_year" class="form-control font-monospace" placeholder="e.g. 2027-2028" required>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="set_active" id="cloneSetActiveCheck" value="1">
                                <label class="form-check-label small fw-semibold" for="cloneSetActiveCheck">Set as Active Edition</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold small text-muted">Version Notes / Modifications</label>
                        <textarea name="new_description" class="form-control" rows="2" placeholder="e.g. Revised edition with updated navigation simulator modules."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-copy me-1"></i> Clone Curriculum</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: DELETE CURRICULUM
     ========================================================================= -->
<div class="modal fade" id="deleteCurriculumModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="delete_curriculum">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <div class="modal-header border-bottom py-3 bg-danger-subtle">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Curriculum Edition
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-dark">
                        Are you sure you want to permanently delete the curriculum <strong><?php echo htmlspecialchars($activeCurriculum['curriculum_name']); ?></strong> (AY <?php echo htmlspecialchars($activeCurriculum['effective_year']); ?>)?
                    </p>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        This action will unlink all <strong><?php echo $stats['total_subjects']; ?> courses</strong> mapped in this version. The underlying master courses in the directory will remain intact.
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-semibold"><i class="bi bi-trash3-fill me-1"></i> Confirm Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: ADD SUBJECT TO CURRICULUM
     ========================================================================= -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="add_curriculum_subject">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy">
                        <i class="bi bi-journal-plus me-1 text-brand-primary"></i> Add Course to Curriculum
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Term Assignment -->
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="addSubjectYear" class="form-select" required>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Semester <span class="text-danger">*</span></label>
                            <select name="semester" id="addSubjectSem" class="form-select" required>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                    </div>

                    <!-- Mode Toggle: Existing Catalog vs Create New -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted d-block">Course Selection Mode</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="subject_mode" id="modeExisting" value="existing" checked onchange="toggleSubjectMode('existing')">
                            <label class="btn btn-outline-primary" for="modeExisting"><i class="bi bi-list-ul me-1"></i> Select from Master Directory</label>

                            <input type="radio" class="btn-check" name="subject_mode" id="modeNew" value="new" onchange="toggleSubjectMode('new')">
                            <label class="btn btn-outline-primary" for="modeNew"><i class="bi bi-plus-square me-1"></i> Register New Subject</label>
                        </div>
                    </div>

                    <!-- Existing Subject Selector Section -->
                    <div id="sectionExistingSubject" class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Select Subject <span class="text-danger">*</span></label>
                        <select name="subject_id" id="subjectIdSelect" class="form-select" onchange="autoFillSubjectDetails(this)">
                            <option value="">-- Choose Subject --</option>
                            <?php foreach ($masterSubjects as $mSub): ?>
                                <option value="<?php echo $mSub['id']; ?>" 
                                        data-units="<?php echo $mSub['units']; ?>" 
                                        data-type="<?php echo htmlspecialchars($mSub['subject_type'] ?? 'General Education'); ?>"
                                        data-desc="<?php echo htmlspecialchars($mSub['description'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($mSub['subject_code'] . ' - ' . $mSub['subject_name'] . ' (' . number_format($mSub['units'], 1) . ' u)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- New Subject Form Section (Initially Hidden) -->
                    <div id="sectionNewSubject" class="mb-3 d-none">
                        <div class="row g-3">
                            <div class="col-4">
                                <label class="form-label fw-semibold small text-muted">Subject Code <span class="text-danger">*</span></label>
                                <input type="text" name="subject_code" class="form-control text-uppercase font-monospace" placeholder="e.g. NAV401">
                            </div>
                            <div class="col-8">
                                <label class="form-label fw-semibold small text-muted">Subject Name <span class="text-danger">*</span></label>
                                <input type="text" name="subject_name" class="form-control" placeholder="e.g. Advanced Marine Radar & Simulation">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small text-muted">Course Description</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Course syllabus overview..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Course Properties -->
                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <label class="form-label fw-semibold small text-muted">Credit Units <span class="text-danger">*</span></label>
                            <input type="number" step="0.5" min="0.5" max="30" name="units" id="subjectUnitsInput" class="form-control" value="3.0" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-semibold small text-muted">Category / Discipline</label>
                            <select name="subject_type" id="subjectTypeSelect" class="form-select">
                                <option value="Navigation">Navigation</option>
                                <option value="Seamanship">Seamanship</option>
                                <option value="Marine Engineering">Marine Engineering</option>
                                <option value="Electrical">Electrical</option>
                                <option value="Safety">Safety</option>
                                <option value="Management">Management</option>
                                <option value="General Education" selected>General Education</option>
                                <option value="Physical Education">Physical Education</option>
                                <option value="NSTP">NSTP</option>
                                <option value="Shipboard Training">Shipboard Training / OBT</option>
                                <option value="Practical Training">Practical Training</option>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small text-muted">Display Order</label>
                            <input type="number" name="display_order" class="form-control" min="1" placeholder="Auto">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="is_required" id="isReqCheck" value="1" checked>
                        <label class="form-check-label small fw-semibold" for="isReqCheck">Mandatory Graduation Requirement</label>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-plus-lg me-1"></i> Add Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: EDIT CURRICULUM SUBJECT MAPPING
     ========================================================================= -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="update_curriculum_subject">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <input type="hidden" name="curriculum_subject_id" id="editCsId" value="0">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy">
                        <i class="bi bi-pencil-square me-1 text-brand-primary"></i> Edit Subject Configuration
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Course Code & Name</label>
                        <input type="text" id="editCsCodeName" class="form-control bg-light fw-bold text-navy" readonly>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="editCsYear" class="form-select" required>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small text-muted">Semester <span class="text-danger">*</span></label>
                            <select name="semester" id="editCsSem" class="form-select" required>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <label class="form-label fw-semibold small text-muted">Units <span class="text-danger">*</span></label>
                            <input type="number" step="0.5" min="0.5" max="30" name="units" id="editCsUnits" class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-semibold small text-muted">Category</label>
                            <select name="subject_type" id="editCsType" class="form-select">
                                <option value="Navigation">Navigation</option>
                                <option value="Seamanship">Seamanship</option>
                                <option value="Marine Engineering">Marine Engineering</option>
                                <option value="Electrical">Electrical</option>
                                <option value="Safety">Safety</option>
                                <option value="Management">Management</option>
                                <option value="General Education">General Education</option>
                                <option value="Physical Education">Physical Education</option>
                                <option value="NSTP">NSTP</option>
                                <option value="Shipboard Training">Shipboard Training / OBT</option>
                                <option value="Practical Training">Practical Training</option>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small text-muted">Sort Order</label>
                            <input type="number" name="display_order" id="editCsOrder" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="is_required" id="editCsRequired" value="1">
                        <label class="form-check-label small fw-semibold" for="editCsRequired">Mandatory Requirement</label>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Update Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: MANAGE PREREQUISITES
     ========================================================================= -->
<div class="modal fade" id="managePrereqModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="manage_prerequisites">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <input type="hidden" name="subject_id" id="prereqSubjectId" value="0">
                <div class="modal-header border-bottom py-3" style="background: var(--surface-tint);">
                    <h5 class="modal-title fw-bold text-navy">
                        <i class="bi bi-diagram-3-fill me-1 text-warning"></i> Manage Prerequisite Rules
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Target Subject</label>
                        <input type="text" id="prereqTargetCodeName" class="form-control bg-light fw-bold text-navy" readonly>
                    </div>
                    <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-info-circle-fill fs-5"></i>
                        <span>Select courses that students <strong>must complete</strong> before enrolling in this subject. Circular dependencies are automatically prevented.</span>
                    </div>

                    <label class="form-label fw-semibold small text-muted mb-2">Check Required Prerequisite Courses:</label>
                    <div class="border rounded-3 p-3 bg-white" style="max-height: 280px; overflow-y: auto;">
                        <div class="row g-2" id="prereqOptionsContainer">
                            <?php foreach ($masterSubjects as $mSub): ?>
                                <div class="col-12 col-md-6 prereq-item-wrapper" data-subject-id="<?php echo (int)$mSub['id']; ?>">
                                    <div class="form-check p-2 rounded border border-light-subtle hover-bg-light">
                                        <input class="form-check-input prereq-checkbox" type="checkbox" name="prerequisite_ids[]" value="<?php echo (int)$mSub['id']; ?>" id="pr_<?php echo (int)$mSub['id']; ?>">
                                        <label class="form-check-label small fw-semibold d-block text-truncate" for="pr_<?php echo (int)$mSub['id']; ?>">
                                            <span class="font-monospace text-navy"><?php echo htmlspecialchars($mSub['subject_code']); ?></span> - <?php echo htmlspecialchars($mSub['subject_name']); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Save Prerequisites</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL: REMOVE SUBJECT FROM CURRICULUM
     ========================================================================= -->
<div class="modal fade" id="removeSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="../actions/curriculum_actions" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="remove_curriculum_subject">
                <input type="hidden" name="curriculum_id" value="<?php echo (int)$activeCurriculum['id']; ?>">
                <input type="hidden" name="curriculum_subject_id" id="removeCsId" value="0">
                <div class="modal-header border-bottom py-3 bg-danger-subtle">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-x-circle-fill me-1"></i> Remove Course from Curriculum
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-dark">
                        Are you sure you want to remove <strong id="removeCsName"></strong> from this curriculum edition?
                    </p>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        The subject will remain in the master subjects directory. Only its assignment in this specific curriculum roadmap will be removed.
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4 bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-semibold"><i class="bi bi-trash3-fill me-1"></i> Unlink Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle Add Subject mode
function toggleSubjectMode(mode) {
    var existSec = document.getElementById('sectionExistingSubject');
    var newSec = document.getElementById('sectionNewSubject');
    var subSelect = document.getElementById('subjectIdSelect');
    
    if (mode === 'new') {
        existSec.classList.add('d-none');
        newSec.classList.remove('d-none');
        subSelect.removeAttribute('required');
    } else {
        existSec.classList.remove('d-none');
        newSec.classList.add('d-none');
        subSelect.setAttribute('required', 'required');
    }
}

// Auto-fill units and type when selecting an existing subject
function autoFillSubjectDetails(selectElem) {
    var opt = selectElem.options[selectElem.selectedIndex];
    if (!opt || !opt.value) return;
    
    var units = opt.getAttribute('data-units');
    var type = opt.getAttribute('data-type');
    
    if (units) {
        document.getElementById('subjectUnitsInput').value = units;
    }
    if (type) {
        var typeSelect = document.getElementById('subjectTypeSelect');
        for (var i = 0; i < typeSelect.options.length; i++) {
            if (typeSelect.options[i].value === type) {
                typeSelect.selectedIndex = i;
                break;
            }
        }
    }
}

// Handle dynamic Add Subject modal pre-fills (from semester header)
var addSubjectModal = document.getElementById('addSubjectModal');
if (addSubjectModal) {
    addSubjectModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        if (!button) return;
        var yr = button.getAttribute('data-year');
        var sm = button.getAttribute('data-sem');
        if (yr) document.getElementById('addSubjectYear').value = yr;
        if (sm) document.getElementById('addSubjectSem').value = sm;
    });
}

// Handle dynamic Edit Subject modal pre-fills
var editSubjectModal = document.getElementById('editSubjectModal');
if (editSubjectModal) {
    editSubjectModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        if (!button) return;
        document.getElementById('editCsId').value = button.getAttribute('data-cs-id');
        document.getElementById('editCsCodeName').value = button.getAttribute('data-code') + ' - ' + button.getAttribute('data-name');
        document.getElementById('editCsYear').value = button.getAttribute('data-year');
        document.getElementById('editCsSem').value = button.getAttribute('data-sem');
        document.getElementById('editCsUnits').value = button.getAttribute('data-units');
        document.getElementById('editCsOrder').value = button.getAttribute('data-order');
        document.getElementById('editCsType').value = button.getAttribute('data-type');
        document.getElementById('editCsRequired').checked = (button.getAttribute('data-required') === '1');
    });
}

// Handle dynamic Manage Prerequisites modal pre-fills
var managePrereqModal = document.getElementById('managePrereqModal');
if (managePrereqModal) {
    managePrereqModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        if (!button) return;
        var sId = parseInt(button.getAttribute('data-subject-id'), 10);
        document.getElementById('prereqSubjectId').value = sId;
        document.getElementById('prereqTargetCodeName').value = button.getAttribute('data-code') + ' - ' + button.getAttribute('data-name');
        
        var prereqIds = [];
        try {
            prereqIds = JSON.parse(button.getAttribute('data-prereq-ids') || '[]');
        } catch(e) { prereqIds = []; }

        // Uncheck all, hide self, and check existing
        var items = document.querySelectorAll('.prereq-item-wrapper');
        items.forEach(function(item) {
            var itemSubId = parseInt(item.getAttribute('data-subject-id'), 10);
            var cb = item.querySelector('.prereq-checkbox');
            if (itemSubId === sId) {
                item.classList.add('d-none'); // Cannot require itself
                cb.checked = false;
            } else {
                item.classList.remove('d-none');
                cb.checked = prereqIds.includes(itemSubId);
            }
        });
    });
}

// Handle dynamic Remove Subject modal pre-fills
var removeSubjectModal = document.getElementById('removeSubjectModal');
if (removeSubjectModal) {
    removeSubjectModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        if (!button) return;
        document.getElementById('removeCsId').value = button.getAttribute('data-cs-id');
        document.getElementById('removeCsName').textContent = button.getAttribute('data-code') + ' - ' + button.getAttribute('data-name');
    });
}

// =========================================================================
// CURRICULUM SORTING, FILTERING & QUICK NAVIGATION CONTROLS
// =========================================================================
(function() {
    var container = document.getElementById('curriculumRoadmapContainer');
    if (!container) return;

    var yearCards = Array.from(container.querySelectorAll('.year-level-card'));
    var filterBtns = document.querySelectorAll('.year-filter-btn');
    var yearSortSelect = document.getElementById('yearSortSelect');
    var courseSortSelect = document.getElementById('courseSortSelect');
    var searchInput = document.getElementById('curriculumSearchInput');
    var clearSearchBtn = document.getElementById('clearCurriculumSearch');
    var searchCounter = document.getElementById('curriculumSearchCounter');
    var jumpBtns = document.querySelectorAll('.jump-to-year-btn');

    var currentYearFilter = '<?php echo addslashes($yearFilter); ?>' || 'all';
    var currentYearSort = '<?php echo addslashes($yearSort); ?>' || 'asc';
    var currentCourseSort = 'default';
    var currentSearchTerm = '';

    var allCourseRows = Array.from(document.querySelectorAll('.course-row'));
    var totalCourses = allCourseRows.length;

    // Helper: Update URL state without page reload
    function updateUrlParams() {
        var url = new URL(window.location);
        if (currentYearSort === 'desc') {
            url.searchParams.set('year_sort', 'desc');
        } else {
            url.searchParams.delete('year_sort');
        }
        if (currentYearFilter !== 'all') {
            url.searchParams.set('year_filter', currentYearFilter);
        } else {
            url.searchParams.delete('year_filter');
        }
        window.history.replaceState({}, '', url);
    }

    // 1. Year Level Sorting (Ascending vs Descending)
    function applyYearSort(order) {
        currentYearSort = order;
        var sortedCards = yearCards.slice().sort(function(a, b) {
            var orderA = parseInt(a.getAttribute('data-year-order'), 10);
            var orderB = parseInt(b.getAttribute('data-year-order'), 10);
            return order === 'desc' ? (orderB - orderA) : (orderA - orderB);
        });

        sortedCards.forEach(function(card) {
            container.appendChild(card);
        });
        updateUrlParams();
    }

    if (yearSortSelect) {
        yearSortSelect.addEventListener('change', function() {
            applyYearSort(this.value);
        });
    }

    // 2. Year Level Filtering
    function applyYearFilter(year) {
        currentYearFilter = year;
        filterBtns.forEach(function(btn) {
            var btnYear = btn.getAttribute('data-year');
            var isMatch = (btnYear === year);
            btn.classList.toggle('active', isMatch);
            var badge = btn.querySelector('.badge');
            if (badge) {
                badge.className = isMatch ? 'badge ms-2 bg-light text-dark' : 'badge ms-2 bg-secondary text-white';
            }
        });

        yearCards.forEach(function(card) {
            var cardYear = card.getAttribute('data-year-level');
            if (year === 'all' || cardYear === year) {
                card.classList.remove('d-none');
            } else {
                card.classList.add('d-none');
            }
        });

        applySearchAndVisibility();
        updateUrlParams();
    }

    filterBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            applyYearFilter(this.getAttribute('data-year'));
        });
    });

    // 3. Course-Level Sorting inside tables
    function applyCourseSort(criteria) {
        currentCourseSort = criteria;
        var tbodies = document.querySelectorAll('.semester-subjects-tbody');
        tbodies.forEach(function(tbody) {
            var rows = Array.from(tbody.querySelectorAll('.course-row'));
            if (rows.length <= 1) return;

            rows.sort(function(a, b) {
                if (criteria === 'code_asc') {
                    return a.getAttribute('data-code').localeCompare(b.getAttribute('data-code'));
                } else if (criteria === 'code_desc') {
                    return b.getAttribute('data-code').localeCompare(a.getAttribute('data-code'));
                } else if (criteria === 'name_asc') {
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                } else if (criteria === 'units_desc') {
                    return parseFloat(b.getAttribute('data-units')) - parseFloat(a.getAttribute('data-units'));
                } else if (criteria === 'units_asc') {
                    return parseFloat(a.getAttribute('data-units')) - parseFloat(b.getAttribute('data-units'));
                } else {
                    // default order
                    return parseInt(a.getAttribute('data-default-order'), 10) - parseInt(b.getAttribute('data-default-order'), 10);
                }
            });

            var noMatchRow = tbody.querySelector('.no-match-row');
            rows.forEach(function(r) { tbody.appendChild(r); });
            if (noMatchRow) tbody.appendChild(noMatchRow);
        });
    }

    if (courseSortSelect) {
        courseSortSelect.addEventListener('change', function() {
            applyCourseSort(this.value);
        });
    }

    // 4. Live Search Filter
    function applySearchAndVisibility() {
        var query = currentSearchTerm.trim().toLowerCase();
        var visibleCount = 0;

        allCourseRows.forEach(function(row) {
            var card = row.closest('.year-level-card');
            var cardYear = card ? card.getAttribute('data-year-level') : '';
            var isYearAllowed = (currentYearFilter === 'all' || cardYear === currentYearFilter);

            var searchData = row.getAttribute('data-search-text') || '';
            var matchesQuery = !query || searchData.indexOf(query) !== -1;

            if (isYearAllowed && matchesQuery) {
                row.classList.remove('d-none');
                visibleCount++;
            } else {
                row.classList.add('d-none');
            }
        });

        // Check each semester table for empty search state
        document.querySelectorAll('.semester-subjects-tbody').forEach(function(tbody) {
            var rows = tbody.querySelectorAll('.course-row');
            if (rows.length === 0) return; // Native empty table

            var visibleInTbody = 0;
            rows.forEach(function(r) {
                if (!r.classList.contains('d-none')) visibleInTbody++;
            });

            var noMatchRow = tbody.querySelector('.no-match-row');
            if (visibleInTbody === 0 && query) {
                if (!noMatchRow) {
                    noMatchRow = document.createElement('tr');
                    noMatchRow.className = 'no-match-row';
                    noMatchRow.innerHTML = '<td colspan="5" class="text-center py-3 text-muted small"><i class="bi bi-search me-1"></i> No matching courses in this semester</td>';
                    tbody.appendChild(noMatchRow);
                }
                noMatchRow.classList.remove('d-none');
            } else if (noMatchRow) {
                noMatchRow.classList.add('d-none');
            }
        });

        // Update counter badge
        if (searchCounter) {
            if (query || currentYearFilter !== 'all') {
                searchCounter.textContent = 'Showing ' + visibleCount + ' of ' + totalCourses + ' courses';
                searchCounter.className = 'badge bg-brand-primary text-white border px-3 py-2 text-nowrap fw-semibold';
            } else {
                searchCounter.textContent = 'Showing all ' + totalCourses + ' courses';
                searchCounter.className = 'badge bg-light text-navy border px-3 py-2 text-nowrap fw-semibold';
            }
        }

        if (clearSearchBtn) {
            clearSearchBtn.style.display = query ? 'inline-block' : 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentSearchTerm = this.value;
            applySearchAndVisibility();
        });
    }

    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            currentSearchTerm = '';
            applySearchAndVisibility();
            searchInput.focus();
        });
    }

    // 5. Smooth Jump Navigation
    jumpBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var targetElem = document.getElementById(targetId);
            if (!targetElem) return;

            // If target year is currently hidden due to year filter, reset filter to 'all'
            if (targetElem.classList.contains('d-none')) {
                applyYearFilter('all');
            }

            targetElem.scrollIntoView({ behavior: 'smooth', block: 'start' });
            targetElem.style.transition = 'outline 0.3s ease';
            targetElem.style.outline = '2px solid var(--brand-primary)';
            setTimeout(function() {
                targetElem.style.outline = 'none';
            }, 1200);
        });
    });
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
