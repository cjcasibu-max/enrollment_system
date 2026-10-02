# AI Guide — NCST Maritime Academy Enrollment System

This file is a practical guide to the current repository state as it exists in the working tree today. It reflects the implementation in code and schema, not a hypothetical design. The authoritative sources are:

- `database/schema.sql`
- `config/database.php`
- `includes/*.php`
- `actions/*.php`
- `admin/*.php`, `registrar/*.php`, `cashier/*.php`, `student/*.php`, `teacher/*.php`, `enrollee/*.php`

This project is a monolithic PHP + MySQL enrollment system for NCST Maritime Academy. It contains public auth, admission workflow, registrar review, cashier payment validation, student registration, teacher attendance/grade operations, and admin configuration pages.

## Live verification note (current working tree)

The following points were verified directly against the repository code and schema as of 2026-08-22:

- Student academic transcript export is implemented in `student/academic_records.php`. The page loads the logged-in student's academic results, filters to registrar-approved/locked grade submissions, computes cumulative GWA and completed units, and supports both a download HTML export (`?download=1`) and browser print.
- The student dashboard in `student/dashboard.php` and the registrar dashboard in `registrar/dashboard.php` still display hard-coded KPI totals rather than live database counts. These are not dynamically generated from the database today.
- `database/schema.sql` remains the authoritative schema and `academic_terms` remains the active term model. Notes referencing older academic-year/semester migration patterns should be treated as stale unless reintroduced deliberately.
- The live grade flow uses `grade_submissions.status` values including `draft`, `submitted`, and `approved`; any `locked` references are present in the transcript query and legacy notes, but the current approval action in `actions/academic_actions.php` is still written around the `approved` status. This is marked as "Needs verification" before treating it as a current business rule.
- The repository still does not contain a working `application_number` / `student_number` generation flow in the checked-in schema. Any docs describing those fields as implemented should be treated as plan-level notes rather than current live state.

---

## 1. Project architecture

### Stack
- PHP 8+
- MySQL / MariaDB using PDO
- Bootstrap 5 HTML/CSS/JS
- Native PHP sessions for authentication and authorization
- SweetAlert2 for user feedback
- No Composer framework, no Node build step, no modern SPAs; this is a classic server-rendered PHP app

### Core architecture pattern
- Pages are rendered directly from PHP files in role folders.
- Server-side logic is centralized in `actions/*.php`.
- Role guards are enforced with `require_once '../includes/auth_check.php';` and `checkRole([...])`.
- Session state is used heavily for user role, student id, and enrollment status.
- The database uses prepared statements and transactions for critical workflows.

### Important implementation detail
The code is the source of truth. If a document says a field or status exists but the schema and code do not match, the live code wins.

---

## 2. Folder structure and responsibilities

```text
/
├── actions/
│   ├── academic_actions.php
│   ├── academic_term_actions.php
│   ├── auth_actions.php
│   ├── course_actions.php
│   ├── enrollment_actions.php
│   ├── enrollee_actions.php
│   ├── fee_actions.php
│   ├── lms_assignment_actions.php
│   ├── lms_quiz_actions.php
│   ├── notification_actions.php
│   ├── payment_actions.php
│   ├── section_actions.php
│   ├── student_actions.php
│   ├── user_actions.php
│   ├── view_document.php
│   └── view_payment_proof.php
├── admin/
│   ├── academic_terms.php
│   ├── audit_log.php
│   ├── dashboard.php
│   ├── fee_setup.php
│   ├── manage_roles.php
│   ├── manage_users.php
│   └── trash_bin.php
├── assets/
│   ├── css/
│   ├── img/
│   ├── js/
│   └── vendor/
├── auth/
│   ├── login.php
│   ├── logout.php
│   └── register.php
├── cashier/
│   ├── assessment.php
│   ├── dashboard.php
│   ├── enrollment_queue.php
│   ├── payment_history.php
│   ├── payments.php
│   └── receipts.php
├── config/
│   └── database.php
├── database/
│   ├── schema.sql
│   └── seed_data.sql
├── enrollee/
│   ├── apply.php
│   ├── notifications.php
│   ├── payment.php
│   ├── review.php
│   ├── status.php
│   └── welcome.php
├── includes/
│   ├── academic_terms.php
│   ├── assessments.php
│   ├── auth_check.php
│   ├── lms_access.php
│   ├── flash_messages.php
│   ├── footer.php
│   ├── header.php
│   ├── notifications.php
│   ├── number_generator.php
│   ├── registration_rules.php
│   ├── sidebar.php
│   └── ...
├── registrar/
│   ├── courses.php
│   ├── dashboard.php
│   ├── enrollee_applications.php
│   ├── enrollments.php
│   ├── grade_approvals.php
│   ├── sections.php
│   └── students.php
├── student/
│   ├── academic_records.php
│   ├── cor.php
│   ├── dashboard.php
│   ├── enroll.php
│   ├── lms.php
│   ├── lms_course.php
│   ├── lms_assignment_file.php
│   ├── lms_quiz.php
│   ├── lms_lesson_file.php
│   ├── lms_material_file.php
│   ├── my_enrollments.php
│   ├── my_payments.php
│   ├── my_profile.php
│   └── profile_edit.php
├── teacher/
│   ├── attendance.php
│   ├── class_list.php
│   ├── dashboard.php
│   ├── gradebook.php
│   ├── my_classes.php
│   └── my_profile.php
├── uploads/
│   └── student_documents/
├── private_uploads/
│   ├── student_documents/
│   ├── lms_materials/
│   ├── lms_assignment_files/
│   └── lms_assignment_submissions/
├── AI_GUIDE.md
├── CURRENT_SYSTEM and FLOW.md
├── index.php
├── README.md
└── .gitignore
```

### Main module breakdown
- `admin/` — system configuration and records management
- `registrar/` — admissions, course/section management, student/GWA review
- `cashier/` — payment recording and validation
- `student/` — post-approval enrollment and profile pages
- `teacher/` — attendance and gradebook pages
- `enrollee/` — admissions application workflow before student activation
- `auth/` — login and registration flows
- `actions/` — backend handlers for all role operations
- `includes/` — shared utilities, auth logic, validation helpers, navigation, assessment utilities

---

## 3. Authentication and authorization

### Auth model
- Session-based auth using `$_SESSION['user_id']`, `$_SESSION['role']`, and sometimes `$_SESSION['student_id']`.
- Registration creates a `users` row with role `enrollee` and a linked `students` record.
- Login accepts either username or email.
- Login attempts are throttled with session-based tracking.
- Passwords are hashed with `password_hash()` and verified with `password_verify()`.

### Role guard behavior
`includes/auth_check.php` implements:
- `ensureCsrfToken()`
- `validateCsrfToken()`
- `resolveAppUrl()`
- `checkRole(array $allowedRoles)`

This function redirects unauthenticated users to `auth/login.php` and unauthorized users to the relevant dashboard.

### Role set in the live app
`users.role` accepts:
- `admin`
- `registrar`
- `cashier`
- `teacher`
- `student`
- `enrollee`

There is no extra role table in the current codebase. Role logic is enforced in session and per-page access checks.

---

## 4. Current roles and access boundaries

