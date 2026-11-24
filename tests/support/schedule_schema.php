<?php

declare(strict_types=1);

/**
 * Test support: SQLite mirror of the Phase 10 `schedules` table.
 *
 * The production table has ENUMs, CHECK constraints, foreign keys and three
 * covering indexes for the conflict query. None of that changes the SQL the
 * repository runs, so the mirror keeps the columns and their nullability and
 * leaves the constraints to MySQL.
 *
 * Requires auth_schema.php and master_schema.php.
 */

use App\Core\Database;

function schedule_schema(Database $database): void
{
    $database->pdo()->exec(
        'CREATE TABLE schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            semester_id INTEGER NOT NULL,
            department_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            faculty_id INTEGER NOT NULL,
            room_id INTEGER NOT NULL,
            day_of_week INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            meeting_date TEXT NULL,
            recurrence TEXT NOT NULL DEFAULT "weekly",
            status TEXT NOT NULL DEFAULT "draft",
            notes TEXT NULL,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            approved_by INTEGER NULL,
            approved_at TEXT NULL,
            published_at TEXT NULL,
            cancelled_at TEXT NULL,
            cancellation_reason TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    // UAT-2: approving an entry writes the teacher's in-app notification. The
    // production table is in database/schema/SyncClass.sql; this mirror keeps
    // the columns NotificationRepository::create() writes.
    $database->pdo()->exec(
        'CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NULL,
            subject_type TEXT NULL,
            subject_id INTEGER NULL,
            action_url TEXT NULL,
            channel TEXT NOT NULL DEFAULT "in_app",
            is_read INTEGER NOT NULL DEFAULT 0,
            read_at TEXT NULL,
            email_status TEXT NOT NULL DEFAULT "none",
            email_attempts INTEGER NOT NULL DEFAULT 0,
            email_last_error TEXT NULL,
            sent_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}
