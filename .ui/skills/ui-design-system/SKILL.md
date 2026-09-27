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

1. `<div className="space-y-5">` page root, with no `max-w-*` / `mx-auto` of its own. `AppLayout`'s `<main>` owns the padding and one shared cap (`mx-auto w-full max-w-7xl`), so every staff page has the same width on wide screens (decided by the user, 2026-09-27)
2. `PageBand` header (deep emerald, gold girih pattern, gold display title, optional `actions`)
3. Filter card: white, rounded-2xl, `border-ink/8`, `shadow-sm`, `p-4`
4. Content: `Card`s or a table card, then `Pagination`
5. States: `LoadingState` / `ErrorState` / `EmptyState` (never a bare "Loading..." string)

Detail pages (`LessonDetailPage`, `StudentProfilePage`, `ExamDetailPage`) use a light header: back link, `h1.font-display.text-3xl.text-ink`, badges, and actions on the end side. Auth and public pages use `AuthLayout` / `PublicLayout` with an `OrnamentFrame` card.

Do not invent a new look. Extend these.

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

Forbidden in TSX: Tailwind default palettes (`stone-*`, `gray-*`, `slate-*`, `sky-*`, `red-*`, `green-*`, `emerald-*`, `amber-*`) and arbitrary hex (`bg-[#...]`). Chart series colors are the one exception. They come from a single constant per chart file.

### Typography

- UI face `font-sans` (IBM Plex Sans Arabic). English switches to Inter automatically.
- Display face `font-display` (Amiri) is only for page titles (`h1`) and brand text.
- Quran text uses `font-quran` only.
- Scale: page title `text-3xl` (`sm:text-4xl` on auth/public hero only); card title `text-base font-semibold`; modal title `text-lg font-semibold`; body `text-sm`; meta `text-xs`; KPI numbers `text-2xl`–`text-3xl font-semibold tabular-nums`.
- Numbers use `tabular-nums` and go through `formatNumber` / `formatDate` from `lib/format.ts`.

### Spacing

- Page stack `space-y-5`. Tab panels and sub-sections under a page, and the inside of a card: `space-y-4`. Form grids `gap-3` or `gap-4`.
- Card padding `p-4 sm:p-5`. Filter bar `p-4`. Table cells `px-4 py-3`. Modal sections `px-5 py-4`.
- Main gutter and width come from `AppLayout` (`px-4 sm:px-6 lg:px-8`, `max-w-7xl`). Pages must not add their own outer padding or width cap.

### Radius, border, shadow

- `rounded-2xl`: cards, bands, table containers, modals
- `rounded-xl`: buttons, inputs, selects, notices, segmented container
- `rounded-lg`: icon buttons, segmented items, small chips inside controls
- `rounded-full`: badges, avatars, pills
- Surfaces use the `SURFACE` constant from `components/ui.tsx` (`rounded-2xl border border-ink/8 bg-white shadow-sm`) or `Card`. Padding: `p-4 sm:p-5` for content cards, `p-4` for filter bars and list items, `p-6` only for the centred single-form cards on public pages.
- Shadow `shadow-sm` on surfaces, `shadow-2xl` only for modals and the drawer.

### Icons

- Use `components/Icon.tsx` (24px stroke paths). Sizes: `size-4` inline with text, `size-5` nav and icon buttons.
- Do not use emoji or text glyphs (`✕`, `+`, `›`) as icons in new code. Existing `+ label` button text is tolerated until replaced.
- Directional icons flip with `rtl:rotate-180` / `ltr:rotate-180`.

### States

| State | Rule |
|---|---|
| hover | surfaces `hover:bg-ink/5`; brand `hover:bg-brand-800`; rows `hover:bg-brand-50/40` |
| focus | inputs `focus:border-brand-500 focus:ring-4 focus:ring-brand-100`; buttons/links `focus-visible:outline-2 focus-visible:outline-brand-500` |
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
