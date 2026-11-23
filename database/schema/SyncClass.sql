-- =====================================================================
-- SyncClass — Classroom & Subject Scheduler
-- Single-file schema generated from database/migrations/*.up.sql
-- DO NOT EDIT BY HAND — run: php database/schema/build.php
-- Server: MySQL 8.0+ / MariaDB 10.4+ · Engine: InnoDB · Charset: utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `anth04_SyncClass`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `anth04_SyncClass`;

-- ---------------------------------------------------------------------
-- 000_create_schema_migrations.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 000_create_schema_migrations (UP)
-- Migration bookkeeping table used by the installer/CLI (SYS-03).
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    migration   VARCHAR(160)     NOT NULL,
    batch       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    applied_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 001_create_rbac.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 001_create_rbac (UP)
-- Roles, permissions and the role -> permission mapping.
-- Requirements: §4 RBAC matrix, SYS-02 (actor attribution).
-- =====================================================================

CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    code        VARCHAR(50)      NOT NULL,
    name        VARCHAR(100)     NOT NULL,
    description VARCHAR(255)     NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 10,
    is_system   TINYINT(1)       NOT NULL DEFAULT 0,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(80)  NOT NULL,
    module      VARCHAR(40)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code),
    KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 002_create_departments_users.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 002_create_departments_users (UP)
-- Departments, user accounts, remember-me tokens, login throttling.
-- Requirements: MD-01, AUTH-01…AUTH-08.
--
-- Circular dependency note: departments.dean_user_id points at users and
-- users.department_id points at departments. The department table is
-- created first without the dean foreign key; the key is added by ALTER
-- after users exists.
-- =====================================================================

CREATE TABLE IF NOT EXISTS departments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code         VARCHAR(20)  NOT NULL,
    name         VARCHAR(150) NOT NULL,
    short_name   VARCHAR(50)  NULL,
    dean_user_id INT UNSIGNED NULL,
    email        VARCHAR(150) NULL,
    phone        VARCHAR(30)  NULL,
    color_hex    CHAR(7)      NOT NULL DEFAULT '#8C1D40',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_code (code),
    UNIQUE KEY uq_departments_dean (dean_user_id),
    KEY idx_departments_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    role_id              INT UNSIGNED     NOT NULL,
    department_id        INT UNSIGNED     NULL,
    email                VARCHAR(150)     NOT NULL,
    username             VARCHAR(60)      NOT NULL,
    password_hash        VARCHAR(255)     NOT NULL,
    first_name           VARCHAR(80)      NOT NULL,
    middle_name          VARCHAR(80)      NULL,
    last_name            VARCHAR(80)      NOT NULL,
    employee_no          VARCHAR(30)      NULL,
    phone                VARCHAR(30)      NULL,
    avatar_path          VARCHAR(255)     NULL,
    is_active            TINYINT(1)       NOT NULL DEFAULT 1,
    must_change_password TINYINT(1)       NOT NULL DEFAULT 0,
    failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME         NULL,
    last_login_at        DATETIME         NULL,
    password_changed_at  DATETIME         NULL,
    created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_employee_no (employee_no),
    KEY idx_users_role (role_id),
    KEY idx_users_department (department_id),
    KEY idx_users_active (is_active),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_users_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dean assignment is optional and must survive a user being archived, so
-- the column is nullable and the relationship is SET NULL on delete.
ALTER TABLE departments
    ADD CONSTRAINT fk_departments_dean
    FOREIGN KEY (dean_user_id) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED    NOT NULL,
    selector       CHAR(24)        NOT NULL,
    validator_hash CHAR(64)        NOT NULL,
    expires_at     DATETIME        NOT NULL,
    last_used_at   DATETIME        NULL,
    ip_address     VARCHAR(45)     NULL,
    user_agent     VARCHAR(255)    NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_tokens_selector (selector),
    KEY idx_remember_tokens_user (user_id),
    KEY idx_remember_tokens_expiry (expires_at),
    CONSTRAINT fk_remember_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw login attempts feed the AUTH-07 throttle (5 failures / 15 min per
-- email+IP). No foreign key: the email may not belong to a real account.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(150)    NOT NULL,
    ip_address   VARCHAR(45)     NOT NULL,
    successful   TINYINT(1)      NOT NULL DEFAULT 0,
    user_agent   VARCHAR(255)    NULL,
    attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 003_create_facilities.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 003_create_facilities (UP)
-- Buildings and rooms (the schedulable physical resources).
-- Requirements: MD-02, CNF-05, BR-05, BR-06.
-- =====================================================================

CREATE TABLE IF NOT EXISTS buildings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    campus     VARCHAR(120) NULL,
    address    VARCHAR(255) NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_buildings_code (code),
    KEY idx_buildings_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    building_id INT UNSIGNED     NOT NULL,
    code       VARCHAR(20)       NOT NULL,
    name       VARCHAR(120)      NULL,
    floor      SMALLINT          NULL,
    room_type  ENUM('lecture','laboratory','seminar') NOT NULL DEFAULT 'lecture',
    capacity   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    facilities VARCHAR(500)      NULL,
    is_active  TINYINT(1)        NOT NULL DEFAULT 1,
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rooms_building_code (building_id, code),
    KEY idx_rooms_type_capacity (room_type, capacity),
    KEY idx_rooms_active (is_active),
    CONSTRAINT fk_rooms_building
        FOREIGN KEY (building_id) REFERENCES buildings (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_rooms_capacity CHECK (capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 004_create_academics.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 004_create_academics (UP)
-- Academic years, semesters (terms) and configurable time slots.
-- Requirements: MD-06, BR-14, CNF-07 (policy windows).
--
-- "At most one current semester per academic year" (BR-14) is enforced by
-- TermRepository, which clears the previous flag inside the same transaction
-- before the new one is written. It is deliberately *not* a generated column +
-- unique key: MySQL 8 accepts that trick, but MariaDB (the server bundled with
-- XAMPP) rejects a generated column that projects a foreign-key column
-- (`academic_year_id`), so the schema would fail to import on a stock XAMPP.
-- The portable schema must load on both engines.
-- =====================================================================

CREATE TABLE IF NOT EXISTS academic_years (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20)  NOT NULL,
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    is_current TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_years_code (code),
    KEY idx_academic_years_current (is_current),
    CONSTRAINT chk_academic_years_dates CHECK (end_date > start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS semesters (
    id                       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    academic_year_id         INT UNSIGNED     NOT NULL,
    term_no                  TINYINT UNSIGNED NOT NULL,
    name                     VARCHAR(40)      NOT NULL,
    code                     VARCHAR(30)      NOT NULL,
    start_date               DATE             NOT NULL,
    end_date                 DATE             NOT NULL,
    status                   ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
    is_current               TINYINT(1)       NOT NULL DEFAULT 0,
    created_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_semesters_code (code),
    UNIQUE KEY uq_semesters_year_term (academic_year_id, term_no),
    KEY idx_semesters_status (status),
    KEY idx_semesters_dates (start_date, end_date),
    CONSTRAINT fk_semesters_academic_year
        FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_semesters_term_no CHECK (term_no BETWEEN 1 AND 3),
    CONSTRAINT chk_semesters_dates CHECK (end_date > start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard teaching periods offered by the UI and the generator. Schedules
-- store free start/end times (not a slot id) so irregular lab blocks stay
-- possible; is_break rows define the institution's break periods (BR-08).
CREATE TABLE IF NOT EXISTS time_slots (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20)      NOT NULL,
    label      VARCHAR(50)      NOT NULL,
    start_time TIME             NOT NULL,
    end_time   TIME             NOT NULL,
    is_break   TINYINT(1)       NOT NULL DEFAULT 0,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  TINYINT(1)       NOT NULL DEFAULT 1,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_time_slots_code (code),
    KEY idx_time_slots_sort (sort_order),
    CONSTRAINT chk_time_slots_range CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 005_create_catalog.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 005_create_catalog (UP)
-- Subjects, sections, the section curriculum bridge, faculty profiles,
-- faculty qualifications and availability windows.
-- Requirements: MD-03, MD-04, MD-05, GEN-02, CNF-04, CNF-06, CNF-07.
--
-- faculty_availability stores windows per weekday as half-open intervals;
-- availability_type = 'unavailable' blocks a window inside a wider
-- available range (e.g. available 08:00-17:00, unavailable 12:00-13:00).
-- =====================================================================

CREATE TABLE IF NOT EXISTS subjects (
    id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    code                   VARCHAR(30)   NOT NULL,
    title                  VARCHAR(180)  NOT NULL,
    department_id          INT UNSIGNED  NOT NULL,
    units                  DECIMAL(4,1)  NOT NULL DEFAULT 3.0,
    lecture_hours          DECIMAL(4,1)  NOT NULL DEFAULT 3.0,
    lab_hours              DECIMAL(4,1)  NOT NULL DEFAULT 0.0,
    contact_hours          DECIMAL(4,1)  GENERATED ALWAYS AS (lecture_hours + lab_hours) STORED,
    subject_type           ENUM('lecture','laboratory','mixed') NOT NULL DEFAULT 'lecture',
    preferred_room_type    ENUM('lecture','laboratory','seminar') NULL,
    description            TEXT          NULL,
    is_active              TINYINT(1)    NOT NULL DEFAULT 1,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_code (code),
    KEY idx_subjects_department_active (department_id, is_active),
    KEY idx_subjects_type (subject_type),
    CONSTRAINT fk_subjects_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_subjects_units CHECK (units >= 0),
    CONSTRAINT chk_subjects_hours CHECK (lecture_hours >= 0 AND lab_hours >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sections (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    code          VARCHAR(30)       NOT NULL,
    name          VARCHAR(120)      NULL,
    program       VARCHAR(120)      NULL,
    year_level    TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    department_id INT UNSIGNED      NOT NULL,
    student_count SMALLINT UNSIGNED NOT NULL DEFAULT 40,
    is_active     TINYINT(1)        NOT NULL DEFAULT 1,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sections_code (code),
    KEY idx_sections_department_year (department_id, year_level),
    KEY idx_sections_active (is_active),
    CONSTRAINT fk_sections_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_sections_year_level CHECK (year_level BETWEEN 1 AND 6),
    CONSTRAINT chk_sections_students CHECK (student_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_profiles (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED  NOT NULL,
    department_id   INT UNSIGNED  NOT NULL,
    employment_type ENUM('full_time','part_time','visiting') NOT NULL DEFAULT 'full_time',
    max_load_units  DECIMAL(4,1)  NOT NULL DEFAULT 18.0,
    specialization  VARCHAR(180)  NULL,
    office          VARCHAR(80)   NULL,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faculty_profiles_user (user_id),
    KEY idx_faculty_profiles_department_active (department_id, is_active),
    CONSTRAINT fk_faculty_profiles_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_faculty_profiles_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_faculty_profiles_load CHECK (max_load_units > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which subjects a faculty member is qualified (and preferred) to teach.
-- Drives FacultyMatcher in the generator and CNF-02/CNF-06 advice.
CREATE TABLE IF NOT EXISTS faculty_subjects (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    proficiency ENUM('primary','secondary') NOT NULL DEFAULT 'primary',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faculty_subjects_pair (faculty_id, subject_id),
    KEY idx_faculty_subjects_subject (subject_id),
    CONSTRAINT fk_faculty_subjects_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculty_profiles (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_faculty_subjects_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_availability (
    id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    faculty_id        INT UNSIGNED     NOT NULL,
    day_of_week       TINYINT UNSIGNED NOT NULL,
    start_time        TIME             NOT NULL,
    end_time          TIME             NOT NULL,
    availability_type ENUM('available','preferred','unavailable') NOT NULL DEFAULT 'available',
    note              VARCHAR(255)     NULL,
    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_faculty_availability_window (faculty_id, day_of_week, start_time),
    CONSTRAINT fk_faculty_availability_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculty_profiles (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_faculty_availability_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_faculty_availability_range CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The term curriculum: which subjects a section must take, and how many
-- weekly meetings each subject needs. This is the generator's demand input
-- (GEN-02) and the basis of the duplicate-subject rule (CNF-04, BR-10).
-- semester_id NULL means "applies to any term for this section".
CREATE TABLE IF NOT EXISTS section_subjects (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    section_id       INT UNSIGNED     NOT NULL,
    subject_id       INT UNSIGNED     NOT NULL,
    semester_id      INT UNSIGNED     NULL,
    required_meetings TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active        TINYINT(1)       NOT NULL DEFAULT 1,
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_section_subjects_scope (section_id, subject_id, semester_id),
    KEY idx_section_subjects_subject (subject_id),
    KEY idx_section_subjects_semester (semester_id),
    CONSTRAINT fk_section_subjects_section
        FOREIGN KEY (section_id) REFERENCES sections (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_section_subjects_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_section_subjects_semester
        FOREIGN KEY (semester_id) REFERENCES semesters (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_section_subjects_meetings CHECK (required_meetings BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 006_create_schedules.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 006_create_schedules (UP)
-- Schedule entries (one row per meeting) and the persisted conflict log.
-- Requirements: SCH-01…SCH-07, CNF-01…CNF-10, BR-01…BR-10.
--
-- Overlap is deliberately NOT enforced by a unique key: MySQL has no
-- exclusion constraints, and a unique key on (room, day, start_time) would
-- also reject legitimate adjacent slots. Half-open interval overlap is
-- enforced by ConflictService for every write path (ADR-03) and supported
-- by the three covering indexes below, which match the conflict-universe
-- query exactly.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schedules (
    id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    semester_id         INT UNSIGNED     NOT NULL,
    department_id       INT UNSIGNED     NOT NULL,
    section_id          INT UNSIGNED     NOT NULL,
    subject_id          INT UNSIGNED     NOT NULL,
    faculty_id          INT UNSIGNED     NOT NULL,
    room_id             INT UNSIGNED     NOT NULL,
    day_of_week         TINYINT UNSIGNED NOT NULL,
    start_time          TIME             NOT NULL,
    end_time            TIME             NOT NULL,
    meeting_date        DATE             NULL,
    recurrence          ENUM('weekly','one_off') NOT NULL DEFAULT 'weekly',
    status              ENUM('draft','pending','approved','published','rejected','cancelled')
                        NOT NULL DEFAULT 'draft',
    notes               VARCHAR(500)     NULL,
    created_by          INT UNSIGNED     NULL,
    updated_by          INT UNSIGNED     NULL,
    approved_by         INT UNSIGNED     NULL,
    approved_at         DATETIME         NULL,
    published_at        DATETIME         NULL,
    cancelled_at        DATETIME         NULL,
    cancellation_reason VARCHAR(255)     NULL,
    created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Covering indexes: WHERE semester_id = ? AND day_of_week = ?
    --                    AND start_time < :end AND end_time > :start
    KEY idx_schedules_room_slot (semester_id, room_id, day_of_week, start_time),
    KEY idx_schedules_faculty_slot (semester_id, faculty_id, day_of_week, start_time),
    KEY idx_schedules_section_slot (semester_id, section_id, day_of_week, start_time),
    KEY idx_schedules_term_status (semester_id, status),
    -- "today's classes" (DASH-01) and the calendar week feed (CAL-05).
    KEY idx_schedules_term_day_status (semester_id, day_of_week, status),
    KEY idx_schedules_department (department_id, semester_id),
    KEY idx_schedules_subject (subject_id),
    KEY idx_schedules_meeting_date (meeting_date),
    CONSTRAINT fk_schedules_semester
        FOREIGN KEY (semester_id) REFERENCES semesters (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_section
        FOREIGN KEY (section_id) REFERENCES sections (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_faculty
        FOREIGN KEY (faculty_id) REFERENCES faculty_profiles (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_room
        FOREIGN KEY (room_id) REFERENCES rooms (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_schedules_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_schedules_time CHECK (end_time > start_time),
    CONSTRAINT chk_schedules_one_off_date
        CHECK (recurrence = 'weekly' OR meeting_date IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_conflicts (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    semester_id            INT UNSIGNED    NOT NULL,
    primary_schedule_id    INT UNSIGNED    NOT NULL,
    conflicting_schedule_id INT UNSIGNED   NULL,
    rule_code              VARCHAR(20)     NOT NULL,
    conflict_type          ENUM('room','faculty','section','duplicate_subject','capacity',
                                'room_type','faculty_load','availability','policy') NOT NULL,
    severity               ENUM('error','warning') NOT NULL DEFAULT 'error',
    message                VARCHAR(500)    NOT NULL,
    status                 ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
    resolution_note        VARCHAR(500)    NULL,
    detected_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by            INT UNSIGNED    NULL,
    resolved_at            DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_schedule_conflicts_primary (primary_schedule_id, status),
    KEY idx_schedule_conflicts_conflicting (conflicting_schedule_id),
    KEY idx_schedule_conflicts_term_status (semester_id, status),
    KEY idx_schedule_conflicts_rule (rule_code),
    CONSTRAINT fk_schedule_conflicts_semester
        FOREIGN KEY (semester_id) REFERENCES semesters (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_conflicts_primary
        FOREIGN KEY (primary_schedule_id) REFERENCES schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_conflicts_conflicting
        FOREIGN KEY (conflicting_schedule_id) REFERENCES schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_conflicts_resolved_by
        FOREIGN KEY (resolved_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 007_create_collaboration.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 007_create_collaboration (UP)
-- Comments and mentions, change requests, task cards, notifications and
-- the activity/audit trail.
-- Requirements: COL-01…COL-05, NTF-01…NTF-04, SYS-02.
--
-- subject_type columns are string discriminators validated against an
-- allow-list in the service layer; they are intentionally not foreign keys
-- because a single column points at several tables.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schedule_comments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    parent_id   BIGINT UNSIGNED NULL,
    body        TEXT            NOT NULL,
    is_deleted  TINYINT(1)      NOT NULL DEFAULT 0,
    edited_at   DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_schedule_comments_schedule (schedule_id, created_at),
    KEY idx_schedule_comments_parent (parent_id),
    KEY idx_schedule_comments_user (user_id),
    CONSTRAINT fk_schedule_comments_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_comments_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_comments_parent
        FOREIGN KEY (parent_id) REFERENCES schedule_comments (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_mentions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id  BIGINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    notified_at DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comment_mentions_pair (comment_id, user_id),
    KEY idx_comment_mentions_user (user_id, notified_at),
    CONSTRAINT fk_comment_mentions_comment
        FOREIGN KEY (comment_id) REFERENCES schedule_comments (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comment_mentions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_requests (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id      INT UNSIGNED    NOT NULL,
    requested_by     INT UNSIGNED    NOT NULL,
    reviewed_by      INT UNSIGNED    NULL,
    request_type     ENUM('create','update','cancel') NOT NULL,
    proposed_changes JSON            NOT NULL,
    reason           VARCHAR(500)    NOT NULL,
    status           ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    review_note      VARCHAR(500)    NULL,
    decided_at       DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_change_requests_schedule (schedule_id, status),
    KEY idx_change_requests_review_queue (status, created_at),
    KEY idx_change_requests_requester (requested_by),
    CONSTRAINT fk_change_requests_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_change_requests_requester
        FOREIGN KEY (requested_by) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_change_requests_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED    NOT NULL,
    schedule_id   INT UNSIGNED    NULL,
    title         VARCHAR(180)    NOT NULL,
    description   TEXT            NULL,
    status        ENUM('backlog','todo','in_progress','review','done') NOT NULL DEFAULT 'backlog',
    priority      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    due_date      DATE            NULL,
    sort_order    INT             NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED    NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_board (department_id, status, sort_order),
    KEY idx_tasks_schedule (schedule_id),
    KEY idx_tasks_due (due_date),
    CONSTRAINT fk_tasks_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tasks_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tasks_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_assignments (
    task_id     BIGINT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    assigned_by INT UNSIGNED    NULL,
    assigned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, user_id),
    KEY idx_task_assignments_user (user_id, task_id),
    CONSTRAINT fk_task_assignments_task
        FOREIGN KEY (task_id) REFERENCES tasks (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_assignments_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_task_assignments_assigner
        FOREIGN KEY (assigned_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_checklist_items (
    id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    task_id    BIGINT UNSIGNED  NOT NULL,
    label      VARCHAR(180)     NOT NULL,
    is_done    TINYINT(1)       NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_checklist_order (task_id, sort_order),
    CONSTRAINT fk_task_checklist_task
        FOREIGN KEY (task_id) REFERENCES tasks (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app rows are written synchronously; email rows are drained by
-- bin/dispatch-notifications.php (NTF-04). email_status is the queue state.
CREATE TABLE IF NOT EXISTS notifications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED    NOT NULL,
    type             VARCHAR(60)     NOT NULL,
    title            VARCHAR(180)    NOT NULL,
    body             VARCHAR(500)    NULL,
    subject_type     VARCHAR(40)     NULL,
    subject_id       BIGINT UNSIGNED NULL,
    action_url       VARCHAR(255)    NULL,
    channel          ENUM('in_app','email','both') NOT NULL DEFAULT 'in_app',
    is_read          TINYINT(1)      NOT NULL DEFAULT 0,
    read_at          DATETIME        NULL,
    email_status     ENUM('none','pending','sent','failed') NOT NULL DEFAULT 'none',
    email_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_last_error VARCHAR(500)    NULL,
    sent_at          DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_inbox (user_id, is_read, created_at),
    KEY idx_notifications_email_queue (email_status, created_at),
    KEY idx_notifications_subject (subject_type, subject_id),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only audit/activity trail (SYS-02). actor_id is nullable so the
-- trail survives account removal; rows are never updated by the app.
CREATE TABLE IF NOT EXISTS activity_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id     INT UNSIGNED    NULL,
    event        VARCHAR(60)     NOT NULL,
    subject_type VARCHAR(40)     NULL,
    subject_id   BIGINT UNSIGNED NULL,
    description  VARCHAR(500)    NULL,
    properties   JSON            NULL,
    ip_address   VARCHAR(45)     NULL,
    user_agent   VARCHAR(255)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_log_subject (subject_type, subject_id),
    KEY idx_activity_log_actor (actor_id, created_at),
    KEY idx_activity_log_event (event, created_at),
    KEY idx_activity_log_created (created_at),
    CONSTRAINT fk_activity_log_actor
        FOREIGN KEY (actor_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 008_create_system.up.sql
-- ---------------------------------------------------------------------
-- =====================================================================
-- SyncClass — 008_create_system (UP)
-- Typed key/value settings store (SYS-01).
-- =====================================================================

CREATE TABLE IF NOT EXISTS settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key   VARCHAR(80)  NOT NULL,
    setting_value TEXT         NULL,
    value_type    ENUM('string','integer','decimal','boolean','json','time') NOT NULL DEFAULT 'string',
    group_name    VARCHAR(40)  NOT NULL DEFAULT 'general',
    label         VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    is_public     TINYINT(1)   NOT NULL DEFAULT 0,
    updated_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key),
    KEY idx_settings_group (group_name),
    CONSTRAINT fk_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
