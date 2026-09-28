---
name: visual-qa
description: The final visual and responsive audit after implementation. It re-runs the ui-audit checks, runs the Playwright browser gate (npm run test:visual, screenshots and layout checks in RTL and LTR), compares similar pages against the master pattern at every target width in both RTL and LTR, and lists what is still inconsistent. Run it last, before reporting a UI task as done.
---

# Visual QA

## Position in the system

Last step. It uses the `ui-audit` checklist, the `ui-design-system` spec, the `responsive-design` widths and the `laravel-blade-ui` verification commands. Any finding goes back to the owning skill, then QA runs again.

## Gate (all must hold)

1. `cd frontend && npm run build` passes.
2. `npm run lint` shows no new errors compared with before the change.
3. The `ui-audit` grep checklist shows no new hits, and the fixed categories are at zero or have a documented exception.
4. `git diff --stat` contains only UI files (see `laravel-blade-ui`).
5. `cd frontend && npm run test:visual` passes: no layout **errors** (page horizontal scroll, content escaping the viewport, wrong `dir`), and every screenshot diff is either none or reviewed and intended.

## Browser gate (Playwright)

The suite lives in `frontend/tests/visual` (setup, auth and commands in its `README.md`). It covers the 27 audited staff pages in `ar` (RTL) and `en` (LTR) at 320, 375, 390, 414, 480, 768, 1024, 1280 and 1440 px.

1. Session: run `npm run test:visual:login` once (manual sign-in, saved to the git-ignored `tests/visual/.auth/`), or export `VISUAL_STAFF_PHONE` / `VISUAL_STAFF_PASSWORD` in the shell. Never write credentials, tokens or cookies into a tracked file.
2. Run `npm run test:visual`. It reuses a running Vite and API, or starts them.
3. Read `frontend/test-results/visual-layout/report.md`. Errors fail the gate. Warnings (clipped text, broken wrapping, grid overflow, card height mismatch, whitespace gaps, CLS, RTL/LTR differences) are heuristics: confirm each one in the HTML report (`npx playwright show-report`) and list the real ones under "Remaining inconsistencies".
4. Screenshot diffs: open expected / actual / diff in the HTML report. A diff caused by the task's intended change is accepted with `npm run test:visual:update`, only after review, and the report names which baselines changed. Any other diff is a regression and goes back to the owning skill.
5. Never edit UI code only to make a screenshot pass, and never update baselines to hide an unexplained diff.
6. When a page or page family is added, add it to `tests/visual/pages.ts` (`master: true` for a family master) and generate its baselines in the same task.

If the browser gate cannot run (no API, no session), say so under "Not verified" and fall back to the code-reading checks below. Do not report the gate as passed.

## Page-family comparison

Compare each family against its master and tick every column:

| Family | Master | Members |
|---|---|---|
| List/home | `StudentsListPage` | Lessons, Packages, Payments, Exams, Evaluation, Lottery, Certificates, Users, Settings, Dashboard |
| Detail | `LessonDetailPage` | StudentProfile, ExamDetail, Lottery detail, AttendanceSheet, EvaluationSheet |
| Dialog/form | `LessonFormDialog` | every `*Dialog.tsx` |
| Auth/public | `LoginPage` | PublicRegister, TrackRequest, VerifyCertificate, ExamPlayer (result screen) |

Columns: header type · container width (`max-w-*`) · stack spacing · filter bar · button primitives · input primitives · table head/wrap · badge tones · empty/loading/error states · pagination.

## Widths × directions

For each family master, and for any page changed in this task, check 320, 375, 390, 414, 768, 1024 and 1440 px in both `ar` (RTL) and `en` (LTR):

- no horizontal scroll (`document.documentElement.scrollWidth <= innerWidth`)
- no clipped text or overlapping controls, and long Arabic names truncate cleanly
- actions reachable, tap targets at least about 36–40 px
- icons flip correctly for direction
- focus ring visible when tabbing through the page

The Playwright suite checks the first bullet automatically and flags the others as warnings. Focus rings and icon direction still need a look at the screenshots or a manual tab-through.

Without a browser, do this by reading the classes and state it plainly in the report ("verified by code reading, not in a browser").

## Report format

```
## Visual QA — <scope>
Gate: build ✓/✗ · lint ✓/✗ · greps <counts> · diff scope ✓/✗ · visual <passed>/<total> (layout errors <n>, screenshot diffs <n>, baselines updated <list or none>)
Fixed: <list>
Remaining inconsistencies:
  - <file:line> <issue> → <owner skill>
Not verified: <what and why>
```

Never report "done" while an item in the gate fails.
