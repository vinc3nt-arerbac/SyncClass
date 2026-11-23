# SyncClass — Database Design

**Document:** `docs/DATABASE.md`
**Phase:** 3 of 20 — Database Design
**Version:** 1.0
**Status:** Ready for review
**Inputs:** `docs/REQUIREMENTS.md` (§8 data requirements, BR-01…BR-14), `docs/ARCHITECTURE.md` (§7 domain model, §8 conflict engine, ADR-04/05/11)
**Targets:** MySQL 8.0+ (primary), MariaDB 10.4+ (shared hosting), InnoDB, `utf8mb4`

---

## 1. Objective and deliverables

Phase 3 turns the logical domain from Phase 2 into a physical schema that the
repositories of Phase 6+ can code against: every entity, key, constraint and
index decided once, in versioned SQL, before any PHP is written.

| Deliverable | Location |
|---|---|
| This design document + data dictionary | `docs/DATABASE.md` |
| Entity-relationship diagrams | `database/schema/ERD.md` |
| Numbered, reversible migrations (source of truth) | `database/migrations/NNN_*.up.sql` / `.down.sql` |
| Single-file install schema (generated) | `database/schema/SyncClass.sql` |
| Schema builder | `database/schema/build.php` |

**Result:** 30 tables, 49 foreign keys, 15 CHECK constraints, 81 secondary
indexes (23 unique, 58 non-unique), one database (`anth04_SyncClass`) that
imports in a single command.

---

## 2. Design principles

1. **Third normal form for master data; denormalise only with a reason.** The
   single deliberate duplication is `schedules.department_id`, which is copied
   from the section so that department scoping, dashboard counts and reports
   never need a three-table join. `ScheduleService` derives it from the section
   — user input is never trusted for it.
2. **Surrogate primary keys, protected natural keys.** Every table has an
   `INT UNSIGNED` (or `BIGINT UNSIGNED` for high-volume logs) auto-increment
   key. Human codes (`departments.code`, `subjects.code`, `sections.code`,
   `rooms.code` per building) carry `UNIQUE` constraints instead of being
   reused as join keys, so a code can be corrected without cascading updates.
3. **Predictable names.** `fk_<child>_<parent>`, `uq_<table>_<purpose>`,
   `idx_<table>_<purpose>`, `chk_<table>_<field>`. Constraint names are unique
   in the schema (MySQL requires it for CHECK), which makes error messages and
   down-migrations readable.
4. **One storage profile.** `ENGINE=InnoDB`, `utf8mb4`, `utf8mb4_unicode_ci`
   on every table. `utf8mb4_unicode_ci` is chosen over MySQL 8's
   `utf8mb4_0900_ai_ci` because MariaDB 10.4 — common on shared hosting — does
   not ship the 0900 collations.
5. **`DATETIME`, never `TIMESTAMP`.** Sessions, schedules and audit rows are
   institutional local time recorded as a literal wall-clock value; TIMESTAMP's
   implicit UTC conversion is a bug source when the database server and PHP
   disagree about the timezone. `TIME` stores 24-hour clock values (BR-13).
6. **Closed sets are `ENUM`; admin-editable sets are tables.** Workflow states
   (`draft`/`pending`/…) are enums because adding a state is a code change
   anyway. Departments, rooms, subjects and terms are tables because the
   administrator owns them.
7. **Deactivate, don't delete (ADR-11).** Master data is `RESTRICT`-protected by
   every foreign key that references it (BR-12). Transactional children
   (comments, conflict rows, checklist items, tokens) use `CASCADE` because
   their life is genuinely owned by the parent.
8. **Audit rows are append-only.** `activity_log` is written inside the same
   transaction as the change and never updated; the application's database user
   can be denied `UPDATE`/`DELETE` on it (see §13).
9. **SQL lives in repositories, not in views or triggers.** No database views:
   reports are read models with their own SQL (ADR-02). No triggers: hidden
   writes break the "one write path" rule (ADR-03) and make dump/restore and
   shared-hosting imports fragile.
10. **Nullable means "unknown or not applicable".** No empty-string sentinels,
    no `0000-00-00` dates, no `-1` foreign keys.
11. **Every mutable table has `created_at` / `updated_at`**, both `DATETIME NOT
    NULL`, `updated_at` with `ON UPDATE CURRENT_TIMESTAMP`.
12. **Decimals for quantities.** `units`, `hours`, `max_load_units` are
    `DECIMAL`, never `FLOAT`, so load arithmetic in reports is exact.

---

## 3. Entity map

```
IDENTITY & ACCESS                 ACADEMIC REFERENCE
roles ──< role_permissions >──    academic_years ──< semesters ──< section_subjects
  │      permissions                    │                            │
  └──< users ──1:1── faculty_profiles   │                            │
        │   │            │              │                            │
        │   └──< remember_tokens        │                            │
        │   └──< login_attempts         │                            │
        └──< notifications             │                            │
        └──< activity_log              │                            │
                                       ▼                            ▼
ORGANISATION                    SCHEDULING CORE  ◄──── departments, sections,
departments ──< buildings ──< rooms        subjects, faculty_profiles
      │                                    │
      └──< subjects, sections, faculty_profiles
                                           │
                                           ├──< schedule_conflicts
                                           ├──< schedule_comments ──< comment_mentions
                                           ├──< change_requests
                                           └──< tasks ──< task_assignments
                                                      └──< task_checklist_items
```

Full Mermaid diagrams, cardinalities and every relationship are in
[`database/schema/ERD.md`](../database/schema/ERD.md).

---

## 4. Table catalogue

