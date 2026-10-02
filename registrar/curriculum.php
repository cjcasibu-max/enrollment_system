<?php
/**
 * Curriculum Management & Preview Portal
 * Provides an interactive, year-by-year and semester-by-semester overview of BSMT & BSMarE curriculums.
 * Strictly adheres to the NCST Maritime Academy Design System & Theme.
 */

require_once '../includes/auth_check.php';
checkRole(['registrar', 'admin']);

// If logged-in user is an administrator, redirect to the Admin Curriculum Management Portal
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $param = isset($_GET['program']) ? '?program=' . urlencode($_GET['program']) : '';
    if (isset($_GET['curriculum_id'])) {
        $param .= ($param ? '&' : '?') . 'curriculum_id=' . (int)$_GET['curriculum_id'];
    }
    header("Location: ../admin/curriculum" . $param);
    exit;
}

require_once '../config/database.php';
require_once '../includes/academic_terms.php';

$activeTerm = getActiveAcademicTerm($pdo);

$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : 'BSMT';
if (!in_array($selectedProgram, ['BSMT', 'BSMarE'], true)) {
    $selectedProgram = 'BSMT';
}

$page_title = $selectedProgram . " Curriculum Preview";
$page_class = 'page-curriculum';

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

// Fetch programs
try {
    $programs = $pdo->query("SELECT id, program_code, program_name FROM programs WHERE program_code IN ('BSMT', 'BSMarE') ORDER BY program_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $programs = [];
}

// Fetch active curriculum for selected program
try {
    $currStmt = $pdo->prepare("
        SELECT c.*, p.program_code, p.program_name
        FROM curriculums c
        JOIN programs p ON p.id = c.program_id
        WHERE p.program_code = :prog
        ORDER BY c.is_active DESC, c.effective_year DESC
    ");
    $currStmt->execute(['prog' => $selectedProgram]);
    $curriculums = $currStmt->fetchAll(PDO::FETCH_ASSOC);
    $activeCurriculum = !empty($curriculums) ? $curriculums[0] : null;
} catch (Exception $e) {
    $curriculums = [];
    $activeCurriculum = null;
}

$curriculumId = isset($_GET['curriculum_id']) ? (int)$_GET['curriculum_id'] : ($activeCurriculum['id'] ?? 0);
if ($curriculumId > 0) {
    foreach ($curriculums as $c) {
        if ((int)$c['id'] === $curriculumId) {
            $activeCurriculum = $c;
            break;
        }
    }
}

// Fetch curriculum subjects & stats
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
            SELECT cs.*, s.subject_code, s.subject_name, s.description, s.subject_type,
                   (SELECT COUNT(*) FROM subject_prerequisites sp WHERE sp.subject_id = s.id) AS prereq_count
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

        // Fetch prerequisites for all subjects
        $prereqStmt = $pdo->query("
            SELECT sp.subject_id, p.subject_code AS prereq_code, p.subject_name AS prereq_name
            FROM subject_prerequisites sp
            JOIN subjects p ON p.id = sp.prerequisite_subject_id
        ");
        $allPrereqs = [];
        foreach ($prereqStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $allPrereqs[(int)$pr['subject_id']][] = $pr['prereq_code'];
        }

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

            if (($row['subject_type'] ?? '') === 'General Education' || ($row['subject_type'] ?? '') === 'NSTP' || ($row['subject_type'] ?? '') === 'Physical Education') {
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
        error_log("Fetch curriculum subjects failed: " . $e->getMessage());
    }
}

require_once '../includes/header.php';
?>

<!-- =========================================================================
     PAGE HEADING
     ========================================================================= -->
<div class="page-heading mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-journal-album me-1"></i> Maritime Academics</div>
        <h3 class="m-0 text-navy-alt fw-bold">Curriculum Roadmap & Master Preview</h3>
        <p class="text-muted small m-0">CHED & MARINA aligned 4-year curriculum structures for BSMT and BSMarE programs.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="subjects?program=<?php echo urlencode($selectedProgram); ?>" class="btn btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-2 fw-semibold">
            <i class="bi bi-book"></i> Subjects Directory
        </a>
        <button type="button" class="btn btn-brand-primary d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2 fw-semibold" onclick="window.print()">
            <i class="bi bi-printer"></i> Print Curriculum
        </button>
    </div>
</div>

<!-- Registrar Read-Only Governance Notice -->
<div class="alert alert-light border border-info-subtle bg-info-subtle text-navy-alt rounded-3 p-2.5 px-3 mb-3 d-flex align-items-center gap-2 small">
    <i class="bi bi-info-circle-fill text-brand-primary fs-5"></i>
    <span><strong>Academic Catalog Reference:</strong> Curriculums, course mappings, and prerequisite chains are governed centrally by the <strong>Administration Office</strong>. This screen provides the registrar office with an operational roadmap preview.</span>
</div>

<!-- Flash Messages -->
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
     UNIFIED MARITIME CONTROL & FILTER BAR
     ========================================================================= -->
<div class="maritime-control-bar mb-4 p-3 bg-white rounded-4 border border-light-subtle shadow-sm">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <!-- Program Selector Pills -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-uppercase text-muted me-1"><i class="bi bi-mortarboard me-1"></i> Program:</span>
            <a href="curriculum?program=BSMT" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMT' ? 'active' : ''; ?>">
                <i class="bi bi-compass"></i> BSMT
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMT' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Transportation</span>
            </a>
            <a href="curriculum?program=BSMarE" 
               class="program-pill-btn <?php echo $selectedProgram === 'BSMarE' ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i> BSMarE
                <span class="badge ms-1 <?php echo $selectedProgram === 'BSMarE' ? 'bg-light text-dark' : 'bg-secondary text-white'; ?>" style="font-size: 0.65rem;">Marine Engineering</span>
            </a>
        </div>

        <!-- Curriculum Version Dropdown -->
        <?php if (!empty($curriculums)): ?>
            <div class="d-flex align-items-center gap-2">
                <label class="form-label m-0 fw-semibold text-muted small text-nowrap"><i class="bi bi-layers me-1 text-brand-primary"></i> Curriculum Edition:</label>
                <select class="form-select form-select-sm fw-semibold text-navy border-light-subtle shadow-none" style="min-width: 220px;" onchange="location.href='curriculum?program=<?php echo urlencode($selectedProgram); ?>&curriculum_id=' + this.value">
                    <?php foreach ($curriculums as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ((int)$c['id'] === (int)($activeCurriculum['id'] ?? 0)) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['curriculum_name']); ?> (AY <?php echo htmlspecialchars($c['effective_year']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeCurriculum): ?>
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
         4-YEAR ACADEMIC PROGRESSION ROADMAP
         ========================================================================= -->
    <div class="row g-4">
        <?php 
        $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
        $yearIndex = 0;
        foreach ($yearLevels as $year): 
            $yearIndex++;
            $semesters = $curriculumSubjects[$year] ?? [];
            $yearSubs = $stats['by_year'][$year]['subjects'] ?? 0;
            $yearUnits = $stats['by_year'][$year]['units'] ?? 0.0;
        ?>
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-2" style="border: 1px solid #e0eded !important; box-shadow: 0 4px 24px rgba(11,155,152,0.07) !important;">
                    <!-- Year Level Banner Header (Maritime Teal Gradient with High-Contrast White Text) -->
                    <div class="py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%);">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle text-navy d-flex align-items-center justify-content-center fw-bold shadow-sm" 
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
                        <?php if (empty($semesters)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-calendar-x fs-2 text-muted-light d-block mb-2"></i>
                                No subjects mapped for <?php echo htmlspecialchars($year); ?>.
                            </div>
                        <?php else: ?>
                            <div class="row g-3 g-lg-4">
                                <?php 
                                $semOrder = ['1st Semester', '2nd Semester', 'Summer'];
                                foreach ($semOrder as $semName):
                                    if (!isset($semesters[$semName])) continue;
                                    $subs = $semesters[$semName];
                                    $semUnits = array_sum(array_column($subs, 'units'));
                                    $isSummer = ($semName === 'Summer');
                                ?>
                                    <div class="col-12">
                                        <div class="card border border-light-subtle rounded-3 shadow-sm h-100 bg-white overflow-hidden">
                                            <!-- Semester Sub-Header -->
                                            <div class="card-header py-2.5 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2"
                                                 style="background-color: <?php echo $isSummer ? '#fefce8' : 'var(--surface-tint)'; ?>;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi <?php echo $isSummer ? 'bi-sun text-warning' : 'bi-calendar3 text-brand-primary'; ?>"></i>
                                                    <span class="fw-bold small text-navy"><?php echo htmlspecialchars($semName); ?></span>
                                                </div>
                                                <div class="d-flex gap-1.5">
                                                    <span class="badge bg-white text-dark border px-2 py-0.5" style="font-size: 0.72rem;"><?php echo count($subs); ?> Subjects</span>
                                                    <span class="badge text-white px-2.5 py-0.5 font-monospace" style="font-size: 0.72rem; background-color: var(--brand-dark);">
                                                        <?php echo number_format($semUnits, 1); ?> Units
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Semester Subjects Table -->
                                            <div class="table-responsive">
                                                <table class="table table-hover table-maritime align-middle mb-0" style="width: 100%; font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th class="ps-4" style="width: 22%;">Code & Category</th>
                                                            <th style="width: 44%;">Subject Name</th>
                                                            <th style="width: 10%;" class="text-center">Units</th>
                                                            <th class="pe-4" style="width: 24%;">Prerequisites</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($subs as $s): ?>
                                                            <tr>
                                                                <td class="ps-4">
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
                                                                        <div class="text-muted small mt-0.5" style="font-size: 0.75rem; line-height: 1.35;" title="<?php echo htmlspecialchars($s['description']); ?>">
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
                                                                <td class="pe-3">
                                                                    <?php if (!empty($s['prerequisites'])): ?>
                                                                        <div class="d-flex flex-wrap gap-1.5">
                                                                            <?php foreach ($s['prerequisites'] as $prCode): ?>
                                                                                <span class="badge font-monospace shadow-2xs d-inline-flex align-items-center" 
                                                                                      style="background-color: #fef3c7; color: #b45309; border: 1.5px solid #f59e0b; font-size: 0.72rem; font-weight: 700; padding: 0.25rem 0.5rem; border-radius: 6px;">
                                                                                    <i class="bi bi-link-45deg me-1" style="font-size: 0.8rem;"></i><?php echo htmlspecialchars($prCode); ?>
                                                                                </span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.7rem; font-weight: 500;">None</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <div class="card card-premium shadow-sm border-0 rounded-4 text-center py-5">
        <div class="card-body">
            <i class="bi bi-journal-x fs-1 text-muted-light mb-3 d-block"></i>
            <h5 class="fw-bold text-dark">No Active Curriculum Configured</h5>
            <p class="text-muted small">No active curriculum was found for <?php echo htmlspecialchars($selectedProgram); ?>.</p>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
