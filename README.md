# alotrojah_Backend — Laravel 12 API (PHP 8.4)

## Setup (Windows dev)
1. PHP 8.4 + Composer on PATH. Required ext: mbstring, pdo_mysql, openssl, fileinfo, tokenizer, sodium.
2. MySQL DB `alotrojah_dev` (`utf8mb4_unicode_ci`) + limited user. Import in order:
   `docs/database/quran_memorization_db.sql`, then `quran_seed_data.sql`.
3. `Copy-Item .env.example .env`, set `DB_*`, `APP_TIMEZONE=Africa/Casablanca`,
   `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, then:
   `php artisan key:generate` (JWT: `php artisan jwt:secret`, TTL 60 / refresh 20160).
4. `php artisan serve --port=8000` → `POST /api/v1/auth/login`
   (dev admin: `admin@example.org` / `password123` — dev only).

## Layout
- `app/Enums/` — DB ENUMs as string enums with Arabic `label()`.
- `app/Models/` — 27 Eloquent models (schema-generated; `User` hand-written for JWT,
  password lives in `password_hash`).
- `app/Http/{Controllers/Api/V1,Requests,Resources,Middleware}` — thin controllers,
  FormRequest validation, shaped Resources, `ForceJsonResponse` on `api` group.
- `app/Services/` — `ScoringService` (weekly /20, R2 sarraj, ÷(2+n) final),
  `SeasonTemplateService` (42-week generator), `DelegationService`, `DashboardService`.
- `app/Policies/` (+`Concerns/CenterScoped`) — center isolation; wired in `AppServiceProvider`.
- `app/Observers/SessionScoreObserver` — dashboard cache bust.
- `routes/api.php` → `/api/v1/...` (auth done; resources land S6–S10).

## Rules (from questions.md — do not break)
- Weekly total /20 = active weekly-total modules only; sarraj separate /20.
- Mowathaba manual; murajaa official = `murajaa_reviews` cycles (1–3 weeks).
- No hard-coded term counts; never auto-derive scores.
