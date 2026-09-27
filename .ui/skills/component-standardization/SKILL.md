---
name: component-standardization
description: Find UI patterns repeated across pages and move each one into one shared primitive. Covers buttons, inputs, search, filters, tables, cards, badges, notices, modals, pagination and page headers. Existing primitives get extended, not duplicated, and pages call them instead of copying class strings.
---

# Component Standardization

## Position in the system

Runs after `ui-audit` has listed the systemic findings. It uses the specs in `ui-design-system`. Refactors go through `ui-refactoring` rules, and `laravel-blade-ui` guards behaviour.

## The shared kit (single source per concept)

| Concept | Primitive | File |
|---|---|---|
| Surface | `Card`, `CardTitle`, or `SURFACE` class constant (keeps the element tag) | `components/ui.tsx` |
| Primary / danger button | `PrimaryButton` (`tone`, `loading`) | `components/ui.tsx` |
| Secondary button | `SecondaryButton` | `components/ui.tsx` |
| Link styled as a button | `buttonClass(variant)` + `<Link className>` | `components/ui.tsx` |
| Large full-width form submit (auth/public) | `Button` | `components/Button.tsx` |
| Text input (dense, staff) | `TextInput` | `components/ui.tsx` |
| Bare field in a table cell, inline row or date picker | `className={inputClass(size, className, danger?)}`: `md`/`sm`, caller sets width and font size; `aria-invalid` for invalid, `danger` for a valid but alarming value | `components/ui.tsx` |
| Text input with error/hint (forms) | `FormField` | `components/FormField.tsx` |
| Search input with icon | `SearchInput` | `components/ui.tsx` |
| Select | `SelectField` | `components/SelectField.tsx` |
| Textarea | `TextArea` | `components/ui.tsx` |
| Filter bar | `FilterBar` | `components/ui.tsx` |
| Segmented / tabs-as-radio | `Segmented` | `components/ui.tsx` |
| Badge | `Badge tone=` | `components/ui.tsx` |
| Inline message | `Notice` (staff) / `Alert` (auth/public) with the same tones | `ui.tsx` / `Alert.tsx` |
| Data table (list pages) | `<TableWrap surface>` + `thead className={TABLE_HEAD}`, rows `divide-y divide-ink/6`, cells `px-4 py-3` | `components/ui.tsx` |
| Table scrolling inside a fixed-height box | `thead className={TABLE_HEAD_STICKY}` | `components/ui.tsx` |
| Compact table inside a `Card` (reports, profile tabs) | `<TableWrap>` (bleeds to card edges), head row `border-b border-ink/10 text-ink/55`, cells `py-2` | `components/ui.tsx` |
| Modal | `Modal` (put a `<form id>` in the body and give the footer submit `form={id}`) | `components/ui.tsx` |
| Icon-only button (remove row, dismiss) | `IconButton` (`icon`, `label`, `tone`: muted / remove / danger), never a ✕/× glyph | `components/ui.tsx` |
| Pagination | `Pagination` | `components/Pagination.tsx` |
| Page header (list/home) | `PageBand` | `components/ornaments/PageBand.tsx` |
| Page header (light) | `PageTitle` | same |
| States | `LoadingState`, `ErrorState`, `EmptyState`; `EmptyCard` for an empty list inside the white card | `ui.tsx`, `ornaments/EmptyState.tsx` |

If a concept is missing from the table, add it to `ui.tsx` and document it here. Do not create a page-local version.

## Rules

1. **Search before you build.** `grep -rn "export function\|export const\|export default" frontend/src/components` first.
2. **Extend with props, not forks.** Need a smaller button? Add a `size` prop. Do not copy the class string into the page.
3. **Pages own layout, primitives own look.** A page may pass layout classes (`className="w-full sm:w-44"`, `ms-auto`). It must not override color, radius, border, shadow or font through `className`.
4. **Keep APIs backward-compatible.** Add optional props with defaults equal to the current behaviour. Never rename or remove a prop in the same change that adopts it.
5. **forwardRef for every input primitive.** React 18 drops `ref` on function components, and `react-hook-form` `register()` needs it. Without forwardRef, fields submit `undefined`.
6. **Accessibility is part of the component:** labels (visible or `sr-only`), `aria-invalid`, `aria-describedby`, `aria-busy`, `role="alert"/"status"`, focus management in `Modal`.
7. **Replace call sites mechanically**, one pattern at a time, and keep each diff reviewable.

## Workflow

1. Take one systemic finding from the audit.
2. Pick or extend the primitive and write it to the `ui-design-system` spec.
3. Replace the call sites. Keep props, handlers and `t()` keys byte-identical.
4. `npm run build` in `frontend/` (type-check plus build) must pass.
5. Update the table above if a primitive was added.