| # | Table | Module | Purpose | Est. rows (steady state) |
|---|---|---|---|---|
| 1 | `roles` | RBAC | The four institution roles | 4 |
| 2 | `permissions` | RBAC | Declarative permission codes | ~50 |
| 3 | `role_permissions` | RBAC | Role → permission grant | ~120 |
| 4 | `departments` | Organisation | Academic departments + dean + calendar colour | 5–15 |
| 5 | `users` | Identity | Accounts for all four roles | 30–500 |
| 6 | `remember_tokens` | Identity | Rotating remember-me selector/validator pairs | 1 per active device |
| 7 | `login_attempts` | Identity | Raw auth attempts for AUTH-07 throttling | pruned at 30 days |
| 8 | `buildings` | Facilities | Buildings and campuses | 3–10 |
| 9 | `rooms` | Facilities | Schedulable rooms with type and capacity | 30–200 |
| 10 | `academic_years` | Terms | Academic years | 3–6 |
| 11 | `semesters` | Terms | Terms (1st / 2nd / summer), one current | ~12 |
| 12 | `time_slots` | Terms | Standard teaching periods and break blocks | ~26 |
| 13 | `subjects` | Catalog | Subjects with units and contact hours | 50–400 |
| 14 | `sections` | Catalog | Student cohorts | 20–150 |
| 15 | `section_subjects` | Catalog | Term curriculum: what a section must take | ~10 per section |
| 16 | `faculty_profiles` | Faculty | Teaching profile, load cap, employment type | 20–300 |
| 17 | `faculty_subjects` | Faculty | Qualifications and preferences | ~8 per faculty |
| 18 | `faculty_availability` | Faculty | Availability / preference / block windows | ~5 per faculty |
| 19 | `schedules` | Scheduling | One row per meeting (the core entity) | 100 (seed) → ~2,000/term |
| 20 | `schedule_conflicts` | Scheduling | Persisted conflict detections and resolutions | ~1 % of schedules |
| 21 | `schedule_comments` | Collaboration | Threaded comments on entries | ~5 per schedule |
| 22 | `comment_mentions` | Collaboration | Resolved `@mention` recipients | ~1 per 3 comments |
| 23 | `change_requests` | Collaboration | Proposed changes and approval workflow | ~50/term |
| 24 | `tasks` | Collaboration | Trello-style cards, department board or entry | ~100/term |
| 25 | `task_assignments` | Collaboration | Many-to-many task assignees | ~1.5 per task |
| 26 | `task_checklist_items` | Collaboration | Checklist state per card | ~3 per task |
| 27 | `notifications` | Notifications | In-app inbox + email queue | ~100/user/month |
| 28 | `activity_log` | Audit | Append-only activity/audit trail | ~10,000/month |
| 29 | `settings` | System | Typed key/value configuration (SYS-01) | ~35 |
| 30 | `schema_migrations` | System | Applied migration bookkeeping (SYS-03) | ~10 |

---

## 5. Data dictionary

Notation: **PK** primary key, **UK** unique key, **FK** foreign key,
**IDX** non-unique index. `—` means the column is nullable and has no default.

### 5.1 `roles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(50) | no | — | UK. `administrator`, `dean`, `secretary`, `faculty` |
| `name` | VARCHAR(100) | no | — | Display name |
| `description` | VARCHAR(255) | yes | NULL | Purpose of the role |
| `sort_order` | TINYINT UNSIGNED | no | 10 | Display order in admin screens |
| `is_system` | TINYINT(1) | no | 0 | System roles cannot be deleted |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_roles_code (code)`.

### 5.2 `permissions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(80) | no | — | UK. Dot notation: `schedules.create`, `reports.export` |
| `module` | VARCHAR(40) | no | — | IDX. `schedules`, `reports`, `admin`, … |
| `name` | VARCHAR(120) | no | — | Human label |
| `description` | VARCHAR(255) | yes | NULL | |
| `created_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_permissions_code`; IDX `idx_permissions_module`.

### 5.3 `role_permissions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `role_id` | INT UNSIGNED | no | — | PK, FK → `roles.id` CASCADE |
| `permission_id` | INT UNSIGNED | no | — | PK, FK → `permissions.id` CASCADE, IDX |
| `created_at` | DATETIME | no | now | |

**Keys:** composite PK `(role_id, permission_id)`; IDX `idx_role_permissions_permission`.

### 5.4 `departments`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(20) | no | — | UK. e.g. `CAS`, `BSIT` |
| `name` | VARCHAR(150) | no | — | |
| `short_name` | VARCHAR(50) | yes | NULL | Compact label for badges |
| `dean_user_id` | INT UNSIGNED | yes | NULL | UK, FK → `users.id` SET NULL. One dean per department |
| `email` | VARCHAR(150) | yes | NULL | Department mailbox |
| `phone` | VARCHAR(30) | yes | NULL | |
| `color_hex` | CHAR(7) | no | `#8C1D40` | Calendar colour (CAL-02), maroon default |
| `is_active` | TINYINT(1) | no | 1 | IDX |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_departments_code`, `uq_departments_dean`; IDX `idx_departments_active`.
**Rules:** Created before `users`; the dean FK is added by `ALTER TABLE` after
`users` exists (circular reference).

### 5.5 `users`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `role_id` | INT UNSIGNED | no | — | IDX, FK → `roles.id` RESTRICT |
| `department_id` | INT UNSIGNED | yes | NULL | IDX, FK → `departments.id` RESTRICT. NULL for system administrators |
| `email` | VARCHAR(150) | no | — | UK. Login identifier, `username@sac.edu.ph` (AUTH-01) |
| `username` | VARCHAR(60) | no | — | UK. Local part, for `@mention` resolution and display |
| `password_hash` | VARCHAR(255) | no | — | `password_hash()` output only (AUTH-02) |
| `first_name` | VARCHAR(80) | no | — | |
| `middle_name` | VARCHAR(80) | yes | NULL | |
| `last_name` | VARCHAR(80) | no | — | |
| `employee_no` | VARCHAR(30) | yes | NULL | UK |
| `phone` | VARCHAR(30) | yes | NULL | |
| `avatar_path` | VARCHAR(255) | yes | NULL | Relative to `public/assets/uploads` |
| `is_active` | TINYINT(1) | no | 1 | IDX. AUTH-08 blocks inactive accounts |
| `must_change_password` | TINYINT(1) | no | 0 | Forces reset after admin provisioning |
| `failed_login_attempts` | TINYINT UNSIGNED | no | 0 | Counter for AUTH-07 |
| `locked_until` | DATETIME | yes | NULL | Temporary lock after repeated failures |
| `last_login_at` | DATETIME | yes | NULL | |
| `password_changed_at` | DATETIME | yes | NULL | Revokes remember tokens (AUTH-05) |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_users_email`, `uq_users_username`, `uq_users_employee_no`; IDX `idx_users_role`, `idx_users_department`, `idx_users_active`.
**Rules:** No `deleted_at`; archive by `is_active = 0`. `employee_no` is
nullable but unique when present (MySQL allows multiple NULLs in a UNIQUE key).

