---
name: laravel-blade-ui
description: The guard rail for UI work in this Laravel app. Keep the architecture as it is: a Laravel 12 JSON API, a React SPA frontend, and Blade only for PDF templates. UI work must never touch routes, controllers, validation, permissions, auth, data contracts or business logic. It also covers styling the Blade PDF views safely.
---

# Laravel + Blade UI guard rails

## Position in the system

This skill is a constraint on every other skill, not a step. Before any edit, check it against the rules below.

## Architecture (as it is, keep it that way)

| Layer | Location | Tech |
|---|---|---|
| API | `backend/app`, `backend/routes/api/*.php` | Laravel 12, JSON resources, Sanctum, spatie permissions |
| Staff / family / public UI | `frontend/src` | React 18 SPA, Tailwind v4, react-router, react-query, i18next |
| PDF documents | `backend/resources/views/pdf/*.blade.php` | Blade + dompdf, ArPHP shaping via `pdf_ar()` |
| Welcome page | `backend/resources/views/welcome.blade.php` | Laravel default, not user-facing |

- **Never convert Blade to React, and never convert React to Blade.** Do not introduce Livewire, Inertia, Vue or a UI kit.
- Do not add npm or composer dependencies for UI work. Tailwind plus the existing primitives are enough.

## Never change (in a UI task)

- `backend/routes/**`, controllers, form requests, policies, services, models, migrations, seeders, enums
- API resource shapes (`app/Http/Resources/*`) and `frontend/src/api/*.ts` request/response types
- Frontend routing (`app/routes.tsx`), guards (`RequirePermission`, `AuthContext`), `can()` checks, nav permission lists (`app/nav.ts`)
- Form behaviour: `react-hook-form` registrations, zod schemas, submit handlers, mutation calls, query keys, validation messages
- Translation keys (you may add keys; do not rename or remove any)

Change only JSX structure and classes, and only where the design system requires it. If a UI fix seems to need one of the items above, stop and report it as "needs a decision".

## Preserving behaviour in React edits

- Keep every `onClick`, `onChange`, `onSubmit`, `disabled`, `type`, `name`, `id`, `htmlFor`, `aria-*`, `data-*` and `key` attribute.
- Keep conditional rendering and permission gates (`can('x') && ...`) exactly as they are.
- When you swap a raw element for a primitive, pass the same props through (the primitives spread `...rest`).
- `type="submit"` buttons: primitives default to `type="button"`, so pass `type="submit"` explicitly.

## Blade PDF templates

- Keep `@extends('pdf.base')`, `@section`, `@yield`, `@foreach`, `@if`, `{{ }}` and `{!! !!}` exactly.
- Arabic text **must** go through `pdf_ar()`, and Arabic blocks use class `.ar` (ArPHP glyph reversal). Do not "fix" that direction CSS.
- dompdf supports CSS 2.1 plus a few extras. No flex, no grid, no CSS variables. Use tables for layout.
- The palette mirrors the web tokens: emerald `#2E6B4F`, gold `#B8872E`, ink `#1B2B28`, muted `#4E5F59`, line `#CBD6CE`, head `#E3EAE3`. Put new shared styles in `pdf/base.blade.php`, not in each view.
- Inline `style=""` is normal in dompdf templates. Consolidate into base classes only when they repeat.

## Verification after any UI change

1. `cd frontend && npm run build` passes (the build type-checks).
2. `cd frontend && npm run lint` shows no new errors.
3. If a Blade PDF changed: `cd backend && php artisan test --filter=Pdf` (or the related feature test) passes.
4. `git diff --stat` shows only view files. No `api/`, `routes`, `app/Http` or `app/Services` files.
