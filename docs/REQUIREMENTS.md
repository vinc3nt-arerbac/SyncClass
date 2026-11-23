# SyncClass — Requirements Analysis

**Document:** `docs/REQUIREMENTS.md`
**Phase:** 1 of 20 — Requirements Analysis
**Version:** 1.0
**Status:** Approved baseline for design
**Last updated:** 2026-11-18

---

## 1. Objective of this phase

Establish a complete, testable, unambiguous statement of *what* SyncClass must do before any architecture or code exists. Every later phase (database, MVC core, scheduling engine, conflict detection, reports) traces back to a requirement ID in this document. Nothing in later phases may introduce behaviour that is not justified here.

**Deliverables of Phase 1**

| Deliverable | Location |
|---|---|
| Requirements analysis (this document) | `docs/REQUIREMENTS.md` |
| System architecture | `docs/ARCHITECTURE.md` |
| Requirement-to-phase traceability | §12 of this document |

**Explicitly not in this phase:** database DDL, migrations, folder scaffolding, source code. Those are Phases 3, 4 and 6.

---

## 2. Purpose and scope

SyncClass is a classroom and subject scheduling platform for colleges and universities. Academic departments use it to schedule subjects, assign teachers and rooms, manage time slots, coordinate across departments, publish timetables, and collaborate on scheduling decisions.

**Primary goal:** prevent scheduling conflicts and maximize classroom utilization.

**Secondary goals**

1. Replace spreadsheet-and-whiteboard timetabling with a single authoritative schedule.
2. Make room utilization and faculty workload measurable.
3. Provide a calendar-first interface that non-technical staff can operate daily.
4. Provide a deterministic (non-AI, non-generative) timetable generator that produces conflict-free drafts that humans then approve.

**Scope boundary:** SyncClass is an *academic resource scheduler*. It is not a learning management system, not a student information system, and not a student-facing portal.

---

## 3. Stakeholders and personas

| Persona | Represents | Primary need |
|---|---|---|
| System Administrator (IT) | Central IT | Control accounts, master data, global rules, integrity of the whole schedule |
| Dean | Department head | Approve the department timetable; see workload and utilization; guard academic policy |
| Department Secretary | Scheduling officer | Build and maintain the department's class schedule quickly, without conflicts |
| Faculty | Teaching staff | Know exactly where and when to teach; request changes; communicate |
| Institution (indirect) | Registrar, QA, administration | Conflict-free, auditable, reportable timetables |

---

## 4. Roles and access control (RBAC)

Four roles. Permissions are declarative (`role_permissions`) so they can be tuned without code changes.

Legend: **C** create, **R** read, **U** update, **D** delete, **A** approve/reject, **—** no access.

| Module | Administrator | Dean | Secretary | Faculty |
|---|---|---|---|---|
| Users & accounts | CRUD | R (own dept) | R (own dept) | R (self) |
| Roles & permissions | CRUD | — | — | — |
| Departments | CRUD | R/U (own) | R (own) | R (own) |
| Buildings & rooms | CRUD | R | R/U (own dept allocations) | R |
| Subjects | CRUD | CRUD (own dept) | R (own dept) | R (own dept) |
| Sections | CRUD | CRUD (own dept) | R (own dept) | R |
| Faculty profiles | CRUD | CRU (own dept) | R (own dept) | R (self) |
| Faculty availability | CRUD | CRU (own dept) | R | RU (self) |
| Academic terms | CRUD | R | R | R |
| Schedules | CRUD | R + A (own dept) | CRUD (own dept) | R (own) |
| Change requests | R + A | A (own dept) | CRU (own dept) | C (own) |
| Conflict records | R | R (own dept) | R (own dept) | R (own) |
| Timetable generator | run | run + A (own dept) | run (own dept) | — |
| Reports & exports | all | own dept | own dept | own |
| Notifications | all | own | own | own |
| Settings & audit log | CRUD | — | — | — |

**Data scoping rule (security-critical):** every non-administrator query is implicitly filtered by `department_id`. Role is a *ceiling*, department scope is a *filter*. A Dean may approve only schedules whose subject, section or room allocation belongs to their department. Faculty may read only schedules where they are the assigned teacher.

