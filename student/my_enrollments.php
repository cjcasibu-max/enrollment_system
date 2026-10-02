<?php
/**
 * Student Class Schedule Timetable
 * Displays the cadet's enrolled subjects in a visual weekly timetable grid,
 * with comprehensive schedule parsing, day-to-time auto-fitting, collision handling,
 * course legend directory, printable single-page landscape view, and high-res PNG download.
 */

require_once '../includes/auth_check.php';
checkRole(['student']);

require_once '../config/database.php';

$userId = (int)$_SESSION['user_id'];
$studentId = 0;
$studentName = '';
$enrolledItems = [];
$totalUnits = 0;
$pendingCount = 0;
$enrolledCount = 0;
$distinctSections = [];

// Helper: Day code and name parser (e.g. MWF, TTh, Sat, Friday)
if (!function_exists('parseScheduleDays')) {
    function parseScheduleDays(string $str): array {
        $str = trim($str);
        if ($str === '') return [];

        $fullDays = [
            'monday' => 'Monday', 'mon' => 'Monday',
            'tuesday' => 'Tuesday', 'tue' => 'Tuesday', 'tues' => 'Tuesday',
            'wednesday' => 'Wednesday', 'wed' => 'Wednesday',
            'thursday' => 'Thursday', 'thu' => 'Thursday', 'thur' => 'Thursday', 'thurs' => 'Thursday',
            'friday' => 'Friday', 'fri' => 'Friday',
            'saturday' => 'Saturday', 'sat' => 'Saturday',
            'sunday' => 'Sunday', 'sun' => 'Sunday'
        ];

        $tokens = preg_split('/[\s,\/]+/', $str, -1, PREG_SPLIT_NO_EMPTY);
        $days = [];
        $hasWordDay = false;
        foreach ($tokens as $token) {
            $lower = strtolower($token);
            if (isset($fullDays[$lower])) {
                $days[] = $fullDays[$lower];
                $hasWordDay = true;
            }
        }
        if ($hasWordDay && !empty($days)) {
            return array_values(array_unique($days));
        }

        // Parse compacted day codes like "MWF", "TTh", "TThS", "Su", "Sat"
        $clean = preg_replace('/[^a-zA-Z]/', '', $str);
        $len = strlen($clean);
        $i = 0;
        while ($i < $len) {
            $three = substr($clean, $i, 3);
            $two = substr($clean, $i, 2);
            $one = substr($clean, $i, 1);

            if (strcasecmp($three, 'sat') === 0) {
                $days[] = 'Saturday';
                $i += 3;
            } elseif (strcasecmp($three, 'sun') === 0) {
                $days[] = 'Sunday';
                $i += 3;
            } elseif (strcasecmp($three, 'mon') === 0) {
                $days[] = 'Monday';
                $i += 3;
            } elseif (strcasecmp($three, 'tue') === 0) {
                $days[] = 'Tuesday';
                $i += 3;
            } elseif (strcasecmp($three, 'wed') === 0) {
                $days[] = 'Wednesday';
                $i += 3;
            } elseif (strcasecmp($three, 'thu') === 0) {
                $days[] = 'Thursday';
                $i += 3;
            } elseif (strcasecmp($three, 'fri') === 0) {
                $days[] = 'Friday';
                $i += 3;
            } elseif (strcasecmp($two, 'th') === 0) {
                $days[] = 'Thursday';
                $i += 2;
            } elseif (strcasecmp($two, 'su') === 0) {
                $days[] = 'Sunday';
                $i += 2;
            } elseif (strcasecmp($two, 'sa') === 0) {
                $days[] = 'Saturday';
                $i += 2;
            } elseif (strcasecmp($one, 'm') === 0) {
                $days[] = 'Monday';
                $i += 1;
            } elseif (strcasecmp($one, 't') === 0) {
                $days[] = 'Tuesday';
                $i += 1;
            } elseif (strcasecmp($one, 'w') === 0) {
                $days[] = 'Wednesday';
                $i += 1;
            } elseif (strcasecmp($one, 'f') === 0) {
                $days[] = 'Friday';
                $i += 1;
            } elseif (strcasecmp($one, 's') === 0) {
                $days[] = 'Saturday';
                $i += 1;
            } else {
                $i += 1;
            }
        }

        return array_values(array_unique($days));
    }
}

// Helper: Parse time string into minutes from midnight (0..1439)
if (!function_exists('parseTimeToMinutes')) {
    function parseTimeToMinutes(string $timeStr, ?string $contextMeridiem = null): ?int {
        $timeStr = trim($timeStr);
        if ($timeStr === '') return null;

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(am|pm)?$/i', $timeStr, $m)) {
            $hour = (int)$m[1];
            $min = (int)$m[2];
            $meridiem = !empty($m[3]) ? strtolower($m[3]) : null;

            if ($meridiem === null) {
                if ($contextMeridiem !== null) {
                    if ($contextMeridiem === 'pm') {
                        if ($hour <= 6 || $hour === 12) {
                            $meridiem = 'pm';
                        } elseif ($hour >= 7 && $hour <= 11) {
                            $meridiem = 'am';
                        } else {
                            $meridiem = 'pm';
                        }
                    } else {
                        $meridiem = 'am';
                    }
                } else {
                    if ($hour >= 13) {
                        return $hour * 60 + $min;
                    }
                    if ($hour >= 7 && $hour <= 11) {
                        $meridiem = 'am';
                    } elseif ($hour === 12 || ($hour >= 1 && $hour <= 6)) {
                        $meridiem = 'pm';
                    } else {
                        $meridiem = 'am';
                    }
                }
            }

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }

            return $hour * 60 + $min;
        }

        $ts = strtotime($timeStr);
        if ($ts !== false) {
            $h = (int)date('G', $ts);
            $min = (int)date('i', $ts);
            return $h * 60 + $min;
        }

        return null;
    }
}

