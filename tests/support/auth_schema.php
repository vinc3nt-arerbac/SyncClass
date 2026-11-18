<?php

declare(strict_types=1);

/**
 * Test support: an in-memory schema for the authentication tables.
 *
 * The production schema is MySQL-specific (ENGINE, ENUM, JSON, ON UPDATE
 * CURRENT_TIMESTAMP), so it cannot be imported into the SQLite database the
 * suites use. These statements mirror the subset of columns the Phase 7
 * repositories actually read and write — same names, same nullability — so the
 * SQL under test is the SQL that will run against MySQL.
 *
 * Required by tests/auth_test.php and tests/core_framework_test.php.
 */

use App\Core\Database;

/**
 * Create the tables used by UserRepository, LoginAttemptRepository,
 * RememberTokenRepository, AuditRepository and RoleRepository — plus the
 * Phase 8 RBAC tables (permissions, role_permissions), so the same fixture
 * serves tests/auth_test.php, tests/rbac_test.php and the framework suite.
 */
function auth_schema(Database $database): void
{
    $statements = [
        'CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 10,
            is_system INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            short_name TEXT NULL,
            dean_user_id INTEGER NULL,
            email TEXT NULL,
            phone TEXT NULL,
            color_hex TEXT NOT NULL DEFAULT "#8C1D40",
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_id INTEGER NOT NULL,
            department_id INTEGER NULL,
            email TEXT NOT NULL UNIQUE,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            first_name TEXT NOT NULL,
            middle_name TEXT NULL,
            last_name TEXT NOT NULL,
            employee_no TEXT NULL UNIQUE,
            phone TEXT NULL,
            avatar_path TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            must_change_password INTEGER NOT NULL DEFAULT 0,
            failed_login_attempts INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL,
            last_login_at TEXT NULL,
            password_changed_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            successful INTEGER NOT NULL DEFAULT 0,
            user_agent TEXT NULL,
            attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            selector TEXT NOT NULL UNIQUE,
            validator_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            last_used_at TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            module TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE role_permissions (
            role_id INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, permission_id)
        )',
        'CREATE TABLE activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER NULL,
            event TEXT NOT NULL,
            subject_type TEXT NULL,
            subject_id INTEGER NULL,
            description TEXT NULL,
            properties TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
    ];

    foreach ($statements as $statement) {
        $database->pdo()->exec($statement);
    }
}

/**
 * Insert a user with a low-cost bcrypt hash (fast tests, same algorithm).
 *
 * @param array<string, mixed> $overrides email, first_name, last_name, password,
 *                                        password_hash, role_code, is_active, username
 */
function auth_create_user(Database $database, array $overrides = []): int
{
    $roleCode = strtolower((string) ($overrides['role_code'] ?? 'faculty'));
    $role = $database->selectOne('SELECT id FROM roles WHERE code = :code', ['code' => $roleCode]);

    // Same display names the real seeder will use (Phase 19).
    $roleNames = [
        'administrator' => 'System Administrator',
        'dean' => 'Dean',
        'secretary' => 'Department Secretary',
        'faculty' => 'Faculty',
    ];

    $roleId = $role !== null
        ? (int) $role['id']
        : $database->insert('roles', [
            'code' => $roleCode,
            'name' => $roleNames[$roleCode] ?? ucfirst($roleCode),
            'sort_order' => 10,
            'is_system' => 1,
        ]);

    $email = strtolower((string) ($overrides['email'] ?? 'faculty.demo@sac.edu.ph'));
    $password = (string) ($overrides['password'] ?? 'SyncClass2026');

    return $database->insert('users', [
        'role_id' => $roleId,
        'department_id' => isset($overrides['department_id']) ? (int) $overrides['department_id'] : null,
        'email' => $email,
        'username' => (string) ($overrides['username'] ?? (strstr($email, '@', true) ?: 'user')),
        'password_hash' => (string) ($overrides['password_hash'] ?? password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])),
        'first_name' => (string) ($overrides['first_name'] ?? 'Demo'),
        'middle_name' => null,
        'last_name' => (string) ($overrides['last_name'] ?? 'User'),
        'employee_no' => null,
        'is_active' => (int) ($overrides['is_active'] ?? 1),
        'must_change_password' => 0,
        'failed_login_attempts' => 0,
        'locked_until' => null,
        'last_login_at' => null,
        'password_changed_at' => null,
    ]);
}

/**
 * Extract a cookie value from a Set-Cookie header.
 */
function auth_cookie_value(string $setCookieHeader, string $name): ?string
{
    if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '=([^;]*)/', $setCookieHeader, $matches) !== 1) {
        return null;
    }

    return $matches[1] === '' ? null : $matches[1];
}