---

## 5. Functional requirements

Each requirement is atomic, verifiable and assigned to the phase that implements it.

### 5.1 Authentication & session (AUTH)

| ID | Requirement | Priority |
|---|---|---|
| AUTH-01 | Users sign in with an institutional email of the form `username@sac.edu.ph` plus a password. | Must |
| AUTH-02 | Passwords are stored only as `password_hash()` output (bcrypt/argon2id) and verified with `password_verify()`. | Must |
| AUTH-03 | Successful login regenerates the session ID; failed login regenerates the ID and never reveals whether the email or the password was wrong. | Must |
| AUTH-04 | Logout destroys the session server-side and clears the session cookie. | Must |
| AUTH-05 | "Remember me" issues a rotating selector/validator token (hashed at rest), valid 30 days, revoked on logout or password change. | Should |
| AUTH-06 | Idle timeout after 30 minutes of inactivity; absolute session lifetime 8 hours. | Must |
| AUTH-07 | Brute-force protection: after 5 failed attempts for one email+IP within 15 minutes, further attempts are throttled and an audit event is written. | Must |
| AUTH-08 | Inactive/archived accounts cannot authenticate even with correct credentials. | Must |
| AUTH-09 | Every state-changing request carries and validates a CSRF token bound to the session. | Must |

### 5.2 Master data (MD)

| ID | Requirement |
|---|---|
| MD-01 | Administrators manage departments (code, name, dean, status). |
| MD-02 | Administrators manage buildings (code, name, campus) and rooms (code, building, floor, type lecture/laboratory, capacity, facilities, status). |
| MD-03 | Departments manage subjects (code, title, units, lecture hours, lab hours, subject type, owning department, active flag). |
| MD-04 | Departments manage sections (code, program, year level, student count, owning department, active flag). |
| MD-05 | Faculty profiles link a user to a department, employment type, maximum teaching load (units) and availability windows. |
| MD-06 | Academic years and semesters (1st, 2nd, summer) are first-class records; only one semester per academic year may be `current`. |
| MD-07 | All master data supports enable/disable rather than hard deletion once referenced by a schedule (referential integrity over destructive deletes). |
| MD-08 | Every master-data list supports search, column sort, and filters (department, status, type, building). |

### 5.3 Scheduling (SCH)

| ID | Requirement |
|---|---|
| SCH-01 | A schedule entry binds: academic term, department, section, subject, faculty, room, day of week, start time, end time, status, recurrence (weekly or one-off), and free-text notes. |
| SCH-02 | Creating or editing an entry runs the full conflict check (§6) before persisting; a blocked save returns the specific conflicts plus alternatives. |
| SCH-03 | Entries may be created from the calendar (draw/drag), from a form, from a bulk/recurring builder, or from the generator. All paths use the same validation service. |
| SCH-04 | Weekly-recurring entries store one row per meeting day (normalised), never a serialised weekday string. |
| SCH-05 | Status lifecycle: `draft → pending → approved → published`, plus `rejected` and `cancelled`. Only `approved`/`published` entries appear on published timetables. |
| SCH-06 | Moving an entry on the calendar (drag/resize) is a normal update and re-runs conflict detection. |
| SCH-07 | Every change is audited (actor, before/after, timestamp, IP). |

### 5.4 Conflict detection (CNF)

