# Agent.md — Project Reference (Quran Memorization App)

> Read this file first in any new session. It holds every decision made so far.
> Details of the meeting live in `questions.md` (same folder). SQL files live on Desktop.

## 1. What this is
- App for a **non-profit Quran memorization association in Morocco** (one association, currently one center; DB supports many).
- Digitizes a paper **memorization logbook** ("البرنامج المقترح لحفظ القرآن الكريم — فئة غير المتفرغ").
- Reference inspiration: `https://ahlquran.com/`. Domain bought: **`https://alotrojah.ma/`**.
- Users: association **manager** (PC), **3+ teachers** (smartphones only, 3G/4G + *6 social pack), **students + guardians share ONE account** (same interface).
- Maintainer after delivery: the developer (me). MVP deadline: **1 month**.

## 2. Tech decisions (locked)
- **Backend: Laravel** (API). **Frontend: Angular**, mobile-first UI (teachers on phones).
- **DB: MySQL 8**, `utf8mb4 / utf8mb4_unicode_ci`. Import: `quran_memorization_db.sql` then `quran_seed_data.sql`, verify with `quran_check_queries.sql`.
- **i18n: ngx-translate**, 3 JSON files `ar` (default first), `fr`, `en`.
- **No paid WhatsApp API.** Use `wa.me/<phone>` deep links with guardian/student numbers from DB (WhatsApp is effectively free for them).
- Future Laravel work goes in **Laravel migrations** (the SQL migration chain v1→v5 was consolidated away; do NOT recreate/delete those files).

## 3. Pedagogy model (from manager + teachers — do not re-ask)
- **Two student types:** children (+4) and adults (+18) → `students.student_type`.
- **Two memorization modes per student:** by **thumn** (1 hizb = 8 thumn; also rubu/nis f variants) or by **surah+ayah range** (e.g. Baqarah 1–5, full Nas) → `students.memorization_mode`, `memorization_logs.log_mode`.
- **Weekly goal, not day counting:** 3 sessions/week default (Mon/Wed/Fri, editable per group → `groups.schedule_days`); teacher checks goal completion → `weekly_goals.is_completed`.
- **Two teacher types:** `hifz` (enters hifz/tajwid/mowathaba/sarraj per session) and `murajaa` (enters ONE official review score /20 per cycle covering **1–3 weeks**, depending on memorized amount) → `users.teacher_type`, `murajaa_reviews` (span enforced by CHECK). Same page, inputs enabled/disabled by type.
- **Review week:** default template = **6 terms × 7 weeks (6 study + 1 review/quiz) = 42 weeks / 126 sessions**. Fully editable rows (`weeks.week_type`, `sessions.session_type`); manager may do e.g. 4+1. Template = Laravel "new season" button, NOT database constants. Any term count allowed (7+).
- **Delegation:** responsible teacher generates time-limited token link (15/30/60/120 min) sent via WhatsApp so another teacher can enter marks → `delegation_tokens`.
- **Announcements page** with audience `all / teachers / manager / my_students` → `announcements`.
- **Honor board** (`tashji3/انتبه`): decided by teacher AND manager; gifts + guardian called; final notes sent via WhatsApp.

## 4. Scoring rules (locked — do not re-ask)
- Weekly total **always /20** = SUM of **active** weekly-total modules. Default: **hifz 14 + mowathaba 4 + tajwid 2**. Manager adjusts per-module maxes on a manager-only page; guardian view `v_scoring_check` must show weekly_total = 20.0.
- **Modular system:** new books (e.g. **السراج في بيان غريب القرآن**) = new `scoring_modules` rows. **R2 adopted:** sarraj is scored per session like hifz but has its **own separate /20 total** (`is_in_weekly_total=0`); weekly average = simple mean of its per-session /20 scores. Inactive modules are grayed/disabled in Angular AND excluded from every view (`is_active=1` filters).
- **Mowathaba /4 is MANUAL per-session input** by the hifz teacher. Never auto-derive from `attendance` (that table is an independent presence register).
- **Murajaa official score /20** comes from `murajaa_reviews` cycles (numeric only — the old جيد جدا/جيد scale is dead). `revision_logs` = optional per-session practice only.
- **Final average formula:** `(avg_murajaa + avg_weekly + SUM(term quiz avgs incl. final)) / (2 + n_terms)` → default 6 terms = ÷8. Computed in Laravel from `v_student_season_avgs` + `v_term_quiz_avgs`. Never hardcode term count.
- Exams: flexible question count; questions generatable as starting ayat from a thumn/hizb, teacher/manager reorders (`sort_order`).

