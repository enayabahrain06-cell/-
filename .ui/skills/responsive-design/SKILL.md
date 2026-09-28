---
name: responsive-design
description: Make every staff screen work from 320 px phones to 3840 px 4K screens, in portrait and landscape, in Arabic (RTL) and English (LTR), with no horizontal overflow, no clipped text, aligned card rows, stable loading (CLS < 0.1) and no desktop regressions. Covers the shell and drawer, tables, filter bars, forms, cards, empty states, charts, dialogs and action rows. Use it after standardization and whenever a page is added or changed. Verify with screenshots looked at by eye; never run test:visual:update.
---

# Responsive Design

## Position in the system

Runs after `component-standardization`. Where possible, fix at the shared-component level so every page inherits the fix. `ui-design-system` has the final say on visual questions (tokens, sizes, spacing). `visual-qa` runs the Playwright gate afterwards. This skill never changes routes, API types, permissions or form logic (`laravel-blade-ui`).

## Screens to cover

Every check below runs at each of these widths, in `ar` (RTL) **and** `en` (LTR):

| Width | Typical device | Tailwind |
|---|---|---|
| 320 | small phone (SE 1st gen) | base |
| 360 | common Android | base |
| 375 / 390 | iPhone | base |
| 414 / 430 | large iPhone (Plus / Pro Max) | base |
| 768 | tablet portrait (iPad mini) | `md:` |
| 820 | tablet portrait (iPad Air) | `md:` |
| 1024 | tablet landscape / small laptop (sidebar appears) | `lg:` |
| 1280 | laptop | `xl:` |
| 1366 | common laptop | `xl:` |
| 1440 | desktop | `xl:` |
| 1536 | desktop (12-column dashboard starts) | `2xl:` |
| 1920 | full HD | `2xl:` |
| 2560 | QHD | `2xl:` |
| 3840 | 4K | `2xl:` |

**Between breakpoints.** Layouts break most often 1 px either side of a breakpoint and in the gaps between them. Check 639/641, 767/769, 1023/1025, 1279/1281 and 1535/1537, and sweep from 320 to 1920 in 16 px steps checking only for overflow (see Verification).

**Orientation.** Phones and tablets in both orientations:

| Device | Portrait | Landscape |
|---|---|---|
| small phone | 375 × 667 | 667 × 375 |
| phone | 390 × 844 | 844 × 390 |
| large phone | 430 × 932 | 932 × 430 |
| tablet | 768 × 1024 / 820 × 1180 | 1024 × 768 / 1180 × 820 |
| large tablet | 1024 × 1366 | 1366 × 1024 |

Landscape phones are wide but short (about 375–430 px tall): sticky bars, the drawer and dialogs must still leave the content usable and scrollable.

The sidebar is a drawer below `lg`. At 1024 px the content column is 1024 − 272 = **752 px**, narrower than a tablet in portrait. Design `lg:` layouts for that width, and put templates with 5 or more columns on `xl:`.

## Rules

Existing rules (unchanged):

1. **Mobile-first.** Base classes are the phone layout. Add `sm:`/`md:`/`lg:` to enhance. Never fix mobile with `max-*:` overrides that fight desktop classes.
2. **No regressions.** A fix for one width must leave every other width pixel-identical unless it was also wrong there. Compare before/after screenshots at all the widths above.
3. **No horizontal page scroll** at any width. The only allowed horizontal scroll is inside a table wrapper or a deliberately scrollable tab strip.
4. **Flex/grid children** that hold text get `min-w-0`. Card grids use `*:min-w-0` on the grid. Long names get `truncate` or `break-words`. User text gets `dir="auto"`.
5. **Fixed widths** (`w-44`, `min-w-[40rem]`) only from `sm:` up, or inside a scroll wrapper. On base, use `w-full` or `flex-1 min-w-0`.
6. **Use the dynamic viewport:** `max-h-[85dvh]`, not `100vh`, in dialogs and drawers.

