# alotrojah_Backend — Laravel 12 JSON API (PHP 8.4)

Quran-memorization management API for a Moroccan non-profit association.
Full context: `docs/Agent.md` (read first), meeting notes: `docs/questions.md`,
schema + seed + checks: `docs/database/`.

## Prerequisites

| Tool | Version | Install (Windows) |
|---|---|---|
| PHP | 8.4.x | `winget install PHP.PHP.8.4` — then fix `extension_dir` in `php.ini` (winget points it at `C:\php\ext`; set it to the real `ext\` folder) and enable: `mbstring, pdo_mysql, openssl, fileinfo, tokenizer, sodium, curl, zip` |
| Composer | 2.x | [getcomposer.org/Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe) (needs `php` on PATH first) |
| MySQL/MariaDB | 8.0 / 10.4+ | XAMPP or standalone; needs `utf8mb4_unicode_ci` support |

> Backend intentionally has **no build step** (pure API; the only `package.json` is the unused Laravel/Vite skeleton).

## Setup (fresh machine)

```powershell
# 1. Database — import IN ORDER with a UTF-8-safe method.
#    WARNING: piping via PowerShell Get-Content corrupts Arabic.
#    Use: .NET UTF-8 read -> no-BOM temp file -> mysql SOURCE with utf8mb4
mysql -u root -e "CREATE DATABASE alotrojah_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root --default-character-set=utf8mb4 -e "SOURCE <path>/quran_memorization_db.sql"
mysql -u root --default-character-set=utf8mb4 -e "SOURCE <path>/quran_seed_data.sql"
#    Verify: SELECT HEX(name_ar) FROM surahs WHERE id = 2;
#    must be D8A7D984D8A8D982D8B1D8A9 (NOT 3F...).

# 2. Dedicated DB user (never root in .env)
mysql -u root -e "CREATE USER 'alotrojah'@'localhost' IDENTIFIED BY '<strong-password>'; GRANT ALL ON alotrojah_dev.* TO 'alotrojah'@'localhost';"

# 3. App
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan jwt:secret
```

Required `.env` keys (see `.env.example`): `DB_*`, `APP_TIMEZONE=Africa/Casablanca`,
`FRONTEND_URL` + `FRONTEND_URLS` (comma-separated CORS origins),
`CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `JWT_*` (TTL 60 / refresh 20160).

Dev accounts (seeded, **dev only** — rotate before prod):
`admin@example.org`, supervisor `sup1.nour@example.org`, teachers
`teach1a/teach1c…`, murajaa `murajaa.nour@example.org`, guardian `g1@example.org` —
all password `password123`.

## Commands

```powershell
php artisan serve --port=8000          # API at http://localhost:8000/api/v1
php artisan test                        # PHPUnit: 24 tests (policies, delegation, scoring, N+1)
php artisan route:list --path=v1        # ~60 endpoints
php artisan tinker                      # REPL (bypasses CenterScope: console is unscoped)
```

## API contract (for the Angular client)

- Base: `/api/v1/...` — envelope `{success, message, data}`, paginated `data/data+meta`.
- Interactive docs: `GET /docs/api` (Swagger UI via Scramble, auto-generated;
  login first, then Authorize with the bearer token). Raw spec: `/docs/api.json`.
  On production, gate or disable docs (see `RestrictedDocsAccess`).
- Auth: JWT bearer (`POST auth/login|refresh|logout`, `GET auth/me`), claims = role/center_id/teacher_type, 60-min TTL.
- Roles: `admin` (global) · `supervisor` (per-center manager) · `teacher` (+`teacher_type` hifz/murajaa/both) · `guardian` · `student` (shared account) · `board` (read).
- Scoring (locked rules): weekly /20 = active weekly-total modules (14+4+2); sarraj separate /20 (`is_in_weekly_total=0`); mowathaba manual; murajaa official = 1–3-week cycles; final = (murajaa + weekly + term quizzes) ÷ (2 + n_terms).
- Center isolation: policies + `CenterScope` global scope; cross-center = 404 (403 in tests); delegation tokens (`groups/:id/delegations` → redeem) punch scoped holes with expiry.
- CORS: `FRONTEND_URLS` env; prod = `https://alotrojah.ma,https://www.alotrojah.ma`.

## Deploy notes (S14 will detail)

Shared host, PHP 8.4: `public/` → `public_html` binding, `storage` link, `APP_DEBUG=false`,
`config:cache` + `route:cache`, opcache on, cron for scheduler, DB backup routine.
