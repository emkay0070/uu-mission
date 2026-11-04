# UU Mission

A mission operations and missionary management platform for Uganda Union Mission, connecting campaigns, missionaries, fieldwork, reporting and mission coordination.

This repository contains the **entire UU Mission project** as a single monorepo:

```
uu-mission/
├── backend/     Laravel API (PHP)
├── frontend/    Next.js missionary workspace (TypeScript)
├── docs/        Architecture notes and decision records
├── README.md
└── .gitignore
```

## Tech stack

| Layer     | Technology                                            |
| --------- | ----------------------------------------------------- |
| Backend   | Laravel 13, PHP 8.3, Sanctum token auth               |
| Database  | PostgreSQL (production/local); SQLite used by tests   |
| Frontend  | Next.js 16 (App Router), React 19, TypeScript         |
| Styling   | Tailwind CSS 4, Framer Motion, lucide-react           |

## Repository layout

- `backend/app/Http/Controllers` — API controllers
- `backend/app/Services` — domain services (e.g. `AuthorizationService`, `ReportPreparationService`)
- `backend/app/Policies` — policy-driven authorisation
- `backend/app/Models` — Eloquent models
- `backend/routes/api.php` — all API v1 routes (`/api/v1`)
- `backend/database/migrations` — schema history
- `backend/database/seeders` — roles, permissions and reference data
- `frontend/src/app/(missionary)` — authenticated missionary workspace
- `frontend/src/app/admin` — administrative workspace
- `frontend/src/lib` — API client, permissions and shared helpers

## Prerequisites

- PHP 8.3+ with the `pgsql` extension, and Composer
- Node.js 20+ and npm
- PostgreSQL (for local development)

## Backend setup

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
# Set the PostgreSQL connection values (DB_*), then:
php artisan migrate --seed
php artisan serve
```

The API is served under `/api/v1`.

The Mission Translator uses the Sunbird AI API. Set `SUNBIRD_API_TOKEN` in the
backend environment (see `backend/.env.example`); the token is read server-side
via `config('services.sunbird.token')` and is never exposed to the frontend.

## Frontend setup

```powershell
cd frontend
npm install
npm run dev
```

The workspace runs on port `3100` by default. `/api/*` requests are proxied to
the backend by a Next.js rewrite.

Two optional environment variables control where the frontend reaches the API:

| Variable               | Used by                        | Default                 |
| ---------------------- | ------------------------------ | ----------------------- |
| `BACKEND_URL`          | Next.js rewrite (build time)   | `http://localhost:8000` |
| `NEXT_PUBLIC_API_URL`  | Speech-to-text / text-to-speech (browser, build time) | empty (same origin) |

Leave both unset for local development and for single-host deployments. Set them
when the frontend and backend run as separate services.

## Testing

```powershell
# Backend — uses PostgreSQL test database (uu_mission_test)
cd backend
php artisan test

# Frontend — type safety
cd frontend
npx tsc --noEmit
```

## Version control

The following must **never** be committed (they are excluded by `.gitignore`):

- `.env` and `.env.*` files containing real secrets — only `.env.example` placeholders are tracked.
- Installed dependencies: Laravel `vendor/` and Next.js `node_modules/`.
- Build output: Next.js `.next/`, `out/`, `dist/`.
- Database dumps and backups (`*.sqlite`, `*.sql`, `*.dump`, `*.bak`).
- Private uploads under `backend/storage/app/private/`.
- API keys, tokens and credentials.

If you add a new secret or generated artefact, add an ignore rule at the same time.