| ID | Requirement |
|---|---|
| CNF-01 | Detect **room** double-booking: two entries in the same room on the same day with overlapping time. |
| CNF-02 | Detect **faculty** double-booking. |
| CNF-03 | Detect **section** double-booking. |
| CNF-04 | Detect **duplicate subject allocation** (same subject + section + term more than once). |
| CNF-05 | Detect **capacity mismatch** (section size > room capacity) and **room type mismatch** (laboratory subject in a lecture room). |
| CNF-06 | Detect **faculty load overflow** (assigned units would exceed `max_load_units`). |
| CNF-07 | Detect **availability violations** (entry outside the faculty's available windows) and **policy violations** (outside allowed teaching hours, overlapping a break period, outside the term's date range). |
| CNF-08 | Conflict output is human-readable and specific: "Room 204 is already occupied from 10:00 AM to 11:00 AM by BSIT-2A / IT-201." |
| CNF-09 | On conflict, the system proposes alternative rooms, alternative time slots, and alternative faculty that resolve *all* hard conflicts. |
| CNF-10 | Conflict severity is classified `error` (blocks save) or `warning` (advisory, e.g. 90% room capacity). |
| CNF-11 | Conflict checks must complete in under 100 ms for a single entry against 5,000 existing entries. |

### 5.5 Timetable generator (GEN)

| ID | Requirement |
|---|---|
| GEN-01 | The generator is deterministic constraint solving, **not** generative AI and not a remote API call. |
| GEN-02 | Inputs: term, department(s), sections and their required subjects, eligible faculty, eligible rooms, faculty availability, allowed hours, break periods, load limits. |
| GEN-03 | Output is a set of candidate entries with zero hard conflicts, or an explicit explanation of the unsatisfiable requirement. |
| GEN-04 | Generation is previewable and transactional: nothing is written until an administrator or dean commits the draft. |
| GEN-05 | Objective function prefers: satisfy all requirements → minimize room waste → balance faculty load → minimize faculty idle gaps → prefer preferred rooms. |
| GEN-06 | The run reports utilization and workload metrics for the produced draft. |

### 5.6 Calendar (CAL)

| ID | Requirement |
|---|---|
| CAL-01 | FullCalendar views: month, week, day, plus a timetable (grid) view. |
| CAL-02 | Events show subject, section, teacher, room, time and status, colour-coded by department. |
| CAL-03 | Drag-and-drop and resize persist through the same validated update path as the form. |
| CAL-04 | Filters: department, teacher, room, building, section, semester, academic year, status. Filters apply server-side. |
| CAL-05 | The calendar loads only the visible date range (range-based API query), keeping payloads small. |
| CAL-06 | Clicking an event opens a detail drawer with notes, comments, change requests and an edit action if permitted. |

### 5.7 Collaboration (COL)

| ID | Requirement |
|---|---|
| COL-01 | Threaded comments on schedule entries with `@mention` support. |
| COL-02 | Task cards (Trello-style) attached to a schedule or to a department board, with assignee, due date and checklist state. |
| COL-03 | Change requests: faculty/secretary proposes, dean/admin approves or rejects with a reason; approval applies the change atomically and re-validates conflicts. |
| COL-04 | Activity feed per schedule and per department. |
| COL-05 | Assignment tracking for outstanding change requests and tasks. |

### 5.8 Notifications (NTF)

| ID | Requirement |
|---|---|
| NTF-01 | In-app notification centre with unread count, mark-as-read, mark-all-read. |
| NTF-02 | Email notifications via PHPMailer (SMTP configurable), respecting per-user preferences. |
| NTF-03 | Triggers: conflict detected, approval requested, approval decided, schedule changed/cancelled, mention received, task assigned or due. |
| NTF-04 | Notifications are queued and dispatched by a cron-safe worker so a slow SMTP server never blocks a request. |

### 5.9 Search (SRCH)

| ID | Requirement |
|---|---|
| SRCH-01 | Global search across subjects, teachers, rooms, schedules, departments, users. |
| SRCH-02 | Results are grouped by type, permission-filtered, and keyboard navigable. |
| SRCH-03 | Advanced filters on every list screen (§5.10). |

### 5.10 Reports (RPT)

| ID | Requirement |
|---|---|
| RPT-01 | Faculty load report (units and hours per teacher, per term). |
| RPT-02 | Room utilization report (% of available hours actually booked, per room and per building). |
| RPT-03 | Department schedule report and weekly/semester timetable. |
| RPT-04 | Conflict report (open, resolved, historical). |
| RPT-05 | Teacher schedule report. |
| RPT-06 | Export to PDF (DomPDF/FPDF), Excel (PHPSpreadsheet) and print-friendly HTML. |
| RPT-07 | Every report respects the viewer's department scope. |

