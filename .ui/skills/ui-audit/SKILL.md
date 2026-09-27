---
name: ui-audit
description: Audit a page, a feature folder, or the whole frontend against the design system before changing any code. Produces a findings report (inconsistency, location, rule broken, proposed fix). Run it first on every UI task. Implementation starts only after the report exists.
---

# UI Audit

## Position in the system

First step of every UI task. Its output feeds `component-standardization`, `ui-refactoring` and `responsive-design`. `visual-qa` re-runs the same checks at the end. The reference for every check is `ui-design-system`.

## Rules

- **Read-only.** An audit never edits files.
- Report before implementing. Each finding names a file:line, the rule it breaks (quote the `ui-design-system` section), and a proposed fix that reuses an existing primitive.
- Group findings by pattern, not by page. "Search input duplicated in 3 pages" is one finding with 3 locations.
- Separate **systemic** findings (fix once in a shared component) from **local** ones (fix in the page).
- Note which files other people are editing (`git status`, peer sessions) so the implementation plan can avoid collisions.

## Checklist

Run these greps from `frontend/src` (Git Bash). Each hit is a candidate. Confirm it by reading the code.

| Check | Command |
|---|---|
| Off-palette colors | `grep -rnE '\b(text\|bg\|border\|ring)-(stone\|gray\|slate\|zinc\|neutral\|sky\|emerald\|amber\|red\|green\|blue)-[0-9]' --include=*.tsx .` |
| Raw hex | `grep -rnoE '\[#[0-9A-Fa-f]{3,8}\]' --include=*.tsx .` |
| Physical direction | `grep -rnE '\b(pl\|pr\|ml\|mr\|left\|right)-[0-9]\|text-(left\|right)\b' --include=*.tsx .` |
| Hand-rolled buttons | `grep -rnE '<(button\|Link)[^>]*className="[^"]*bg-brand-7' --include=*.tsx features` |
| Hand-rolled inputs | `grep -rnE '<input[^>]*type="(search\|text)"[^>]*className=' --include=*.tsx features` |
| Tables | `grep -rn '<table' --include=*.tsx .` then confirm every table sits in an `overflow-x-auto` wrapper with a shared head style |
| Inline style | `grep -rn 'style={{' --include=*.tsx .` (allowed only for data-driven values: chart colors, percentages, background image) |
| `!important` | `grep -rn '!important\|![a-z]' frontend/src/index.css` plus `!`-prefixed utilities in TSX |
| Page headers | `grep -rnE '<h1\|PageBand\|PageTitle' --include=*.tsx features` |
| Loading text | `grep -rniE '>\s*(loading\|جار)' --include=*.tsx features` (should use `LoadingState`) |
| Card clones | `grep -rn 'rounded-2xl border border-ink/8 bg-white' --include=*.tsx features` (should be `Card` or a shared surface class) |
| Glyph icons | `grep -rnE '[✕×›‹→←]' --include=*.tsx .` |

Also check by reading:

- **Spacing:** page stack `space-y-5`, card padding, filter bar padding.
- **Alignment:** actions sit on the end side; label above field; badges vertically centered.
- **Typography:** one `h1` per page, correct scale, `font-display` only on titles.
- **Forms:** every control has a label (visible or `sr-only`); errors use `aria-describedby`.
- **States:** loading, error, empty and disabled states exist and use the shared components.

## Report format

```
## UI audit — <scope> — <date>
Master reference: <file>
### Systemic
S1. <pattern> — rule: <design-system section>
    where: a.tsx:12, b.tsx:40, c.tsx:88
    fix: <shared primitive to add/extend>, then replace call sites
### Local
L1. <file:line> — <problem> — fix: <...>
### Responsive risks (hand to responsive-design)
R1. ...
### Out of scope / needs a decision
Q1. ...
```

## Workflow

1. `git status` and check peer sessions. List the files that are dirty or owned by others.
2. Read the master pages named in `ui-design-system`.
3. Run the checklist and read each hit.
4. Write the report, then stop and hand it over. Do not fix anything in the same step.