// Helper: Parse unstructured schedule string like "MWF 1:00-2:00PM"
if (!function_exists('parseScheduleString')) {
    function parseScheduleString(string $schedule): ?array {
        $schedule = trim($schedule);
        if ($schedule === '' || strcasecmp($schedule, 'tba') === 0 || strcasecmp($schedule, 'not scheduled') === 0) {
            return null;
        }

        if (preg_match('/^(.*?)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*[-–—to]+\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)(.*)$/i', $schedule, $matches)) {
            $dayPart = trim($matches[1] . ' ' . $matches[4]);
            $startRaw = trim($matches[2]);
            $endRaw = trim($matches[3]);

            if (!str_contains($startRaw, ':')) {
                $startRaw = preg_replace('/^(\d{1,2})([a-z]*)$/i', '$1:00$2', $startRaw);
            }
            if (!str_contains($endRaw, ':')) {
                $endRaw = preg_replace('/^(\d{1,2})([a-z]*)$/i', '$1:00$2', $endRaw);
            }

            $endMeridiem = null;
            if (preg_match('/(am|pm)/i', $endRaw, $em)) {
                $endMeridiem = strtolower($em[1]);
            }

            $startMin = parseTimeToMinutes($startRaw, $endMeridiem);
            $endMin = parseTimeToMinutes($endRaw, $endMeridiem);
            $days = parseScheduleDays($dayPart);

            if (!empty($days) && $startMin !== null && $endMin !== null && $endMin > $startMin) {
                return [
                    'days' => $days,
                    'start_min' => $startMin,
                    'end_min' => $endMin,
                ];
            }
        }

        return null;
    }
}

if (!function_exists('formatTime12h')) {
    function formatTime12h(int $minutes): string {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $meridiem = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return sprintf('%d:%02d %s', $h12, $m, $meridiem);
    }
}

if (!function_exists('formatTimeRange')) {
    function formatTimeRange(int $startMin, int $endMin): string {
        return formatTime12h($startMin) . ' - ' . formatTime12h($endMin);
    }
}

// Pastel Palette Generator (derived deterministically from Course Code)
$colorPalette = [
    [
        'bg' => '#e6f7f6',
        'border' => '#0b9b98',
        'text' => '#065f5d',
        'badge_bg' => '#cbf1ef',
        'badge_text' => '#044e4c'
    ],
    [
        'bg' => '#eef2ff',
        'border' => '#6366f1',
        'text' => '#3730a3',
        'badge_bg' => '#e0e7ff',
        'badge_text' => '#312e81'
    ],
    [
        'bg' => '#ecfdf5',
        'border' => '#10b981',
        'text' => '#065f46',
        'badge_bg' => '#d1fae5',
        'badge_text' => '#064e3b'
    ],
    [
        'bg' => '#fffbeb',
        'border' => '#f59e0b',
        'text' => '#92400e',
        'badge_bg' => '#fef3c7',
        'badge_text' => '#78350f'
    ],
    [
        'bg' => '#f5f3ff',
        'border' => '#8b5cf6',
        'text' => '#5b21b6',
        'badge_bg' => '#ede9fe',
        'badge_text' => '#4c1d95'
    ],
    [
        'bg' => '#fff1f2',
        'border' => '#f43f5e',
        'text' => '#9f1239',
        'badge_bg' => '#ffe4e6',
        'badge_text' => '#881337'
    ],
    [
        'bg' => '#f0f9ff',
        'border' => '#0284c7',
        'text' => '#075985',
        'badge_bg' => '#e0f2fe',
        'badge_text' => '#0c4a6e'
    ],
    [
        'bg' => '#f8fafc',
        'border' => '#64748b',
        'text' => '#334155',
        'badge_bg' => '#e2e8f0',
        'badge_text' => '#1e293b'
    ],
];

function getSubjectColor(string $courseCode, array $palette): array {
    $idx = abs(crc32($courseCode)) % count($palette);
    return $palette[$idx];
}

// Fetch student profile ID & name
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $student = $stmt->fetch();
    if ($student) {
        $studentId = (int)$student['id'];
        $studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    }
} catch (\PDOException $e) {
    error_log("Class Schedule student query failed: " . $e->getMessage());
}

if ($studentId > 0) {
    // Fetch cadet registrations & enrolled subjects
    try {
        $stmtMy = $pdo->prepare("
            SELECT 'subject' AS row_type,
                   e.id AS enrollment_id,
                   e.school_year,
                   e.semester,
                   e.status AS reg_status,
                   e.created_at,
                   s.id AS section_id,
                   s.section_name,
                   sub.id AS subject_id,
                   sub.subject_code AS course_code,
                   sub.subject_name AS course_name,
                   sub.units,
                   COALESCE(ss.day_of_week, s.day_of_week) AS day_of_week,
                   COALESCE(ss.start_time, s.start_time) AS start_time,
                   COALESCE(ss.end_time, s.end_time) AS end_time,
                   COALESCE(ss.room, s.room) AS room,
                   s.schedule AS section_schedule,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, sec_u.username, 'TBA') AS teacher_name,
                   COALESCE(u.email, sec_u.email) AS teacher_email
            FROM enrollments e
            JOIN sections s ON e.section_id = s.id
            JOIN section_subjects ss ON ss.section_id = s.id
            JOIN subjects sub ON sub.id = ss.subject_id
            LEFT JOIN users u ON u.id = ss.instructor_id
            LEFT JOIN users sec_u ON sec_u.id = s.teacher_id
            WHERE e.student_id = :student_id_1 AND e.status != 'dropped'

            UNION ALL

            SELECT 'legacy' AS row_type,
                   e.id AS enrollment_id,
                   e.school_year,
                   e.semester,
                   e.status AS reg_status,
                   e.created_at,
                   s.id AS section_id,
                   s.section_name,
                   NULL AS subject_id,
                   COALESCE(c.course_code, s.section_name, 'Section') AS course_code,
                   COALESCE(c.course_name, s.section_name, 'Block Section') AS course_name,
                   COALESCE(c.units, 0) AS units,
                   s.day_of_week,
                   s.start_time,
                   s.end_time,
                   s.room,
                   s.schedule AS section_schedule,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', sec_u.first_name, sec_u.last_name)), ''), sec_u.username, 'TBA') AS teacher_name,
                   sec_u.email AS teacher_email
            FROM enrollments e
            JOIN sections s ON e.section_id = s.id
            LEFT JOIN courses c ON c.id = s.course_id
            LEFT JOIN users sec_u ON sec_u.id = s.teacher_id
            WHERE e.student_id = :student_id_2 AND e.status != 'dropped'
              AND NOT EXISTS (SELECT 1 FROM section_subjects ss_sub WHERE ss_sub.section_id = s.id)

            ORDER BY school_year DESC, semester DESC, section_name, course_code
        ");

        $stmtMy->execute([
            'student_id_1' => $studentId,
            'student_id_2' => $studentId,
        ]);
        $enrolledItems = $stmtMy->fetchAll();

        foreach ($enrolledItems as $r) {
            $distinctSections[$r['section_id']] = true;
            if ($r['reg_status'] === 'enrolled') {
                $totalUnits += (float)$r['units'];
                $enrolledCount++;
            } elseif ($r['reg_status'] === 'pending') {
                $pendingCount++;
            }
        }
    } catch (\PDOException $e) {
        error_log("Class Schedule fetch registrations failed: " . $e->getMessage());
    }
}

