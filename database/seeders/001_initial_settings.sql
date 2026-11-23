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
