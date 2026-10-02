-- ============================================================
-- NCST Maritime Academy - Enrollment System
-- Consolidated Database Schema (single source of truth)
--
-- Last consolidated: 2026-08-21
-- Folds in all prior migration files, including:
--   notifications, phase1-5 (academic terms, payments, registration
--   rules, self-service, academic operations), and the 2026-08-21
--   fixes_and_phase2 migration (users.first_name/last_name,
--   curriculums, subjects).
--
-- 2026-08-21 cleanup: removed the duplicate academic_years/semesters
-- tables (and the academic_year_id/semester_id columns they added
-- to sections and enrollments) introduced by the fixes_and_phase2
-- migration. academic_terms remains the single source of truth for
-- school year + semester; it is the table 8 other tables key off of
-- and the one carrying real business logic (payment_requirement_percent,
-- max_units). curriculums/subjects are unaffected since they only use
-- a plain-text effective_year, not a foreign key.
--
-- DB-001 fix (2026-09-14): removed 6 post-CREATE ALTER TABLE statements
-- that caused duplicate column/constraint errors on schema re-import.
-- payment_terms is now defined before fee_configurations so that
-- payment_term_id and program_code (and their FK constraints) can be
-- declared inline in the CREATE TABLE blocks. The resulting table
-- structure is identical to the live database; verified via throwaway
-- test-database idempotency check (fresh import + re-import, both
-- clean; SHOW CREATE TABLE matched live DB exactly).
--
-- Team workflow: this is the ONLY schema file in the database
-- folder. If you change the DB structure, re-export/update this
-- file directly (or via mysqldump --no-data) and OVERWRITE it.
-- Do not add new migration_*.sql files to this folder.
--
-- To set up a fresh database: run this file, then
-- database/seed_data.sql for sample/test accounts.
-- ============================================================

CREATE DATABASE IF NOT EXISTS enrollment_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE enrollment_system;

-- ------------------------------------------------------------
-- academic_terms: configured school year and semester combinations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academic_terms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd','summer') NOT NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    enrollment_starts_on DATE NULL,
    enrollment_ends_on DATE NULL,
    registration_deadline DATE NULL,
    late_registration_deadline DATE NULL,
    is_enrollment_open TINYINT(1) NOT NULL DEFAULT 1,
    payment_requirement_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    downpayment_percentage DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    minimum_downpayment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_units DECIMAL(5,2) NOT NULL DEFAULT 24.00,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_academic_terms_year_semester (school_year, semester),
    KEY idx_academic_terms_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users: login accounts for every role
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('enrollee','student','admin','registrar','cashier','teacher') NOT NULL,
    email VARCHAR(100) NOT NULL,
    first_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NULL,
    profile_picture VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- programs: BSMT and BSMarE only
-- (defined early so fee_configurations and students can FK to program_code inline)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS programs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_code VARCHAR(20) NOT NULL UNIQUE,
    program_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO programs (program_code, program_name) VALUES
('BSMT', 'Bachelor of Science in Marine Transportation'),
('BSMarE', 'Bachelor of Science in Marine Engineering');