| Role | Primary pages | Current responsibility |
|---|---|---|
| `admin` | `admin/*` | Configures academic terms, fee rules, users, roles UI, audit logs, trash recovery |
| `registrar` | `registrar/*` | Reviews applications, verifies documents, approves/rejects applications, approves section enrollments, grades |
| `cashier` | `cashier/*` | Records and validates OR payments, prints receipts, checks assessment balance |
| `teacher` | `teacher/*` | Views assigned sections, attendance, gradebook submissions |
| `student` | `student/*` | Waits for payment activation, enrolls in sections, views academic records, profile, payments |
| `enrollee` | `enrollee/*` | Applies, uploads docs, edits application drafts, checks status |

### Important role behavior in live code
- `student` access is granted only after a validated payment upgrades the database user role from `enrollee` to `student`.
- `enrollee` users can still view their own status and notifications before activation.
- `teacher` and `student` rights are enforced by role checks in each page.

---

## 5. Database structure

The live schema is consolidated in `database/schema.sql`.

### Core tables
- `users`
- `students`
- `academic_terms`
- `courses`
- `course_prerequisites`
- `curriculums`
- `subjects`
- `fee_configurations`
- `assessments`
- `assessment_items`
- `sections`
- `enrollments`
- `payments`
- `payment_allocations`
- `documents`
- `notifications`
- `application_remarks`
- `deleted_items`
- `audit_logs`
- `attendance_sessions`
- `attendance_records`
- `grade_submissions`
- `student_grades`
- `lms_modules`
- `lms_lessons`
- `lms_materials`
- `lms_assignments`
- `lms_assignment_files`
- `lms_assignment_submissions`
- `lms_quizzes`
- `lms_quiz_questions`
- `lms_quiz_choices`
- `lms_quiz_attempts`
- `lms_quiz_responses`

### Key schema realities
- `academic_terms` is the single source of truth for academic year + term configuration.
- `students` has one row per user and stores current application + enrollment status.
- `sections` are tied to a `course_id` and `academic_term_id` and can be assigned a `teacher_id`.
- `enrollments` are per student/section/term, with status values like `pending`, `enrolled`, `dropped`.
- `payments` record payment data and OR details, with `or_status` values `pending`, `validated`, `voided`, `rejected`, and private `proof_file` upload storage.
- `documents` records uploaded files and verification state.
- `grade_submissions` and `student_grades` power teacher grade submissions and registrar approval.
- LMS materials are linked to `section_subjects`; `lms_materials.is_available` controls student visibility, and downloads are served only after the shared LMS gate and subject-enrollment check pass.
- Student assignments and submissions use `lms_assignments`, `lms_assignment_files`, and `lms_assignment_submissions`; `assignment_type` distinguishes assignments from activities, late submissions are disabled by default, and existing submission rows also back the student Grades view.
- Student quizzes use database-backed attempts and autosaved responses. Time and attempt limits are server-enforced; interrupted attempts are consumed and cannot be resumed. The student Grades view reads their scores from attempts.
- The LMS overall course grade is not separately stored: it reuses Registrar-approved/locked `student_grades` records, which also power `student/academic_records.php`.

### Important status enums in the current schema
`students.application_status`
- `draft`
- `pending`
- `under_review`
- `approved`
- `rejected`
- `needs_revision`

`students.enrollment_status`
- `draft`
- `pending`
- `needs_revision`
- `approved`
- `paid`
- `enrolled`
- `rejected`

### Actual schema notes and mismatches
- The schema has no `application_number` or `student_number` columns on `students`. This is a confirmed gap between the repo docs and the actual schema.
- `database/schema.sql` explicitly removes older duplicate academic-year/semester tables; `academic_terms` is the active model.
- Current code references `needs_revision`, not a planning-only `requires_revision` status.

---

## 6. Admissions workflow as implemented

This is the actual flow the live code enforces.

### 1) Account creation
- User registers at `auth/register.php`.
- Backend is `actions/auth_actions.php`.
- Validation checks:
  - first/last name: letters + spaces
  - contact number: 11-digit Philippine mobile number beginning with `09`
  - username: alphanumeric only, minimum 4 chars
  - email: valid format
  - password: minimum 8 chars, must contain letters and numbers
- New record is inserted into `users` with role `enrollee`.
- A matching row is inserted into `students` with `application_status = 'draft'` and `enrollment_status = 'draft'`.

### 2) Application form
- Applicant enters personal, contact, academic, and document data in `enrollee/apply.php`.
- Server-side validation is heavy and happens in `actions/enrollee_actions.php`.
- Important checks include:
  - applicant age at least 15
  - valid birthdate and graduation month/year
  - valid general average between 70 and 100
  - allowed program values: BSMarE or BSMT
  - allowed applicant type: `New Student` or `Transferee`
  - allowed year levels: `1st Year`..`4th Year`
  - valid gender, civil status, SHS type, religion values

### 3) Draft vs submit
- `submission_mode` is either `review` (save as draft) or `submit` (final submit).
- Drafts may be saved while `application_status` is `draft` or `needs_revision`.
- Final submission updates `students.connection` status to `pending` and sets `enrollment_status = 'pending'`.

### 4) Document upload
- Documents are stored in `private_uploads/student_documents` or `uploads/student_documents` with `.htaccess` protection.
- Supported types:
  - `form_137`
  - `shs_diploma`
  - `good_moral`
  - `birth_certificate`
  - `marriage_certificate` (required only when civil status is `Married`)
  - `medical_clearance`
  - `id_photo`
- MIME validation allows PDF/JPG/PNG; `id_photo` is image-only.
- A document may be `pending`, `verified`, or `rejected`.

### 5) Document and application review
- Registrar opens `registrar/enrollee_applications.php`.
- The document review action updates each `documents.status` to `verified` or `rejected`.
- Application approval requires required documents to be verified unless the applicant acknowledged submission without documents.
- `request_edits` sets `application_status = 'needs_revision'` and `enrollment_status = 'needs_revision'` with a remark.
- If the application is rejected, both statuses are set to `rejected`.

### 6) Approval and payment activation
- Registrar approval sets `application_status = 'eligible_to_enroll'`, `admission_status = 'approved'`, and initially `enrollment_status = 'pending'`; walk-in validation can advance enrollment to `walk_in_ready` before payment.
- The user remains `enrollee` until payment validation upgrades the user row to `student`.
- Cashier-recorded payments are inserted as validated; student-submitted ORs are validated from Payment History.
- Student access activates after validated payments meet the term's minimum/percentage downpayment, capped at the assessment total. An eligible enrollee's `users.role` changes to `student`.

---

## 7. Payment and assessment workflow

### Fee configuration
- Admin configures fee rules in `admin/fee_setup.php`.
- Fee rules are stored in `fee_configurations` and linked to:
  - `academic_term_id`
  - `program_applying_for`
  - `year_level`
- Calculation methods: `fixed` and `per_unit`.

### Assessment generation
`includes/assessments.php` creates and recalculates student assessments per academic term.

Important logic:
- One assessment per student + academic term.
- The total is built from active fee rules.
- `assessment_items` are created from fee rules.
- `payment_allocations` allocate validated payments to the assessment items.

