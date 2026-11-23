# SyncClass — System Architecture

**Document:** `docs/ARCHITECTURE.md`
**Phase:** 2 of 20 — Architecture Design
**Version:** 1.0
**Status:** Approved baseline for implementation
**Inputs:** `docs/REQUIREMENTS.md` (requirement IDs referenced throughout)

---

## 1. Architectural goals

1. **Native PHP with framework discipline.** No framework dependency, but the same clarity a framework would provide: one entry point, explicit routing, a DI container, middleware, repositories, services.
2. **One write path.** Every schedule mutation — form, calendar drag, bulk builder, generator commit, change-request approval — funnels through the same service method so conflict rules (BR-01…BR-14) cannot be bypassed.
3. **Scope-safe by construction.** Department filtering is enforced in the repository layer, not in views.
4. **Boring where it matters.** SQL in repositories, business rules in services, HTTP in controllers, markup in views. No event sourcing, no CQRS, no microservices, no hexagonal ceremony.
5. **Hostable anywhere.** Runs from `public/` (preferred) or from the project root with a fallback bootstrap, on XAMPP or shared hosting.

---

## 2. Architectural style

A **layered MVC with a service/repository split and a thin application kernel**:

```
┌──────────────────────────────────────────────────────────────────┐
│ Presentation      Bootstrap 5 views, vanilla JS modules,          │
│                   FullCalendar, Chart.js, SortableJS              │
├──────────────────────────────────────────────────────────────────┤
│ HTTP              Front controller → Router → Middleware pipeline │
│                   → Controller (thin: validate, call, respond)    │
├──────────────────────────────────────────────────────────────────┤
│ Application       Services: transactions, business rules,         │
│                   orchestration, conflict detection, generation   │
├──────────────────────────────────────────────────────────────────┤
│ Domain            Entities + value objects (TimeRange, Term,      │
│                   DayOfWeek), policy objects (rules), invariants  │
├──────────────────────────────────────────────────────────────────┤
│ Persistence       Repositories (all SQL lives here) → PDO/MySQL   │
├──────────────────────────────────────────────────────────────────┤
│ Infrastructure    Config, Container, Logger, Mailer, PDF/Excel,   │
│                   Session, Cache, Storage                         │
└──────────────────────────────────────────────────────────────────┘
```

**Dependency rule:** dependencies point downward only. Controllers never build SQL; repositories never contain business rules; views never query the database; services never read `$_POST` or echo output.

---

## 3. Target directory layout

Consistent with the project brief; `public/` is the web root.

```
SyncClass/
├── app/
│   ├── Core/            Kernel, Router, Request, Response, Controller,
│   │                    View, Container, Config, Database, Security,
│   │                    Session, Model base
│   ├── Controllers/     Auth, Dashboard, Schedule, Calendar, Api/*, Reports, Admin/*
│   ├── Models/          Thin entity objects (User, Schedule, Room, …)
│   ├── Repositories/    UserRepository, ScheduleRepository, RoomRepository, …
│   ├── Services/        ScheduleService, ConflictService, Generator/
│   │                    TimetableGenerator, NotificationService, ReportService
│   ├── Middleware/      AuthMiddleware, RoleMiddleware, CsrfMiddleware,
│   │                    ThrottleMiddleware, ScopeMiddleware
│   ├── Helpers/         Security, Validator, Format, Str, Flash, Url
│   └── Views/           layouts/, auth/, dashboard/, schedules/, calendar/,
│                        admin/, reports/, partials/
├── config/              app.php, database.php, mail.php, schedule.php (policy
│                        defaults: allowed hours, breaks, load caps)
├── database/
│   ├── migrations/      numbered, idempotent, up/down
│   ├── seeders/         DemoSeeder, RoleSeeder, ReferenceSeeder
│   └── schema/          SyncClass.sql (single-file install), ERD.md
├── public/              index.php (front controller), .htaccess,
│                        assets/{css,js,images,uploads}
├── routes/              web.php, api.php
├── storage/             logs/, cache/, exports/, sessions/  (not web-accessible)
├── tests/               smoke + rule tests (Phase 18)
├── vendor/              Composer libraries only
├── docs/                this documentation set
├── bootstrap.php        autoload, config, container wiring
└── composer.json        PSR-4: "App\\": "app/"
```

