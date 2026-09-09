# TransitFlow — Smart Transport Management Platform

TransitFlow is a modern, multi-company transport management platform built with
**Laravel** (JSON API) and **React** (single-page application).

It supersedes the legacy **BITS — Bus Income Tracking System** (vanilla PHP),
which serves as the functional source of truth during migration and is **not**
part of this codebase.

| | Legacy | New Platform |
| --- | --- | --- |
| Name | BITS — Bus Income Tracking System | TransitFlow — Smart Transport Management Platform |
| Stack | Vanilla PHP + MySQL | Laravel 13 API + React 19 SPA + MySQL |
| Path | `C:\xampp\htdocs\BITS` (read-only reference) | `C:\xampp\htdocs\TransitFlow` |

---

## Tech stack

**Backend**

- Laravel 13 (PHP 8.3+), REST JSON API under `/api`
- Laravel Sanctum for SPA + future mobile authentication
- MySQL / MariaDB (`transitflow` database)

**Frontend** (`resources/js`, built by Vite)

- React 19 + React Router 7 (client-side routing)
- Bootstrap 5 + Bootstrap Icons
- Axios (shared instance in `resources/js/lib/axios.js`)
- SweetAlert2 (modals) and React-Toastify (toasts)

The SPA is served from a single Blade shell (`resources/views/app.blade.php`)
via a catch-all web route; every non-`/api` path returns the shell and React
Router takes over.

---

## Local setup

Prerequisites: PHP 8.3+, Composer, Node 20+, and a running MySQL/MariaDB
(XAMPP is fine).

```sh
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
#   Ensure DB_DATABASE=transitflow exists:
#   mysql -u root -e "CREATE DATABASE transitflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 3. Migrate
php artisan migrate

# 4. Run (two terminals, or `composer run dev`)
php artisan serve      # http://localhost:8000
npm run dev            # Vite dev server / HMR
```

Production build of the frontend: `npm run build`.

### Health check

- `GET /api/health` → JSON `{ status, app, environment, laravel, time }`
- `GET /up` → Laravel framework health endpoint

---

## Documentation

- [`docs/MIGRATION_MAP.md`](docs/MIGRATION_MAP.md) — BITS → TransitFlow module mapping
- [`docs/PARITY_CHECKLIST.md`](docs/PARITY_CHECKLIST.md) — feature parity tracking

---

## Project phases

1. **Phase 1 — Base application** ✅ Laravel + React + Bootstrap stack running
2. **Phase 2 — Legacy analysis** Analyse BITS modules, DB, business rules
3. **Phase 3 — Multi-company foundation** Companies, isolation, company-aware authz
4. **Phase 4 — Auth & administration** Super Admin, Company Admin, users, roles/permissions
5. **Phase 5 — Module migration** Fleet, trips, tickets, remittance, reports, …
"# TransitFlow" 
