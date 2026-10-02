-- ============================================================
-- Sample Subjects Seed
-- Maritime Academy — BSMT & BSMarE programs
-- All 4 Year Levels × 1st & 2nd Semester
-- Run: mysql -u root enrollment_system < database/seed_subjects.sql
-- ============================================================

USE enrollment_system;

-- ============================================================
-- BSMT — Bachelor of Science in Marine Transportation (program_id = 1)
-- ============================================================

-- 1st Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-GE101',  'Communication Skills 1',                     3.0, 'General Education',  '1st Year', '1st Semester'),
(1, 'BSMT-GE102',  'Mathematics in the Modern World',            3.0, 'General Education',  '1st Year', '1st Semester'),
(1, 'BSMT-GE103',  'Understanding the Self',                     3.0, 'General Education',  '1st Year', '1st Semester'),
(1, 'BSMT-MT101',  'Introduction to Maritime Profession',        3.0, 'Professional',       '1st Year', '1st Semester'),
(1, 'BSMT-MT102',  'Personal Safety and Social Responsibilities',3.0, 'Professional',       '1st Year', '1st Semester'),
(1, 'BSMT-MT103',  'Seamanship 1',                               3.0, 'Professional',       '1st Year', '1st Semester'),
(1, 'BSMT-PE101',  'Physical Education 1',                       2.0, 'Physical Education', '1st Year', '1st Semester'),
(1, 'BSMT-NSTP101','National Service Training Program 1',        3.0, 'NSTP',               '1st Year', '1st Semester');

-- 1st Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-GE104',  'Communication Skills 2',                     3.0, 'General Education',  '1st Year', '2nd Semester'),
(1, 'BSMT-GE105',  'Science Technology and Society',             3.0, 'General Education',  '1st Year', '2nd Semester'),
(1, 'BSMT-GE106',  'Readings in Philippine History',             3.0, 'General Education',  '1st Year', '2nd Semester'),
(1, 'BSMT-MT104',  'Seamanship 2',                               3.0, 'Professional',       '1st Year', '2nd Semester'),
(1, 'BSMT-MT105',  'Navigation 1 Terrestrial Navigation',        3.0, 'Professional',       '1st Year', '2nd Semester'),
(1, 'BSMT-MT106',  'Ship Stability 1',                           3.0, 'Professional',       '1st Year', '2nd Semester'),
(1, 'BSMT-PE102',  'Physical Education 2',                       2.0, 'Physical Education', '1st Year', '2nd Semester'),
(1, 'BSMT-NSTP102','National Service Training Program 2',        3.0, 'NSTP',               '1st Year', '2nd Semester');

-- 2nd Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-GE201',  'The Contemporary World',                     3.0, 'General Education',  '2nd Year', '1st Semester'),
(1, 'BSMT-GE202',  'Ethics and Professional Values',             3.0, 'General Education',  '2nd Year', '1st Semester'),
(1, 'BSMT-MT201',  'Navigation 2 Electronic Navigation',         3.0, 'Professional',       '2nd Year', '1st Semester'),
(1, 'BSMT-MT202',  'Ship Stability 2',                           3.0, 'Professional',       '2nd Year', '1st Semester'),
(1, 'BSMT-MT203',  'Cargo Handling and Stowage 1',               3.0, 'Professional',       '2nd Year', '1st Semester'),
(1, 'BSMT-MT204',  'Watchkeeping Bridge Watch',                  3.0, 'Professional',       '2nd Year', '1st Semester'),
(1, 'BSMT-MT205',  'Ship Meteorology and Oceanography',          3.0, 'Professional',       '2nd Year', '1st Semester'),
(1, 'BSMT-PE201',  'Physical Education 3',                       2.0, 'Physical Education', '2nd Year', '1st Semester');