### 5.6 `remember_tokens`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `user_id` | INT UNSIGNED | no | — | IDX, FK → `users.id` CASCADE |
| `selector` | CHAR(24) | no | — | UK. Random lookup half of the cookie |
| `validator_hash` | CHAR(64) | no | — | SHA-256 of the validator; raw value never stored |
| `expires_at` | DATETIME | no | — | IDX. 30 days from issue |
| `last_used_at` | DATETIME | yes | NULL | Rotation audit |
| `ip_address` | VARCHAR(45) | yes | NULL | IPv4/IPv6 |
| `user_agent` | VARCHAR(255) | yes | NULL | |
| `created_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_remember_tokens_selector`; IDX `idx_remember_tokens_user`, `idx_remember_tokens_expiry`.

### 5.7 `login_attempts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `email` | VARCHAR(150) | no | — | IDX with `attempted_at` |
| `ip_address` | VARCHAR(45) | no | — | IDX with `attempted_at` |
| `successful` | TINYINT(1) | no | 0 | Failures drive throttling |
| `user_agent` | VARCHAR(255) | yes | NULL | |
| `attempted_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_login_attempts_email_time (email, attempted_at)`, `idx_login_attempts_ip_time (ip_address, attempted_at)`.
**Rules:** No foreign keys — an attempt may target a non-existent address.
Retention is 30 days (cron prune).

### 5.8 `buildings`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(20) | no | — | UK |
| `name` | VARCHAR(120) | no | — | |
| `campus` | VARCHAR(120) | yes | NULL | Multiple campuses supported |
| `address` | VARCHAR(255) | yes | NULL | |
| `is_active` | TINYINT(1) | no | 1 | IDX |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_buildings_code`; IDX `idx_buildings_active`.

### 5.9 `rooms`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `building_id` | INT UNSIGNED | no | — | FK → `buildings.id` RESTRICT |
| `code` | VARCHAR(20) | no | — | UK together with building (`204`, `LAB-3`) |
| `name` | VARCHAR(120) | yes | NULL | |
| `floor` | SMALLINT | yes | NULL | Negative values allowed (basement) |
| `room_type` | ENUM(`lecture`,`laboratory`,`seminar`) | no | `lecture` | BR-05 |
| `capacity` | SMALLINT UNSIGNED | no | 30 | BR-06; CHECK > 0 |
| `facilities` | VARCHAR(500) | yes | NULL | Comma-separated tags (projector, AC, …) |
| `is_active` | TINYINT(1) | no | 1 | IDX. BR-04 |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_rooms_building_code`; IDX `idx_rooms_type_capacity`, `idx_rooms_active`; CHECK `chk_rooms_capacity`.

### 5.10 `academic_years`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(20) | no | — | UK. `2026-2027` |
| `start_date` | DATE | no | — | |
| `end_date` | DATE | no | — | CHECK after `start_date` |
| `is_current` | TINYINT(1) | no | 0 | IDX |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_academic_years_code`; IDX `idx_academic_years_current`; CHECK `chk_academic_years_dates`.

### 5.11 `semesters`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `academic_year_id` | INT UNSIGNED | no | — | FK → `academic_years.id` CASCADE |
| `term_no` | TINYINT UNSIGNED | no | — | 1 = first, 2 = second, 3 = summer; CHECK 1–3 |
| `name` | VARCHAR(40) | no | — | `1st Semester`, `Summer` |
| `code` | VARCHAR(30) | no | — | UK. `2026-2027-1` |
| `start_date` | DATE | no | — | |
| `end_date` | DATE | no | — | CHECK after `start_date` |
| `status` | ENUM(`planned`,`active`,`closed`) | no | `planned` | IDX |
| `is_current` | TINYINT(1) | no | 0 | BR-14 |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_semesters_code`, `uq_semesters_year_term (academic_year_id, term_no)`; IDX `idx_semesters_status`, `idx_semesters_dates`.
**Rules:** BR-14 (at most one `is_current` semester per academic year, and only
one current semester institution-wide) is enforced by `TermRepository`: it
clears the previous flag inside the same transaction that sets the new one and
returns a friendly validation error. There is no generated column doing this at
the storage layer because MariaDB — the server bundled with XAMPP — refuses a
generated column that projects a foreign-key column, so the schema must stay
portable between MySQL 8 and MariaDB 10.4+.

### 5.12 `time_slots`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(20) | no | — | UK. `T1`, `BREAK-AM` |
| `label` | VARCHAR(50) | no | — | `07:00 – 07:30` |
| `start_time` | TIME | no | — | 24-hour (BR-13) |
| `end_time` | TIME | no | — | CHECK after `start_time` |
| `is_break` | TINYINT(1) | no | 0 | Break periods for BR-08 |
| `sort_order` | TINYINT UNSIGNED | no | 0 | IDX |
| `is_active` | TINYINT(1) | no | 1 | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_time_slots_code`; IDX `idx_time_slots_sort`; CHECK `chk_time_slots_range`.
**Rules:** Reference data for the UI and generator; `schedules` does not
reference slot IDs, so irregular blocks remain possible.

### 5.13 `subjects`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(30) | no | — | UK. `IT-201` |
| `title` | VARCHAR(180) | no | — | |
| `department_id` | INT UNSIGNED | no | — | FK → `departments.id` RESTRICT, IDX with `is_active` |
| `units` | DECIMAL(4,1) | no | 3.0 | Load accounting (BR-07); CHECK ≥ 0 |
| `lecture_hours` | DECIMAL(4,1) | no | 3.0 | Weekly lecture contact hours |
| `lab_hours` | DECIMAL(4,1) | no | 0.0 | Weekly laboratory contact hours |
| `contact_hours` | DECIMAL(4,1) GENERATED STORED | no | computed | `lecture_hours + lab_hours` |
| `subject_type` | ENUM(`lecture`,`laboratory`,`mixed`) | no | `lecture` | IDX. Drives BR-05 |
| `preferred_room_type` | ENUM(`lecture`,`laboratory`,`seminar`) | yes | NULL | Generator preference only, never a constraint |
| `description` | TEXT | yes | NULL | |
| `is_active` | TINYINT(1) | no | 1 | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_subjects_code`; IDX `idx_subjects_department_active`, `idx_subjects_type`; CHECK `chk_subjects_units`, `chk_subjects_hours`.