// Process timetable schedule items
$allScheduledSlots = [];
$scheduledClassesByDay = [];
$unscheduledSubjects = [];

foreach ($enrolledItems as &$item) {
    $days = [];
    $startMin = null;
    $endMin = null;

    if (!empty($item['start_time']) && !empty($item['end_time']) && $item['start_time'] !== '00:00:00') {
        $startMin = parseTimeToMinutes($item['start_time']);
        $endMin = parseTimeToMinutes($item['end_time']);
        if (!empty($item['day_of_week'])) {
            $days = parseScheduleDays($item['day_of_week']);
        }
    }

    if (empty($days) || $startMin === null || $endMin === null || $endMin <= $startMin) {
        $schedStr = !empty($item['section_schedule']) ? $item['section_schedule'] : (!empty($item['day_of_week']) ? $item['day_of_week'] : '');
        $parsed = parseScheduleString($schedStr);
        if ($parsed) {
            $days = $parsed['days'];
            $startMin = $parsed['start_min'];
            $endMin = $parsed['end_min'];
        }
    }

    if (!empty($days) && $startMin !== null && $endMin !== null && $endMin > $startMin) {
        $item['formatted_schedule'] = implode(', ', $days) . ' ' . formatTimeRange($startMin, $endMin);
        foreach ($days as $d) {
            $instance = $item;
            $instance['day'] = $d;
            $instance['start_min'] = $startMin;
            $instance['end_min'] = $endMin;
            $instance['formatted_time'] = formatTimeRange($startMin, $endMin);
            $scheduledClassesByDay[$d][] = $instance;
            $allScheduledSlots[] = $instance;
        }
    } else {
        $item['formatted_schedule'] = 'TBA / Schedule Not Set';
        $unscheduledSubjects[] = $item;
    }
}
unset($item);

// Determine Days to display (Monday to Saturday, Sunday included only if class exists)
$daysList = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
if (!empty($scheduledClassesByDay['Sunday'])) {
    $daysList[] = 'Sunday';
}

// Compute time range with 1 hour auto-padding
$defaultEarliest = 7; // 7:00 AM
$defaultLatest = 18;  // 6:00 PM

if (!empty($allScheduledSlots)) {
    $minSlot = min(array_column($allScheduledSlots, 'start_min'));
    $maxSlot = max(array_column($allScheduledSlots, 'end_min'));
    $earliestHour = max(0, intdiv($minSlot, 60) - 1);
    $latestHour = min(24, (int)ceil($maxSlot / 60) + 1);
    $earliestHour = min($defaultEarliest, $earliestHour);
    $latestHour = max($defaultLatest, $latestHour);
} else {
    $earliestHour = $defaultEarliest;
    $latestHour = $defaultLatest;
}

$gridStartMin = $earliestHour * 60;
$gridEndMin = $latestHour * 60;
$totalMinutes = $gridEndMin - $gridStartMin;
$totalSlots = max(1, intdiv($totalMinutes, 30));
$slotHeight = 36; // px per 30 minutes on screen
$totalGridHeight = $totalSlots * $slotHeight;
$todayName = date('l');

// Detect overlapping classes per day and assign side-by-side lanes
$positionedDayEvents = [];
foreach ($daysList as $dName) {
    $dayClasses = $scheduledClassesByDay[$dName] ?? [];
    if (empty($dayClasses)) {
        $positionedDayEvents[$dName] = [];
        continue;
    }

    usort($dayClasses, function ($a, $b) {
        if ($a['start_min'] === $b['start_min']) {
            return ($b['end_min'] - $b['start_min']) <=> ($a['end_min'] - $a['start_min']);
        }
        return $a['start_min'] <=> $b['start_min'];
    });

    $clusters = [];
    foreach ($dayClasses as $cls) {
        $placed = false;
        foreach ($clusters as &$cluster) {
            $cStart = min(array_column($cluster, 'start_min'));
            $cEnd = max(array_column($cluster, 'end_min'));
            if ($cls['start_min'] < $cEnd && $cls['end_min'] > $cStart) {
                $cluster[] = $cls;
                $placed = true;
                break;
            }
        }
        unset($cluster);
        if (!$placed) {
            $clusters[] = [$cls];
        }
    }

    $positionedDayEvents[$dName] = [];
    foreach ($clusters as $cluster) {
        $lanes = [];
        $clusterAssigned = [];
        foreach ($cluster as $cls) {
            $assignedLane = -1;
            foreach ($lanes as $laneIdx => $laneEnd) {
                if ($cls['start_min'] >= $laneEnd) {
                    $assignedLane = $laneIdx;
                    $lanes[$laneIdx] = $cls['end_min'];
                    break;
                }
            }
            if ($assignedLane === -1) {
                $assignedLane = count($lanes);
                $lanes[] = $cls['end_min'];
            }
            $cls['lane'] = $assignedLane;
            $clusterAssigned[] = $cls;
        }
        $totalLanes = max(1, count($lanes));
        foreach ($clusterAssigned as $cls) {
            $cls['total_lanes'] = $totalLanes;
            $positionedDayEvents[$dName][] = $cls;
        }
    }
}

