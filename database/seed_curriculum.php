<?php
/**
 * Database Migration & Seeder for Philippine Maritime Curriculum & Subjects
 * Programs: BSMT and BSMarE
 *
 * IMPORTANT: This script must only be executed from the command line (CLI).
 * It performs destructive DDL operations (ALTER TABLE, DROP FOREIGN KEY) and
 * mass INSERT/UPDATE across multiple tables. Running it via an HTTP request
 * could corrupt live data. Access via the web is blocked here as a code-level
 * safeguard, independent of server/htaccess configuration.
 */

// CLI-only guard — block any HTTP/web execution
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo->beginTransaction();

    echo "--- 1. UPDATING TABLES AND SCHEMAS ---\n";

    // Ensure curriculums table has proper structure
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS curriculums (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            program_id INT UNSIGNED NOT NULL,
            curriculum_name VARCHAR(100) NOT NULL,
            effective_year VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_curriculums_program (program_id),
            KEY idx_curriculums_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Drop legacy course_id FK if exists and make course_id nullable
    try {
        $pdo->exec("ALTER TABLE curriculums DROP FOREIGN KEY fk_curriculums_course");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE curriculums MODIFY course_id INT UNSIGNED NULL DEFAULT NULL");
    } catch (Exception $e) {}

    // Ensure program_id column exists if curriculums was created before
    $hasProgramId = $pdo->query("SHOW COLUMNS FROM curriculums LIKE 'program_id'")->fetch();
    if (!$hasProgramId) {
        $pdo->exec("ALTER TABLE curriculums ADD COLUMN program_id INT UNSIGNED NOT NULL AFTER id, ADD KEY idx_curriculums_program (program_id)");
    }
    $hasIsActive = $pdo->query("SHOW COLUMNS FROM curriculums LIKE 'is_active'")->fetch();
    if (!$hasIsActive) {
        $pdo->exec("ALTER TABLE curriculums ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER effective_year");
    }
    $hasDescription = $pdo->query("SHOW COLUMNS FROM curriculums LIKE 'description'")->fetch();
    if (!$hasDescription) {
        $pdo->exec("ALTER TABLE curriculums ADD COLUMN description TEXT NULL AFTER is_active");
    }

    // Ensure curriculum_subjects table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS curriculum_subjects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            curriculum_id INT UNSIGNED NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') NOT NULL,
            semester ENUM('1st Semester','2nd Semester','Summer') NOT NULL,
            units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT UNSIGNED NOT NULL DEFAULT 1,
            subject_type VARCHAR(50) NULL DEFAULT 'General Education',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_curr_sub_curr (curriculum_id),
            KEY idx_curr_sub_subj (subject_id),
            UNIQUE KEY uq_curr_sub (curriculum_id, subject_id, year_level, semester),
            CONSTRAINT fk_curr_sub_curriculum FOREIGN KEY (curriculum_id) REFERENCES curriculums(id) ON DELETE CASCADE,
            CONSTRAINT fk_curr_sub_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Ensure subject_type column exists on subjects table
    $hasSubjectType = $pdo->query("SHOW COLUMNS FROM subjects LIKE 'subject_type'")->fetch();
    if (!$hasSubjectType) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN subject_type VARCHAR(50) NULL DEFAULT 'General Education' AFTER units");
    }

    // Ensure subjects.program_id is nullable (to allow common general education subjects shared across programs)
    $pdo->exec("ALTER TABLE subjects MODIFY program_id INT UNSIGNED NULL DEFAULT NULL");

    // Ensure unique constraint on subject_code in subjects
    $subjectCodeUnique = $pdo->query("SHOW INDEX FROM subjects WHERE Column_name = 'subject_code' AND Non_unique = 0")->fetch();
    if (!$subjectCodeUnique) {
        // Clear duplicates if any
        $pdo->exec("ALTER TABLE subjects ADD UNIQUE KEY uq_subject_code (subject_code)");
    }

    echo "--- 2. CREATING OR SYNCING PROGRAMS ---\n";
    // Ensure BSMT and BSMarE programs exist
    $pdo->exec("INSERT INTO programs (program_code, program_name) VALUES ('BSMarE', 'Bachelor of Science in Marine Engineering') ON DUPLICATE KEY UPDATE program_name = VALUES(program_name)");
    $pdo->exec("INSERT INTO programs (program_code, program_name) VALUES ('BSMT', 'Bachelor of Science in Marine Transportation') ON DUPLICATE KEY UPDATE program_name = VALUES(program_name)");

    $bsmtId = (int)$pdo->query("SELECT id FROM programs WHERE program_code = 'BSMT'")->fetchColumn();
    $bsmareId = (int)$pdo->query("SELECT id FROM programs WHERE program_code = 'BSMarE'")->fetchColumn();

    echo "BSMT ID: {$bsmtId}, BSMarE ID: {$bsmareId}\n";

    echo "--- 3. CREATING OR SYNCING CURRICULUMS ---\n";
    $currStmt = $pdo->prepare("
        INSERT INTO curriculums (program_id, curriculum_name, effective_year, is_active, description)
        VALUES (?, ?, '2026-2027', 1, ?)
        ON DUPLICATE KEY UPDATE curriculum_name = VALUES(curriculum_name), is_active = 1
    ");

    $currCheck = $pdo->prepare("SELECT id FROM curriculums WHERE program_id = ? AND curriculum_name = ? LIMIT 1");
    
    // BSMT Curriculum
    $currCheck->execute([$bsmtId, 'BSMT - Curriculum 2026-2027']);
    $bsmtCurrId = $currCheck->fetchColumn();
    if (!$bsmtCurrId) {
        $currStmt->execute([$bsmtId, 'BSMT - Curriculum 2026-2027', 'Standard CHED/MARINA aligned Maritime Transportation starter curriculum']);
        $bsmtCurrId = (int)$pdo->lastInsertId();
    }

    // BSMarE Curriculum
    $currCheck->execute([$bsmareId, 'BSMarE - Curriculum 2026-2027']);
    $bsmareCurrId = $currCheck->fetchColumn();
    if (!$bsmareCurrId) {
        $currStmt->execute([$bsmareId, 'BSMarE - Curriculum 2026-2027', 'Standard CHED/MARINA aligned Marine Engineering starter curriculum']);
        $bsmareCurrId = (int)$pdo->lastInsertId();
    }

    echo "BSMT Curriculum ID: {$bsmtCurrId}, BSMarE Curriculum ID: {$bsmareCurrId}\n";

    echo "--- 4. SEEDING MASTER SUBJECTS & CURRICULUM MAPPINGS ---\n";

    // Master subject definition list
    // Code => [Name, Units, Default Type, Description]
    $allMasterSubjects = [
        // Common / General Education / Foundation
        'NGEC101' => ['Understanding the Self', 3.0, 'General Education', 'Exploration of personal identity, psychological and social dimensions of self.'],
        'NGEC102' => ['Readings in Philippine History', 3.0, 'General Education', 'Critical analysis of Philippine history through primary and secondary sources.'],
        'NGEC103' => ['The Contemporary World', 3.0, 'General Education', 'Study of globalization, international economics, politics, and culture.'],
        'NGEC104' => ['Mathematics in the Modern World', 3.0, 'General Education', 'Nature of mathematics and practical quantitative problem solving.'],
        'NGEC105' => ['Purposive Communication', 3.0, 'General Education', 'Communication in various multi-cultural contexts and technical writing.'],
        'NGEC106' => ['Art Appreciation', 3.0, 'General Education', 'Exploration and understanding of humanities, visual arts, and aesthetic expression.'],
        'NGEC107' => ['Science, Technology and Society', 3.0, 'General Education', 'Interaction between scientific advancements, technology, and society.'],
        'NGEC108' => ['Ethics', 3.0, 'General Education', 'Principles of ethical behavior in modern society and professional practice.'],
        'ICT101'  => ['Software Applications and Network Systems Used in Seagoing Ships', 2.0, 'Maritime', 'IT systems, hardware, networking, and marine computer applications.'],
        'PATHFIT101' => ['Movement Competency Training', 2.0, 'Physical Education', 'Fundamental movement patterns and physical fitness conditioning.'],
        'PATHFIT102' => ['Exercise-Based Fitness Activities', 2.0, 'Physical Education', 'Cardiovascular endurance, strength training, and fitness routines.'],
        'PATHFIT201' => ['Outdoor Activities / Basic Swimming', 2.0, 'Physical Education', 'Water survival techniques, strokes, and maritime fitness development.'],
        'NSTP101' => ['National Service Training Program 1', 3.0, 'NSTP', 'Civic welfare, community engagement, and disaster risk management.'],
        'NSTP102' => ['National Service Training Program 2', 3.0, 'NSTP', 'Community development projects and national defense preparedness.'],
        'MARLAW201' => ['Maritime Law', 3.0, 'Maritime', 'Introduction to maritime jurisprudence, admiralty laws, and vessel regulations.'],
        'MARLAW301' => ['International Maritime Law', 3.0, 'Maritime', 'UNCLOS, IMO conventions, SOLAS, STCW, and carriage of goods by sea.'],
        'MARSAF301' => ['Maritime Safety', 3.0, 'Safety', 'Safety management systems, fire prevention, emergency response, and ISM code.'],
        'MGMT201' => ['Leadership and Teamwork', 3.0, 'Management', 'Leadership principles, crew resource management, and conflict resolution.'],
        'MGMT301' => ['Leadership and Teamwork', 3.0, 'Management', 'Advanced leadership, operational management, and engine crew supervision.'],

        // BSMT Specific
        'NAV101'  => ['Navigational Instruments and Compasses', 4.0, 'Navigation', 'Magnetic compasses, gyrocompasses, and navigational instrument maintenance.'],
        'SEAM101' => ['Ships, Ship Routines and Ship Construction', 4.0, 'Seamanship', 'Vessel nomenclature, compartmentalization, deck routines, and shipyard principles.'],
        'NAV102'  => ['Terrestrial and Coastal Navigation I', 4.0, 'Navigation', 'Dead reckoning, position lines, coastal piloting, and navigational charts.'],
        'MET101'  => ['Meteorology and Oceanography', 5.0, 'Maritime', 'Weather systems, atmospheric pressure, ocean currents, and weather routing.'],
        'SEAM102' => ['Trim, Stability and Stress', 3.0, 'Seamanship', 'Hydrostatics, transverse and longitudinal stability, trim calculations, and stress tables.'],
        'MAR101'  => ['Collision Regulations', 3.0, 'Navigation', 'COLREG 72 rules of the road, sound signals, and navigation lights.'],
        'NAV201'  => ['Terrestrial and Coastal Navigation II', 5.0, 'Navigation', 'Advanced passage planning, tidal stream calculations, and coastal navigation.'],
        'NAV202'  => ['Operational Use of RADAR and ARPA', 3.0, 'Navigation', 'Radar plotting, automatic radar plotting aids, target tracking, and anti-collision.'],
        'SEAM201' => ['Cargo Handling and Stowage', 3.0, 'Seamanship', 'Dry cargo stowage, securing procedures, hold inspection, and cargo gear inspection.'],
        'SEAM202' => ['Dangerous Goods and Inspection', 3.0, 'Seamanship', 'IMDG Code, hazardous cargo classes, containment, and chemical hazard response.'],
        'MAR202'  => ['Protection of the Marine Environment', 3.0, 'Maritime', 'MARPOL annexes, ballast water management, and oily water separation.'],
        'MTP201'  => ['Maritime Training Program I', 3.0, 'Practical Training', 'Bridge watchkeeping procedures, mooring operations, and deck practical skills.'],
        'NAV203'  => ['Electronic Navigation', 3.0, 'Navigation', 'GNSS, echo sounders, speed logs, AIS, and integrated navigation systems.'],
        'NAV204'  => ['Celestial Navigation', 3.0, 'Navigation', 'Sextant operation, sight reduction, meridian transit, and celestial position fixing.'],
        'SEAM203' => ['Ship Handling', 3.0, 'Seamanship', 'Pivot points, shallow water effects, bank suction, anchoring, and tug assistance.'],
        'CARGO201'=> ['Cargo Operations', 3.0, 'Seamanship', 'Containerized, ro-ro, bulk, and liquid cargo handling operations.'],
        'GMDSS201'=> ['Global Maritime Distress and Safety System', 3.0, 'Maritime', 'VHF DSC, MF/HF, EPIRB, SART, and emergency distress communications.'],
        'MET201'  => ['Advanced Meteorology', 3.0, 'Maritime', 'Tropical cyclones, optimum weather routing, wave forecasting, and synoptic chart interpretation.'],
        'MTP202'  => ['Maritime Training Program II', 3.0, 'Practical Training', 'Survival craft operations, fast rescue boats, and firefighting practical drills.'],
        'NAV301'  => ['Advanced Navigation', 3.0, 'Navigation', 'Ocean passage planning, blind pilotage, and high latitude navigation.'],
        'NAV302'  => ['Electronic Chart Display and Information System', 3.0, 'Navigation', 'ECDIS sensors, safety contours, ENC route validation, and playback analysis.'],
        'BRM301'  => ['Bridge Resource Management', 3.0, 'Navigation', 'Bridge team organization, situational awareness, workload management, and pilot integration.'],
        'SEAM301' => ['Advanced Seamanship', 3.0, 'Seamanship', 'Heavy weather seamanship, towing operations, and distress rescue operations.'],
        'CARGO301'=> ['Advanced Cargo Operations', 3.0, 'Seamanship', 'Gas and chemical tanker operations, inert gas systems, and crude oil washing.'],
        'STAB301' => ['Advanced Ship Stability', 3.0, 'Seamanship', 'Damage stability, grain stability, free surface effects, and cross-curves of stability.'],
        'MTP301'  => ['Maritime Training Program III', 3.0, 'Practical Training', 'Simulator-based passage planning, bridge watch simulator exercises.'],
        'NAV303'  => ['Advanced Celestial Navigation', 3.0, 'Navigation', 'Planetary and star sights, Polaris latitude determination, and compass errors.'],
        'NAV304'  => ['Ship Maneuvering and Handling', 3.0, 'Navigation', 'Maneuvering in heavy weather, docking, turning circles, and crash stop procedures.'],
        'CARGO302'=> ['Advanced Cargo Handling', 3.0, 'Seamanship', 'Cargo damage prevention, draft surveys, and automated ballast systems.'],
        'GMDSS301'=> ['Advanced GMDSS', 3.0, 'Maritime', 'General Operator Certificate (GOC) standards, satellite communication networks.'],
        'ENV301'  => ['Marine Environmental Protection', 3.0, 'Maritime', 'Green shipping, greenhouse gas emission limits, and environmental audits.'],
        'MTP302'  => ['Maritime Training Program IV', 3.0, 'Practical Training', 'Comprehensive full-mission bridge simulation and evaluation.'],

        // BSMarE Specific
        'MACH101' => ['Hand and Measuring Tools', 2.0, 'Marine Engineering', 'Calipers, micrometers, hand tool safety, and mechanical fabrication basics.'],
        'ELEC101' => ['Basic Electricity', 4.0, 'Electrical', 'Circuit analysis, Ohm\'s law, AC/DC fundamentals, and marine electrical safety.'],
        'EMAT101' => ['Engineering Materials', 4.0, 'Marine Engineering', 'Metals, polymers, heat treatment, tensile strength, and metallurgy.'],
        'ELEC102' => ['Basic Electronics', 3.0, 'Electrical', 'Semiconductors, rectifiers, transistors, and operational amplifiers.'],
        'MACH102' => ['Machining Tools', 2.0, 'Marine Engineering', 'Lathes, milling machines, drill presses, and bench work practices.'],
        'MARENG101'=> ['Maritime English', 3.0, 'Maritime', 'SMCP terminology for marine engineering, logs, and maintenance reports.'],
        'MECH201' => ['Mechanics and Hydromechanics', 4.0, 'Marine Engineering', 'Statics, dynamics, fluid pressure, Bernoulli\'s theorem, and pump hydraulics.'],
        'CHEM201' => ['Industrial Chemistry and Tribology', 3.0, 'Marine Engineering', 'Lubricants, fuel oil analysis, corrosion prevention, and water treatment.'],
        'THERMO201'=> ['Thermodynamics I', 3.0, 'Marine Engineering', 'First and second laws of thermodynamics, gas cycles, and heat transfer.'],
        'PROP201' => ['Main Propulsion I', 4.0, 'Marine Engineering', 'Two-stroke and four-stroke diesel engine operation, components, and timing.'],
        'AUX201'  => ['Auxiliary Machinery I', 4.0, 'Marine Engineering', 'Pumps, air compressors, heat exchangers, and oily water separators.'],
        'EWK201'  => ['Engine Watchkeeping I', 3.0, 'Marine Engineering', 'Engine room watch routines, log keeping, and safety inspections.'],
        'THERMO202'=> ['Thermodynamics II', 3.0, 'Marine Engineering', 'Steam power plants, refrigeration cycles, and psychrometric principles.'],
        'PROP202' => ['Main Propulsion II', 4.0, 'Marine Engineering', 'Fuel injection systems, supercharging, turbochargers, and scavenge systems.'],
        'AUX202'  => ['Auxiliary Machinery II', 4.0, 'Marine Engineering', 'Purifiers, freshwater generators, steering gear, and hydraulic systems.'],
        'ELEC201' => ['Marine Electrical Technology', 3.0, 'Electrical', 'Generators, switchboards, transformers, and electrical distribution systems.'],
        'CTRL201' => ['Basic Control Engineering', 3.0, 'Marine Engineering', 'Pneumatic, hydraulic, and electronic feedback controllers and sensors.'],
        'EWK202'  => ['Engine Watchkeeping II', 3.0, 'Marine Engineering', 'Watchkeeping under adverse conditions, blackout recovery, and alarm procedures.'],
        'ENV201'  => ['Marine Environmental Protection', 3.0, 'Maritime', 'MARPOL Annex VI NOx/SOx compliance, incinerators, and sewage treatment.'],
        'PROP301' => ['Main Propulsion III', 4.0, 'Marine Engineering', 'Electronic common rail engines, dual fuel systems, and emissions monitoring.'],
        'AUX301'  => ['Auxiliary Machinery III', 4.0, 'Marine Engineering', 'Marine boilers, steam turbines, HVAC systems, and deck machinery.'],
        'AUTO301' => ['Marine Automation', 3.0, 'Marine Engineering', 'PLC systems, SCADA, data acquisition, and unmanned machinery space (UMS).' ],
        'ELEC301' => ['Marine Electrical and Electronic Systems', 3.0, 'Electrical', 'High voltage installations, motor control centers, and circuit protection.'],
        'CTRL301' => ['Control Engineering', 3.0, 'Marine Engineering', 'Closed-loop PID tuning, governor systems, and automated valves.'],
        'EWK301'  => ['Engine Watchkeeping III', 3.0, 'Marine Engineering', 'Simulator-based engine watchkeeping, fault diagnosis, and emergency response.'],
        'MAINT301'=> ['Maintenance and Repair I', 3.0, 'Marine Engineering', 'Planned maintenance systems (PMS), overhaul of cylinder heads, pistons, and liners.'],
        'PROP302' => ['Advanced Main Propulsion', 4.0, 'Marine Engineering', 'Propeller geometry, shaft alignment, indicator cards, and engine performance tuning.'],
        'AUX302'  => ['Advanced Auxiliary Machinery', 4.0, 'Marine Engineering', 'Refrigeration plant overhaul, hydraulic steering maintenance, and exhaust gas boilers.'],
        'AUTO302' => ['Marine Automation II', 3.0, 'Marine Engineering', 'Microcontroller architecture, sensor calibration, and network diagnostics.'],
        'EWK302'  => ['Engine Watchkeeping IV', 3.0, 'Marine Engineering', 'Full-mission engine room simulator assessment and chief engineer delegation.'],
        'MAINT302'=> ['Advanced Maintenance and Repair', 3.0, 'Marine Engineering', 'Welding, precision alignment, non-destructive testing (NDT), and drydocking.'],
        'ERM301'  => ['Engine Resource Management', 3.0, 'Marine Engineering', 'Situational awareness, team briefing, stress management, and engine room leadership.'],

        // 4th Year Shipboard Training (Configurable & Shared)
        'OBT401'        => ['On-Board Training / Shipboard Training I', 20.0, 'Shipboard Training', 'Phase 1 of cadet shipboard cadetship and Training Record Book (TRB) compliance.'],
        'OBT402'        => ['On-Board Training / Shipboard Training II', 20.0, 'Shipboard Training', 'Phase 2 of cadet shipboard training, sea service assessment, and oral evaluation.'],
        'SEASERVICE401' => ['Sea Service / Practical Training', 10.0, 'Shipboard Training', 'Supervised sea service documentation and watchkeeping journal completion.'],
        'MARTRAIN401'   => ['Maritime Training Requirements', 10.0, 'Practical Training', 'STCW mandatory training certifications, survival craft, and advanced firefighting.'],
        'ERW401'        => ['Engine Room Watchkeeping Practical', 10.0, 'Shipboard Training', 'Supervised watchkeeping duties in the engine department during sea service.']
    ];

    $subInsert = $pdo->prepare("
        INSERT INTO subjects (subject_code, subject_name, units, subject_type, description, status)
        VALUES (?, ?, ?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE 
            subject_name = VALUES(subject_name),
            units = VALUES(units),
            subject_type = VALUES(subject_type),
            description = VALUES(description),
            status = 'active'
    ");

    foreach ($allMasterSubjects as $code => $info) {
        $subInsert->execute([$code, $info[0], $info[1], $info[2], $info[3]]);
    }

    echo "Master subjects synced (" . count($allMasterSubjects) . " entries).\n";

    // Build subject ID lookup
    $subMap = [];
    $subRows = $pdo->query("SELECT id, subject_code FROM subjects")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subRows as $r) {
        $subMap[$r['subject_code']] = (int)$r['id'];
    }

    // Define BSMT Curriculum structure
    $bsmtCurriculum = [
        '1st Year' => [
            '1st Semester' => ['NGEC101', 'NGEC104', 'NGEC105', 'NGEC107', 'NAV101', 'SEAM101', 'ICT101', 'PATHFIT101', 'NSTP101'],
            '2nd Semester' => ['NGEC102', 'NGEC103', 'NGEC108', 'NAV102', 'MET101', 'SEAM102', 'MAR101', 'PATHFIT102', 'NSTP102'],
        ],
        '2nd Year' => [
            '1st Semester' => ['NGEC106', 'NAV201', 'NAV202', 'SEAM201', 'SEAM202', 'MAR202', 'MGMT201', 'PATHFIT201', 'MTP201'],
            '2nd Semester' => ['NAV203', 'NAV204', 'SEAM203', 'CARGO201', 'MARLAW201', 'GMDSS201', 'MET201', 'MTP202'],
        ],
        '3rd Year' => [
            '1st Semester' => ['NAV301', 'NAV302', 'BRM301', 'SEAM301', 'CARGO301', 'STAB301', 'MARLAW301', 'MTP301'],
            '2nd Semester' => ['NAV303', 'NAV304', 'CARGO302', 'GMDSS301', 'MARSAF301', 'ENV301', 'MTP302'],
        ],
        '4th Year' => [
            '1st Semester' => ['OBT401', 'SEASERVICE401'],
            '2nd Semester' => ['OBT402', 'MARTRAIN401'],
        ]
    ];

    // Define BSMarE Curriculum structure
    $bsmareCurriculum = [
        '1st Year' => [
            '1st Semester' => ['NGEC101', 'NGEC104', 'NGEC105', 'NGEC107', 'MACH101', 'ELEC101', 'ICT101', 'PATHFIT101', 'NSTP101'],
            '2nd Semester' => ['NGEC102', 'NGEC108', 'EMAT101', 'ELEC102', 'MACH102', 'MARENG101', 'PATHFIT102', 'NSTP102'],
        ],
        '2nd Year' => [
            '1st Semester' => ['NGEC103', 'NGEC106', 'MECH201', 'CHEM201', 'THERMO201', 'PROP201', 'AUX201', 'EWK201'],
            '2nd Semester' => ['THERMO202', 'PROP202', 'AUX202', 'ELEC201', 'CTRL201', 'EWK202', 'MARLAW201', 'ENV201'],
        ],
        '3rd Year' => [
            '1st Semester' => ['PROP301', 'AUX301', 'AUTO301', 'ELEC301', 'CTRL301', 'EWK301', 'MAINT301', 'MGMT301'],
            '2nd Semester' => ['PROP302', 'AUX302', 'AUTO302', 'EWK302', 'MAINT302', 'ERM301', 'MARSAF301', 'MARLAW301'],
        ],
        '4th Year' => [
            '1st Semester' => ['OBT401', 'ERW401', 'SEASERVICE401'],
            '2nd Semester' => ['OBT402', 'MARTRAIN401'],
        ]
    ];

    $currSubInsert = $pdo->prepare("
        INSERT INTO curriculum_subjects (curriculum_id, subject_id, year_level, semester, units, is_required, display_order, subject_type)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE 
            units = VALUES(units),
            display_order = VALUES(display_order),
            subject_type = VALUES(subject_type)
    ");

    // Populate BSMT Curriculum Subjects
    echo "--- 5. POPULATING BSMT CURRICULUM SUBJECTS ---\n";
    foreach ($bsmtCurriculum as $year => $semesters) {
        foreach ($semesters as $sem => $codes) {
            $order = 1;
            foreach ($codes as $code) {
                if (!isset($subMap[$code])) continue;
                $subjectId = $subMap[$code];
                $units = $allMasterSubjects[$code][1];
                $type = $allMasterSubjects[$code][2];
                $currSubInsert->execute([$bsmtCurrId, $subjectId, $year, $sem, $units, $order++, $type]);
            }
        }
    }

    // Populate BSMarE Curriculum Subjects
    echo "--- 6. POPULATING BSMarE CURRICULUM SUBJECTS ---\n";
    foreach ($bsmareCurriculum as $year => $semesters) {
        foreach ($semesters as $sem => $codes) {
            $order = 1;
            foreach ($codes as $code) {
                if (!isset($subMap[$code])) continue;
                $subjectId = $subMap[$code];
                $units = $allMasterSubjects[$code][1];
                $type = $allMasterSubjects[$code][2];
                $currSubInsert->execute([$bsmareCurrId, $subjectId, $year, $sem, $units, $order++, $type]);
            }
        }
    }

    echo "--- 7. SEEDING PREREQUISITES ---\n";
    // Define prerequisite pairs: [SubjectCode => [PrerequisiteCodes]]
    $prereqRules = [
        // BSMT Chain
        'NAV102'  => ['NAV101'],
        'NAV201'  => ['NAV102'],
        'NAV202'  => ['NAV102'],
        'NAV203'  => ['NAV201'],
        'NAV204'  => ['NAV201'],
        'NAV301'  => ['NAV201'],
        'NAV302'  => ['NAV203'],
        'NAV303'  => ['NAV204'],
        'NAV304'  => ['SEAM203'],
        'SEAM102' => ['SEAM101'],
        'SEAM201' => ['SEAM102'],
        'SEAM202' => ['SEAM201'],
        'SEAM203' => ['SEAM201'],
        'SEAM301' => ['SEAM203'],
        'CARGO201'=> ['SEAM201'],
        'CARGO301'=> ['CARGO201'],
        'CARGO302'=> ['CARGO301'],
        'GMDSS301'=> ['GMDSS201'],
        'MET201'  => ['MET101'],
        'STAB301' => ['SEAM102'],
        'BRM301'  => ['NAV202'],
        'MTP202'  => ['MTP201'],
        'MTP301'  => ['MTP202'],
        'MTP302'  => ['MTP301'],

        // BSMarE Chain
        'MACH102' => ['MACH101'],
        'ELEC102' => ['ELEC101'],
        'ELEC201' => ['ELEC102'],
        'ELEC301' => ['ELEC201'],
        'THERMO202' => ['THERMO201'],
        'PROP202' => ['PROP201'],
        'PROP301' => ['PROP202'],
        'PROP302' => ['PROP301'],
        'AUX202'  => ['AUX201'],
        'AUX301'  => ['AUX202'],
        'AUX302'  => ['AUX301'],
        'EWK202'  => ['EWK201'],
        'EWK301'  => ['EWK202'],
        'EWK302'  => ['EWK301'],
        'CTRL301' => ['CTRL201'],
        'AUTO302' => ['AUTO301'],
        'MAINT302'=> ['MAINT301'],
        'ERM301'  => ['EWK301'],

        // Common General & PE
        'PATHFIT102' => ['PATHFIT101'],
        'PATHFIT201' => ['PATHFIT102'],
        'NSTP102'    => ['NSTP101'],
        'MARLAW301'  => ['MARLAW201'],

        // 4th Year Shipboard
        'OBT402'     => ['OBT401']
    ];

    $prereqInsert = $pdo->prepare("
        INSERT INTO subject_prerequisites (subject_id, prerequisite_subject_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id)
    ");

    $prereqCount = 0;
    foreach ($prereqRules as $subjCode => $reqCodes) {
        if (!isset($subMap[$subjCode])) continue;
        $sId = $subMap[$subjCode];
        foreach ($reqCodes as $reqCode) {
            if (!isset($subMap[$reqCode])) continue;
            $reqId = $subMap[$reqCode];
            // Ensure no duplicate or self
            if ($sId !== $reqId) {
                $prereqInsert->execute([$sId, $reqId]);
                $prereqCount++;
            }
        }
    }

    echo "Prerequisites synced ({$prereqCount} relationships created/verified).\n";

    // Set default program_id on subjects that are program specific for backward compatibility with existing views
    $pdo->exec("UPDATE subjects s JOIN curriculum_subjects cs ON cs.subject_id = s.id JOIN curriculums c ON c.id = cs.curriculum_id SET s.year_level = cs.year_level, s.semester_name = cs.semester WHERE s.year_level IS NULL OR s.semester_name IS NULL");
    $pdo->exec("UPDATE subjects s JOIN curriculum_subjects cs ON cs.subject_id = s.id JOIN curriculums c ON c.id = cs.curriculum_id SET s.program_id = c.program_id WHERE s.program_id IS NULL");

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    echo "\n=== MIGRATION & SEEDING COMPLETED SUCCESSFULLY! ===\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
