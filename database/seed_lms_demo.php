<?php
/**
 * LMS Demo Data Seeder
 * 
 * Generates complete, realistic demo data for testing and faculty demonstrations:
 * - Admin, Registrar, Cashier demo accounts
 * - 5 Teachers with department specializations
 * - 18 Enrolled Students + 1 Unenrolled Student across BSMT and BSMarE
 * - Sections: BSMT-1A, BSMT-1B, BSMarE-1A
 * - Non-conflicting schedules across teachers, sections, and rooms (Mon-Sat, 7AM-5PM, lunch break)
 * - LMS Announcements, Materials, Assignments, Quizzes (MC, T/F, Identification), and Submissions
 * - Idempotent execution (cleans existing demo data before re-seeding)
 * 
 * Guard: CLI or Localhost only.
 */

$isCli = (PHP_SAPI === 'cli');
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);

if (!$isCli && !$isLocalhost) {
    http_response_code(403);
    die("Access denied: Seeder can only be executed via CLI or local machine.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/assessments.php';

// First, clean up previous demo records to ensure idempotence
require_once __DIR__ . '/cleanup_lms_demo.php';

echo "\n=== SEEDING LMS DEMO DATA ===\n";

try {
    $pdo->beginTransaction();

    // ── 1. Term & Program Validation ─────────────────────────────────────────────
    $termStmt = $pdo->query("SELECT * FROM academic_terms WHERE is_active = 1 LIMIT 1");
    $activeTerm = $termStmt->fetch(PDO::FETCH_ASSOC);
    if (!$activeTerm) {
        throw new RuntimeException("No active academic term found. Please activate a term first.");
    }
    $termId = (int)$activeTerm['id'];
    $schoolYear = $activeTerm['school_year'];
    $semester = $activeTerm['semester'];
    echo "Active Term: {$schoolYear} {$semester} Semester (ID: {$termId})\n";

    $programMap = [];
    foreach ($pdo->query("SELECT id, program_code, program_name FROM programs")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $programMap[$p['program_code']] = (int)$p['id'];
    }
    if (!isset($programMap['BSMT']) || !isset($programMap['BSMarE'])) {
        throw new RuntimeException("BSMT or BSMarE program records missing in 'programs' table.");
    }

    // Common password hash for all demo accounts
    $demoPassword = 'Demo@12345';
    $passwordHash = password_hash($demoPassword, PASSWORD_DEFAULT);

    // ── 2. Staff Accounts (Admin, Registrar, Cashier) ───────────────────────────
    $staffUsers = [
        [
            'username' => 'demo_admin',
            'email' => 'admin@demo.com',
            'role' => 'admin',
            'first_name' => 'Admiral Juan',
            'last_name' => 'Santos'
        ],
        [
            'username' => 'demo_registrar',
            'email' => 'registrar@demo.com',
            'role' => 'registrar',
            'first_name' => 'Elena',
            'last_name' => 'Ramos'
        ],
        [
            'username' => 'demo_cashier',
            'email' => 'cashier@demo.com',
            'role' => 'cashier',
            'first_name' => 'Ricardo',
            'last_name' => 'Dalisay'
        ]
    ];

    $userInsertStmt = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, created_at)
         VALUES (:username, :email, :password_hash, :role, :first_name, :last_name, 1, NOW())"
    );

    foreach ($staffUsers as $u) {
        $userInsertStmt->execute([
            'username' => $u['username'],
            'email' => $u['email'],
            'password_hash' => $passwordHash,
            'role' => $u['role'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
        ]);
        echo "  [+] Seeded {$u['role']}: {$u['username']} ({$u['email']})\n";
    }

    // ── 3. Faculty / Teachers ───────────────────────────────────────────────────
    $teachers = [
        [
            'key' => 'T1',
            'username' => 'demo_teacher1',
            'email' => 'teacher1@demo.com',
            'first_name' => 'Capt. Roberto',
            'last_name' => 'Santos',
            'specialization' => 'Navigation & Seamanship'
        ],
        [
            'key' => 'T2',
            'username' => 'demo_teacher2',
            'email' => 'teacher2@demo.com',
            'first_name' => 'Engr. Maria Clara',
            'last_name' => 'Reyes',
            'specialization' => 'Marine Engineering & Thermodynamics'
        ],
        [
            'key' => 'T3',
            'username' => 'demo_teacher3',
            'email' => 'teacher3@demo.com',
            'first_name' => 'Capt. Eduardo',
            'last_name' => 'Gomez',
            'specialization' => 'Maritime Safety & Environmental Protection'
        ],
        [
            'key' => 'T4',
            'username' => 'demo_teacher4',
            'email' => 'teacher4@demo.com',
            'first_name' => 'Dr. Juan',
            'last_name' => 'Dela Cruz',
            'specialization' => 'Mathematics & Applied Marine Science'
        ],
        [
            'key' => 'T5',
            'username' => 'demo_teacher5',
            'email' => 'teacher5@demo.com',
            'first_name' => 'Prof. Teresa',
            'last_name' => 'Bautista',
            'specialization' => 'Maritime English & Communication'
        ]
    ];

    $teacherIds = [];
    foreach ($teachers as $t) {
        $userInsertStmt->execute([
            'username' => $t['username'],
            'email' => $t['email'],
            'password_hash' => $passwordHash,
            'role' => 'teacher',
            'first_name' => $t['first_name'],
            'last_name' => $t['last_name'],
        ]);
        $tUserId = (int)$pdo->lastInsertId();
        $teacherIds[$t['key']] = $tUserId;
        echo "  [+] Seeded Teacher {$t['key']}: {$t['first_name']} {$t['last_name']} ({$t['username']})\n";
    }

    // ── 4. Class Sections ───────────────────────────────────────────────────────
    $sectionDefs = [
        'BSMT-1A' => [
            'section_name' => 'BSMT-1A',
            'program' => 'BSMT',
            'year_level' => '1st Year',
            'lead_teacher' => $teacherIds['T1'],
            'schedule' => 'MWF 08:00-14:30 / TTh 08:30-11:30'
        ],
        'BSMT-1B' => [
            'section_name' => 'BSMT-1B',
            'program' => 'BSMT',
            'year_level' => '1st Year',
            'lead_teacher' => $teacherIds['T1'],
            'schedule' => 'MWF 13:00-16:00 / TTh 08:30-14:30'
        ],
        'BSMarE-1A' => [
            'section_name' => 'BSMarE-1A',
            'program' => 'BSMarE',
            'year_level' => '1st Year',
            'lead_teacher' => $teacherIds['T2'],
            'schedule' => 'MWF 08:00-16:00 / TTh 13:00-16:00'
        ]
    ];

    $sectionInsertStmt = $pdo->prepare(
        "INSERT INTO sections (section_name, academic_term_id, schedule, capacity, year_level, program, teacher_id, status, created_at)
         VALUES (:section_name, :academic_term_id, :schedule, 40, :year_level, :program, :teacher_id, 'active', NOW())"
    );

    $sectionIds = [];
    foreach ($sectionDefs as $key => $sec) {
        $sectionInsertStmt->execute([
            'section_name' => $sec['section_name'],
            'academic_term_id' => $termId,
            'schedule' => $sec['schedule'],
            'year_level' => $sec['year_level'],
            'program' => $sec['program'],
            'teacher_id' => $sec['lead_teacher'],
        ]);
        $sId = (int)$pdo->lastInsertId();
        $sectionIds[$key] = $sId;
        echo "  [+] Seeded Section: {$sec['section_name']} (ID: {$sId})\n";
    }

    // ── 5. Subject Lookups ──────────────────────────────────────────────────────
    $subjectLookup = [];
    $subjsStmt = $pdo->query("SELECT id, subject_code, subject_name FROM subjects WHERE year_level = '1st Year' AND semester_name = '1st Semester'");
    foreach ($subjsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $subjectLookup[$row['subject_code']] = (int)$row['id'];
    }

    // Required BSMT & BSMarE Subjects
    $neededCodes = [
        'BSMT-MT101', 'BSMT-MT102', 'BSMT-MT103', 'BSMT-GE101', 'BSMT-GE102',
        'BSMARE-ME101', 'BSMARE-ME102', 'BSMARE-ME103', 'BSMARE-GE101', 'BSMARE-GE102'
    ];
    foreach ($neededCodes as $c) {
        if (!isset($subjectLookup[$c])) {
            throw new RuntimeException("Required subject code '{$c}' not found in 'subjects' table.");
        }
    }

    // ── 6. Section-Subjects & Non-Conflicting Schedules ─────────────────────────
    /**
     * Non-conflicting Master Schedule Matrix:
     * 
     * BSMT-1A:
     *   - BSMT-MT101: T1 (Santos), Room 101, MWF 08:00 - 09:30
     *   - BSMT-MT102: T3 (Gomez),  Room 101, MWF 09:30 - 11:00
     *   - BSMT-MT103: T1 (Santos), Room 101, MWF 13:00 - 14:30
     *   - BSMT-GE101: T5 (Bautista), Room 101, TTh 08:30 - 10:00
     *   - BSMT-GE102: T4 (Dela Cruz), Room 101, TTh 10:00 - 11:30
     * 
     * BSMT-1B:
     *   - BSMT-MT102: T3 (Gomez),  Room 102, MWF 13:00 - 14:30
     *   - BSMT-MT101: T1 (Santos), Room 102, MWF 14:30 - 16:00
     *   - BSMT-GE102: T4 (Dela Cruz), Room 102, TTh 08:30 - 10:00
     *   - BSMT-GE101: T5 (Bautista), Room 102, TTh 10:00 - 11:30
     *   - BSMT-MT103: T1 (Santos), Room 102, TTh 13:00 - 14:30
     * 
     * BSMarE-1A:
     *   - BSMARE-ME101: T2 (Reyes), Room 201, MWF 08:00 - 09:30
     *   - BSMARE-ME102: T2 (Reyes), Room 202, MWF 09:30 - 11:00
     *   - BSMARE-ME103: T3 (Gomez), Room 201, MWF 14:30 - 16:00
     *   - BSMARE-GE101: T5 (Bautista), Room 103, TTh 13:00 - 14:30
     *   - BSMARE-GE102: T4 (Dela Cruz), Room 103, TTh 14:30 - 16:00
     */
    $timetableEntries = [
        // BSMT-1A
        [
            'section_key' => 'BSMT-1A',
            'subject_code' => 'BSMT-MT101',
            'teacher_key' => 'T1',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '08:00:00',
            'end' => '09:30:00',
            'room' => 'Room 101'
        ],
        [
            'section_key' => 'BSMT-1A',
            'subject_code' => 'BSMT-MT102',
            'teacher_key' => 'T3',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '09:30:00',
            'end' => '11:00:00',
            'room' => 'Room 101'
        ],
        [
            'section_key' => 'BSMT-1A',
            'subject_code' => 'BSMT-MT103',
            'teacher_key' => 'T1',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '13:00:00',
            'end' => '14:30:00',
            'room' => 'Room 101'
        ],
        [
            'section_key' => 'BSMT-1A',
            'subject_code' => 'BSMT-GE101',
            'teacher_key' => 'T5',
            'days' => 'Tuesday, Thursday',
            'start' => '08:30:00',
            'end' => '10:00:00',
            'room' => 'Room 101'
        ],
        [
            'section_key' => 'BSMT-1A',
            'subject_code' => 'BSMT-GE102',
            'teacher_key' => 'T4',
            'days' => 'Tuesday, Thursday',
            'start' => '10:00:00',
            'end' => '11:30:00',
            'room' => 'Room 101'
        ],

        // BSMT-1B
        [
            'section_key' => 'BSMT-1B',
            'subject_code' => 'BSMT-MT102',
            'teacher_key' => 'T3',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '13:00:00',
            'end' => '14:30:00',
            'room' => 'Room 102'
        ],
        [
            'section_key' => 'BSMT-1B',
            'subject_code' => 'BSMT-MT101',
            'teacher_key' => 'T1',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '14:30:00',
            'end' => '16:00:00',
            'room' => 'Room 102'
        ],
        [
            'section_key' => 'BSMT-1B',
            'subject_code' => 'BSMT-GE102',
            'teacher_key' => 'T4',
            'days' => 'Tuesday, Thursday',
            'start' => '08:30:00',
            'end' => '10:00:00',
            'room' => 'Room 102'
        ],
        [
            'section_key' => 'BSMT-1B',
            'subject_code' => 'BSMT-GE101',
            'teacher_key' => 'T5',
            'days' => 'Tuesday, Thursday',
            'start' => '10:00:00',
            'end' => '11:30:00',
            'room' => 'Room 102'
        ],
        [
            'section_key' => 'BSMT-1B',
            'subject_code' => 'BSMT-MT103',
            'teacher_key' => 'T1',
            'days' => 'Tuesday, Thursday',
            'start' => '13:00:00',
            'end' => '14:30:00',
            'room' => 'Room 102'
        ],

        // BSMarE-1A
        [
            'section_key' => 'BSMarE-1A',
            'subject_code' => 'BSMARE-ME101',
            'teacher_key' => 'T2',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '08:00:00',
            'end' => '09:30:00',
            'room' => 'Room 201'
        ],
        [
            'section_key' => 'BSMarE-1A',
            'subject_code' => 'BSMARE-ME102',
            'teacher_key' => 'T2',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '09:30:00',
            'end' => '11:00:00',
            'room' => 'Room 202'
        ],
        [
            'section_key' => 'BSMarE-1A',
            'subject_code' => 'BSMARE-ME103',
            'teacher_key' => 'T3',
            'days' => 'Monday, Wednesday, Friday',
            'start' => '14:30:00',
            'end' => '16:00:00',
            'room' => 'Room 201'
        ],
        [
            'section_key' => 'BSMarE-1A',
            'subject_code' => 'BSMARE-GE101',
            'teacher_key' => 'T5',
            'days' => 'Tuesday, Thursday',
            'start' => '13:00:00',
            'end' => '14:30:00',
            'room' => 'Room 103'
        ],
        [
            'section_key' => 'BSMarE-1A',
            'subject_code' => 'BSMARE-GE102',
            'teacher_key' => 'T4',
            'days' => 'Tuesday, Thursday',
            'start' => '14:30:00',
            'end' => '16:00:00',
            'room' => 'Room 103'
        ],
    ];

    $ssInsertStmt = $pdo->prepare(
        "INSERT INTO section_subjects (section_id, subject_id, instructor_id, day_of_week, start_time, end_time, room, created_at)
         VALUES (:section_id, :subject_id, :instructor_id, :day_of_week, :start_time, :end_time, :room, NOW())"
    );

    $ssLookup = []; // "section_key:subject_code" => ss_id
    foreach ($timetableEntries as $entry) {
        $secId = $sectionIds[$entry['section_key']];
        $subjId = $subjectLookup[$entry['subject_code']];
        $instId = $teacherIds[$entry['teacher_key']];

        $ssInsertStmt->execute([
            'section_id' => $secId,
            'subject_id' => $subjId,
            'instructor_id' => $instId,
            'day_of_week' => $entry['days'],
            'start_time' => $entry['start'],
            'end_time' => $entry['end'],
            'room' => $entry['room'],
        ]);
        $newSsId = (int)$pdo->lastInsertId();
        $ssLookup[$entry['section_key'] . ':' . $entry['subject_code']] = $newSsId;
    }
    echo "  [+] Seeded " . count($timetableEntries) . " section-subject timetable rows.\n";

    // ── 7. Students (18 Enrolled + 1 Unenrolled) ─────────────────────────────────
    $studentNames = [
        // BSMT-1A (Students 1 to 6)
        ['first_name' => 'Alexander', 'last_name' => 'Cruz',      'gender' => 'Male',   'program' => 'BSMT',   'section' => 'BSMT-1A'],
        ['first_name' => 'Beatriz',   'last_name' => 'Navarro',   'gender' => 'Female', 'program' => 'BSMT',   'section' => 'BSMT-1A'],
        ['first_name' => 'Christian', 'last_name' => 'Mendoza',   'gender' => 'Male',   'program' => 'BSMT',   'section' => 'BSMT-1A'],
        ['first_name' => 'Danielle',  'last_name' => 'Villanueva','gender' => 'Female', 'program' => 'BSMT',   'section' => 'BSMT-1A'],
        ['first_name' => 'Ethan',     'last_name' => 'Aquino',    'gender' => 'Male',   'program' => 'BSMT',   'section' => 'BSMT-1A'],
        ['first_name' => 'Faith',     'last_name' => 'Del Rosario','gender' => 'Female','program' => 'BSMT',   'section' => 'BSMT-1A'],

        // BSMT-1B (Students 7 to 12)
        ['first_name' => 'Gabriel',   'last_name' => 'Soriano',   'gender' => 'Male',   'program' => 'BSMT',   'section' => 'BSMT-1B'],
        ['first_name' => 'Hannah',    'last_name' => 'Mercado',   'gender' => 'Female', 'program' => 'BSMT',   'section' => 'BSMT-1B'],
        ['first_name' => 'Ian',       'last_name' => 'Castillo',  'gender' => 'Male',   'program' => 'BSMT',   'section' => 'BSMT-1B'],
        ['first_name' => 'Jasmine',   'last_name' => 'Flores',    'gender' => 'Female', 'program' => 'BSMT',   'section' => 'BSMT-1B'],
        ['first_name' => 'Kevin',     'last_name' => 'Salazar',   'gender' => 'Male',   'program' => 'BSMT',   'section' => 'BSMT-1B'],
        ['first_name' => 'Larissa',   'last_name' => 'Valdez',    'gender' => 'Female', 'program' => 'BSMT',   'section' => 'BSMT-1B'],

        // BSMarE-1A (Students 13 to 18)
        ['first_name' => 'Marcus',    'last_name' => 'Tolentino', 'gender' => 'Male',   'program' => 'BSMarE', 'section' => 'BSMarE-1A'],
        ['first_name' => 'Nicole',    'last_name' => 'Ocampo',    'gender' => 'Female', 'program' => 'BSMarE', 'section' => 'BSMarE-1A'],
        ['first_name' => 'Oliver',    'last_name' => 'Pascual',   'gender' => 'Male',   'program' => 'BSMarE', 'section' => 'BSMarE-1A'],
        ['first_name' => 'Patricia',  'last_name' => 'Cortez',    'gender' => 'Female', 'program' => 'BSMarE', 'section' => 'BSMarE-1A'],
        ['first_name' => 'Rafael',    'last_name' => 'Guerrero',  'gender' => 'Male',   'program' => 'BSMarE', 'section' => 'BSMarE-1A'],
        ['first_name' => 'Sophia',    'last_name' => 'Roxas',     'gender' => 'Female', 'program' => 'BSMarE', 'section' => 'BSMarE-1A'],
    ];

    $studentInsertStmt = $pdo->prepare(
        "INSERT INTO students (
            user_id, academic_term_id, first_name, last_name, birthdate, address_street,
            contact_number, program_applying_for, program_code, applicant_type, age,
            year_level, application_status, admission_status, enrollment_status,
            payment_status, outstanding_balance, created_at
        ) VALUES (
            :user_id, :academic_term_id, :first_name, :last_name, '2005-06-15', 'Port Area, Manila',
            '09170000000', :program_name, :program_code, 'New Student', 19,
            '1st Year', 'approved', 'admitted', 'enrolled',
            'fully_paid', 0.00, NOW()
        )"
    );

    $enrollmentInsertStmt = $pdo->prepare(
        "INSERT INTO enrollments (student_id, section_id, academic_term_id, school_year, semester, status, created_at)
         VALUES (:student_id, :section_id, :academic_term_id, :school_year, :semester, 'enrolled', NOW())"
    );

    $paymentInsertStmt = $pdo->prepare(
        "INSERT INTO payments (
            student_id, academic_term_id, amount, payment_method, payment_reference,
            or_number, payment_date, or_status, validated_at, created_at
        ) VALUES (
            :student_id, :academic_term_id, :amount, 'cash', :ref,
            :or_number, '2025-08-10', 'validated', NOW(), NOW()
        )"
    );

    $enrolledStudentsMap = []; // studentIndex => ['student_id', 'user_id', 'username']

    foreach ($studentNames as $idx => $st) {
        $num = $idx + 1;
        $username = "demo_student{$num}";
        $email = "student{$num}@demo.com";

        // Create user
        $userInsertStmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'student',
            'first_name' => $st['first_name'],
            'last_name' => $st['last_name'],
        ]);
        $uId = (int)$pdo->lastInsertId();

        // Create student profile
        $progName = ($st['program'] === 'BSMT') 
            ? 'Bachelor of Science in Marine Transportation'
            : 'Bachelor of Science in Marine Engineering';

        $studentInsertStmt->execute([
            'user_id' => $uId,
            'academic_term_id' => $termId,
            'first_name' => $st['first_name'],
            'last_name' => $st['last_name'],
            'program_name' => $progName,
            'program_code' => $st['program'],
        ]);
        $studentId = (int)$pdo->lastInsertId();

        // Create enrollment record
        $targetSectionId = $sectionIds[$st['section']];
        $enrollmentInsertStmt->execute([
            'student_id' => $studentId,
            'section_id' => $targetSectionId,
            'academic_term_id' => $termId,
            'school_year' => $schoolYear,
            'semester' => $semester,
        ]);

        // Generate full assessment via system fee engine
        $assessmentId = getOrCreateAssessment($pdo, $studentId, $termId);
        $assessmentTotals = getAssessmentTotals($pdo, $assessmentId);
        $totalAmount = (float)($assessmentTotals['total_amount'] ?? 0.00);

        // Create validated payment for the exact full assessment amount
        $paymentInsertStmt->execute([
            'student_id' => $studentId,
            'academic_term_id' => $termId,
            'amount' => $totalAmount,
            'ref' => "DEMO-PAY-{$studentId}",
            'or_number' => "DEMO-OR-2025-" . str_pad($studentId, 4, '0', STR_PAD_LEFT),
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // Allocate payment to each assessment item
        $itemsStmt = $pdo->prepare("SELECT id, amount FROM assessment_items WHERE assessment_id = :aid");
        $itemsStmt->execute(['aid' => $assessmentId]);
        $allocStmt = $pdo->prepare("INSERT INTO payment_allocations (payment_id, assessment_item_id, amount, created_at) VALUES (:pid, :item_id, :amt, NOW())");
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $allocStmt->execute([
                'pid' => $paymentId,
                'item_id' => $item['id'],
                'amt' => $item['amount']
            ]);
        }

        // Mark assessment as paid and finalized
        $pdo->prepare("UPDATE assessments SET status = 'paid', is_finalized = 1, finalized_amount = :amt, finalized_at = NOW(), updated_at = NOW() WHERE id = :aid")
            ->execute(['amt' => $totalAmount, 'aid' => $assessmentId]);

        // Ensure student payment status is fully_paid with 0.00 balance
        $pdo->prepare("UPDATE students SET payment_status = 'fully_paid', outstanding_balance = 0.00 WHERE id = :sid")
            ->execute(['sid' => $studentId]);

        $enrolledStudentsMap[$num] = [
            'student_id' => $studentId,
            'user_id' => $uId,
            'username' => $username,
            'name' => "{$st['first_name']} {$st['last_name']}",
            'section' => $st['section'],
            'program' => $st['program']
        ];
    }
    echo "  [+] Seeded 18 active, enrolled, and fully-paid students.\n";

    // ── 8. Seed 1 Unenrolled Student (For testing 'No schedule yet' empty state) ──
    $userInsertStmt->execute([
        'username' => 'demo_student0',
        'email' => 'unenrolled@demo.com',
        'password_hash' => $passwordHash,
        'role' => 'student',
        'first_name' => 'Mark (Unenrolled)',
        'last_name' => 'Applicant',
    ]);
    $unenrolledUserId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO students (
            user_id, academic_term_id, first_name, last_name, birthdate, address_street,
            contact_number, program_applying_for, program_code, applicant_type, age,
            year_level, application_status, admission_status, enrollment_status,
            payment_status, outstanding_balance, created_at
        ) VALUES (
            :user_id, :academic_term_id, 'Mark (Unenrolled)', 'Applicant', '2005-09-20', 'Tondo, Manila',
            '09180000000', 'Bachelor of Science in Marine Transportation', 'BSMT', 'New Student', 19,
            '1st Year', 'pending', 'pending', 'draft',
            'unpaid', 0.00, NOW()
        )"
    )->execute([
        'user_id' => $unenrolledUserId,
        'academic_term_id' => $termId,
    ]);
    echo "  [+] Seeded 1 Unenrolled Student: demo_student0 (unenrolled@demo.com) for empty schedule testing.\n";

    // ── 9. Storage Setup for Placeholder Upload Files ────────────────────────────
    $baseUploadDir = realpath(__DIR__ . '/../private_uploads');
    if (!$baseUploadDir) {
        $baseUploadDir = __DIR__ . '/../private_uploads';
        @mkdir($baseUploadDir, 0755, true);
        $baseUploadDir = realpath($baseUploadDir);
    }

    $materialsDir = $baseUploadDir . DIRECTORY_SEPARATOR . 'lms_materials';
    if (!is_dir($materialsDir)) {
        @mkdir($materialsDir, 0755, true);
    }
    file_put_contents($materialsDir . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\nDeny from all\n");

    $submissionsDir = $baseUploadDir . DIRECTORY_SEPARATOR . 'lms_assignment_submissions';
    if (!is_dir($submissionsDir)) {
        @mkdir($submissionsDir, 0755, true);
    }
    file_put_contents($submissionsDir . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\nDeny from all\n");

    // Create valid minimal PDF placeholder files
    $minimalPdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";

    $matFile1 = 'demo_mt101_syllabus.pdf';
    $matFile2 = 'demo_stcw_manila_guidelines.pdf';
    $matFile3 = 'demo_vessel_nomenclature.pdf';
    $matFile4 = 'demo_me101_engine_manual.pdf';

    file_put_contents($materialsDir . DIRECTORY_SEPARATOR . $matFile1, $minimalPdfContent);
    file_put_contents($materialsDir . DIRECTORY_SEPARATOR . $matFile2, $minimalPdfContent);
    file_put_contents($materialsDir . DIRECTORY_SEPARATOR . $matFile3, $minimalPdfContent);
    file_put_contents($materialsDir . DIRECTORY_SEPARATOR . $matFile4, $minimalPdfContent);

    // ── 10. LMS Announcements (1 per subject) ───────────────────────────────────
    $announcementInsertStmt = $pdo->prepare(
        "INSERT INTO lms_announcements (section_subject_id, author_id, title, message, published_at, is_published, is_important, created_at)
         VALUES (:section_subject_id, :author_id, :title, :message, NOW(), 1, :is_important, NOW())"
    );

    foreach ($timetableEntries as $entry) {
        $ssKey = $entry['section_key'] . ':' . $entry['subject_code'];
        $ssId = $ssLookup[$ssKey];
        $authorId = $teacherIds[$entry['teacher_key']];

        $announcementInsertStmt->execute([
            'section_subject_id' => $ssId,
            'author_id' => $authorId,
            'title' => "Welcome to {$entry['subject_code']} - Course Orientation",
            'message' => "Welcome to the 1st Semester AY {$schoolYear}! Please review the course syllabus in the Materials tab and take note of the scheduled lecture hours in {$entry['room']}.",
            'is_important' => 1
        ]);
    }
    echo "  [+] Seeded announcements across all active section subjects.\n";

    // ── 11. LMS Materials (For BSMT-1A: MT101 & BSMarE-1A: ME101) ───────────────
    $matInsertStmt = $pdo->prepare(
        "INSERT INTO lms_materials (section_subject_id, title, description, material_type, file_name, file_path, display_order, is_available, created_at)
         VALUES (:ss, :title, :desc, :type, :fname, :fpath, :ord, 1, NOW())"
    );

    $bsmtMt101SsId = $ssLookup['BSMT-1A:BSMT-MT101'];

    $matInsertStmt->execute([
        'ss' => $bsmtMt101SsId,
        'title' => 'Course Syllabus & Maritime Career Pathways',
        'desc' => 'Comprehensive syllabus, grading breakdown, and STCW competency requirements.',
        'type' => 'pdf',
        'fname' => 'MT101_Course_Syllabus.pdf',
        'fpath' => $matFile1,
        'ord' => 1
    ]);

    $matInsertStmt->execute([
        'ss' => $bsmtMt101SsId,
        'title' => 'STCW 2010 Manila Amendments Guidelines',
        'desc' => 'Standard training, certification, and watchkeeping standards for deck cadets.',
        'type' => 'document',
        'fname' => 'STCW_Manila_Guidelines.pdf',
        'fpath' => $matFile2,
        'ord' => 2
    ]);

    $matInsertStmt->execute([
        'ss' => $bsmtMt101SsId,
        'title' => 'Vessel Nomenclature and Terminology Reference',
        'desc' => 'Hull anatomy, bridge layout, and fundamental seamanship terminology.',
        'type' => 'presentation',
        'fname' => 'Vessel_Nomenclature_Deck.pdf',
        'fpath' => $matFile3,
        'ord' => 3
    ]);

    // Also seed a material for BSMarE-1A: ME101
    $bsmareMe101SsId = $ssLookup['BSMarE-1A:BSMARE-ME101'];
    $matInsertStmt->execute([
        'ss' => $bsmareMe101SsId,
        'title' => 'Introduction to Marine Auxiliary Machinery Manual',
        'desc' => 'Overview of pumps, compressors, and diesel propulsion auxiliary systems.',
        'type' => 'pdf',
        'fname' => 'ME101_Engine_Room_Auxiliary.pdf',
        'fpath' => $matFile4,
        'ord' => 1
    ]);
    echo "  [+] Seeded LMS downloadable materials with valid placeholder files.\n";

    // ── 12. LMS Assignments & Submissions ────────────────────────────────────────
    // Assignment 1 (Future due date)
    $assignStmt = $pdo->prepare(
        "INSERT INTO lms_assignments (section_subject_id, assignment_type, title, instructions, due_at, max_score, allow_late_submissions, display_order, is_published, created_at)
         VALUES (:ss, :type, :title, :instr, :due_at, :max_score, :allow_late, :ord, 1, NOW())"
    );

    // Assignment 1: Due in 14 days (Open)
    $assignStmt->execute([
        'ss' => $bsmtMt101SsId,
        'type' => 'assignment',
        'title' => 'Assignment 1: Maritime Safety & Life-Saving Appliances Analysis',
        'instr' => 'Prepare a 2-page report identifying the primary life-saving appliances on merchant ships according to SOLAS Chapter III.',
        'due_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        'max_score' => 50.00,
        'allow_late' => 1,
        'ord' => 1
    ]);
    $assignIdFuture = (int)$pdo->lastInsertId();

    // Assignment 2: Past due date (5 days ago)
    $assignStmt->execute([
        'ss' => $bsmtMt101SsId,
        'type' => 'activity',
        'title' => 'Activity 1: Merchant Vessel Types & Cargo Classification',
        'instr' => 'Summarize the distinguishing characteristics between container ships, bulk carriers, and tankers.',
        'due_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
        'max_score' => 50.00,
        'allow_late' => 0,
        'ord' => 2
    ]);
    $assignIdPast = (int)$pdo->lastInsertId();

    // Student Submissions:
    // Student 1 submits Assignment 2 (Graded)
    $st1Info = $enrolledStudentsMap[1];
    $st1Dir = $submissionsDir . DIRECTORY_SEPARATOR . $st1Info['student_id'];
    if (!is_dir($st1Dir)) {
        @mkdir($st1Dir, 0755, true);
    }
    $st1SubFile = 'sub_assign2_' . bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents($st1Dir . DIRECTORY_SEPARATOR . $st1SubFile, $minimalPdfContent);

    $submInsertStmt = $pdo->prepare(
        "INSERT INTO lms_assignment_submissions (assignment_id, student_id, file_name, file_path, submitted_at, score, feedback, graded_at, created_at)
         VALUES (:aid, :sid, :fname, :fpath, :submitted_at, :score, :feedback, :graded_at, NOW())"
    );

    $submInsertStmt->execute([
        'aid' => $assignIdPast,
        'sid' => $st1Info['student_id'],
        'fname' => 'Alexander_Cruz_Activity1_CargoClassification.pdf',
        'fpath' => $st1Info['student_id'] . '/' . $st1SubFile,
        'submitted_at' => date('Y-m-d H:i:s', strtotime('-6 days')),
        'score' => 48.00,
        'feedback' => 'Excellent work on vessel classification and cargo equipment safety protocols! Very thorough analysis.',
        'graded_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
    ]);

    // Student 2 submits Assignment 1 (Pending Grading)
    $st2Info = $enrolledStudentsMap[2];
    $st2Dir = $submissionsDir . DIRECTORY_SEPARATOR . $st2Info['student_id'];
    if (!is_dir($st2Dir)) {
        @mkdir($st2Dir, 0755, true);
    }
    $st2SubFile = 'sub_assign1_' . bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents($st2Dir . DIRECTORY_SEPARATOR . $st2SubFile, $minimalPdfContent);

    $submInsertStmt->execute([
        'aid' => $assignIdFuture,
        'sid' => $st2Info['student_id'],
        'fname' => 'Beatriz_Navarro_Assignment1_SafetyAppliances.pdf',
        'fpath' => $st2Info['student_id'] . '/' . $st2SubFile,
        'submitted_at' => date('Y-m-d H:i:s', strtotime('-1 days')),
        'score' => null,
        'feedback' => null,
        'graded_at' => null
    ]);
    echo "  [+] Seeded LMS assignments: 1 future due, 1 past due, 1 graded submission, 1 pending submission.\n";

    // ── 13. LMS Quizzes (With Multiple Choice, True/False, Identification) ────────
    $quizInsertStmt = $pdo->prepare(
        "INSERT INTO lms_quizzes (
            section_subject_id, title, instructions, time_limit_minutes,
            allowed_attempts, passing_score, opens_at, closes_at, display_order, is_published, created_at
        ) VALUES (
            :ss, :title, :instr, 30,
            2, 10.00, :opens_at, :closes_at, 1, 1, NOW()
        )"
    );

    $quizInsertStmt->execute([
        'ss' => $bsmtMt101SsId,
        'title' => 'Quiz 1: Fundamentals of Maritime Profession & Safety Standards',
        'instr' => 'Please answer all 3 questions carefully. This quiz tests core international regulations and vessel terminology.',
        'opens_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'closes_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
    ]);
    $quizId = (int)$pdo->lastInsertId();

    $qStmt = $pdo->prepare(
        "INSERT INTO lms_quiz_questions (quiz_id, question_type, prompt, points, correct_answer, display_order, created_at)
         VALUES (:qid, :type, :prompt, :points, :correct_answer, :ord, NOW())"
    );

    $choiceStmt = $pdo->prepare(
        "INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order, created_at)
         VALUES (:qid, :choice, :is_correct, :ord, NOW())"
    );

    // Q1: Multiple Choice
    $qStmt->execute([
        'qid' => $quizId,
        'type' => 'multiple_choice',
        'prompt' => 'Which international convention establishes minimum qualification and certification standards for seafarers globally?',
        'points' => 5.00,
        'correct_answer' => null,
        'ord' => 1
    ]);
    $q1Id = (int)$pdo->lastInsertId();

    $choicesQ1 = [
        ['choice' => 'A. MARPOL 73/78 Convention', 'is_correct' => 0],
        ['choice' => 'B. SOLAS 1974 Convention', 'is_correct' => 0],
        ['choice' => 'C. STCW Convention (as amended)', 'is_correct' => 1],
        ['choice' => 'D. MLC 2006 Maritime Labour Convention', 'is_correct' => 0]
    ];
    $correctChoiceIdQ1 = null;
    foreach ($choicesQ1 as $i => $ch) {
        $choiceStmt->execute([
            'qid' => $q1Id,
            'choice' => $ch['choice'],
            'is_correct' => $ch['is_correct'],
            'ord' => $i + 1
        ]);
        if ($ch['is_correct']) {
            $correctChoiceIdQ1 = (int)$pdo->lastInsertId();
        }
    }

    // Q2: True or False
    $qStmt->execute([
        'qid' => $quizId,
        'type' => 'true_false',
        'prompt' => 'The port side of a vessel refers to the left-hand side when an observer is facing forward toward the bow.',
        'points' => 5.00,
        'correct_answer' => 'true',
        'ord' => 2
    ]);
    $q2Id = (int)$pdo->lastInsertId();

    $choiceStmt->execute(['qid' => $q2Id, 'choice' => 'True', 'is_correct' => 1, 'ord' => 1]);
    $correctChoiceIdQ2 = (int)$pdo->lastInsertId();
    $choiceStmt->execute(['qid' => $q2Id, 'choice' => 'False', 'is_correct' => 0, 'ord' => 2]);

    // Q3: Identification
    $qStmt->execute([
        'qid' => $quizId,
        'type' => 'identification',
        'prompt' => 'What is the nautical term for the forward-most part of a ship or vessel?',
        'points' => 5.00,
        'correct_answer' => 'bow',
        'ord' => 3
    ]);
    $q3Id = (int)$pdo->lastInsertId();

    // Seed 1 completed quiz attempt for Student 1 with perfect score 15.00 / 15.00
    $attemptStmt = $pdo->prepare(
        "INSERT INTO lms_quiz_attempts (quiz_id, student_id, attempt_number, status, started_at, deadline_at, submitted_at, score, total_points, feedback, created_at)
         VALUES (:qid, :sid, 1, 'submitted', :started_at, :deadline_at, :submitted_at, 15.00, 15.00, :feedback, NOW())"
    );

    $startedAt = date('Y-m-d H:i:s', strtotime('-1 days'));
    $deadlineAt = date('Y-m-d H:i:s', strtotime('-1 days +30 minutes'));
    $submittedAt = date('Y-m-d H:i:s', strtotime('-1 days +15 minutes'));

    $attemptStmt->execute([
        'qid' => $quizId,
        'sid' => $st1Info['student_id'],
        'started_at' => $startedAt,
        'deadline_at' => $deadlineAt,
        'submitted_at' => $submittedAt,
        'feedback' => 'Perfect score! Demonstrates mastery of STCW conventions and nautical orientation.'
    ]);
    $attemptId = (int)$pdo->lastInsertId();

    // Store responses for the attempt
    $respStmt = $pdo->prepare(
        "INSERT INTO lms_quiz_responses (attempt_id, question_id, choice_id, answer_text, answered_at)
         VALUES (:attempt_id, :question_id, :choice_id, :answer_text, :answered_at)"
    );

    // Q1 response
    $respStmt->execute([
        'attempt_id' => $attemptId,
        'question_id' => $q1Id,
        'choice_id' => $correctChoiceIdQ1,
        'answer_text' => null,
        'answered_at' => $submittedAt
    ]);

    // Q2 response
    $respStmt->execute([
        'attempt_id' => $attemptId,
        'question_id' => $q2Id,
        'choice_id' => $correctChoiceIdQ2,
        'answer_text' => null,
        'answered_at' => $submittedAt
    ]);

    // Q3 response
    $respStmt->execute([
        'attempt_id' => $attemptId,
        'question_id' => $q3Id,
        'choice_id' => null,
        'answer_text' => 'bow',
        'answered_at' => $submittedAt
    ]);
    echo "  [+] Seeded complete quiz with Multiple Choice, True/False, and Identification questions & student attempt.\n";

    // ── 14. Schedule Conflict Verification Query ────────────────────────────────
    echo "\n=== RUNNING CONFLICT AUDIT ===\n";
    $conflictSql = "
        SELECT 
            a.id AS id1, b.id AS id2,
            a.day_of_week AS days_a, b.day_of_week AS days_b,
            a.start_time AS start_a, a.end_time AS end_a,
            b.start_time AS start_b, b.end_time AS end_b,
            CASE
                WHEN a.instructor_id = b.instructor_id THEN CONCAT('Teacher Conflict (ID: ', a.instructor_id, ')')
                WHEN a.section_id = b.section_id THEN CONCAT('Section Conflict (ID: ', a.section_id, ')')
                WHEN a.room = b.room THEN CONCAT('Room Conflict (', a.room, ')')
            END AS conflict_reason
        FROM section_subjects a
        JOIN section_subjects b ON a.id < b.id
        WHERE (a.instructor_id = b.instructor_id OR a.section_id = b.section_id OR a.room = b.room)
          AND a.day_of_week = b.day_of_week
          AND a.start_time < b.end_time
          AND a.end_time > b.start_time
    ";
    $conflicts = $pdo->query($conflictSql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($conflicts)) {
        echo "  [SUCCESS] 0 schedule conflicts found! All teacher, section, and room assignments are conflict-free.\n";
    } else {
        echo "  [WARNING] Found " . count($conflicts) . " schedule conflict(s):\n";
        print_r($conflicts);
        throw new RuntimeException("Schedule conflict detected in demo timetable.");
    }

    $pdo->commit();
    echo "\n[OK] LMS Demo Data Seeding Completed Successfully!\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR] Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
