# Deployment & Testing

## Requirements
PHP 8.1+ (pdo_mysql, mbstring, fileinfo, gd), MySQL 5.7+/MariaDB 10.3+, Apache with `mod_rewrite`, `mod_headers` (or any server that honours the `.htaccess` rules).

## Install
1. Create the database and import the schema:
   `mysql -u root -p < sql/database.sql`
2. Configure the environment (nothing secret lives in git). Either export environment variables or create `config/local.php` (git-ignored):
   ```php
   <?php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'st_benedicts_prod');
   define('DB_USER', 'app_user');
   define('DB_PASS', 'a-strong-password');
   define('ENVIRONMENT', 'production');   // or set APP_ENV
   // define('BASE_URL', 'https://school.example.com'); // optional; auto-detected otherwise (APP_URL env also works)
   ```
3. Make `uploads/`, `storage/` and `logs/` writable by the web server. `storage/` (admission documents) is never served directly.
4. Log in as `admin@stbenedicts.edu.ng` / `Admin@123` and **change the password immediately**.
5. Schedule backups: `php scripts/backup.php` (CLI only) from cron.

Mail (password resets, confirmations) uses PHP `mail()`; configure sendmail/SMTP relay on the host.

## Upgrading the live site (stbenedictsacademy.com.ng)
1. **Back up first**: export the database (phpMyAdmin or `mysqldump`) and download the whole `uploads/` folder. The new version stops tracking uploaded files in git, so a `git pull` on the server can remove tracked files from `uploads/` - restoring your backup afterwards puts them back.
2. Put the new code on the server (git pull of this branch, or upload the files). Keep the existing `uploads/` contents.
3. Create `config/local.php` from `config/local.php.example` with the live database details.
4. Upgrade the database (adds the new tables/columns, keeps all data; safe to run twice):
   `php scripts/migrate.php --dry-run` then `php scripts/migrate.php`
   (no SSH? ask the host for a one-off cron job / "Run PHP script" in cPanel, or ask me for a phpMyAdmin SQL version).
5. Make sure `uploads/`, `storage/` and `logs/` are writable, and that the host allows `.htaccess` overrides (`AllowOverride All`, `mod_rewrite`).
6. Admission documents uploaded under the old version are in `uploads/admissions/`; new ones go to the private `storage/applications/`. Add `uploads/.htaccess` (already in the repo) so PHP can never run from `uploads/`.
7. Check: `/login`, an admin page, `/public/apply`, and the chat assistant. Log in as admin and change any default passwords. Delete the old `test.php`/`hash.php` from the server if they still exist.

## Clean URLs (no `.php`)
Pages are served without the extension (`/admin/students`, `/public/apply`, `/api/chatbot`). On Apache this is done by the rewrite rules in `.htaccess` (`mod_rewrite` and `AllowOverride All` required); old `.php` addresses redirect with a 301. For local development run `php -S 127.0.0.1:8080 router.php`, which applies the same rules.

## Tests (development only)
Needs a local MariaDB/MySQL, `php -S 127.0.0.1:8080 router.php`, and Node + Playwright for the mobile audit.

| Command | Checks |
|---|---|
| `php tests/seed.php` | Loads demo users (password `Test@12345`) |
| `tests/crawl.sh` | Every page as every role: no PHP errors, CSS/JS present, role separation |
| `python3 tests/e2e.py` | ~230 end-to-end checks (CRUD, uploads, API authorization, IDOR, lockout, password reset). Resets the DB first |
| `python3 tests/sqlcheck.py` | Tables/columns used in SQL exist in the schema |
| `python3 tests/linkcheck.py` | No broken internal links |
| `python3 tests/escape_scan.py` | Unescaped template output |
| `php tests/models_smoke.php` | Model classes run their read methods |
| `node tests/mobile/audit.js [--width=375]` | Horizontal overflow audit on every page (phone/tablet/desktop) |

The `tests/` directory is blocked by `.htaccess`; remove it from production.
