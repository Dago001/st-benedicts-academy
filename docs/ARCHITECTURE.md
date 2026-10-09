# Architecture

Plain PHP 8 + MySQL/MariaDB, no framework and no Composer dependencies. Deliberately small so it runs on any shared host.

## Request flow
```
Browser -> Apache (.htaccess: HTTPS redirect, clean URLs, blocked dirs)
        -> <area>/<page>.php  (login.php, admin/*, teacher/*, student/*, parent/*, public/*, api/*)
        -> config/config.php   constants, security headers + CSP nonce, output buffering
        -> config/security.php sessions, CSRF, roles, throttling, uploads, audit log
        -> config/database.php PDO wrapper (prepared statements only)
        -> includes/header.php + layout.php (navigation) ... includes/footer.php
```
`router.php` mimics the `.htaccess` rules for `php -S` development.

## Layout
| Path | Purpose |
|---|---|
| `config/` | Constants, environment detection, `Database`, `Security` (static helpers) |
| `includes/` | `Auth` (login + 2FA), `Totp`, `Mailer`, model classes (`Student`, `Fee`, `Result`, ...), shared page renderers (`profile_page`, `receipt`, `report_card`), `SchoolBot` chatbot, `helpers.php` |
| `admin/ teacher/ student/ parent/` | Role portals. Every page starts with `Security::requireRole()` |
| `public/` | Marketing pages, application form, privacy notice |
| `api/` | JSON endpoints, all started with `api_init($methods, $roles)` (method + role + CSRF checks) |
| `storage/` | Private files (admission documents) - served only through an authorised admin page |
| `uploads/` | Public images; PHP execution disabled by `.htaccess` |
| `assets/vendor/` | Self-hosted jQuery, Chart.js, DataTables, Font Awesome, fonts, SheetJS, Fancybox |
| `scripts/` | CLI only: `migrate`, `backup`, `restore`, `retention` |
| `sql/database.sql` | Full schema for a fresh install |
| `tests/` | Unit, end-to-end, crawl, chatbot, mobile audits |

## Content management
Admins edit the public site from **Site Content** (text/images registered in `includes/cms.php`, saved in `site_content`; blank = built-in default), **Home Slider** (`hero_slides`), **Extra Pages** (`site_pages`, rendered by `public/page`), **News & Events** and **Gallery**. To make another piece of text editable, add a field to `cms_registry()` and print it with `cms_e('group.key')`. Contact details come from `school_phone()/school_email()/school_address()/school_hours()`.

## Security model
* **AuthN**: bcrypt (cost 12, rehash on login), per-account lockout, per-IP throttle (`login_throttle`), optional TOTP 2FA, session id regeneration on login, 2 h idle timeout, `HttpOnly` + `SameSite=Lax` (+ `Secure` on HTTPS) cookies.
* **AuthZ**: role gate on every page/endpoint plus ownership checks (`Security::canAccessStudent/Class`, `currentTeacherId/ParentId/StudentId`) to prevent IDOR.
* **Input**: PDO prepared statements with typed binding; CSRF token on every state-changing request; uploads validated by extension, finfo MIME and `getimagesize`.
* **Output**: `e()` escaping on display; CSP with per-request nonce for scripts, `object-src 'none'`, `frame-ancestors 'self'`, plus HSTS, nosniff, Referrer-Policy, Permissions-Policy.
* **Audit**: sensitive actions are written to `audit_logs`.
* **Known trade-off**: ~130 legacy inline event handlers (`onclick=`) and inline styles remain, so the CSP allows `script-src-attr 'unsafe-inline'` and `style-src 'unsafe-inline'`. Injected `<script>` tags are still blocked. Removing these means refactoring each page's markup to `addEventListener`/classes.

## Conventions
* Post/Redirect/Get via `flash_set()/flash_redirect()`.
* Links never include `.php`.
* Dates stored in `Africa/Lagos` local time.
* Grade scale lives only in `letterGrade()`.
