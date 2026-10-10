# AGENTS.md

This repository is the GuideLink student social hub / guidance portal. The app is a Laravel 13 backend with an Inertia + React + TypeScript frontend and Tailwind styling.

## Project layout

- `app/` — Laravel application code
  - `Actions/` — command or workflow-style business logic
  - `Http/Controllers/` — route handlers; keep these thin
  - `Models/` — Eloquent models
  - `Policies/` — authorization rules
  - `Enums/` — typed domain enums such as `UserRole`
- `routes/` — HTTP routes; `web.php` is the main entry point
- `database/` — migrations, factories, seeders
- `resources/js/` — React + Inertia frontend
  - `app.tsx` — app bootstrap
  - `components/`, `layouts/`, `pages/`, `hooks/`, `lib/` — UI and client code
- `public/build/` — generated frontend assets; do not edit by hand
- `tests/` — PHPUnit test suite

## Working conventions

- Follow the existing Laravel conventions: routes in `routes/*.php`, controllers in `app/Http/Controllers`, models in `app/Models`.
- Prefer domain logic in `app/Actions` or appropriately named service-style classes instead of embedding logic directly in controllers.
- Keep controller actions focused on authorization, request validation, and returning an Inertia response or redirect.
- Frontend pages use Inertia page names; page names typically map to `resources/js/pages/...` and route names in `routes/web.php`.
- Reuse existing UI components under `resources/js/components` and layouts under `resources/js/layouts` before introducing new patterns.
- Respect the role and authorization model already in `UserRole` and `EnsureSuperadmin`/middleware checks.

## Development commands

- Install and bootstrap: `composer run setup`
- Start the app: `composer run dev`
- Start frontend Vite dev server: `npm run dev`
- Lint and format PHP: `composer run lint`
- Frontend validation: `npm run check`
- Type check: `npm run types:check`
- Static analysis: `composer run types:check`
- Run tests: `php artisan test` or `composer test`
- CI-style validation: `composer run ci:check`

## Before making changes

- Check the relevant route and controller before adding or changing endpoints.
- Match the repo’s naming and authorization patterns rather than introducing a parallel structure.
- For database work, include the migration and any related factory/seed changes as needed.
- For UI work, prefer the existing shadcn-style component structure and Inertia patterns instead of building ad hoc patterns.

## Key project-specific notes

- The app is intentionally organized around Inertia pages, not a separate SPA router.
- The default app title comes from `resources/js/app.tsx` and is set via `VITE_APP_NAME`.
- Route names are meaningful and grouped by feature (`dashboard`, `admin.*`, `guidance.*`, `student.*`). Preserve that structure when editing or adding routes.
- This project already uses Laravel Fortify for authentication and role checks around student password resets and super-admin access.
