---
name: responsive-design
description: Make layouts work at 320, 375, 390, 414, 768 px and desktop without horizontal overflow and without regressing desktop. Covers the shell, tables, filter bars, forms, cards, modals and action rows. Use it after standardization and whenever a page is added or changed.
---

# Responsive Design

## Position in the system

Runs after `component-standardization`. Where possible, fix at the shared-component level so every page inherits the fix. `visual-qa` verifies the widths below.

## Breakpoints (Tailwind v4 defaults, mobile-first)

| Width | Device | Tailwind |
|---|---|---|
| 320 | small phone | base |
| 375 / 390 / 414 | phones | base |
| 640 | large phone / small tablet | `sm:` |
| 768 | tablet | `md:` |
| 1024 | laptop (sidebar appears) | `lg:` |
| 1280+ | desktop | `xl:` |

The sidebar is a drawer below `lg`. The content column at 1024 px is 1024 - 272 = **752 px**, narrower than a tablet. Design `lg:` layouts for that width.

## Rules

1. **Mobile-first.** Base classes are the phone layout. Add `sm:`/`md:`/`lg:` to enhance. Never fix mobile with `max-*:` overrides that fight desktop classes.
2. **Never break desktop.** A mobile fix must leave the `lg:`+ rendering pixel-identical unless the desktop layout was also wrong.
3. **No horizontal page scroll** at 320 px. The only allowed horizontal scroll is inside a table wrapper or a deliberately scrollable tab strip.
4. **Flex/grid children** that hold text get `min-w-0`. Grid items default to `min-width: auto` and push past the viewport, so card grids use `*:min-w-0` on the `<ul className="grid ...">`. Long names get `truncate` or `break-words`. User text gets `dir="auto"`.
5. **Fixed widths** (`w-44`, `w-56`, `min-w-[40rem]`) are allowed only on `sm:` and up, or inside a scroll wrapper. On base, use `w-full` or `flex-1 min-w-0`.
6. **Tap targets** at least 40 px high (`py-2` + `text-sm` on a button is about 36 px, which is acceptable for dense toolbars. Primary mobile actions use `py-2.5`+).
7. **Use the dynamic viewport:** `max-h-[85dvh]`, not `100vh`, inside modals.

## Patterns

### Tables

- Always wrap: `<div className="overflow-x-auto">` inside the surface (`rounded-2xl ... overflow-hidden` on the outer surface, scroll on the inner div).
- Give the table a `min-w-[..rem]` so columns do not crush, and let the wrapper scroll.
- For primary lists (students, requests), keep the existing pattern: a **card list below `sm`** and the table from `sm:`. Hide low-priority columns with `hidden md:table-cell` / `lg:table-cell`.

### Filter bars

- Base: stacked, every control `w-full`.
- `sm:` and up: `flex flex-wrap items-end gap-3`. Search `flex-1 min-w-48`, selects `sm:w-44`.
- The primary action moves to its own row on phones (`w-full sm:w-auto sm:ms-auto`).

### Forms

- Grids: `grid gap-4 sm:grid-cols-2`. Never `grid-cols-2` at base, except very short pairs (from/to dates) at 360 px+.
- Buttons in a form footer: `flex flex-wrap justify-end gap-2`.

### Cards and KPI rows

- `grid gap-4 sm:grid-cols-2 lg:grid-cols-4`. Never start at 3+ columns on base.

### Page header actions

- `PageBand` actions wrap already. Keep action labels short. Icon-only buttons need `aria-label`.

### Modals

- The overlay scrolls (`overflow-y-auto`, `p-4`), and the panel is `w-full max-w-lg`. On phones the panel must fit at 320 px minus 32 px of padding. Nothing inside may set a width larger than that.

## Verification

Without a browser runner, verify by reading the classes at each breakpoint. When the app can run (`npm run dev -- --port 5180`, backend on 8010), check each width in DevTools device mode. `document.documentElement.scrollWidth > innerWidth` must be false on every page.