### Cashier flow
- The cashier records payments from the Enrollment Queue via the 'Pay' button (`cashier/payments.php?student_id=...`), posting action `create` to `actions/payment_actions.php`. It transactionally inserts the payment with `or_status = 'validated'`, allocates it to assessment items, updates the assessment and student payment summary, then commits before showing the receipt.
- Student-submitted payments are inserted with `or_status = 'pending'`; the cashier reviews and validates or rejects those online submissions from the Enrollment Queue review modal. `cashier/payment_history.php` is a view-only searchable audit trail. Validation, assessment updates, threshold checks, and any student-role promotion are handled in one transaction.
- OR numbers must be unique. Failed allocation, status updates, or required role promotion must roll back the payment transaction.
- Direct cashier recording sets `payment_status` to `partially_paid` or `fully_paid` and advances eligible applicants to `enrollment_status = 'paid'`; an already enrolled student's status remains `enrolled`.
- Student access activation is gated by the configured minimum/percentage downpayment, capped at the assessment total.

### Business rules in code
- Payment validation itself is not enough for student access; the validated total must meet the configured downpayment requirement.
- `getAssessmentTotals()` computes the outstanding balance using `validated_paid`.
- Student section enrollment is blocked until `enrollment_status` is `paid`.

---

## 8. Enrollment workflow

The actual section enrollment flow is implemented in `actions/enrollment_actions.php` and `student/enroll.php`.

### Eligibility
A student may enroll only if:
- `students.enrollment_status = 'paid'`
- the student is linked to the active `academic_terms` row
- payment validation has been completed for the active term

### Enrollment checks
The code validates:
- at least one section selected
- sections belong to the active term
- no duplicate course selections within the same request
- no overlapping schedules
- no existing conflict with the student’s active registrations
- max-unit load cannot exceed `academic_terms.max_units`
- prerequisite courses must already be completed with an approved/locked grade
- section capacity is checked before insertion

### Enrollment lifecycle
- Student submits sections with `status = 'pending'`
- Registrar approves each enrollment to `status = 'enrolled'`
- Rejected or dropped enrollments are set to `dropped`
- Student `students.enrollment_status` is advanced to `enrolled` when the first enrollment is approved while the student had `paid`

---

## 9. Course, curriculum, prerequisites, and sections

### Course model
- `courses` contains `course_code`, `course_name`, `units`
- `course_prerequisites` defines prerequisite relationships between courses

### Curriculum model
- `curriculums` stores curriculum metadata keyed by `course_id` and `effective_year`
- `subjects` are curriculum-defined and include semester and year-level metadata

### Section model
- `sections` links to a course and term, with `schedule`, `room`, `capacity`, and `teacher_id`
- `teacher_id` points at `users.id`

### Registrar functions
`registrar/courses.php` and `registrar/sections.php` are the main administration pages for course and section records.

---

## 10. Academic operations: attendance and grades

### Attendance
Tables:
- `attendance_sessions`
- `attendance_records`

Teacher pages:
- `teacher/attendance.php`
- `teacher/my_classes.php`
- `teacher/class_list.php`

### Gradebook
Tables:
- `grade_submissions`
- `student_grades`

These support teacher draft grading and registrar approval/locking.

### Registrar grade approval
- `actions/academic_actions.php` handles grade submission approval.
- `registrar/grade_approvals.php` shows submitted grade records and allows approval.
- `student/academic_records.php` displays approved grade history.

---

## 11. Notifications, remarks, and audit trail

### Notifications
- Stored in `notifications`
- Created through `includes/notifications.php`
- Used for application updates, registrar remarks, payment validation, and registration decisions

### Application remarks
- `application_remarks` stores registrar comments per applicant
- Comments are shown on the applicant status page and can trigger notifications

### Audit logs
- `audit_logs` tracks administrative actions through the app
- `admin/audit_log.php` exposes the log

---

## 12. Coding conventions and safe-edit practices

### Safe patterns already used in the repo
- All protected pages call `checkRole([...])`
- All mutations happen in `actions/*.php` or equivalent endpoints
- Prepared statements are used for SQL queries
- Transactions are used around multi-step updates like application approval and payment validation
- Session flash messages are used for user feedback

### High-risk areas to avoid changing casually
- `includes/auth_check.php` — access control logic
- `actions/enrollee_actions.php` — application validation and status transitions
- `actions/payment_actions.php` — payment validation and student activation
- `actions/enrollment_actions.php` — section selection and enrollment rules
- `includes/assessments.php` — financial calculations and payment allocation logic
- `database/schema.sql` — schema changes affect many workflows and should be done deliberately

### Business rules worth preserving
- `academic_terms` is the active term model.
- `students.enrollment_status` must be `paid` before self-enrollment is allowed.
- Payment validation controls student activation.
- Document verification is required for application approval unless the applicant explicitly acknowledges submitting without docs.
- Registrar edits are the branch that sets `needs_revision`.

---

## 13. Important gaps and verification notes

### Confirmed inconsistencies / repo gaps
- The older planning docs mention `requires_revision`, but the live code and schema use `needs_revision`.
- `includes/number_generator.php` and older planning notes reference application/student numbers, but the current schema does not contain `application_number` / `student_number` columns.
- Some legacy migration references found in older docs are no longer relevant because the code has already consolidated to `academic_terms`.

### Areas that still need verification if the project evolves
- Whether `manage_roles.php` is a true role-management feature or only a placeholder UI.
- Whether `teacher/my_profile.php` is intentionally stubbed or incomplete.
- Whether there are formal “void payment” or “payment reversal” workflows beyond current validation behavior.
- Whether application number generation should be reintroduced as a DB field and UI feature.

---

## 14. Bottom line

The current project is a working PHP enrollment system with a clear and enforced application -> approval -> payment -> enrollment flow. The code uses `students` as the central applicant record, `academic_terms` as the term authority, and `payments` + `assessments` to gate student activation. The most important thing for future AI agents is to treat the schema and code as the source of truth and not rely on older planning notes or stale documentation.
6. Registrar checks documents in `registrar/enrollee_applications.php` and marks them `verified` or `rejected`.
7. Registrar may approve, reject, or request edits.
8. If edits are requested, the app sets `application_status = 'needs_revision'` and `enrollment_status = 'needs_revision'`.
9. Once admission is approved, the enrollee chooses a section in `enrollee/sections.php` (`enrollment_status = 'section_chosen'`).
10. Enrollee visits campus for walk-in review where Registrar verifies documents, reviews subjects, and finalizes tuition assessment (`enrollment_status = 'walk_in_ready'`).
11. Enrollee submits an online payment (with receipt proof uploaded to private storage, `or_status = 'pending'`) or pays in-person at the cashier (`or_status = 'validated'`).
12. Cashier reviews and validates payments in `cashier/enrollment_queue.php`. Validated payments meeting the required downpayment (`max(minimum_downpayment, downpayment_percentage × assessment total)`) upgrade the user role to `student` and `enrollment_status` to `paid`. If rejected, cashier provides a reason, status becomes `rejected`, allocations are kept for audit trail, and student is notified to resubmit.
13. Registrar performs final review and approves the paid walk-in in `registrar/walk_in.php` (`approvePaidWalkIn`), moving the student to `enrolled` and granting LMS access.

---

## 7. Admission workflow details

### Enrollee application
`enrollee/apply.php` captures:
- Personal data (`first_name`, `middle_name`, `last_name`, `suffix`, `birthdate`, `gender`, `civil_status`, etc.)
- Address/contact/guardian fields
- SHS background and general average
- Program choice and year level
- Required document uploads

