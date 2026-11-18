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