### 5.11 Dashboard (DASH)

| ID | Requirement |
|---|---|
| DASH-01 | Widgets: total teachers, subjects, rooms, today's classes, active conflicts, department statistics, room utilization %, faculty workload, upcoming sessions. |
| DASH-02 | Widgets are role-aware; a faculty member sees their own day, a dean sees their department, an administrator sees the institution. |
| DASH-03 | Charts render with Chart.js from JSON endpoints; no chart data is hard-coded in views. |

### 5.12 Administration (SYS)

| ID | Requirement |
|---|---|
| SYS-01 | Settings store (institution name, logo, allowed hours, break periods, default semester, SMTP credentials). |
| SYS-02 | Audit log of authentication, authorisation failures and all schedule mutations. |
| SYS-03 | Installer/CLI script to create the schema and seed demo data non-interactively. |
| SYS-04 | Health check endpoint reporting DB connectivity, writable storage and version. |

---

## 6. Business rules (conflict semantics)

These rules are normative — the conflict engine and generator must implement exactly these definitions.

| ID | Rule |
|---|---|
| BR-01 | **Overlap definition:** two entries on the same weekday overlap iff `a.start < b.end AND b.start < a.end`. Touching intervals (10:00–11:00 and 11:00–12:00) are *not* conflicts. |
| BR-02 | A room conflict is an overlap in the same `room_id` on the same day in the same academic term, where neither entry is `cancelled`. |
| BR-03 | A faculty and a section conflict use the same interval test on `faculty_id` and `section_id`. |
| BR-04 | Only `active` rooms/teachers may be assigned; `draft`/`cancelled` entries never block a room in published timetables, but *do* block in conflict previews. |
| BR-05 | Laboratory subjects require rooms of type `laboratory`; lecture subjects may use either type. |
| BR-06 | Section size must be ≤ room capacity. Exceeding 90% capacity is a warning, exceeding 100% is an error. |
| BR-07 | A faculty member's total assigned units in a term may not exceed `max_load_units`. |
| BR-08 | Entries must fall inside institution allowed hours (default 07:00–19:00) and must not overlap configured break periods. |
| BR-09 | One section may not be scheduled for two different subjects at the same time (special case of BR-03). |
| BR-10 | A subject may be allocated to a section at most once per term, except when split into multiple meeting days that together respect the subject's weekly contact hours. |
| BR-11 | Approval requires: no `error`-severity conflicts, the approver's department scope, and a `pending` status. |
| BR-12 | Deleting a referenced room/teacher/subject is forbidden; it must be deactivated instead. |
| BR-13 | Time is stored in 24-hour `TIME` columns; display follows the institution's 12-hour format preference. |
| BR-14 | Only one semester per academic year may be `current`; publishing a timetable for a non-current term is allowed but flagged. |

---

## 7. Non-functional requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-01 | Stack | Native PHP 8.1+ only. No Laravel/Symfony/CodeIgniter or any MVC framework. Composer libraries permitted: PHPMailer, DomPDF, FPDF, PHPSpreadsheet, Carbon. |
| NFR-02 | Database | MySQL 8.x / MariaDB 10.4+ compatible, database `anth04_SyncClass`, InnoDB, utf8mb4. |
| NFR-03 | Frontend | Bootstrap 5, vanilla JS (no React/Vue/jQuery dependency), Fetch/AJAX, Chart.js, FullCalendar, SortableJS. |
| NFR-04 | Hosting | Runs on XAMPP (Apache + MySQL) at `http://localhost/syncclass/` and on typical shared hosting via `public/` as document root, with a fallback bootstrap for hosts where the document root cannot be changed. |
| NFR-05 | Portability | No absolute host paths in code; base URL resolved from configuration/request. |
| NFR-06 | Performance | Calendar month load ≤ 1.5 s at 1,000 entries; conflict check ≤ 100 ms; dashboard ≤ 1 s; paginated lists ≤ 50 rows/page. |
| NFR-07 | Security | OWASP Top 10 mitigations: prepared statements everywhere, contextual output escaping, CSRF on all mutations, session hardening, password hashing, upload validation, no secrets in the web root. |
| NFR-08 | Accessibility | WCAG 2.1 AA for contrast, focus visibility, labels, and keyboard operation of forms and modals. |
| NFR-09 | Responsiveness | Usable at 360 px, 768 px and 1280 px+; offcanvas navigation on small screens. |
| NFR-10 | Maintainability | PSR-12, namespaced PSR-4 autoloading, typed properties/returns, layered separation, no duplicated SQL outside repositories. |
| NFR-11 | Observability | Structured application log + audit log; no stack traces or SQL shown to end users. |
| NFR-12 | Backup/ops | Schema and seed scripts are re-runnable; demo data is separable from production seeding. |
| NFR-13 | Browser support | Last two versions of Chrome, Edge, Firefox, Safari. |
| NFR-14 | Localisation | English only, but all user-facing strings centralised for future translation. |