// Prepare export data for high-resolution Canvas generator
$canvasData = [
    'days' => $daysList,
    'gridStartMin' => $gridStartMin,
    'gridEndMin' => $gridEndMin,
    'totalSlots' => $totalSlots,
    'eventsByDay' => []
];

foreach ($daysList as $dName) {
    $canvasData['eventsByDay'][$dName] = [];
    $dayEvents = $positionedDayEvents[$dName] ?? [];
    foreach ($dayEvents as $cls) {
        $color = getSubjectColor($cls['course_code'], $colorPalette);
        $canvasData['eventsByDay'][$dName][] = [
            'course_code' => $cls['course_code'],
            'course_name' => $cls['course_name'],
            'room' => $cls['room'] ?: 'TBA',
            'time' => $cls['formatted_time'],
            'teacher' => (!empty($cls['teacher_name']) && $cls['teacher_name'] !== 'TBA') ? $cls['teacher_name'] : '',
            'start_min' => (int)$cls['start_min'],
            'end_min' => (int)$cls['end_min'],
            'lane' => (int)$cls['lane'],
            'total_lanes' => (int)$cls['total_lanes'],
            'is_pending' => ($cls['reg_status'] === 'pending'),
            'color' => $color
        ];
    }
}

$safeStudentSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentName);
$downloadFilename = 'class-schedule' . ($safeStudentSlug ? '-' . $safeStudentSlug : '') . '.png';
$hasScheduledClasses = !empty($allScheduledSlots);

$page_title = "Class Schedule";
require_once '../includes/header.php';
?>

<style>
/* Timetable Styling & Responsive Layout */
#printableTimetable {
    --slot-height: 36px;
}