**Root-install fallback:** if the host cannot point the document root at `public/`, a root `index.php` boots the same kernel and a root `.htaccess` denies direct access to `app/`, `config/`, `database/`, `storage/`, `vendor/`.

---

## 4. Request lifecycle

### 4.1 HTML request

```
GET /schedules?department=2&semester=5
  → public/index.php
  → bootstrap.php (Composer autoload, Config::load, Container bindings, error handler)
  → Kernel::handle(Request)
  → Router::match('GET', '/schedules')         → routes/web.php
  → Pipeline: StartSession → Csrf(verify for non-GET) → Authenticate
              → Authorize(role/permission) → Scope(resolve department)
  → ScheduleController@index(ScheduleQuery)
       ScheduleService::list($filters, $scope)
         ScheduleRepository::paginate($filters, $scope)   [SQL + JOINs]
  → View::render('schedules/index', $data)  inside layouts/app.php
  → Response (200, text/html)
```

### 4.2 Mutation request (PRG + JSON variants)

```
POST /schedules            (form, CSRF token)
  → validation (Validator) → 422 + old input + errors on failure
  → ScheduleService::create(array $data, User $actor)
       1. resolve/authorize scope
       2. ConflictService::check($candidate)  ── hard conflicts? → throw DomainException(conflicts, alternatives)
       3. DB transaction: insert schedule, insert audit row, queue notification
       4. commit
  → 302 redirect to /schedules/{id} with flash success   (or 409 JSON for AJAX)
```

**AJAX/API:** same controllers, same services; `Accept: application/json` selects JSON error/response formatting (`{ ok, data, errors, conflicts, alternatives }`). FullCalendar drag-drop is a `PATCH /api/schedules/{id}` call. Status codes: `200` ok, `201` created, `403` scope, `409` conflict, `422` validation, `429` throttled.

---

## 5. Core components (`app/Core`)

| Component | Responsibility |
|---|---|
| `Kernel` | Boots config/container, dispatches request through middleware pipeline, sends response, terminates (flush queue hooks). |
| `Router` | Exact + parameterised routes (`/schedules/{id}`), per-route middleware lists, named routes for URL generation. |
| `Request` | Immutable accessors for method, path, query, body, JSON payload, headers, files, session, authenticated user; `isAjax()`. |
| `Response` | Status, headers, body; `html()`, `json()`, `redirect()`, `download()`. |
| `Controller` | Base helpers: `view()`, `json()`, `validate()`, `authorize()`, `redirect()`, `back()`. |
| `View` | Plain-PHP templates with layout sections, `e()` escaping helper, partials, and view composers. No template engine dependency. |
| `Container` | Constructor-injection container with singletons for Database, Config, Logger, Mailer and repositories. |
| `Database` | PDO wrapper: connection from config, `transaction(callable)`, prepared `select/insert/update/delete`, `lastInsertId`, typed fetch helpers. |
| `Model` | Minimal data holder with `fill()`, `toArray()`, attribute casting. No active-record magic, no lazy relation loading. |
| `Config` | Dot-notation access to `config/*.php`; never reads `$_ENV` in business code. |
| `Logger` | PSR-3-style levels to `storage/logs/app-YYYY-MM-DD.log`; separate `audit` channel. |
| `Security` | Password hashing/verification, token generation, CSRF, output escaping, JSON embedding and response hardening headers. See `docs/SECURITY.md`. |
| `Session` | Hardened session lifecycle: cookie policy, strict mode, idle + absolute timeouts, ID regeneration, server-side storage outside the web root. |

**Explicitly rejected:** ORM/active record, template engine, annotation routing, event bus, service locator calls from views.

---

## 6. Module map

