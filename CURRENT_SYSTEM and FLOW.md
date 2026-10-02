# Current System and Flow

This document describes the enrollment system as it exists in the current repository, based on the live code and schema. The implementation is the source of truth; older notes and planning artifacts are not treated as authoritative if they disagree with the app.

## 1. System overview

This is a PHP + MySQL enrollment platform for NCST Maritime Academy with a role-based portal and a server-rendered Bootstrap layout. The modules in the live app are:

- `auth/` — account creation and login
- `enrollee/` — applicant registration and document workflow
- `registrar/` — application decisions, course/section review, grade approvals
- `cashier/` — payment recording and validation
- `student/` — student enrollment, profile, academic records, and payment visibility
- `teacher/` — attendance and LMS grading pages
- `admin/` — system configuration and administration

The active operational flow is:

Application -> Review -> Approval -> Section chosen -> Walk-in review and tuition finalization -> Online/cashier downpayment (cashier-validated) -> Student activation (role 'student', status 'paid') -> Registrar walk-in approval -> 'enrolled' -> Academic progression

## 1.1 Verified live features and exceptions

The following are verified in the current repository state:

- `student/academic_records.php` is a live academic transcript page. It pulls finalized grade entries, computes cumulative GWA and units, and provides a transcript download and print view.
- The transcript query is intentionally restricted to grade submissions that are already approved or locked by the registrar. This matches the student-facing visibility rule used in the current code.
- `student/dashboard.php` still uses hard-coded KPI values; `registrar/dashboard.php` now loads its overview KPIs from live active-term data.
- Walk-In Validation and Reports modules have been removed completely from the system flow and navigation to streamline the enrolment process.
- The schema and action code still rely on `academic_terms` as the active academic term model. Older mentions of a separate academic-year/semester structure are not current repository truth.
- Section selection for students is strictly scoped to the cadet's registered program (`BSMT` / `BSMarE`) and current year level (`1st Year` – `4th Year`).

### Needs verification

- Whether the `locked` grade submission status is still used anywhere in the live workflow beyond the transcript query.
- Whether application and student number generation is ever implemented in the real database layer; the current schema does not include those fields.

---

## 2. Current roles and responsibilities

### Enrollee
Primary folder: `enrollee/`

Responsibilities:
- Registers a new account with role `enrollee`
- Completes application form in `enrollee/apply.php`
- Selects the City / Municipality through a type-to-search combobox covering all Philippine cities and municipalities (PSGC data in `assets/data/ph/cities.json`), with province auto-fill and dynamic barangay loading via `actions/get_barangays.php`
- Uploads required supporting documents
- Saves draft or submits application for registrar review
- Tracks application status and revision messages in `enrollee/status.php`
- Receives notifications and registrar remarks

### Student
Primary folder: `student/`

Responsibilities:
- Accesses the student portal only after the account is upgraded from `enrollee` to `student`
- Section selection occurs prior to walk-in review (while in enrollee `approved` or `section_chosen` status). Once tuition is finalized by the Registrar during walk-in review (`walk_in_ready`) and the cashier validates a payment meeting the minimum downpayment threshold, the enrollee is activated to role `student` and status `paid`. The Registrar then reviews and approves the paid walk-in (`approvePaidWalkIn`) to transition the student to `enrolled`.
- Views enrollment, payment, academic records, profile pages, and LMS (once enrolled)
- Checks COR and current enrollment status

### Registrar
Primary folder: `registrar/`

Responsibilities:
- Reviews pending applicant records in `registrar/enrollee_applications.php`
- Verifies or rejects individual documents
- Requests revision notes when data/documents are incomplete
- Approves or rejects admission applications
- Reviews submitted enrollment records and approves/rejects them
- Reviews grade submissions and approves locked grade data
- Maintains courses, sections, and student records

### Cashier
Primary folder: `cashier/`

