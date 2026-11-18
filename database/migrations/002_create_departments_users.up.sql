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