| # | Module | Requirements | Controllers | Services | Repositories |
|---|---|---|---|---|---|
| 1 | Authentication | AUTH-01…09 | `AuthController` | `AuthService` | `UserRepository`, `RememberTokenRepository` |
| 2 | RBAC | §4, SYS-02 | `RoleController`, `UserController` | `AuthorizationService` | `RoleRepository`, `PermissionRepository` |
| 3 | Master data | MD-01…08, BR-12 | `DepartmentController`, `RoomController`, `BuildingController`, `SubjectController`, `SectionController`, `FacultyController`, `TermController` | `MasterDataService` (generics + invariants) | matching repositories |
| 4 | Scheduling | SCH-01…07, BR-11 | `ScheduleController`, `ScheduleRequestController` | `ScheduleService`, `ChangeRequestService` | `ScheduleRepository`, `ChangeRequestRepository` |
| 5 | Conflict detection | CNF-01…11, BR-01…BR-11 | — (no HTTP surface) | `ConflictService`, `AlternativeService` | `ConflictRepository` |
| 6 | Generator | GEN-01…06 | `GeneratorController` | `TimetableGenerator`, `ConstraintEvaluator`, `RoomMatcher`, `FacultyMatcher`, `LoadBalancer`, `DraftSession` | read-only via existing repositories |
| 7 | Calendar | CAL-01…06 | `CalendarController`, `Api\CalendarController` | `CalendarService` | `ScheduleRepository` |
| 8 | Collaboration | COL-01…05 | `CommentController`, `TaskController`, `ActivityController` | `CollaborationService` | `CommentRepository`, `TaskRepository`, `ActivityRepository` |
| 9 | Notifications | NTF-01…04 | `NotificationController` | `NotificationService`, `MailDispatcher` | `NotificationRepository` |
| 10 | Search | SRCH-01…03 | `SearchController`, `Api\SearchController` | `SearchService` | `SearchRepository` (union queries) |
| 11 | Reports | RPT-01…07 | `ReportController`, `ExportController` | `ReportService`, `PdfExporter`, `ExcelExporter` | `ReportRepository` (read models) |
| 12 | Dashboard | DASH-01…03 | `DashboardController`, `Api\DashboardController` | `DashboardService` | `AnalyticsRepository` |
| 13 | Administration | SYS-01…04 | `SettingsController`, `AuditController`, `HealthController` | `SettingsService` | `SettingsRepository`, `AuditRepository` |

---

## 7. Domain model overview

```
Department 1─* User
Department 1─* Subject        Department 1─* Section
Building   1─* Room           Department 1─1 FacultyProfile (per user)
FacultyProfile 1─* FacultyAvailability
AcademicYear 1─* Semester
Semester 1─* Schedule
Department ─┐
Section ────┤
Subject ────┼──> Schedule  *─1 Room
FacultyProfile ┘            *─1 User (actor)
Schedule 1─* ScheduleConflict
Schedule 1─* ScheduleComment 1─* MentionedUser
Schedule 1─* ChangeRequest *─1 User
Schedule 1─* Task *─* User (assignments)
User 1─* Notification
```

**Aggregate boundaries (where transactions end):**

- `Schedule` — schedule row + its conflict log entries + audit + notifications, written in one transaction.
- `ChangeRequest` — request + applied schedule diff + audit, applied atomically on approval (BR-11).
- `TimetableDraft` — ephemeral, not persisted; committed as many `Schedule` inserts in a single transaction.

**Value objects** (`app/Domain`): `TimeRange` (with the BR-01 overlap test), `Weekday`, `TeachingLoad`, `RoomRequirement`, `TermRef`. These exist specifically so the overlap rule is written once.

---

## 8. Conflict detection architecture

**Design:** a pure, side-effect-free evaluator over a candidate entry plus a pre-fetched conflict universe.

```
ConflictService::check(ScheduleCandidate $c, ConflictUniverse $u): ConflictReport
```

