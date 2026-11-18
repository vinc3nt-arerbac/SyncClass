# SyncClass — Security Architecture

**Document:** `docs/SECURITY.md`
**Phase:** 5 of 20 — Security Architecture
**Version:** 1.0
**Status:** Implemented primitives; enforcement wiring lands in Phases 6–8
**Inputs:** `docs/REQUIREMENTS.md` (AUTH-01…09, NFR-07, NFR-11, SYS-02), `docs/ARCHITECTURE.md` §13
**Code:** `app/Core/Security.php`, `app/Core/Session.php`, `app/Helpers/Validator.php`, `config/security.php`, `tests/security_primitives_test.php`

---

## 1. Scope

This document is the security contract for SyncClass. It states what is protected, how, and by which component; it is written so a reviewer can verify a control by reading one file and running one command.

In scope:

- password storage and verification (AUTH-02);
- session creation, fixation defence, idle/absolute timeouts, logout (AUTH-03, AUTH-04, AUTH-06);
- CSRF token issuance and verification (AUTH-09);
- input validation and typed extraction (`Validator`);
- output encoding, JSON embedding, response headers (XSS, NFR-07);
- the design for throttling, remember-me, RBAC scoping, uploads, auditing and secrets (implemented in later phases, specified here).

Out of scope for this phase (explicitly deferred): multi-factor authentication, SSO, WAF, encryption at rest of the database, and client-side validation (Phase 17). None of these is claimed anywhere in this document.

---

## 2. Security objectives

| # | Objective | Enforced by |
|---|---|---|
| O-1 | A stolen database dump does not reveal passwords. | bcrypt/argon2id hashes, cost 12; no reversible storage; passwords never logged |
| O-2 | A stolen or guessed session cookie is of limited use. | HttpOnly, SameSite, Secure, strict mode, UA binding, idle + absolute timeouts, regenerated ID |
| O-3 | Every state-changing request is intentional. | session-bound CSRF token on all non-safe methods, including JSON APIs |
| O-4 | A user only sees and changes their scope. | RBAC ceiling + department scope filter in repositories (Phase 8); no client-supplied scope |
| O-5 | No user input reaches SQL, HTML, JS or a shell unmediated. | prepared statements, `escape()`, `json()`, strict validation |
| O-6 | Errors and logs help operators without helping attackers. | request IDs, no stack traces when `debug=false`, PII-minimised logging |
| O-7 | Every privileged action is attributable. | `activity_log` rows with actor, IP, event and before/after properties (Phase 8+) |

**Security invariants** (review checklist for every later phase):

1. No SQL string concatenation with user data; every query is prepared.
2. Every dynamic value in a template passes through `$security->escape()` or an equivalent context encoder.
3. Every non-GET route passes through CSRF verification before the controller runs.
4. The session starts through `App\Core\Session`; no direct `session_start()` anywhere else.
5. Authorisation is checked in the service/repository, not only in the UI.
6. Secrets are read from `config/local.php` or `settings`, never from tracked files or the web root.

---

## 3. Assets, actors, trust boundaries