Responsibilities:
- Records payment with OR number and amount
- Validates pending OR records submitted by students
- Checks assessment totals and remaining balance
- Upgrades eligible `enrollee` accounts to `student` based on payment validation threshold
- Prints receipt views

### Teacher
Primary folder: `teacher/`

Responsibilities:
- Views assigned sections in `teacher/my_classes.php`
- Records attendance in `teacher/attendance.php`
- Manages student grades in `teacher/lms_grades.php`
- Reviews class rosters in `teacher/class_list.php`

### Admin
Primary folder: `admin/`

Responsibilities:
- Manages academic terms
- Configures fee rules
- Reviews users and system audit data
- Accesses role-management UI and admin dashboard tools
- Maintains recovery and audit records

---

## 3. Actual user flow

### Account creation and login

```text
START
  guest visits index.php
    ↓
  auth/register.php -> auth_actions.php
    ↓
  creates users row with role = 'enrollee'
    ↓
  creates students row with application_status = 'draft'
    ↓
  session is created
    ↓
  user is redirected to enrollee/welcome.php
```

Login is handled by `auth/login.php` and `actions/auth_actions.php`.
- Username or email is accepted.
- Password is verified.
- User role determines the landing dashboard.

### Enrollee application flow

```text
enrollee/welcome.php
    ↓
enrollee/apply.php
    ↓
validate fields and uploads
    ↓
application saved as draft or final submission
    ↓
students.application_status = 'draft' or 'pending'
    ↓
student reviews documents and status
    ↓
registrar/enrollee_applications.php
```

### Application status progression

```text
draft
  -> pending
  -> under_review
  -> needs_revision
  -> approved
  -> rejected
```

Important implementation detail:
- The project uses `needs_revision`, not `requires_revision`.
- The code explicitly checks `needs_revision` in several places.
- Old planning notes that use `requires_revision` are stale.

### Approval and payment flow

```text
registrar-approved application (application_status = 'eligible_to_enroll')
    ↓
Cashier records a payment or validates a student-submitted OR
    ↓
payments.or_status = 'validated' (cashier approves submitted payment)
    ↓
validated payments meet the configured downpayment requirement: max(minimum_downpayment, downpayment_percentage × assessment total)
    ↓
users.role is updated from 'enrollee' to 'student'
    ↓
students.enrollment_status = 'paid' (student portal access activated)
    ↓
registrar reviews paid walk-in in registrar/walk_in.php (approvePaidWalkIn)
    ↓
students.enrollment_status = 'enrolled' (enrollment complete, full LMS access unlocked)
```

### Enrollee sectioning & walk-in validation flow

```text
enrollee/sections.php
    ↓
eligible after registrar admission approval (application_status = 'approved' / 'eligible_to_enroll')
    ↓
sections strictly filtered to student program & year level
    ↓
enrollee selects section -> enrollment_status becomes 'section_chosen'
    ↓
enrollee proceeds to campus with physical documents for Registrar Walk-in Review
    ↓
registrar/walk_in.php (finalize_walk_in)
    ↓
registrar verifies physical credentials, reviews subjects, and finalizes tuition assessment
    ↓
enrollment_status becomes 'walk_in_ready'
    ↓
tuition payment (online or cashier walk-in):
  - cashier validates payment totaling at least the required downpayment
  - enrollee is upgraded to role 'student' and enrollment_status becomes 'paid'
    ↓
registrar/walk_in.php (approvePaidWalkIn)
    ↓
status becomes 'enrolled'
```

---

## 4. Document workflow

The live document model is in `documents`.

Supported document types:
- `form_137`
- `shs_diploma`
- `good_moral`
- `birth_certificate`
- `marriage_certificate`
- `medical_clearance`
- `id_photo`

Document statuses:
- `pending`
- `verified`
- `rejected`

Actual workflow:

```text
Applicant uploads file
    ↓
file is MIME-checked (PDF/JPG/PNG)
    ↓
record saved to documents table
    ↓
registrar verifies or rejects each document
    ↓
application may be approved only after required docs are verified
    ↓
if not all required docs are valid, registrar may request revisions
```

