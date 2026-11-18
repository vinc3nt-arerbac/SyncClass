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