.schedule-scroll-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
}
.schedule-grid-container {
    min-width: 840px;
    position: relative;
}
.schedule-day-column {
    position: relative;
    flex: 1 1 0;
    min-width: 125px;
    border-right: 1px solid #e2e8f0;
    background-image: 
        linear-gradient(to bottom, #e2e8f0 1px, transparent 1px),
        linear-gradient(to bottom, #f1f5f9 1px, transparent 1px);
    background-size: 100% calc(var(--slot-height) * 2), 100% var(--slot-height);
    background-position: 0 0, 0 0;
}
.schedule-day-column:last-child {
    border-right: none;
}
.schedule-block {
    position: absolute;
    border-radius: 8px;
    padding: 6px 8px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease, z-index 0.15s ease;
    cursor: default;
    z-index: 2;
}
.schedule-block:hover {
    transform: translateY(-1px) scale(1.01);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.14);
    z-index: 10;
}

/* Print Architecture: Prints ONLY the Timetable on a Single Landscape Page */
@media print {
    @page {
        size: landscape;
        margin: 8mm;
    }

    /* Hide EVERYTHING except the timetable */
    .sidebar,
    .top-navbar,
    .footer,
    .no-print,
    .page-heading,
    .schedule-page-header,
    .schedule-stats-row,
    .schedule-unscheduled-card,
    #sidebarToggle,
    .navbar,
    .app-container > aside {
        display: none !important;
    }

    /* Reset host wrappers to zero padding/margin to prevent extra sheets */
    html, body {
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        overflow: visible !important;
    }

    .app-container,
    .main-wrapper,
    .content-body,
    .animated-fade-in {
        display: block !important;
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        background: #ffffff !important;
    }

    /* Isolate timetable container & fit onto single landscape page */
    #printableTimetable {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 8px !important;
        box-shadow: none !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: avoid !important;
        break-after: avoid !important;
        /* Dynamic row height scaling to guarantee entire time span fits exactly 1 page */
        --slot-height: clamp(16px, calc((164mm - 32px) / <?php echo $totalSlots; ?>), 28px) !important;
    }

    .schedule-grid-container {
        min-width: 100% !important;
        width: 100% !important;
        position: relative !important;
    }

    /* Remove sticky headers in print */
    .schedule-header-row {
        position: static !important;
        border-bottom: 2px solid #cbd5e1 !important;
        background: #f8fafc !important;
    }
    .schedule-header-row > div {
        position: static !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        padding: 4px 2px !important;
    }

    /* Neutralize day headers in print: remove today highlight and today badge */
    .schedule-day-header {
        background: #f8fafc !important;
        color: #0f172a !important;
    }
    .schedule-day-header .today-badge {
        display: none !important;
    }
    .schedule-day-header .print-day-count {
        display: block !important;
        font-size: 0.62rem !important;
        color: #64748b !important;
    }
    .schedule-day-header .day-title {
        font-size: 0.78rem !important;
        font-weight: 700 !important;
        color: #0f172a !important;
    }

    .schedule-grid-body {
        position: relative !important;
        height: calc(<?php echo $totalSlots; ?> * var(--slot-height)) !important;
    }

    .schedule-time-column {
        position: static !important;
        width: 65px !important;
        min-width: 65px !important;
        background: #ffffff !important;
        border-right: 1px solid #cbd5e1 !important;
    }
    .schedule-time-slot {
        height: var(--slot-height) !important;
        font-size: 0.58rem !important;
        padding-top: 1px !important;
        padding-right: 4px !important;
        line-height: 1 !important;
    }

    .schedule-day-column {
        min-width: 0 !important;
        flex: 1 1 0 !important;
        height: calc(<?php echo $totalSlots; ?> * var(--slot-height)) !important;
        background-size: 100% calc(var(--slot-height) * 2), 100% var(--slot-height) !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        border-right: 1px solid #e2e8f0 !important;
    }
    .schedule-day-column:last-child {
        border-right: none !important;
    }

    .schedule-block {
        box-shadow: none !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        padding: 2px 4px !important;
        border-radius: 4px !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    .schedule-block .course-code {
        font-size: 0.65rem !important;
        font-weight: 700 !important;
        line-height: 1.1 !important;
    }
    .schedule-block .course-name {
        font-size: 0.56rem !important;
        line-height: 1.15 !important;
    }
    .schedule-block .course-meta {
        font-size: 0.52rem !important;
        margin-top: 1px !important;
    }
    .schedule-block .course-teacher {
        font-size: 0.50rem !important;
    }
}
</style>

<!-- Page Heading -->
<div class="page-heading schedule-page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="page-eyebrow"><i class="bi bi-calendar-week me-1"></i> Academic Timetable</div>
        <h3 class="m-0 text-navy-alt fw-bold">Class Schedule</h3>
        <p class="text-muted small m-0">Your weekly class timetable based on your enrolled subjects.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap no-print">
        <a href="cor" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-file-earmark-text"></i> Certificate of Registration
        </a>
        <button type="button" 
                class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5" 
                id="btnDownloadSchedule"
                <?php if (!$hasScheduledClasses): ?>disabled title="No scheduled classes to download"<?php endif; ?>>
            <i class="bi bi-download"></i> Download Schedule
        </button>
        <button type="button" 
                class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5" 
                onclick="window.print()"
                <?php if (!$hasScheduledClasses): ?>disabled title="No scheduled classes to print"<?php endif; ?>>
            <i class="bi bi-printer"></i> Print Schedule
        </button>
    </div>
</div>

<!-- Quick Statistics Row -->
<div class="row g-3 mb-4 schedule-stats-row">
    <div class="col-12 col-sm-4">
        <div class="card card-premium shadow-sm border-start border-4 border-brand-primary h-100" style="border-radius: 12px;">
            <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-uppercase text-muted-alt small fw-bold m-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">Registered Sections</h6>
                    <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo count($distinctSections); ?></h3>
                </div>
                <div class="fs-1 text-brand-primary opacity-25"><i class="bi bi-journals"></i></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="card card-premium shadow-sm border-start border-4 border-success h-100" style="border-radius: 12px;">
            <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-uppercase text-muted-alt small fw-bold m-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">Enrolled Credits</h6>
                    <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo number_format($totalUnits, 1); ?> <span class="fs-6 text-muted fw-normal">Units</span></h3>
                </div>
                <div class="fs-1 text-success opacity-25"><i class="bi bi-mortarboard"></i></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="card card-premium shadow-sm border-start border-4 border-warning h-100" style="border-radius: 12px;">
            <div class="card-body p-3.5 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-uppercase text-muted-alt small fw-bold m-0" style="font-size: 0.72rem; letter-spacing: 0.5px;">Awaiting Registrar</h6>
                    <h3 class="fw-extrabold text-navy-alt mt-1.5 mb-0"><?php echo $pendingCount; ?> <span class="fs-6 text-muted fw-normal">Pending</span></h3>
                </div>
                <div class="fs-1 text-warning opacity-25"><i class="bi bi-clock-history"></i></div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($enrolledItems)): ?>
    <!-- Empty State -->
    <div class="card border-0 shadow-sm rounded-4 text-center p-5 bg-white">
        <div class="mb-3 text-muted opacity-50" style="font-size: 3.5rem;">
            <i class="bi bi-calendar-x"></i>
        </div>
        <h4 class="fw-bold text-navy mb-2">No Enrolled Classes Yet</h4>
        <p class="text-muted mx-auto mb-4" style="max-width: 480px; font-size: 0.92rem;">
            You have no enrolled class sections for the current academic term yet. Once your section registration is confirmed by the Registrar, your weekly timetable will be displayed here.
        </p>
        <div>
            <a href="dashboard" class="btn btn-brand-primary px-4 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2" style="border-radius: var(--radius-sm, 8px);">
                <i class="bi bi-speedometer2"></i> Back to Dashboard
            </a>
        </div>
    </div>