1. **Narrow the universe** in one repository call: entries in the same term sharing any of `room_id`, `faculty_id`, `section_id`, restricted to the candidate's weekday and overlapping time band, excluding `cancelled`. Indexes: `(semester_id, room_id, day, start_time)`, `(semester_id, faculty_id, day, start_time)`, `(semester_id, section_id, day, start_time)`.
2. **Run rule objects** in deterministic order, each returning zero or more `Conflict` values with `ruleId`, `severity`, `message`, and implicated schedule IDs:

   | Rule | Source |
   |---|---|
   | `RoomOccupiedRule` | CNF-01, BR-02 |
   | `FacultyBusyRule` | CNF-02, BR-03 |
   | `SectionBusyRule` | CNF-03, BR-09 |
   | `DuplicateAllocationRule` | CNF-04, BR-10 |
   | `RoomSuitabilityRule` | CNF-05, BR-05, BR-06 |
   | `FacultyLoadRule` | CNF-06, BR-07 |
   | `AvailabilityRule` | CNF-07 |
   | `PolicyWindowRule` | CNF-07, BR-08 |

3. **Aggregate** into `ConflictReport` with `hasErrors()`, `errors`, `warnings`.
4. **If errors exist**, `AlternativeService` computes candidates by free-slot subtraction: available rooms = rooms of matching type/capacity minus occupied in the window; available slots = allowed hours minus busy intervals minus breaks, ranked by proximity to the requested time and by faculty preference.

**Why this shape:** rules are independently unit-testable (UAT-1), the universe query is a single round trip (CNF-11), and the same evaluator serves form validation, calendar drag, bulk import and the generator's feasibility filter.

**Candidate state:** previews include `draft` entries (BR-04) so two unapproved drafts cannot silently collide.

---

## 9. Timetable generator architecture (deterministic)

**Not AI.** A constrained search using backtracking with heuristics, wrapped in a preview/commit boundary.

**Pipeline**

1. **Requirement expansion** — for the term and department(s), expand each section's curriculum into `(section, subject, weekly contact hours)` demands.
2. **Candidate space** — for each demand, enumerate legal placements: eligible faculty (department, qualification, remaining load), eligible rooms (type, capacity ≥ section size), legal day/hour blocks (allowed hours minus breaks, respecting availability).
3. **Ordering** — sort demands by *most constrained first* (fewest candidate placements; MRV heuristic), tie-broken by largest section.
4. **Search** — depth-first assignment with forward checking and conflict-directed backjumping; a placement is accepted only if `ConflictService` reports no hard conflict against the partial timetable.
5. **Objective scoring** on complete assignments (GEN-05 order): maximize satisfied demands → minimize unused room-hours → minimize faculty load variance → minimize faculty idle gaps → prefer preferred rooms. Keep the best of N restarts with different tie-break seeds (still deterministic for a given seed, which is recorded with the draft).
6. **Result** — `TimetableDraft` (in-session or cache, never DB) with placements, unsat list with the specific blocking constraint, and metrics (utilization %, load balance).
7. **Commit** — one transaction inserting all placements as `draft` schedules, then handing them to the normal approval flow (BR-11).

**Explicit constraints:** bounded node budget (e.g. 200k expansions) and wall-clock budget, so a pathological input fails with "no feasible timetable found; these demands are unsatisfiable because…" instead of hanging.

---

## 10. Calendar and API contract (Phase 12)

### Calendar views (CAL-01)

- **Month** (`?view=month`, the default): a 7-column grid whose cells carry
  department-colour dots. The month header pivots ±1 month; the date range is
  the server's scope, never the client's storage.
- **Week** (`?view=week`): a horizontal time rail (Monday→Sunday) rendered as
  30-minute row cells. The same drag layer that drives the grid paints the
  week and the day.
- **Day** (`?view=day`): a single 24-hour rail, 30-minute slots.
- **Timetable** (`?view=timetable`): rows are rooms, columns are 30-minute
  slots. The range feed returns all of the term's entries scoped by role and
  department (CAL-04), so the grid paints without a second query.

The page (`/calendar`) serves all four pivots from one controller so the
week/day/timetable can render from the same data shape. The timetableView
owns the drag targets; the month cells carry the same `data-date`/`
data-entry-count` shape the drag stub consumes, so a month cell can be a
drop target without any re-derivation.

