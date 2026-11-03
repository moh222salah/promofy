# PROMOFY Platform

> **We Don't Just Market. We Make Brands Matter.**

PROMOFY is a creative marketing & digital-growth agency serving Egypt and the GCC. This repository holds its
digital business platform: a bilingual (Arabic RTL / English LTR) corporate website, services catalog, digital
store, CRM, quotations, project management, CMS and an enterprise admin dashboard.

The full specification lives in the PROMOFY project (master spec + "Direct Execution" rules). This README only
tracks **what exists in the repository today**, honestly.

## Stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13 (PHP 8.3+), REST API under `/api/v1`, Sanctum SPA cookie auth |
| Frontend | React 19 + TypeScript + Vite, Tailwind CSS, React Router, TanStack Query, React Hook Form + Zod, Recharts *(planned — not yet added)* |
| Database | SQLite for local dev/tests, MySQL 8 (or PostgreSQL) for production |
| Queue / cache | Database driver by default, Redis optional |

## Repository layout

```
backend/    Laravel API (domain-organised, policies, form requests, API resources, services)
frontend/   React SPA: public website + admin dashboard   (planned)
docs/       Architecture, API, integrations, deployment, backup, admin guide   (planned)
```

## Run the backend locally

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve          # http://localhost:8000/api/v1/health
php artisan test           # automated tests
```

## Status (honest labels: COMPLETE / PARTIAL / BLOCKED / NOT STARTED)

| Area | Status | Notes |
| --- | --- | --- |
| Application foundation (Laravel 13, `/api/v1` prefix, Sanctum, JSON errors, health endpoint) | COMPLETE | verified by tests |
| Auth (login, lockout, register, email verification, password reset, TOTP 2FA, sessions, login history), RBAC (11 roles, 28 permission modules, backend Gate enforcement), append-only audit log, generic admin CRUD kit (search, filter, sort, pagination, bulk, CSV export), staff users and roles admin API | COMPLETE | `php artisan test`: 28 tests, 154 assertions |
| Services, products, store, checkout, orders, payments | NOT STARTED | |
| CRM, quotations, projects | NOT STARTED | |
| CMS, homepage builder, media library, navigation | NOT STARTED | |
| Admin dashboard + analytics | NOT STARTED | |
| Public website (AR/EN) | NOT STARTED | |
| OpenAPI docs, deployment & backup docs, admin guide | NOT STARTED | |

## Notes

* `backend/composer.lock` was generated from the packages' GitHub tags because the build sandbox could not reach
  Packagist. It installs normally with `composer install`; run `composer update` at any time to re-resolve from Packagist.
* No demo customers, orders, testimonials, case studies or metrics are ever seeded — every business record comes from real use.
* Secrets never live in the repository: copy `.env.example` to `.env` and fill real values on the server.

---
