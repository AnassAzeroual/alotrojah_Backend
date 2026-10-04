---
name: ftp-only-prod-db-patching
description: "Prepare and apply DB schema changes to AlOtrojah production (Heberjahiz shared hosting: FTP + phpMyAdmin only, no SSH, no artisan). Use when the user mentions the prod database, phpMyAdmin, 'SQL for prod', migrating schema changes to production, or translating `php artisan migrate --pretend` output into manual patch SQL."
---

# FTP-only Prod DB Patching

## Host constraints (hard rules)

- Prod is Heberjahiz shared hosting: **FTP + phpMyAdmin only**. No SSH, no CLI — **artisan can never run on prod**.
- Prod database: `alotr15q_prod`. The only way to modify it is SQL the user pastes into phpMyAdmin.
- Prod schema came from a dump imported via phpMyAdmin and has no `migrations` table. Keep it that way: **never create or write to a migrations table on prod**.
- **Never auto-commit patch files.** Hand them over uncommitted; the user reviews, applies, and commits themselves.

## The `--pretend` trap

`php artisan migrate --pretend` prints **pending migrations only**. The dev database has the full baseline registered (batch 1), so it prints "Nothing to migrate" — which does **not** mean prod is in sync. Never treat `--pretend` output as "SQL for prod".

## Workflow

1. **Establish prod's real state first.** Ask the user to run queries in phpMyAdmin (or compare against the dump prod was imported from):
   `SHOW TABLES;` · `SHOW COLUMNS FROM users LIKE 'role';` · `SHOW CREATE VIEW <view>;`
   Prod lags dev — never assume they match.
2. **Diff dev vs prod via the git history of the canonical dump** (`alotrojah_Backend/docs/database/quran_memorization_db.sql`):
   - `git log --follow -- docs/database/quran_memorization_db.sql`
   - `git show <commit> -- <dump>` for every commit after prod's import date.
   - Classify each change: schema (DDL) or data (DML). Then **re-check for missed commits** — in one past incident a guardians-removal commit (`d6e01f9`) nearly slipped through while only the view fix was obvious.
3. **Write an idempotent patch** at `alotrojah_Backend/docs/database/prod_patch_YYYY-MM-DD.sql`:
   - STEP 0: verify-before queries with expected results (proof the patch is needed).
   - Order: drop FK **before** the column, column **before** the table; `UPDATE` data **before** shrinking an ENUM; views via `CREATE OR REPLACE VIEW`.
   - FK names on prod can differ from dev: query `information_schema.KEY_COLUMN_USAGE` for the real name instead of guessing.
   - Guard every statement: `DROP TABLE IF EXISTS`, `CREATE TABLE IF NOT EXISTS`, "skip this step if X already exists".
   - Final step: verify-after queries with expected results (table count, ENUM contents, view rows).
4. **Hand the patch over uncommitted.** The user reviews, pastes it into phpMyAdmin, and commits themselves. If they report error `#1091 Unknown constraint`, the FK name was wrong — go back to the information_schema discovery query.

## Reference example

`alotrojah_Backend/docs/database/prod_patch_2026-10-02.sql` is the worked example: verify-first → guardians removal (FK, then column, then table) → `CREATE OR REPLACE VIEW v_student_season_avgs` (follows `is_current=1`) → `UPDATE users SET role='student' WHERE role='guardian'` **before** shrinking `MODIFY role ENUM(...)` → `CREATE TABLE IF NOT EXISTS registration_requests` → final verification.

## Fallback: token-gated web script

Running PHP via URL works on this host (`deploy.php` proves it). A token-protected file in `public/` that boots Laravel and runs `Artisan::call('migrate', ['--pretend' => true])` would work — but until prod registers the baseline migrations it would print the entire schema, so it is a diagnostic tool only, not a source for patches. Offer it as a sketch only when asked; never create such a file without an explicit request.