Important rule in current code:
- If the applicant acknowledges submission without certain documents, the application may still move forward if the registrar allows it.
- This is enforced in `actions/enrollee_actions.php` and is not merely cosmetic.

---

## 5. Payment workflow

### Fee configuration
Admin configures fee rules in `admin/fee_setup.php`.

Data stored in:
- `academic_terms`
- `fee_configurations`

Fee scopes include:
- academic term
- program applying for
- year level
- fixed or per-unit calculation

### Assessment generation
`includes/assessments.php` creates a per-student, per-term assessment and itemized charges.

Flow:

```text
student + term selected
    ↓
fee rules are matched
    ↓
assessment record created
    ↓
assessment_items created
    ↓
assessment total is computed
```

### OR validation
Cashier flow in `actions/payment_actions.php`:

```text
cashier clicks 'Pay' in cashier/enrollment_queue.php (cashier/payments.php?student_id=...)
    ↓
transaction inserts payment with or_status = 'validated'
    ↓
payment is allocated to assessment items
    ↓
assessment and student payment summary are updated
    ↓
eligible applicant meeting downpayment is promoted to student and marked paid

student/enrollee submits online payment (actions/payment_actions.php?action=record_student_payment)
    ↓
payment is inserted with or_status = 'pending' and uploaded proof_file in private storage
    ↓
allocations reserve item capacity (status IN ('validated', 'pending')) to prevent walk-in over-allocation
    ↓
cashier reviews in cashier/enrollment_queue.php review modal (cashier/payment_history.php is view-only):
  - If approved: or_status becomes 'validated', custom OR optionally logged, student activated if threshold met
  - If rejected: or_status becomes 'rejected', reason recorded in validation_notes, allocations kept for audit, student notified to resubmit
```

Important rules enforced in code:
- **Payment Statuses (`or_status`)**: `pending` (online payment under review), `validated` (official receipt approved), `rejected` (rejected by cashier with required reason), `voided` (cancelled transaction).
- **Proof File Security (`proof_file`)**: Stored in `private_uploads/payment_proofs/{student_id}/` with randomized filenames and MIME validation; accessible only via authenticated route `actions/view_payment_proof.php` for owning applicant, cashiers, and admins. Path resolution strictly compares against `realpath(private_uploads/payment_proofs) . DIRECTORY_SEPARATOR`. *Deployment note*: The `.htaccess` access restriction rule applies on Apache servers with `AllowOverride` enabled; for production deployments, `private_uploads` should be configured outside the public web document root.
- **Allocation Reservation**: `allocatePayment()` counts both `'validated'` and `'pending'` payments to reserve capacity and prevent concurrent walk-in over-allocation; only `'validated'` payments count toward `paid_amount`, balance, percentages, and student activation. `'rejected'` and `'voided'` are excluded from capacity and totals.
- **Downpayment Threshold**: Activation to role `'student'` and status `'paid'` requires cumulative validated payments meeting `max(minimum_downpayment, downpayment_percentage × assessment total)`. Downpayment must be paid in full during initial downpayment stage; subsequent payments can be any amount up to the remaining balance.
- **Remaining Downpayment Enforcement**: If an enrollee makes a partial walk-in payment below the downpayment threshold, subsequent online submissions must cover at least the remaining required downpayment (`required - validated_paid`). The cashier enrollment queue explicitly displays `"Downpayment incomplete: ₱X remaining"`.
- **Approval-time Balance Guard**: Inside the `validate` transaction, remaining balance is re-verified (`payment amount > remaining balance` throws an error and rolls back), guaranteeing total validated payments cannot exceed assessment totals even under concurrent walk-in transactions.
- **Race-Safe Duplicate Protection**: Student payment creation uses transaction row locking (`FOR UPDATE`) to strictly prevent duplicate pending payments.
- **Official OR Number**: Defaults to system `ST-...` number. Cashier can override with a physical OR number, which is validated for uniqueness across all payments.
- **Audit Reports**: Daily Collections, Receipts Issued, and official receipt documents strictly exclude `pending`, `voided`, and `rejected` payments.

