---
name: angular-playwright-e2e-patterns
description: Debugging and writing Playwright e2e specs for the AlOtrojah Angular app in alotrojah_Frontend (playwright.config.ts + e2e/*.e2e.ts). Use when a Playwright e2e test fails or flakes, when adding or editing *.e2e.ts specs, or when driving Angular forms/lists from Playwright. Covers the recurring failures — fill() does NOT fire Angular (change) bindings (commit with .blur() or Enter), hardcoded count assertions drift on the shared seeded verify DB (use expect.poll floors or delta counts), test.describe.serial still gives every test a FRESH context (login per test, never assume carried state), npm drops the playwright file-filter arg (single files run via scripts/run-e2e.ps1 directly), and the seven known workflow cuts F1-F7 that specs intentionally assert as actual behavior — plus content-based row selection, getByLabel({exact:true}) for shared labels, and the done-gate (full green suite + prettier --check on e2e/).
---

# Angular + Playwright e2e patterns

## Overview

Playwright specs for the AlOtrojah Angular frontend live in `alotrojah_Frontend/e2e/*.e2e.ts` and run against a real backend plus `ng serve`, both started automatically by `playwright.config.ts`. The specs run against a **shared, seeded database** (`alotrojah_verify`, selected by `scripts/run-e2e.ps1` swapping the backend `.env` — never dev) — interrupted runs and parallel flows leave residue rows in that same DB between runs. That one fact causes most flakiness: an assertion that was exact yesterday drifts today.

The suite covers the 34-step workflow in `roadmap-qa.html` (S1–S34): **34 tests across 18 spec files**, all `test.describe.serial`. Several specs are deliberate **cut-detectors** — they assert the app's actual (broken) behavior at known workflow cuts so a fix flips them red (see Gotcha G). The specs are the source of truth for current selectors and credentials — re-read the spec closest to your page before relying on any locator below. Key references:

- `alotrojah_Frontend/e2e/api.ts` — shared helpers: `loginAs(page, email, pwd)`, `adminLogin(page)`, `apiToken(request)`, `apiNames`.
- `alotrojah_Frontend/e2e/auth-flow.e2e.ts`, `groups-flow.e2e.ts` — base CRUD + theme/locale patterns.
- `alotrojah_Frontend/e2e/centers-flow.e2e.ts` (F7), `comms-flow.e2e.ts` (F6, S28–S30), `entry-flow.e2e.ts` (F1, S17–S19), `exams-cut.e2e.ts` (F4), `isolation-crawl.e2e.ts` (F5, S32–S33), `reviews-flow.e2e.ts` (F2/F3, S20–S21), `registrations-flow.e2e.ts` (S5/S6/S31), `results-reports-flow.e2e.ts` (S25–S27).

## Suite facts

- `testDir: './e2e'`, `testMatch: '**/*.e2e.ts'`, `baseURL: http://127.0.0.1:4201`.
- `webServer` auto-starts PHP `artisan serve` (:8000) and `ng serve` (:4201) with `reuseExistingServer: true` — do not start them by hand. **Check :8000 ownership before every run**: a reused API server keeps whatever DB it booted with (the wrapper swaps `.env`, but a stale artisan still holds its boot-time DB — a contamination hazard both ways).
- Admin login used by the specs: `admin@example.org` / `password123` (all seeded accounts share it).
- Run e2e **only** via the wrapper `scripts/run-e2e.ps1` (swaps backend `.env` to `alotrojah_verify`, verifies, restores). Full suite: `npm run e2e`. Single/multiple files: `MSYS_NO_PATHCONV=1 powershell -ExecutionPolicy Bypass -File scripts/run-e2e.ps1 e2e/<file>.e2e.ts` — **npm drops the file-filter arg**, so `npm run e2e -- e2e/x.e2e.ts` runs EVERYTHING. Raw `npx playwright test` is `npm run e2e:direct` for emergencies only.
- Current suite = 34 tests across 18 spec files; a healthy full run is 34/34 green (~4.5 min, workers 1).

## Gotcha A — fill() does NOT fire Angular (change)

`locator.fill()` dispatches an `input` event but **not** a `change` event. Angular templates that bind `(change)` (search boxes, some selects) never see the new value, so the list does not filter and the next assertion fails.

Commit the value explicitly after `fill()`:

```ts
const search = page.getByTestId('users-search');
await search.fill('L3');
await search.press('Enter');
await expect(page.locator('tbody tr.row-link')).toHaveCount(1);
```

Symptom: the input visibly holds the text but the table/list is unchanged. Fix: `.blur()` (or `press('Enter')`) after every `fill()` on a `(change)`-bound control. Prefer binding search inputs to `(input)` in app code so `fill()` alone suffices — commit events are inherently racy under re-renders.

## Gotcha B — count assertions drift on the shared seeded DB

Because specs run on a shared DB, interrupted runs and other flows add rows over time. A hardcoded exact count (e.g. `toHaveCount(7)` students) passes once, then fails when the count becomes 9.

For any collection that can **grow**, assert a floor with `expect.poll`, not an exact count:

```ts
const rows = page.locator('.grid-auto .card');
await expect.poll(async () => rows.count()).toBeGreaterThanOrEqual(7);
```

Reserve exact `toHaveCount(N)` for sets genuinely fixed by the seed (e.g. the 5 seeded groups / `.kpi` cards). When in doubt, prefer `toBeGreaterThanOrEqual`.

For specs that **create** rows, prefer **delta counts** over floors — they survive residue from interrupted runs:

```ts
const lines = cyclesCard.locator('.line');
const before = await lines.count();
// ... action that adds exactly one row ...
await expect(lines).toHaveCount(before + 1);
```

Cleanup loops over variable residue should re-read `.count()` each iteration rather than assuming a fixed starting number.

## Gotcha C — select rows by content, not DOM position

The default sort is by Arabic name, so DOM order is not intuitive (`الفرقان` sorts before `النور`). Never use `.first()` / `.nth(i)` to mean a specific record. Locate by text:

```ts
page.locator('tbody tr.row-link', { hasText: 'الإحسان' });
```

## Gotcha D — locator precision (labels, test-ids & comboboxes)

- Prefer `data-testid` hooks for every static control: `page.getByTestId('auth-email')`, `nav-users`, `users-search`, `group-save`, dropdown triggers via the shared `testId` input (`users-role`, `group-center`, `lang-switcher`). Dynamic data (entity names in options/rows) keeps content selection (Gotcha C) — test-ids can't name values unknown at author time. Text-based control locators (labels, aria-names, placeholders) are banned: they break on locale switch. Structural classes (`.kpi`, `.row-link`, `canvas`, `.auth-error`) stay for assertions.

- Use `{ exact: true }` when a sibling shares the label. The password field's visibility toggle also contains `كلمة المرور`, so:
  ```ts
  await page.getByLabel('كلمة المرور', { exact: true }).fill('password123');
  ```
  Same for dropdown options — `getByRole('option', { name: 'حفظ' })` prefix-matches `حفظ ومراجعة`; pass `exact: true`.
- Target the intended combobox explicitly — the language switcher is often the first `option`/`select` on the page, so a bare `getByRole('combobox')` grabs the wrong one.
- Unlabeled `<select>` elements break `getByLabel`; they must be wrapped/labeled in the component (also an a11y win).

## Gotcha E — a test only guards the pages it visits

The NG0203 loader bug (`inject()` inside a `resource()` loader spun `/groups` forever) survived because no e2e spec ever opened `/groups`. Rule: when a spec navigates to a list page, assert its row/KPI count there. Visiting a page without asserting its content guards nothing.

## Gotcha F — serial ≠ shared state: every test gets a FRESH context

`test.describe.serial` guarantees execution ORDER and skip-after-failure ONLY. Playwright still isolates every test in its own browser context/page — **no login, localStorage, or navigation carries over between tests**. A spec's second test must log in itself (via `adminLogin(page)` / `loginAs(page, ...)` from `e2e/api.ts`), and must settle the post-login URL before navigating on:

```ts
await loginAs(page, 'teach2.forqan@example.org', 'password123');
await expect(page).toHaveURL(/^http:\/\/127\.0\.0\.1:4201\/$/); // login POST races the next goto — guard redirect bounces to /login otherwise
```

## Gotcha G — known workflow cuts asserted as actual behavior (F1–F7)

The suite's job is detecting cuts in the 34-step workflow (`roadmap-qa.html`). Seven cuts are confirmed and their specs **assert the actual broken behavior on purpose** — when the app gets fixed, that spec flips red and must be updated. Do not "fix" these specs while the app is unfixed:

- **F1 (S18)** score-sheet over-cap save: the 422 error handler only resets the saving flag — zero user feedback. Assert the row stays and no error banner appears.
- **F2 (S21)** reviews cycle delete is NOT `canManage()`-gated — murajaa/hifz teachers see the delete button (roadmap says admin/supervisor only). Assert the button IS visible to the teacher (scope the assertion to the seed row).
- **F3 (S20)** reviews 4-week span: `span > 3` returns silently and the submit's `[disabled]` doesn't catch it (no validator) — a complete form dead-clicks. Note: a successful save runs `form.reset()`, so re-pick the term before a second attempt.
- **F4 (S23)** exam `addQuestion` over-max: client-side silent return — the queue button looks dead. Draft rows are `ul.draft-list > li` (not `.line`).
- **F5 (S32)** student-detail on a cross-center 404: reading `.value()` on an errored `resource()` throws `ResourceValueError`, change detection crashes, the page freezes on the aria-hidden skeleton forever (never reaches `<app-empty-state>`). Isolation itself holds (API 404s fast, no leak) — assert the FROZEN SKELETON.
- **F6 (S29)** delegation redeem by a DIFFERENT teacher → 403 → same `ResourceValueError` freeze: eternal spinner, the invalid message never renders. Same-teacher re-entry is idempotently re-granted (assert that too). The generated link is ABSOLUTE with a `localhost:4200` origin — strip it via `new URL(link)` → `pathname + search` before `goto`.
- **F7 (S1/S2)** centers list: backend `orderBy('id')->paginate(20)` (`CenterController@index`), frontend renders only `p.data` (page 1) and has no pager UI — past 20 centers a create returns 201 but the row never appears. Race-proof the assertion: register the list-reload `waitForResponse(GET …/centers)` BEFORE the save click, await it, then assert `toHaveCount(0)` for the new row.

Freeze-assertion pattern (F5/F6): await the error response, settle ~1.5s (`waitForTimeout`), then assert the spinner/skeleton is STILL visible and the empty-state message count is 0.

## Done-gate (run before considering e2e work complete)

1. Full suite green via the wrapper — `npm run e2e`, expect 34/34. Read the head of the failure output first; Playwright prints received vs expected and the trace path (`trace: 'retain-on-failure'`).
2. `npm run format:check` — prettier covers `e2e/**/*.ts`, and freshly edited specs frequently fail formatting. To target only e2e: `npx prettier --check "e2e/**/*.ts"`.

### Windows / Git Bash cautions

- Never put Arabic or other non-ASCII text in shell string literals — the shell mangles it. Edit spec files with the Edit/Write tool instead.
- For arguments that begin with a slash (paths, some flags), prefix the command with `MSYS_NO_PATHCONV=1` so Git Bash does not rewrite them.

## Resources

No bundled scripts or references. The authoritative, current examples are the specs themselves — start from `alotrojah_Frontend/e2e/api.ts` (helpers) and the spec files listed in the Overview.