### 5.14 `sections`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `code` | VARCHAR(30) | no | — | UK. `BSIT-2A` |
| `name` | VARCHAR(120) | yes | NULL | Long description |
| `program` | VARCHAR(120) | yes | NULL | `BS Information Technology` |
| `year_level` | TINYINT UNSIGNED | no | 1 | CHECK 1–6 |
| `department_id` | INT UNSIGNED | no | — | FK → `departments.id` RESTRICT |
| `student_count` | SMALLINT UNSIGNED | no | 40 | BR-06 capacity check; CHECK > 0 |
| `is_active` | TINYINT(1) | no | 1 | IDX |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_sections_code`; IDX `idx_sections_department_year`, `idx_sections_active`; CHECK `chk_sections_year_level`, `chk_sections_students`.

### 5.15 `section_subjects`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `section_id` | INT UNSIGNED | no | — | FK → `sections.id` CASCADE |
| `subject_id` | INT UNSIGNED | no | — | FK → `subjects.id` CASCADE, IDX |
| `semester_id` | INT UNSIGNED | yes | NULL | FK → `semesters.id` CASCADE, IDX. NULL = template for any term |
| `required_meetings` | TINYINT UNSIGNED | no | 1 | Weekly meetings, 1–6 |
| `is_active` | TINYINT(1) | no | 1 | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_section_subjects_scope (section_id, subject_id, semester_id)`; IDX `idx_section_subjects_subject`, `idx_section_subjects_semester`; CHECK `chk_section_subjects_meetings`.
**Rules:** This is the generator's demand input (GEN-02) and the reference for
CNF-04/BR-10. MySQL treats NULLs as distinct in a unique key, so the service
must reject a duplicate template row (same section+subject with
`semester_id IS NULL`); that is validated in Phase 13.

### 5.16 `faculty_profiles`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK. **Referenced by `schedules.faculty_id`** |
| `user_id` | INT UNSIGNED | no | — | UK, FK → `users.id` CASCADE (1:1) |
| `department_id` | INT UNSIGNED | no | — | FK → `departments.id` RESTRICT, IDX with `is_active` |
| `employment_type` | ENUM(`full_time`,`part_time`,`visiting`) | no | `full_time` | |
| `max_load_units` | DECIMAL(4,1) | no | 18.0 | BR-07; CHECK > 0 |
| `specialization` | VARCHAR(180) | yes | NULL | |
| `office` | VARCHAR(80) | yes | NULL | |
| `is_active` | TINYINT(1) | no | 1 | BR-04 |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_faculty_profiles_user`; IDX `idx_faculty_profiles_department_active`; CHECK `chk_faculty_profiles_load`.
**Rules:** Only users with role `faculty` should own a profile — enforced by
`MasterDataService`, since a CHECK cannot query another table.

### 5.17 `faculty_subjects`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `faculty_id` | INT UNSIGNED | no | — | FK → `faculty_profiles.id` CASCADE |
| `subject_id` | INT UNSIGNED | no | — | FK → `subjects.id` CASCADE, IDX |
| `proficiency` | ENUM(`primary`,`secondary`) | no | `primary` | Ranking hint for `FacultyMatcher` |
| `created_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_faculty_subjects_pair (faculty_id, subject_id)`; IDX `idx_faculty_subjects_subject`.

### 5.18 `faculty_availability`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `faculty_id` | INT UNSIGNED | no | — | FK → `faculty_profiles.id` CASCADE |
| `day_of_week` | TINYINT UNSIGNED | no | — | 1 = Monday … 7 = Sunday; CHECK 1–7 |
| `start_time` | TIME | no | — | |
| `end_time` | TIME | no | — | CHECK after `start_time` |
| `availability_type` | ENUM(`available`,`preferred`,`unavailable`) | no | `available` | `unavailable` carves a hole out of a wider available window |
| `note` | VARCHAR(255) | yes | NULL | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_faculty_availability_window (faculty_id, day_of_week, start_time)`; CHECK `chk_faculty_availability_day`, `chk_faculty_availability_range`.
**Rules:** Windows are half-open, matching BR-01. Overlaps between windows of
the same type are tolerated; `AvailabilityRule` resolves precedence as
`unavailable` > `preferred` > `available`.

### 5.19 `schedules`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `semester_id` | INT UNSIGNED | no | — | FK → `semesters.id` RESTRICT |
| `department_id` | INT UNSIGNED | no | — | FK → `departments.id` RESTRICT. Denormalised from the section for scoping/reporting |
| `section_id` | INT UNSIGNED | no | — | FK → `sections.id` RESTRICT |
| `subject_id` | INT UNSIGNED | no | — | FK → `subjects.id` RESTRICT, IDX |
| `faculty_id` | INT UNSIGNED | no | — | FK → `faculty_profiles.id` RESTRICT |
| `room_id` | INT UNSIGNED | no | — | FK → `rooms.id` RESTRICT |
| `day_of_week` | TINYINT UNSIGNED | no | — | 1 = Monday … 7 = Sunday; CHECK 1–7 |
| `start_time` | TIME | no | — | 24-hour |
| `end_time` | TIME | no | — | CHECK after `start_time` (no cross-midnight sessions) |
| `meeting_date` | DATE | yes | NULL | Required when `recurrence = 'one_off'` (CHECK) |
| `recurrence` | ENUM(`weekly`,`one_off`) | no | `weekly` | Weekly entries are one row per meeting day (ADR-04) |
| `status` | ENUM(`draft`,`pending`,`approved`,`published`,`rejected`,`cancelled`) | no | `draft` | SCH-05 lifecycle |
| `notes` | VARCHAR(500) | yes | NULL | Free text shown in the drawer |
| `created_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `updated_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `approved_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `approved_at` | DATETIME | yes | NULL | BR-11 |
| `published_at` | DATETIME | yes | NULL | |
| `cancelled_at` | DATETIME | yes | NULL | |
| `cancellation_reason` | VARCHAR(255) | yes | NULL | |
| `created_at`, `updated_at` | DATETIME | no | now | SCH-07 (audit detail lives in `activity_log`) |

**Keys:** PK `id`; IDX `idx_schedules_room_slot (semester_id, room_id, day_of_week, start_time)`, `idx_schedules_faculty_slot (semester_id, faculty_id, day_of_week, start_time)`, `idx_schedules_section_slot (semester_id, section_id, day_of_week, start_time)`, `idx_schedules_term_status (semester_id, status)`, `idx_schedules_term_day_status (semester_id, day_of_week, status)`, `idx_schedules_department (department_id, semester_id)`, `idx_schedules_subject`, `idx_schedules_meeting_date`; CHECK `chk_schedules_day`, `chk_schedules_time`, `chk_schedules_one_off_date`.

**Deliberately absent:** a unique key on `(room_id, day_of_week, start_time)`.
It would reject legitimate touching slots (BR-01) and, because cancellation is
a status change rather than a delete, would block re-booking a cancelled slot.
MySQL has no exclusion constraint, so overlap is enforced exclusively by
`ConflictService` (ADR-03) and the three covering indexes above.

### 5.20 `schedule_conflicts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `semester_id` | INT UNSIGNED | no | — | FK → `semesters.id` CASCADE |
| `primary_schedule_id` | INT UNSIGNED | no | — | FK → `schedules.id` CASCADE, IDX with `status` |
| `conflicting_schedule_id` | INT UNSIGNED | yes | NULL | FK → `schedules.id` CASCADE, IDX. NULL for absolute rules (capacity, policy) |
| `rule_code` | VARCHAR(20) | no | — | `CNF-01` … `CNF-07`; IDX |
| `conflict_type` | ENUM(`room`,`faculty`,`section`,`duplicate_subject`,`capacity`,`room_type`,`faculty_load`,`availability`,`policy`) | no | — | Machine-readable category |
| `severity` | ENUM(`error`,`warning`) | no | `error` | CNF-10 |
| `message` | VARCHAR(500) | no | — | Rendered, specific message (CNF-08) |
| `status` | ENUM(`open`,`resolved`,`ignored`) | no | `open` | Conflict report grouping (RPT-04) |
| `resolution_note` | VARCHAR(500) | yes | NULL | Why it was resolved/ignored |
| `detected_at` | DATETIME | no | now | |
| `resolved_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `resolved_at` | DATETIME | yes | NULL | |

**Keys:** PK `id`; IDX `idx_schedule_conflicts_primary`, `idx_schedule_conflicts_conflicting`, `idx_schedule_conflicts_term_status`, `idx_schedule_conflicts_rule`.
**Rules:** Conflicts that block a save do not reach this table — the write is
rolled back and the errors are returned to the caller. Rows here record
detections that were persisted (imports, generator previews saved for review,
post-hoc scans) and their resolution history.

### 5.21 `schedule_comments`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `schedule_id` | INT UNSIGNED | no | — | FK → `schedules.id` CASCADE, IDX with `created_at` |
| `user_id` | INT UNSIGNED | no | — | FK → `users.id` CASCADE, IDX |
| `parent_id` | BIGINT UNSIGNED | yes | NULL | Self FK → `schedule_comments.id` CASCADE, IDX |
| `body` | TEXT | no | — | Escaped on output, never trusted as HTML |
| `is_deleted` | TINYINT(1) | no | 0 | Soft delete keeps replies attached |
| `edited_at` | DATETIME | yes | NULL | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_schedule_comments_schedule`, `idx_schedule_comments_parent`, `idx_schedule_comments_user`.