<?php else: ?>
    <!-- Timetable Card Container (Targeted for isolated printing) -->
    <div id="printableTimetable" class="schedule-scroll-wrapper mb-4">
        <div class="schedule-grid-container">
            <!-- Header Row -->
            <div class="schedule-header-row d-flex" style="position: sticky; top: 0; z-index: 6; border-bottom: 2px solid #e2e8f0; background: #f8fafc;">
                <!-- Corner cell above time column -->
                <div style="width: 75px; min-width: 75px; position: sticky; left: 0; z-index: 7; background: #f8fafc; border-right: 1px solid #e2e8f0; padding: 10px 4px; text-align: center;">
                    <span class="text-muted fw-bold" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px;">Time</span>
                </div>
                <!-- Day Headers -->
                <?php foreach ($daysList as $dName): 
                    $isToday = (strcasecmp($dName, $todayName) === 0);
                    $dayCount = count($scheduledClassesByDay[$dName] ?? []);
                ?>
                    <div class="schedule-day-header flex-fill text-center py-2 px-1" 
                         style="min-width: 125px; border-right: 1px solid #e2e8f0; background: <?php echo $isToday ? 'linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-dark) 100%)' : '#f8fafc'; ?>; color: <?php echo $isToday ? '#ffffff' : 'var(--navy-alt, #0f172a)'; ?>;">
                        <div class="day-title fw-bold" style="font-size: 0.85rem; letter-spacing: 0.3px;">
                            <?php echo htmlspecialchars($dName); ?>
                        </div>
                        <?php if ($isToday): ?>
                            <span class="today-badge badge bg-white text-dark py-0.5 px-2 mt-0.5" style="font-size: 0.62rem; font-weight: 700; border-radius: 999px;">Today</span>
                            <div class="print-day-count small text-muted d-none" style="font-size: 0.68rem;"><?php echo $dayCount; ?> Class<?php echo $dayCount !== 1 ? 'es' : ''; ?></div>
                        <?php else: ?>
                            <div class="small text-muted" style="font-size: 0.68rem;"><?php echo $dayCount; ?> Class<?php echo $dayCount !== 1 ? 'es' : ''; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Grid Body: Time Labels + Day Columns -->
            <div class="schedule-grid-body d-flex" style="position: relative; height: calc(<?php echo $totalSlots; ?> * var(--slot-height));">
                <!-- Time Column -->
                <div class="schedule-time-column" style="width: 75px; min-width: 75px; position: sticky; left: 0; z-index: 5; background: #ffffff; border-right: 1px solid #e2e8f0;">
                    <?php for ($sIdx = 0; $sIdx < $totalSlots; $sIdx++): 
                        $sMins = $gridStartMin + $sIdx * 30;
                        $isHour = ($sIdx % 2 === 0);
                    ?>
                        <div class="schedule-time-slot" style="height: var(--slot-height); padding-right: 6px; text-align: right; font-size: <?php echo $isHour ? '0.72rem' : '0.64rem'; ?>; color: <?php echo $isHour ? '#334155' : '#94a3b8'; ?>; font-weight: <?php echo $isHour ? '700' : '500'; ?>; border-bottom: 1px <?php echo $isHour ? 'solid #e2e8f0' : 'dashed #f1f5f9'; ?>; display: flex; align-items: flex-start; justify-content: flex-end; padding-top: 2px;">
                            <span><?php echo formatTime12h($sMins); ?></span>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Day Columns with Placed Classes -->
                <?php foreach ($daysList as $dName): 
                    $dayEvents = $positionedDayEvents[$dName] ?? [];
                ?>
                    <div class="schedule-day-column" style="height: calc(<?php echo $totalSlots; ?> * var(--slot-height));">
                        <?php foreach ($dayEvents as $cls): 
                            $cColor = getSubjectColor($cls['course_code'], $colorPalette);
                            $duration = $cls['end_min'] - $cls['start_min'];
                            $tLanes = $cls['total_lanes'];
                            $lane = $cls['lane'];
                            $widthPct = 100 / $tLanes;
                            $leftPct = $lane * $widthPct;
                            $isPending = ($cls['reg_status'] === 'pending');
                        ?>
                            <div class="schedule-block" 
                                 title="<?php echo htmlspecialchars($cls['course_code'] . ' - ' . $cls['course_name'] . ' | ' . ($cls['room'] ?: 'TBA') . ' | ' . $cls['formatted_time'] . ' | ' . ($cls['teacher_name'] ?: 'TBA')); ?>"
                                 style="top: calc(((<?php echo $cls['start_min'] - $gridStartMin; ?> / 30) * var(--slot-height)));
                                        height: max(24px, calc(((<?php echo $duration; ?> / 30) * var(--slot-height)) - 3px));
                                        left: calc(<?php echo round($leftPct, 2); ?>% + 2px);
                                        width: calc(<?php echo round($widthPct, 2); ?>% - 4px);
                                        background-color: <?php echo $cColor['bg']; ?>;
                                        border-left: 4px solid <?php echo $cColor['border']; ?>;
                                        color: <?php echo $cColor['text']; ?>;
                                        <?php if ($isPending): ?>
                                            border-top: 1.5px dashed <?php echo $cColor['border']; ?>;
                                            border-right: 1.5px dashed <?php echo $cColor['border']; ?>;
                                            border-bottom: 1.5px dashed <?php echo $cColor['border']; ?>;
                                            opacity: 0.88;
                                        <?php endif; ?>">
                                <div class="d-flex align-items-center justify-content-between gap-1 mb-0.5">
                                    <strong class="course-code text-truncate" style="font-size: 0.78rem; color: <?php echo $cColor['text']; ?>;">
                                        <?php echo htmlspecialchars($cls['course_code']); ?>
                                    </strong>
                                    <?php if ($isPending): ?>
                                        <span class="badge bg-warning text-dark py-0 px-1" style="font-size: 0.58rem; border-radius: 4px;">Pending</span>
                                    <?php endif; ?>
                                </div>
                                <div class="course-name text-truncate fw-semibold" style="font-size: 0.72rem; line-height: 1.2; opacity: 0.95;">
                                    <?php echo htmlspecialchars($cls['course_name']); ?>
                                </div>
                                <div class="course-meta d-flex align-items-center flex-wrap gap-x-2 gap-y-0.5 mt-1" style="font-size: 0.68rem; opacity: 0.85;">
                                    <span class="text-truncate">
                                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($cls['room'] ?: 'TBA'); ?>
                                    </span>
                                    <span>
                                        <i class="bi bi-clock"></i> <?php echo htmlspecialchars($cls['formatted_time']); ?>
                                    </span>
                                </div>
                                <?php if (!empty($cls['teacher_name']) && $cls['teacher_name'] !== 'TBA'): ?>
                                    <div class="course-teacher text-truncate mt-1" style="font-size: 0.66rem; opacity: 0.8;">
                                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($cls['teacher_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($unscheduledSubjects)): ?>
        <!-- Unscheduled / TBA Section -->
        <div class="card schedule-unscheduled-card border-0 shadow-sm mb-4" style="border-radius: 14px; border-left: 4px solid #f59e0b !important; background: #fffcf0;">
            <div class="card-body p-3.5">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i>
                    <h6 class="m-0 fw-bold text-dark" style="font-size: 0.94rem;">Unscheduled / TBA Subjects</h6>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning ms-auto" style="font-size: 0.7rem; border-radius: 999px;">
                        <?php echo count($unscheduledSubjects); ?> Subject<?php echo count($unscheduledSubjects) !== 1 ? 's' : ''; ?>
                    </span>
                </div>
                <p class="text-muted small mb-3">The following enrolled subjects do not currently have a definite weekly timetable or classroom assigned. They will appear on your timetable grid once finalized by the Registrar.</p>
                <div class="row g-2">
                    <?php foreach ($unscheduledSubjects as $unSub): 
                        $unColor = getSubjectColor($unSub['course_code'], $colorPalette);
                    ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="p-2.5 rounded-3 bg-white border d-flex align-items-center justify-content-between gap-2 shadow-sm">
                                <div class="d-flex align-items-center gap-2 overflow-hidden">
                                    <span class="d-inline-block rounded-circle flex-shrink-0" style="width: 10px; height: 10px; background-color: <?php echo $unColor['border']; ?>;"></span>
                                    <div class="text-truncate">
                                        <strong class="d-block text-truncate small text-dark"><?php echo htmlspecialchars($unSub['course_code']); ?></strong>
                                        <span class="d-block text-truncate text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($unSub['course_name']); ?></span>
                                    </div>
                                </div>
                                <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.68rem;">TBA</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Structured data payload for Canvas Schedule PNG Generator -->
<script id="scheduleExportData" type="application/json">
<?php echo json_encode($canvasData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const downloadBtn = document.getElementById('btnDownloadSchedule');
    if (!downloadBtn) return;

    downloadBtn.addEventListener('click', async function () {
        const payloadElement = document.getElementById('scheduleExportData');
        if (!payloadElement) {
            alert('Schedule data could not be located.');
            return;
        }

        let scheduleData;
        try {
            scheduleData = JSON.parse(payloadElement.textContent);
        } catch (e) {
            console.error('Failed to parse schedule JSON:', e);
            alert('Failed to read schedule data.');
            return;
        }

        // Check for classes
        const hasClasses = scheduleData.days && scheduleData.days.some(d => {
            return scheduleData.eventsByDay[d] && scheduleData.eventsByDay[d].length > 0;
        });

        if (!hasClasses) {
            alert('No scheduled classes available to download.');
            return;
        }

        const originalBtnHtml = downloadBtn.innerHTML;
        downloadBtn.disabled = true;
        downloadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating Image...';

        try {
            // Wait for custom fonts (Inter) to be fully loaded
            if (document.fonts && document.fonts.ready) {
                await document.fonts.ready;
            }

            const fontFamily = window.getComputedStyle(document.body).fontFamily || 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

            // Canvas Layout Dimensions (Crisp 1400px base with 2x retina scale)
            const logicalWidth = 1400;
            const timeColWidth = 85;
            const headerHeight = 52;
            const slotHeight = 36;
            const totalSlots = scheduleData.totalSlots || 22;
            const gridHeight = totalSlots * slotHeight;
            const logicalHeight = headerHeight + gridHeight;
            const dayCount = scheduleData.days.length;
            const dayColWidth = (logicalWidth - timeColWidth) / dayCount;

            const scale = 2;
            const canvas = document.createElement('canvas');
            canvas.width = logicalWidth * scale;
            canvas.height = logicalHeight * scale;
            const ctx = canvas.getContext('2d');

            ctx.scale(scale, scale);

            // 1. Background Fill
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, logicalWidth, logicalHeight);

            // 2. Day Header Row Background
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(0, 0, logicalWidth, headerHeight);

            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(0, headerHeight);
            ctx.lineTo(logicalWidth, headerHeight);
            ctx.stroke();

            // Time Header Corner Box
            ctx.fillStyle = '#64748b';
            ctx.font = 'bold 11px ' + fontFamily;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('TIME', timeColWidth / 2, headerHeight / 2);

            // Helper: Text truncation with ellipsis
            function fitText(ctx, text, maxWidth) {
                if (!text) return '';
                if (ctx.measureText(text).width <= maxWidth) return text;
                let s = text;
                while (s.length > 0 && ctx.measureText(s + '...').width > maxWidth) {
                    s = s.slice(0, -1);
                }
                return s ? s + '...' : '';
            }

            // Helper: Format 12-hour time
            function formatTimeStr(mins) {
                let h = Math.floor(mins / 60);
                let m = mins % 60;
                let meridiem = h >= 12 ? 'PM' : 'AM';
                let h12 = h % 12;
                if (h12 === 0) h12 = 12;
                return h12 + ':' + (m < 10 ? '0' : '') + m + ' ' + meridiem;
            }

            // Helper: Rounded rectangle
            function drawRoundRect(c, x, y, w, h, r) {
                if (typeof c.roundRect === 'function') {
                    c.beginPath();
                    c.roundRect(x, y, w, h, r);
                } else {
                    c.beginPath();
                    c.moveTo(x + r, y);
                    c.arcTo(x + w, y, x + w, y + h, r);
                    c.arcTo(x + w, y + h, x, y + h, r);
                    c.arcTo(x, y + h, x, y, r);
                    c.arcTo(x, y, x + w, y, r);
                    c.closePath();
                }
            }

            // 3. Day Header Labels (Neutral, NO Today highlight or tag)
            for (let i = 0; i < dayCount; i++) {
                const dayName = scheduleData.days[i];
                const dayLeft = timeColWidth + i * dayColWidth;
                const dayCenter = dayLeft + dayColWidth / 2;
                const events = scheduleData.eventsByDay[dayName] || [];

                ctx.fillStyle = '#0f172a';
                ctx.font = 'bold 13.5px ' + fontFamily;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'top';
                ctx.fillText(dayName, dayCenter, 11);

                ctx.fillStyle = '#64748b';
                ctx.font = '500 11px ' + fontFamily;
                const classCountStr = events.length + (events.length === 1 ? ' Class' : ' Classes');
                ctx.fillText(classCountStr, dayCenter, 31);
            }

            // 4. Horizontal Grid Lines & Time Labels
            for (let s = 0; s < totalSlots; s++) {
                const slotY = headerHeight + s * slotHeight;
                const slotMins = scheduleData.gridStartMin + s * 30;
                const isHour = (s % 2 === 0);

                if (isHour) {
                    ctx.strokeStyle = '#e2e8f0';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(0, slotY);
                    ctx.lineTo(logicalWidth, slotY);
                    ctx.stroke();

                    ctx.fillStyle = '#334155';
                    ctx.font = 'bold 11px ' + fontFamily;
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'top';
                    ctx.fillText(formatTimeStr(slotMins), timeColWidth - 8, slotY + 2);
                } else {
                    ctx.strokeStyle = '#f1f5f9';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(timeColWidth, slotY);
                    ctx.lineTo(logicalWidth, slotY);
                    ctx.stroke();

                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '500 9.5px ' + fontFamily;
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'top';
                    ctx.fillText(formatTimeStr(slotMins), timeColWidth - 8, slotY + 2);
                }
            }

            // 5. Vertical Day Separators
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(timeColWidth, 0);
            ctx.lineTo(timeColWidth, logicalHeight);
            ctx.stroke();

            for (let i = 1; i < dayCount; i++) {
                const x = timeColWidth + i * dayColWidth;
                ctx.beginPath();
                ctx.moveTo(x, 0);
                ctx.lineTo(x, logicalHeight);
                ctx.stroke();
            }

            // 6. Draw Class Blocks
            for (let i = 0; i < dayCount; i++) {
                const dayName = scheduleData.days[i];
                const dayLeft = timeColWidth + i * dayColWidth;
                const events = scheduleData.eventsByDay[dayName] || [];

                events.forEach(function (cls) {
                    const top = headerHeight + ((cls.start_min - scheduleData.gridStartMin) / 30) * slotHeight;
                    const duration = cls.end_min - cls.start_min;
                    const blockHeight = Math.max(26, (duration / 30) * slotHeight - 3);
                    const laneWidth = (dayColWidth - 6) / cls.total_lanes;
                    const left = dayLeft + 3 + cls.lane * laneWidth;
                    const width = laneWidth - 3;

                    // Fill rounded rectangle with pastel background
                    drawRoundRect(ctx, left, top, width, blockHeight, 6);
                    ctx.fillStyle = cls.color.bg;
                    ctx.fill();

                    // Left accent border (4px)
                    ctx.save();
                    drawRoundRect(ctx, left, top, width, blockHeight, 6);
                    ctx.clip();
                    ctx.fillStyle = cls.color.border;
                    ctx.fillRect(left, top, 4.5, blockHeight);
                    ctx.restore();

                    // Subtle outer border (dashed if pending)
                    drawRoundRect(ctx, left, top, width, blockHeight, 6);
                    ctx.strokeStyle = cls.is_pending ? cls.color.border : '#e2e8f0';
                    ctx.lineWidth = 1;
                    if (cls.is_pending) ctx.setLineDash([3, 3]);
                    ctx.stroke();
                    if (cls.is_pending) ctx.setLineDash([]);

                    // Clip content inside block boundaries
                    ctx.save();
                    ctx.beginPath();
                    ctx.rect(left + 6, top + 2, width - 8, blockHeight - 4);
                    ctx.clip();

                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'top';
                    const textX = left + 8;
                    const maxTextW = width - 14;
                    let curY = top + 5;

                    // 1. Course Code
                    ctx.fillStyle = cls.color.text;
                    ctx.font = 'bold 12px ' + fontFamily;
                    let codeText = cls.course_code;
                    if (cls.is_pending) codeText += ' (Pending)';
                    ctx.fillText(fitText(ctx, codeText, maxTextW), textX, curY);
                    curY += 15;

                    // 2. Course Name
                    ctx.font = '600 10.5px ' + fontFamily;
                    ctx.fillStyle = cls.color.text;
                    ctx.fillText(fitText(ctx, cls.course_name, maxTextW), textX, curY);
                    curY += 14;

                    // 3. Room & Time
                    if (blockHeight >= 48) {
                        ctx.font = '500 10px ' + fontFamily;
                        ctx.fillStyle = cls.color.text;
                        const metaStr = cls.room + ' • ' + cls.time;
                        ctx.fillText(fitText(ctx, metaStr, maxTextW), textX, curY);
                        curY += 13;
                    }

                    // 4. Instructor
                    if (blockHeight >= 72 && cls.teacher) {
                        ctx.font = '500 9.5px ' + fontFamily;
                        ctx.fillStyle = cls.color.text;
                        ctx.fillText(fitText(ctx, cls.teacher, maxTextW), textX, curY);
                    }

                    ctx.restore();
                });
            }

            // 7. Outer Timetable Border
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1;
            ctx.strokeRect(0.5, 0.5, logicalWidth - 1, logicalHeight - 1);

            // 8. Trigger PNG Download via Blob
            canvas.toBlob(function (blob) {
                if (!blob) {
                    alert('Canvas image export failed.');
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = originalBtnHtml;
                    return;
                }

                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = <?php echo json_encode($downloadFilename); ?>;
                document.body.appendChild(a);
                a.click();

                setTimeout(function () {
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = originalBtnHtml;
                }, 150);
            }, 'image/png');

        } catch (err) {
            console.error('Download Schedule generation error:', err);
            alert('An error occurred while generating the schedule image.');
            downloadBtn.disabled = false;
            downloadBtn.innerHTML = originalBtnHtml;
        }
    });
});
</script>

<?php
require_once '../includes/footer.php';
?>
