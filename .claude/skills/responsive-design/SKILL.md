---
name: responsive-design
description: Make every staff screen work from 320 px phones to 3840 px 4K screens, in portrait and landscape, in Arabic (RTL) and English (LTR), with no horizontal overflow, no clipped text, aligned card rows, stable loading (CLS < 0.1) and no desktop regressions. Covers the shell and drawer, tables, filter bars, forms, cards, empty states, charts, dialogs and action rows. Use it after standardization and whenever a page is added or changed. Verify with screenshots looked at by eye; never run test:visual:update.
---

The canonical instructions for this skill are in `.ui/skills/responsive-design/SKILL.md` (repo root). Read that file in full with the Read tool and follow it exactly. It is part of the UI engineering system (ui-audit → ui-design-system → component-standardization / ui-refactoring → responsive-design → visual-qa, with laravel-blade-ui guarding every step). When a skill there names another skill, read `.ui/skills/<that-skill>/SKILL.md` too.
