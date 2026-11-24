# SyncClass
SyncClass: Avoid scheduling conflicts and maximize classroom utilization for modern classrooms

**Release v1.0.0** · PHP 8.1+ · MySQL 8 / MariaDB · Apache / XAMPP

## Install it

XAMPP on Windows is the reference target (`D:\xampp8.2\htdocs\SyncClass`,
`http://localhost/syncclass/`, database `anth04_SyncClass`). The short version:

```bash
composer install --no-dev
copy .env.example .env
# create database anth04_SyncClass (utf8mb4), then:
mysql -u root anth04_SyncClass < database/schema/syncclass_complete.sql
```

`database/schema/syncclass_complete.sql` is the whole installation: 30 tables
with indexes and foreign keys, the permission catalogue and role matrix, the
initial settings, four demo roles and accounts, and the demo timetable. No
further seeding is required.

Full walkthrough, update workflow and troubleshooting: **[INSTALL.md](INSTALL.md)**.
Release history: **[CHANGELOG.md](CHANGELOG.md)**.

Demo sign-ins (password `SyncClass2026!`): `admin@sac.edu.ph`,
`dean.csm@sac.edu.ph`, `secretary.csm@sac.edu.ph`, `jcruz@sac.edu.ph`.

## Documentation
- [Requirements Analysis](docs/REQUIREMENTS.md) — Phase 1: scope, roles, functional/non-functional requirements, business rules
- [System Architecture](docs/ARCHITECTURE.md) — Phase 2: layers, modules, request lifecycle, conflict detection, generator, security, ADRs, roadmap
- [Database Design](docs/DATABASE.md) — Phase 3: data dictionary, index strategy, business-rule enforcement, migration workflow
- [Security Architecture](docs/SECURITY.md) — Phase 5: threat model, OWASP mapping, password/session/CSRF/validation primitives, hardening checklist
- [Entity-Relationship Diagram](database/schema/ERD.md) — 30 tables, cardinalities, referential actions

## Stack
Native PHP 8.1+ (no framework) · MySQL 8 (`anth04_SyncClass`) · Bootstrap 5 · vanilla JS · FullCalendar · Chart.js · Apache / XAMPP

## Database
Nine numbered migrations in `database/migrations/` are the source of truth;
`database/schema/SyncClass.sql` is generated from them with
`php database/schema/build.php`. The **release** artifact is
`database/schema/syncclass_complete.sql`, which layers the RBAC catalogue, the
settings seeders and the demo data set on top of that schema:

```bash
php database/schema/build.php   # schema only (after changing migrations)
php bin/sync-rbac.php           # permissions + role matrix
php bin/seed-demo.php           # demo departments, faculty, catalogue, timetable
php bin/export-sql.php          # rebuild syncclass_complete.sql from all of the above
```

Import the complete file into an empty database for a fresh install:

```bash
mysql -u root anth04_SyncClass < database/schema/syncclass_complete.sql
```

`php bin/export-sql.php` also accepts `--schema` to emit schema + seeders only
(no demo data).

## Local setup
```bash
composer install                        # PSR-4 App\ -> app/
php database/schema/build.php           # only after changing migrations
php bin/lint.php                        # syntax-check every PHP file
php tests/security_primitives_test.php  # Phase 5 security primitives
php tests/core_framework_test.php       # Phase 6 kernel, router, middleware, DB wrapper
php tests/auth_test.php                 # Phase 7 login, throttle, remember-me, logout
php tests/rbac_test.php                 # Phase 8 permissions, scope, guarded routes
php tests/master_data_test.php          # Phase 9 master-data CRUD, scope, invariants
php tests/calendar_test.php             # calendar feed, recurring occurrences, conflicts
php tests/schedule_test.php             # Phase 10 meetings, validation, lifecycle, scope
composer run test:schedule              # same file through Composer
php -S 127.0.0.1:8000 -t public         # http://127.0.0.1:8000/ and /health
```

Copy `config/local.example.php` to `config/local.php` for machine-specific settings (git-ignored). On XAMPP, place the project at `D:\xampp8.2\htdocs\SyncClass` and open `http://localhost/syncclass/`. Point the virtual host at `public/` when possible; the root `index.php` + `.htaccess` pair is the fallback for hosts that cannot.