## 5. Database map (25 tables, 11 views)
- **People/org:** `users` (roles: admin/supervisor/teacher/examiner/guardian/board; id=1 global admin, `center_id` NULL), `centers` (exactly 3 in seed, isolated), `levels` (L1/L2/L3 from book), `groups`, `guardians`, `students`.
- **Calendar:** `academic_seasons`, `terms`, `weeks`, `sessions`.
- **Planning:** `term_plans` (thumn range OR surah range via `plan_mode`), `weekly_goals`.
- **Scoring core:** `scoring_modules`, `session_scores` (UNIQUE student×session×module), `memorization_logs` (amounts + ranges only, NO score columns), `revision_logs`, `murajaa_reviews`, `attendance`.
- **Exams/reports:** `exams`, `exam_questions`, `term_results`, `season_results`.
- **Features:** `delegation_tokens`, `announcements`, `notifications_log`.
- **Reference:** `surahs` (114 seeded), `quran_hizb_reference` (60).
- **Views (Chart.js):** `v_session_totals`, `v_weekly_progress`, `v_weekly_murajaa`, `v_attendance_rate`, `v_term_quiz_avgs`, `v_student_season_avgs`, `v_separate_module_avgs`, `v_murajaa_cycles`, `v_scoring_check`, `v_season_dashboard`, `v_announcements_feed`.
- **Isolation invariant:** every operational row belongs to exactly ONE center (`users.center_id` scalar; students/groups linked). Audit queries A2/A3/A4 in check file must return 0 rows.

## 6. Seed demo data (what's in the DB now)
- 3 centers (Nour/Algiers, Forqan/Oran, Ihsan/Constantine), 19 users, 5 groups, 7 guardians, 7 students (3+2+2).
- Students 3 (Baqarah) and 6 (Nas) demo **surah mode**; rest thumn mode.
- Sessions 1–6 logged (42 logs, **168** session_scores incl. sarraj), 3 murajaa cycles by review teacher id=19, mixed attendance (1 absent, 1 late, 1 excused in center 3), 10 exams / 44 questions, 7 term + 7 season results, 1 token, 3 announcements, 4 goals, 2 notifications.
- Center tiers for chart testing: center1 high (~18), center2 medium (~14), center3 low (~12).

## 7. Conventions for code work
- Amounts: hizb `DECIMAL(4,1)` (1.0–60.0), thumn amounts `DECIMAL(5,2)`; 1 hizb = 8 thumn.
- Scores `DECIMAL(4,1)`; exam question scores `DECIMAL(4,2)`; all bounded 0–20 (or module max).
- Every fact row carries `student_id, season_id, term_id, week_id, session_id, (log_)date` — keep this chain on any new fact table.
- Qualitative book checkboxes are `ENUM`s; measurable things are numeric. Never store plannable quantities as free text.
- Status/type changes by UPDATE, never DELETE (students, users, groups).
- New schema changes → **Laravel migration files**, keep SQL dump files untouched unless explicitly asked to regenerate.

## 8. Open / pending (not blockers)
- Real student Excel from association (pending) → import script; live season already started (fasl 1 ends ~3 weeks after meeting).
- Old paper دفتر totals: import only if manager provides them, else fresh start.
- Cross-center comparison pages: allowed (used to motivate students via WhatsApp) with PDF/image export + WhatsApp share button.
- Reports printable like book pages, but freedom to improve layout.