-- 2nd Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-GE203',  'Purposive Communication',                    3.0, 'General Education',  '2nd Year', '2nd Semester'),
(1, 'BSMT-GE204',  'Art Appreciation',                           3.0, 'General Education',  '2nd Year', '2nd Semester'),
(1, 'BSMT-MT206',  'Navigation 3 Celestial Navigation',          3.0, 'Professional',       '2nd Year', '2nd Semester'),
(1, 'BSMT-MT207',  'Cargo Handling and Stowage 2',               3.0, 'Professional',       '2nd Year', '2nd Semester'),
(1, 'BSMT-MT208',  'Ship Firefighting and Fire Prevention',      3.0, 'Professional',       '2nd Year', '2nd Semester'),
(1, 'BSMT-MT209',  'Survival Craft and Rescue Boats',            3.0, 'Professional',       '2nd Year', '2nd Semester'),
(1, 'BSMT-MT210',  'Ship Management and Leadership',             3.0, 'Professional',       '2nd Year', '2nd Semester'),
(1, 'BSMT-PE202',  'Physical Education 4',                       2.0, 'Physical Education', '2nd Year', '2nd Semester');

-- 3rd Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-MT301',  'Ship Maneuvering and Handling',              3.0, 'Professional',       '3rd Year', '1st Semester'),
(1, 'BSMT-MT302',  'Bridge Resource Management',                 3.0, 'Professional',       '3rd Year', '1st Semester'),
(1, 'BSMT-MT303',  'Global Maritime Distress and Safety System', 3.0, 'Professional',       '3rd Year', '1st Semester'),
(1, 'BSMT-MT304',  'Ship Search and Rescue Operations',          3.0, 'Professional',       '3rd Year', '1st Semester'),
(1, 'BSMT-MT305',  'MARPOL and Environmental Protection',        3.0, 'Professional',       '3rd Year', '1st Semester'),
(1, 'BSMT-MT306',  'International Maritime Law',                 3.0, 'Professional',       '3rd Year', '1st Semester'),
(1, 'BSMT-MT307',  'Ship Business and Administrative Management',3.0, 'Professional',       '3rd Year', '1st Semester');

-- 3rd Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-MT308',  'Ship Safety Management Systems',             3.0, 'Professional',       '3rd Year', '2nd Semester'),
(1, 'BSMT-MT309',  'Radar and Automatic Radar Plotting Aids',    3.0, 'Professional',       '3rd Year', '2nd Semester'),
(1, 'BSMT-MT310',  'Electronic Chart Display and Info System',   3.0, 'Professional',       '3rd Year', '2nd Semester'),
(1, 'BSMT-MT311',  'Medical First Aid',                          3.0, 'Professional',       '3rd Year', '2nd Semester'),
(1, 'BSMT-MT312',  'Ship Stability Advanced',                    3.0, 'Professional',       '3rd Year', '2nd Semester'),
(1, 'BSMT-MT313',  'Maritime English',                           3.0, 'Professional',       '3rd Year', '2nd Semester'),
(1, 'BSMT-MT314',  'Research Methods in Navigation',             3.0, 'Professional',       '3rd Year', '2nd Semester');

-- 4th Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-MT401',  'Shipboard Training Practicum 1',             6.0, 'Practicum',          '4th Year', '1st Semester'),
(1, 'BSMT-MT402',  'Integrated Review for Licensure Exam 1',    3.0, 'Professional',       '4th Year', '1st Semester'),
(1, 'BSMT-MT403',  'Research and Thesis Writing 1',              3.0, 'Research',           '4th Year', '1st Semester'),
(1, 'BSMT-MT404',  'Advanced Watchkeeping',                      3.0, 'Professional',       '4th Year', '1st Semester');

-- 4th Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(1, 'BSMT-MT405',  'Shipboard Training Practicum 2',             6.0, 'Practicum',          '4th Year', '2nd Semester'),
(1, 'BSMT-MT406',  'Integrated Review for Licensure Exam 2',    3.0, 'Professional',       '4th Year', '2nd Semester'),
(1, 'BSMT-MT407',  'Research and Thesis Writing 2',              3.0, 'Research',           '4th Year', '2nd Semester'),
(1, 'BSMT-MT408',  'Port State Control and Flag State Duties',   3.0, 'Professional',       '4th Year', '2nd Semester');


-- ============================================================
-- BSMarE — Bachelor of Science in Marine Engineering (program_id = 2)
-- ============================================================