## Routes (Phase 8–10)
| Method | Path | Middleware | Purpose |
|---|---|---|---|
| GET | `/admin/users` | global + `auth` + `permission:users.view` | People directory, filtered by the signed-in user's scope |
| GET/POST | `/master/departments`, `/master/departments/{id}/edit`, `/master/departments/{id}/toggle` | `auth` + `departments.view/create/update/delete` | Departments |
| GET/POST | `/master/buildings`, `/master/rooms` (same shape) | `auth` + `rooms.view/create/update/delete` | Buildings and rooms |
| GET/POST | `/master/subjects`, `/master/sections` (same shape) | `auth` + `subjects.*` / `sections.*` | Catalogue |
| GET/POST | `/master/faculty`, `/master/faculty/{id}/availability` | `auth` + `faculty.*` / `availability.update` | Faculty profiles and availability |
| GET/POST | `/master/years`, `/master/semesters` (+ `/{id}/current`) | `auth` + `terms.view/manage` | Academic terms |
| GET | `/schedules` | `auth` + `permission:schedules.view` | Meeting list, filtered by scope, semester, day, status and search |
| GET/POST | `/schedules/create`, `/schedules` | `auth` + `permission:schedules.create` | Add-class form and its single validated save path |
| GET | `/schedules/{id}` | `auth` + `permission:schedules.view` | Meeting detail plus the lifecycle actions available to the viewer |
| GET/POST | `/schedules/{id}/edit`, `/schedules/{id}` | `auth` + `permission:schedules.update` | Edit one meeting |
| POST | `/schedules/{id}/status` | `auth` + `permission:schedules.update,schedules.approve,schedules.delete` | One lifecycle move; the service checks the permission the target needs |
| GET | `/login` | global + `guest` | Sign-in form; signed-in users are redirected to `/` |
| POST | `/login` | global | Credential check, throttle (429) and remember-me cookie |
| POST | `/logout` | global + `auth` | Destroys the session and clears the remember-me cookie |
| GET | `/` | global + `auth` | Role-aware dashboard (DASH-01…03): today, seven-day agenda, workload, utilization, conflicts and charts |
| GET | `/api/dashboard` | global + `auth` | Scoped Chart.js series for the dashboard; `no-store` JSON envelope (DASH-03) |
| GET | `/health` | global | JSON environment check; 503 when a required extension is missing |
| POST | `/pipeline/echo` | global | Validated JSON probe; 419 without a CSRF token |
| GET | `/pipeline/guarded` | global + `auth` | 401 JSON for API clients, 302 to `/login` for browsers |

`StartSession` and `VerifyCsrfToken` are **global** middleware: every request starts
the hardened session, and every non-GET request is CSRF-checked without the route
having to opt in. Only authorisation is declared per route (`auth`, `guest`).

## First account
Accounts are created from the command line — the password is prompted for, never
passed as an argument, and validated against `config/security.php`:

```bash
php bin/create-user.php admin@sac.edu.ph Ana Reyes --role=administrator
```

Roles are created on demand (`administrator`, `dean`, `secretary`, `faculty`);
Phase 19 seeds the full demo data set.

Then write the permission catalogue and role matrix to the database:

```bash
php bin/sync-rbac.php
```

## Roles and permissions (Phase 8)
`config/permissions.php` is the single source of truth: 48 capability codes grouped
by module, and four roles with explicit grant lists. `php bin/sync-rbac.php` copies
them into `permissions` / `role_permissions`; it is idempotent and safe to re-run
after editing the file.

| Role | Sees | Can |
|---|---|---|
| System Administrator | everything | every catalogued permission |
| Dean | own department | read + approve timetables, manage own-department subjects/sections/faculty |
| Department Secretary | own department | build and edit the timetable, file/update requests |
| Faculty | own records | read their timetable, maintain availability, file requests |

Three rules make the model predictable:

- **Deny by default.** A permission code that is not in the catalogue is refused to
every role, including the administrator. There is no wildcard and no `isAdmin()`
bypass, so a typo in a route locks the page instead of opening it.
- **Role is a ceiling, scope is a filter.** Every non-administrator query goes through
`App\Services\Scope`, and repositories add the `WHERE` clause themselves
(`UserRepository::paginateForScope()`). A dean's list is shorter in SQL, not in the
template.
- **Refusals are audited.** `Authorize` writes `authz.denied` with the route, the
required rules and the actor.

