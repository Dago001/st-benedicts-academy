# JSON API

Base path `/api/<name>` (no `.php`). All requests need the session cookie. Every non-GET request must carry the CSRF token, either as a `csrf_token` field or an `X-CSRF-Token` header. Get a token with `GET /api/auth?action=csrf`. Errors use `{"success": false, "message": "..."}` with an HTTP status (401 not signed in, 403 wrong role, 405 wrong method, 419 bad CSRF, 429 rate limited).

| Endpoint | Methods | Roles | Notes |
|---|---|---|---|
| `auth` | GET, POST | public | `action=csrf` (GET), `login`, `verify_2fa`, `logout`, `check_session`. `login` returns `{needs_2fa:true}` when the account has two-step verification; then POST `verify_2fa` with `code` |
| `chatbot` | POST | public | `{message}` -> `{reply, suggestions}`; 40 requests / 10 min per client |
| `attendance`, `results`, `notifications` | GET, POST | signed in | Row-level ownership enforced per role |
| `fees` | GET, POST | admin, parent, student | |
| `generate-login`, `update-subject-assignment` | POST | admin | |
| `get-audit-log` | GET | admin | |
| `get-class-roster`, `get-student-details`, `get-teacher-subjects` | GET | admin, teacher | Teachers limited to their own classes/students |
| `get-assignment` | GET | teacher | |

Health check for monitors: `GET /health` (no auth) -> `200 {"status":"ok"}` or `503`.
