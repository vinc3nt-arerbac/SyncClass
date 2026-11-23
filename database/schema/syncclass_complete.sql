-- =====================================================================
-- SyncClass — Classroom & Subject Scheduler
-- COMPLETE INSTALLATION EXPORT (schema + seed data + demo accounts)
-- =====================================================================
-- Application version : 1.0.0-dev
-- Generated           : 2026-11-23 17:46:28
-- Database            : anth04_SyncClass
-- Source server       : 11.4.5-MariaDB
-- Engine target       : MySQL 8.0+ / MariaDB 10.4+ (XAMPP), InnoDB, utf8mb4
--
-- Contents
--   1. CREATE DATABASE / USE
--   2. Schema: every table, index, foreign key and CHECK constraint,
--      concatenated from database/migrations/*.up.sql
--   3. Seed data: roles, permission matrix, initial settings, demo users,
--      departments, faculty, subjects, rooms, sections, terms, schedules
--
-- Fresh installation
--   1. Start MySQL/MariaDB (XAMPP control panel).
--   2. Import this file, either in phpMyAdmin (Import → choose file → Go)
--      or from a terminal:
--          mysql -u root -p < database/schema/syncclass_complete.sql
--   3. Point config/local.php at the database (anth04_SyncClass) and open the app.
--
-- The file is generated: do not edit it by hand. Regenerate with
--   php database/schema/build.php     (schema changes)
--   php bin/export-sql.php            (data changes)
--
-- Demo sign-in accounts all use the password: SyncClass2026!
--   admin@sac.edu.ph            System Administrator
--   dean.csm@sac.edu.ph         Dean — CSM
--   secretary.csm@sac.edu.ph    Department Secretary — CSM
--   jcruz@sac.edu.ph            Faculty — CSM
-- Change these passwords (or delete the demo accounts) before production use.
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
-- =====================================================================
-- INITIAL SETTINGS (database/seeders/*.sql)
-- =====================================================================

-- 001_initial_settings.sql
-- =====================================================================
-- SyncClass — 001_initial_settings (installation defaults, SYS-01)
-- =====================================================================
-- Values the application needs before an administrator has opened the
-- settings screen. Keys mirror the configuration paths in config/*.php, so a
-- missing row simply falls back to the file default.
--
-- Re-runnable: INSERT IGNORE keeps whichever value the institution has already
-- saved, so importing this file again never overwrites live configuration.
--
-- Imported by database/schema/syncclass_complete.sql. To reapply it alone:
--     mysql -u root -p anth04_SyncClass < database/seeders/001_initial_settings.sql
-- =====================================================================

INSERT IGNORE INTO settings
    (setting_key, setting_value, value_type, group_name, label, description, is_public)
VALUES
    ('app.name', 'SyncClass', 'string', 'general', 'Application name', 'Product name shown in the header and page titles.', 1),
    ('app.institution', 'Santa Ana College', 'string', 'general', 'Institution name', 'Printed on report and export headers.', 1),
    ('app.timezone', 'Asia/Manila', 'string', 'general', 'Institution timezone', 'IANA timezone used to render every schedule time.', 1),
    ('app.locale', 'en', 'string', 'general', 'Default locale', 'Interface language.', 1),
    ('calendar.default_view', 'week', 'string', 'general', 'Default calendar view', 'Day, week or month.', 1),
    ('schedule.allowed_hours.start', '07:00', 'time', 'scheduling', 'Earliest class start', 'No session may start before this time.', 1),
    ('schedule.allowed_hours.end', '19:00', 'time', 'scheduling', 'Latest class end', 'No session may end after this time.', 1),
    ('schedule.slot_minutes', '30', 'integer', 'scheduling', 'Slot length in minutes', 'Grid step used by the calendar and the generator.', 1),
    ('schedule.min_duration_minutes', '30', 'integer', 'scheduling', 'Minimum session length', 'Shortest allowed meeting in minutes.', 1),
    ('schedule.max_duration_minutes', '240', 'integer', 'scheduling', 'Maximum session length', 'Longest allowed meeting in minutes.', 1),
    ('schedule.default_max_load_units', '18.0', 'decimal', 'scheduling', 'Default faculty load cap', 'Fallback when a faculty profile sets no ceiling.', 0),
    ('schedule.capacity_warning_ratio', '0.9', 'decimal', 'scheduling', 'Room capacity warning ratio', 'Above this ratio a room is flagged as nearly full.', 0),
    ('schedule.lunch_break', '12:00-13:00', 'string', 'scheduling', 'Break period', 'Sessions may not overlap this window.', 1),
    ('generator.time_budget_seconds', '30', 'integer', 'scheduling', 'Generator time budget', 'Upper bound for one whole-timetable generation run.', 0),
    ('security.password_min_length', '12', 'integer', 'security', 'Minimum password length', 'Enforced for every new or changed password.', 0),
    ('security.session_lifetime_minutes', '480', 'integer', 'security', 'Absolute session lifetime', 'Minutes before a session expires regardless of activity.', 0),
    ('security.idle_timeout_minutes', '30', 'integer', 'security', 'Idle timeout', 'Minutes of inactivity before the user is signed out.', 0),
    ('mail.enabled', '0', 'boolean', 'mail', 'Email notifications', 'Send email copies of in-app notifications once SMTP is configured.', 0),
    ('uploads.max_bytes', '2097152', 'integer', 'general', 'Maximum upload size', 'Largest accepted image upload in bytes.', 0),
    ('pagination.per_page', '25', 'integer', 'general', 'Rows per page', 'Default page size for list screens.', 0);

-- =====================================================================
-- SEED DATA
-- =====================================================================

-- ----------------------------------------------------------------------
-- schema_migrations (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- roles (4 rows)
-- ----------------------------------------------------------------------
INSERT INTO `roles` (`id`, `code`, `name`, `description`, `sort_order`, `is_system`, `created_at`, `updated_at`) VALUES
    (1, 'administrator', 'System Administrator', 'Full access to every module and setting.', 10, 1, '2026-11-23 09:46:20', '2026-11-23 09:46:20'),
    (2, 'dean', 'Dean', 'Owns the academic programme of one department and approves its timetable.', 20, 1, '2026-11-23 09:46:20', '2026-11-23 09:46:20'),
    (3, 'secretary', 'Department Secretary', 'Builds and maintains the department timetable day to day.', 30, 1, '2026-11-23 09:46:20', '2026-11-23 09:46:20'),
    (4, 'faculty', 'Faculty', 'Reads the assigned timetable and files change requests.', 40, 1, '2026-11-23 09:46:20', '2026-11-23 09:46:20');

-- ----------------------------------------------------------------------
-- permissions (51 rows)
-- ----------------------------------------------------------------------
INSERT INTO `permissions` (`id`, `code`, `module`, `name`, `description`, `created_at`) VALUES
    (1, 'users.view', 'users', 'View user accounts', NULL, '2026-11-23 09:46:20'),
    (2, 'users.create', 'users', 'Create user accounts', NULL, '2026-11-23 09:46:20'),
    (3, 'users.update', 'users', 'Edit user accounts', NULL, '2026-11-23 09:46:20'),
    (4, 'users.delete', 'users', 'Deactivate user accounts', NULL, '2026-11-23 09:46:20'),
    (5, 'roles.view', 'roles', 'View roles and their permissions', NULL, '2026-11-23 09:46:20'),
    (6, 'roles.manage', 'roles', 'Create roles and change the permission matrix', NULL, '2026-11-23 09:46:20'),
    (7, 'departments.view', 'departments', 'View departments', NULL, '2026-11-23 09:46:20'),
    (8, 'departments.create', 'departments', 'Create departments', NULL, '2026-11-23 09:46:20'),
    (9, 'departments.update', 'departments', 'Edit departments', NULL, '2026-11-23 09:46:20'),
    (10, 'departments.delete', 'departments', 'Archive departments', NULL, '2026-11-23 09:46:20'),
    (11, 'rooms.view', 'rooms', 'View buildings and rooms', NULL, '2026-11-23 09:46:20'),
    (12, 'rooms.create', 'rooms', 'Create buildings and rooms', NULL, '2026-11-23 09:46:20'),
    (13, 'rooms.update', 'rooms', 'Edit rooms and department allocations', NULL, '2026-11-23 09:46:20'),
    (14, 'rooms.delete', 'rooms', 'Archive rooms', NULL, '2026-11-23 09:46:20'),
    (15, 'subjects.view', 'subjects', 'View subjects', NULL, '2026-11-23 09:46:20'),
    (16, 'subjects.create', 'subjects', 'Create subjects', NULL, '2026-11-23 09:46:20'),
    (17, 'subjects.update', 'subjects', 'Edit subjects', NULL, '2026-11-23 09:46:20'),
    (18, 'subjects.delete', 'subjects', 'Archive subjects', NULL, '2026-11-23 09:46:20'),
    (19, 'sections.view', 'sections', 'View class sections', NULL, '2026-11-23 09:46:20'),
    (20, 'sections.create', 'sections', 'Create sections', NULL, '2026-11-23 09:46:20'),
    (21, 'sections.update', 'sections', 'Edit sections', NULL, '2026-11-23 09:46:20'),
    (22, 'sections.delete', 'sections', 'Archive sections', NULL, '2026-11-23 09:46:20'),
    (23, 'faculty.view', 'faculty', 'View faculty profiles', NULL, '2026-11-23 09:46:20'),
    (24, 'faculty.create', 'faculty', 'Create faculty profiles', NULL, '2026-11-23 09:46:20'),
    (25, 'faculty.update', 'faculty', 'Edit faculty profiles', NULL, '2026-11-23 09:46:20'),
    (26, 'faculty.delete', 'faculty', 'Archive faculty profiles', NULL, '2026-11-23 09:46:20'),
    (27, 'availability.view', 'availability', 'View availability windows', NULL, '2026-11-23 09:46:20'),
    (28, 'availability.update', 'availability', 'Edit availability windows (own department, or own profile)', NULL, '2026-11-23 09:46:20'),
    (29, 'terms.view', 'terms', 'View academic years and semesters', NULL, '2026-11-23 09:46:20'),
    (30, 'terms.manage', 'terms', 'Create terms and set the current semester', NULL, '2026-11-23 09:46:20'),
    (31, 'schedules.view', 'schedules', 'View class schedules', NULL, '2026-11-23 09:46:20'),
    (32, 'schedules.create', 'schedules', 'Create class schedules', NULL, '2026-11-23 09:46:20'),
    (33, 'schedules.update', 'schedules', 'Edit class schedules', NULL, '2026-11-23 09:46:20'),
    (34, 'schedules.delete', 'schedules', 'Cancel class schedules', NULL, '2026-11-23 09:46:20'),
    (35, 'schedules.approve', 'schedules', 'Approve or reject schedules', NULL, '2026-11-23 09:46:20'),
    (36, 'schedules.conflict_suggestions', 'schedules', 'Get conflict and room alternatives', NULL, '2026-11-23 09:46:20'),
    (37, 'calendar.view', 'calendar', 'View the calendar (month, week, day, timetable)', NULL, '2026-11-23 09:46:20'),
    (38, 'calendar.export', 'calendar', 'Export calendar events', NULL, '2026-11-23 09:46:20'),
    (39, 'requests.view', 'requests', 'View schedule change requests', NULL, '2026-11-23 09:46:20'),
    (40, 'requests.create', 'requests', 'File a schedule change request', NULL, '2026-11-23 09:46:20'),
    (41, 'requests.update', 'requests', 'Edit or cancel own change requests', NULL, '2026-11-23 09:46:20'),
    (42, 'requests.approve', 'requests', 'Approve or reject change requests', NULL, '2026-11-23 09:46:20'),
    (43, 'conflicts.view', 'conflicts', 'View detected schedule conflicts', NULL, '2026-11-23 09:46:20'),
    (44, 'generator.run', 'generator', 'Run the timetable generator', NULL, '2026-11-23 09:46:20'),
    (45, 'generator.approve', 'generator', 'Accept a generated draft timetable', NULL, '2026-11-23 09:46:20'),
    (46, 'reports.view', 'reports', 'View reports', NULL, '2026-11-23 09:46:20'),
    (47, 'reports.export', 'reports', 'Export reports for the user\'s own scope', NULL, '2026-11-23 09:46:20'),
    (48, 'reports.export.all', 'reports', 'Export reports for the whole institution', NULL, '2026-11-23 09:46:20'),
    (49, 'notifications.view', 'notifications', 'Read in-app notifications', NULL, '2026-11-23 09:46:20'),
    (50, 'settings.manage', 'settings', 'Change system settings', NULL, '2026-11-23 09:46:20'),
    (51, 'audit.view', 'settings', 'Read the audit log', NULL, '2026-11-23 09:46:20');

-- ----------------------------------------------------------------------
-- role_permissions (116 rows)
-- ----------------------------------------------------------------------
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`) VALUES
    (1, 1, '2026-11-23 09:46:20'),
    (1, 2, '2026-11-23 09:46:20'),
    (1, 3, '2026-11-23 09:46:20'),
    (1, 4, '2026-11-23 09:46:20'),
    (1, 5, '2026-11-23 09:46:20'),
    (1, 6, '2026-11-23 09:46:20'),
    (1, 7, '2026-11-23 09:46:20'),
    (1, 8, '2026-11-23 09:46:20'),
    (1, 9, '2026-11-23 09:46:20'),
    (1, 10, '2026-11-23 09:46:20'),
    (1, 11, '2026-11-23 09:46:20'),
    (1, 12, '2026-11-23 09:46:20'),
    (1, 13, '2026-11-23 09:46:20'),
    (1, 14, '2026-11-23 09:46:20'),
    (1, 15, '2026-11-23 09:46:20'),
    (1, 16, '2026-11-23 09:46:20'),
    (1, 17, '2026-11-23 09:46:20'),
    (1, 18, '2026-11-23 09:46:20'),
    (1, 19, '2026-11-23 09:46:20'),
    (1, 20, '2026-11-23 09:46:20'),
    (1, 21, '2026-11-23 09:46:20'),
    (1, 22, '2026-11-23 09:46:20'),
    (1, 23, '2026-11-23 09:46:20'),
    (1, 24, '2026-11-23 09:46:20'),
    (1, 25, '2026-11-23 09:46:20'),
    (1, 26, '2026-11-23 09:46:20'),
    (1, 27, '2026-11-23 09:46:20'),
    (1, 28, '2026-11-23 09:46:20'),
    (1, 29, '2026-11-23 09:46:20'),
    (1, 30, '2026-11-23 09:46:20'),
    (1, 31, '2026-11-23 09:46:20'),
    (1, 32, '2026-11-23 09:46:20'),
    (1, 33, '2026-11-23 09:46:20'),
    (1, 34, '2026-11-23 09:46:20'),
    (1, 35, '2026-11-23 09:46:20'),
    (1, 36, '2026-11-23 09:46:20'),
    (1, 37, '2026-11-23 09:46:20'),
    (1, 38, '2026-11-23 09:46:20'),
    (1, 39, '2026-11-23 09:46:20'),
    (1, 40, '2026-11-23 09:46:20'),
    (1, 41, '2026-11-23 09:46:20'),
    (1, 42, '2026-11-23 09:46:20'),
    (1, 43, '2026-11-23 09:46:20'),
    (1, 44, '2026-11-23 09:46:20'),
    (1, 45, '2026-11-23 09:46:20'),
    (1, 46, '2026-11-23 09:46:20'),
    (1, 47, '2026-11-23 09:46:20'),
    (1, 48, '2026-11-23 09:46:20'),
    (1, 49, '2026-11-23 09:46:20'),
    (1, 50, '2026-11-23 09:46:20'),
    (1, 51, '2026-11-23 09:46:20'),
    (2, 1, '2026-11-23 09:46:20'),
    (2, 7, '2026-11-23 09:46:20'),
    (2, 9, '2026-11-23 09:46:20'),
    (2, 11, '2026-11-23 09:46:20'),
    (2, 15, '2026-11-23 09:46:20'),
    (2, 16, '2026-11-23 09:46:20'),
    (2, 17, '2026-11-23 09:46:20'),
    (2, 18, '2026-11-23 09:46:20'),
    (2, 19, '2026-11-23 09:46:20'),
    (2, 20, '2026-11-23 09:46:20'),
    (2, 21, '2026-11-23 09:46:20'),
    (2, 22, '2026-11-23 09:46:20'),
    (2, 23, '2026-11-23 09:46:20'),
    (2, 24, '2026-11-23 09:46:20'),
    (2, 25, '2026-11-23 09:46:20'),
    (2, 27, '2026-11-23 09:46:20'),
    (2, 28, '2026-11-23 09:46:20'),
    (2, 29, '2026-11-23 09:46:20'),
    (2, 31, '2026-11-23 09:46:20'),
    (2, 35, '2026-11-23 09:46:20'),
    (2, 39, '2026-11-23 09:46:20'),
    (2, 42, '2026-11-23 09:46:20'),
    (2, 43, '2026-11-23 09:46:20'),
    (2, 44, '2026-11-23 09:46:20'),
    (2, 45, '2026-11-23 09:46:20'),
    (2, 46, '2026-11-23 09:46:20'),
    (2, 47, '2026-11-23 09:46:20'),
    (2, 49, '2026-11-23 09:46:20'),
    (3, 1, '2026-11-23 09:46:20'),
    (3, 7, '2026-11-23 09:46:20'),
    (3, 11, '2026-11-23 09:46:20'),
    (3, 13, '2026-11-23 09:46:20'),
    (3, 15, '2026-11-23 09:46:20'),
    (3, 19, '2026-11-23 09:46:20'),
    (3, 23, '2026-11-23 09:46:20'),
    (3, 27, '2026-11-23 09:46:20'),
    (3, 29, '2026-11-23 09:46:20'),
    (3, 31, '2026-11-23 09:46:20'),
    (3, 32, '2026-11-23 09:46:20'),
    (3, 33, '2026-11-23 09:46:20'),
    (3, 34, '2026-11-23 09:46:20'),
    (3, 39, '2026-11-23 09:46:20'),
    (3, 40, '2026-11-23 09:46:20'),
    (3, 41, '2026-11-23 09:46:20'),
    (3, 43, '2026-11-23 09:46:20'),
    (3, 44, '2026-11-23 09:46:20'),
    (3, 46, '2026-11-23 09:46:20'),
    (3, 47, '2026-11-23 09:46:20'),
    (3, 49, '2026-11-23 09:46:20');
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`) VALUES
    (4, 1, '2026-11-23 09:46:20'),
    (4, 7, '2026-11-23 09:46:20'),
    (4, 11, '2026-11-23 09:46:20'),
    (4, 15, '2026-11-23 09:46:20'),
    (4, 19, '2026-11-23 09:46:20'),
    (4, 23, '2026-11-23 09:46:20'),
    (4, 27, '2026-11-23 09:46:20'),
    (4, 28, '2026-11-23 09:46:20'),
    (4, 29, '2026-11-23 09:46:20'),
    (4, 31, '2026-11-23 09:46:20'),
    (4, 39, '2026-11-23 09:46:20'),
    (4, 40, '2026-11-23 09:46:20'),
    (4, 43, '2026-11-23 09:46:20'),
    (4, 46, '2026-11-23 09:46:20'),
    (4, 47, '2026-11-23 09:46:20'),
    (4, 49, '2026-11-23 09:46:20');

-- ----------------------------------------------------------------------
-- departments (5 rows)
-- ----------------------------------------------------------------------
INSERT INTO `departments` (`id`, `code`, `name`, `short_name`, `dean_user_id`, `email`, `phone`, `color_hex`, `is_active`, `created_at`, `updated_at`) VALUES
    (1, 'CSM', 'College of Computer Science & Engineering', 'CSE', 2, 'csm@sac.edu.ph', NULL, '#1B4F72', 1, '2026-11-23 09:46:20', '2026-11-23 09:46:27'),
    (2, 'ENGR', 'College of Engineering', 'ENGR', 8, 'engr@sac.edu.ph', NULL, '#7B2C2C', 1, '2026-11-23 09:46:20', '2026-11-23 09:46:27'),
    (3, 'BUS', 'College of Business Administration', 'BA', 14, 'bus@sac.edu.ph', NULL, '#7A4E12', 1, '2026-11-23 09:46:20', '2026-11-23 09:46:27'),
    (4, 'EDU', 'College of Education', 'COED', 20, 'edu@sac.edu.ph', NULL, '#3D6B2A', 1, '2026-11-23 09:46:20', '2026-11-23 09:46:27'),
    (5, 'HSS', 'College of Humanities & Social Sciences', 'CHSS', 26, 'hss@sac.edu.ph', NULL, '#5B2C6F', 1, '2026-11-23 09:46:20', '2026-11-23 09:46:27');

-- ----------------------------------------------------------------------
-- users (31 rows)
-- ----------------------------------------------------------------------
INSERT INTO `users` (`id`, `role_id`, `department_id`, `email`, `username`, `password_hash`, `first_name`, `middle_name`, `last_name`, `employee_no`, `phone`, `avatar_path`, `is_active`, `must_change_password`, `failed_login_attempts`, `locked_until`, `last_login_at`, `password_changed_at`, `created_at`, `updated_at`) VALUES
    (1, 1, NULL, 'admin@sac.edu.ph', 'admin', '$2y$12$CSoFJTQ1IdMvXUKJAvtxT.TQ/xeIfZLKY0M8oj.PkmfimkSQ/Q6E.', 'System', NULL, 'Administrator', 'ADMIN-0001', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:20', '2026-11-23 09:46:20', '2026-11-23 09:46:20'),
    (2, 2, 1, 'dean.csm@sac.edu.ph', 'dean.csm', '$2y$12$QX0lvY/fBZ7dL345EGuf.e77pn9vjIsaQAr6XhckSgrJ24FCzJcBS', 'Maria', NULL, 'Santos', 'DEAN-CSM', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:21', '2026-11-23 09:46:21', '2026-11-23 09:46:21'),
    (3, 3, 1, 'secretary.csm@sac.edu.ph', 'secretary.csm', '$2y$12$xxJrGwbW0k6E1QyMzAzniuv6fn0IgiQkkeZTdX7WVPHKzht4gQ0/e', 'Liza', NULL, 'Fernandez', 'SEC-CSM', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:21', '2026-11-23 09:46:21', '2026-11-23 09:46:21'),
    (4, 4, 1, 'jcruz@sac.edu.ph', 'jcruz', '$2y$12$dDHGhHXeDtvX1vwt2XjR2u5YB83bRC9T5x6QakZoKEczNhsGs2l/K', 'Juan', NULL, 'Cruz', 'FAC-CSM-01', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:21', '2026-11-23 09:46:21', '2026-11-23 09:46:21'),
    (5, 4, 1, 'areyes@sac.edu.ph', 'areyes', '$2y$12$zldIkWhtvhCWxUDqIeWotuaKcqGnC38UbNGiG98KSVdSTzvjCBMZe', 'Ana', NULL, 'Reyes', 'FAC-CSM-02', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:21', '2026-11-23 09:46:21', '2026-11-23 09:46:21'),
    (6, 4, 1, 'pmendoza@sac.edu.ph', 'pmendoza', '$2y$12$/9mNOBYp61bXyxJyypshcOMxw4frQ8QmDdJ1Wl8bD1Fk/BvztG4oa', 'Paolo', NULL, 'Mendoza', 'FAC-CSM-03', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:22', '2026-11-23 09:46:22', '2026-11-23 09:46:22'),
    (7, 4, 1, 'gaquino@sac.edu.ph', 'gaquino', '$2y$12$z5fpav0dZ/pyuyWd8UVpU.cRKQ/beujrJoxxOQ7KYDqxfo3ao1wuy', 'Grace', NULL, 'Aquino', 'FAC-CSM-04', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:22', '2026-11-23 09:46:22', '2026-11-23 09:46:22'),
    (8, 2, 2, 'dean.engr@sac.edu.ph', 'dean.engr', '$2y$12$YpmqD399uyIiL6saWm0r3eao0Y7wk00VTeh1ktKij35hGb0EvGkQC', 'Carlos', NULL, 'Garcia', 'DEAN-ENGR', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:22', '2026-11-23 09:46:22', '2026-11-23 09:46:22'),
    (9, 3, 2, 'secretary.engr@sac.edu.ph', 'secretary.engr', '$2y$12$78Gu9BuAk2QsJ7ySNZ2AEOwzIEcZ4fexY2Mh286Fvo/hbjT3szk7.', 'Ramon', NULL, 'Villanueva', 'SEC-ENGR', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:22', '2026-11-23 09:46:22', '2026-11-23 09:46:22'),
    (10, 4, 2, 'dtorres@sac.edu.ph', 'dtorres', '$2y$12$khWd.sJNX/jdOVY2GkYgXegjMAHX.jhPCUK.Px7B9vNtbZdTfmTL6', 'Daniel', NULL, 'Torres', 'FAC-ENGR-01', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:23', '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (11, 4, 2, 'bnavarro@sac.edu.ph', 'bnavarro', '$2y$12$u08XImQLWMDyh1HqmR0RAeso5G.MH.9OtMDe7VDTyRnangpJwRf4W', 'Benjamin', NULL, 'Navarro', 'FAC-ENGR-02', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:23', '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (12, 4, 2, 'tlim@sac.edu.ph', 'tlim', '$2y$12$22naifevh1uv1uVajrqwseH5Y5Kmk0NEEo9SPy0XHvO2L7I2uBqI.', 'Teresa', NULL, 'Lim', 'FAC-ENGR-03', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:23', '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (13, 4, 2, 'mbautista@sac.edu.ph', 'mbautista', '$2y$12$NDK4jgA4CGniEds/1q9DnuW/Zue2katPjHWEDFcEz1k7ny/MmhNJ2', 'Mark', NULL, 'Bautista', 'FAC-ENGR-04', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:23', '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (14, 2, 3, 'dean.bus@sac.edu.ph', 'dean.bus', '$2y$12$ka3JudFn0s3s8CegQvdeMucPKy/PD0FdJ1aGi3JlU3NGATydk3c36', 'Elena', NULL, 'Ramos', 'DEAN-BUS', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:23', '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (15, 3, 3, 'secretary.bus@sac.edu.ph', 'secretary.bus', '$2y$12$okNlvEK9wTlAzPLNOBIKJeDdU2Ri16uh.MTS483MJitHCqWZdEZxu', 'Carmela', NULL, 'Domingo', 'SEC-BUS', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:24', '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (16, 4, 3, 'esalazar@sac.edu.ph', 'esalazar', '$2y$12$tMrT.tMbbUWBtUkk2IVvLORQOWH2zWUZqhhaW4vvWyEFGZEbvE0wS', 'Eduardo', NULL, 'Salazar', 'FAC-BUS-01', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:24', '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (17, 4, 3, 'rmercado@sac.edu.ph', 'rmercado', '$2y$12$uYzLG6vXBNGupThOpZ/HLe4dLKD/sh5euI2o22zvxaluZrPi/1iyS', 'Rosario', NULL, 'Mercado', 'FAC-BUS-02', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:24', '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (18, 4, 3, 'acastillo@sac.edu.ph', 'acastillo', '$2y$12$wltU5.AGdEDfVl/Jjveib.hBziR3Qw3volmMSd1DQQk.5gSONGU4W', 'Alfredo', NULL, 'Castillo', 'FAC-BUS-03', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:24', '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (19, 4, 3, 'npascual@sac.edu.ph', 'npascual', '$2y$12$WzR/q3k5WuT3UJceLqXVKePMEq3x5.YKSodx75YF7wGMaWlDd58We', 'Nena', NULL, 'Pascual', 'FAC-BUS-04', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:25', '2026-11-23 09:46:25', '2026-11-23 09:46:25'),
    (20, 2, 4, 'dean.edu@sac.edu.ph', 'dean.edu', '$2y$12$Z5RR1YuksddWA4N7sw6Sr.86bTUdPQ4cNV6XO8R.qFW2lRyd24yGS', 'Sofia', NULL, 'Dela Cruz', 'DEAN-EDU', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:25', '2026-11-23 09:46:25', '2026-11-23 09:46:25'),
    (21, 3, 4, 'secretary.edu@sac.edu.ph', 'secretary.edu', '$2y$12$uadsVE954E1jUKeBC.vq/OnKD5.epIXfPNF2MN7LdxdDCnQ5/fbeW', 'Victor', NULL, 'Buenaventura', 'SEC-EDU', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:25', '2026-11-23 09:46:25', '2026-11-23 09:46:25'),
    (22, 4, 4, 'faguilar@sac.edu.ph', 'faguilar', '$2y$12$SlY9FuKXD7I5liSrY1aQme2r1ULSAXcE4OztnjBYXJZ.8C0YpZxpa', 'Felix', NULL, 'Aguilar', 'FAC-EDU-01', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:25', '2026-11-23 09:46:25', '2026-11-23 09:46:25'),
    (23, 4, 4, 'brosales@sac.edu.ph', 'brosales', '$2y$12$tr02figxCh0Ek3htzsnFBeix3MUnqlbCLCVQg83qUhBeT.R8CrmNC', 'Bianca', NULL, 'Rosales', 'FAC-EDU-02', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:26', '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (24, 4, 4, 'hmarquez@sac.edu.ph', 'hmarquez', '$2y$12$k3IcphU1ANLCcHq85GgU8.FAZ9PjG4xBJPGvNLmu8SFqr7YqNcQGi', 'Hector', NULL, 'Marquez', 'FAC-EDU-03', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:26', '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (25, 4, 4, 'docampo@sac.edu.ph', 'docampo', '$2y$12$fl4TKLtiG9WmFmVNLhUS6erSrTqARcnh0Eu6kIs7gnfBiy9qErqFW', 'Divina', NULL, 'Ocampo', 'FAC-EDU-04', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:26', '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (26, 2, 5, 'dean.hss@sac.edu.ph', 'dean.hss', '$2y$12$.S4rKDWvTQvuTHqWZZQF1OjoT2TzSrvLEpRcsNiHf0h6vl4a72btm', 'Rosa', NULL, 'Villamor', 'DEAN-HSS', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:26', '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (27, 3, 5, 'secretary.hss@sac.edu.ph', 'secretary.hss', '$2y$12$w0VbitrLOH.EyNPsbKiuVul1lOn/F8vB7KqY6Q/zY7R4CZkpBbywe', 'Irene', NULL, 'Fajardo', 'SEC-HSS', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:27', '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (28, 4, 5, 'gestrada@sac.edu.ph', 'gestrada', '$2y$12$EJhpVayFp/yTimNm4orzoOl9/vGdFsSwwkyRNd1gG.yrm0V8oyMSy', 'Gabriel', NULL, 'Estrada', 'FAC-HSS-01', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:27', '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (29, 4, 5, 'cpanganiban@sac.edu.ph', 'cpanganiban', '$2y$12$hv2LTogp4QwqxAOoGxhJLugOh2kGR7J2jGilZtlaYFUZQ2LeWylqu', 'Cecilia', NULL, 'Panganiban', 'FAC-HSS-02', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:27', '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (30, 4, 5, 'ozamora@sac.edu.ph', 'ozamora', '$2y$12$kOGbohe1lRiw6OXdOcu4W.23dxqQtstxJTuUkpNnjmivV3m4.vgTO', 'Oscar', NULL, 'Zamora', 'FAC-HSS-03', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:27', '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (31, 4, 5, 'lfontanilla@sac.edu.ph', 'lfontanilla', '$2y$12$ahhDS6YBDiwMnaJabtrYsepUGWw8HdCx6Wl4r2WZ.8Yp0h2fDLy4q', 'Lucia', NULL, 'Fontanilla', 'FAC-HSS-04', NULL, NULL, 1, 0, 0, NULL, NULL, '2026-11-23 17:46:27', '2026-11-23 09:46:27', '2026-11-23 09:46:27');

-- ----------------------------------------------------------------------
-- buildings (4 rows)
-- ----------------------------------------------------------------------
INSERT INTO `buildings` (`id`, `code`, `name`, `campus`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
    (1, 'MAIN', 'Main Building', 'Main Campus', '100 University Avenue', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (2, 'SCI', 'Science Building', 'Main Campus', '102 Education Boulevard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (3, 'ENGH', 'Engineering Hall', 'North Campus', '200 Technology Park Drive', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (4, 'HSC', 'Humanities & Sciences Center', 'South Campus', '300 Liberal Arts Parkway', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27');

-- ----------------------------------------------------------------------
-- rooms (30 rows)
-- ----------------------------------------------------------------------
INSERT INTO `rooms` (`id`, `building_id`, `code`, `name`, `floor`, `room_type`, `capacity`, `facilities`, `is_active`, `created_at`, `updated_at`) VALUES
    (1, 1, 'R101', 'Main Building Room 1', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (2, 1, 'R102', 'Main Building Room 2', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (3, 1, 'R103', 'Main Building Room 3', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (4, 1, 'R104', 'Main Building Room 4', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (5, 1, 'R105', 'Main Building Room 5', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (6, 1, 'R201', 'Main Building Room 6', 2, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (7, 1, 'R202', 'Main Building Room 7', 2, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (8, 1, 'R203', 'Main Building Room 8', 2, 'seminar', 40, 'Video conferencing, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (9, 1, 'R204', 'Main Building Room 9', 2, 'seminar', 40, 'Video conferencing, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (10, 2, 'R101', 'Science Building Room 1', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (11, 2, 'R102', 'Science Building Room 2', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (12, 2, 'R103', 'Science Building Room 3', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (13, 2, 'R104', 'Science Building Room 4', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (14, 2, 'R105', 'Science Building Room 5', 1, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (15, 2, 'R201', 'Science Building Room 6', 2, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (16, 2, 'R202', 'Science Building Room 7', 2, 'seminar', 40, 'Video conferencing, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (17, 2, 'R203', 'Science Building Room 8', 2, 'seminar', 40, 'Video conferencing, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (18, 3, 'R101', 'Engineering Hall Room 1', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (19, 3, 'R102', 'Engineering Hall Room 2', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (20, 3, 'R103', 'Engineering Hall Room 3', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (21, 3, 'R104', 'Engineering Hall Room 4', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (22, 3, 'R105', 'Engineering Hall Room 5', 1, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (23, 3, 'R201', 'Engineering Hall Room 6', 2, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (24, 3, 'R202', 'Engineering Hall Room 7', 2, 'seminar', 40, 'Video conferencing, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (25, 4, 'R101', 'Humanities & Sciences Center Room 1', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (26, 4, 'R102', 'Humanities & Sciences Center Room 2', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (27, 4, 'R103', 'Humanities & Sciences Center Room 3', 1, 'lecture', 45, 'Projector, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (28, 4, 'R104', 'Humanities & Sciences Center Room 4', 1, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (29, 4, 'R105', 'Humanities & Sciences Center Room 5', 1, 'laboratory', 40, 'Projector, Smartboard, Lab benches', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (30, 4, 'R201', 'Humanities & Sciences Center Room 6', 2, 'seminar', 40, 'Video conferencing, Whiteboard', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27');

-- ----------------------------------------------------------------------
-- academic_years (2 rows)
-- ----------------------------------------------------------------------
INSERT INTO `academic_years` (`id`, `code`, `start_date`, `end_date`, `is_current`, `created_at`, `updated_at`) VALUES
    (1, 'SY2025-2026', '2025-06-01', '2026-05-31', 0, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (2, 'SY2026-2027', '2026-06-01', '2027-05-31', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28');

-- ----------------------------------------------------------------------
-- semesters (4 rows)
-- ----------------------------------------------------------------------
INSERT INTO `semesters` (`id`, `academic_year_id`, `term_no`, `name`, `code`, `start_date`, `end_date`, `status`, `is_current`, `created_at`, `updated_at`) VALUES
    (1, 1, 1, 'First Semester 2025–2026', '2025-1ST', '2025-06-16', '2025-10-31', 'closed', 0, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (2, 1, 2, 'Second Semester 2025–2026', '2025-2ND', '2025-11-04', '2026-03-31', 'closed', 0, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (3, 2, 1, 'First Semester 2026–2027', '2026-1ST', '2026-06-15', '2026-10-31', 'active', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (4, 2, 2, 'Second Semester 2026–2027', '2026-2ND', '2026-11-03', '2027-03-31', 'planned', 0, '2026-11-23 09:46:28', '2026-11-23 09:46:28');

-- ----------------------------------------------------------------------
-- time_slots (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- subjects (50 rows)
-- ----------------------------------------------------------------------
INSERT INTO `subjects` (`id`, `code`, `title`, `department_id`, `units`, `lecture_hours`, `lab_hours`, `subject_type`, `preferred_room_type`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
    (1, 'CMP101', 'Introduction to Computing', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (2, 'CMP102', 'Programming Fundamentals', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (3, 'CMP103', 'Data Structures & Algorithms', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (4, 'CMP104', 'Database Management Systems', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (5, 'CMP105', 'Web Systems & Technologies', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (6, 'CMP106', 'Computer Networks', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (7, 'CMP107', 'Operating Systems', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (8, 'CMP108', 'Software Engineering', 1, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (9, 'CMP109', 'Information Assurance & Security', 1, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (10, 'CMP110', 'Capstone Project I', 1, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (11, 'EGR101', 'Engineering Graphics', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (12, 'EGR102', 'Statics of Rigid Bodies', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (13, 'EGR103', 'Dynamics of Rigid Bodies', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (14, 'EGR104', 'Mechanics of Deformable Bodies', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (15, 'EGR105', 'Circuit Theory', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (16, 'EGR106', 'Thermodynamics', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (17, 'EGR107', 'Fluid Mechanics', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (18, 'EGR108', 'Materials Science', 2, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (19, 'EGR109', 'Engineering Economics', 2, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (20, 'EGR110', 'Control Systems', 2, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (21, 'BUS101', 'Principles of Management', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (22, 'BUS102', 'Financial Accounting', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (23, 'BUS103', 'Business Statistics', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (24, 'BUS104', 'Marketing Management', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (25, 'BUS105', 'Human Resource Management', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (26, 'BUS106', 'Operations Management', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (27, 'BUS107', 'Corporate Finance', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (28, 'BUS108', 'Business Law', 3, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (29, 'BUS109', 'Strategic Management', 3, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (30, 'BUS110', 'Business Analytics', 3, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (31, 'EDU101', 'Foundations of Education', 4, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (32, 'EDU102', 'Child & Adolescent Development', 4, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (33, 'EDU103', 'Learning Theories', 4, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (34, 'EDU104', 'Instructional Design', 4, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (35, 'EDU105', 'Assessment of Learning', 4, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (36, 'EDU106', 'Curriculum Development', 4, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (37, 'EDU107', 'Educational Technology', 4, '3.0', '3.0', '0.0', 'lecture', 'seminar', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (38, 'EDU108', 'Classroom Management', 4, '3.0', '3.0', '0.0', 'lecture', 'seminar', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (39, 'EDU109', 'Research Methods in Education', 4, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (40, 'EDU110', 'Teaching Internship', 4, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (41, 'HSS101', 'Introduction to Philosophy', 5, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (42, 'HSS102', 'Ethics & Society', 5, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (43, 'HSS103', 'Philippine History', 5, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (44, 'HSS104', 'World Civilizations', 5, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (45, 'HSS105', 'Introduction to Sociology', 5, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (46, 'HSS106', 'General Psychology', 5, '3.0', '3.0', '0.0', 'lecture', 'lecture', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (47, 'HSS107', 'Political Science', 5, '3.0', '3.0', '0.0', 'lecture', 'seminar', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (48, 'HSS108', 'Principles of Economics', 5, '3.0', '3.0', '0.0', 'lecture', 'seminar', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (49, 'HSS109', 'Literature & Culture', 5, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (50, 'HSS110', 'Research Writing', 5, '3.0', '2.0', '1.0', 'mixed', 'laboratory', 'Demonstration catalogue entry used by the Phase 15 reports.', 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28');

-- ----------------------------------------------------------------------
-- sections (15 rows)
-- ----------------------------------------------------------------------
INSERT INTO `sections` (`id`, `code`, `name`, `program`, `year_level`, `department_id`, `student_count`, `is_active`, `created_at`, `updated_at`) VALUES
    (1, 'CSE-1A', 'BS Computer Science — Year 1', 'BS Computer Science', 1, 1, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (2, 'CSE-2A', 'BS Computer Science — Year 2', 'BS Computer Science', 2, 1, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (3, 'CSE-3A', 'BS Computer Science — Year 3', 'BS Computer Science', 3, 1, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (4, 'ENGR-1A', 'BS Civil Engineering — Year 1', 'BS Civil Engineering', 1, 2, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (5, 'ENGR-2A', 'BS Civil Engineering — Year 2', 'BS Civil Engineering', 2, 2, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (6, 'ENGR-3A', 'BS Civil Engineering — Year 3', 'BS Civil Engineering', 3, 2, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (7, 'BA-1A', 'BS Business Administration — Year 1', 'BS Business Administration', 1, 3, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (8, 'BA-2A', 'BS Business Administration — Year 2', 'BS Business Administration', 2, 3, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (9, 'BA-3A', 'BS Business Administration — Year 3', 'BS Business Administration', 3, 3, 40, 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (10, 'COED-1A', 'Bachelor of Elementary Education — Year 1', 'Bachelor of Elementary Education', 1, 4, 40, 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (11, 'COED-2A', 'Bachelor of Elementary Education — Year 2', 'Bachelor of Elementary Education', 2, 4, 40, 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (12, 'COED-3A', 'Bachelor of Elementary Education — Year 3', 'Bachelor of Elementary Education', 3, 4, 40, 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (13, 'CHSS-1A', 'BA Communication — Year 1', 'BA Communication', 1, 5, 40, 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (14, 'CHSS-2A', 'BA Communication — Year 2', 'BA Communication', 2, 5, 40, 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (15, 'CHSS-3A', 'BA Communication — Year 3', 'BA Communication', 3, 5, 40, 1, '2026-11-23 09:46:28', '2026-11-23 09:46:28');

-- ----------------------------------------------------------------------
-- faculty_profiles (20 rows)
-- ----------------------------------------------------------------------
INSERT INTO `faculty_profiles` (`id`, `user_id`, `department_id`, `employment_type`, `max_load_units`, `specialization`, `office`, `is_active`, `created_at`, `updated_at`) VALUES
    (1, 4, 1, 'full_time', '24.0', 'College of Computer Science & Engineering', 'CSE Faculty Room 1', 1, '2026-11-23 09:46:21', '2026-11-23 09:46:21'),
    (2, 5, 1, 'full_time', '24.0', 'College of Computer Science & Engineering', 'CSE Faculty Room 2', 1, '2026-11-23 09:46:21', '2026-11-23 09:46:21'),
    (3, 6, 1, 'part_time', '18.0', 'College of Computer Science & Engineering', 'CSE Faculty Room 3', 1, '2026-11-23 09:46:22', '2026-11-23 09:46:22'),
    (4, 7, 1, 'part_time', '18.0', 'College of Computer Science & Engineering', 'CSE Faculty Room 4', 1, '2026-11-23 09:46:22', '2026-11-23 09:46:22'),
    (5, 10, 2, 'full_time', '24.0', 'College of Engineering', 'ENGR Faculty Room 1', 1, '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (6, 11, 2, 'full_time', '24.0', 'College of Engineering', 'ENGR Faculty Room 2', 1, '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (7, 12, 2, 'part_time', '18.0', 'College of Engineering', 'ENGR Faculty Room 3', 1, '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (8, 13, 2, 'part_time', '18.0', 'College of Engineering', 'ENGR Faculty Room 4', 1, '2026-11-23 09:46:23', '2026-11-23 09:46:23'),
    (9, 16, 3, 'full_time', '24.0', 'College of Business Administration', 'BA Faculty Room 1', 1, '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (10, 17, 3, 'full_time', '24.0', 'College of Business Administration', 'BA Faculty Room 2', 1, '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (11, 18, 3, 'part_time', '18.0', 'College of Business Administration', 'BA Faculty Room 3', 1, '2026-11-23 09:46:24', '2026-11-23 09:46:24'),
    (12, 19, 3, 'part_time', '18.0', 'College of Business Administration', 'BA Faculty Room 4', 1, '2026-11-23 09:46:25', '2026-11-23 09:46:25'),
    (13, 22, 4, 'full_time', '24.0', 'College of Education', 'COED Faculty Room 1', 1, '2026-11-23 09:46:25', '2026-11-23 09:46:25'),
    (14, 23, 4, 'full_time', '24.0', 'College of Education', 'COED Faculty Room 2', 1, '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (15, 24, 4, 'part_time', '18.0', 'College of Education', 'COED Faculty Room 3', 1, '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (16, 25, 4, 'part_time', '18.0', 'College of Education', 'COED Faculty Room 4', 1, '2026-11-23 09:46:26', '2026-11-23 09:46:26'),
    (17, 28, 5, 'full_time', '24.0', 'College of Humanities & Social Sciences', 'CHSS Faculty Room 1', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (18, 29, 5, 'full_time', '24.0', 'College of Humanities & Social Sciences', 'CHSS Faculty Room 2', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (19, 30, 5, 'part_time', '18.0', 'College of Humanities & Social Sciences', 'CHSS Faculty Room 3', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27'),
    (20, 31, 5, 'part_time', '18.0', 'College of Humanities & Social Sciences', 'CHSS Faculty Room 4', 1, '2026-11-23 09:46:27', '2026-11-23 09:46:27');

-- ----------------------------------------------------------------------
-- faculty_availability (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- faculty_subjects (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- section_subjects (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- schedules (100 rows)
-- ----------------------------------------------------------------------
INSERT INTO `schedules` (`id`, `semester_id`, `department_id`, `section_id`, `subject_id`, `faculty_id`, `room_id`, `day_of_week`, `start_time`, `end_time`, `meeting_date`, `recurrence`, `status`, `notes`, `created_by`, `updated_by`, `approved_by`, `approved_at`, `published_at`, `cancelled_at`, `cancellation_reason`, `created_at`, `updated_at`) VALUES
    (1, 3, 1, 1, 1, 1, 1, 1, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (2, 3, 1, 2, 2, 1, 2, 1, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (3, 3, 1, 3, 3, 1, 3, 1, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (4, 3, 1, 1, 4, 1, 4, 1, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (5, 3, 1, 2, 5, 1, 5, 1, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (6, 3, 1, 3, 6, 2, 10, 1, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (7, 3, 1, 1, 7, 2, 11, 1, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (8, 3, 1, 2, 8, 2, 12, 2, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (9, 3, 1, 3, 9, 2, 6, 2, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (10, 3, 1, 1, 10, 2, 7, 2, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (11, 3, 1, 2, 1, 3, 19, 2, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (12, 3, 1, 3, 2, 3, 20, 2, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (13, 3, 1, 1, 3, 3, 21, 2, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (14, 3, 1, 2, 4, 3, 25, 2, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (15, 3, 1, 3, 5, 3, 26, 3, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (16, 3, 1, 1, 6, 4, 27, 3, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (17, 3, 1, 2, 7, 4, 1, 3, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (18, 3, 1, 3, 8, 4, 2, 3, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (19, 3, 1, 1, 9, 4, 14, 3, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (20, 3, 1, 2, 10, 4, 15, 3, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (21, 3, 2, 4, 11, 5, 5, 3, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (22, 3, 2, 5, 12, 5, 10, 4, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (23, 3, 2, 6, 13, 5, 11, 4, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (24, 3, 2, 4, 14, 5, 12, 4, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (25, 3, 2, 5, 15, 5, 13, 4, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (26, 3, 2, 6, 16, 6, 18, 4, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (27, 3, 2, 4, 17, 6, 19, 4, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (28, 3, 2, 5, 18, 6, 20, 4, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (29, 3, 2, 6, 19, 6, 22, 5, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (30, 3, 2, 4, 20, 6, 23, 5, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (31, 3, 2, 5, 11, 7, 26, 5, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (32, 3, 2, 6, 12, 7, 27, 5, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (33, 3, 2, 4, 13, 7, 1, 5, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (34, 3, 2, 5, 14, 7, 2, 5, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (35, 3, 2, 6, 15, 7, 3, 5, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (36, 3, 2, 4, 16, 8, 4, 1, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (37, 3, 2, 5, 17, 8, 5, 1, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (38, 3, 2, 6, 18, 8, 10, 1, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (39, 3, 2, 4, 19, 8, 28, 1, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (40, 3, 2, 5, 20, 8, 29, 1, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (41, 3, 3, 7, 21, 9, 13, 1, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (42, 3, 3, 8, 22, 9, 18, 1, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (43, 3, 3, 9, 23, 9, 19, 2, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (44, 3, 3, 7, 24, 9, 20, 2, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (45, 3, 3, 8, 25, 9, 21, 2, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (46, 3, 3, 9, 26, 10, 25, 2, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (47, 3, 3, 7, 27, 10, 26, 2, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (48, 3, 3, 8, 28, 10, 27, 2, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (49, 3, 3, 9, 29, 10, 6, 2, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (50, 3, 3, 7, 30, 10, 7, 3, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (51, 3, 3, 8, 21, 11, 3, 3, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (52, 3, 3, 9, 22, 11, 4, 3, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (53, 3, 3, 7, 23, 11, 5, 3, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (54, 3, 3, 8, 24, 11, 10, 3, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (55, 3, 3, 9, 25, 11, 11, 3, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (56, 3, 3, 7, 26, 12, 12, 3, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (57, 3, 3, 8, 27, 12, 13, 4, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (58, 3, 3, 9, 28, 12, 18, 4, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (59, 3, 3, 7, 29, 12, 14, 4, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (60, 3, 3, 8, 30, 12, 15, 4, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (61, 3, 4, 10, 31, 13, 21, 4, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (62, 3, 4, 11, 32, 13, 25, 4, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (63, 3, 4, 12, 33, 13, 26, 4, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (64, 3, 4, 10, 34, 13, 27, 5, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (65, 3, 4, 11, 35, 13, 1, 5, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (66, 3, 4, 12, 36, 14, 2, 5, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (67, 3, 4, 10, 37, 14, 8, 5, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (68, 3, 4, 11, 38, 14, 9, 5, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (69, 3, 4, 12, 39, 14, 22, 5, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (70, 3, 4, 10, 40, 14, 23, 5, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (71, 3, 4, 11, 31, 15, 11, 1, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (72, 3, 4, 12, 32, 15, 12, 1, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (73, 3, 4, 10, 33, 15, 13, 1, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (74, 3, 4, 11, 34, 15, 18, 1, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (75, 3, 4, 12, 35, 15, 19, 1, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (76, 3, 4, 10, 36, 16, 20, 1, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (77, 3, 4, 11, 37, 16, 24, 1, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (78, 3, 4, 12, 38, 16, 30, 2, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (79, 3, 4, 10, 39, 16, 28, 2, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (80, 3, 4, 11, 40, 16, 29, 2, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (81, 3, 5, 13, 41, 17, 1, 2, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (82, 3, 5, 14, 42, 17, 2, 2, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (83, 3, 5, 15, 43, 17, 3, 2, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (84, 3, 5, 13, 44, 17, 4, 2, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (85, 3, 5, 14, 45, 17, 5, 3, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (86, 3, 5, 15, 46, 18, 10, 3, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (87, 3, 5, 13, 47, 18, 16, 3, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (88, 3, 5, 14, 48, 18, 17, 3, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (89, 3, 5, 15, 49, 18, 6, 3, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (90, 3, 5, 13, 50, 18, 7, 3, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (91, 3, 5, 14, 41, 19, 19, 3, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (92, 3, 5, 15, 42, 19, 20, 4, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (93, 3, 5, 13, 43, 19, 21, 4, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (94, 3, 5, 14, 44, 19, 25, 4, '10:00:00', '11:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (95, 3, 5, 15, 45, 19, 26, 4, '13:00:00', '14:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (96, 3, 5, 13, 46, 20, 27, 4, '14:30:00', '16:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (97, 3, 5, 14, 47, 20, 8, 4, '16:00:00', '17:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (98, 3, 5, 15, 48, 20, 9, 4, '17:30:00', '19:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (99, 3, 5, 13, 49, 20, 14, 5, '07:00:00', '08:30:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28'),
    (100, 3, 5, 14, 50, 20, 15, 5, '08:30:00', '10:00:00', NULL, 'weekly', 'approved', 'Demo seed dataset.', 1, 1, 1, '2026-11-23 17:46:28', NULL, NULL, NULL, '2026-11-23 09:46:28', '2026-11-23 09:46:28');

-- ----------------------------------------------------------------------
-- schedule_conflicts (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- schedule_comments (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- comment_mentions (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- change_requests (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- tasks (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- task_checklist_items (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- task_assignments (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- activity_log (1 rows)
-- ----------------------------------------------------------------------
INSERT INTO `activity_log` (`id`, `actor_id`, `event`, `subject_type`, `subject_id`, `description`, `properties`, `ip_address`, `user_agent`, `created_at`) VALUES
    (1, 1, 'system.demo_seeded', 'institution', NULL, 'Demo data set created by bin/seed-demo.php.', '{\"departments\":5,\"faculty\":20,\"subjects\":50,\"rooms\":30,\"schedules\":100,\"semester\":\"2026-1ST\"}', '127.0.0.1', 'bin/seed-demo.php', '2026-11-23 09:46:28');

-- ----------------------------------------------------------------------
-- notifications (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- login_attempts (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

-- ----------------------------------------------------------------------
-- remember_tokens (0 rows)
-- ----------------------------------------------------------------------
-- (empty)

SET FOREIGN_KEY_CHECKS = 1;

-- Row counts written by this export
--   schema_migrations            0
--   roles                        4
--   permissions                 51
--   role_permissions           116
--   departments                  5
--   users                       31
--   buildings                    4
--   rooms                       30
--   academic_years               2
--   semesters                    4
--   time_slots                   0
--   subjects                    50
--   sections                    15
--   faculty_profiles            20
--   faculty_availability         0
--   faculty_subjects             0
--   section_subjects             0
--   schedules                  100
--   schedule_conflicts           0
--   schedule_comments            0
--   comment_mentions             0
--   change_requests              0
--   tasks                        0
--   task_checklist_items         0
--   task_assignments             0
--   activity_log                 1
--   notifications                0
--   login_attempts               0
--   remember_tokens              0
--   TOTAL                      433
