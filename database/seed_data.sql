-- ============================================================
-- Seed data - one test account per role + sample courses/sections
-- Import AFTER enrollment_system.sql
--
-- ⚠ SECURITY NOTICE — FOR LOCAL DEVELOPMENT USE ONLY ⚠
-- The password_hash values below are bcrypt hashes of a known weak
-- password. NEVER import this file on a staging or production server.
-- Before any non-local import, regenerate every hash with:
--   php -r "echo password_hash('YOUR_STRONG_PASSWORD', PASSWORD_BCRYPT, ['cost'=>12]);"
-- and replace every '$2b$12$...' value below with a unique strong hash.
-- ============================================================

USE enrollment_system;

INSERT INTO academic_terms (school_year, semester, starts_on, ends_on, is_active) VALUES
('2025-2026', '1st', '2025-08-01', '2026-01-31', 1);

INSERT INTO fee_components (component_name, component_code, description, is_required, sort_order) VALUES
('Admission Fee', 'ADMISSION', 'One-time registration and admission processing fee', 1, 1),
('Tuition Fee', 'TUITION', 'Academic tuition based on enrolled units', 1, 2),
('Laboratory Fee', 'LAB', 'Laboratory and practical course fee', 0, 3),
('Miscellaneous Fee', 'MISC', 'School services and miscellaneous charges', 0, 4);

INSERT INTO payment_methods (method_name, method_code, description, requires_reference, is_active, sort_order) VALUES
('Cash', 'cash', 'Cash payment upon receipt', 0, 1, 1),
('Bank Transfer', 'bank_transfer', 'Manual bank or online transfer', 1, 1, 2),
('GCash', 'gcash', 'GCash or mobile wallet payment', 1, 1, 3),
('Credit Card', 'credit_card', 'Credit or debit card settlement', 1, 1, 4),
('Check', 'check', 'Check payment with reference details', 1, 1, 5);

INSERT INTO fee_configurations (academic_term_id, fee_scope, fee_code, fee_name, calculation_method, amount) VALUES
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'all', 'ADMISSION', 'Admission and registration fee', 'fixed', 5000.00),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'all', 'TUITION', 'Tuition per enrolled unit', 'per_unit', 1500.00);

-- Insert canonical programs
-- (schema.sql already inserts BSMT/BSMarE via INSERT IGNORE — use IGNORE
-- here too so re-running this file, or running it after schema.sql,
-- never errors on the duplicate program_code)
INSERT IGNORE INTO programs (program_code, program_name) VALUES
('BSMarE', 'Bachelor of Science in Marine Engineering'),
('BSMT', 'Bachelor of Science in Marine Transportation');

INSERT INTO users (username, password_hash, role, email) VALUES
('admin1',     '$2b$12$SNqPBq4kayqwbbR/WuelbuIRJ9UeMF7rDOsVyvFccHGv.BoOiROoW', 'admin',     'admin1@school.edu'),
('registrar1', '$2b$12$SNqPBq4kayqwbbR/WuelbuIRJ9UeMF7rDOsVyvFccHGv.BoOiROoW', 'registrar', 'registrar1@school.edu'),
('cashier1',   '$2b$12$SNqPBq4kayqwbbR/WuelbuIRJ9UeMF7rDOsVyvFccHGv.BoOiROoW', 'cashier',   'cashier1@school.edu'),
('teacher1',   '$2b$12$SNqPBq4kayqwbbR/WuelbuIRJ9UeMF7rDOsVyvFccHGv.BoOiROoW', 'teacher',   'teacher1@school.edu'),
('enrollee1',  '$2b$12$SNqPBq4kayqwbbR/WuelbuIRJ9UeMF7rDOsVyvFccHGv.BoOiROoW', 'enrollee',  'enrollee1@school.edu'),
('student1',   '$2b$12$SNqPBq4kayqwbbR/WuelbuIRJ9UeMF7rDOsVyvFccHGv.BoOiROoW', 'student',   'student1@school.edu');