**Assets:** user credentials and session identifiers; the schedule data (the institution's operational plan); audit history; SMTP and database credentials; uploaded files; personal data (names, institutional emails, employee numbers).

**Actors:** anonymous visitor; authenticated faculty; department secretary; dean; system administrator; cron/CLI; SMTP provider.

**Trust boundaries:**

```
Browser ──(1)──> Apache/PHP ──(2)──> MySQL
   │                  │
   │                  ├──(3)──> storage/ (logs, sessions, exports)
   │                  └──(4)──> SMTP
   └─ untrusted input, untrusted output context
```

1. Everything from the browser is untrusted: headers, cookies, query, body, files, JSON.
2. The application is the only client of the database; the database is trusted but its content is still escaped on output.
3. `storage/` is outside the document root and denied by `.htaccess`; only PHP writes it.
4. Email content is generated from templates and escaped data; SMTP credentials never appear in a message.

---

## 4. Threat model (STRIDE, abbreviated)

| Threat | Example | Control | Phase |
|---|---|---|---|
| **Spoofing** | Credential stuffing against `admin@sac.edu.ph` | bcrypt cost 12, uniform failure message, dummy verify for unknown emails, throttle 5/15 min, optional Argon2id | 5 / 7 |
| **Spoofing** | Stolen cookie replayed from another browser | HttpOnly + Secure + SameSite, strict mode, UA fingerprint binding, ID regeneration, timeouts | 5 |
| **Tampering** | Forged schedule feedback/approval request | CSRF token on every mutation; authorisation in services | 5 / 8 |
| **Tampering** | Mass assignment (`role_id=1` posted to a profile form) | `Validator::validated()` allow-list; services accept explicit fields | 5 / 9 |
| **Repudiation** | "I never approved that timetable" | append-only `activity_log` with actor, IP, before/after JSON | 8 / 15 |
| **Information disclosure** | SQL error with credentials on screen | `debug=false`, generic message + request ID, PDO exceptions logged not printed | 4 / 5 |
| **Information disclosure** | Cross-department timetable enumeration by changing `?department=2` | Department scope resolved server-side and applied in repositories; 404 for out-of-scope IDs | 8 |
| **Denial of service** | Login flood, generator abuse | Login throttle table + bounded generator budget (200k nodes / 30 s) | 5 / 13 |
| **Elevation of privilege** | Faculty calls `/admin/users` directly | Route middleware + service-level permission checks, deny-by-default | 8 |
| **XSS** | Subject title contains `<script>` | `escape()` everywhere; JSON hex flags; CSP with nonce; no `innerHTML` from server strings | 5 / 17 |
| **CSRF** | `<img src="/schedules/9/cancel">` from an email | Non-GET verification with session-bound token; SameSite=Lax | 5 / 6 |
| **SQL injection** | `sort=(SELECT SLEEP(5))` | prepared statements, `ATTR_EMULATE_PREPARES=false`, order-by whitelist | 5 / 6 |
| **Path traversal** | `../../config/local.php` as an export name | Whitelisted report names, generated filenames, no user paths | 15 |

---

## 5. OWASP Top 10 (2021) mapping

| Risk | SyncClass control | Where |
|---|---|---|
| A01 Broken access control | RBAC + department scope, deny-by-default middleware, 404 for out-of-scope objects, no client-supplied actor/role | `docs/ARCHITECTURE.md` §13, Phase 8 |
| A02 Cryptographic failures | `password_hash`/`password_verify`, cost 12, SHA-256 only for high-entropy tokens, TLS required in production, HSTS | `Security` |
| A03 Injection | PDO prepared statements with emulation off; output escaping by context; no shell execution in app code | `config/database.php`, `Security::escape()` |
| A04 Insecure design | One write path through services; conflict rules cannot be bypassed; explicit threat model (this document) | `ARCHITECTURE` §8, Phase 10 |
| A05 Security misconfiguration | `debug=false`, security headers, `.htaccess` denial, writable-only `storage/`, documented hardening checklist | §15, §16 |
| A06 Vulnerable components | Composer allow-list (PHPMailer, DomPDF, FPDF, PHPSpreadsheet, Carbon); `composer audit` in CI/release | `composer.json` |
| A07 Identification/auth failures | Password policy, throttling, session regeneration, remember-me rotation, no user enumeration | `Session`, `Security`, Phase 7 |
| A08 Software/data integrity | Append-only audit trail; schema migrations reviewed; no unserialize of user input | `activity_log`, Phase 8 |
| A09 Logging/monitoring failures | Structured app log + audit log, request IDs, auth failure events, log levels | `bootstrap.php`, §14 |
| A10 SSRF | No user-supplied URLs are fetched server-side; SMTP host comes from config only | Phase 14 |

---

## 6. Authentication

### 6.1 Credentials

- Login identifier: institutional email `username@sac.edu.ph` (AUTH-01). The `users.email` column is unique; the domain rule is enforced with `email_domain:sac.edu.ph` in the login/registration validator, not by a hard-coded `str_ends_with` in a controller.
- Password policy (`config/security.php` → `password`):

| Rule | Default | Rationale |
|---|---|---|
| Minimum length | 10 characters | NIST SP 800-63B favours length over composition |
| Maximum length | 72 **bytes** | bcrypt truncates beyond 72 bytes; the visible policy equals the real one |
| Mixed case | required | Raises the search space for weak human passwords |
| At least one digit | required | Same |
| At least one symbol | optional | Length is the dominant factor; symbols frustrate users without adding much |
| Breach-list check | not implemented | Would require an external service; out of scope and documented as such |

Passwords are never trimmed, lower-cased or otherwise normalised: the exact bytes typed are hashed.

### 6.2 Storage (AUTH-02)

`Security::hashPassword()` uses `password_hash()` with:

- `PASSWORD_DEFAULT` (bcrypt, cost 12) unless `PASSWORD_ALGORITHM=argon2id` is configured **and** the PHP build supports it;
- Argon2id options `memory_cost=65536` (64 MiB), `time_cost=4`, `threads=2` when selected;
- a failure to produce a hash throws — the application never stores a plaintext fallback.

`users.password_hash` is `VARCHAR(255)`, which fits bcrypt (60 chars) and Argon2id (~97 chars) with room for future algorithms.

### 6.3 Verification (AUTH-03)

`Security::verifyPassword()` returns `false` for empty input or an empty hash; `password_verify()` otherwise. It never throws on malformed hashes, so a corrupted row cannot authenticate anybody.

For an unknown email, Phase 7 calls `Security::verifyDummyPassword()`: it verifies against a cached hash generated with the *current* algorithm and cost, so the response time of "no such user" is indistinguishable from "wrong password". Combined with the single failure message ("These credentials do not match our records."), this prevents account enumeration.

`Security::needsRehash()` lets Phase 7 transparently upgrade old hashes after a successful login when the cost or algorithm is raised, without a password reset.

### 6.4 Throttling (AUTH-07)

Design (implemented in Phase 7 against the `login_attempts` table):

- Every attempt writes `(email, ip_address, successful, user_agent, attempted_at)`.
- Before verifying, the service counts failures for `email + ip_address` in the last **15 minutes**. At **5** failures the attempt is refused for **15 minutes** and an `auth.throttled` audit event is written.
- A successful login clears the counters for that pair.
- Thresholds live in `config/security.php` → `throttle`, so they are tunable without code changes.
- IP is taken from the direct connection (`REMOTE_ADDR`). `X-Forwarded-For` is **not** trusted; a deployment behind a proxy must configure a trusted-proxy list before using it, documented in Phase 20.

### 6.5 Remember me (AUTH-05)

Selector/validator scheme, matching `remember_tokens`:

1. On login with "remember me": generate `selector = bin2hex(random_bytes(12))` (24 chars) and `validator = randomToken(32)`.
2. Store `selector` and `sha256(validator)` (`validator_hash CHAR(64)`), `expires_at = now + 30 days`, plus IP and User-Agent for diagnostics.
3. Send `selector:validator` in a `Secure`, `HttpOnly`, `SameSite=Lax` cookie with a 30-day expiry.
4. On a request without a session: split the cookie, look up by selector, compare `hash_equals(sha256(validator), validator_hash)`.
5. On success: rotate **both** parts (delete the old row, issue a new one), regenerate the session ID, and start a fresh session.
6. If the selector exists but the validator does not match, treat the token as stolen: delete **all** remember tokens for that user and require a password login.
7. Revoke on logout, password change (`password_changed_at`) and account deactivation.

The validator is never stored in plaintext; the selector is not secret (it is only a lookup key).

### 6.6 Login flow (implemented in Phase 7)

```
GET  /login                  → form + CSRF token (`guest`: signed-in visitors go to /)
POST /login                  → global CSRF verify → validate email/password
                               → AuthService::attempt()
                                 1 throttle: >= max_attempts failures / window for email+IP → 429 + Retry-After
                                 2 UserRepository::findByEmail; on miss verifyDummyPassword() (timing)
                                 3 inactive account → generic 422; locked account → 429
                                 4 verifyPassword(); failure writes login_attempts + users counter
                                   and locks the account at the threshold
                                 5 success: rehash if needed, reset counters, stamp last_login_at,
                                   session_regenerate_id(true), rotate the CSRF token, store the
                                   user id in the session, optionally issue a remember token
                               → 303 to the intended path, or /
POST /logout (CSRF)          → revoke the presented remember token → audit → Session::destroy()
                               → expire the cookie → 303 /login
```

**Session contents:** only the authenticated user id (`_syncclass_auth_id`). The role and department are read from the database on every request by `Authenticate`, so a role change or a deactivation takes effect immediately instead of waiting for the session to expire.

**Open-redirect protection:** the `intended` value is written by the auth middleware from the requested URI, is accepted only as a local path (a leading `//` is discarded), and is prefixed with the application base path before redirecting.

**Remember me:** cookie `syncclass_remember` = `selector:validator`, HttpOnly, SameSite=Lax, Secure over HTTPS, 30 days. The database stores the selector plus SHA-256(validator) in `remember_tokens`; consuming a cookie deletes the row and issues a new pair, and a selector/validator mismatch invalidates every remembered browser for that user.

---

## 7. Session management (AUTH-04, AUTH-06)

Implemented by `App\Core\Session`.

### 7.1 Cookie and PHP ini

| Setting | Value | Why |
|---|---|---|
| Cookie name | `syncclass_session` | Avoids the default `PHPSESSID` fingerprint |
| `HttpOnly` | on | JavaScript cannot read the cookie |
| `SameSite` | `Lax` (downgraded from `None` on plain HTTP) | Blocks cross-site POSTs; `None` without `Secure` is rejected by browsers |
| `Secure` | on when `APP_HTTPS=true` or the request is HTTPS | Cookie never sent over plain HTTP in production |
| `Path` / `Domain` | `/`, host-only | No cross-subdomain leakage |
| Cookie lifetime | 0 (browser session) | The server enforces the real 8-hour cap; a copied cookie cannot outlive it |
| `session.use_strict_mode` | 1 | Rejects session IDs the server did not issue (fixation) |
| `session.use_only_cookies` | 1 | No `?PHPSESSID=` URLs |
| `session.use_trans_sid` | 0 | No SID rewriting into HTML |
| `session.cache_limiter` | `nocache` | Authenticated pages are not cached by proxies |
| `session.gc_maxlifetime` | absolute lifetime (480 min) | Garbage collection does not delete live sessions |

### 7.2 Timeouts

Metadata is stored under `Session::META_KEY`:

```php
['created_at' => int, 'last_seen' => int, 'ua' => sha256(User-Agent)]
```

- **Idle:** more than `idle_timeout_minutes` (30) since `last_seen` → expire with reason `idle`.
- **Absolute:** more than `lifetime_minutes` (480) since `created_at` → expire with reason `absolute`.
- Both are checked by `Session::start()` and can be re-checked by `checkTimeouts()` during long requests (reports, generator).
- Expiry is graceful: the ID is regenerated, session data is cleared, and `expiredReason()` tells the middleware to show "Your session expired — please sign in again." The user is not left with a half-valid session.

### 7.3 Hijack mitigation

- The session ID is regenerated on login and logout, and on any privilege change.
- The User-Agent fingerprint is compared with `hash_equals`; a mismatch discards the session (reason `agent`). This is configurable (`bind_user_agent`) because proxies can rewrite the header.
- Nothing sensitive is stored in the session: only `user_id`, `role_id`, `department_id`, CSRF state and flash data.

### 7.4 Storage

Sessions live in `storage/sessions` (created `0770` by `Session`), which `.htaccess` denies to the web. If the directory cannot be created or written, the class falls back to the host's system session path so the application still works; the health check reports storage writability.

### 7.5 Logout (AUTH-04)

`Session::destroy()` clears `$_SESSION`, expires the cookie with the same path/domain/SameSite attributes, and calls `session_destroy()`. Phase 7 also revokes remember tokens and writes an `auth.logout` audit event.

### 7.6 Regeneration matrix

| Event | Session ID | CSRF token | Session data |
|---|---|---|---|
| Login success | regenerate (`delete_old=true`) | rotate | new identity fields |
| Login failure | regenerate | keep | unchanged (no identity) |
| Privilege/role change | regenerate | rotate | refreshed role data |
| Logout | destroy | n/a | cleared |
| Idle/absolute expiry | regenerate | implicitly invalid (data cleared) | cleared |
| Password change | regenerate | rotate | remember tokens revoked |

---

## 8. CSRF (AUTH-09)

Implemented by `Security` (`csrfToken`, `validateCsrf`, `rotateCsrfToken`, `csrfField`, `csrfMeta`).

- One token per session, 32 random bytes hex-encoded, stored under `Security::CSRF_SESSION_KEY` as `['value' => ..., 'issued_at' => ...]`.
- Lifetime 120 minutes (`csrf.lifetime_minutes`); a form left open longer is rejected and a fresh token is issued on the next render.
- Transport: hidden field `_token` for forms, `X-CSRF-Token` header for `fetch`/AJAX. Both are read by the Phase 6 middleware.
- Verification runs for every method except `GET`, `HEAD`, `OPTIONS`, `TRACE` — including JSON APIs, calendar drag/drop, bulk actions and logout.
- Comparison uses `hash_equals` (constant time). Missing and empty tokens fail.
- Rotation on login, logout and privilege change prevents a token captured before authentication from being replayed after it.
- Failure response: HTTP **419** (JSON `{ ok: false, code: "csrf" }` for API clients), an `auth.csrf_failed` audit event, and no controller execution.
- `SameSite=Lax` is defence in depth, not a replacement: it does not protect every browser/flow, so the token is always verified server-side.

**Embedding:**

```php
<form method="post" action="/schedules">
    <?= $security->csrfField() ?>
    ...
</form>
```

```html
<meta name="csrf-token" content="<?= $e($security->csrfToken()) ?>">
```

The `assets/js` fetch wrapper (Phase 17) reads the meta tag and sends the header; it never stores the token in `localStorage`.

---

## 9. Authorisation

Authentication answers *who*; authorisation answers *what*. The design (implemented in Phase 8) is:

1. **Role ceiling.** Four roles: administrator, dean, secretary, faculty. Permissions are rows (`permissions.code`, e.g. `schedule.approve`), mapped through `role_permissions`. Role checks are route middleware (`role:administrator,dean`) and service-level `authorize()` calls.
2. **Department scope.** Every non-administrator request resolves a `Scope` value object (`department_id`, `user_id`, `is_admin`). Repositories take the scope as a required argument and add the `WHERE` clause themselves. There is no "scope optional" code path.
3. **Object-level checks.** Services re-check ownership/scope on the loaded row: a dean may approve only schedules whose section or room belongs to their department; faculty may read only their own assignments.
4. **Existence hiding.** Fetching an out-of-scope ID returns **404**, not 403, so IDs cannot be enumerated. A 403 is used only when the object is legitimately visible but the action is not permitted.
5. **Deny by default.** Unmapped routes and unknown permissions are refused. Permissions are additive; no role implies another.
6. **No client-supplied identity.** `actor_id`, `role_id` and `department_id` always come from the session, never from the request body. `Validator` allow-lists protect against mass assignment.

The RBAC matrix itself is in `docs/REQUIREMENTS.md` §4.

---

## 10. Input validation

Implemented by `App\Helpers\Validator`. Server-side validation is authoritative; client-side validation (Phase 17) only improves feedback.

### 10.1 Usage

```php
$validator = Validator::make($_POST, [
    'department_id' => 'required|integer',
    'section_id'    => 'required|integer',
    'subject_id'    => 'required|integer',
    'room_id'       => 'required|integer',
    'day'           => 'required|integer|between:1,7',
    'start_time'    => 'required|time',
    'end_time'      => 'required|time|after:start_time',
    'notes'         => 'nullable|string|max:500',
], [
    'department_id' => 'Department',
], [], $config['security']['password']);

if ($validator->fails()) {
    return $this->unprocessable($validator->errors(), $validator->input(...));
}

$data = $validator->validated();   // only declared fields survive
```

### 10.2 Rules

| Rule | Parameter | Behaviour |
|---|---|---|
| `required` | — | Fails when absent, `null`, or only whitespace |
| `nullable` | — | Absent/null values skip the remaining rules and are omitted from `validated()` |
| `string` | — | Must be a string |
| `email` | — | `FILTER_VALIDATE_EMAIL`, max 254 characters |
| `email_domain` | `a.edu,b.edu` | Domain after `@` must be in the list |
| `integer` | — | `FILTER_VALIDATE_INT`; cast to `int` |
| `numeric` | — | `is_numeric`; cast to int/float |
| `boolean` | — | Accepts `1/0/true/false/on/yes/no`; cast to `bool` |
| `array` | — | Must be an array |
| `min` / `max` | `n` | Text length in characters; numeric value when `integer`/`numeric` is also declared |
| `between` | `min,max` | Same measure as `min`/`max` |
| `in` / `not_in` | `a,b,c` | Strict string membership |
| `regex` | `/pattern/` | Full PCRE; invalid patterns throw a developer exception |
| `date` | — | `strtotime`-parseable |
| `date_format` | `Y-m-d` | Exact round-trip format match |
| `time` | — | `HH:MM` or `HH:MM:SS`, 24-hour, 00–23 / 00–59 |
| `after` / `before` | `field` | Compares with another field using `strtotime` |
| `same` / `different` | `field` | String equality with another field |
| `confirmed` | — | Must equal `<field>_confirmation` |
| `alpha_dash` | — | `A–Z a–z 0–9 _ -` |
| `password` | — | Applies the configured password policy |
| `Closure` | — | Custom check; return `true` or an error string |

**Formats:** rules can be a pipe string (`'required|email|max:150'`) or an array (`['required', 'regex:/^[A-Z]{2}-/']`). Use the array form whenever a regex contains `|`, because the string form splits on pipes.

**Messages:** defaults live in `Validator::DEFAULT_MESSAGES`; override globally (`'email' => '...'`) or per field (`'users.email.email' => '...'`). Labels replace `:attribute`.

**Mass assignment:** `validated()` returns only fields that have rules and passed, with `integer`/`numeric`/`boolean` casts applied. Controllers pass that array to services — never `$_POST`.

**Database-aware rules** (uniqueness, "room exists") are deliberately absent: they belong in repositories/services, so validation never contains SQL and cannot leak existence through error text.

### 10.3 File uploads (Phase 9 policy)

| Control | Requirement |
|---|---|
| Size | `app.uploads.max_bytes` (2 MiB default) |
| Type | MIME sniffed with `finfo`, must be in `app.uploads.allowed_mimes` (JPEG/PNG/WebP) |
| Name | Randomised (`bin2hex(random_bytes(16))` + canonical extension); the client filename is never used on disk |
| Location | `public/assets/uploads/` with an `.htaccess` that denies script execution |
| Image safety | Re-encode via GD before storing when available; strip EXIF |
| Authorisation | Upload endpoints require an authenticated actor and CSRF; download names are validated against the DB record |

SVG is not accepted (script-capable). Uploaded files are never included or executed.

---

## 11. Output encoding (XSS)

| Context | Method | Notes |
|---|---|---|
| HTML text and quoted attributes | `$security->escape($value)` | `htmlspecialchars` with `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8; arrays/objects render as empty rather than "Array" |
| JSON API responses | `json_encode(..., JSON_THROW_ON_ERROR)` in `Response::json()` | Envelope never contains HTML |
| Data inside `<script>` | `$security->json($value)` | `JSON_HEX_TAG/AMP/APOS/QUOT` neutralise `</script>` and quotes |
| URL query values | `http_build_query()` + escaped output | No string concatenation of user data into URLs |
| Inline scripts | CSP nonce | `nonce="<?= $e($security->nonce()) ?>"`; no `unsafe-inline` for scripts |
| DOM updates | `textContent` / `createElement` | Server strings are never assigned to `innerHTML` |

Templates (Phase 6) expose escaping as `$e(...)`, a bound reference to `Security::escape()`, so a view cannot accidentally call `htmlspecialchars` with the wrong flags.

**CSP** (default): `default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-{nonce}'; connect-src 'self'`.

`style-src 'unsafe-inline'` is an accepted, documented compromise for Bootstrap's inline style attributes; scripts remain nonce-only. Tightening styles is a Phase 17 task (nonce-based `<style>` blocks).

---

## 12. SQL injection

- All database access goes through `App\Core\Database` (Phase 6) using PDO prepared statements.
- `PDO::ATTR_EMULATE_PREPARES => false`, `ATTR_ERRMODE => EXCEPTION`, `ATTR_STRINGIFY_FETCHES => false` (already in `config/database.php`).
- Values are bound, never interpolated. Identifiers that must be dynamic (sort column, sort direction) come from a whitelist map in the repository; an unknown key falls back to the default instead of reaching SQL.
- `LIKE` patterns are built with escaped `%`/`_` and bound as parameters.
- Dynamic `IN (...)` lists are generated as `?, ?, ?` placeholders with one bound value each.
- No repository accepts a raw SQL fragment from a controller. No application code calls `exec`, `shell_exec`, `system` or `popen`.

---

## 13. Cryptography and secrets

| Use | Primitive | Implementation |
|---|---|---|
| Passwords | bcrypt cost 12 (or Argon2id) | `password_hash` / `password_verify` in `Security` |
| Random tokens | `random_bytes` | `Security::randomToken()`, `rememberSelector()`, `nonce()` |
| Token storage | SHA-256 | `Security::hashToken()` — appropriate because inputs are 128+ bits of entropy |
| Comparisons | `hash_equals` | `Security::equals()`, `verifyTokenHash()`, CSRF, UA binding |
| Transport | TLS 1.2+ | Apache configuration; HSTS header once HTTPS |
| At rest | Host/filesystem | Database and `storage/` are protected by OS permissions and backups; no application-level encryption is claimed |

Secrets:

- Database/SMTP credentials come from environment variables or `config/local.php` (git-ignored), never from tracked files.
- `settings` may hold SMTP credentials for the admin UI; they are masked on read, excluded from logs/exports and never selected by `is_public` queries (Phase 20).
- `.env`, `config/local.php`, `storage/logs/*` are in `.gitignore`; `config/`, `app/`, `database/`, `storage/` are denied by `.htaccess`.
- Secrets are never written to `activity_log`, application logs or error pages.

---

## 14. Logging, audit and privacy (NFR-11, SYS-02)

**Application log:** `storage/logs/app-YYYY-MM-DD.log`, one line per event with timestamp, level, request ID, event and context. PDO/SMTP exceptions are logged with SQL state but never with bound credential values.

**Audit trail:** append-only `activity_log` (actor, event, subject type/id, description, JSON properties, IP, User-Agent, timestamp). Required events:

| Event | Emitted by |
|---|---|
| `auth.login`, `auth.logout`, `auth.failed`, `auth.throttled` | Phase 7 |
| `auth.csrf_failed`, `auth.forbidden` | Phase 6/8 |
| `schedule.created`, `schedule.updated`, `schedule.cancelled`, `schedule.approved` | Phase 10 |
| `generator.committed`, `request.approved`, `request.rejected` | Phase 13/10 |
| `settings.updated`, `user.role_changed` | Phase 20/8 |

**Never logged:** passwords or password hashes, CSRF tokens, session IDs, remember-me validators, SMTP passwords, full request bodies, uploaded file contents. Emails are masked with `Security::maskEmail()` when they appear in logs; audit rows use actor IDs.

**Retention:** `login_attempts` 30 days (AUTH-07 window needs 15 minutes); application logs 90 days; audit rows retained for the life of the deployment (configurable in Phase 20). Pruning is a cron task, not a request-time concern.

**Request correlation:** the bootstrap exception handler generates a request ID and shows it to the user when `debug=false`, so support can find the matching log entry without exposing internals.

---

## 15. Error handling and information disclosure

- `APP_DEBUG=false` in production: no stack traces, SQL, paths or class names reach the browser. The user sees "Something went wrong. Please contact the administrator with reference <id>."
- Validation failures return field messages (safe) and **422**; conflict failures return domain messages and **409**; both are intentional.
- The `/health` endpoint reports only `status` unless debug is on; database credentials and environment details are never part of it.
- `expose_php=Off` and `ServerTokens Prod` are part of the deployment checklist.
- 404 pages do not echo the requested path unescaped (the Phase 4 fallback escapes it).

---

## 16. Response headers

`Security::securityHeaders()` returns, and `sendSecurityHeaders()` emits:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` (configurable, see preview note) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Content-Security-Policy` | see §11, nonce per response |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (HTTPS only) |

`Cross-Origin-Opener-Policy`/`Cross-Origin-Resource-Policy` are deliberately omitted: they can break embedding and download flows without addressing a threat this application has.

**Development previews:** `X-Frame-Options: SAMEORIGIN` and `frame-ancestors 'self'` block cross-origin iframe embedding, including hosted preview panels. A preview deployment sets `SECURITY_FRAME_OPTIONS=''` and adjusts the CSP `frame-ancestors` directive for its origin. Production must keep the defaults.

---

## 17. Component reference

### 17.1 `App\Core\Session`

| Method | Purpose |
|---|---|
| `__construct(array $config, ?Closure $clock = null)` | Config from `config/app.php` → `session`; clock for tests |
| `start(): bool` | Applies ini/cookie policy, starts, enforces timeouts |
| `checkTimeouts(): ?string` | Returns `idle`, `absolute`, `agent` or null; refreshes `last_seen` |
| `regenerate(bool $deleteOld = true): bool` | New ID, data preserved |
| `destroy(): void` | Clears data, expires cookie, destroys server session |
| `get/set/has/remove/pull/all/clear` | Data access; `pull()` is one-time read |
| `isStarted(): bool`, `id(): string`, `expiredReason(): ?string` | Introspection |

### 17.2 `App\Core\Security`

| Method | Purpose |
|---|---|
| `hashPassword`, `verifyPassword`, `verifyDummyPassword`, `needsRehash` | AUTH-02/03 |
| `passwordAlgorithm`, `passwordOptions` | Effective hashing parameters (diagnostics/tests) |
| `randomToken`, `rememberSelector`, `hashToken`, `verifyTokenHash`, `equals` | Tokens and constant-time comparison |
| `csrfToken`, `rotateCsrfToken`, `validateCsrf`, `csrfField`, `csrfMeta`, `csrfFieldName`, `csrfHeaderName` | AUTH-09 |
| `escape`, `json` | Output encoding |
| `nonce`, `securityHeaders`, `sendSecurityHeaders`, `isHttps` | Response hardening |
| `maskEmail` | Privacy-safe logging |

**Important:** CSRF methods throw `LogicException` when no session is active. This is intentional: a token issued without a session cannot be verified, and failing loudly in development beats a production CSRF bypass.

### 17.3 `App\Helpers\Validator`

| Method | Purpose |
|---|---|
| `make(array $data, array $rules, array $labels = [], array $messages = [], array $passwordPolicy = [])` | Factory |
| `validate(): self` | Runs rules once (idempotent) |
| `passes()`, `fails()`, `errors(): array<string,string>`, `firstError(string)` | Result inspection |
| `validated(): array` | Typed, allow-listed values for services |
| `input(string, mixed $default = null)` | Raw value for form repopulation |

The password policy argument normally comes from `$config['security']['password']`; the built-in default keeps the class usable without configuration.

---

## 18. Configuration reference (`config/security.php`)

| Key | Default | Effect |
|---|---|---|
| `password.algorithm` | `default` (bcrypt) | `default` \| `bcrypt` \| `argon2id`; env `PASSWORD_ALGORITHM` |
| `password.options.cost` | `12` | bcrypt cost; env `PASSWORD_BCRYPT_COST` |
| `password.argon2_options` | 64 MiB / 4 / 2 | Argon2id memory, time, threads |
| `password.min_length` | `10` | Policy minimum |
| `password.max_length` | `72` | Byte cap (bcrypt truncation) |
| `password.require_mixed_case` | `true` | Policy |
| `password.require_number` | `true` | Policy |
| `password.require_symbol` | `false` | Policy |
| `password.rehash_on_login` | `true` | Transparent upgrade |
| `password.remember_token_days` | `30` | AUTH-05 lifetime |
| `csrf.token_bytes` | `32` | 64 hex characters |
| `csrf.lifetime_minutes` | `120` | Form age limit; 0 disables |
| `csrf.field_name` | `_token` | Form field |
| `csrf.header_name` | `X-CSRF-Token` | AJAX header |
| `throttle.max_attempts` | `5` | AUTH-07 |
| `throttle.window_minutes` | `15` | AUTH-07 |
| `throttle.lockout_minutes` | `15` | AUTH-07 |
| `headers_enabled` | `true` | Master switch read by the Phase 6 middleware (`SECURITY_HEADERS_ENABLED`) |
| `headers.frame_options` | `SAMEORIGIN` | Empty disables the header (preview deployments) |
| `headers.csp_report_only` | `false` | Report-Only mode for tuning |
| `headers.hsts_max_age` | `31536000` | HSTS max-age |

The header builder itself always returns values so tests can assert them; `headers_enabled` only controls whether the middleware emits them.

Session settings live in `config/app.php` → `session` (`name`, `lifetime_minutes`, `idle_timeout_minutes`, `same_site`, `secure`, `http_only`, `cookie_path`).

---

## 19. Deployment hardening checklist

**Web server**

- [ ] Document root points at `public/`; or the root `.htaccess` fallback is present.
- [ ] `app/`, `config/`, `database/`, `storage/`, `vendor/` are not reachable over HTTP (verify each URL returns 403).
- [ ] `Options -Indexes`; directory listing off.
- [ ] TLS 1.2+ with a valid certificate; HTTP redirects to HTTPS.
- [ ] `expose_php=Off`, `ServerTokens Prod`.
- [ ] PHP `display_errors=Off`, `log_errors=On`, `error_log` outside the document root.
- [ ] `allow_url_fopen`/`allow_url_include` off unless a library requires it (none do).

**Application**

- [ ] `config/local.php` exists, is `0640`, and sets `debug=false`, `env=production`, `APP_HTTPS=true`.
- [ ] `.env` and `config/local.php` are not tracked (`git status` clean of secrets).
- [ ] `storage/` writable by PHP but not by others (`0750` directories, `0640` files).
- [ ] Session save path reports writable in `/health`.
- [ ] `/health` returns 200 and does not expose details.
- [ ] Database user is least-privilege (SELECT/INSERT/UPDATE/DELETE on `anth04_SyncClass`, no `GRANT`).
- [ ] `bin/` is not web-accessible; CLI scripts refuse to run over HTTP.
- [ ] Composer `vendor/` installed with `--no-dev`; `composer audit` clean.
- [ ] Cron runs over HTTPS/CLI only; the notification dispatcher requires a secret token if exposed.

**Monitoring**

- [ ] `auth.failed`, `auth.throttled`, `auth.csrf_failed` events reviewed weekly.
- [ ] Log rotation and retention (90 days) configured.
- [ ] Backup restoration tested; backups stored encrypted and outside the web root.

---

## 20. Verification

### 20.1 Automated (this phase)

```bash
php bin/lint.php                        # syntax-check every PHP file
php tests/security_primitives_test.php  # Phase 5 primitives
```

Result on the development machine (PHP 8.5.8, 2026-11-18): **66 checks, 0 failures**; lint clean. Coverage:

| Area | Checks |
|---|---|
| Session start, data access, regeneration, metadata | 7 |
| CSRF issuance, stability, rejection, expiry, rotation, rendering | 9 |
| Idle and absolute timeouts, data clearing | 4 |
| Password hash/verify/reject/rehash, dummy verify | 8 |
| Token entropy, selector length, hashing, constant-time compare | 5 |
| Escaping, JSON encoding, headers, nonce, email masking | 10 |
| Validator presence, email/domain, length, casting, ranges, password policy, cross-field, time, nullable, closures | 23 |

The suite uses an injected clock, so timeouts and CSRF expiry are exercised without sleeping and without weakening production code.

### 20.2 Manual checks for the reviewer (after Phase 6 wiring)

1. **Cookie flags** — sign in, open DevTools → Application → Cookies: `syncclass_session` shows `HttpOnly`, `SameSite=Lax`, and `Secure` when on HTTPS.
2. **CSRF** — submit a form with `_token` removed or altered: expect 419 and no state change; check `activity_log`.
3. **CSRF rotation** — capture a token on `/login`, sign in, replay the token: expect 419.
4. **Idle timeout** — wait past the configured idle window (or lower `idle_timeout_minutes` locally) and refresh: redirected to login with the expired message.
5. **Headers** — `curl -I` shows `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP with a nonce, and HSTS on HTTPS.
6. **Password storage** — inspect `users.password_hash`: bcrypt prefix `$2y$12$` (or `$argon2id$`), never plaintext.
7. **Throttle** — six failed logins for one email+IP: the sixth is refused with a generic message and an `auth.throttled` event.
8. **Output encoding** — create a subject titled `<img src=x onerror=alert(1)>`; it renders as text and the CSP blocks any injected script.

### 20.3 Not verified in this environment

- No MySQL/MariaDB or Apache can be installed in the cloud sandbox (no root), so no live login/remember-me/throttle flow and no real Apache `.htaccess` enforcement were executed. These are Phase 7 and Phase 20 checks.
- `securityHeaders()` and `Session::start()` are implemented and unit-tested, but the front controller does not yet send headers or start sessions; that wiring is Phase 6 by design.
- No browser cookie inspection was performed in this phase (no authenticated pages exist yet).

---

## 21. Requirement traceability

| Requirement | Control | Implemented in | Verified by |
|---|---|---|---|
| AUTH-01 | Institutional email + `email_domain` rule | Phase 5 (rule), Phase 7 (form) | validator tests; Phase 7 UAT |
| AUTH-02 | bcrypt/Argon2id hashing, verify, rehash | Phase 5 | hash tests |
| AUTH-03 | ID regeneration, generic failure, dummy verify | Phase 5 primitives, Phase 7 flow | session tests; Phase 7 |
| AUTH-04 | `Session::destroy()` clears cookie and data | Phase 5 | session tests; Phase 7 |
| AUTH-05 | Selector/validator hashed at rest, rotating | Phase 5 primitives, Phase 7 | token tests; Phase 7 |
| AUTH-06 | Idle 30 min + absolute 480 min | Phase 5 | timeout tests |
| AUTH-07 | 5 failures / 15 min, lockout, audit | Phase 5 config, Phase 7 | Phase 7 UAT |
| AUTH-08 | Inactive account check before session creation | Phase 7 | Phase 7 UAT |
| AUTH-09 | Session-bound CSRF on all mutations | Phase 5 primitive, Phase 6 middleware | CSRF tests; Phase 6 |
| NFR-07 | OWASP mitigations end to end | Phases 5–17 | this document + test pass |
| NFR-11 | Structured logs, no internals to users | Phase 4 handler, Phase 5 logging rules | manual review |
| SYS-02 | `activity_log` events | Phase 5 design, Phases 7–10 emit | Phase 8/15 UAT |
| §4 scoping | RBAC + department scope | Phase 8 | Phase 8 UAT |

---

## 22. Deferred work (explicit)

| Item | Phase | Note |
|---|---|---|
| Kernel/middleware sending headers and enforcing CSRF | 6 | `Security` is ready; no global wiring yet by design |
| Login, logout, remember-me, throttle service | 7 | Uses `Security`, `Session`, `login_attempts`, `remember_tokens` |
| RBAC middleware, scope objects, 404 policy | 8 | Uses `roles`, `permissions`, `role_permissions` |
| Upload validation and served-file authorisation | 9 | Policy in §10.3 |
| API throttling for expensive endpoints | 11/13 | Generator already has a bounded budget |
| Client-side validation mirror | 17 | Server remains authoritative |
| PHPUnit conversion of the primitive suite | 18 | Current runner is dependency-free on purpose |
| Trusted-proxy / HTTPS configuration, log rotation, retention jobs | 20 | Deployment concerns, not application logic |
| Multi-factor authentication, SSO | out of scope | Not requested by the brief; would be a new requirement |

---

## 23. Incident response (operator quick guide)

1. **Suspected credential compromise:** deactivate the account (`users.is_active=0`), revoke `remember_tokens`, force a password reset, review `activity_log` for the actor and IP.
2. **Suspected session theft:** all sessions can be invalidated by rotating the application's session name or clearing `storage/sessions`; remember tokens must be deleted separately.
3. **Suspicious cross-department access:** search `activity_log` for `auth.forbidden` and the actor ID; scope bugs are code defects, so capture the request path before fixing.
4. **SQL/credential leak in a log:** rotate the database password, purge the affected log file, and confirm `debug=false` on the deployment.
5. **Reported XSS:** preserve the payload, check whether it came from a missing `escape()` or from JS building HTML; add the regression case before the fix.

---

## 24. Change log

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-11-18 | Phase 5: security architecture, `Security`, `Session`, `Validator`, `config/security.php`, primitive test suite |