Layout quality rules:

7. **No clipped text.** Nothing cut off vertically or horizontally without an ellipsis. `truncate` needs a line box tall enough for the font: display-face titles (Amiri) use `leading-normal`, not `leading-tight`. Text inside `overflow-hidden` must fit or use `truncate` / `line-clamp-*`.
8. **No broken wrapping.** No text squeezed into a column so narrow it stacks word by word (short phrases like times, dates and labels stay on one or two lines). Headings of columns that only hold "—" or short values get `whitespace-nowrap`. The main text block of a wrapping row uses `ROW_MAIN` (`min-w-0 flex-[1_1_12rem]`), so actions drop to the next line instead of crushing the text.
9. **Card rows align.** Cards that share a row in a grid have the same height and aligned bottoms: the grid stretches (no `items-start`) and each card fills its cell (`h-full`, or `*:*:h-full` when cards sit in wrapper divs). Card footers stay at the bottom (`flex flex-col` with `flex-1` on the body).
10. **No empty areas inside cards.** Stretching must not leave a gap of more than about 40 px inside a card. If it would, pair cards of similar height in the same row (reorder or change spans), or let the short card use the space meaningfully (centred content, a larger chart). Never pad with filler.
11. **Compact empty states.** Empty cards use `EmptyState` / `EmptyCard` (`size="sm"` inside cards): an icon, one line and an optional hint, not a tall blank box. An empty card must not set the height of its row.
12. **No big empty sides on wide screens.** At 1536–3840 px the content stays inside the `AppLayout` width (`max-w-7xl`) and is centred. Pages never add their own outer width cap or padding, and never stretch text lines across the full screen. Check that 2560 and 3840 show the same layout as 1920, centred, with balanced margins.
13. **Correct RTL/LTR alignment.** Logical properties only (`ms-`/`me-`/`ps-`/`pe-`/`start-`/`end-`, `text-start`/`text-end`). Actions sit on the end side in both directions. Directional icons flip (`rtl:rotate-180` / `ltr:rotate-180`). Vertical moves (up/down) do not flip. Numbers, phones and codes use `dir="ltr"` or `tabular-nums` where needed. Compare `ar` and `en` screenshots side by side: the same structure mirrored, no element aligned to the wrong side.
14. **Stable loading: CLS under 0.1.** Nothing moves after the first paint. Rows that sit right on the edge of wrapping (a few px from fitting) wrap differently when a web font swaps in, so give them room or a fixed width, or put the long part on its own line on phones (`basis-full sm:basis-auto`). Reserve space for data that arrives late (skeletons with the final height). The UI fonts are preloaded in `index.html`; add any new weight you use there.
15. **Sidebar as a working drawer on phones.** Below `lg` the menu button opens the drawer, the drawer is `role="dialog"` `aria-modal`, focus moves into it, Escape and the backdrop close it, following a link closes it, and the page behind does not scroll. It fits in landscape phones (scrolls inside if taller than the screen).
16. **Touch targets at least 44 px** on phones and tablets (coarse pointer): buttons, links in lists, icon buttons, tabs, pagination and checkbox rows. Visual controls may stay 40 px (`min-h-10`, as `ui-design-system` sets) when the clickable area around them reaches 44 px through padding or a larger hit area. `ui-design-system` decides the visual size; this rule decides the hit area.
17. **Tables scroll inside their container.** Every table sits in `TableWrap` (or an `overflow-x-auto` div inside the surface) with a `min-w-*` so columns do not crush. The page itself never scrolls sideways. Primary lists may switch to a card list below `sm`.
18. **Charts fit on phones.** Charts use `ResponsiveContainer` with a height that works at 320 px, axis labels do not overlap (fewer ticks on phones), legends wrap, and the "show as table" view uses `TableWrap`. No chart is wider than its card.
19. **Dialogs fit on phones.** Panel `w-full max-w-lg` (or the design-system size) inside an overlay with `p-4` and `overflow-y-auto`, body `max-h-[85dvh]` scrolling inside, footer buttons wrap (`flex flex-wrap justify-end gap-2`). Nothing inside sets a width larger than the screen minus 32 px. Check at 320 portrait and 667 × 375 landscape.

