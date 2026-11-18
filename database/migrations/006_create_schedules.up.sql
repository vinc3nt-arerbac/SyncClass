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
