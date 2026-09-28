# Visual regression (Playwright)

Browser screenshots and layout checks for the staff UI, in Arabic (RTL) and English (LTR).

## Run

```bash
cd frontend
npm run test:visual:login    # once: sign in by hand in the window; saves tests/visual/.auth/staff.json
npm run test:visual          # compare against the baselines + layout checks
npm run test:visual:update   # accept the current UI as the new baseline (after reviewing the diffs!)
npm run test:visual:ui       # Playwright UI mode, to step through pages and diffs
npm run typecheck:visual     # type-check the suite
npx playwright show-report   # HTML report with expected / actual / diff images
```

The API and Vite dev server are reused when already running (`VITE_API_PROXY_TARGET` in `frontend/.env`
and `http://localhost:5173`). Otherwise the config starts `php artisan serve` and `npm run dev`.
Set `VISUAL_BASE_URL` to test an already running frontend on another port.

## Authentication

Staff pages need a Sanctum bearer token (localStorage `ahl.token`). The `setup` project:

1. reuses `tests/visual/.auth/staff.json` while `/api/auth/me` still accepts its token;
2. otherwise signs in with `VISUAL_STAFF_PHONE` / `VISUAL_STAFF_PASSWORD` **from your shell**;
3. otherwise, when headed (`npm run test:visual:login`), waits for you to sign in by hand.

`tests/visual/.auth/` is git-ignored. Never put credentials in a committed file. To rotate the token,
delete `staff.json` and sign in again.

Detail pages use the first record of each kind returned by the API (`.auth/ids.json`). A page whose
record does not exist in the database (for example no exams yet) is skipped, not failed.

## What is checked

- `toHaveScreenshot()` full-page, animations and transitions frozen, `prefers-reduced-motion`.
  Masters (`master: true` in `pages.ts`) at every width, family members at 375 / 768 / 1280.
  `VISUAL_ALL_WIDTHS=1` screenshots every page at every width.
- Widths: 320, 375, 390, 414, 480, 768, 1024, 1280, 1440 (layout checks run at all of them).
- **Errors** (fail the test): page horizontal scroll, content escaping the viewport, wrong `dir`.
- **Warnings** (report only, judge by eye): clipped text, broken wrapping, grid overflow, card height
  mismatch in a row, gaps over 96px, failed fonts, layout shift on load (CLS > 0.1), RTL/LTR height
  or finding differences.

Warnings and errors per page and width: `test-results/visual-layout/report.md`.

## Baselines

Committed under `tests/visual/__screenshots__/<ar|en>/<page>-<width>.png`. They depend on the data in
the database and on today's date (dashboard, attendance day), so regenerate them on the same machine
and data set, and review every diff before running `test:visual:update`.