## Patterns

### Tables

- Wrap: `TableWrap`, or `<div className="overflow-x-auto">` inside the surface (`overflow-hidden` on the outer surface, scroll on the inner div).
- `min-w-[..rem]` on the table. Hide low-priority columns with `hidden md:table-cell` / `lg:table-cell`.

### Filter bars

- `FilterBar` layouts: `stack` (default, stacks on phones), `grid` (the page passes `sm:`/`lg:`/`xl:grid-cols-*`; 5+ columns go to `xl:`), `row` (one wrapping row at every width, for date steppers; long text there gets `basis-full sm:basis-auto`).
- The primary action moves to its own row on phones (`w-full sm:w-auto sm:ms-auto`).

### Forms

- `grid gap-4 sm:grid-cols-2`. Never `grid-cols-2` at base, except very short pairs (from/to dates) from 360 px.
- Footer buttons: `flex flex-wrap justify-end gap-2`.

### Cards, KPI rows and dashboards

- KPI rows: `grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6`, never 3+ columns on base.
- Card grids: stretch rows (rule 9) and pair similar heights (rule 10). Reference: `DashboardPage` (alerts | today, ages | fees, memorization | activity; 12-column spans from `2xl`).

### Page header actions

- `PageBand` actions wrap already. Keep action labels short. Icon-only buttons need `aria-label`.

### Segmented controls and tab strips

- A control that wraps onto its own line on phones uses `Segmented fill` (equal-width options, full row). Tab strips with many tabs scroll horizontally inside their own container.

## Verification

Screenshots, looked at by eye, are the proof. Reading classes alone is not enough when the app can run.

1. **Servers:** Vite on `:5180` (`npm run dev -- --port 5180 --strictPort`) and the API on `:8010`. Never use `:5173`.
2. **Suite widths, both directions:** `cd frontend && VISUAL_BASE_URL=http://localhost:5180 npm run test:visual -- --update-snapshots=none`. Read `test-results/visual-layout/report.md` (errors fail; warnings are heuristics to confirm by eye) and the diffs in `npx playwright show-report`.
3. **The other widths, orientations and 4K:** take full-page screenshots with a one-off Playwright script in the scratchpad (reuse `tests/visual/.auth/staff.json`, set `localStorage['ahl.locale']`, `reducedMotion: 'reduce'`, `animations: 'disabled'`) at every size in the tables above, in `ar` and `en`, for each page you changed and each family master (`StudentsListPage`, `LessonDetailPage`, `DashboardPage`, `QuickEnrollPage`, `LoginPage`). Open the images and look at them. In Git Bash set `MSYS_NO_PATHCONV=1` and pass Windows paths (`cygpath -w`) for output files.
4. **Overflow sweep:** in the same script, resize from 320 to 1920 in 16 px steps and at each breakpoint ±1 px, asserting `document.documentElement.scrollWidth <= innerWidth`.
5. **CLS:** load each changed page fresh (new browser context, 320 px) with a `layout-shift` PerformanceObserver and confirm the total stays under 0.1 in both directions.
6. **Drawer, dialogs, touch:** at 390 × 844 and 844 × 390, open the drawer and one dialog per changed page; tab through them; check hit areas are at least 44 × 44 px (`getBoundingClientRect` of the clickable element).
7. **Report** before/after screenshots for every changed page at the affected widths, both directions, and state anything not checked.

Never run `test:visual:update` or `--update-snapshots` from this skill. Updating baselines is a separate step that needs the user's explicit approval after they have reviewed the screenshots (`visual-qa`).