---

## 8. Data requirements (summary)

The full schema is Phase 3; this is the required entity set and cardinality.

| Entity | Key relationships | Notes |
|---|---|---|
| `users` | → roles, departments | 1:N with comments/tasks/notifications |
| `roles`, `permissions`, `role_permissions` | many-to-many | declarative RBAC |
| `departments` | dean → users | 5 seeded |
| `buildings` | 1:N rooms | multiple campuses |
| `rooms` | → buildings | 30 seeded, type + capacity |
| `subjects` | → departments | 50 seeded, units + contact hours |
| `sections` | → departments | program, year level, size |
| `faculty_profiles` | 1:1 users, → departments | max load units |
| `faculty_availability` | → faculty_profiles | day + window + availability type |
| `academic_years`, `semesters` | 1:N | one `current` semester |
| `schedules` | → semester, department, section, subject, faculty, room | one row per meeting |
| `schedule_conflicts` | → schedules | persisted conflict log with severity + resolution |
| `schedule_comments` | → schedules, users | threaded, mentions |
| `change_requests` | → schedules, users | state machine |
| `tasks`, `task_assignments` | → schedules/users | Trello-style cards |
| `notifications` | → users | in-app + email queue |
| `activity_log` | polymorphic | audit trail |
| `settings` | key/value | typed |

Seeded demo volume: 5 departments, 20 faculty, 50 subjects, 30 rooms, 100 schedules, 4 roles, multiple semesters and sections, multiple buildings.

---

## 9. Assumptions and constraints

1. Users have institutional `@sac.edu.ph` accounts; email is the unique identifier. There is no self-registration and no public sign-up.
2. A single institution is served per installation (no multi-tenancy).
3. Time is institutional-local; timezone is a configuration value, not per-user.
4. One teaching session is one contiguous block on one weekday; no cross-midnight sessions.
5. Rooms are exclusive resources; two departments cannot share a room concurrently.
6. Students are counted, not modelled — no student-user accounts in this scope.
7. Cron (or a documented pseudo-cron URL) is available for notification dispatch and reminders.
8. The generator's correctness matters more than its speed; a departmental draft may take seconds.
9. Shared hosting may forbid `putenv`/`exec`; nothing may depend on shell execution at runtime.
10. Email delivery depends on a working SMTP account supplied at install time; without it, in-app notifications still work.

---

## 10. Out of scope

- Student accounts, enrolment, grades, attendance, learning materials.
- Payroll/finance and HR records beyond teaching load.
- Real-time collaborative editing (live cursors), video, chat rooms.
- Generative AI or LLM-based scheduling.
- Mobile native apps (responsive web only).
- Multi-campus synchronisation or a distributed database.
- Two-way sync with Google Calendar / Microsoft 365.

---

## 11. Acceptance criteria

Phase 1 is complete when a reviewer can confirm:

1. Every module in the project brief has at least one functional requirement covering it (§5 coverage check).
2. Every role has an explicit permission row and no module has an undefined permission column (§4).
3. Every blocking rule in the brief is expressible as a numbered business rule (§6) and is testable by a single boolean assertion.
4. Each requirement maps to exactly one implementation phase (§12).
5. Non-functional targets are numeric where measurable (NFR-06).
6. Assumptions and exclusions are explicit enough that no later "missing feature" is genuinely ambiguous.

**UAT scenarios that must eventually pass** (used as end-to-end tests from Phase 18):

- **UAT-1** Secretary attempts to book Room 204 for BSIT-2A at 10:00–11:00 when it is already booked; save is refused with a specific message and alternatives.
- **UAT-2** Dean approves a pending timetable; status becomes `approved` and the faculty sees the change in their calendar and notifications.
- **UAT-3** Administrator generates a department timetable; the draft contains zero hard conflicts and reports utilization.
- **UAT-4** Faculty attempts to edit a schedule by calling the endpoint directly; the request is rejected with 403 and audited.
- **UAT-5** Room utilization report for a term matches independently computed booked hours.
- **UAT-6** Drag-and-drop move of an event that would collide is rejected and the calendar reverts.

---

## 12. Requirement-to-phase traceability

| Phase | Covers |
|---|---|
| 1 Requirements Analysis | §§1–11 |
| 2 Architecture Design | `docs/ARCHITECTURE.md`, ADRs |
| 3 Database Design | §8; BR-01…BR-14 (design: index and constraint support only) |
| 4 Folder Structure | — |
| 5 Security Architecture | NFR-07; AUTH-01…09 and §4 scoping (design inputs) |
| 6 Core MVC Framework | NFR-04, NFR-10 |
| 7 Authentication | AUTH-01…09 |
| 8 RBAC | §4, SYS-02 |
| 9 Master Data Modules | MD-01…08, BR-12 |
| 10 Scheduling Engine | SCH-01…07, SRCH-01…03 |
| 11 Conflict Detection | CNF-01…11, BR-01…BR-11 |
| 12 Calendar System | CAL-01…06 |
| 13 Timetable Generator | GEN-01…06 |
| 14 Collaboration & Notifications | COL-01…05, NTF-01…04 |
| 15 Reports | RPT-01…07 |
### 13.1 Phase 13 — Timetable generator (GEN-01…06)

| ID | Requirement | Status |
|---|---|---|
| GEN-01 | Deterministic constraint solving, not generative AI | **done** |
| GEN-02 | Inputs: term, department(s), sections, subjects, faculty, rooms, availability, policy | **done** |
| GEN-03 | Output: conflict-free candidate entries, or unsatisfiable explanation | **done** |
| GEN-04 | Previewable, transactional commit | **done** |
| GEN-05 | Objective: satisfy requirements → min room waste → load balance → min idle gaps | **done** |
| GEN-06 | Utilization and workload metrics | **done** |

**Files created** (Phase 13):

- `app/Services/Generator/TimetableGenerator.php` — the deterministic search
  driver; expands requirements, maintains the bounded candidate space,
  backtracks with forward checking and conflict-directed backjumping, and
  commits the best draft.
- `app/Services/Generator/DraftSession.php` — the in-session draft timer and
  node counter (ADR-06).
- `app/Services/Generator/ConstraintEvaluator.php` — wraps `ConflictService`
  so the generator and the form/calendar share the same feasibility check.
- `app/Services/Generator/RoomMatcher.php`, `FacultyMatcher.php` — candidates
  filtered by subject preference, capacity, department and load.
- `app/Controllers/Generator/GeneratorController.php` — GET `/generator`,
  POST `/generator/preview`, POST `/generator/commit`.
- `app/Views/generator/plan.php`, `result.php`, `_partial.php` — the planning
  form, the run result with metrics and the commit action.
- `public/assets/js/generator.js` — runs the generator, renders the draft
  timetable, wires commit.
- `routes/api.php` — `POST /generator/preview`, `POST /generator/commit`.
- `database/schema/SyncClass.sql` — `GEN-02` demand input is already the
  `section_subjects` table (subjects × sections); the generator reads it.

**Testing procedure** (to be run on the local XAMPP installation):