-- 1st Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-GE101',   'Communication Skills 1',                  3.0, 'General Education',  '1st Year', '1st Semester'),
(2, 'BSMARE-GE102',   'Mathematics 1 Algebra and Trigonometry',  3.0, 'General Education',  '1st Year', '1st Semester'),
(2, 'BSMARE-GE103',   'Physics for Engineers',                   3.0, 'General Education',  '1st Year', '1st Semester'),
(2, 'BSMARE-ME101',   'Introduction to Marine Engineering',      3.0, 'Professional',       '1st Year', '1st Semester'),
(2, 'BSMARE-ME102',   'Engineering Drawing',                     3.0, 'Professional',       '1st Year', '1st Semester'),
(2, 'BSMARE-ME103',   'Personal Safety and Social Responsibilities',3.0,'Professional',     '1st Year', '1st Semester'),
(2, 'BSMARE-PE101',   'Physical Education 1',                    2.0, 'Physical Education', '1st Year', '1st Semester'),
(2, 'BSMARE-NSTP101', 'National Service Training Program 1',     3.0, 'NSTP',               '1st Year', '1st Semester');

-- 1st Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-GE104',   'Communication Skills 2',                  3.0, 'General Education',  '1st Year', '2nd Semester'),
(2, 'BSMARE-GE105',   'Mathematics 2 Calculus',                  3.0, 'General Education',  '1st Year', '2nd Semester'),
(2, 'BSMARE-GE106',   'Chemistry for Engineers',                 3.0, 'General Education',  '1st Year', '2nd Semester'),
(2, 'BSMARE-ME104',   'Marine Auxiliary Machinery 1',            3.0, 'Professional',       '1st Year', '2nd Semester'),
(2, 'BSMARE-ME105',   'Engineering Materials and Metallurgy',    3.0, 'Professional',       '1st Year', '2nd Semester'),
(2, 'BSMARE-ME106',   'Workshop Practice Fitting and Machining', 3.0, 'Professional',       '1st Year', '2nd Semester'),
(2, 'BSMARE-PE102',   'Physical Education 2',                    2.0, 'Physical Education', '1st Year', '2nd Semester'),
(2, 'BSMARE-NSTP102', 'National Service Training Program 2',     3.0, 'NSTP',               '1st Year', '2nd Semester');

-- 2nd Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-GE201',   'Ethics and Professional Values',          3.0, 'General Education',  '2nd Year', '1st Semester'),
(2, 'BSMARE-GE202',   'Mathematics 3 Differential Equations',   3.0, 'General Education',  '2nd Year', '1st Semester'),
(2, 'BSMARE-ME201',   'Thermodynamics 1',                        3.0, 'Professional',       '2nd Year', '1st Semester'),
(2, 'BSMARE-ME202',   'Marine Auxiliary Machinery 2',            3.0, 'Professional',       '2nd Year', '1st Semester'),
(2, 'BSMARE-ME203',   'Fluid Mechanics and Hydraulics',          3.0, 'Professional',       '2nd Year', '1st Semester'),
(2, 'BSMARE-ME204',   'Electrical Technology 1',                 3.0, 'Professional',       '2nd Year', '1st Semester'),
(2, 'BSMARE-ME205',   'Seamanship for Marine Engineers',         3.0, 'Professional',       '2nd Year', '1st Semester'),
(2, 'BSMARE-PE201',   'Physical Education 3',                    2.0, 'Physical Education', '2nd Year', '1st Semester');

