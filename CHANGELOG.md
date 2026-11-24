# Changelog

All notable changes to SyncClass are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Phase 16 replaces the Phase 6 pipeline overview with the role-aware dashboard.

### Added
- `App\Repositories\AnalyticsRepository` — scoped dashboard aggregates: active
  teacher/subject/room/section counts, meetings per department, today's
  meetings (weekly plus one-off), a seven-day window and conflict counts. The
  queries avoid MySQL-only functions so the dashboard runs on the SQLite test
  mirror without shims.
- `App\Services\DashboardService` — resolves the actor's audience
  (institution / department / faculty), builds the widget row, expands today
  and the next seven days from the recurring timetable, reuses
  `ReportService` for workload and utilization so the numbers match the
  reports, and returns Chart.js series from `charts()`.
- `App\Controllers\DashboardController` — `GET /` (landing page for every
  authenticated role) and `GET /api/dashboard` (`{ ok, data }`, `no-store`).
- `app/Views/dashboard/index.php` — widget row, today's classes, seven-day
  agenda, room-utilization meters, faculty workload, conflict counts,
  department statistics and four chart canvases with an empty-term state.
- `public/assets/js/vendor/chart.umd.min.js` — Chart.js 4.4.1 UMD build,
  vendored locally because the CSP only permits `script-src 'self'`.
- `public/assets/js/dashboard.js` — fetches the scoped series, renders the four
  charts and degrades to a readable message if the library is unavailable. No
  dataset is written into the view (DASH-03).
- `public/assets/css/app.css` — dashboard tokens: stat cards, session lists,
  utilization meters, conflict counts and chart cards.
- `tests/dashboard_test.php` — 70 checks: audience scope isolation, the pinned
  Monday agenda (including the one-off), utilization/workload/conflict reuse,
  chart-series shape, empty term, term fallback and HTTP rendering for all
  roles including the anonymous redirect.

### Changed
- `GET /` now renders the dashboard; `HomeController` and
  `app/Views/home/index.php` (the Phase 6 pipeline demo) were removed.
- `tests/core_framework_test.php`, `tests/auth_test.php` and
  `tests/rbac_test.php` now build the master-data and schedule mirrors for the
  kernel database, because the landing page reads the timetable; their old
  "route table / permissions list" assertions were replaced with dashboard
  assertions.

### UI polish (Phase 17, NFR-08/NFR-09)
- Shell: a skip link, a focusable `<main>` landmark, `aria-current="page"` on
  the active section (the kernel now shares the route name with the view), a
  mobile navigation toggle with `aria-expanded`, document theme colour and a
  light colour-scheme declaration.
