---
name: angular-playwright-e2e-patterns
description: Debugging and writing Playwright e2e specs for the AlOtrojah Angular app in alotrojah_Frontend (playwright.config.ts + e2e/*.e2e.ts). Use when a Playwright e2e test fails or flakes, when adding or editing *.e2e.ts specs, or when driving Angular forms/lists from Playwright. Covers the two recurring failures — fill() does NOT fire Angular (change) bindings (commit with .blur() or Enter), and hardcoded row/count assertions drift because specs are read-only on a shared seeded dev DB (use expect.poll(...).toBeGreaterThanOrEqual(N)) — plus content-based row selection, getByLabel({exact:true}) for shared labels, asserting counts on every visited list page, and the done-gate (npm run e2e then prettier --check on e2e/).
---

# Angular + Playwright e2e patterns

## Overview

Playwright specs for the AlOtrojah Angular frontend live in `alotrojah_Frontend/e2e/*.e2e.ts` and run against a real backend plus `ng serve`, both started automatically by `playwright.config.ts`. The specs are **read-only** (no writes, no cleanup) and run against a **shared, seeded dev database** — other flows (registration, feature tests, manual use) add rows to that same DB between runs. That one fact causes most flakiness: an assertion that was exact yesterday drifts today.

The two specs are the source of truth for current selectors and credentials — re-read them before relying on any locator below:
- `alotrojah_Frontend/e2e/auth-flow.e2e.ts`
- `alotrojah_Frontend/e2e/groups-flow.e2e.ts`

## Suite facts

- `testDir: './e2e'`, `testMatch: '**/*.e2e.ts'`, `baseURL: http://127.0.0.1:4201`.
- `webServer` auto-starts PHP `artisan serve` (:8000) and `ng serve` (:4201) with `reuseExistingServer: true` — do not start them by hand.
- Admin login used by the specs: `admin@example.org` / `password123`.
- Run the suite with `npm run e2e` (or `npx playwright test`); UI mode is `npm run e2e:ui`.
- Current suite = 4 tests (1 in groups-flow, 3 in auth-flow); a healthy run is 4/4 green.

## Gotcha A — fill() does NOT fire Angular (change)

`locator.fill()` dispatches an `input` event but **not** a `change` event. Angular templates that bind `(change)` (search boxes, some selects) never see the new value, so the list does not filter and the next assertion fails.

Commit the value explicitly after `fill()`:

```ts
const search = page.locator('.toolbar input[type="search"]');
await search.fill('L3');
await search.blur(); // fires (change) — or: await search.press('Enter');
await expect(page.locator('tbody tr.row-link')).toHaveCount(1);
```

Symptom: the input visibly holds the text but the table/list is unchanged. Fix: `.blur()` (or `press('Enter')`) after every `fill()` on a `(change)`-bound control.

## Gotcha B — count assertions drift on the shared seeded DB

Because specs are read-only but the dev DB is shared, registration-flow tests and manual use add students/rows over time. A hardcoded exact count (e.g. `toHaveCount(7)` students) passes once, then fails when the count becomes 9.

For any collection that can **grow**, assert a floor with `expect.poll`, not an exact count:

```ts
const rows = page.locator('.grid-auto .card');
await expect.poll(async () => rows.count()).toBeGreaterThanOrEqual(7);
```

Reserve exact `toHaveCount(N)` for sets genuinely fixed by the seed (e.g. the 5 seeded groups / `.kpi` cards). When in doubt, prefer `toBeGreaterThanOrEqual`.

## Gotcha C — select rows by content, not DOM position

The default sort is by Arabic name, so DOM order is not intuitive (`الفرقان` sorts before `النور`). Never use `.first()` / `.nth(i)` to mean a specific record. Locate by text:

```ts
page.locator('tbody tr.row-link', { hasText: 'الإحسان' });
```

## Gotcha D — locator precision (labels & comboboxes)

- Use `{ exact: true }` when a sibling shares the label. The password field's visibility toggle also contains `كلمة المرور`, so:
  ```ts
  await page.getByLabel('كلمة المرور', { exact: true }).fill('password123');
  ```
- Target the intended combobox explicitly — the language switcher is often the first `option`/`select` on the page, so a bare `getByRole('combobox')` grabs the wrong one.
- Unlabeled `<select>` elements break `getByLabel`; they must be wrapped/labeled in the component (also an a11y win).

## Gotcha E — a test only guards the pages it visits

The NG0203 loader bug (`inject()` inside a `resource()` loader spun `/groups` forever) survived because no e2e spec ever opened `/groups`. Rule: when a spec navigates to a list page, assert its row/KPI count there. Visiting a page without asserting its content guards nothing.

## Done-gate (run before considering e2e work complete)

1. `npm run e2e` — expect all tests green (currently 4/4). Read the head of the failure output first; Playwright prints received vs expected and the trace path (`trace: 'retain-on-failure'`).
2. `npm run format:check` — prettier covers `e2e/**/*.ts`, and freshly edited specs frequently fail formatting. To target only e2e: `npx prettier --check "e2e/**/*.ts"`.

### Windows / Git Bash cautions

- Never put Arabic or other non-ASCII text in shell string literals — the shell mangles it. Edit spec files with the Edit/Write tool instead.
- For arguments that begin with a slash (paths, some flags), prefix the command with `MSYS_NO_PATHCONV=1` so Git Bash does not rewrite them.

## Resources

No bundled scripts or references. The authoritative, current examples are the specs themselves: `alotrojah_Frontend/e2e/auth-flow.e2e.ts` and `alotrojah_Frontend/e2e/groups-flow.e2e.ts`.
