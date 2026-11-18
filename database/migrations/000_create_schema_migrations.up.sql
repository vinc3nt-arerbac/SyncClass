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
