# Ahl Al-Quran System

- `backend/`: Laravel 12 JSON API. `frontend/`: React 18 + Tailwind v4 SPA. Blade is used only for PDFs (`backend/resources/views/pdf`).
- `packages/`: reusable modules used by both apps. `certificates` (Composer, `ahl/laravel-certificates`) and `certificates-react` (npm, `@ahl/certificates-react`), and `id-card-reader` (npm, `@ahl/id-card-reader`: Bahrain ID card client, React components, reception PC setup kit). App-specific behaviour lives in adapters (`backend/app/Certificates`, `frontend/src/app/certificates.tsx`, `frontend/src/app/idCard.tsx`), not in the packages.

## UI work

Any change to UI in `frontend/src` or the Blade PDFs follows the UI engineering skills in `.ui/skills/` (registered as project skills in `.claude/skills/`):

1. `ui-audit`: audit before editing
2. `ui-design-system`: tokens and the master pattern (source of truth)
3. `component-standardization` / `ui-refactoring`: reuse the primitives in `frontend/src/components/ui.tsx`
4. `responsive-design`: 320–1440px, RTL and LTR
5. `visual-qa`: final gate (`npm run build`, `npm run lint`, audit greps)

`laravel-blade-ui` guards every step: UI changes never touch routes, controllers, API types, permissions or form logic.
