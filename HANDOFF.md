# SyncClass — Handoff Document

## 1. Project Overview

**SyncClass** is a production-style academic classroom & subject scheduling platform for colleges/universities. Primary goal: avoid scheduling conflicts and maximize classroom utilization. It should feel like a hybrid of Google Calendar + Notion + Trello + classroom management software — not a generic CRUD app.

- **Stack:** Native PHP 8.2 (no frameworks), MySQL 8 (`anth04_SyncClass`), Apache/XAMPP, Bootstrap 5, vanilla JS, FullCalendar, Chart.js, SortableJS. Composer only for autoloading; optional PHPMailer/DomPDF/PHPSpreadsheet/Carbon.
- **Architecture:** Practical native MVC with Service + Repository layers, DDD-inspired separation, dependency injection where appropriate. PSR-4 `App\` -> `app/`. Routes in `routes/web.php`, kernel/middleware in `app/Core`, controllers in `app/Controllers`, views in `app/Views`.
- **Auth/RBAC:** Session-based login (`username@sac.edu.ph`), remember-me, CSRF. 4 roles (administrator, dean, secretary, faculty), 48 permission codes, deny-by-default, scope enforced in SQL. Audit log for mutations.
- **App path:** `D:\xampp8.2\htdocs\SyncClass` (served), worktree at `C:\Users\ICTO\.freebuff\worktrees\...`. Local URL `http://localhost/syncclass/`.
- **Demo creds:** password `SyncClass2026!` for `admin@sac.edu.ph`, `dean.csm@sac.edu.ph`, `secretary.csm@sac.edu.ph`, `jcruz@sac.edu.ph`.

## 2. Completed Work

Phases 1–19 complete and verified by test suites; v1.1.0 plus dashboard rebuild (Phase 16) and UI polish (Phase 17).

Key features built:
- **Auth & security:** login/logout/throttle/remember-me, CSRF, XSS/SQLi prevention, session hardening (`app/Services/AuthService`, `RememberMeService`, middleware).
- **RBAC & scope:** permission catalogue + role matrix, `Scope` service, scoped repositories (`config/permissions.php`, `bin/sync-rbac.php`).
- **Master data:** departments, buildings, rooms, subjects, sections, faculty (+ availability), academic terms with archive/restore and invariants.
- **Scheduling engine:** one row per meeting, `ScheduleService` as sole write path, lifecycle `draft→pending→approved→published`, policy from `config/schedule.php`, audit logging.
- **Conflict detection:** `ConflictService` (teacher/room/section/time overlap, over-capacity, ≥95% fill) computed live; alternatives suggested.
- **Calendar:** FullCalendar feed with recurring weekly occurrences; day/week/month.
- **AI timetable generator:** deterministic constraint-based generator (`app/Services/Generator`).
- **Notifications:** in-app + email dispatcher (`bin/dispatch-notifications.php`).
- **Reports:** 6 reports (faculty load, room utilization, department schedule, conflicts, timetable) print-friendly.
- **Dashboard:** role-aware command centre — stat cards, weekly timetable grid, conflict watch, utilization gauge + building bars, Chart.js panel, today agenda, 4-column planning board (`app/Services/DashboardService`, `app/Repositories/AnalyticsRepository`, `app/Views/dashboard/index.php`).
- **UI polish:** accessibility (skip link, landmarks, aria-current, mobile nav), focus rings, responsive breakpoints, reduced-motion, print rules.
- **Seed data & export:** `bin/seed-demo.php`, `database/schema/syncclass_complete.sql` (30 tables, 433 rows), `bin/export-sql.php`.

Recently modified files (last commits `9084eba`, `15febd0`): dashboard view/service/repository, CSS media-query fix + board selector alignment, `AccountController` + password view, nav/layout/permissions/subjects/schedules, `Response.php`. Uncommitted: `AGENTS.md` status update.

## 3. Current State

**Working:** All core features; dashboard renders over HTTP (login redirect 302 → sign-in). PHP lint passes; `tests/dashboard_test.php` passes (70/70). Braces balanced in CSS.

**Broken / partially implemented:**
- `tests/ui_polish_test.php` fails: rendered href `/syncclass/` vs expected `/SyncClass/` or `/` due to `APP_URL` casing mismatch with environment (pre-existing, unrelated to dashboard).
- Phase 20 (deployment packaging) partially complete — INSTALL.md and export exist but final packaging/deployment notes in progress.
- RPT-06 PDF/Excel export not implemented (reports print-friendly only; DomPDF/PHPSpreadsheet not installed).
- Failed schedule/master-data validation renders the 422 page instead of re-rendering the form with submitted values.
- Email delivery requires SMTP creds in `.env`; otherwise in-app only.
- `tests/schedule_test.php` has 29 pre-existing assertions failing (old pre-409 HTTP contract); behavior covered by HTTP smoke suite.
- No recurrence-group column: co-created weekly rows are independent once saved.

## 4. Immediate Next Steps

1. Complete Phase 20 deployment packaging: finalize `INSTALL.md`/`DEPLOYMENT.md`, verify `bin/export-sql.php` rebuild, document update workflow.
2. Implement RPT-06 PDF/Excel export — install DomPDF + PHPSpreadsheet (or document as optional) and add download endpoints to `ReportsController`.
3. Fix form repopulation on validation failure (schedule + master-data forms) so 422 responses re-render with submitted values and field errors.

## 5. Conventions & Rules

- **No frameworks** — native PHP only; Composer for autoloading/deps. Shared-hosting/XAMPP compatible.
- **MVC + Service + Repository**; controllers thin; business logic in services; data access in repositories with scope in SQL.
- **PSR-12**, namespaces, DI where practical, reusable services/repos, highly commented, avoid duplication, SOLID where reasonable.
- **Security:** prepared statements always; `password_hash`/`password_verify`; CSRF on all non-GET; output escaping; session regeneration; server + client validation; no DB exceptions to users.
- **RBAC:** deny by default; no wildcard/`isAdmin()` bypass; role = ceiling, scope = filter; refusals audited (`authz.denied`).
- **Scope:** every non-admin query filtered by `App\Services\Scope` in SQL; out-of-scope ids return 404 (not 403); writes double-check `department_id`.
- **Archive, never delete** (MD-07); mutations audited with actor/IP/affected id.
- **Naming:** controllers `*Controller.php`, services `*Service.php`, repos `*Repository.php`, views under `app/Views/<module>/`. Permission codes `module.action`. DB tables snake_case, columns snake_case.
- **Routes:** `web.php` with middleware arrays `['auth', 'permission:...']`; global `StartSession` + `VerifyCsrfToken`.
- **Config:** `config/local.php` (git-ignored) overrides; `.env` for secrets; never commit `.env`.
- **Testing:** run `php bin/lint.php`, then `php tests/<suite>.php`; dashboard tests must stay green. Do not weaken assertions.
- **Git:** branch `freebuff/project-purpose-and-framework-requirements`; commit only when asked; push only when authorized.
