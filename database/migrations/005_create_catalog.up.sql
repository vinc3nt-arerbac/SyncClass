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
