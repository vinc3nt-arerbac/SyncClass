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