-- 2nd Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-GE203',   'Purposive Communication',                 3.0, 'General Education',  '2nd Year', '2nd Semester'),
(2, 'BSMARE-GE204',   'The Contemporary World',                  3.0, 'General Education',  '2nd Year', '2nd Semester'),
(2, 'BSMARE-ME206',   'Thermodynamics 2',                        3.0, 'Professional',       '2nd Year', '2nd Semester'),
(2, 'BSMARE-ME207',   'Marine Main Propulsion Machinery',        3.0, 'Professional',       '2nd Year', '2nd Semester'),
(2, 'BSMARE-ME208',   'Electrical Technology 2',                 3.0, 'Professional',       '2nd Year', '2nd Semester'),
(2, 'BSMARE-ME209',   'Refrigeration and Air Conditioning',      3.0, 'Professional',       '2nd Year', '2nd Semester'),
(2, 'BSMARE-ME210',   'Engineering Watchkeeping',                3.0, 'Professional',       '2nd Year', '2nd Semester'),
(2, 'BSMARE-PE202',   'Physical Education 4',                    2.0, 'Physical Education', '2nd Year', '2nd Semester');

-- 3rd Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-ME301',   'Marine Diesel Engines 1',                 3.0, 'Professional',       '3rd Year', '1st Semester'),
(2, 'BSMARE-ME302',   'Marine Boiler Systems',                   3.0, 'Professional',       '3rd Year', '1st Semester'),
(2, 'BSMARE-ME303',   'Marine Electrical and Electronic Systems',3.0, 'Professional',       '3rd Year', '1st Semester'),
(2, 'BSMARE-ME304',   'Engine Room Resource Management',         3.0, 'Professional',       '3rd Year', '1st Semester'),
(2, 'BSMARE-ME305',   'Ship Firefighting for Engineers',         3.0, 'Professional',       '3rd Year', '1st Semester'),
(2, 'BSMARE-ME306',   'Automation and Control Systems',          3.0, 'Professional',       '3rd Year', '1st Semester'),
(2, 'BSMARE-ME307',   'Marine Environmental Protection',         3.0, 'Professional',       '3rd Year', '1st Semester');

-- 3rd Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-ME308',   'Marine Diesel Engines 2',                 3.0, 'Professional',       '3rd Year', '2nd Semester'),
(2, 'BSMARE-ME309',   'Ship Hull and Stability for Engineers',   3.0, 'Professional',       '3rd Year', '2nd Semester'),
(2, 'BSMARE-ME310',   'Maintenance and Repair of Marine Machinery',3.0,'Professional',      '3rd Year', '2nd Semester'),
(2, 'BSMARE-ME311',   'Medical First Aid for Engineers',         3.0, 'Professional',       '3rd Year', '2nd Semester'),
(2, 'BSMARE-ME312',   'Oily Water Separator and MARPOL',         3.0, 'Professional',       '3rd Year', '2nd Semester'),
(2, 'BSMARE-ME313',   'Maritime Safety Management',              3.0, 'Professional',       '3rd Year', '2nd Semester'),
(2, 'BSMARE-ME314',   'Research Methods in Marine Engineering',  3.0, 'Research',           '3rd Year', '2nd Semester');

-- 4th Year, 1st Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-ME401',   'Engine Room Simulator Training',          6.0, 'Practicum',          '4th Year', '1st Semester'),
(2, 'BSMARE-ME402',   'Integrated Review for Licensure Exam 1', 3.0, 'Professional',       '4th Year', '1st Semester'),
(2, 'BSMARE-ME403',   'Research and Thesis Writing 1',           3.0, 'Research',           '4th Year', '1st Semester'),
(2, 'BSMARE-ME404',   'Advanced Marine Diesel Technology',       3.0, 'Professional',       '4th Year', '1st Semester');

-- 4th Year, 2nd Semester
INSERT IGNORE INTO subjects (program_id, subject_code, subject_name, units, subject_type, year_level, semester_name) VALUES
(2, 'BSMARE-ME405',   'Shipboard Engineering Practicum',         6.0, 'Practicum',          '4th Year', '2nd Semester'),
(2, 'BSMARE-ME406',   'Integrated Review for Licensure Exam 2', 3.0, 'Professional',       '4th Year', '2nd Semester'),
(2, 'BSMARE-ME407',   'Research and Thesis Writing 2',           3.0, 'Research',           '4th Year', '2nd Semester'),
(2, 'BSMARE-ME408',   'Ship Management and Maritime Law',        3.0, 'Professional',       '4th Year', '2nd Semester');

SELECT COUNT(*) AS total_subjects_inserted FROM subjects;