### 5.22 `comment_mentions`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `comment_id` | BIGINT UNSIGNED | no | — | FK → `schedule_comments.id` CASCADE |
| `user_id` | INT UNSIGNED | no | — | FK → `users.id` CASCADE, IDX with `notified_at` |
| `notified_at` | DATETIME | yes | NULL | Set when the notification was queued |
| `created_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_comment_mentions_pair (comment_id, user_id)`; IDX `idx_comment_mentions_user`.
**Rules:** Resolved user IDs are stored, not the raw `@email` text (COL-01).

### 5.23 `change_requests`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `schedule_id` | INT UNSIGNED | no | — | FK → `schedules.id` CASCADE, IDX with `status` |
| `requested_by` | INT UNSIGNED | no | — | FK → `users.id` CASCADE, IDX |
| `reviewed_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `request_type` | ENUM(`create`,`update`,`cancel`) | no | — | |
| `proposed_changes` | JSON | no | — | Field → new value map; validated against an allow-list |
| `reason` | VARCHAR(500) | no | — | Justification shown to the approver |
| `status` | ENUM(`pending`,`approved`,`rejected`,`cancelled`) | no | `pending` | IDX with `created_at` for the review queue |
| `review_note` | VARCHAR(500) | yes | NULL | Required on rejection |
| `decided_at` | DATETIME | yes | NULL | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_change_requests_schedule`, `idx_change_requests_review_queue (status, created_at)`, `idx_change_requests_requester`.
**Rules:** Approval applies the diff through `ScheduleService` inside one
transaction and re-runs the conflict check (COL-03, BR-11).

### 5.24 `tasks`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `department_id` | INT UNSIGNED | no | — | FK → `departments.id` RESTRICT; the board it belongs to |
| `schedule_id` | INT UNSIGNED | yes | NULL | FK → `schedules.id` CASCADE; NULL for board-only cards |
| `title` | VARCHAR(180) | no | — | |
| `description` | TEXT | yes | NULL | |
| `status` | ENUM(`backlog`,`todo`,`in_progress`,`review`,`done`) | no | `backlog` | Board column |
| `priority` | ENUM(`low`,`normal`,`high`,`urgent`) | no | `normal` | |
| `due_date` | DATE | yes | NULL | IDX |
| `sort_order` | INT | no | 0 | SortableJS position within a column |
| `created_by` | INT UNSIGNED | no | — | FK → `users.id` RESTRICT |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_tasks_board (department_id, status, sort_order)`, `idx_tasks_schedule`, `idx_tasks_due`.

### 5.25 `task_assignments`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `task_id` | BIGINT UNSIGNED | no | — | PK, FK → `tasks.id` CASCADE |
| `user_id` | INT UNSIGNED | no | — | PK, FK → `users.id` CASCADE, IDX |
| `assigned_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `assigned_at` | DATETIME | no | now | |

**Keys:** composite PK `(task_id, user_id)`; IDX `idx_task_assignments_user`.
**Rules:** Multiple assignees per card (COL-02/COL-05); removing a user removes
their assignment rows only.