### API contract

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/calendar/events?start=&end=&semester_id=&department_id=&filters[]` | GET | FullCalendar range feed, permission-scoped, colour by department |

**Query parameters** (all optional, server defaults to the actor's term):

- `start` / `end` — ISO-8601 date-time in the configured timezone (app serves
  UTC). The HTTP layer converts them before the controller formats the
  envelope; the controller only ever sees clean strings.
- `semester_id` — optional; the repository's `currentSemester()` gate falls
  back to the actor's department when absent.
- `department_id` — optional department filter; only rows with `department_id`
  matching the scope are returned.
- `page` / `per_page` — pagination; `default 25`, `max 100` in the controller.

**Response** — `{ ok: true, data: { events, total } }` where each event is:

```json
{
  "id": 1234,
  "title": "CSC 101 · CS101A",
  "start": "2026-08-24T07:00:00Z",
  "end": "2026-08-24T08:30:00Z",
  "allDay": false,
  "section_code": "CS101A",
  "subject_code": "CSC 101",
  "teacher": "Alan Cruz",
  "room": "A-102",
  "department_color": "#8c1d40",
  "status": "published",
  "notes": "Bring projector."
}
```

**Events are colour-coded by department** — the repository's `activeInSemester()`
JOIN already returns `department_color`; the controller just forwards it as
`department_color`. The calendar's `eventRender` hook paints the tile with
that hex, so the department palette is a server concern, not a client one.

**Security** — every calendar endpoint requires `auth` + `permission:c_calendar.view`,
and the repository filters rows by scope before the payload leaves the server:

- `administrator` → every row in the term,
- `dean` / `secretary` → rows of their own department,
- `faculty` → only rows they teach.

**CAL-03 drag-and-drop** — the month/week/day pivots are FullCalendar's
`eventDrop` / `eventResize`; the drag stub validates through `PATCH
/api/schedules/{id}` (same path as the form). The response must be one of:

- `200 { ok: true, ... }` — move/resize committed;
- `409 { ok: false, errors: [...], conflicts: [..], alternatives: [...] }` —
  the engine rejected the placement;
- `422 { ok: false, errors: [...] }` — validation failed (calendar plays by
  the same rules as the form, including the conflict-phase check);
- `429` — throttle (NMF-02).

On `409` the client must revert the tile to its old slot and show the
`alternatives` the conflict engine computed (roommate-first suggestion).

**CAL-05 range scoping** — the API never returns the whole table. The
repository's `calendarFeed()` accepts `start`/`end`, filters the term's rows
in one JOIN scan, and hands the frontend only the visible window. Payload is
bounded by the range + `per_page`, never by the table size.

**CAL-06** — clicking an event navigates to the schedule's detail drawer
(`/schedules/{id}`), which renders notes, comments, change requests and an
edit action if permitted. The calendar does not embed the drawer.

The rest of the API (`/api/schedules`, `/api/schedules/{id}`, conflicts,
generator, dashboard, search, notifications) is documented in the Phase 11
and later phase modules — it is untouched by this phase.

**Conventions:** JSON envelope `{ ok, data }` / `{ ok: false, errors, conflicts,
alternatives }`; all mutating endpoints require `X-CSRF-Token`; pagination
`?page=&per_page=`; errors carry machine-readable `code` plus human
`message`.

---

## 11. Notification and collaboration architecture

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/calendar/events?start=&end=&filters[]` | GET | FullCalendar range feed, permission-scoped, colour by department |
| `/api/schedules` | POST | Create (validated, conflict-checked) |
| `/api/schedules/{id}` | PATCH | Move/resize/edit via calendar |
| `/api/schedules/{id}` | DELETE | Cancel (soft status change) |
| `/api/schedules/conflicts/preview` | POST | Dry-run conflict check + alternatives (no write) |
| `/api/dashboard/widgets` | GET | Chart.js datasets, role-aware |
| `/api/search?q=` | GET | Grouped global search |
| `/api/notifications` | GET/PATCH | List, unread count, mark read |
| `/api/generator/preview` | POST | Run generator, return draft |
| `/api/generator/commit` | POST | Persist draft transactionally |

