# Installing SyncClass on XAMPP (Windows)

Target environment: **Windows 11 · XAMPP 8.2 (PHP 8.2, Apache, MySQL/MariaDB)
· project at `D:\xampp8.2\htdocs\SyncClass` · URL `http://localhost/syncclass/`
· database `anth04_SyncClass`**.

The steps below take a clean machine to a running application. Every command is
run from the project root unless stated otherwise.

---

## 0. Prerequisites

| Requirement | Notes |
|---|---|
| XAMPP 8.2 | Apache + MySQL started from the XAMPP Control Panel |
| PHP 8.1+ | `php -v` — must show 8.1 or newer (8.2 is what ships with XAMPP 8.2) |
| PDO MySQL, mbstring, json, openssl | enabled by default in XAMPP; `/health` reports any that are missing |
| Composer | <https://getcomposer.org/download/> — needed for `composer install` |
| Git for Windows | only for the Git-based deployment route |
| PHPMailer (optional) | required only when you enable SMTP email |

---

## 1. Get the code

**Git route (recommended — this is how future updates arrive):**

```bash
cd /d D:\xampp8.2\htdocs
git clone https://github.com/vinc3nt-arerbac/SyncClass.git SyncClass
cd SyncClass
git checkout v1.0.0        # or stay on main
```

**ZIP route:** extract the release archive so that `index.php`, `app/`,
`public/` … sit directly inside `D:\xampp8.2\htdocs\SyncClass`.

Either way the folder layout must be:

```
D:\xampp8.2\htdocs\SyncClass\
├── app\  config\  database\  docs\  public\  routes\  storage\  vendor\
├── index.php  bootstrap.php  composer.json
```

If `vendor\` is missing after a clone (it is git-ignored), install it:

```bash
composer install --no-dev --optimize-autoloader
```

> `composer install` needs a `composer.lock`; run it from the project root so the
> autoloader lands in `SyncClass\vendor\`.

---

## 2. Copy the environment file

```bash
copy .env.example .env
```

Then edit `.env` in Notepad. The minimum for XAMPP is usually nothing at all —
the defaults already point at `127.0.0.1:3306`, user `root`, empty password and
`anth04_SyncClass`. Change these lines only if your MySQL differs:

```ini
APP_URL=http://localhost/syncclass
APP_DEBUG=false
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=anth04_SyncClass
DB_USERNAME=root
DB_PASSWORD=
```

`.env` is git-ignored: it never enters the repository and `git pull` never
overwrites it. (A `config/local.php` override file works the same way and is
merged last if you prefer PHP config.)

---

## 3. Create the database

Open phpMyAdmin (<http://localhost/phpmyadmin>) → **Databases** → name
`anth04_SyncClass` → collation `utf8mb4_unicode_ci` → **Create**.

Or from a terminal:

```bash
D:\xampp8.2\mysql\bin\mysql.exe -u root -e "CREATE DATABASE anth04_SyncClass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

---

## 4. Import the complete SQL export

This single file creates all 30 tables (with indexes and foreign keys), the
permission catalogue and role matrix, the 20 settings rows, the demo accounts and
the demo timetable. It is self-contained — do **not** also import
`database/schema/SyncClass.sql`.

**phpMyAdmin:** select `anth04_SyncClass` → **Import** → choose
`database\schema\syncclass_complete.sql` → **Import**. (The file is ~115 KB;
`max_execution_time` defaults are enough.)

**Command line:**

```bash
D:\xampp8.2\mysql\bin\mysql.exe -u root anth04_SyncClass < database\schema\syncclass_complete.sql
```

The export creates the database only if it is missing; importing into a **new,
empty** database is the supported path. Importing twice duplicates the demo data
and fails on the first unique key — drop and recreate the database to start over.

---

## 5. Configure database credentials

Nothing to edit if step 2 and step 3 used the same values. Verify:

```bash
php -r "require 'bootstrap.php'; $c = require 'config/database.php'; echo $c['database'], PHP_EOL;"
```

---

## 6. Launch the application

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open <http://localhost/syncclass/>.
3. Sign in with a demo account (password for all four: `SyncClass2026!`):

