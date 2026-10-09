# St. Benedict's Early Years British Academy

School website and management portal for a nursery/primary school in Enugu, Nigeria. Plain PHP 8 + MySQL/MariaDB, no framework and no Composer, so it runs on ordinary cPanel hosting.

Live site: https://stbenedictsacademy.com.ng

## Features

**Public website** (fully editable by the admin, no code needed)
- Home page with slider, About, Academics, Admissions, Contact, News & Events, Gallery, privacy notice
- Online application form with private document upload
- Chat assistant that answers questions about fees, classes, admissions and events from live school data
- Extra pages (Fees, Calendar, Careers...) created from the admin panel and shown in the menu

**Portals** (role based: Admin, Teacher, Student, Parent)
- Students, parents, teachers, classes, subjects, timetable, attendance
- Fees, payments and printable receipts; results and report cards; homework
- Announcements, messages, notifications, audit log, CSV/Excel export, reports
- **Website management:** Site Content, Home Slider, Extra Pages, News & Events, Gallery

**Security and privacy**
- bcrypt passwords, CSRF protection, prepared statements, output escaping, ownership checks on every record
- Per-account lockout and per-IP login throttling; optional two-step verification (authenticator app)
- Content-Security-Policy with self-hosted scripts, fonts and styles (no CDNs); HTTPS and security headers
- Upload validation, private storage for admission documents, audit logging
- Privacy notice, pupil data export/erase, automatic data-retention clean-up

**Operations**
- Clean URLs (no `.php`), mobile-first responsive layout
- SMTP email with fallback, `/health` endpoint, backup, restore and migration scripts
- GitHub Actions CI

## Quick start (development)

Requirements: PHP 8.1+ (pdo_mysql, mbstring, fileinfo, gd), MySQL 5.7+/MariaDB 10.3+.

```bash
mysql -u root -e "CREATE DATABASE st_benedicts_academy CHARACTER SET utf8mb4"
mysql -u root st_benedicts_academy < sql/database.sql
php tests/seed.php                      # optional demo users (password Test@12345)
php -S 127.0.0.1:8080 router.php        # then open http://127.0.0.1:8080
```

Default admin from the schema: `admin@stbenedicts.edu.ng` / `Admin@123` - **change it immediately**.

## Deploying to the live site

See [DEPLOYMENT.md](DEPLOYMENT.md): `config/local.php`, SMTP, cron jobs (backup, retention), restore, monitoring, the cPanel Git flow (`.cpanel.yml`) and upgrading an existing database with `php scripts/migrate.php`.

## Tests

```bash
php tests/unit_test.php          # unit tests
php tests/chatbot_test.php       # chat assistant
python3 tests/smtp_test.py       # mailer
python3 tests/e2e.py             # ~320 end-to-end checks (resets the dev database)
bash tests/crawl.sh              # every page, every role
node tests/mobile/audit.js       # overflow/console audit on phone, tablet, desktop
```

CI runs the same on every push (`.github/workflows/ci.yml`).

## Documentation

| File | Contents |
|---|---|
| [DEPLOYMENT.md](DEPLOYMENT.md) | Install, upgrade, cron, backups, monitoring |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Structure, request flow, security model, content management |
| [docs/API.md](docs/API.md) | JSON endpoints |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Coding rules |
| [SECURITY.md](SECURITY.md) | Reporting vulnerabilities, operational checklist |

## Editing the website

Sign in as admin, then use **Site Content** (wording, pictures, contact details, social links), **Home Slider**, **Extra Pages**, **News & Events** and **Gallery** in the sidebar. Anything you leave unchanged keeps its built-in default.