### 5.26 `task_checklist_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `task_id` | BIGINT UNSIGNED | no | — | FK → `tasks.id` CASCADE |
| `label` | VARCHAR(180) | no | — | |
| `is_done` | TINYINT(1) | no | 0 | |
| `sort_order` | SMALLINT UNSIGNED | no | 0 | |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_task_checklist_order (task_id, sort_order)`.

### 5.27 `notifications`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `user_id` | INT UNSIGNED | no | — | FK → `users.id` CASCADE |
| `type` | VARCHAR(60) | no | — | `schedule.changed`, `request.approved`, `mention.created`, … |
| `title` | VARCHAR(180) | no | — | |
| `body` | VARCHAR(500) | yes | NULL | |
| `subject_type` | VARCHAR(40) | yes | NULL | Allow-listed discriminator; IDX with `subject_id` |
| `subject_id` | BIGINT UNSIGNED | yes | NULL | |
| `action_url` | VARCHAR(255) | yes | NULL | Relative URL opened by the toast/inbox |
| `channel` | ENUM(`in_app`,`email`,`both`) | no | `in_app` | Per-user preference applied before insert |
| `is_read` | TINYINT(1) | no | 0 | IDX with `user_id`, `created_at` |
| `read_at` | DATETIME | yes | NULL | |
| `email_status` | ENUM(`none`,`pending`,`sent`,`failed`) | no | `none` | IDX with `created_at` = the email queue |
| `email_attempts` | TINYINT UNSIGNED | no | 0 | Retry counter with backoff (NTF-04) |
| `email_last_error` | VARCHAR(500) | yes | NULL | Truncated SMTP error, never credentials |
| `sent_at` | DATETIME | yes | NULL | |
| `created_at` | DATETIME | no | now | |

**Keys:** PK `id`; IDX `idx_notifications_inbox (user_id, is_read, created_at)`, `idx_notifications_email_queue (email_status, created_at)`, `idx_notifications_subject (subject_type, subject_id)`.

### 5.28 `activity_log`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | no | auto | PK |
| `actor_id` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL; NULL for system/cron actions |
| `event` | VARCHAR(60) | no | — | `auth.login`, `schedule.updated`, `request.approved`, … |
| `subject_type` | VARCHAR(40) | yes | NULL | Allow-listed discriminator |
| `subject_id` | BIGINT UNSIGNED | yes | NULL | |
| `description` | VARCHAR(500) | yes | NULL | Human summary for the feed |
| `properties` | JSON | yes | NULL | Before/after diff, request payload keys, IP-derived context |
| `ip_address` | VARCHAR(45) | yes | NULL | |
| `user_agent` | VARCHAR(255) | yes | NULL | |
| `created_at` | DATETIME | no | now | IDX |

**Keys:** PK `id`; IDX `idx_activity_log_subject (subject_type, subject_id)`, `idx_activity_log_actor (actor_id, created_at)`, `idx_activity_log_event (event, created_at)`, `idx_activity_log_created`.
**Rules:** Append-only. `properties` carries the schedule diff (SCH-07) and
must never contain password hashes, tokens or SMTP credentials.

### 5.29 `settings`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `setting_key` | VARCHAR(80) | no | — | UK. Dot notation: `schedule.allowed_start` |
| `setting_value` | TEXT | yes | NULL | Stored as text, cast by `value_type` |
| `value_type` | ENUM(`string`,`integer`,`decimal`,`boolean`,`json`,`time`) | no | `string` | Cast on read |
| `group_name` | VARCHAR(40) | no | `general` | IDX. Settings page tab |
| `label` | VARCHAR(120) | no | — | |
| `description` | VARCHAR(255) | yes | NULL | Help text |
| `is_public` | TINYINT(1) | no | 0 | Safe to expose to the browser (e.g. institution name) |
| `updated_by` | INT UNSIGNED | yes | NULL | FK → `users.id` SET NULL |
| `created_at`, `updated_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_settings_key`; IDX `idx_settings_group`.
**Rules:** SMTP credentials live here (SYS-01) and are never selected into a
view or logged; `SettingsService` masks them and refuses to render
`is_secret`-class keys outside the admin settings form.

### 5.30 `schema_migrations`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED | no | auto | PK |
| `migration` | VARCHAR(160) | no | — | UK. File basename, e.g. `006_create_schedules.up.sql` |
| `batch` | SMALLINT UNSIGNED | no | 1 | Incremented per runner invocation |
| `applied_at` | DATETIME | no | now | |

**Keys:** PK `id`; UK `uq_schema_migrations_migration`.

---

## 6. Referential integrity summary

| Behaviour | Count | Applied to |
|---|---|---|
| `RESTRICT` | 14 | Master data referenced by schedules and profiles: role, department, building, section, subject, room, faculty profile, task board, task creator |
| `CASCADE` | 26 | Owned children: role permissions, remember tokens, faculty profile/subjects/availability, section curriculum, conflicts, comments/mentions, change requests, task children, notifications |
| `SET NULL` | 9 | Actor references that must survive account archival: dean, schedule `created_by`/`updated_by`/`approved_by`, conflict resolver, change-request reviewer, task assigner, settings editor, audit actor |

Full per-constraint listing: [`database/schema/ERD.md` §6](../database/schema/ERD.md).

**Why `RESTRICT` rather than `CASCADE` for master data:** deleting a room that
appears in 40 published schedules would silently destroy timetable history.
The application never issues `DELETE` against referenced master data; it flips
`is_active`. The constraint is the last line of defence (BR-12).

---

## 7. Index strategy

The conflict engine (CNF-11: ≤ 100 ms against 5,000 entries) and the calendar
(CAL-05) drive the indexing decisions. Each hot query has one leading index.

| Hot query (module) | Index used | Notes |
|---|---|---|
| Conflict universe: same term + weekday + overlapping band, for a room / faculty / section | `idx_schedules_room_slot`, `idx_schedules_faculty_slot`, `idx_schedules_section_slot` | `(semester_id, <resource>_id, day_of_week, start_time)` filters in index order; `end_time > :start` is evaluated on the narrow result |
| Calendar range feed (CAL-05) | `idx_schedules_term_day_status` | Term + weekday + status before the time filter |
| Today's classes (DASH-01) | `idx_schedules_term_day_status` | Same index, status `IN ('approved','published')` |
| Department timetable / scope filter | `idx_schedules_department (department_id, semester_id)` | Scope is applied in the repository (ADR-10) |
| Room utilization report (RPT-02) | `idx_schedules_room_slot` | Covers `SUM(TIMESTAMPDIFF(...))` per room with a range scan |
| Faculty load report (RPT-01) | `idx_schedules_faculty_slot` | Covers per-faculty grouping by term |
| Unread notifications (NTF-01) | `idx_notifications_inbox (user_id, is_read, created_at)` | Unread count is a range scan, no filesort |
| Email queue drain (NTF-04) | `idx_notifications_email_queue (email_status, created_at)` | Oldest pending first |
| Review queue (COL-03) | `idx_change_requests_review_queue (status, created_at)` | |
| Global search (SRCH-01) | `uq_subjects_code`, `uq_sections_code`, `uq_rooms_building_code`, `uq_users_email`, `uq_users_username` | Prefix lookups; `LIKE 'x%'` uses the unique keys |
| Login throttle (AUTH-07) | `idx_login_attempts_email_time`, `idx_login_attempts_ip_time` | 15-minute window |
| Audit/activity feed (COL-04) | `idx_activity_log_subject`, `idx_activity_log_created` | |

**Conflict-universe query shape**

```sql
SELECT id, room_id, faculty_id, section_id, start_time, end_time, status
FROM schedules
WHERE semester_id = :semester
  AND day_of_week = :day
  AND status <> 'cancelled'
  AND start_time < :end_time
  AND end_time   > :start_time
  AND (room_id = :room OR faculty_id = :faculty OR section_id = :section);
