# SyncClass
SyncClass: Avoid scheduling conflicts and maximize classroom utilization for modern classrooms

## Documentation
- [Requirements Analysis](docs/REQUIREMENTS.md) — Phase 1: scope, roles, functional/non-functional requirements, business rules
- [System Architecture](docs/ARCHITECTURE.md) — Phase 2: layers, modules, request lifecycle, conflict detection, generator, security, ADRs, roadmap
- [Database Design](docs/DATABASE.md) — Phase 3: data dictionary, index strategy, business-rule enforcement, migration workflow
- [Security Architecture](docs/SECURITY.md) — Phase 5: threat model, OWASP mapping, password/session/CSRF/validation primitives, hardening checklist
- [Entity-Relationship Diagram](database/schema/ERD.md) — 30 tables, cardinalities, referential actions

## Stack
Native PHP 8.1+ (no framework) · MySQL 8 (`anth04_SyncClass`) · Bootstrap 5 · vanilla JS · FullCalendar · Chart.js · Apache / XAMPP

## Database
Nine numbered migrations in `database/migrations/` are the source of truth; `database/schema/SyncClass.sql` is generated from them with `php database/schema/build.php`.

```bash
mysql -u root < database/schema/SyncClass.sql   # creates anth04_SyncClass and all 30 tables
```

## Local setup
```bash
composer install                        # PSR-4 App\ -> app/
php database/schema/build.php           # only after changing migrations
php bin/lint.php                        # syntax-check every PHP file
php tests/security_primitives_test.php  # Phase 5 security primitives
php tests/core_framework_test.php       # Phase 6 kernel, router, middleware, DB wrapper
php tests/auth_test.php                 # Phase 7 login, throttle, remember-me, logout
php -S 127.0.0.1:8000 -t public         # http://127.0.0.1:8000/ and /health
```

Copy `config/local.example.php` to `config/local.php` for machine-specific settings (git-ignored). On XAMPP, place the project at `D:\xampp8.2\htdocs\SyncClass` and open `http://localhost/syncclass/`. Point the virtual host at `public/` when possible; the root `index.php` + `.htaccess` pair is the fallback for hosts that cannot.

## Routes (Phase 7)
| Method | Path | Middleware | Purpose |
|---|---|---|---|
| GET | `/login` | global + `guest` | Sign-in form; signed-in users are redirected to `/` |
| POST | `/login` | global | Credential check, throttle (429) and remember-me cookie |
| POST | `/logout` | global + `auth` | Destroys the session and clears the remember-me cookie |
| GET | `/` | global + `auth` | Scheduler overview (becomes the Phase 16 dashboard) |
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