**Conventions:** JSON envelope `{ ok, data }` / `{ ok: false, errors, conflicts, alternatives }`; all mutating endpoints require `X-CSRF-Token`; pagination `?page=&per_page=`; errors carry machine-readable `code` plus human `message`.

---

## 11. Notification and collaboration architecture

- **Triggers** are raised as domain events *inside* services after the DB write succeeds (`schedule.created`, `conflict.detected`, `request.approved`, `mention.created`), then queued in the `notifications` table in the same transaction.
- **Delivery** is decoupled: an in-app row is written immediately; email rows sit with `status = pending` and are drained by `bin/dispatch-notifications.php` (cron) or the documented pseudo-cron URL. Failures retry with backoff and are logged — SMTP slowness never blocks a user request (NTF-04).
- **Comments/tasks/activity** are polymorphic by `subject_type`/`subject_id` with explicit allowed-type maps (no arbitrary class names from user input).
- **Mentions** are resolved server-side from `@email` tokens against scoped users; resolved IDs are stored, not the raw text.

---

## 12. Reporting architecture

Reports are **read models**: dedicated SQL in `ReportRepository` (aggregations, grouped sums, date-range filters), never reuse of write-path entities. Export is a rendering concern:

```
ReportService::build($type, $filters, $scope): ReportResult (title, columns, rows, totals, meta)
    → HtmlReportRenderer  (print CSS)
    → PdfExporter         (DomPDF, storage/exports, streaming download)
    → ExcelExporter       (PHPSpreadsheet, streaming download)
```

Exports are generated to `storage/exports/` with a signed, expiring filename and are pruned by the same cron job.

---

## 13. Security architecture

