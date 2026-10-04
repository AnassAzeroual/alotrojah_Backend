# AGENTS.md — AI agent entry point (AlOtrojah)

Read this first. It points to the authoritative project knowledge; do not duplicate those sources here.

## Where the knowledge lives

- **Project skills (procedural how-tos):** `.qoder/skills/<name>/SKILL.md`. Qoder-based agents auto-load these; other agents should open the relevant one before starting work in that area.
  - `angular-playwright-e2e-patterns` — Playwright e2e for the Angular frontend (`fill()` vs `(change)`, count drift on the shared seeded DB, locator precision, the `npm run e2e` → `prettier --check` done-gate).
  - `browser-use-verification` — verifying UI flows in a real browser.
  - `headless-screenshot-capture` — themed (light/dark) desktop/mobile screenshots via headless Chrome.
  - `ftp-only-prod-db-patching` — production DB changes (FTP + phpMyAdmin only; no SSH, no artisan).
- **Standards & chronological build log:** `docs/Agent.md`. Treat its standards sections as law.

## Non-negotiables

- Production is FTP + phpMyAdmin only. Never run `artisan migrate` or SSH against prod; ship idempotent patch SQL (see the `ftp-only-prod-db-patching` skill).
- `docs/Agent.md` exists in BOTH repos and must stay in lockstep — update both when facts change.
- Frontend unit tests run via `ng test` (Vitest); e2e via `npm run e2e` (Playwright). Read the e2e skill before touching `e2e/*.e2e.ts`.