```bash
cd /workspace/chats/e3c32778e44f5cc31faf9e26
php tests/generator_test.php
```

Generates a draft against the seeded term, verifies zero hard conflicts,
verifies the draft commits transactionally as `draft` rows, and re-uses the
existing `ConflictService::check()` coverage to confirm the generator has
not introduced a new rule. The demo data (5 departments, 20 faculty, 50
subjects, 30 rooms, 30 schedule records) gives the generator enough volume
to exercise the backtracking and the objective scorer.

**Next phase** — Phase 14: Collaboration & Notifications (NTF-01…04, COL-01…05) and Phase 15: Reports + exports (RPT-01…07).
| 16 Dashboard Analytics | DASH-01…03 |
| 17 UI Polish | NFR-08, NFR-09 |
| 18 Testing | §11 UAT-1…6, CNF-11 (verification) |
| 19 Seed Data | §8 demo volume; demo accounts for all four roles |
| 20 Deployment Package | NFR-05, NFR-12, SYS-01, SYS-03, SYS-04 |

The **first** phase listed for each requirement is its primary implementing phase. References marked *design*, *verification* or *deployment verification* are downstream/upstream uses of an ID whose implementation lives in the primary phase, so each requirement has exactly one implementation owner. NFR-04 is implemented in Phase 6 and re-verified in Phase 20; CNF-11 is implemented in Phase 11 and re-verified in Phase 18.

---

## 13. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Native PHP drifts into framework-like over-engineering | Schedule slip, unreadable code | Architecture doc fixes layer responsibilities; ADRs require justification for new abstractions |
| Conflict detection is duplicated in form, calendar and generator paths | Inconsistent saves, data corruption | Single `ConflictService` used by all write paths (SCH-03) |
| Shared hosting lacks `mod_rewrite` or a writable `storage/` | App unusable on target host | Fallback front-controller bootstrap; documented permissions; health check (SYS-04) |
| Calendar payload grows with the term | Slow UI | Range-scoped API queries (CAL-05) and server-side filters (CAL-04) |
| Generator produces "valid" but pedagogically poor timetables | Low adoption | Explicit objective ordering (GEN-05) plus mandatory human approval (BR-11) |
| Email failures block user actions | Lost requests | Queued dispatch (NTF-04), in-app fallback |
| Department scope implemented only in views | Data leak across departments | Enforcement in repository/service layer, tested via UAT-4 |

---

## 14. Glossary

- **Section** — a cohort of students (e.g. BSIT-2A) that takes a set of subjects together.
- **Term** — an academic year plus semester pair.
- **Entry / session** — one scheduled meeting: subject + section + teacher + room + day + time.
- **Hard conflict** — a violation that must block persistence (`error`).
- **Soft conflict** — a warning that permits persistence but is flagged.
- **Draft timetable** — generator output not yet committed.
- **Scope** — the department a user is allowed to read and mutate.

---

## 15. Testing procedure for this phase

Documentation review, not execution:

1. **Coverage walk** — tick off every feature named in the project brief against §5; anything unticked is a defect in this document.
2. **Role walk** — for each of the four roles, read §4 down the columns; confirm no `—` is required by the brief and no `CRUD` exceeds it.
3. **Rule walk** — for each of CNF-01…CNF-11, locate the corresponding BR rule and confirm it is expressed as a deterministic boolean test.
4. **Traceability walk** — expand every range in §12 (e.g. `AUTH-01…09` means AUTH-01 through AUTH-09 inclusive) and confirm each requirement ID is covered exactly once and by exactly one phase.
5. **Ambiguity walk** — confirm every assumption in §9 that could change behaviour is either resolved or flagged for Phase 2.

Result: all five walks pass; no open questions blocking architecture.

---

## 16. Next phase

**Phase 2 — Architecture Design** (`docs/ARCHITECTURE.md`): layered MVC structure, request lifecycle, module boundaries, service/repository contracts, conflict and generator architecture, security architecture, ADRs, and the 20-phase roadmap. Requirement IDs from this document are the traceability input for that design.