```

MySQL 8 can satisfy the `OR` with an index-merge union over the three covering
indexes. `ConflictRepository` will keep the single-round-trip form and, if
`EXPLAIN` in Phase 11 shows a full scan on large data sets, fall back to three
indexed probes merged in PHP. That measurement is a Phase 11 acceptance item,
not assumed here.

**Why no index on `status` alone:** status is never selective (most rows are
`approved`), so it is only useful as a trailing column of a composite index,
which is how it is used above.

---

## 8. Business-rule enforcement map

| Rule | Storage support | Enforced by |
|---|---|---|
| BR-01 overlap `a.start < b.end AND b.start < a.end` | `TIME` columns, composite indexes | `TimeRange` value object / `ConflictService` |
| BR-02 room conflict | `idx_schedules_room_slot` | `RoomOccupiedRule` |
| BR-03 faculty/section conflict | `idx_schedules_faculty_slot`, `idx_schedules_section_slot` | `FacultyBusyRule`, `SectionBusyRule` |
| BR-04 only active resources; drafts block previews | `rooms.is_active`, `faculty_profiles.is_active`, `schedules.status` | `ConflictService` candidate set |
| BR-05 lab subjects need lab rooms | `subjects.subject_type`, `rooms.room_type` | `RoomSuitabilityRule` |
| BR-06 capacity ≥ section size | `rooms.capacity`, `sections.student_count` | `RoomSuitabilityRule` (warning > 90 %, error > 100 %) |
| BR-07 load cap | `faculty_profiles.max_load_units` | `FacultyLoadRule` |
| BR-08 allowed hours / breaks | `time_slots.is_break`, `settings` | `PolicyWindowRule` |
| BR-09 one subject per section at a time | `idx_schedules_section_slot` | `SectionBusyRule` |
| BR-10 subject allocated once per term (multi-day splits allowed) | `section_subjects`, `schedules` | `DuplicateAllocationRule` |
| BR-11 approval prerequisites | `schedules.status`, `approved_by`, `approved_at` | `ScheduleService::approve()` |
| BR-12 no deletion of referenced master data | 16 `RESTRICT` foreign keys | Database + `MasterDataService` |
| BR-13 24-hour time | `TIME` columns | Schema |
| BR-14 one current semester per year | `uq_semesters_one_current` (generated column) | Database + `TermService` |

---

## 9. Status lifecycles

```
schedules.status

  draft ──submit──> pending ──approve──> approved ──publish──> published
    │                  │                    │                     │
    │                  └──reject──> rejected│                     │
    └────────────cancel (any non-published state)──> cancelled <──┘
```

| Enum | Values | Terminal? |
|---|---|---|
| `schedules.status` | `draft`, `pending`, `approved`, `published`, `rejected`, `cancelled` | `rejected`, `cancelled` |
| `change_requests.status` | `pending`, `approved`, `rejected`, `cancelled` | all but `pending` |
| `schedule_conflicts.status` | `open`, `resolved`, `ignored` | `resolved`, `ignored` |
| `semesters.status` | `planned`, `active`, `closed` | `closed` |
| `tasks.status` | `backlog`, `todo`, `in_progress`, `review`, `done` | `done` |
| `notifications.email_status` | `none`, `pending`, `sent`, `failed` | `sent` (or `failed` after backoff exhaustion) |

Cancellation is a status change, never a `DELETE`; `cancelled_at` and
`cancellation_reason` preserve why a session disappeared (SCH-07).

---

## 10. Volume, growth and retention

| Concern | Expectation | Handling |
|---|---|---|
| `schedules` | 100 seeded; ~1,000–2,000 per term at a mid-size college | Indexed range scans; term is always in the predicate |
| `activity_log` | Largest table, ~10k rows/month/college | 24-month retention, then archive/export and prune (cron, Phase 20) |
| `notifications` | ~100/user/month | Prune read rows older than 6 months |
| `login_attempts` | Spiky during attacks | Prune older than 30 days; index supports the window |
| `remember_tokens` | One per device | Delete expired rows with the notification cron |
| `schedule_conflicts` | Small; grows with import scans | Retention tied to the schedule (CASCADE) |
| Exports (PDF/Excel) | Not stored in MySQL | `storage/exports/` with signed, expiring names (Phase 15) |
| Backups | `mysqldump` of one schema | Re-runnable migrations + Phase 19 seeders (NFR-12) |

---

## 11. Migration workflow

### 11.1 Files

```
database/migrations/
├── 000_create_schema_migrations.up.sql / .down.sql
├── 001_create_rbac.up.sql              / .down.sql
├── 002_create_departments_users.up.sql / .down.sql
├── 003_create_facilities.up.sql        / .down.sql
├── 004_create_academics.up.sql         / .down.sql
├── 005_create_catalog.up.sql           / .down.sql
├── 006_create_schedules.up.sql         / .down.sql
├── 007_create_collaboration.up.sql     / .down.sql
└── 008_create_system.up.sql            / .down.sql
```

Rules:

1. **Applied migrations are immutable.** Fix a mistake with a new numbered
   migration, never by editing history.
2. **Up files are idempotent** (`CREATE TABLE IF NOT EXISTS`), so a partially
   completed import can be re-run safely. Down files reverse the order exactly.
3. **Down migrations are for development and review**, not for production
   rollbacks. Production is forward-only with a verified backup.
4. Table creation order follows dependency order, so the concatenated file also
   imports with `FOREIGN_KEY_CHECKS = 1`. The generated file disables the check
   anyway to tolerate partial restores.

### 11.2 Generating the single-file schema

```bash
php database/schema/build.php
# Wrote .../database/schema/SyncClass.sql (9 migrations, ~40 KB)
```

`SyncClass.sql` is **generated**: it begins with `CREATE DATABASE IF NOT EXISTS`
and ends with `SET FOREIGN_KEY_CHECKS = 1` (40 KB, 30 tables). `database/schema/build.php` is the
only supported way to produce it; the file header repeats this so nobody edits
it by hand.

### 11.3 Installing on XAMPP

```bash
# Option A — command line (MySQL root, default XAMPP password is empty)
D:\xampp8.2\mysql\bin\mysql.exe -u root < D:\xampp8.2\htdocs\SyncClass\database\schema\SyncClass.sql