| Email | Role | Sees |
|---|---|---|
| `admin@sac.edu.ph` | System Administrator | everything |
| `dean.csm@sac.edu.ph` | Dean — CSM | own department, approvals |
| `secretary.csm@sac.edu.ph` | Department Secretary | own department timetable |
| `jcruz@sac.edu.ph` | Faculty — CSM | own schedule and availability |

Health check: <http://localhost/syncclass/health> returns JSON and `200` when
every required extension and the database connection are fine (`503` otherwise).

### Optional: prettier URLs with a virtual host

Point a virtual host's `DocumentRoot` at `D:\xampp8.2\htdocs\SyncClass\public`
and set `APP_URL=http://syncclass.local`. Serve from `public/` when you can: it
keeps `app/`, `config/`, `storage/` and `.env` outside the web root. The root
`index.php` + `.htaccess` pair is the fallback for shared htdocs installs and is
what `http://localhost/syncclass/` uses.

### Optional: email notifications

In-app notifications work with no configuration. To also send mail, set
`MAIL_ENABLED=true` plus `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` in `.env`, install
PHPMailer (`composer require phpmailer/phpmailer` — already required in
`composer.json`), then schedule the dispatcher:

```bash
php bin\dispatch-notifications.php
```

Register it in Windows **Task Scheduler** every 5 minutes, or call it from cron
on a Linux host.

---

## 7. Updating an existing install (Git workflow)

All future releases arrive by `git pull` into `D:\xampp8.2\htdocs\SyncClass`:

```bash
cd /d D:\xampp8.2\htdocs\SyncClass
git pull origin main
composer install --no-dev --optimize-autoloader
```

Then, **only if the release notes say the schema changed**:

```bash
D:\xampp8.2\mysql\bin\mysql.exe -u root anth04_SyncClass < database\migrations\NNN_name.up.sql
```

What `git pull` does **not** touch (all git-ignored, so your settings survive):

- `.env` and `config/local.php` — credentials and machine settings
- `storage/logs`, `storage/cache`, `storage/sessions`, `storage/exports`
- `vendor/` — reinstalled by `composer install`
- `public/assets/uploads/` — user-uploaded files

The database is not in Git either. Take a backup before a schema migration:

```bash
D:\xampp8.2\mysql\bin\mysqldump.exe -u root anth04_SyncClass > backup_before_update.sql
```

---

## 8. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `SyncClass is not installed yet` | `vendor\autoload.php` missing → run `composer install` |
| `404` on every page except `/` | Apache `mod_rewrite` off, or `AllowOverride` is `None` for `htdocs` → the bundled `.htaccess` needs `RewriteEngine On` |
| Blank page / 500 | set `APP_DEBUG=true` in `.env` temporarily, reload, then read `storage\logs\app-YYYY-MM-DD.log` |
| `SQLSTATE[HY000] [1049] Unknown database` | the database name in `.env` does not match the one created in step 3 |
| `SQLSTATE[HY000] [1045] Access denied` | wrong `DB_USERNAME`/`DB_PASSWORD`; XAMPP's default root password is empty |
| Login says "invalid credentials" after import | the demo accounts come from the export — confirm the import ran on the database named in `.env` |
| Session/cookie errors behind a proxy | set `APP_HTTPS=true` and use the same host name you set in `APP_URL` |
| `/health` reports a missing extension | enable it in `D:\xampp8.2\php\php.ini` (e.g. `extension=pdo_mysql`), restart Apache |

Reset the demo data at any time (recreates the demo rows idempotently — run
against an empty database for the documented counts):

```bash
php bin\sync-rbac.php        # permission catalogue + role matrix
php bin\seed-demo.php        # demo departments, faculty, catalogue, timetable
```

---

## 9. Post-install checklist

- [ ] Apache and MySQL running; `http://localhost/syncclass/health` returns 200
- [ ] Login works for each of the four demo roles
- [ ] `.env` exists, `APP_DEBUG=false`, and `.env` is **not** in `git status`
- [ ] `storage\logs`, `storage\cache`, `storage\sessions`, `storage\exports` are writable
- [ ] `vendor\` installed
- [ ] Database imported from `database\schema\syncclass_complete.sql`
- [ ] Backup taken (`mysqldump`) before the first production use
- [ ] SMTP configured, or email left disabled on purpose
- [ ] `git log --oneline -1` shows the release commit you expect