The action handler is `actions/enrollee_actions.php`.

### Required document checks
The live code validates these document types:
- `form_137`
- `shs_diploma`
- `good_moral`
- `birth_certificate`
- `medical_clearance`
- `id_photo`
- `marriage_certificate` (only if `civil_status = 'Married'`)

Each uploaded file is stored under `uploads/student_documents/` and recorded in the `documents` table.

### Revision request workflow
The intended project naming for this state is `requires_revision`, and that wording is used in the project notes and planning docs. The repository schema has not yet added the corresponding field(s), and the live application logic currently checks `needs_revision` in several places.

Actual logic in the checked-in code:
- Registrar action `request_edits` calls:
  - `UPDATE students SET application_status = 'needs_revision', enrollment_status = 'needs_revision' WHERE ...`
- `enrollee/apply.php` is treated as editable again when the record is `draft` or `needs_revision`.
- Status page logic in `enrollee/status.php` reflects the same pattern.

This remains a live inconsistency between the project notes and the implemented codebase, and it should be treated as a required follow-up before finalizing the system’s canonical status vocabulary.

---

## 8. Payment and activation flow

The actual payment flow is cashier-driven and recorded against a student assessment.

### Payment flow in code
- `admin/fee_setup.php` and related fee configuration pages define `fee_configurations` for the active academic term.
- `includes/assessments.php` generates per-student assessments and recalculates totals.
- `cashier/payments.php` collects student, amount, and OR number.
- `actions/payment_actions.php` inserts payment rows, allocates amounts, and validates them.
- `or_status` goes from `pending` to `validated` and may be set to `voided` in another flow.

### Activation logic
A student becomes `paid` only after:
- the application is registrar-approved (`eligible_to_enroll`; legacy `approved` is also accepted)
- the enrollment stage is payable (`walk_in_ready`, `section_chosen`, or the applicable paid/enrolled state)
- validated payments meet the configured minimum/percentage downpayment for the student's academic term

Then the code updates:
- `students.enrollment_status = 'paid'`
- `users.role = 'student'` if the current role is `enrollee`

This is the actual behavior shown in `actions/payment_actions.php`.

---

## 9. Student enrollment flow

The actual student enrollment logic is in `actions/enrollment_actions.php` and `student/enroll.php`.

### Eligibility gate
A student can submit class selections only if:
- `students.enrollment_status = 'paid'`
- the student is assigned to the active academic term
- the active academic term exists

### Enrollment rules enforced in code
- duplicate section selections are rejected
- overlapping schedules are rejected
- existing registrations in the same term are checked for conflicts
- maximum units are enforced against `academic_terms.max_units`
- prerequisite course checks are enforced using `course_prerequisites`
- section capacity is enforced with the current count of active enrollments

### Registrar approval
The registrar can approve or reject pending enrollment rows. Approved rows become `enrolled` and may also advance a `paid` student to `enrolled` status if appropriate.

---

## 10. Academic operations in the current repo

The project includes more operational modules than the older guide described.

### Admin academic setup
The actual working admin screens are:
- `admin/academic_terms.php`
- `admin/fee_setup.php`

The schema also includes `curriculums` and `subjects`, but these are not reflected as a full set of `admin/curriculum.php` / `admin/subjects.php` screens in the current repo tree.

### Teacher features
The current repo includes real support for:
- `teacher/my_classes.php`
- `teacher/class_list.php`
- `teacher/attendance.php`
- `teacher/gradebook.php`

Related tables in the schema:
- `attendance_sessions`
- `attendance_records`
- `grade_submissions`
- `student_grades`

### Student academic-tracking features
- `student/academic_records.php`
- `student/cor.php`
- `student/my_enrollments.php`
- `student/my_payments.php`

These are implemented and reflect the actual academic operations in the codebase.

---

## 11. Business rules and gotchas to keep in mind

### 11.1 Role sync is dynamic
`includes/auth_check.php` re-reads the `users` table and updates `$_SESSION['role']` on access. This prevents stale role data when an enrollee is promoted to student after payment validation.

### 11.2 `application_status` and `enrollment_status` are separate
The code treats these as two different tracks:
- application review status
- enrollment/payment status

The same student may be `approved` for the application while remaining `approved` or `paid` in the enrollment track depending on the payment stage.

### 11.3 Revision flow naming is intentionally split between project docs and implementation
The project notes and earlier planning language use `requires_revision`, while the checked-in code currently enforces `needs_revision`. This is a real gap in the repository’s intended terminology.

### 11.4 The project is partially ahead of the checked-in schema
The code references generated application/student numbers and a more advanced revision flow, but the schema file does not consistently define these columns. This may mean the schema is stale, or the live DB has columns added manually outside the repository.

### 11.5 The app is not fully “idealized” flow-doc compliant
Files like `CUREENT_SYSTEM and FLOW.md` are useful for process understanding, but some planning language in earlier docs is not the same as the actual repo state.

---

## 12. Documentation contradictions and unresolved questions

These are the confirmed implementation gaps and naming notes for this repo:

1. `application_number` and `student_number` are referenced in the code and number generator, but they are not yet added to the repository schema.
2. The project note and intended naming convention is `requires_revision`.
3. The project notes file name is `CUREENT_SYSTEM and FLOW.md` and should be treated as the companion documentation file for process context.

These are not theoretical issues; they are observable differences between the documentation and the live implementation.

---

## 13. Recommended reading sequence

1. Read `database/schema.sql` for the structural truth.
2. Read `actions/enrollee_actions.php`, `actions/payment_actions.php`, and `actions/enrollment_actions.php` for live business rules.
3. Read `includes/auth_check.php` and `includes/sidebar.php` for role access logic.
4. Use `CURRENT_SYSTEM and FLOW.md` as a process-level companion, not as a replacement for the schema and action files.

This approach keeps the documentation grounded in what the code actually does today.

`index.php` must no longer immediately redirect to the login page for guests. It becomes a
real public homepage:

- **If a session already exists** (`$_SESSION['user_id']` set) → redirect straight to that
  role's dashboard, same as before.
- **If no session exists** → render a homepage with:
  - Hero section: school name/branding, a short welcome tagline, and two clear buttons —
    **"Portal Login"** (→ `auth/login.php`) and **"Apply / Register"**
    (→ `auth/register.php`)
  - An "About NCST Maritime Academy" section — placeholder descriptive text about the
    school (programs offered, mission, etc. — use reasonable placeholder copy since real
    copy will come from the user; mark clearly as placeholder text to replace)
  - Optional sections: featured programs, contact info / location, footer
  - **No CDN/stock images.** Leave clearly marked placeholder `<img>` tags pointing to
    `assets/img/` (e.g. `assets/img/hero-placeholder.jpg`, `assets/img/campus-placeholder.jpg`)
    with descriptive `alt` text, so the user can drop in their own photos later. Do not pull
    images from any external URL.
  - Use the teal brand palette (Section 1a) for the hero background/buttons.
- This page uses `includes/header.php` / `footer.php` like any other page, but pass a flag
  (e.g. `$no_sidebar = true;` and a `$public_page = true;` if header.php needs to skip the
  authenticated navbar/sidebar entirely for guests) so guests don't see a broken sidebar
  with no session.