## 9. Backend build log (S1 → …)
- **S1 (done):** PHP 8.4.25 (winget) + Composer 2.10.3. Gotchas: winget PHP `extension_dir` pointed at `C:\php\ext` (fixed in php.ini); enabled mbstring/pdo_mysql/openssl/fileinfo/tokenizer/sodium/curl/zip. Shell sessions load stale PATH → prepend real dirs every call. User terminal is fine after restart (registry PATH correct).
- **S2 (done):** `alotrojah_dev` on XAMPP MariaDB 10.4.32 (root/no-password). Dedicated user `alotrojah` (see S3 summary for password — stored in backend `.env`, never root). **Encoding lesson:** importing SQL via PowerShell `Get-Content | mysql` DESTROYS Arabic (stored as `?`, HEX `3F…`). Always import via .NET UTF-8 read → no-BOM temp file → mysql `SOURCE` with `--default-character-set=utf8mb4`. Verify with `SELECT HEX(name_ar) FROM surahs WHERE id=2;` → must be `D8A7D984D8A8D982D8B1D8A9`. If the main HeidiSQL DB shows `3F…`, re-import it the same way.
- **S3 (done):** Laravel 12.69.2 in `alotrojah_Backend` (temp-dir + move, `.git`/docs kept). `.env`: `alotrojah_dev`, timezone `Africa/Casablanca`, `CACHE_STORE=file` + `QUEUE_CONNECTION=sync` (L12 DB defaults need framework tables that don't exist). PowerShell `-replace` needs `(?m)` + `#\s?` for commented `.env` lines.
- **S4 (done):** 18 enums (DB values + Arabic `label()`), 27 schema-generated models (fillable/casts/relations/scopes; hand-fixed: relation-class bug, missing `center()` relations where no FK exists). Base API controller, `ForceJsonResponse`, real `ScoringService` / `SeasonTemplateService` (42-week) / `DelegationService` / `DashboardService`, `CenterScoped` + Student/Group policies, Student request/resource exemplars, `SessionScoreObserver`, `/api/v1` skeleton.
- **S5 (done, live-tested):** JWT via `php-open-source-saver/jwt-auth` (needed `ext-sodium`). Password column is `password_hash` → `User::getAuthPassword()` overridden; claims = role/center_id/teacher_type. login/me/refresh/logout + 401s verified. L12 `redirectGuestsTo` default calls `route('login')` → overridden to `null` for JSON 401s.
- **S6 (done, live-tested):** users/centers/groups/students/guardians CRUD, center auto-forcing, cross-center 403s, validation 422s. Fixed: `center_id` must be nullable in store rules (controller forces it); added missing relations.
- **S7 (done, live-tested):** bulk attendance + module scores (max from DB rows, murajaa-teacher 403, all-or-nothing), follow-up page endpoint, weekly-goal upsert. Sarraj correctly excluded from /20 total (18.0 for 13+3+2+17).
- **S8 (done, live-tested):** season template generation, 42-week default verified (mini-template counts exact), activate switching, module bulk rebalance guarded to sum 20 (422 + rollback), term-plan hizb/ayah validation, reference feeds. **Rebuilt dev DB once** due to the encoding lesson above; dev passwords re-set (admin/supervisor/teachers/murajaa = `password123`, dev only).
- **S9 (done, live-tested):** murajaa cycles (span 1–3 enforced, hifz-teacher 403) + revision practice rows; exams (examiner role conducts) + flexible bulk questions with dup/ayah validation and auto-recomputed `overall_avg`; term/season result upserts (honor teacher+manager); delegation full cycle — generate (responsible only) → redeem (binds first teacher) → cross-center score allowed → revoke → blocked again. `DelegationService::canActAs` integrated into `ScoreEntryService`. Fixed: `Exam::questions()` alias (generator named it `examQuestions`), generated-column `.fresh()` for `weeks_covered`, GroupPolicy `generate` alias (Gate resolves policy by model class). Test-only: login throttle is 6/min (hit it during rapid tests).
- Dev passwords: admin id=1, supervisor id=2, teachers id=3,7, murajaa id=19, examiner id=5 → all `password123` (DEV ONLY, rotate before prod).
