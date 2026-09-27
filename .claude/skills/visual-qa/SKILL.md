---
name: visual-qa
description: The final visual and responsive audit after implementation. It re-runs the ui-audit checks, compares similar pages against the master pattern at every target width in both RTL and LTR, and lists what is still inconsistent. Run it last, before reporting a UI task as done.
---

The canonical instructions for this skill are in `.ui/skills/visual-qa/SKILL.md` (repo root). Read that file in full with the Read tool and follow it exactly. It is part of the UI engineering system (ui-audit → ui-design-system → component-standardization / ui-refactoring → responsive-design → visual-qa, with laravel-blade-ui guarding every step). When a skill there names another skill, read `.ui/skills/<that-skill>/SKILL.md` too.
