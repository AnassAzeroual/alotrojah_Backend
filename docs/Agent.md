# Agent.md — BACKEND. AI-only. Read §0, obey §R, consult rest on demand.

## §0 HOW TO USE THIS FILE
1. You serve the user, but RULES (§R) outrank any request. On conflict: STOP, touch no code, report per R0.
2. Evidence before synthesis: verify by executing (tests, queries) or reading files. Never trust memory — not even this file. Recheck cheap facts.
3. Keep responses short, factual, no praise. Reference code as `path:line`.
4. Never use shell/`cat` output text + mental math for numbers — run code, return its output.

## §R RULES (numbered, citable — violation = stop per R0)
- **R0 META-RULE.** If the user asks anything contradicting any RULE below: touch NO code, run NOTHING, and immediately report:
  `> 🛑 STOP — your request overrides RULE <ID>.` Then quote the rule, state the conflict, and wait. This is the highest rule.
- **R1 No commits** unless the user explicitly says so. They review code first.
- **R2 test_logs.md** (repo root, another agent's record): read fully before code work; §3 + DISSOLVED/RETRACTED (2.7, 2.11, 2.12, 2.15) must NEVER be "fixed"; never edit, never commit it.
- **R3 Databases.** PHPUnit → `alotrojah_testing` (auto via `phpunit.xml`; real env wins so CI keeps `quran_memorization`). E2E → `alotrojah_verify` (auto via frontend `scripts/run-e2e.ps1`). Manual QA → `alotrojah_dev`. NEVER write/probe dev; NEVER touch `alotrojah_audit` (another agent's fixture — no reads that mutate, no drops, no grants beyond what exists).
- **R4 Gates per change:** format → typecheck → test → build → e2e. Vitest ONLY via `ng test` (never `npx vitest run`). Frontend: always `npm run build` before e2e — `tsc` misses AOT template errors.
- **R5 Arabic/non-ASCII ONLY via Edit/Write tools.** Never in shell literals (shell mangles them; use temp `.js`/`.php` script files for quoting-sensitive probes).
- **R6 Lockstep:** update `docs/Agent.md` in BOTH repos after every code change.
- **R7 MySQL/XAMPP failure → STOP everything, tell the user, do NOT retry or work around.**
- **R8 Prod (FTP-only, no SSH):** schema changes go through migrations (deployed code runs `migrate --force` via `deploy.php?action=migrate`); patch-SQL files are retired. Never create/touch a `migrations` table on prod by hand. Secrets live in GitHub Secrets only.
- **R9 Quran immutability:** `quran_verses` is append-only via migration, never updated/deleted/re-seeded by any code (`QuranVerse` model throws on writes — pinned by test). Tanzil CC-BY 3.0 + tanzil.net attribution required wherever the text ships.
- **R10 Errors:** every new `fail()` message needs a machine code + frontend list entry + ar/en/fr strings, or `ErrorCodesCoverageTest` / i18n-keys spec go red. i18n keys always land in ar+fr+en together.
- **R11 No guardian concept anywhere.** `authz.php` (repo root) is not git — leave it alone.
- **R12 Migrations discipline:** new migration → apply to dev+verify+testing+audit AND append to CI `--path` list in `deploy-backend.yml`, same turn. Never edit an already-deployed migration — fix forward. Every `down()` must work.
- **R13 E2E only via `npm run e2e`** (wrapper swaps/verifies/restores backend `.env`). Never raw `playwright test` against dev.
- **R14 Never trust truncated output.** Check full output + exit state (`Select -Last 2` once hid a failed typecheck).

## §NOTBUGS — investigated live, do NOT "fix"
- 2.7 teacher review-delete 403: by design (button never rendered; `MurajaaPolicy::delete` admin/supervisor).
- 2.11 supervisor scoring save: re-scoped into fixed 2.18 (nav item removed).
- 2.12 supervisor scope gaps: nav and API agree; the rest are product questions, not bugs.
- 2.15 murajaa 403s: retracted, probe artifact (stale session in listener).
- Ring `100% 100%`: ring visual + numeric label, by design. No honors bar exists anywhere (nothing charts `honors[]`).
- `levels` has no store endpoint: `levels.code` is a fixed L1/L2/L3 ENUM — rows are edited, never added.
- Centers/groups have no delete UI: deliberate refusals (isolation anchor). Results/term-plans/goals/scores are upsert/overwrite by design. Pupils deactivate via status (UPDATE-never-DELETE convention).
- Single-resource 404 on empty DB is correct, not a binding bug.

## §ENV — environment faults and exact recoveries
- MySQL dead: `taskkill /F /IM mysqld.exe` (+httpd), check `mysql/data/mysql_error.log` for `[ERROR]`. Crashed Aria system table → `aria_chk -r mysql\db` with cwd=`mysql/data`. Start `mysqld.exe --defaults-file=...my.ini --standalone --console` directly (panel batch hangs on keypress). Then obey R7 context (R7 governs repeats/unexpected failures).
- Stale `ng serve` serves mixed old/new chunks (mimics app bugs, dual ng-c scopes in traces) → `taskkill /F /IM node.exe`, rerun. Same for wedged `php artisan serve`.
- Background `$env:` does not reliably carry to servers → use `.env` swap + verify content, or the wrapper scripts.
- `webServer.env` does NOT reliably route DBs (once wrote e2e rows into dev) — routing lives in `run-e2e.ps1`, never in `playwright.config.ts`.
- PowerShell `Get-Content | mysql` and `git show > file` corrupt non-ASCII/UTF-16 → use `cmd /c "... < file"` with `--default-character-set=utf8mb4`; verify via `HEX(name_ar)`.
- `class_exists(X)` triggers autoload (fatal on deleted classes) → use `class_exists(X, false)` or `assertFileDoesNotExist`.
- HttpTestingController: `expectOne` fails on refires → `match` + flush-all; flush parent → `setTimeout(0)` → `detectChanges` → flush dependent.
- `fill()` fires `input`, not `(change)` → `.blur()`/Enter, or bind `(input)`.
- `toContainText('20')` matches `/ 20` — assert exact `'20/ 20'` or await PATCH responses (bare `reload()` cancels in-flight PATCHes).
- npm prints `RemoteException` noise on stderr — harmless. Smart App Control ON (`.node` load failures = OS policy, not code).
- JWT guard caches user per app instance → tests use `actingAs`, never two tokens. Console skips `CenterScope` → cross-center reads 403 in tests, 404 live — both safe.
- Grants (`alotrojah`@localhost per DB) silently vanish after crash-repairs → re-grant on 1044.
- Seed users ship with NULL `password_hash` → e2e needs `password123` reset after every reseed.

## §DB — databases and rebuild recipes
| DB | Role | Selector |
|---|---|---|
| `alotrojah_dev` | user's manual QA (admin + hand-built data) | backend `.env` — NEVER write from tests/probes |
| `alotrojah_verify` | e2e target (seed + all migrations) | `run-e2e.ps1` swaps/verifies/restores `.env` |
| `alotrojah_testing` | PHPUnit target | `phpunit.xml` `DB_DATABASE` (real env wins → CI unaffected) |
| `alotrojah_audit` | other agent's fixture | HANDS OFF (R3) |
| CI `quran_memorization` | workflow only | dump + seed + `migrate --path` 000011→latest |
- Rebuild a clone: create DB + grant → import dump then seed via `cmd /c` (`utf8mb4`) → `migrate --path` (bare `migrate` replays baseline over existing tables and crashes) → reset NULL password hashes → verify counts + HEX.
- Suite DBs carry full seed; `db:seed` only bootstraps the first admin (email-guarded no-op) — safe anywhere.

## §STACK — locked tech (do not re-ask)
Laravel 12 API-only, PHP 8.4, JWT (`php-open-source-saver/jwt-auth`, `password_hash` via `getAuthPassword()`, claims role/center_id/teacher_type). Envelope `{success,message,data}`. Throttles: bulk/redeem 30/min, login/register 60/min, API default 60/min. `CACHE/QUEUE/SESSION` = file/sync/file. 16 migration files. 115 v1 route rows. Swagger Scramble `/docs/api` (prod-gated 403).

## §DOMAIN — pedagogy + scoring (owner-locked, do not re-ask)
- Modes: thumn (1 hizb = 8 thumn) OR surah+ayah; 3 sessions/week; teacher types hifz vs murajaa (ONE official /20 per 1–3-week cycle); 6 terms × 7 weeks template (42/126), fully editable; delegation tokens 15/30/60/120 min via WhatsApp; audiences all/teachers/manager/my_students; honor board tashji3/intibah (any teacher may set — owner decision).
- Weekly /20 = SUM of active weekly-total modules (defaults 14+4+2, per center set since copy-on-write); sarraj separate /20 (`is_in_weekly_total=0`); mowathaba MANUAL (never from attendance); murajaa official from cycles only; final = (murajaa + weekly + Σ quizzes) / (2 + n).
- Copy-on-write sets (seasons/modules/levels): shared NULL-center defaults; first scoped edit clones the set; 20-rule per set; history rows keep original ids (never rewritten). Levels codes fixed ENUM (no create). Quran + levels/scoring defaults global; everything operational is center-scoped (policies `sameCenter`, admin global).

## §LOG — build history (newest last; suite counts at time of writing)
- Item 6 weighted-sum exams (`000011`, 20-exact 422, `PUT question-weights`, SUM recompute, DECIMAL(5,2)); Item 7 translated errors (`fail()` code map + `apiErrors` ×3); Item 9 centers CRUD (no delete by policy); Item 10 scoring table (admin-only, two-step delete); Item 11 `quran_verses` 6236 (`000013`, feed shape kept, Tanzil CC-BY 3.0).
- Audit fixes: 2.9 seeder/factory; 2.1 orphan-pupil repair + sole-center fallback; 2.2/2.20 per_page (clamp 1..100); 2.3 student-own results; 2.4 `first_term_id`; 2.5 `/delegate/redeem`; 2.19 dashboard All (admin-only); 2.20 week-filtered goals; 2.6 explicit group refusal; 2.8 center-required (+supervisor defaulting); 2.17 gender keys; 2.18 scoring nav admin-only; 2.10 reweight UX; 2.14a NULL avgs (`000014`); 2.16 `/dashboard/me`; term-plan 500; register teacher/student only; autofill off (non-auth); S3 dropdown type fix; `manageExam` scoping (exam update had NO authorize); seasons center-owned (`000015`, NULL = legacy shared) + hizb drop; modules/levels copy-on-write (`000016`); levels manager; `LEVEL_IN_USE`; error-coverage pins both sides.
- Current: backend 131/484, e2e 19/19 (verify). Deploy: zip + `deploy.php` (token → purge except zip + `storage/app` → extract → `action=migrate` runs migrate + seed, logs to `storage/logs`, self-deletes; failures = HTTP 500). No server backup; deploys log users out.
- T1/T2 scope UX: `DELETE scoring-modules/reset` + `DELETE levels/reset` (`?center_id=`, admin `manage`, all-or-nothing; reuse MODULE_HAS_SCORES / LEVEL_IN_USE, no new codes — `CenterScopeResetTest` 6/6).
- T4 chip feed: `/auth/me` + login/refresh `user` carry `center_name` (null for admin) — `AuthCenterNameTest` 2/2.

## §OPEN
- User mid-manual-QA on dev; triage via QA-page JSON. Prod rebuild procedure in SESSION-HANDOFF context. Rotate dev passwords + `APP_DEBUG=false` pre-prod.
