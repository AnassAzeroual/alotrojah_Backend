---
name: headless-screenshot-capture
description: Capture themed (light/dark), desktop and mobile screenshots of locally served dev-server pages using puppeteer-core with the system Chrome in headless mode. Use when the in-app browser screenshot fails with NATIVE_BROWSER_VIEWPORT_UNAVAILABLE or the Browser panel is hidden, when comparing a rendered page against a mockup, or when visually verifying UI changes across themes, viewports, or after login.
when_to_use: browser-use take_screenshot fails (NATIVE_BROWSER_VIEWPORT_UNAVAILABLE), the in-app Browser panel is hidden, or multi-theme/multi-viewport screenshots of a localhost page are needed for visual verification.
---

# Headless Screenshot Capture

## Overview

Screenshot locally served pages (multi-theme, multi-viewport) with puppeteer-core driving the system Chrome in headless mode, for when the in-app browser cannot take screenshots. Every step below was verified on Windows + Git Bash against an Angular dev server; derive per-project paths, ports, and keys instead of assuming them.

## When to use

- browser-use `take_screenshot` fails with `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE` (in-app Browser panel hidden: `visible=false`, `visibilityState=hidden`). If structure is all you need, `take_snapshot` still works.
- Visual verification is required: light/dark themes, desktop/mobile sizes, mockup comparison, or post-login pages.

Do not use while the in-app browser panel is open and screenshots work normally.

## Preflight

1. Check the dev server is up (example ports from the verified case: frontend 4200, backend 8000):

   ```bash
   curl -s -o /dev/null -w '%{http_code}' http://localhost:4200
   ```

   Expect 200. If down, start the dev server(s) in the background and wait until this returns 200.

2. Find a browser executable (verified locations on this machine — Chrome first, Edge as fallback):

   ```bash
   ls "/c/Program Files/Google/Chrome/Application/chrome.exe" 2>/dev/null \
     || ls "/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"
   ```

## Setup

Work in a scratch dir so node_modules never lands in a repo:

```bash
mkdir -p "/c/Users/$USER/AppData/Local/Temp/shot-tool"
```

1. `cd` there and `npm install puppeteer-core` (small install, no browser download — that is the point of puppeteer-core over puppeteer).
2. Create `shot.js` in that dir with the **Write tool**.

**Never pass the script inline via `node -e`.** Shell escaping eats the backslashes in Windows paths and launch fails with `Browser was not found at the configured executablePath (C:Program FilesGoogle...)`. Write the file, then run the file.

## Script template

```js
// shot.js — themed, multi-viewport screenshots of a locally served SPA.
// Usage (Git Bash): MSYS_NO_PATHCONV=1 node shot.js /register
const puppeteer = require('puppeteer-core');

const BASE = 'http://localhost:4200'; // dev server origin
const ROUTE = process.argv[2] || '/'; // SPA route passed as CLI arg
const CHROME =
  process.argv[3] ||
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const THEME_KEY = 'alotrojah_theme'; // grep the project's ThemeService for its real key
const WAIT_MS = 1600; // settle time after reload (fonts, images, charts)
const SIZES = [
  ['desktop', 1440, 960],
  ['mobile', 390, 844],
];
const THEMES = ['light', 'dark'];

async function shoot(page, out, w, h, theme) {
  await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
  await page.goto(`${BASE}${ROUTE}`, { waitUntil: 'networkidle0' });
  await page.evaluate((k, t) => localStorage.setItem(k, t), THEME_KEY, theme);
  await page.reload({ waitUntil: 'networkidle0' });
  await new Promise((r) => setTimeout(r, WAIT_MS));
  await page.screenshot({ path: out });
  const state = await page.evaluate(() => ({
    dir: document.documentElement.getAttribute('dir'),
    lang: document.documentElement.getAttribute('lang'),
    theme: document.documentElement.getAttribute('data-theme'),
  }));
  console.log(out, JSON.stringify(state));
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--hide-scrollbars'],
  });
  const page = await browser.newPage();
  const base = ROUTE.replace(/^\//, '').replace(/\//g, '-') || 'page';
  for (const theme of THEMES) {
    for (const [vp, w, h] of SIZES) {
      await shoot(page, `${base}-${theme}-${vp}.png`, w, h, theme);
    }
  }
  await browser.close();
})().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
```

