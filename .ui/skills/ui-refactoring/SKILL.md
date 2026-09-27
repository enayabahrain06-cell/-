---
name: ui-refactoring
description: Remove duplicated, conflicting and obsolete styling without changing behaviour. Consolidate repeated Tailwind class strings into primitives or @layer components, cut inline styles and needless !important, and clear dead UI code. Every change here is a no-op for functionality.
---

# UI Refactoring

## Position in the system

Runs alongside `component-standardization`, which moves patterns into components. This skill cleans what remains: CSS, inline styles and dead code. `laravel-blade-ui` limits what may change.

## Where styles live (order of preference)

1. **Tokens:** `@theme` in `frontend/src/index.css`. A repeated raw value becomes a token.
2. **Primitive component** in `components/`. A repeated element with a class string becomes a component.
3. **`@layer components` class** in `index.css`. Use it only for things that cannot be a component (a `thead` style shared across tables, third-party markup).
4. **Utilities in JSX:** layout and one-off tweaks.
5. **Inline `style`:** only for runtime data values (chart color, percentage width, a user-uploaded background URL).

## Rules

- **Zero behaviour change.** Same DOM semantics, same handlers, same text, same `aria-*`. The only intended diff is visual consistency.
- **Delete only when proven dead.** Before removing a component, class or file, `grep -rn "<Name\b\|from '.*Name'" frontend/src` must return nothing. Demo/design pages (`features/design/*`) count as users.
- **`!important`:** allowed only where it must beat inline or third-party styles (the `.font-quran` override and the `[data-ornament]` hide rules are intentional). Anywhere else, fix specificity.
- **No conflicting utilities** on one element (`p-4 p-5`, `rounded-xl rounded-2xl`). Resolve to the design-system value.
- **Off-palette values** (`stone-*`, `sky-*`, raw hex) map to the nearest token:
  - `stone-700` label → `text-ink/75`
  - `stone-500` hint → `text-ink/55`
  - `stone-400` placeholder → `placeholder:text-ink/40`
  - `stone-300` border → `border-ink/15`
  - `sky-*` info → `info` tokens
  - `#3F74C0` / `#2F5E9E` → `info` / `info-700`
- **Small diffs.** One pattern per commit-sized change. Do not reformat files you are not changing (no whole-file prettier runs).
- **Shared-file etiquette:** other sessions may be editing the same working tree. Before touching a file that `git status` shows as modified, tell the owner (SendMessage) or leave it for a later pass.

## Workflow

1. Take a finding (for example, "hex info color in 4 files").
2. Add or confirm the token or primitive.
3. Replace usages with targeted edits.
4. `npm run build` and `npm run lint` in `frontend/`.
5. `git diff` review: only class and JSX-structure lines changed.