| Control | Design |
|---|---|
| Authentication | `password_hash(PASSWORD_DEFAULT)`; `password_verify`; session `RegenerateID` on login/logout; remember-me as selector+validator (validator hashed); throttling per email+IP (AUTH-07). |
| Session | `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS; strict mode; idle + absolute timeouts; custom save path in `storage/sessions`. |
| CSRF | Per-session token in a meta tag and hidden field; `CsrfMiddleware` verifies all non-GET requests including API (header token); constant-time compare. |
| Authorisation | Route-level `role:`/`permission:` middleware + service-level scope resolution; repository always receives a `Scope` value object. Deny-by-default for unmapped routes. |
| SQL injection | PDO prepared statements only; no string interpolation into SQL; repository base forbids raw user data in `ORDER BY` (whitelist maps). |
| XSS | All output through `e()`; JSON encoded with `JSON_HEX_TAG`; no `innerHTML` with server strings in JS; CSP header blocking inline scripts except a nonce. |
| Input validation | `Validator` with rule sets per form; server-side is authoritative, client-side is convenience; typed casting before persistence. |
| File uploads | Extension + MIME + size checks, randomised names, stored under `public/assets/uploads` with executable extensions denied by `.htaccess`. |
| Secrets | `config/*.php` outside the web root; `.env`-style local override never committed; SMTP credentials never rendered. |
| Auditing | Auth events, authorisation failures and every schedule mutation with actor, IP, before/after diff. |
| Headers | `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, HSTS when HTTPS. |

**Implementation:** Phase 5 delivers the enforcement points as `App\Core\Security`, `App\Core\Session`, `App\Helpers\Validator` and `config/security.php`; the full control catalogue, threat model, API reference and verification procedure live in `docs/SECURITY.md`. Phase 6 wires them into the request: the Kernel sends `Security::securityHeaders()` on every response and marks them `no-store`; `StartSession` runs globally; `VerifyCsrfToken` guards every non-safe method (419 on failure); `Authenticate` guards `auth:` routes (401 JSON or a 302 to the login route).

Phase 8 implements authorisation end to end: `config/permissions.php` declares the catalogue and the role matrix, `bin/sync-rbac.php` writes it to `permissions`/`role_permissions`, `AuthorizationService` answers decisions with deny-by-default semantics (no wildcard, no administrator bypass), `Authorize` enforces `permission:`/`role:` route rules and audits refusals as `authz.denied`, and `App\Services\Scope` is passed into repository queries so department and self filtering happens in SQL rather than in templates.

**Threat-model highlights:** privilege escalation by ID enumeration (mitigated by scope-filtered repository fetch returning 404, not 403, for out-of-scope IDs); conflict bypass via API (all writes share `ScheduleService`); CSV/Excel formula injection (prefix `= + - @` cells).

---

## 14. Configuration and environment

```
config/app.php        name, base_url, timezone, env, debug, session, upload limits
config/database.php   host, port, database (anth04_SyncClass), user, pass, charset
config/mail.php       SMTP host/port/user/pass/from, enabled flag
config/schedule.php   allowed hours, break periods, default max load, hard/soft rules
config/security.php   password algorithm/policy, CSRF lifetime, throttle thresholds, response headers
```

Selection order: `config/local.php` (git-ignored) → environment variables → defaults. `debug=true` shows a styled error page; `debug=false` logs and shows a generic message with a request ID.

---

## 15. Frontend architecture

- **Server-rendered** Bootstrap 5 pages with a persistent sidebar, topbar and offcanvas mobile navigation.
- **Design tokens** in `assets/css/tokens.css` (`--primary-gold`, `--primary-maroon`, `--sidebar-bg`, `--card-shadow`, status colours, department palette). Components layer on Bootstrap utilities rather than forking it.
- **JS modules** (ES modules, no bundler required): `calendar.js`, `schedule-form.js` (live conflict preview via `AbortController`), `notifications.js` (poll + toast), `charts.js`, `search.js` (debounced), `tasks.js` (SortableJS).
- **Data flow:** modules fetch JSON endpoints, render via DOM APIs (never string-built HTML), and update the UI optimistically only when the server confirms.
- **Progressive enhancement:** forms submit and redirect without JS; JS adds live validation, conflict preview and drag-drop.

---

## 16. Deployment topology

```
Browser ──HTTPS──> Apache (mod_rewrite, mod_headers)
                      └── DocumentRoot = SyncClass/public
                            index.php ─┬─> PHP-FPM/mod_php 8.1+
                                       ├─> MySQL 8 (anth04_SyncClass)
                                       ├─> storage/ (logs, exports, sessions, cache)
                                       └─> SMTP (outbound, PHPMailer)
Cron: bin/dispatch-notifications.php  (every 5 min; optional pseudo-cron URL)
```

XAMPP: copy to `D:\xampp8.2\htdocs\SyncClass`, import `database/schema/SyncClass.sql`, run seeders, browse `http://localhost/syncclass/`. Shared hosting: upload, point domain to `public/`, or use the root fallback bootstrap; chmod `storage/` writable; set `config/local.php`.

---

## 17. Architecture decision records (summary)

| ADR | Decision | Rationale | Consequences |
|---|---|---|---|
| ADR-01 | Native PHP MVC, hand-rolled kernel | Mandated by the brief | We own routing/DI/middleware; must keep them small and well-tested |
| ADR-02 | Repositories own all SQL | Prevents query sprawl and scope leaks | More files; explicit read models for reports |
| ADR-03 | One `ConflictService` for every write path | Guarantees BR rules cannot be bypassed | Write endpoints must never touch the `schedules` table directly |
| ADR-04 | Weekly entries normalised to one row per meeting | Simple SQL overlap queries, correct uniqueness | Recurring edits update multiple rows (handled by service) |
| ADR-05 | Interval overlap as `start < other.end AND other.start < end` | Half-open intervals; correct for touching slots | Centralised in `TimeRange` |
| ADR-06 | Generator is backtracking + heuristics, deterministic by seed | Brief forbids generative AI; reproducible drafts | Bounded node/time budget required |
| ADR-07 | Drafts never persisted until commit | Prevents half-generated timetables polluting live data | Draft stored in session/cache with TTL |
| ADR-08 | Notifications queued in a table, drained by cron | SMTP failures cannot block requests | Requires cron or documented pseudo-cron |
| ADR-09 | Server-rendered views, JSON islands | No SPA build step; shared-hosting friendly | Slightly more full-page loads; mitigated by AJAX islands |
| ADR-10 | Department scope enforced in repositories | Data-leak safety over convenience | Every query takes a `Scope` object |
| ADR-11 | Soft deactivation for referenced master data | Preserves historical timetables | "Delete" becomes "Deactivate" in the UI |
| ADR-12 | Composer limited to PHPMailer, DomPDF, PHPSpreadsheet, Carbon | Brief-allowed, avoids framework creep | No ORM/template engine utilities to lean on |

---

## 18. Roadmap (phases 3–20)

| Phase | Deliverable | Exit criterion |
|---|---|---|
| 3 | `docs/DATABASE.md`, ERD, migrations, `schema/SyncClass.sql` | Schema imports cleanly on MySQL 8; FKs and indexes present |
| 4 | Folder structure + autoload | `composer dump-autoload` resolves `App\*` |
| 5 | `docs/SECURITY.md`, security helpers | CSRF/session/validation primitives unit-tested |
| 6 | Core kernel, router, container, view, DB, base classes | A demo route renders through the real pipeline |
| 7 | Auth (login, logout, remember me, throttle) | AUTH-01…09 pass manually |
| 8 | RBAC + scope | UAT-4 passes (403/404 on cross-scope access) |
| 9 | Master data CRUD + lists/filters | MD-01…08 pass |
| 10 | Scheduling and search (form, recurring builder, SRCH-01…03) | SCH-01…07 pass |
| 11 | Conflict engine + alternatives | UAT-1 passes; CNF-11 benchmark met |
| 12 | Calendar + API (CAL-01…06) | UAT-6 passes; month view under 1.5 s |
| 12 | Calendar + API (CAL-01…06) | UAT-6 passes; month view under 1.5 s |
| 13 | Generator (GEN-01…06) | UAT-3 passes; drafts commit transactionally |
| 14 | Collaboration + notifications | NTF-01…04, COL-01…05 pass |
| 15 | Reports + exports (RPT-01…07) | UAT-5 passes; PDF/Excel open correctly |
| 16 | Dashboard analytics | DASH-01…03 pass |
| 17 | UI polish, responsive, a11y | NFR-08/09 reviewed |
| 18 | Test pass (rule tests, smoke, UAT-1…6) | All UAT green |
| 19 | Seeders (5 depts, 20 faculty, 50 subjects, 30 rooms, 100 schedules) | Demo login works for all four roles |
| 20 | Deployment package (SYS-01, SYS-03, SYS-04) + docs (`INSTALL`, `DEPLOYMENT`, `USER_GUIDE`, `API`, `CHANGELOG`) | Clean install from docs on XAMPP |

---

## 19. Testing procedure for this phase

Architecture review checklist (no code yet):

1. **Layer discipline** — read §2 and §6; confirm every module has exactly one controller set, service and repository, with no shortcut paths.
2. **Write-path uniqueness** — confirm §8 and §9 both delegate rule evaluation to `ConflictService`, satisfying CNF-01…CNF-11 without duplication.
3. **Scope safety** — confirm §13 denies access in the repository layer and that §4's pipeline carries a `Scope` object into services.
4. **Hosting feasibility** — confirm §3/§16 need nothing beyond Apache + PHP 8.1 + MySQL 8 + writable `storage/`.
5. **Traceability** — confirm every functional requirement group (AUTH, MD, SCH, CNF, GEN, CAL, COL, NTF, SRCH, RPT, DASH, SYS) appears both in the §6 module map and in a §18 roadmap row, and that each module there has a single owning service and repository.
6. **Over-engineering check** — confirm no rejected pattern (CQRS, event sourcing, microservices, ORM) has crept in; each abstraction is justified by ADR-03/07/10 or the brief.

Result: architecture is implementable as specified with no unresolved dependency on Phase 3+ decisions.

---

## 20. Next phase

**Phase 3 — Database Design:** ERD, data dictionary, migration files, `schema/SyncClass.sql`, indexes matching the conflict-engine access patterns (§8) and audit/soft-delete conventions from ADR-11. Await review before migrations are written.
