---
name: browser-use-verification
description: Verify web UIs end-to-end in a real browser via the browser-use MCP tools. Use when asked to test or verify a frontend in a browser, click through user flows (register/login/admin approval), assert success and error states, check the console for errors, or compare rendered pages. Covers MCP lazy-loading invocation, uid-based interaction, stale-DOM timing with async change detection (zoneless Angular), and known dead ends with fallbacks.
when_to_use: A UI change needs real-browser interactive verification - clicking through flows, asserting states, console-error check - via browser-use MCP tools.
---

# Browser-Use UI Verification

## Overview

Playbook for verifying web UIs interactively through the browser-use MCP tools: correct invocation under MCP lazy-loading, uid-based click/fill, avoiding stale-DOM misreads when change detection is async, dead ends with fallbacks, and the golden-path template that ends with a zero-console-errors assertion.

## 1. Invoke tools correctly (MCP lazy-loading)

- Never call a browser-use tool by its qualified name (`mcp__browser-use__click`) - it fails with "Tool not found".
- Always go through the meta tools: `mcp_list` -> `mcp_get` (fetch the schema) -> `mcp_call` (invoke).
- `mcp_get` each tool's schema before first use; parameter shapes are not guessable:
  - `click`: `{uid}` - NOT element/ref (rejected: "params must have required property 'uid'")
  - `fill`: `{uid, value}` - not element/ref/text
  - `press_key`: `{key}` only - uid is rejected as an additional property
  - `evaluate_script`: `function` must be an UNINVOKED arrow function (`() => {...}`); an IIFE fails with "did not evaluate to a function"
- `take_snapshot` works even when `take_screenshot` fails (hidden Browser panel) - structure is always inspectable.

## 2. Snapshots and stale DOM

- uids come from `take_snapshot` output and go STALE after any click/fill/navigation - take a fresh snapshot before each interaction.
- With async change detection (e.g. zoneless Angular), a snapshot taken right after a click can still show the OLD DOM: the click worked, the snapshot was just early. Fix by re-taking the snapshot, or by `evaluate_script` with `waitForStableDom: true` reading the expected new state.
- Reads inside one `evaluate_script` call are synchronous - DOM the app renders asynchronously needs a SEPARATE `evaluate_script` (with `waitForStableDom: true`) to observe.
- The uid-based `click` sometimes misses small controls (pill buttons) - fall back to `el.click()` inside `evaluate_script`.

## 3. Known dead ends (do not retry)

- `window.resizeTo` via `evaluate_script` is a silent no-op - the tab viewport does not change. Verify responsive behavior by reading the CSS/SCSS media queries instead.
- `take_screenshot` fails with `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE` when the in-app Browser panel is hidden. Use `evaluate_script` + `getComputedStyle` for style checks, ask the user to open the panel, or use the `headless-screenshot-capture` skill for themed/multi-viewport PNGs.
- Before judging a theme toggle broken, check the app's DEFAULT theme first: the first toggle switches to the opposite of the default, which reads as a no-op if you assumed the wrong starting state.
- Before comparing a screenshot against a mockup, confirm the actual route - the app root often redirects to login while the page under test lives at its own path.

## 4. Golden-path verification template

Run the full user flow, asserting state after every step (fresh snapshot or `waitForStableDom`):

1. Fill the form and submit; assert the success state (panel/message/redirect).
2. Exercise each error path; assert the exact error message (e.g. duplicate email -> waiting-room notice).
3. Toggle conditional fields (role-dependent selects) and re-verify.
4. Log in as an admin; run the approve flow with any required options (e.g. assigning a center); assert persistence - the created row exists, the request is gone - and the empty state afterwards.
5. Run the reject/cancel flow including its confirmation step; assert hard delete and empty state.
6. Re-run the creation flow fresh and log in as the newly created user.
7. Spot-check languages (RTL/LTR switch, correct labels per locale) and light/dark themes.
8. Close with `list_console_messages`; the error count must be ZERO.
9. When the flow persists data, cross-check DB/API state independently - never trust the UI alone.

## 5. Report

For each flow report: steps executed, assertion results, console error count (zero required), and screenshots if any. State explicitly anything that could NOT be verified and why (e.g. hidden Browser panel).