- `public/assets/js/app.js` — dependency-free shell behaviour: the mobile nav
  and a focus-managed modal for the Bootstrap markup the notifications page
  uses (Bootstrap's JavaScript was never loaded, so that modal did nothing).
- `public/assets/css/app.css` — component layer for the Bootstrap names the
  views already used (`.btn-*`, `.form-select`, `.form-floating`, `.list-group`,
  `.modal`, `.badge`, `.alert-*`, `.metric*`, layout utilities), a global
  `:focus-visible` ring, 900 px navigation and 640 px layout breakpoints,
  reduced-motion rules and print hiding for the new chrome.
- `tests/ui_polish_test.php` — 28 structural checks for landmarks, assets and
  the stylesheet contract.

### Fixed
- `ScheduleRepository::currentSemesterId()` read `['id']` from `null` when no
  term was marked current (fresh install, empty dashboard), which emitted a
  warning before the session started and broke the response headers.

## [1.1.0] — 2026-11-24

Adds the reporting module (RPT-01…RPT-05): five read-only reports and a
print-friendly timetable, each scoped to the signed-in user's role.

### Added
- `App\Repositories\ReportRepository` — one scoped read model per report
  (faculty load, room utilization, department schedule, conflict log,
  timetable/semester board, hub summary). Scope is applied in SQL: the
  administrator sees the institution, a dean or secretary their department,
  faculty only their own meetings. Cancelled meetings are excluded.
- `App\Services\ReportService` — term context (requested term when visible,
  otherwise the current one), derived numbers and bands (over/under-loaded,
  low/high utilization, 60 %/100 % and 40 %/75 % thresholds), and the weekly
  grid. Room utilization expands weekly meetings across the term's teaching
  weeks and counts one-offs once, so the percentage is comparable between
  terms of different lengths.
- `App\Controllers\ReportsController` — six GET actions with `?semester=`,
  `?faculty=`, `?department=` and `?section=` filters; filters only narrow the
  scope and never widen it.
- Report views under `app/Views/reports/` — hub, faculty load, room
  utilization (per room and per building), department schedule, conflict
  report and weekly/semester timetable; plus shared sub-navigation and
  print stylesheet rules (`@media print`, `.band-*`, `.timetable`, `.slot`).
- Routes `/reports`, `/reports/faculty-load`, `/reports/rooms`,
  `/reports/departments`, `/reports/conflicts` and `/reports/timetable`,
  gated by `reports.view`; Reports and Calendar links added to the main nav.
- `tests/reports_test.php` — 98 checks: scope isolation across all four roles,
  exact room-utilization math against a known four-week term, the empty-term
  case, unknown-term fallback, cancelled-meeting handling and HTTP rendering.

### Known limitations
- RPT-06 PDF/Excel export is not implemented: the reports are print-friendly
  (`window.print()` / `@media print`) but there are no DomPDF or PHPSpreadsheet
  downloads, since neither library is installed.

## [1.0.0] — 2026-11-23

First installable release: authentication, master data, scheduling with
conflict detection, the draft timetable generator, calendar and in-app
notifications, plus a complete fresh-install SQL export.

### Added
- `database/schema/syncclass_complete.sql` — schema, indexes, foreign keys,
  RBAC catalogue, settings and the full demo data set in one importable file
  (29 tables, 433 rows), generated by `bin/export-sql.php`.
- `bin/export-sql.php` — rebuilds the export from the generated schema,
  `database/seeders/*.sql` and the live database, with portable multi-row
  `INSERT`s and generated columns skipped.
- `bin/seed-demo.php` — idempotent demo data: 4 roles, 5 departments,
  20 faculty with accounts, 50 subjects, 4 buildings, 30 rooms, 15 sections,
  4 semesters and 100 conflict-free meetings.
- `database/seeders/001_initial_settings.sql` — the 20 initial settings rows.
- `.env` support in `bootstrap.php` and a documented `.env.example`.
- `INSTALL.md` — step-by-step XAMPP install and the Git update workflow.
- `App\Repositories\SemesterRepository` and `AcademicYearRepository`.
- `App\Core\Controller::actor()/authorize()/csrfMeta()` and `Kernel::current()`
  so controllers share one actor/CSRF contract.

### Fixed
- `ConflictService` compared `schedules.faculty_id` (a `faculty_profiles` id)
  with a user id, so two classes for the same teacher were never reported;
  both sides now use the profile id. Room alternatives no longer crash on the
  missing `meetingConflictsInSlot()` helper, which is now implemented.
- The cross-department rule was reported for every clash in the term; it now
  requires the same room, matching the business rule.
- `CalendarController`, `GeneratorController`, `NotificationController` and
  `routes/web.php` were missing imports/namespaces and returned 500s.
- `ScheduleService::resolve()` returned nested rows that `ConflictService`
  could not read; it now returns the flat keys both callers expect, and is
  public so the conflict-preview endpoint evaluates a candidate exactly as the
  save path does.
- Notification stack (`NotificationService`, `NotificationRepository`,
  `Mailer`, `bin/dispatch-notifications.php`) wrong namespace, missing DB
  handle, missing `countForUser()`/`delete()`/`markReadAll()`, broken LIMIT/
  OFFSET binding and recipient lookup.
- `ScheduleRepository::calendarFeed()` returned one row per weekly meeting
  instead of one per occurrence inside the term, and crashed on an empty term.
- Master-data pager built a null `$page_url`; schedule form interpolated PHP
  into JavaScript.
- `semesters.current_academic_year_id` (MySQL-only generated column over an FK)
  was removed from the schema and migration 004; BR-14 is enforced in
  `TermRepository` instead, which lets XAMPP's MariaDB import the schema.
- Test fixtures: `tests/calendar_test.php` never created its schema or defined
  `check()`, and `tests/schedule_test.php` mixed user ids with faculty profile
  ids in its clash fixtures.

### Verified
- Fresh import of `syncclass_complete.sql` into an empty database: 30 tables,
  31 users, 100 meetings, 20 settings, no orphan rows.
- `bin/seed-demo.php` re-run writes nothing (idempotent).
- HTTP smoke suite: 31/31 checks (health, auth + CSRF, 15 pages, calendar feed,
  conflict rejection, create + cleanup, generator draft, faculty scoping,
  logout).
- PHP suites: security primitives (66), core framework (93), auth (72),
  RBAC (102) and master data (87) pass with 0 failures.

### Known limitations
- The dashboard at `/` is still the Phase 6 overview shell; the reporting
  module (`/reports`) is not implemented yet and answers 404.
- A failed schedule/master-data validation renders the 422 page instead of
  re-rendering the form with the submitted values.
- `tests/schedule_test.php` still carries Phase 10/11 fixtures that assert the
  pre-409 HTTP contract; 29 of its assertions fail for that reason. The
  behaviour itself is covered by the HTTP smoke suite.
- Email requires SMTP credentials in `.env`; without them notifications are
  in-app only.