-- ------------------------------------------------------------
-- payment_terms: defines available payment plans per academic term
-- (moved before fee_configurations so payment_term_id FK can be declared inline)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_terms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_term_id INT UNSIGNED NULL,
    term_code VARCHAR(50) NOT NULL,
    term_name VARCHAR(100) NOT NULL,
    installments INT NOT NULL DEFAULT 1,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_terms_academic_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_payment_terms_code_term (academic_term_id, term_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- fee_configurations: fee rules per academic term/program/year level
-- (payment_term_id and program_code folded in from former ALTER TABLE statements)
-- Scoping rules:
--   - fee_scope = 'all': program_code and year_level are NULL; fee applies universally to all students in the term.
--   - fee_scope = 'program': applies to all students in that program across any year level (year_level is NULL).
--   - fee_scope = 'year_level': applies to all students in that year level across any program (program_code is NULL).
--   - fee_scope = 'program_year': applies strictly to students matching BOTH program_code AND year_level.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fee_configurations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_term_id INT UNSIGNED NOT NULL,
    program_applying_for VARCHAR(100) NULL,
    program_code VARCHAR(20) NULL,
    year_level VARCHAR(20) NULL,
    fee_scope ENUM('all','program','year_level','program_year') NOT NULL DEFAULT 'all',
    fee_code VARCHAR(40) NOT NULL,
    fee_name VARCHAR(150) NOT NULL,
    calculation_method ENUM('fixed','per_unit') NOT NULL DEFAULT 'fixed',
    amount DECIMAL(10,2) NOT NULL,
    discount_type ENUM('none','fixed','percent') NOT NULL DEFAULT 'none',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_term_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fee_configurations_term (academic_term_id),
    KEY idx_fee_configurations_scope (program_applying_for, year_level),
    KEY idx_fee_configurations_program_code (program_code),
    CONSTRAINT fk_fee_configurations_term
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fee_configurations_payment_term
        FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_fee_configurations_program_code
        FOREIGN KEY (program_code) REFERENCES programs(program_code)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- students: profile and admission/enrollment status
-- A user can have at most one student profile.
-- (program_code folded in from former ALTER TABLE statement)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    academic_term_id INT UNSIGNED NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    suffix VARCHAR(10) NULL,
    birthdate DATE NULL,
    place_of_birth VARCHAR(100) NULL,
    gender ENUM('Male','Female','Other') NULL,
    civil_status ENUM('Single','Married','Widowed','Separated') NULL,
    nationality VARCHAR(50) NULL,
    religion VARCHAR(50) NULL,
    contact_number VARCHAR(20) NULL,
    address_street VARCHAR(100) NULL,
    address_barangay VARCHAR(100) NULL,
    address_city VARCHAR(100) NULL,
    address_province VARCHAR(100) NULL,
    address_zip_code VARCHAR(10) NULL,
    guardian_name VARCHAR(100) NULL,
    guardian_relationship VARCHAR(50) NULL,
    guardian_contact_number VARCHAR(20) NULL,
    guardian_address VARCHAR(255) NULL,
    shs_track_strand VARCHAR(100) NULL,
    shs_name VARCHAR(100) NULL,
    shs_type ENUM('Public','Private') NULL,
    year_graduated INT NULL,
    general_average DECIMAL(5,2) NULL,
    program_applying_for VARCHAR(100) NULL,
    program_code VARCHAR(20) NULL,
    applicant_type ENUM('New Student','Transferee') NULL,
    age TINYINT UNSIGNED NULL,
    year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') NULL,
    ack_submit_without_docs TINYINT(1) NOT NULL DEFAULT 0,
    revision_notes TEXT NULL,
    application_status ENUM('draft','pending','under_review','approved','eligible_to_enroll','rejected','needs_revision') NOT NULL DEFAULT 'draft',
    admission_status ENUM('draft','pending','under_review','approved','needs_revision','rejected','admitted') NOT NULL DEFAULT 'draft',
    enrollment_status ENUM('draft','pending','needs_revision','approved','section_chosen','walk_in_ready','paid','enrolled','rejected') NOT NULL DEFAULT 'draft',
    payment_status ENUM('unpaid','partially_paid','fully_paid') NOT NULL DEFAULT 'unpaid',
    outstanding_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    walk_in_validated_at TIMESTAMP NULL,
    walk_in_validated_by INT UNSIGNED NULL,
    walk_in_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_user_id (user_id),
    KEY idx_students_term (academic_term_id),
    KEY idx_students_application_status (application_status),
    KEY idx_students_admission_status (admission_status),
    KEY idx_students_status (enrollment_status),
    KEY idx_students_name (last_name, first_name),
    KEY idx_students_program_code (program_code),
    CONSTRAINT fk_students_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_students_academic_term
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_students_walk_in_validator
        FOREIGN KEY (walk_in_validated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_students_program_code
        FOREIGN KEY (program_code) REFERENCES programs(program_code)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- courses: subjects offered
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) NOT NULL,
    course_name VARCHAR(100) NOT NULL,
    units INT NOT NULL DEFAULT 3,
    program_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_courses_code (course_code),
    KEY idx_courses_program (program_id),
    CONSTRAINT fk_courses_program
        FOREIGN KEY (program_id) REFERENCES programs(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- course_prerequisites: prerequisite course relationships
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_prerequisites (
    course_id INT UNSIGNED NOT NULL,
    prerequisite_course_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (course_id, prerequisite_course_id),
    CONSTRAINT fk_course_prerequisites_course
        FOREIGN KEY (course_id) REFERENCES courses(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_course_prerequisites_prerequisite
        FOREIGN KEY (prerequisite_course_id) REFERENCES courses(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- curriculums / subjects
-- Added 2026-08-21. Independent of academic_terms — versioning is
-- keyed off the plain-text effective_year on curriculums, not a
-- foreign key, so this feature is unaffected by the academic_terms
-- vs. academic_years/semesters decision below.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS curriculums (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NULL DEFAULT NULL,
    curriculum_name VARCHAR(100) NOT NULL,
    effective_year VARCHAR(20) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_curriculums_program (program_id),
    KEY idx_curriculums_active (is_active),
    CONSTRAINT fk_curriculums_program
        FOREIGN KEY (program_id) REFERENCES programs(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NULL,
    program_id INT UNSIGNED NULL,
    subject_code VARCHAR(20) NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    subject_type VARCHAR(50) NULL DEFAULT 'General Education',
    year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') NULL,
    semester_name ENUM('1st Semester','2nd Semester','Summer') NULL,
    description TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subjects_code_curriculum (curriculum_id, subject_code),
    UNIQUE KEY uq_subjects_code (subject_code),
    KEY idx_subjects_program (program_id),
    KEY idx_subjects_program_year_semester (program_id, year_level, semester_name),
    CONSTRAINT fk_subjects_curriculum
        FOREIGN KEY (curriculum_id) REFERENCES curriculums(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subjects_program
        FOREIGN KEY (program_id) REFERENCES programs(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ------------------------------------------------------------
-- subject_prerequisites: subject-level prerequisite relationships
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subject_prerequisites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NOT NULL,
    prerequisite_subject_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subject_prerequisite (subject_id, prerequisite_subject_id),
    KEY idx_subject_prerequisites_subject (subject_id),
    KEY idx_subject_prerequisites_prerequisite (prerequisite_subject_id),
    CONSTRAINT fk_subject_prerequisites_subject
        FOREIGN KEY (subject_id) REFERENCES subjects(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subject_prerequisites_prerequisite
        FOREIGN KEY (prerequisite_subject_id) REFERENCES subjects(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- discounts: reusable discount records (scholarship, sibling, etc.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS discounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    discount_code VARCHAR(50) NOT NULL UNIQUE,
    discount_name VARCHAR(100) NOT NULL,
    discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- assessments: one generated assessment per student and academic term
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assessments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_term_id INT UNSIGNED NOT NULL,
    reference_number VARCHAR(50) NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    calculated_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    finalized_amount DECIMAL(10,2) NULL DEFAULT NULL,
    is_finalized TINYINT(1) NOT NULL DEFAULT 0,
    finalized_at DATETIME NULL DEFAULT NULL,
    finalized_by INT UNSIGNED NULL DEFAULT NULL,
    finalization_notes TEXT NULL DEFAULT NULL,
    status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assessments_student_term (student_id, academic_term_id),
    UNIQUE KEY uq_assessments_reference_number (reference_number),
    KEY idx_assessments_term_status (academic_term_id, status),
    CONSTRAINT fk_assessments_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assessments_term
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assessments_finalized_by
        FOREIGN KEY (finalized_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- assessment_discounts: record discounts applied to assessments (snapshot)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assessment_discounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT UNSIGNED NOT NULL,
    discount_id INT UNSIGNED NULL,
    discount_name VARCHAR(100) NOT NULL,
    discount_type ENUM('fixed','percent') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    amount_applied DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assessment_discounts_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assessment_discounts_discount FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_components (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component_name VARCHAR(100) NOT NULL,
    component_code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(50) NOT NULL,
    method_code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT NULL,
    requires_reference TINYINT(1) NOT NULL DEFAULT 0,
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- ------------------------------------------------------------
-- assessment_items: itemized generated fees
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assessment_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT UNSIGNED NOT NULL,
    fee_configuration_id INT UNSIGNED NULL,
    description VARCHAR(150) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit_amount DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assessment_items_assessment (assessment_id),
    CONSTRAINT fk_assessment_items_assessment
        FOREIGN KEY (assessment_id) REFERENCES assessments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assessment_items_fee
        FOREIGN KEY (fee_configuration_id) REFERENCES fee_configurations(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- sections: scheduled course offerings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- DB-008: legacy 1:1 course-section link from the old prototype, superseded by section_subjects
    -- for the current block-cohort model; kept nullable rather than dropped to avoid breaking existing
    -- joins/display in legacy seed data and existing consumer queries.
    course_id INT UNSIGNED NULL DEFAULT NULL,
    section_name VARCHAR(50) NULL,
    academic_term_id INT UNSIGNED NULL,
    schedule VARCHAR(100) NOT NULL,
    day_of_week VARCHAR(50) NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    room VARCHAR(50) NULL,
    capacity INT NOT NULL DEFAULT 40,
    year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') NULL,
    program VARCHAR(100) NULL,
    teacher_id INT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    section_type ENUM('M','A') NULL,
    section_number VARCHAR(10) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sections_course (course_id),
    KEY idx_sections_teacher (teacher_id),
    KEY idx_sections_term (academic_term_id),
    KEY idx_sections_status (status),
    CONSTRAINT fk_sections_course
        FOREIGN KEY (course_id) REFERENCES courses(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sections_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sections_academic_term
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- section_subjects: per-section subject schedule assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS section_subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    instructor_id INT UNSIGNED NULL,
    day_of_week VARCHAR(50) NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    room VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_section_subjects_section (section_id),
    KEY idx_section_subjects_subject (subject_id),
    KEY idx_section_subjects_instructor (instructor_id),
    KEY idx_section_subjects_section_day (section_id, day_of_week),
    CONSTRAINT fk_section_subjects_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_section_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_section_subjects_instructor FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- LMS modules and lessons: student-visible content per section subject
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lms_modules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_subject_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_modules_subject_order (section_subject_id, is_published, display_order),
    CONSTRAINT fk_lms_modules_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    content_type ENUM('text','image','presentation','pdf','video','external_link') NOT NULL DEFAULT 'text',
    content_body LONGTEXT NULL,
    content_path VARCHAR(500) NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_lessons_module_order (module_id, is_published, display_order),
    CONSTRAINT fk_lms_lessons_module FOREIGN KEY (module_id) REFERENCES lms_modules(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_lesson_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lms_lesson_progress (lesson_id, student_id),
    KEY idx_lms_lesson_progress_student (student_id, viewed_at),
    CONSTRAINT fk_lms_lesson_progress_lesson FOREIGN KEY (lesson_id) REFERENCES lms_lessons(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_lesson_progress_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_subject_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    material_type ENUM('pdf','presentation','document','image','video','other') NOT NULL DEFAULT 'other',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_available TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_materials_subject_available (section_subject_id, is_available, display_order),
    CONSTRAINT fk_lms_materials_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_material_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    material_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lms_material_view (material_id, student_id),
    KEY idx_lms_material_views_student (student_id, viewed_at),
    CONSTRAINT fk_lms_material_views_material FOREIGN KEY (material_id) REFERENCES lms_materials(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_material_views_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- lms_announcements: instructor messages published per section subject
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lms_announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_subject_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    message LONGTEXT NOT NULL,
    published_at DATETIME NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    is_important TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_announcements_subject_publish (section_subject_id, is_published, published_at),
    CONSTRAINT fk_lms_announcements_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_announcements_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_subject_id INT UNSIGNED NOT NULL,
    assignment_type ENUM('assignment','activity') NOT NULL DEFAULT 'assignment',
    title VARCHAR(150) NOT NULL,
    instructions LONGTEXT NOT NULL,
    due_at DATETIME NOT NULL,
    max_score DECIMAL(8,2) NULL,
    allow_late_submissions TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_assignments_subject_due (section_subject_id, is_published, due_at),
    CONSTRAINT fk_lms_assignments_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_assignment_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lms_assignment_files_assignment (assignment_id),
    CONSTRAINT fk_lms_assignment_files_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_assignment_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    score DECIMAL(8,2) NULL,
    feedback TEXT NULL,
    graded_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lms_assignment_submission (assignment_id, student_id),
    KEY idx_lms_assignment_submissions_student (student_id, submitted_at),
    CONSTRAINT fk_lms_assignment_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES lms_assignments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_assignment_submissions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_quizzes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_subject_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    instructions LONGTEXT NULL,
    time_limit_minutes SMALLINT UNSIGNED NOT NULL,
    allowed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    passing_score DECIMAL(8,2) NULL,
    opens_at DATETIME NULL,
    closes_at DATETIME NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_quizzes_subject_publish (section_subject_id, is_published, opens_at, closes_at),
    CONSTRAINT fk_lms_quizzes_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_quiz_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    question_type ENUM('multiple_choice','true_false','identification') NOT NULL,
    prompt LONGTEXT NOT NULL,
    points DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    correct_answer LONGTEXT NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lms_quiz_questions_order (quiz_id, display_order),
    CONSTRAINT fk_lms_quiz_questions_quiz FOREIGN KEY (quiz_id) REFERENCES lms_quizzes(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_quiz_choices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    choice_text TEXT NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lms_quiz_choices_order (question_id, display_order),
    CONSTRAINT fk_lms_quiz_choices_question FOREIGN KEY (question_id) REFERENCES lms_quiz_questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_quiz_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    status ENUM('in_progress','submitted','interrupted','timed_out') NOT NULL DEFAULT 'in_progress',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    score DECIMAL(10,2) NULL,
    total_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    feedback TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lms_quiz_attempt_number (quiz_id, student_id, attempt_number),
    KEY idx_lms_quiz_attempts_student_status (student_id, quiz_id, status),
    CONSTRAINT fk_lms_quiz_attempts_quiz FOREIGN KEY (quiz_id) REFERENCES lms_quizzes(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_quiz_attempts_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lms_quiz_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    choice_id INT UNSIGNED NULL,
    answer_text LONGTEXT NULL,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lms_quiz_response_question (attempt_id, question_id),
    CONSTRAINT fk_lms_quiz_responses_attempt FOREIGN KEY (attempt_id) REFERENCES lms_quiz_attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_quiz_responses_question FOREIGN KEY (question_id) REFERENCES lms_quiz_questions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lms_quiz_responses_choice FOREIGN KEY (choice_id) REFERENCES lms_quiz_choices(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- enrollments: student registration into a section per term
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    academic_term_id INT UNSIGNED NULL,
    school_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd','summer') NOT NULL,
    status ENUM('pending','approved','paid','enrolled','dropped') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollments_student_section_term (student_id, section_id, school_year, semester),
    KEY idx_enrollments_section_status (section_id, status),
    KEY idx_enrollments_student_status (student_id, status),
    KEY idx_enrollments_term (academic_term_id),
    CONSTRAINT fk_enrollments_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_enrollments_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_enrollments_academic_term
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- section_reservations: temporary section slot reservations for enrollees
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS section_reservations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    status ENUM('active','expired','cancelled','converted') NOT NULL DEFAULT 'active',
    active_status VARCHAR(10) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 'active' ELSE NULL END) VIRTUAL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sec_res_student (student_id),
    KEY idx_sec_res_section_status (section_id, status, expires_at),
    UNIQUE KEY uq_sec_res_student_section_active (student_id, section_id, active_status),
    CONSTRAINT fk_sec_res_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sec_res_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- student_selected_subjects: registrar-reviewed, finalized subject list for a chosen section
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_selected_subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    subject_code VARCHAR(50) NULL,
    subject_name VARCHAR(150) NULL,
    units DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    is_finalized TINYINT(1) NOT NULL DEFAULT 0,
    finalized_at DATETIME NULL,
    finalized_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_selected_subjects (student_id, section_id, subject_id),
    KEY idx_student_selected_subjects_student (student_id, is_finalized),
    KEY idx_student_selected_subjects_section (section_id),
    CONSTRAINT fk_student_selected_subjects_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_selected_subjects_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_selected_subjects_subject
        FOREIGN KEY (subject_id) REFERENCES subjects(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_student_selected_subjects_finalizer
        FOREIGN KEY (finalized_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- transferee_evaluations: previous subject credit evaluations for transferees
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transferee_evaluations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    previous_school VARCHAR(150) NULL,
    previous_subject_code VARCHAR(50) NOT NULL,
    previous_subject_title VARCHAR(150) NOT NULL,
    previous_units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
    previous_grade VARCHAR(10) NULL,
    equivalent_subject_id INT UNSIGNED NULL,
    status ENUM('pending','credited','rejected') NOT NULL DEFAULT 'pending',
    remarks TEXT NULL,
    evaluated_by INT UNSIGNED NULL,
    evaluated_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_transferee_eval_student (student_id),
    KEY idx_transferee_eval_equiv (equivalent_subject_id),
    CONSTRAINT fk_transferee_eval_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transferee_eval_subject FOREIGN KEY (equivalent_subject_id) REFERENCES subjects(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_transferee_eval_evaluator FOREIGN KEY (evaluated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payments: cashier-recorded payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_term_id INT UNSIGNED NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NULL,
    payment_reference VARCHAR(100) NULL,
    bank_name VARCHAR(100) NULL,
    check_number VARCHAR(50) NULL,
    or_number VARCHAR(50) NOT NULL,
    payment_date DATE NOT NULL,
    cashier_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    proof_file VARCHAR(255) NULL,
    issue_date DATE NULL,
    or_status ENUM('pending','validated','voided','rejected') NOT NULL DEFAULT 'pending',
    validated_at TIMESTAMP NULL,
    validated_by INT UNSIGNED NULL,
    validation_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_or_number (or_number),
    KEY idx_payments_student_date (student_id, payment_date),
    KEY idx_payments_cashier (cashier_id),
    KEY idx_payments_term (academic_term_id),
    KEY idx_payments_or_status (or_status),
    CONSTRAINT fk_payments_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payments_cashier
        FOREIGN KEY (cashier_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_academic_term
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_validated_by
        FOREIGN KEY (validated_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payment_allocations: payment amounts applied to assessment items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    assessment_item_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_allocations_payment (payment_id),
    KEY idx_payment_allocations_item (assessment_item_id),
    UNIQUE KEY uq_alloc (payment_id, assessment_item_id),
    CONSTRAINT fk_payment_allocations_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_allocations_item
        FOREIGN KEY (assessment_item_id) REFERENCES assessment_items(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- documents: uploaded enrollee credentials
-- One current document record is retained per student/document type.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    document_type ENUM('form_137','shs_diploma','good_moral','birth_certificate','marriage_certificate','medical_clearance','id_photo') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_documents_student_type (student_id, document_type),
    KEY idx_documents_status (status),
    CONSTRAINT fk_documents_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- notifications: account messages for admission and enrollment updates
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user_read (user_id, is_read),
    KEY idx_notifications_user_created (user_id, created_at),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- application_remarks: registrar comments visible to applicants
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS application_remarks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    remark TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_application_remarks_student (student_id, created_at),
    CONSTRAINT fk_application_remarks_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_application_remarks_author
        FOREIGN KEY (author_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- deleted_items: administrator recovery snapshots
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deleted_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_type VARCHAR(50) NOT NULL,
    original_id INT UNSIGNED NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    deleted_by INT UNSIGNED NOT NULL,
    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    snapshot JSON NOT NULL,
    restored_at TIMESTAMP NULL,
    restored_by INT UNSIGNED NULL,
    KEY idx_deleted_items_active (item_type, original_id, restored_at),
    KEY idx_deleted_items_deleted_by (deleted_by),
    CONSTRAINT fk_deleted_items_deleted_by
        FOREIGN KEY (deleted_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_deleted_items_restored_by
        FOREIGN KEY (restored_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- audit_logs: accountability records for administrative actions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    item_type VARCHAR(50) NOT NULL,
    item_id INT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_logs_actor (actor_id),
    KEY idx_audit_logs_item (item_type, item_id),
    KEY idx_audit_logs_created_at (created_at),
    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- attendance_sessions: class session attendance headers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    academic_term_id INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    topic VARCHAR(255) NULL,
    remarks TEXT NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_session (section_id, session_date, start_time),
    CONSTRAINT fk_attendance_sessions_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_sessions_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id),
    CONSTRAINT fk_attendance_sessions_recorder FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- attendance_records: individual student attendance marks
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_session_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_record (attendance_session_id, student_id),
    CONSTRAINT fk_attendance_records_session FOREIGN KEY (attendance_session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_records_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- grade_submissions: term grade submission headers by section
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grade_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    academic_term_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    status ENUM('draft','submitted','approved','locked') NOT NULL DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grade_submission_section_term (section_id, academic_term_id),
    CONSTRAINT fk_grade_submissions_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_grade_submissions_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id),
    CONSTRAINT fk_grade_submissions_teacher FOREIGN KEY (teacher_id) REFERENCES users(id),
    CONSTRAINT fk_grade_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- student_grades: individual student prelim/midterm/final grades
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_grades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grade_submission_id INT UNSIGNED NOT NULL,
    section_subject_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to section_subjects for per-subject grades; NULL for legacy single-course sections',
    ss_unique_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(section_subject_id, 0)) VIRTUAL COMMENT 'Deterministic expression enabling strict uniqueness for legacy NULL section_subject_id rows',
    enrollment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    prelim_grade DECIMAL(5,2) NULL,
    midterm_grade DECIMAL(5,2) NULL,
    final_exam_grade DECIMAL(5,2) NULL,
    final_grade DECIMAL(5,2) NULL,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sg_submission_enrollment_subject (grade_submission_id, enrollment_id, ss_unique_key),
    CONSTRAINT fk_student_grades_submission FOREIGN KEY (grade_submission_id) REFERENCES grade_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_grades_section_subject FOREIGN KEY (section_subject_id) REFERENCES section_subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_grades_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_grades_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ------------------------------------------------------------
-- login_attempts: IP-based rate limiting records for authentication
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    user_hash VARCHAR(64) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_hash, attempted_at),
    INDEX idx_login_attempts_user_time (user_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- lms_settings: Central configuration for LMS system settings (TASK 4.1)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lms_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    setting_group VARCHAR(32) NOT NULL DEFAULT 'general',
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    KEY idx_lms_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Load optional sample records separately with database/seed_data.sql.
-- End of consolidated schema.