- If Admin has not yet opened an enrollment period (Section 5e), the "Apply / Register"
  button should still work (registration is always open) but `enrollee/apply.php` should
  show a friendly notice that applications aren't being accepted yet, instead of a broken
  form.

## 4c. Document Uploads (mandatory enrollment credentials)

The application must let an enrollee upload the following documents as part of
`enrollee/apply.php` (or a follow-up sub-step of the same page if one long form gets
unwieldy — agent's judgment, but keep it in the enrollee flow, not a separate module).

**Required documents (accept PDF or image formats — JPG/PNG):**
- Form 137 / Senior High School Permanent Record (SF-10)
- Senior High School Diploma or Certificate of Graduation
- Certificate of Good Moral Character
- PSA-authenticated Birth Certificate
- PSA-authenticated Marriage Certificate (only shown/required if `civil_status` = Married)
- Initial medical clearance / screening document (note in the UI, not enforced by code,
  that visual acuity and Ishihara color-blindness testing are maritime-specific
  prerequisites — this is informational text near the upload field, not something the
  system validates)
- Recent 2x2 ID photo, white background, formal attire (JPG/PNG only, not PDF, for this one)

**Implementation requirements:**
- Table `documents` (already in `database/enrollment_system.sql`):
  ```sql
  documents
    id, student_id (FK -> students.id), document_type ENUM('form_137','shs_diploma',
    'good_moral','birth_certificate','marriage_certificate','medical_clearance','id_photo'),
    file_path, original_filename, uploaded_at, status ENUM('pending','verified','rejected')
    DEFAULT 'pending'
  ```
- Store uploaded files in a new `uploads/student_documents/` directory (add this to the
  folder structure in Section 3) — **not** inside `assets/`, since assets are meant for
  static site files, not user-uploaded content.
- **Server-side validation is mandatory, not optional:**
  - Check actual MIME type (not just file extension) — reject anything that isn't a real
    PDF/JPG/PNG
  - Enforce a reasonable max file size (e.g. 5MB per file)
  - Rename uploaded files on save (e.g. `{student_id}_{document_type}_{timestamp}.ext`) —
    never trust or reuse the original filename as the stored filename, to avoid collisions
    and path-traversal issues
  - Reject the whole submission with a clear SweetAlert2 error if a required document is
    missing (except the marriage certificate, which is conditional)
- `registrar/enrollee_applications.php` should let the registrar view/download each
  uploaded document per applicant, and optionally mark each document `verified` or
  `rejected` individually before approving the overall application.
- **When a revision is requested (Section 5c):** the registrar's revision notes should say
  which specific document(s) need re-upload; only those document rows go back to `pending`
  status so the enrollee re-uploads just the flagged file(s), not the whole set.
- Add `uploads/` to `.gitignore` if using git, since uploaded files shouldn't be committed.

**⚠️ Scope note:** file uploads are a meaningfully bigger feature than the rest of the form —
they add file handling, storage, validation, and a review UI for the registrar. Budget real
time for this specifically; it is not a quick add-on to the text-field form.

### Enrollee
- Applicant Overview: `enrollee/welcome.php` — applicant dashboard and quick links to start/review application.
- Application Form: `enrollee/apply.php` — detailed admission application form (personal, contact, academic fields) and document upload UI; locks when status is approved/paid/enrolled.
- Review Application: `enrollee/review.php` — view uploaded documents and submit the final application.
- Application Status: `enrollee/status.php` — shows current admission status, profile summary, and redirects to the student dashboard if role changes to `student`.
- Notifications: `enrollee/notifications.php` — displays admission application updates, registrar remarks, and enrollment status for enrollees (retains backward-compatible access for upgraded students).
- Registrar Remarks: application remarks are stored per applicant, shown on `enrollee/status.php`, and create a notification for the applicant.

### Student
- Academic Records: `student/academic_records.php` displays approved grade history and a unit-weighted GWA.
- Student Dashboard: `student/dashboard.php` — student workspace and quick actions.
- Class Enrollment: `student/enroll.php` — choose available class sections after payment (eligibility check: `paid`).
- My Enrollments: `student/my_enrollments.php` — read-only list of the student’s section registrations and statuses.
- My Payments: `student/my_payments.php` — student-only history, assessment balance, receipt links, and downloadable assessment slip.
- My Profile: `student/my_profile.php` + `student/profile_edit.php` — profile view and restricted editing of contact, address, guardian, and religion fields.
- Certificate of Registration: `student/cor.php` — printable/downloadable COR for approved registrations in the student's academic term.
- Notifications: `student/notifications.php` — dedicated student notification center for class enrollments, grade approvals, and academic records (`enrollee/notifications.php` retained as a legacy bookmark-safe fallback).
- Registration rules: student registration is limited to paid students in the active term and checks schedule conflicts, maximum units, prerequisites, duplicate courses, and locked section capacity.

### Admin
- Admin Dashboard: `admin/dashboard.php` — admin workspace and quick actions.
- Manage Users: `admin/manage_users.php` — full CRUD for user accounts, role assignment, and basic linked student profile editing.
- Manage Roles: `admin/manage_roles.php` — present but no full role-management flow (UI shell / partial).
- Trash Bin: `admin/trash_bin.php` — archived items recovery UI.
- Audit Log: `admin/audit_log.php` — read-only audit entries for admin actions.
- Academic Terms: `admin/academic_terms.php` — creates, edits, and activates school year/semester combinations used by admission, payment, and enrollment records.

### Registrar
- Grade Approvals: `registrar/grade_approvals.php` reviews submitted gradebooks and approves/locks them.
- Registrar Dashboard: `registrar/dashboard.php` — workspace overview and quick links.
- Admission Applications: `registrar/enrollee_applications.php` — list pending enrollee applications, view uploaded documents, verify/reject individual documents, and approve/reject applications.
- Student Records: `registrar/students.php` — searchable list of student profiles and statuses.
- Courses: `registrar/courses.php` — create/edit/delete course records.
- Sections: `registrar/sections.php` — create/edit/delete sections and assign teachers.
- Enrollments: `registrar/enrollments.php` — review submitted section registrations and approve/reject per-section registrations.

### Cashier
- Cashier Dashboard: `cashier/dashboard.php` — workspace overview.
- Record Payment: `cashier/payments.php` — search/select a student, record payment (amount, unique OR number, notes).
- Official Receipt: `cashier/receipts.php` — printable receipt view for a recorded payment.
- Payment History: `cashier/payment_history.php` — searchable read-only payment history.
- Assessment: `cashier/assessment.php` — itemized assessment, validated payment total, and running balance.

### Phase 2 payment control
- Admin configures fee rules per academic term, program, and year level in `admin/fee_setup.php`.
- Assessments are generated from matching fixed and per-unit fee rules; per-unit items use the student's current registered course load.
- Direct cashier-recorded payments are validated when recorded; student-submitted payments begin as pending and require cashier validation.
- Validated payment amounts offset assessment items and count toward the running balance.
- Student access activates after cumulative validated payments meet the term's minimum/percentage downpayment.
- Assessment: `cashier/assessment.php` — itemized term assessment with validated payments and balance.

### Phase 2 payment control
- Admin fee rules are stored per term/program/year level in `fee_configurations`.
- Assessments and assessment items are generated from matching fee rules and current course load.
- Direct cashier-recorded payments are immediately validated and allocated; student-submitted payments require OR validation before counting toward the running balance.
- Student access activation requires the configured minimum/percentage downpayment.

### Phase 3 registration rules
- Sections belong to an academic term.
- The enrollment action locks selected sections while checking capacity.
- Duplicate courses, overlapping schedules, unmet prerequisites, and loads above the term maximum are rejected.
- Registrar approval/rejection of registration requests is restricted to the active term.

### Teacher
- Attendance: `teacher/attendance.php` records teacher-owned section sessions and enrolled-student attendance.
- Gradebook: `teacher/gradebook.php` stores draft grades and submits them for Registrar approval.
- Teacher Dashboard: `teacher/dashboard.php` — workspace overview.
- My Classes: `teacher/my_classes.php` — read-only list of sections assigned to the signed-in instructor (admins may view all).
- Class Roster: `teacher/class_list.php` — read-only roster for a selected section (shows only per-section `enrolled` registrations).
- My Profile: `teacher/my_profile.php` — stub/empty page (no profile flow).

Other important files:
- Public landing and auth: `index.php`, `auth/login.php`, `auth/register.php`, `auth/logout.php` — public landing + split login/register UI and account creation.
- Includes: `includes/auth_check.php` (role guard), `includes/header.php`, `includes/footer.php`, `includes/sidebar.php`, `includes/flash_messages.php` (SweetAlert2 toast integration).
- Action handlers (server-side processors): `actions/*.php` (auth_actions, enrollee_actions, enrollment_actions, payment_actions, user_actions, course_actions, section_actions, student_actions — some are stubs).
- Database schema: `database/schema.sql` includes `users`, `students`, `courses`, `sections`, `enrollments`, `payments`, `documents`, `notifications`, `audit_logs`, etc.

---

## 5. New Feature Specs (from Section 0's "Add" list)

### 5a. Updated `enrollment_status` ENUM

```sql
enrollment_status ENUM('pending','requires_revision','approved','paid','enrolled','rejected')
DEFAULT 'pending'
```

`requires_revision` is new. It sits between `pending` and `approved` in the flow — see 5c.

### 5b. Application Number & Student Number Generation

Both are System automation, generated server-side, never editable by any role.

**Application Number** — generated the moment an enrollee successfully submits
`apply.php` (i.e. `enrollment_status` first becomes `pending`). Format:
`{YEAR}-ADM-{5-digit sequence}`, e.g. `2026-ADM-00001`. Store in a new
`application_number VARCHAR(20) UNIQUE` column on `students`.

**Student Number** — generated only when the Registrar performs the final enrollment
confirmation (status → `enrolled`), not before. Format: `{YEAR}-{5-digit sequence}`,
e.g. `2026-00001`. Store in a new `student_number VARCHAR(20) UNIQUE NULL` column on
`students` (`NULL` until enrolled).

Implement both as functions in `includes/number_generator.php`:

```php
function generateApplicationNumber(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE application_number LIKE ?");
    $stmt->execute(["$year-ADM-%"]);
    $next = $stmt->fetchColumn() + 1;
    return sprintf('%s-ADM-%05d', $year, $next);
}

function generateStudentNumber(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_number LIKE ?");
    $stmt->execute(["$year-%"]);
    $next = $stmt->fetchColumn() + 1;
    return sprintf('%s-%05d', $year, $next);
}
```

Call `generateApplicationNumber()` from `actions/enrollee_actions.php` on first submit only
(never regenerate on resubmit-after-revision). Call `generateStudentNumber()` from
`actions/enrollment_actions.php` at the moment the registrar confirms final enrollment.
Display the application number prominently on `enrollee/status.php` and the student number
on `student/dashboard.php` and the COR (5d).

### 5c. Application Locking & Revision Workflow

- On submit, `apply.php` sets `enrollment_status = 'pending'`, generates the application
  number, and becomes **read-only** — re-visiting `apply.php` after submission shows a
  locked summary view instead of an editable form.
- Add a `revision_notes TEXT NULL` column to `students` (registrar's free-text explanation
  of what needs fixing) and, if practical given the timeline, a simple
  `revision_document_types VARCHAR(255) NULL` column storing a comma-separated list of which
  `document_type` values need re-upload (used to selectively unlock those document rows —
  see Section 4c).
- `registrar/enrollee_applications.php` gets a third action button next to
  Approve/Reject: **"Request Revision"**, which opens a modal for the notes, sets
  `enrollment_status = 'requires_revision'`, and saves `revision_notes`.
- When status is `requires_revision`, `enrollee/apply.php` unlocks — either the whole form
  or just the flagged fields/documents (agent's judgment based on remaining time; unlocking
  the whole form is the simpler, acceptable fallback if time is tight).
- `enrollee/status.php` must display `revision_notes` clearly (a highlighted alert box) so
  the enrollee knows exactly what to fix.
- On resubmit, status goes back to `pending` (do not regenerate the application number) and
  the registrar reviews again from `enrollee_applications.php` as normal.

### 5d. COR (Certificate of Registration) Generation

A printable page, built the same way as the existing `cashier/receipts.php` (same
print-friendly CSS pattern, same "Print" button using `window.print()` — do not introduce a
new PDF library).

- New pages: `student/cor.php` (student's own COR, read-only) and `registrar/cor.php`
  (registrar can view/reprint any enrolled student's COR).
- Only available once `enrollment_status = 'enrolled'` and `student_number` is set — if not
  yet enrolled, show a friendly "not available yet" message instead of a broken page.
- COR content, pulled live from existing tables (no new `cor` table needed — same approach
  as receipts, generated on the fly):
  - School name/header, "Certificate of Registration" title
  - Student Number, Full Name, Program, Year Level
  - Academic Year, Semester, Section (from `enrollments` + `sections` + the new academic
    tables in 5e)
  - Subject list with units and schedule (from `subjects` + `sections`, once 5e exists —
    until then, fall back to whatever course/section data already exists)
  - Total units
  - Date generated, and the same teal-branded header/footer style as receipts.php
- Add a "View/Print COR" link on `student/dashboard.php` once enrolled, and on
  `registrar/students.php` per enrolled student row.

### 5e. Academic Setup (Admin) — Academic Year / Semester / Curriculum / Subjects

**This is the biggest structural addition.** Admin must configure these *before* enrollment
meaningfully opens. Keep each CRUD page simple — table list + add/edit modal or form,
matching the existing `admin/manage_users.php` pattern, not a new UI paradigm.

**New tables** (add to `database/enrollment_system.sql`):

```sql
academic_years
  id, year_label VARCHAR(9) e.g. '2026-2027', is_active BOOLEAN DEFAULT 0,
  created_at, updated_at

semesters
  id, academic_year_id (FK -> academic_years.id),
  semester_name ENUM('1st Semester','2nd Semester','Summer'),
  is_active BOOLEAN DEFAULT 0,
  created_at, updated_at

curriculums
  id, course_id (FK -> courses.id), curriculum_name VARCHAR(100) e.g. 'BSMT 2026 Curriculum',
  effective_year VARCHAR(9), created_at, updated_at

subjects
  id, curriculum_id (FK -> curriculums.id), subject_code, subject_name, units DECIMAL(3,1),
  year_level ENUM('1st Year','2nd Year','3rd Year','4th Year'),
  semester_name ENUM('1st Semester','2nd Semester','Summer'),
  created_at, updated_at
```

**Relationships to existing tables:**
- `sections` gets two new FK columns: `academic_year_id`, `semester_id` — a section now
  belongs to a specific term, not just a course.
- `enrollments` (already has `school_year`, `semester` as free text per the original
  schema) — replace those two free-text columns with proper FKs: `academic_year_id`,
  `semester_id`, to stay consistent with the new tables. Update any existing seed data
  accordingly.
- `student/enroll.php` (subject/section picker) should filter available sections down to
  the currently **active** academic year + semester (`is_active = 1` on both), and pull the
  subject list for the student's program/year level from `subjects` via the matching
  `curriculums` row.

**New admin pages** (`admin/academic_years.php`, `semesters.php`, `curriculum.php`,
`subjects.php`, wired to `actions/academic_actions.php`):
- Simple CRUD tables, each with an "activate" action (only one academic year and one
  semester should be `is_active = 1` at a time — flipping one off when another is turned on,
  same pattern as toggling `is_active` on user accounts).
- `curriculum.php` lists curriculums per course/program; `subjects.php` lets admin add
  subjects under a chosen curriculum, grouped by year level + semester for readability.
- Gate `enrollee/apply.php` (or at minimum `student/enroll.php`) behind "is there an active
  academic year AND active semester?" — if not, show the "not accepting applications yet"
  notice from Section 4b instead of a broken form.

**Scope guardrail given the 2-week timeline:** don't over-build this. No drag-and-drop
curriculum builder, no prerequisite-chain validation, no multi-curriculum-versioning UI
beyond a simple list — just enough structure so Academic Year/Semester/Curriculum/Subjects
exist as real configurable data instead of hardcoded values, and so sections/enrollments
correctly reference a specific term.

---

## 6. Explicitly Skipped (do not build these)

Per Section 0, these two items from the Flow Doc are **not** part of this build. If asked to
"add the audit log" or "let applicants upload proof of payment" later, that's a scope change
requiring explicit confirmation — don't build them proactively just because they're
described in the Flow Doc.

- **Reversed payment flow.** Keep `cashier/payments.php` exactly as already built: the
  Cashier directly records a payment (amount, OR number, date) against a student. Do **not**
  add an applicant-side "upload proof of payment" step or a Cashier verify/reject-proof
  queue.
- **Audit log** (lightweight or full). Do not add an `audit_logs` table or logging calls
  scattered through `actions/*.php`. Existing `created_at`/`updated_at` timestamps on each
  table are the only history being kept.

## 7. Database Schema (core tables — extend, don't replace)

```sql
users
  id, username, password_hash, role ENUM('enrollee','student','admin','registrar','cashier','teacher'),
  email, is_active, created_at

students
  id, user_id (FK), first_name, last_name, birthdate, address, contact_number,
  application_number VARCHAR(20) UNIQUE NULL,      -- NEW, Section 5b
  student_number VARCHAR(20) UNIQUE NULL,          -- NEW, Section 5b
  revision_notes TEXT NULL,                        -- NEW, Section 5c
  revision_document_types VARCHAR(255) NULL,       -- NEW, Section 5c (optional)
  enrollment_status ENUM('pending','requires_revision','approved','paid','enrolled','rejected'),
  created_at
  -- plus the full extended column set from Section 4a (personal info, address,
  -- academic background, program choice) — already implemented in Phase 1.5

academic_years        -- NEW, Section 5e
  id, year_label, is_active, created_at, updated_at

semesters              -- NEW, Section 5e
  id, academic_year_id (FK), semester_name, is_active, created_at, updated_at

curriculums             -- NEW, Section 5e
  id, course_id (FK), curriculum_name, effective_year, created_at, updated_at

subjects                -- NEW, Section 5e
  id, curriculum_id (FK), subject_code, subject_name, units, year_level, semester_name,
  created_at, updated_at

courses
  id, course_code, course_name, units

sections
  id, course_id (FK), academic_year_id (FK), semester_id (FK),  -- 2 new FKs, Section 5e
  schedule, room, capacity, teacher_id (FK -> users.id)

enrollments
  id, student_id (FK), section_id (FK), academic_year_id (FK), semester_id (FK),
  -- replaces old free-text school_year/semester columns, Section 5e
  status ENUM('pending','approved','paid','enrolled','dropped')

payments
  id, student_id (FK), amount, or_number, payment_date, cashier_id (FK -> users.id), notes
  -- unchanged, see Section 6 — cashier records this directly

documents
  id, student_id (FK), document_type ENUM(...), file_path, original_filename,
  uploaded_at, status ENUM('pending','verified','rejected') — see Section 4c for full
  document_type ENUM values and upload handling rules
```

Build the actual `CREATE TABLE` statements in `database/enrollment_system.sql`, with
foreign keys and sensible `NOT NULL` / `DEFAULT` constraints. Add `created_at` /
`updated_at` timestamps on every table.

## 8. Coding Conventions

- **Always use PDO prepared statements** — bind parameters, never interpolate user input
  into SQL strings.
- **Escape all output** with `htmlspecialchars()` when echoing user-provided data into HTML.
- **One `$pdo` connection**, defined in `config/database.php`, included via `require_once`
  wherever needed.
- **Session guard pattern** — `includes/auth_check.php` should accept an allowed-roles array,
  e.g. `checkRole(['registrar','admin']);`, and redirect to login if the session role doesn't
  match.
- **Naming:** snake_case for files and DB columns, camelCase for PHP variables/functions.
- **Actions scripts** (`actions/*.php`) never output HTML — they process, then redirect.
  Errors/success go through a session flash message (`$_SESSION['flash']`).
- **Passwords:** never store plain text. `password_hash($pw, PASSWORD_DEFAULT)` on create,
  `password_verify()` on login.
- **Validate on both ends:** HTML5/Bootstrap validation attributes on forms AND server-side
  checks in the matching `actions/` file — never trust client-side validation alone.
- **Number generation is server-side only** (Section 5b) — never accept an
  application/student number from a form field.

## 9. Build Order (follow this sequence, don't jump ahead)

Status legend: ✅ done & tested · ⬅️ next · ⬜ not started

**Already built (Phase 1 — unchanged by this revision):**

1. ✅ `database/enrollment_system.sql` — finalize schema, run it, seed with test data
2. ✅ `config/database.php` — PDO connection, tested working
3. ✅ `includes/header.php`, `footer.php`, `sidebar.php`, `auth_check.php` — shared shell +
   guard logic, tested working
4. ✅ `auth/login.php` + `actions/auth_actions.php`
5. ✅ `admin/manage_users.php` — CRUD for user accounts across all 6 roles
6. ✅ `enrollee/apply.php` → `registrar/enrollee_applications.php` (approve/reject flow)
7. ✅ `auth/register.php`, `index.php` landing page, extended `apply.php` fields, document
   uploads, show/hide password icon
8. ✅ `registrar/courses.php`, `sections.php`
9. ✅ `student/enroll.php` → `registrar/enrollments.php`
10. ✅ `cashier/payments.php`, `receipts.php`
11. ✅ `teacher/my_classes.php`, `class_list.php`
12. ✅ Polish pass: SweetAlert2 flash messages, search/filter tables, form validation audit
13. ⬅️ **Teal re-theme sweep — still outstanding.** Go through every page built in Steps 3–12
    and replace every remaining old color reference with the exact 5-step teal ramp from
    Section 1a. Check for: hardcoded old hex values, Bootstrap `.btn-primary`/`.bg-primary`
    classes still rendering blue, inline `style=""` with old colors. No page should show
    blue as its dominant color when done.

**New work from this revision (Phase 2 — see Section 11 for the detailed step-by-step task
list):**

14. ⬜ Academic setup (Section 5e) — tables, `admin/academic_years.php`, `semesters.php`,
    `curriculum.php`, `subjects.php`, FK updates to `sections`/`enrollments`
15. ⬜ Application number generation + locking (Sections 5b, 5c)
16. ⬜ Revision workflow — registrar "Request Revision" action, unlocked resubmit flow
    (Section 5c)
17. ⬜ Student number generation on final enrollment confirmation (Section 5b)
18. ⬜ COR generation — `student/cor.php`, `registrar/cor.php` (Section 5d)
19. ⬜ Re-theme sweep for all newly built pages (extends Step 13's checklist to Steps 14–18)
20. ⬜ Test every role's full flow end-to-end before the deadline

**Note for the agent:** update the checkmarks above as steps are completed, so progress stays
visible across sessions/tools without re-explaining status each time.

## 10. What AI agents should NOT do

- Do not introduce Laravel, Symfony, Composer autoloading, or any package manager
- Do not switch to React, Vue, or any JS framework/build step
- Do not use raw SQL string concatenation — PDO prepared statements only
- Do not create new top-level folders outside this structure without asking first
- Do not skip `auth_check.php` on any protected page
- Do not invent new status values outside the `enrollment_status` ENUM listed in Section 5a
  without updating this file too
- Do not build the reversed payment-upload flow or an audit log (Section 6) — they were
  explicitly cut for time
- Do not rename `enrollee` → `Applicant` or add an `Applicant` role to match the Flow Doc's
  vocabulary — see Section 0
- Do not regenerate an `application_number` on resubmit-after-revision — it's issued once,
  on first submit only

## 11. Step-by-Step Task List — Start to End (for the user *and* the AI agent)

This is the practical checklist to follow for the remaining ~2 weeks. Each step names who
does it. Work top to bottom; don't start a step until the one before it is genuinely done
and tested, not just "written."

1. **[User]** Confirm the exact program list for `program_applying_for` (Section 4a) if not
   already locked in — BSMarE / BSMT or otherwise. Skip if already confirmed.
2. **[AI]** Update `database/enrollment_system.sql`: add the 4 new tables from Section 5e
   (`academic_years`, `semesters`, `curriculums`, `subjects`), the new columns on `students`
   from Section 5b/5c (`application_number`, `student_number`, `revision_notes`,
   `revision_document_types`), the updated `enrollment_status` ENUM (Section 5a), and the
   new FK columns on `sections`/`enrollments` (Section 5e). Update `seed_data.sql` to match.
3. **[AI]** Run the migration locally, confirm it imports clean with no FK errors, and that
   existing seeded students/enrollments still load correctly on already-built pages.
4. **[AI]** Build `admin/academic_years.php` + `semesters.php` (simple CRUD, one active
   record at a time) — Section 5e. **[User]** Sanity-check: can you create a year, create a
   semester under it, and activate them?
5. **[AI]** Build `admin/curriculum.php` + `subjects.php` (CRUD, grouped by year
   level/semester) — Section 5e. **[User]** Add at least one real curriculum with a handful
   of subjects for the actual program(s) so later steps have real data to point at.
6. **[AI]** Wire `sections.php` and `student/enroll.php` to the new academic-year/semester
   FKs and active-term filtering (Section 5e). Test: only sections in the active term show
   up for students.
7. **[AI]** Add `includes/number_generator.php` (Section 5b). Hook
   `generateApplicationNumber()` into `actions/enrollee_actions.php` on first submit.
   **[User]** Submit a test application, confirm you see a real `2026-ADM-00001`-style number
   on `enrollee/status.php`.
8. **[AI]** Add application locking to `enrollee/apply.php` (Section 5c) — read-only after
   submit. **[User]** Confirm you can't edit a submitted application by revisiting the page.
9. **[AI]** Add the `requires_revision` status + "Request Revision" action to
   `registrar/enrollee_applications.php`, the notes modal, and the unlock-on-revision logic
   on `apply.php`/`status.php` (Section 5c). **[User]** Walk through: submit → registrar
   requests revision with a note → note shows on `status.php` → applicant edits → resubmits
   → registrar sees it again.
10. **[AI]** Hook `generateStudentNumber()` into `actions/enrollment_actions.php` at final
    enrollment confirmation (Section 5b). **[User]** Confirm a real student number appears
    once an enrollment is confirmed.
11. **[AI]** Build `student/cor.php` and `registrar/cor.php` (Section 5d), reusing the
    receipt's print CSS pattern. **[User]** Print/preview a COR for a fully enrolled test
    student and check it actually shows the right subjects/section/units.
12. **[AI]** Full teal re-theme sweep (Section 9, Step 19) across every page touched in Steps
    2–11, plus anything still blue from the earlier Step 13 sweep.
13. **[User + AI]** End-to-end test, one role at a time, in this order: Enrollee (register →
    apply → get sent back for revision → resubmit → get approved) → Cashier (record payment)
    → Registrar (confirm enrollment) → Student (see dashboard, enroll in sections, view COR)
    → Teacher (see class list) → Admin (confirm academic setup pages all still work after
    real data has been added). Fix anything that breaks before moving to demo prep.
14. **[User]** Prepare demo data / README / talking points for the live walkthrough using
    Definition of Done (Section 12) as the checklist.

## 12. Live System Status — see CURRENT_SYSTEM and FLOW.md

This file (`AI_GUIDE.md`) documents the **plan** — tech stack, conventions, and what
*should* be built. It does not track what's actually done at any given moment.

**`CURRENT_SYSTEM and FLOW.md`**, in the project root, is the **real-time source of truth for
what's actually implemented right now** — every role's real working pages, the actual
system flow and per-role flows as ASCII diagrams, and a "Known Gaps" checklist of what's
still missing. Any AI agent picking up this project should read `CURRENT_SYSTEM and FLOW.md`
first to know exactly where the build currently stands, then reference this file
(`AI_GUIDE.md`) for how to build the rest.

`CURRENT_SYSTEM and FLOW.md` must be updated in the same session any time a feature, flow, or
page is added, changed, or fixed — see the maintenance rule inside that file itself.

## 13. Definition of Done (for the 2-week deadline)

- All 6 roles can log in and see a role-appropriate dashboard
- Admin has configured at least one active Academic Year, Semester, Curriculum, and set of
  Subjects before enrollment is demoed
- Enrollee → (optional revision round-trip) → Student → Payment → Enrolled flow works
  end-to-end with real data, including a real generated Application Number and Student
  Number
- A submitted application is locked and only becomes editable again via a registrar-issued
  "Requires Revision" status with notes
- Registrar can manage courses/sections/curriculum; Teacher sees only their own class list
- Cashier can record a payment and print/view a receipt (cashier-recorded, not
  applicant-uploaded — see Section 6)
- An enrolled student can view and print their COR
- No plaintext passwords, no SQL injection-prone queries
- README/demo data ready for a live walkthrough 
