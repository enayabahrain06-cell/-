---
name: ui-design-system
description: The visual language of the Ahl Al-Quran system. Tokens, type, spacing, radius, shadow, icon and state rules, plus the master pattern every page must match. Load it before any UI change and give it the final say when another skill disagrees on a visual question.
---

# UI Design System

## Position in the system

This skill is the **source of truth**. The other six skills defer to it:

```
ui-audit ──► ui-design-system ◄── component-standardization
   │                ▲                     │
   ▼                │                     ▼
ui-refactoring ─────┴──── responsive-design ──► visual-qa
                  laravel-blade-ui (guards every step)
```

If a rule here conflicts with a page, the page is wrong. If a rule here is missing, add it here first, then apply it.

## Stack facts (verify before trusting)

- The staff and family UI is a **React 18 + Tailwind CSS v4 SPA** in `frontend/`. It is not Blade.
- Blade is used only for PDF output (`backend/resources/views/pdf/*.blade.php`). It has its own print palette (see `laravel-blade-ui`).
- Tokens live in `frontend/src/index.css` under `@theme`. Tailwind generates utilities from them (`bg-brand-700`, `text-ink`, `bg-deep`...).
- Shared primitives live in `frontend/src/components/ui.tsx`, `components/ornaments/*`, `FormField.tsx`, `SelectField.tsx`, `Pagination.tsx`, `Icon.tsx`, `Avatar.tsx`.
- The UI is bilingual (Arabic RTL default, English LTR). `html[dir]` switches with the locale.

## Master pattern

The master reference is the **staff list page** as built in `features/students/StudentsListPage.tsx` and `features/lessons/LessonsHomePage.tsx`:

1. `<div className="space-y-5">` page root, with no `max-w-*` / `mx-auto` of its own. `AppLayout`'s `<main>` is the one page container: it owns the gutter and fills the column beside the sidebar (`mx-auto w-full max-w-page`, where `--container-page` is 120rem and only takes effect on QHD/4K). Every staff page gets the same width at every size. The user dropped the old `max-w-7xl` cap on 2026-09-29 because it left empty side bands at 1440–1920
2. `PageBand` header (deep emerald, gold girih pattern, gold display title, optional `actions`)
3. Filter card: white, rounded-2xl, `border-ink/8`, `shadow-sm`, `p-4`
4. Content: `Card`s or a table card, then `Pagination`
5. States: `LoadingState` / `ErrorState` / `EmptyState` (never a bare "Loading..." string)

Detail pages (`LessonDetailPage`, `StudentProfilePage`, `ExamDetailPage`) use a light header: back link, `h1.font-display.text-3xl.text-ink`, badges, and actions on the end side. Auth and public pages use `AuthLayout` / `PublicLayout` with an `OrnamentFrame` card.

Do not invent a new look. Extend these.

### nav_v2 section pages (runtime switch in القائمة, docs/07-NAV-V2.md)

With nav_v2 on, a list page is shown inside a **tab page** (`features/navV2/TabPage.tsx`), top to bottom:

