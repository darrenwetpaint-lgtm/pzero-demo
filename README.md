# PZero Service Management Assessment

This repository is the technical assessment project for PZero: a Laravel + Vue application that accepts service-activation requests from an external portal API, processes them safely and idempotently through a background queue, and gives operators a session-authenticated interface to view, diagnose, and retry them — all scoped per organisation.

## Requirements

- PHP 8.4 (developed and verified against PHP 8.4.25)
- Composer 2.x
- Laravel 13 (verified against 13.32.0)
- MariaDB 11.4+ (verified against 11.4.12)
- Node.js and npm (verified against Node v24.14.1 / npm 11.11.0)

## Installation

```bash
composer install
npm install
cp .env.example .env   # Windows Command Prompt: copy .env.example .env
php artisan key:generate
```

After copying `.env.example` to `.env`, add your local MariaDB credentials (see [Database](#database) below). `.env` is git-ignored and must never be committed.

## Database

This project uses Laravel 13's native MariaDB connection.

```env
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pzero_service_manager
DB_USERNAME=your_local_db_user
DB_PASSWORD=your_local_db_password
```

Create the database and a dedicated local database user (do not use the MariaDB `root` account for the application connection), grant that user privileges on `pzero_service_manager` only, and set the matching credentials in `.env`.

Once configured, run the migrations and seed the demo data (organisations, customers, operator logins, portal tokens, and a representative spread of service requests):

```bash
php artisan migrate --seed
```

(`php artisan migrate:fresh --seed` instead, if you need to rebuild the database from scratch.)

## Running locally

Each of these can be run in its own terminal:

```bash
php artisan serve       # HTTP server
npm run dev              # Vite dev server (HMR)
php artisan queue:work   # Database queue worker
```

Alternatively, `composer run dev` starts all three concurrently (`php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`, and `npm run dev`) in a single terminal, using the `dev` script defined in `composer.json`.

**Use one or the other, not both.** `composer run dev` already starts its own `npm run dev` as one of its concurrent processes — running a separate `npm run dev` in another terminal at the same time starts a second Vite dev server (Vite will silently pick the next free port, e.g. `5174` instead of `5173`), which is confusing and unnecessary. If you're not sure whether one is already running, check for a process listening on port `5173` before starting another.

### Background activation processing

Submitting a service request via the portal API (`POST /api/v1/service-requests`) dispatches a background job onto the database queue to activate it. That job only runs once a queue worker is running:

```bash
php artisan queue:work
```

Activation currently talks to a deterministic **fake** provider (no real external service), keyed by the request's `service_id`, so the demo scenarios are reproducible:

| `service_id` | Behaviour                                                                                           |
| ------------ | --------------------------------------------------------------------------------------------------- |
| `6221`       | Activation succeeds immediately → `completed`                                                       |
| `6222`       | Provider reports unavailable → retried with backoff, up to 3 attempts, then `failed`                |
| `6223`       | Provider times out → `uncertain`, then automatically reconciled via a provider lookup → `completed` |

The automated test suite exercises all of this without a running queue worker and without any real delays — the fake provider and job logic are invoked directly/synchronously in tests, so retries and backoff are asserted as configuration rather than actually waited out.

If a request ends up in the `uncertain` status and the one automatic reconciliation lookup is inconclusive, it is left in that state deliberately for manual/operator review — see `DECISIONS.md` for the reasoning.

## Portal API

The portal API is a separate, token-authenticated interface from the operator web login above — it's how an external caller (e.g. a billing/ordering system) submits a service-activation request on behalf of one of the two seeded organisations.

### Portal test credentials (fictional, local assessment only)

These are only valid after running the database seeder (`php artisan db:seed`, or `php artisan migrate:fresh --seed`):

| Organisation      | `client_id` | Bearer token                     | Example `customer_id` |
| ----------------- | ----------- | -------------------------------- | --------------------- |
| Northstar Telecom | `18`        | `northstar-portal-test-token-18` | `10482`               |
| Bluewave Services | `27`        | `bluewave-portal-test-token-27`  | `20482`               |

Only the SHA-256 hash of each token is stored (`portal_credentials.token_hash`); these raw values exist only because this seeder deterministically generates them for local testing — they are not real credentials of any kind.

### Submitting a request

With a queue worker running (`php artisan queue:work`, or via `composer run dev`), submit a request for Northstar:

```bash
curl -X POST http://localhost:8000/api/v1/service-requests \
  -H "Authorization: Bearer northstar-portal-test-token-18" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-request-001" \
  -d '{
        "client_id": 18,
        "customer_id": 10482,
        "service_id": 6221,
        "action": "activate",
        "requested_by": "customer",
        "amount": "199.99",
        "currency": "ZAR"
      }'
```

`service_id` determines the fake provider's outcome — see the [Background activation processing](#background-activation-processing) table below (`6221` succeeds, `6222` fails after retries, `6223` ends up `uncertain` then reconciles to `completed`). The `Idempotency-Key` must be unique per logical request; resubmitting the same key with the same body replays the original response instead of creating a duplicate.

Once the queue worker picks the job up, log in as the matching operator (see below) and open [Service Requests](#operator-demo-credentials-fictional-local-assessment-only) to watch the request move through its lifecycle and view its history.

## Operator interface

Authenticated operators (session/Fortify login, not the portal Bearer token) land on `/dashboard`, a small organisation-scoped summary (Total, Completed, In Progress, Needs Attention) linking through to the full list, and can view their own organisation's service requests at:

```
/service-requests
/service-requests/{id}
```

The list supports a status filter, sortable columns (Reference, Customer, Service ID, Amount, Status, Created), pagination, and a manual refresh button; the detail page shows the request and its full processing history, and its customer links back to a filtered view of that customer's own requests. Viewing or refreshing these pages is read-only — it never triggers provider activation.

### Retry

The detail page offers a **Retry** action, but only when the backend can honour it safely, and it means different things depending on why the request needs attention:

- **`failed`** — every path to `failed` is three consecutive, explicit "provider did not accept this" outcomes, so the provider never activated anything. Retry restarts activation from scratch (`queued`, attempts reset).
- **`uncertain`** — the provider's outcome is genuinely unknown, so retrying **never** calls the provider's activation operation again (that would risk double-activating a real service). It only re-checks status with the provider (a lookup), using the same correlation id from the original attempt.
- Every other status (`queued`, `processing`, `retrying`, `completed`) has no Retry action — either it's already progressing on its own, or (for `completed`) it must never be reopened.

**Cancellation/reversal is deliberately not supported.** There is no way to cancel a queued request or reverse a completed activation in this application, because the underlying provider abstraction has no cancel/deactivate operation to call — adding a button for it would be fake functionality with nothing behind it.

### Operator demo credentials (fictional, local assessment only)

| Organisation      | Email                  | Password   |
| ----------------- | ---------------------- | ---------- |
| Northstar Telecom | `alice@northstar.test` | `password` |
| Bluewave Services | `bianca@bluewave.test` | `password` |

These only exist after running the database seeder (`php artisan db:seed`) and are not real credentials of any kind.

## Tests

```bash
php artisan test
```

## Production frontend build

```bash
npm run build
```

## API request auditing

Portal API requests that are rejected before they can produce (or affect) a normal service request — an invalid/missing/revoked Bearer token, a validation failure, a `client_id` mismatch, a customer that doesn't belong to the calling organisation, or an idempotency-key conflict — are recorded to a durable `api_request_audits` table: HTTP method/route, status code, a short reason code, a small whitelisted context (e.g. which fields failed validation, never their values), and a hashed IP. Raw tokens, the `Authorization` header, passwords, and raw request bodies are never persisted. Successful submissions and idempotent replays are not audited — the service request and its own history already represent those. This is currently an internal audit trail only; there is no operator-facing screen for it yet.

## Setup limitations

This project was developed and verified on Windows with Laravel Herd and a local MariaDB instance; the versions pinned in [Requirements](#requirements) are what was actually tested against, not a guaranteed compatibility range. There is no Docker/Sail setup — a reviewer needs PHP, Composer, Node, and MariaDB installed locally as described above. A GitHub Actions workflow (`.github/workflows/tests.yml`) runs the automated checks in CI, but does not run or seed against a real MariaDB instance the way local development does.

## Current status

- MariaDB connectivity verified (native `mariadb` driver, dedicated non-root application user)
- Standard Laravel migrations applied; database queue infrastructure (`jobs`, `job_batches`, `failed_jobs`) present
- Portal API submission, idempotency, background activation processing, operator Retry/reconciliation, API request auditing, and the operator Inertia/Vue interface are all implemented
- Full automated test suite passing
- Vue/Vite production build passing
