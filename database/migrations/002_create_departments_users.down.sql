-- =====================================================================
-- SyncClass — 002_create_departments_users (DOWN)
-- =====================================================================

DROP TABLE IF EXISTS remember_tokens;
DROP TABLE IF EXISTS login_attempts;

-- Break the circular reference before dropping users.
ALTER TABLE departments DROP FOREIGN KEY fk_departments_dean;

DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS departments;
