---
name: visual-qa
description: The final visual and responsive audit after implementation. It re-runs the ui-audit checks, compares similar pages against the master pattern at every target width in both RTL and LTR, and lists what is still inconsistent. Run it last, before reporting a UI task as done.
---

# Visual QA

## Position in the system

Last step. It uses the `ui-audit` checklist, the `ui-design-system` spec, the `responsive-design` widths and the `laravel-blade-ui` verification commands. Any finding goes back to the owning skill, then QA runs again.

## Gate (all must hold)

1. `cd frontend && npm run build` passes.
2. `npm run lint` shows no new errors compared with before the change.
3. The `ui-audit` grep checklist shows no new hits, and the fixed categories are at zero or have a documented exception.
4. `git diff --stat` contains only UI files (see `laravel-blade-ui`).

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

Without a browser, do this by reading the classes and state it plainly in the report ("verified by code reading, not in a browser").

## Report format

```
## Visual QA — <scope>
Gate: build ✓/✗ · lint ✓/✗ · greps <counts> · diff scope ✓/✗
Fixed: <list>
Remaining inconsistencies:
  - <file:line> <issue> → <owner skill>
Not verified: <what and why>
```

Never report "done" while an item in the gate fails.
