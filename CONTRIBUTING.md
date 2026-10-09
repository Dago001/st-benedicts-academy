# Contributing

1. Branch from `main`; keep changes small and focused.
2. Run the tests before pushing (see "Tests" in `DEPLOYMENT.md`): `php tests/unit_test.php`, `php tests/chatbot_test.php`, `python3 tests/e2e.py`, `bash tests/crawl.sh`. CI runs the same.
3. Rules for code in this repo:
   * Database access only through prepared statements; never concatenate user input into SQL.
   * Escape every value printed into HTML with `e()`.
   * Every form/POST handler verifies `Security::verifyCSRFToken()`; every page calls `Security::requireRole()`, and per-record access uses the ownership helpers.
   * New inline `<script>` tags must include `nonce="<?php echo CSP_NONCE; ?>"`; prefer external files in `assets/js/`.
   * Third-party front-end libraries are vendored in `assets/vendor/` (no CDNs).
   * Links omit `.php`. Schema changes go into both `sql/database.sql` and `scripts/migrate.php` (idempotent).
4. Never commit secrets; use `config/local.php` (git-ignored) or environment variables.
