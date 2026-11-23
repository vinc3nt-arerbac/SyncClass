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
