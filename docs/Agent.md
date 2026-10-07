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
- **R2 RETIRED** (test_logs.md deleted per user 2026-10-06 — number kept stable so all R3+ citations hold).
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
- **R15 Dead code.** After every change, hunt what it orphaned: unused imports/symbols, unreachable branches, orphaned routes/keys/files/temp scripts. Verify by grepping every usage surface (code, specs, e2e, i18n, CI) — never assume. Remove it or justify it in the report. (Frontend twin: R11.)

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
- Current: backend 137/508, e2e 34/34 (verify, global roadmap suite). Deploy: zip + `deploy.php` (token → purge except zip + `storage/app` → extract → `action=migrate` runs migrate + seed, logs to `storage/logs`, self-deletes; failures = HTTP 500). No server backup; deploys log users out.
- T1/T2 scope UX: `DELETE scoring-modules/reset` + `DELETE levels/reset` (`?center_id=`, admin `manage`, all-or-nothing; reuse MODULE_HAS_SCORES / LEVEL_IN_USE, no new codes — `CenterScopeResetTest` 6/6).
- T4 chip feed: `/auth/me` + login/refresh `user` carry `center_name` (null for admin) — `AuthCenterNameTest` 2/2.
- Admin protection: no admin delete (422 ADMIN_DELETE incl. replacer), no admin self-deactivate (422 ADMIN_SELF_DISABLE), last-active-admin guard (422 ADMIN_LAST_ACTIVE), non-admin self-off allowed one-way — `AdminProtectionTest` 6/6.
- Global e2e suite (roadmap-qa.html 34-step workflow S1–S34): 34/34 across 18 frontend spec files, all serial, run ONLY via frontend `scripts/run-e2e.ps1`. Seven workflow cuts asserted as actual behavior (findings, not fixes) — backend-facing ones: F7 `CenterController@index` `orderBy('id')->paginate(20)` + frontend renders only `p.data` with no pager (past 20 centers a create returns 201 but never lists). Frontend-side freezes: F5 student-detail + F6 delegation redeem read `.value()` on an errored resource (`ResourceValueError` crashes CD → eternal skeleton/spinner); F1 score-sheet silent 422, F3 reviews 4-wk span silent drop, F4 exam over-max silent drop. Playwright: serial = order only, fresh context per test (login per test); shared-DB residue → delta counts.
- E2E gate 38/38 (verify, 2026-10-06): 34 roadmap + unsaved-flow 2 + user-safety-flow 2. No backend change (suite untouched). Frontend fixes only: `FormsModule` added to 16 plain-`<form (ngSubmit)>` components (no NgForm → native submit reloaded the page and cancelled POSTs — proven via `GET /centers?` document-nav in trace); exam-delete now clears dirty state pre-nav; self-off uncheck no longer reverts while armed.
- Perf pass (ECC audit verified 2026-10-06, then fixed): `ScoringService::finalAverage` N+1 → precomputed `exams.overall_avg` (semantics pinned by `ExamQuestionController::recompute`: NULL ⇔ no scored questions, else ROUND(SUM,2)); probe 15→4 queries, value identical. `seasonAvgs` avgWeekly averaged in SQL (`fromSub`), not PHP collections. `Level::effectiveFor` + `ScoringModule::effectiveFor` filter `center_id IS NULL OR = ?` in SQL (was: fetch all rows, discard in PHP). Backend 137/508 green; 3 endpoint JSONs byte-identical before/after. Localhost wall-clock unchanged — ~240ms fixed request overhead dominates at bench scale; query count is the scaling metric. STALE SEED (reported, untouched): `docs/database/quran_seed_data.sql` exam `overall_avg` values are pre-`000011` AVG-stale — re-seed needs the SUM backfill or a fresh dump.
- Frontend perf Phase 1+2 (gated prettier/typecheck/build/Vitest 95/95/shots 60/60 + manual login capture/e2e FULL suite 38/38 6.8m exit 0): Phase 1 images — auth slides recompressed JPEG q75 (slide-1 1,060,763→260,064 B, slide-2 978,833→234,987 B, 1024² kept); logo 423,363 B jpg → 128px PNG 25,096 B (`assets/logo.png` + width/height=42 in shell, kills CLS); dead `public/logo.jpg` duplicate deleted (grep-verified); slide-0 `fetchpriority=high`+eager, others `loading=lazy` (width/height attrs skipped = dead vs `.slide` inset:0 CSS). ~1.9 MB saved. Phase 2 exam-detail score PATCH debounce 400ms + blur-flush: `ScoreInputComponent` gained `blurred` output; `scoreEdits` local-override signal shields in-progress typing from reload clobbering (tick++ re-binds `[value]`); `scoreInFlight` re-arm prevents double-PATCH; guarded `dropEdit` keeps newer mid-flight keystrokes; `ngOnDestroy` fire-and-forget flush (no tick); `deleteQuestion` cancels pending timer+edit. Entry score-sheet already buffered (untouched). Dev `.env` restored. Phases 3–5 (startup unblock, GET cache, fonts+cleanup+Lighthouse) NOT started — await user approval.
- Frontend perf Phase 3 — startup unblock (gated prettier/typecheck/build/Vitest 101/101 + e2e FULL suite 38/38 6.9m exit 0; no backend change, same facts per lockstep): `AuthService.init()` boot probe is now fire-and-forget — `provideAppInitializer` no longer awaits `/auth/me`, so first paint no longer blocks on a session round trip. New `sessionProbed` signal + `ready` Promise settle after the probe (live token / dead token / none). `roleGuard` gained a wait branch: while the probe is in flight it returns `from(auth.ready).pipe(first(), map(decide))` so a returning user isn't misread as a guest; post-probe it decides synchronously through a shared `decide()` closure (guest→/login, roles, teacherTypes — exact pre-change semantics). Race guard: probe handlers capture the token at init and only apply `currentUser.set`/`clearLocal()` when `token() === t`, so a login/logout that wins the race against the probe is never clobbered; expired-token boot still rides the interceptor's single-flight `refreshOnce()` on the probe's retry. +6 Vitest (init settles with no token + fires no request; live-token restore; dead-token clear still settles ready; login-wins-race not clobbered; guard waits→allows valid user; guard waits→redirects dead token). e2e: first full run 31 passed + 2 known load-flakes (dropdown option actionability; login-form HMR wipe — both documented flake classes, green in isolation re-run 7/7); full re-run 38/38. Dev `.env` restored. Phases 4–5 (GET cache, fonts+cleanup+Lighthouse) NOT started — await user approval.
- Frontend perf Phase 4 — GET cache (gated prettier/typecheck/build/Vitest 109/109 + e2e FULL suite 38/38 6.8m exit 0; no backend change, same facts per lockstep): `ApiClient` TTL-caches GETs per (path, sorted-params) key — `shareReplay(1)` per key, `httpCacheTtlMs` from environment (30 s in ts/prod/shots; missing or ≤0 = cache disabled, fail open). Any mutation (post/put/patch/delete) clears the whole map via `finalize`, so lists never serve pre-mutation data; failed GETs evict their own key (`tap({error})`) so errors are never cached. `clearCache()` also runs in `AuthService.clearLocal()` (logout + interceptor 401 → cross-user cache hygiene on shared PCs). e2e bypass: `playwright.config.ts` `use.storageState` seeds `alotrojah_e2e_no_http_cache='1'` into every context (delta-count assertions are stale-cache-sensitive); the key literal is duplicated as `E2E_CACHE_BYPASS_KEY` in api-client.ts (the config can't import Angular deps) — the two must stay in sync. +8 Vitest (replay within TTL = 1 request; TTL expiry; distinct params = distinct keys; post invalidates; delete invalidates; failed GET not cached then refetch succeeds; bypass flag set → no caching; clearCache). e2e: first full run red — S25 strict-mode violation on `getByText('تشجيع')` (status badge + honor-dropdown label both present after fill()): latent locator ambiguity, NOT cache-related (bypass seeding proven by throwaway spec, since deleted); fixed by scoping the assertion to `app-status-badge`; isolation 3/3, full re-run 38/38. Dev `.env` restored. Phase 5 (fonts+cleanup+Lighthouse) NOT started — await user approval.

## §OPEN
- User mid-manual-QA on dev; triage via QA-page JSON. Prod DB work: ftp-only-prod-db-patching skill + deploy notes in §LOG. Rotate dev passwords + `APP_DEBUG=false` pre-prod.
