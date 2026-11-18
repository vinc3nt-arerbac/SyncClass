# SyncClass
SyncClass: Avoid scheduling conflicts and maximize classroom utilization for modern classrooms

## Documentation
- [Requirements Analysis](docs/REQUIREMENTS.md) — Phase 1: scope, roles, functional/non-functional requirements, business rules
- [System Architecture](docs/ARCHITECTURE.md) — Phase 2: layers, modules, request lifecycle, conflict detection, generator, security, ADRs, roadmap
- [Database Design](docs/DATABASE.md) — Phase 3: data dictionary, index strategy, business-rule enforcement, migration workflow
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
php -S 127.0.0.1:8000 -t public         # http://127.0.0.1:8000/ and /health
```

Copy `config/local.example.php` to `config/local.php` for machine-specific settings (git-ignored). On XAMPP, place the project at `D:\xampp8.2\htdocs\SyncClass` and open `http://localhost/syncclass/`. Point the virtual host at `public/` when possible; the root `index.php` + `.htaccess` pair is the fallback for hosts that cannot.

## Project structure
```
app/            Core, Controllers, Models, Repositories, Services, Middleware, Helpers, Domain, Views
config/         app.php, database.php, mail.php, schedule.php, local.example.php
database/       migrations/ (source of truth), schema/ (generated install SQL, ERD), seeders/
public/         index.php front controller, .htaccess, assets/{css,js,images,uploads}
routes/         web.php, api.php (Phase 6+)
storage/        logs, cache, exports, sessions (not web-accessible)
tests/          rule and smoke tests (Phase 18)
bin/            CLI scripts (lint, notification dispatcher, installer)
docs/           REQUIREMENTS, ARCHITECTURE, DATABASE, API, SECURITY, DEPLOYMENT, …
```