1. `Breadcrumb` (`PageBand breadcrumb`): `text-xs text-ink/60`, links `text-info-700`, `chevron` separators (never `›` text)
2. One `PageBand`: the tab name as the title, the exact name of the screen shown as the subtitle, the page's own actions, then `ModeSwitch onDeep` on the end edge (visually left in RTL). A hosted page's own `PageBand` and `MobilePage` add their subtitle and actions to it through `EmbedContext`; they never draw a second band
3. `SectionTabs`: one row of real links, 44px tall, `border-b border-ink/10`; active `border-brand-700 font-semibold text-brand-800`, idle `text-ink/65`. On phones the row scrolls sideways and keeps the active tab centred
4. Below lg: `ModeSwitch` light, full width, 44px options (`Segmented size="lg" fill`) under the tabs; a kind filter (`?kind=`, e.g. الصف / التقسيم) is a plain `Segmented`
5. The page body. In-page tab bars (`Segmented` / `MSegmented` / chip rows that switch a page's `?tab=`) are hidden when hosted: the mode switch replaces them. Read the page's own tab key with `useOwnParam`, never `params.get('tab')` directly

Segmented on the deep band: `Segmented onDeep` (translucent `bg-white/10` track, chosen option `bg-white text-brand-900 font-semibold`, focus outline `gold-300`). Tab and mode labels are short and never repeat the section name (one approved exception: التواصل والتقارير › التقارير).

## Tokens

### Color (use tokens only; no raw hex in TSX)

| Role | Token / utility | Use |
|---|---|---|
| Primary action | `brand-700` bg, `brand-800` hover, `brand-900` active | Primary buttons, active links |
| Primary tint | `brand-50`, `brand-100` | Selected rows, success notices, focus ring (`ring-brand-100`) |
| Focus border | `brand-500` | `focus:border-brand-500`, `focus-visible:outline-brand-500` |
| Header surface | `deep` | Sidebar, `PageBand` |
| Accent | `gold-300/400/500/700` | Titles on deep, ornaments, "gold" badges. Never for body text on white |
| Text | `ink`, `ink/75` labels, `ink/60` secondary, `ink/50` meta, `ink/40` placeholder | |
| Lines | `ink/8` card border, `ink/6` row divider, `ink/12`–`ink/15` input border | |
| Page bg | `page` | body |
| Paper | `paper` | certificate and ornamental surfaces |
| Danger | `danger`, `danger/5` bg, `danger/25` border | errors, destructive actions |
| Info | `info` (`#3F74C0`), `info-700` text | info badges and notices |

Forbidden in TSX: Tailwind default palettes (`stone-*`, `gray-*`, `slate-*`, `sky-*`, `red-*`, `green-*`, `emerald-*`, `amber-*`) and arbitrary hex (`bg-[#...]`). Chart series colors are the one exception. They come from a single constant per chart file. Grid, axis, tick and cursor styling comes from `components/chart.ts` (`CHART_GRID`, `CHART_AXIS_LINE`, `CHART_TICK`, `CHART_BAR_CURSOR`, `CHART_LINE_CURSOR`), never a repeated ink hex.

### Typography

- UI face `font-sans` (IBM Plex Sans Arabic). English switches to Inter automatically.
- Display face `font-display` (Amiri) is only for page titles (`h1`) and brand text. Section headings (`h2`) and KPI numbers are never display face. Exceptions: the student name on the certificate verification card, and exam question prompts (Arabic question text in the editor, detail and player).
- Quran text uses `font-quran` only, with `lang="ar" dir="rtl"`. This covers verse answers, verse options and verse inputs in exams, not only display quotes. Never put verses in `font-display`: in the English UI `font-display` switches to Inter bold.
- Scale: page title `text-3xl` (`sm:text-4xl` on auth/public hero only); page section heading `text-lg font-semibold` (`text-xl font-semibold` for a public confirmation card); card title `text-base font-semibold`; modal title `text-lg font-semibold`; body `text-sm`; meta `text-xs`; KPI numbers `text-2xl`–`text-3xl font-semibold tabular-nums`.
- Numbers use `tabular-nums` and go through `formatNumber` / `formatDate` from `lib/format.ts`.

### Spacing

- Page stack `space-y-5`. Tab panels and sub-sections under a page, and the inside of a card: `space-y-4`. Form grids `gap-3` or `gap-4`.
- Card padding `p-4 sm:p-5`. Filter bar `p-4`. Table cells `px-4 py-3`. Modal sections `px-5 py-4`.
- Control height: buttons (`buttonClass`), `inputClass("md")`, `SearchInput` and `SelectField` are all `min-h-10` (40px) so filter rows line up. Compact in-row buttons pass `py-1`/`py-1.5` and keep their size. The large 50px `FormField`/`Button` pair is for auth and public forms.
- `FilterBar` layouts: `stack` (default, flex, stacks on phones), `grid` (the page passes its own `sm:`/`lg:`/`xl:grid-cols-*`; move templates with 5+ columns to `xl:`, since at 1024–1279px the content is only about 690–940px wide), `row` (one wrapping row at every width, for date steppers).
- Main gutter and width come from `AppLayout` (`px-4 sm:px-6 lg:px-8`, `max-w-page`). The sticky header uses the same gutter so its edges line up with the content. Pages must not add their own outer padding or width cap. Narrow caps belong only on focused content inside a page (auth and public cards, `FamilyLayout`, a single-form card), never on a list, table, dashboard or report.

### Radius, border, shadow

- `rounded-2xl`: cards, bands, table containers, modals
- `rounded-xl`: buttons, inputs, selects, notices, segmented container
- `rounded-lg`: icon buttons, segmented items, small chips inside controls
- `rounded-full`: badges, avatars, pills
- Surfaces use the `SURFACE` constant from `components/ui.tsx` (`rounded-2xl border border-ink/8 bg-white shadow-sm`) or `Card`. Padding: `p-4 sm:p-5` for content cards, `p-4` for filter bars and list items, `p-6` only for the centred single-form cards on public pages.
- Shadow `shadow-sm` on surfaces, `shadow-2xl` only for modals and the drawer.
- Floating layers (dropdown listboxes, chart tooltips, the sticky save bar) use `shadow-lg` with `border-ink/10` (the sticky bar may use `border-gold-500/30`).
- Whole-card links (dashboard KPI tiles, report catalog) lift on hover: `hover:border-brand-600/30 hover:shadow-md`, plus the standard focus outline.
- Compact tables inside a `Card` (summaries, chart "show as table" views) sit in `TableWrap` or `overflow-x-auto`, with a head row of `border-b border-ink/10 text-ink/55` and `py-2` cells. Full list tables use `TABLE_HEAD` / `TABLE_HEAD_STICKY` and `px-4 py-3`.
- Exceptions: `HonorDisplayPage` is a wall-screen TV view on `deep` and may use `rounded-3xl` and oversized display type. The certificate thumbnail in `certificates/shared.tsx` is a miniature certificate and may use `text-[8px]`–`text-[11px]`.

### Icons

- Use `components/Icon.tsx` (24px stroke paths). Sizes: `size-4` inline with text, `size-5` nav and icon buttons.
- Do not use emoji or text glyphs (`✕`, `+`, `›`) as icons in new code. Existing `+ label` button text is tolerated until replaced.
- Directional icons flip with `rtl:rotate-180` / `ltr:rotate-180`.
- Vertical moves (reorder up/down, expand/collapse) use `chevron` with `-rotate-90` / `rotate-90`. They do not flip with direction.

### States

| State | Rule |
|---|---|
| hover | surfaces `hover:bg-ink/5`; brand `hover:bg-brand-800`; rows `hover:bg-brand-50/40` |
| focus | inputs `focus:border-brand-500 focus:ring-4 focus:ring-brand-100`; buttons/links `focus-visible:outline-2 focus-visible:outline-brand-500`. A base rule in `index.css` gives every `a`, `button`, `summary`, `[role=button]` and `[tabindex]` the same outline by default, so small text and icon buttons are never focus-less |
| disabled | `disabled:cursor-not-allowed disabled:opacity-60` (secondary `opacity-50`) |
| loading | `StarSpinner` inside the button with `aria-busy` |
| invalid | `aria-invalid` + `border-danger/60` + message wired by `aria-describedby` |
| selected | `bg-white shadow-sm ring-1 ring-ink/10` (segmented), `bg-brand-50` (rows) |

## RTL / bilingual rules

- Use logical utilities only: `ps/pe`, `ms/me`, `start/end`, `text-start/end`, `border-s/e`. Never `pl/pr/ml/mr/left/right/text-left/text-right` in TSX.
- Every visible string goes through `t()`. Add both `locales/ar/*.json` and `locales/en/*.json` keys.
- User-entered names get `dir="auto"`.

## Ornaments

Ornaments are decorative, `aria-hidden`, pointer-events none, and controlled by `html[data-ornament]` (full / minimal / off). Put them only in `PageBand`, the sidebar, auth frames and certificates. Never inside tables or forms.

## Workflow

1. Read `index.css` `@theme` and `components/ui.tsx` before deciding anything.
2. For a visual question, find the answer in this file. If it is missing, find the closest existing master usage and write the rule down here.
3. When adding a token, add it to `@theme` with a comment, then update the table above.