Route syntax: `['auth', 'permission:users.view']`, any-of with
`permission:schedules.create,schedules.update`, or `['auth', 'role:administrator']`.
Views hide what the user cannot operate with `$can('users.create')`, but that is
convenience only — every guarded route is authoritative.

## Master data (Phase 9)
Seven screens share one base controller and one repository contract:
**departments**, **buildings**, **rooms**, **subjects**, **sections**, **faculty**
(with availability windows) and **academic terms**.

What every screen guarantees:

- **Scope in SQL.** Subjects, sections and faculty profiles are filtered by
`App\Services\Scope` before any search or filter the client sends; a dean's list
contains their department and nothing else. Out-of-scope ids answer 404, not 403.
- **Writes double-check the department.** A posted `department_id` is validated
against the scope (`MasterDataService::assertDepartment()`), so a hidden field
cannot place a subject in another department.
- **Archive, never delete** (MD-07). Every list has an Archive/Restore action and
the department edit page shows the rows that reference it.
- **No database exception ever reaches the user.** Duplicate codes and duplicate
term numbers are 422 field errors; one current year and one current semester per
year are enforced in a transaction.
- **Every mutation is audited** (`master.subject.created`, `master.room.updated`, …)
with actor, IP and the affected id.

`php tests/master_data_test.php` exercises the repositories, the invariants and
the HTTP layer (CSRF, 403 vs 404, PRG, audit) against SQLite.

**Known limitation:** a validation failure renders the 422 page rather than
re-rendering the form with the submitted values; Phase 17 replaces the forms and
adds old-input repopulation.

## Scheduling (Phase 10)
One row in `schedules` is **one meeting** (SCH-04). A weekly class is created as one
row per selected weekday, so the week view, the conflict engine and the generator
all read the same shape; a one-off meeting derives its weekday from `meeting_date`.
There is no recurrence-group column yet, so co-created rows are independent once
saved — editing one does not move its siblings.

`App\Services\ScheduleService` is the **only** write path (SCH-02):

- **Relations are checked, not trusted.** Section, subject, faculty profile and
room must exist, be active, and agree on the department. The department of an
entry is derived from the section, never posted.
- **Scope is enforced twice.** The route gate decides who may build a timetable
(`schedules.create` / `schedules.update`); the service re-checks the actor's
department for the *section being written*, so a hidden field cannot cross
departments. Reads are scoped in SQL: faculty see only their own classes, deans
and secretaries only their department, and an out-of-scope id answers 404.
- **Policy comes from `config/schedule.php`:** start before end, 30–240 minutes,
07:00–19:00, and no overlap with the lunch break. Classes may end exactly when a
break starts; adjacency is allowed.
- **Lifecycle is a graph:** `draft → pending → approved → published`, with
`rejected → draft` and `cancelled` terminal. Approval/publication need
`schedules.approve`, cancellation needs `schedules.delete`. Editing an approved or
published entry requires an approver and reopens it as `pending` rather than
silently changing a published timetable.
- **Every mutation is audited:** `schedule.created` (with all created ids),
`schedule.updated`, `schedule.status_changed` (from → to).

Deliberately **not** in this phase: overlap detection. `ScheduleRepository::activeInSemester()`
and the covering indexes from migration 006 exist for Phase 11's `ConflictService`,
which will run inside `create()`/`update()` before the insert.

**Known limitation:** a failed schedule validation (422) renders the error page and
not the form with the submitted values; this is the same Phase 17 item noted above.

## Project structure
```
app/            Core, Controllers, Models, Repositories, Services, Middleware, Helpers, Domain, Views
config/         app.php, database.php, mail.php, schedule.php, security.php, local.example.php
database/       migrations/ (source of truth), schema/ (generated install SQL, ERD), seeders/
public/         index.php front controller, .htaccess, assets/{css,js,images,uploads}
routes/         web.php, api.php (Phase 6+)
storage/        logs, cache, exports, sessions (not web-accessible)
tests/          rule and smoke tests (Phase 18)
bin/            CLI scripts (lint, notification dispatcher, installer)
docs/           REQUIREMENTS, ARCHITECTURE, DATABASE, API, SECURITY, DEPLOYMENT, …
```