-- Student profile rows (enrollee1 = pending, student1 = already enrolled)
INSERT INTO students (user_id, academic_term_id, first_name, last_name, birthdate, address_street, contact_number, program_applying_for, program_code, application_status, admission_status, enrollment_status) VALUES
((SELECT id FROM users WHERE username = 'enrollee1'), (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'Juan', 'Dela Cruz', '2005-03-14', 'Imus, Cavite', '09171234567', 'BSMarE', 'BSMarE', 'pending', 'pending', 'pending'),
((SELECT id FROM users WHERE username = 'student1'), (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'Maria', 'Santos',   '2004-11-02', 'Dasmariñas, Cavite', '09179876543', 'BSMT', 'BSMT', 'approved', 'admitted', 'enrolled');

-- Sample courses
INSERT INTO courses (course_code, course_name, units) VALUES
('IT101', 'Introduction to Computing', 3),
('IT102', 'Web Development Fundamentals', 3),
('GE101', 'Purposive Communication', 3);

INSERT INTO course_prerequisites (course_id, prerequisite_course_id) VALUES
((SELECT id FROM courses WHERE course_code = 'IT102'), (SELECT id FROM courses WHERE course_code = 'IT101'));

-- Sample sections, assigned to teacher1
INSERT INTO sections (course_id, section_name, academic_term_id, schedule, day_of_week, start_time, end_time, room, capacity, year_level, program, teacher_id) VALUES
((SELECT id FROM courses WHERE course_code = 'IT101'), 'IT10-01A', (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'MWF 8:00-9:00AM', 'Monday, Wednesday, Friday', '08:00:00', '09:00:00', 'Room 201', 40, '1st Year', 'BSMarE', (SELECT id FROM users WHERE username = 'teacher1')),
((SELECT id FROM courses WHERE course_code = 'IT102'), 'IT10-02A', (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'TTH 9:00-10:30AM', 'Tuesday, Thursday', '09:00:00', '10:30:00', 'Room 202', 40, '1st Year', 'BSMarE', (SELECT id FROM users WHERE username = 'teacher1')),
((SELECT id FROM courses WHERE course_code = 'GE101'), 'GE10-01A', (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'MWF 1:00-2:00PM', 'Monday, Wednesday, Friday', '13:00:00', '14:00:00', 'Room 105', 45, '1st Year', 'BSMT', NULL);

-- Sample enrollment for student1
INSERT INTO enrollments (student_id, section_id, academic_term_id, school_year, semester, status) VALUES
((SELECT id FROM students WHERE user_id = (SELECT id FROM users WHERE username = 'student1')),
 (SELECT id FROM sections WHERE room = 'Room 201'),
 (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'),
 '2025-2026', '1st', 'enrolled');

-- Sample payment for student1
INSERT INTO payments (student_id, academic_term_id, amount, or_number, payment_date, cashier_id, notes, or_status, validated_at, validated_by) VALUES
((SELECT id FROM students WHERE user_id = (SELECT id FROM users WHERE username = 'student1')),
 (SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'),
 5000.00, 'OR-2025-0001', '2025-08-01', (SELECT id FROM users WHERE username = 'cashier1'), 'Tuition down payment',
 'validated', '2025-08-01 08:00:00', (SELECT id FROM users WHERE username = 'cashier1'));

-- ------------------------------------------------------------
-- Sample payment terms and discounts for Phase 2 testing
-- ------------------------------------------------------------
INSERT INTO payment_terms (academic_term_id, term_code, term_name, installments, description) VALUES
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'full', 'Full Payment', 1, 'Single full settlement'),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), '2inst', '2 Installments', 2, 'Two equal installments'),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), '4inst', '4 Installments', 4, 'Four equal installments');

INSERT INTO discounts (discount_code, discount_name, discount_type, discount_value) VALUES
('SCHOLAR', 'Scholarship', 'percent', 100.00),
('SIBLING', 'Sibling Discount', 'percent', 10.00),
('OTHER', 'Other Discount', 'fixed', 1000.00);

-- ------------------------------------------------------------
-- Sample fee configurations for BSMarE and BSMT (sample values)
-- ------------------------------------------------------------
INSERT INTO fee_configurations (academic_term_id, program_applying_for, program_code, year_level, fee_scope, fee_code, fee_name, calculation_method, amount, discount_type, discount_value, is_active)
VALUES
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMarE', 'BSMarE', '1st Year', 'program_year', 'TUITION', 'Tuition Fee', 'fixed', 25000.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMarE', 'BSMarE', '1st Year', 'program_year', 'LAB', 'Laboratory Fee', 'fixed', 5000.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMarE', 'BSMarE', '1st Year', 'program_year', 'WSHOP', 'Workshop Fee', 'fixed', 3000.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMarE', 'BSMarE', '1st Year', 'program_year', 'MET', 'Marine Engineering Tools', 'fixed', 2500.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMarE', 'BSMarE', '1st Year', 'program_year', 'MISC', 'Miscellaneous Fees', 'fixed', 4500.00, 'none', 0.00, 1),

((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMT', 'BSMT', '1st Year', 'program_year', 'TUITION', 'Tuition Fee', 'fixed', 22000.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMT', 'BSMT', '1st Year', 'program_year', 'LAB', 'Laboratory Fee', 'fixed', 5000.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMT', 'BSMT', '1st Year', 'program_year', 'WSHOP', 'Workshop Fee', 'fixed', 2500.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMT', 'BSMT', '1st Year', 'program_year', 'NAVT', 'Navigation Tools', 'fixed', 2500.00, 'none', 0.00, 1),
((SELECT id FROM academic_terms WHERE school_year = '2025-2026' AND semester = '1st'), 'BSMT', 'BSMT', '1st Year', 'program_year', 'MISC', 'Miscellaneous Fees', 'fixed', 4000.00, 'none', 0.00, 1);