---

## 6. Academic term and admin configuration

The active term model is `academic_terms`.

Fields include:
- `school_year`
- `semester`
- `starts_on`
- `ends_on`
- `payment_requirement_percent`
- `downpayment_percentage`
- `minimum_downpayment`
- `max_units`
- `is_active`

Operational meaning:
- Only one term may be active at a time from the current design.
- Applications, fee rules, payment requirements, and enrollment rules all use the active term.

The admin pages managing this are:
- `admin/academic_terms.php`
- `admin/fee_setup.php`
- `admin/manage_users.php`
- `admin/audit_log.php`
- `admin/trash_bin.php`

---

## 7. Course and section rules

### Courses
`courses` stores:
- `course_code`
- `course_name`
- `units`

### Subject prerequisites
`subject_prerequisites` stores prerequisite relationships between subjects.

### Sections
`sections` stores:
- `section_name`
- `program` (`BSMT`, `BSMarE`)
- `year_level` (`1st Year`, `2nd Year`, `3rd Year`, `4th Year`)
- `course_id`
- `academic_term_id`
- `schedule`
- `room`
- `capacity`
- `teacher_id`
- `status` (`active`, `inactive`)

### Registration rules in live code
The student enrollment validation checks:
- program matching (`sections.program` matches student's program)
- year level matching (`sections.year_level` matches student's current year level)
- schedule conflicts
- duplicate course registrations
- unmet prerequisites
- maximum unit load per term
- active term membership
- payment status
- section capacity

These are enforced in `actions/enrollment_actions.php` and `includes/registration_rules.php`.

---

## 8. Attendance and grades

### Attendance tables
- `attendance_sessions`
- `attendance_records`

### Grade tables
- `grade_submissions`
- `student_grades`

### Teacher workflow
From the code:

```text
teacher/my_classes.php
    ↓
teacher/attendance.php or teacher/lms_grades.php
    ↓
records or grade submissions
    ↓
registrar/grade_approvals.php
    ↓
approval/lock status
```

Student academic records are shown from `student/academic_records.php` for approved/locked grades.

---

## 9. Database relationships and entity model

Key relationships currently used in code:

- `users.id` -> `students.user_id`
- `academic_terms.id` -> `students.academic_term_id`
- `academic_terms.id` -> `fee_configurations.academic_term_id`
- `students.id` -> `assessments.student_id`
- `academic_terms.id` -> `assessments.academic_term_id`
- `assessments.id` -> `assessment_items.assessment_id`
- `assessment_items.id` -> `payment_allocations.assessment_item_id`
- `payments.id` -> `payment_allocations.payment_id`
- `courses.id` -> `sections.course_id`
- `students.id` -> `enrollments.student_id`
- `sections.id` -> `enrollments.section_id`
- `students.id` -> `documents.student_id`
- `users.id` -> `notifications.user_id`
- `students.id` -> `application_remarks.student_id`

---

## 10. Business rules the app currently enforces

- Registration creates an `enrollee` account before application approval.
- A student cannot enroll in class sections until payment validation has completed and `students.enrollment_status = 'paid'`.
- Students can only view and select class sections that belong to their chosen program (`BSMT` / `BSMarE`) and current year level (`1st Year` – `4th Year`).
- Admission approval requires required documents to be verified or explicitly acknowledged as submitted without documents.
- Students can edit draft or revision applications only if the status allows it.
- Registrar actions move the application to `needs_revision`, `approved`, or `rejected`.
- `academic_terms` is the single active term model in this codebase.
- Section capacity, program/year alignment, and schedule conflict checks are enforced at submission time.
- Prerequisite completion is checked against already approved/locked grades before enrollment.

---

## 11. Current end-to-end flow summary

```text
Guest -> Register -> Enrollee account -> Fill app -> Upload docs -> Submit
  -> pending/under_review -> registrar verifies documents -> approve/reject/request revision
  -> approved -> enrollee chooses section -> walk-in review & tuition finalization ('walk_in_ready')
  -> online or cashier downpayment validated -> student role activated -> enrollment_status = 'paid'
  -> registrar approves paid walk-in -> student status becomes 'enrolled' -> LMS access granted
  -> teacher takes attendance -> teacher submits grades -> registrar approves grade submissions -> student sees academic records
```

This is the currently implemented live system flow in the repository.

## 12. Student LMS access (initial implementation)

- `includes/lms_access.php` is the shared LMS guard. It checks the LMS role allowlist (`student`, `teacher`, `registrar`, `admin`) before applying student-specific eligibility. Other account types, including `enrollee`, are denied by the existing role guard.
- Student LMS access requires `students.enrollment_status = 'enrolled'`, a confirmed `enrollments.status = 'enrolled'` record for the student's current academic term, and validated payment allocations meeting the existing term downpayment rule (`max(minimum_downpayment, downpayment_percentage × assessment total)`). Pending or unvalidated ORs do not count.
- `student/lms.php` lists subjects joined through the student's confirmed enrollment and `section_subjects`; students cannot add or select LMS courses. Its summary progress indicator is derived from completed lessons, viewed learning materials, submitted assignments, completed quizzes, and completed modules.
- `student/lms_course.php` repeats the enrollment-scoped subject check on direct requests, so an unassigned subject ID is rejected server-side. It provides course hub navigation for Overview, Announcements, Lessons / Modules, Learning Materials, Assignments, Quizzes / Exams, Grades, and Course Progress. Grades show approved/locked records from existing grade tables.
- Subject Announcements are stored in `lms_announcements`, tied to a `section_subjects` assignment. Students see only announcements for their confirmed current-term subject enrollment, and only after an instructor-set `published_at` time when `is_published = 1`. `notifyLmsAnnouncementRecipients()` uses the existing `notifications` helper to create in-app/email notifications for enrolled students when a future instructor publishing action posts an announcement; instructor authoring remains out of scope for this student phase.
- The student Lessons / Modules view uses published `lms_modules` and `lms_lessons` attached to a section-subject assignment, ordered by their instructor-managed display-order fields. Text, image, presentation, PDF, video, and external-link content are supported. A CSRF-protected action records lesson views in `lms_lesson_progress`; a module is complete when all of its published lessons have been viewed. Lesson assets are streamed through `student/lms_lesson_file.php`, which rechecks LMS eligibility and current subject enrollment; direct access to the private storage folder is denied.
- Learning Materials are stored in `lms_materials`, linked to a `section_subjects` assignment, and displayed only when `is_available = 1`. The list and `student/lms_material_file.php` both recheck the student's confirmed current-term subject enrollment; successful downloads create or update a student-owned `lms_material_views` record. Files are downloaded through the private `private_uploads/lms_materials` directory.
- Student Assignments use published `lms_assignments` linked to a section-subject assignment, reference files in `lms_assignment_files`, and one current student submission in `lms_assignment_submissions`. Students can read instructions, download references, submit or replace an ungraded file, and view recorded score/feedback. The due-time cutoff uses database time; late submissions are closed by default and accepted only when `allow_late_submissions = 1`. Uploads are CSRF-protected, MIME/size validated, and stored privately. Listing, upload, and reference-file download all recheck confirmed enrollment in the subject.
- Student Quizzes / Exams use published `lms_quizzes`, `lms_quiz_questions`, and `lms_quiz_choices`, with time-limited attempts and saved responses stored in `lms_quiz_attempts` and `lms_quiz_responses`. Multiple choice, true/false, and identification answers are scored server-side. Attempt limits and deadlines are enforced using database time. Responses autosave; leaving an active quiz consumes the attempt, grades saved responses, and does not permit resuming. Quiz listing, start, attempt, and result routes all recheck current confirmed enrollment in the subject.
- The student Grades view reads assignment/activity scores and feedback from `lms_assignment_submissions`, and quiz attempt scores/feedback from `lms_quiz_attempts`. Every work-score query binds the submission or attempt to the logged-in student's confirmed enrollment, so another learner's work in the same subject cannot be displayed. Its overall course grade, prelim/midterm/final-exam components, and remarks come only from Registrar-approved/locked `student_grades` rows, the same source used by `student/academic_records.php`; no second final-grade record is created. LMS work is classified with `lms_assignments.assignment_type` (`assignment` or `activity`).
- Course Progress is calculated per enrolled subject from actual student activity: `lms_lesson_progress`, `lms_material_views`, `lms_assignment_submissions`, `lms_quiz_attempts`, and published-module lesson completion. It is read-only and repeats the confirmed current-term subject-enrollment checks used by the other LMS sections.
- Instructor content-management and grading controls are not part of this student-facing phase. Instructor announcement authoring, calendar, chat, and reports still need their own management routes.

## 13. Teacher LMS — Instructor Management (Foundation + Dashboard)

- `includes/lms_access.php` was extended with four teacher-specific guard functions: `requireLmsTeacherAccess()`, `fetchLmsTeacherSubjects()`, `verifyTeacherOwnsSubject()`, and `requireTeacherSubjectAccess()`. These mirror the student guard pattern but apply the teacher role. Teacher access is NOT gated by payment or enrollment status — a `users.role = 'teacher'` account may access the Instructor Management area at any time.
- Subject-level access is scoped to sections where the teacher is either the section lead (`sections.teacher_id`) or a named subject instructor (`section_subjects.instructor_id`). A section lead covers every subject in their section for which no named instructor is explicitly set. `verifyTeacherOwnsSubject()` blocks direct-URL access to another teacher's subject by returning NULL; the convenience wrapper `requireTeacherSubjectAccess()` redirects to the hub with a flash error on failure.
- `teacher/lms.php` is the Instructor Dashboard — the teacher's LMS landing page. It displays: KPI cards (subjects assigned, total students, pending submissions, average class progress), per-subject cards with schedule/enrollment/progress details and quick-action shortcuts (Lessons, Material, Assignment, Quiz, Announce), a pending submissions panel showing the 15 most recent ungraded submissions with student name/type/subject and relative time, and a students-per-subject panel with mini progress bars. All data is live (no caching) and scoped strictly to the logged-in teacher's subjects.
- The teacher sidebar (`includes/sidebar.php`) has an "Instructor Management" nav group with links to: My Subjects, Learning Materials, Assignments, Quizzes, Submissions, Grades, Announcements, and Student Progress. The teacher's main dashboard (`teacher/dashboard.php`) was updated with a prominent "Instructor LMS" quick-action in the Faculty Desk grid.
- Empty state: a teacher with zero assigned subjects sees a friendly empty-state card with guidance to check My Classes; no errors are thrown.
- `teacher/lms_subject.php` is the Subject Management Hub (Phase 2 — My Subjects). It accepts a `section_subject_id` GET parameter, re-verifies teacher ownership via `requireTeacherSubjectAccess()`, and renders a tab-based management interface using the same `?section=` query-param pattern as `student/lms_course.php`. The Overview tab shows live statistics (enrolled count, published module/material/assignment/quiz counts, pending submissions, average class progress) and clickable management-area cards that link into each phase. Tabs for Lessons/Modules (Phase 3), Materials (Phase 4), Assignments (Phase 5), Quizzes (Phase 6), Submissions (Phase 9.1), Grades (Phase 7), Announcements (Phase 8), and Student Progress (Phase 9.2) are present with placeholder stubs so the page is fully navigable. The subject list on `teacher/lms.php` (dashboard) is the canonical "My Subjects" view; `lms_subject.php` is what opens when a teacher clicks "Manage Subject" on any subject card — no second subject-list source of truth exists.
- **Phase 3 — Lessons/Modules:** The Lessons/Modules tab of `teacher/lms_subject.php` is fully implemented. It fetches all `lms_modules` and `lms_lessons` rows for the given `section_subject_id` (published and unpublished) with a single LEFT JOIN query ordered by `display_order`. The UI shows: a "New Module" button → Bootstrap modal form (title, description, publish toggle); per-module collapsible cards with header (drag handle, published/draft badge, title, lesson count, edit/delete buttons) and a lesson list inside (drag handle, type icon, title, content-type label, published/draft badge, edit/delete). An "Add Lesson" button is inside each module card, opening a content-type-aware modal form that switches between text body, external URL, and file upload fields depending on the selected `content_type` (`text`, `image`, `pdf`, `presentation`, `video`, `external_link`). Edit Lesson similarly pre-populates all fields and shows the current file name for file-type lessons. Delete actions use `confirm()` dialogs and hidden form submission. Reordering (both module-level and lesson-level within a module) uses HTML5 native drag-and-drop with `draggable` toggled by mousedown on the grip handle; on `dragend` the new ID order is sent asynchronously via `fetch()` to the action handler, which returns `{"ok":true}`. All writes go through `actions/lms_module_lesson_actions.php`, which: is POST-only, validates CSRF on every action, re-verifies teacher ownership of the `section_subject_id` via `verifyTeacherOwnsSubject()`, validates module/lesson ownership via their `section_subject_id`/`module_id` foreign keys, stores uploaded files in `private_uploads/lms_lessons/` with random hex filenames and `.htaccess` protection (same pattern as other LMS private uploads), deletes orphaned files on lesson edit/delete/module-delete, wraps DB mutations in transactions, and catches `DomainException`/`Throwable` separately with flash error redirect. Student-side functions `fetchLmsModulesForStudentSubject()` and `fetchLmsLessonForStudentSubject()` read from the same `lms_modules`/`lms_lessons` tables; changes made by the teacher immediately reflect on the student view (no cache layer).
- **Phase 4 — Learning Materials:** `teacher/lms_materials.php` is the dedicated materials management page. It operates in two modes: (a) **subject-scoped** (with `?section_subject_id=N`) — shows all materials for that subject with upload/edit/delete/reorder/availability-toggle; (b) **overview** (no param) — shows all teacher subjects with material counts and "Manage Materials" links per subject. The subject-scoped view shows a stats strip (total/available/hidden counts), a drag-sortable list of materials (grip handle → HTML5 drag, on `dragend` fires AJAX `reorder_materials`), per-row availability toggle button (eye/eye-slash, AJAX `toggle_availability` that returns `{"ok":true,"is_available":N}` and updates the button live), Edit modal (pre-populated, optional file replacement), and Delete (confirm dialog + hidden form). The Upload modal accepts: title, optional description, optional manual material_type override (auto-detects from MIME if not set), file input (PDF, PPT/PPTX, DOC/DOCX, XLS/XLSX, TXT, CSV, RTF, images, MP4/WebM/OGV, ZIP — max 200 MB), and an "available immediately" checkbox. All writes go to `actions/lms_material_actions.php` (POST-only, CSRF-validated, ownership re-verified, file stored in `private_uploads/lms_materials/` with random hex name + original name in `file_name` column, `.htaccess` protected, transactions, DomainException/Throwable caught). Setting `is_available = 0` immediately hides a material from students since `fetchLmsMaterialsForStudentSubject()` filters `is_available = 1` — verified bidirectional connection. The `materials` tab in `teacher/lms_subject.php` now redirects directly to `lms_materials?section_subject_id=N` via `header()` instead of showing a placeholder.