# Option B — phpMyAdmin: Import → choose database/schema/SyncClass.sql → Go
```

The script creates `anth04_SyncClass` itself, so no manual database creation is
needed. Seeding (roles, departments, demo accounts, 100 schedules) is Phase 19.

### 11.4 Migrating an existing installation

Phase 20 ships `bin/migrate.php` (SYS-03): it reads `schema_migrations`, applies
new `*.up.sql` files in filename order inside a transaction, and records each
with an incrementing batch. Until then, a fresh import of `SyncClass.sql` is
the supported path.

---

## 12. Verification performed for this phase

Available in this environment: Python 3 and a SQL parser. **Not available: a
MySQL/MariaDB server**, so the statements have not been executed against a live
database here. The import checklist in §13 is the acceptance step to run on
XAMPP.

Automated checks that passed (re-runnable with the commands shown):

| Check | Result |
|---|---|
| Single-file schema generated from 9 migration pairs | 40 KB, 30 `ENGINE=InnoDB` tables |
| Every `CREATE TABLE` has a `PRIMARY KEY` | 30/30 |
| All 49 foreign keys reference an existing table | 0 unknown targets |
| FK source columns exist in their table | 0 unresolved |
| Constraint/index names unique across the schema | 0 duplicates |
| Every up migration has a matching down migration | 9/9 |
| Statement-level parse, MySQL dialect (36 statements) | 0 errors |

### 12.1 Manual acceptance checklist (run on XAMPP)

```sql
-- 1. Import
--    mysql -u root < database/schema/SyncClass.sql

-- 2. Object counts: expect 30 and 48
SELECT COUNT(*) AS tables          -- expect 30
FROM information_schema.tables
WHERE table_schema = 'anth04_SyncClass' AND table_type = 'BASE TABLE';

SELECT COUNT(*) AS foreign_keys    -- expect 49
FROM information_schema.referential_constraints
WHERE constraint_schema = 'anth04_SyncClass';

-- 3. Charset/engine consistency: expect only InnoDB / utf8mb4_unicode_ci
SELECT engine, table_collation, COUNT(*)
FROM information_schema.tables
WHERE table_schema = 'anth04_SyncClass'
GROUP BY engine, table_collation;

-- 4. Conflict indexes present on schedules
SHOW INDEX FROM anth04_SyncClass.schedules;

-- 5. BR-14 guard: second current semester in the same year must fail
INSERT INTO anth04_SyncClass.academic_years (code, start_date, end_date, is_current)
VALUES ('2099-2100', '2099-06-01', '2100-05-31', 1);
INSERT INTO anth04_SyncClass.semesters
    (academic_year_id, term_no, name, code, start_date, end_date, is_current)
VALUES (LAST_INSERT_ID(), 1, '1st Semester', '2099-2100-1', '2099-06-01', '2099-10-31', 1);
-- expected: ERROR 1062 duplicate entry for uq_semesters_one_current
INSERT INTO anth04_SyncClass.semesters
    (academic_year_id, term_no, name, code, start_date, end_date, is_current)
VALUES (1, 2, '2nd Semester', '2099-2100-2', '2099-11-01', '2100-03-31', 1);

-- 6. CHECK constraint: end before start must fail
INSERT INTO anth04_SyncClass.time_slots (code, label, start_time, end_time)
VALUES ('BAD', 'bad', '10:00:00', '09:00:00');
-- expected: ERROR 3819 check constraint violated

-- 7. FK protection (BR-12): deleting a referenced room must fail once
--    schedules exist. With an empty schedules table it succeeds, so run this
--    after Phase 19 seeding.
```

The last two steps are the ones a parser cannot prove: they need the server's
constraint engine.

---

## 13. Data security

| Concern | Design |
|---|---|
| Credentials | Only `password_hash()` output in `users.password_hash`; `remember_tokens.validator_hash` stores SHA-256 of the validator, never the cookie value |
| Secrets at rest | SMTP credentials live in `settings` (SYS-01), are masked in the admin UI and excluded from logs, exports and `is_public` reads |
| PII minimisation | Name, institutional e-mail, employee number, phone, avatar path only — no birth dates, addresses or government IDs |
| Audit immutability | `activity_log` is insert-only in the application; the runtime DB user should be denied `UPDATE`/`DELETE` on it |
| Least privilege | The application connects as a DML-only user; schema changes use a separate admin account |
| SQL injection | All access through PDO prepared statements; no dynamic table/column names; `ORDER BY` whitelists (Phase 5/6) |
| Export safety | CSV/Excel cell prefixing (`'`, `=`, `+`, `-`, `@`) to stop formula injection (Phase 15) |
| Backups | `mysqldump --single-transaction --routines=false`; no credentials inside the dump beyond hashed values |

Recommended grants (documented, not created by the schema):

```sql
CREATE USER 'syncclass_app'@'localhost' IDENTIFIED BY '<generated>';
GRANT SELECT, INSERT, UPDATE, DELETE ON anth04_SyncClass.* TO 'syncclass_app'@'localhost';
-- deliberately no CREATE, ALTER, DROP, FILE or SUPER
```

---

## 14. Compatibility notes

| Feature used | MySQL 8.0 | MariaDB 10.4 | Mitigation if unsupported |
|---|---|---|---|
| `CHECK` constraints | enforced from 8.0.16 | enforced from 10.2 | Application validation is authoritative anyway |
| `GENERATED ALWAYS AS … STORED` + unique key | yes | yes | Drop the generated column and keep the service-level BR-14 guard |
| `JSON` column | native | alias for `LONGTEXT` with a JSON check | Code always reads/writes JSON strings via `json_encode`/`json_decode` |
| `utf8mb4_unicode_ci` | yes | yes | — |
| `ENUM` | yes | yes | — |
| `ON UPDATE CURRENT_TIMESTAMP` | yes | yes | — |

No stored procedures, triggers, events or views are used, so the schema
imports on restricted shared hosting that disables them.

---

## 15. Next phase

**Phase 4 — Folder Structure:** scaffold the directory tree from
`docs/ARCHITECTURE.md` §3 (`app/Core`, `app/Controllers`, `app/Repositories`,
`app/Services`, `app/Views`, `config/`, `public/`, `routes/`, `storage/`,
`tests/`), add `composer.json` with PSR-4 `App\` → `app/`, `.htaccess` files,
`bootstrap.php`, and the root-fallback front controller — no feature code yet.