## Run it

```bash
cd "/c/Users/$USER/AppData/Local/Temp/shot-tool" && MSYS_NO_PATHCONV=1 node shot.js /register
```

- Always prefix `MSYS_NO_PATHCONV=1` on Git Bash for Windows: without it, MSYS rewrites the route argument `/login` into a Windows path and navigation fails with `Protocol error (Page.navigate): Cannot navigate to invalid URL`.
- The Bash tool resets cwd between calls — keep `cd <scratch-dir> && node shot.js ...` in one command. Relative output paths resolve against the cwd, so this also keeps PNGs in the scratch dir.
- File names carry route + theme + viewport (`register-light-desktop.png`), so shots for different pages never overwrite each other. Keep that convention.
- Each output line is `<file> {"dir":...,"lang":...,"theme":...}` — that state echo is the verification that theme and direction actually applied.
- After SCSS/code edits, just rerun the same command; the dev server hot-reloads and the script relaunches Chrome fresh each run.

## Force theme and locale

- **Theme (verified):** set the project's persisted-theme localStorage key, then reload — the app reads it on boot. Find the key in the theme service first. Verified example: AlOtrojah's `ThemeService` uses `alotrojah_theme` (`'light' | 'dark'`) and `index.html` reads it pre-boot to avoid a flash of the wrong theme.
- **Locale (not yet verified):** do not assume a key. Inspect the project's language service for its persistence key and apply the same set → reload → fixed-wait pattern, then confirm via the echoed `lang`/`dir`. Otherwise pages render the project default (AlOtrojah: `ar` / `rtl`).

## Authenticated pages

Verified flow (AlOtrojah login → dashboard):

1. `goto('/login')`, set the theme key, reload, wait ~1200 ms.
2. Fill the form — AlOtrojah selectors: `page.$$('.auth-input input')`, then type the dev test account (take credentials from project memory/docs; AlOtrojah: `admin@example.org` / `password123`).
3. Submit `form.auth-form .auth-btn`, then wait for the SPA to navigate:

   ```js
   await page.waitForFunction(
     () => !location.pathname.startsWith('/login'),
     { timeout: 15000 },
   );
   ```

4. Wait ~2500 ms for data/charts to settle, screenshot, and log the landed path.

`location` exists only in the browser context. Referencing it in Node scope throws `ReferenceError: location is not defined` — read the path via `page.evaluate(() => location.pathname)`.

## Failure signatures

| Error | Cause | Fix |
|---|---|---|
| `Browser was not found at the configured executablePath (C:Program FilesGoogle...)` | Script passed inline via `node -e`; shell ate the backslashes | Write `shot.js` with the Write tool and run the file |
| `Protocol error (Page.navigate): Cannot navigate to invalid URL` | Git Bash converted the `/route` CLI argument into a Windows path | Prefix `MSYS_NO_PATHCONV=1` |
| `ReferenceError: location is not defined` | `location` used in Node scope | Use it only inside `page.evaluate` / `page.waitForFunction` |

A launch error naming a missing `.exe` means the browser path is wrong — rerun the preflight discovery and fall back to Edge.

## Verify

1. Preflight returned 200 and every screenshot line echoed the expected `dir`/`lang`/`theme`.
2. Open the PNGs (Read tool) and confirm the visuals: theme, direction, layout, logged-in state.
3. Report the absolute output paths to the user.

Ports (4200), sizes (1440×960, 390×844), the theme key, and credentials above are the verified AlOtrojah example — derive per-project equivalents from the project's code and memory.
