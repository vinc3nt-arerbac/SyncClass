<?php

declare(strict_types=1);

/**
 * Test support: SQLite mirror of the Phase 9 master-data tables.
 *
 * The production schema is MySQL-specific (ENUM, generated columns, CHECK
 * constraints, ON UPDATE CURRENT_TIMESTAMP). These tables mirror the columns
 * the repositories read and write, with the same names and nullability, so the
 * SQL under test is the SQL that will run against MySQL.
 *
 * Two deliberate differences:
 *   - `subjects.contact_hours` is a normal column here; MySQL generates it.
 *     The repositories never write it, so fixtures set it themselves.
 *   - the "one current semester per year" generated column and unique key are
 *     not reproduced; TermRepository enforces the rule in a transaction, which
 *     is what the tests exercise.
 *
 * Requires tests/support/auth_schema.php first (roles, permissions, users,
 * departments, activity_log).
 */

use App\Core\Database;

function master_schema(Database $database): void
{
    $statements = [
        'CREATE TABLE buildings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            campus TEXT NULL,
            address TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            building_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            name TEXT NULL,
            floor INTEGER NULL,
            room_type TEXT NOT NULL DEFAULT "lecture",
            capacity INTEGER NOT NULL DEFAULT 30,
            facilities TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (building_id, code)
        )',
        'CREATE TABLE subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            department_id INTEGER NOT NULL,
            units REAL NOT NULL DEFAULT 3.0,
            lecture_hours REAL NOT NULL DEFAULT 3.0,
            lab_hours REAL NOT NULL DEFAULT 0.0,
            contact_hours REAL NOT NULL DEFAULT 3.0,
            subject_type TEXT NOT NULL DEFAULT "lecture",
            preferred_room_type TEXT NULL,
            description TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NULL,
            program TEXT NULL,
            year_level INTEGER NOT NULL DEFAULT 1,
            department_id INTEGER NOT NULL,
            student_count INTEGER NOT NULL DEFAULT 40,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE faculty_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            department_id INTEGER NOT NULL,
            employment_type TEXT NOT NULL DEFAULT "full_time",
            max_load_units REAL NOT NULL DEFAULT 18.0,
            specialization TEXT NULL,
            office TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE faculty_availability (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            faculty_id INTEGER NOT NULL,
            day_of_week INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            availability_type TEXT NOT NULL DEFAULT "available",
            note TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE academic_years (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            is_current INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE semesters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            academic_year_id INTEGER NOT NULL,
            term_no INTEGER NOT NULL,
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "planned",
            is_current INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (academic_year_id, term_no)
        )',
    ];

    foreach ($statements as $statement) {
        $database->pdo()->exec($statement);
    }
}
