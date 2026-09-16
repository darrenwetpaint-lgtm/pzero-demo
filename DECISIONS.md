# Decisions

## Architecture

The project uses Laravel 13 as the application/backend, with Vue 3 + Inertia + Vite for the frontend, in a single repository (no separate frontend or API repository).

The official Laravel Vue starter kit was used to scaffold the project, in order to minimise setup overhead and keep the assessment focused on business behaviour rather than boilerplate configuration.

## Request Lifecycle

`POST /api/v1/service-requests` covers acceptance, idempotency, and — via the background job dispatched on acceptance — full activation processing:

1. `portal.token` middleware resolves the `Organisation` from the hashed Bearer token; this organisation is trusted, never the request body.
2. The `client_id` in the body must equal the authenticated organisation's `organisations.client_id`, or the request is rejected — a valid token does not authorise acting as a different organisation.
3. `customer_id` resolves against `customers.customer_number` scoped to that organisation; a customer belonging to another organisation is indistinguishable from a non-existent one in the response.
4. The `Idempotency-Key` header plus a canonical request fingerprint decide whether to create a new request, replay an existing one, or reject the payload as a conflicting reuse of the key (see below).
5. A newly accepted request is always created with `status = queued`, regardless of any `status` value supplied in the payload, and gets exactly one `ServiceRequestEvent` recording that acceptance.
6. Only a genuinely new request dispatches `ActivateServiceRequestJob` (on the database queue), and only after its creating transaction has committed — a replay or a conflicting idempotency key never dispatches a job.

Background processing (`ActivateServiceRequestJob`, using the `ActivationProvider` bound to `FakeActivationProvider`):

7. `queued`/`retrying` → the job ensures a `provider_request_id` (a UUID) exists — generated once and reused for every subsequent attempt/lookup on that request, never regenerated — transitions to `processing`, increments `provider_attempts`, records `last_attempted_at`, then calls `activate()`. Local state is always persisted _before_ the provider call, never inside a transaction that spans it.
8. Success → `completed` (`completed_at` set). Unavailable → `retrying` with a queued backoff (`release()`, 5s then 15s) for up to 3 total attempts, then `failed` on the third; the local `status`/`provider_attempts` are the business source of truth, independent of Laravel's own `failed_jobs` table. Timeout → `uncertain`, with exactly one automatic follow-up reconciliation scheduled (no further activation attempts).
9. `uncertain` → the job calls `lookup()` (never `activate()` again) using the same `provider_request_id`. Confirmed active → `completed`. Inconclusive → remains `uncertain` with an event recorded, and no further automatic reconciliation is scheduled — see the provider-contract assumption below.
10. `completed`/`failed` are terminal: the job returns immediately without calling the provider, so redundant queue delivery is always safe.

Operator web interface (`/service-requests`, `/service-requests/{id}`):

11. Operators authenticate via the existing Laravel/Fortify session guard — the same login used for `/dashboard` — never the portal Bearer token, which the browser never sees. Access control on these routes is the `auth` middleware only. **Email verification is intentionally not part of this demo**: `App\Models\User` does not implement `MustVerifyEmail` (the starter kit ships this commented out), which makes Laravel's `verified` middleware a no-op regardless of `email_verified_at` — so it was removed from these routes rather than left in place implying an enforced control that wasn't real. This is consistent with the demo's fictional seeded operators and `MAIL_MAILER=log` (no real mail delivery to verify against).
12. The organisation scope for every operator query is `auth()->user()->organisation_id`; there is no other source of tenant scope on these routes.
13. Both routes query Eloquent directly, explicitly filtered by that organisation id — no implicit/unscoped route-model binding. A request belonging to another organisation returns `404` from the detail page, identically to a non-existent id.
14. The operator pages call the domain models directly rather than the portal API — the portal API remains solely the token-authenticated external interface; the two never share a request path.
15. Viewing or refreshing a service-request page is purely observational: it only reads current database state and never dispatches or invokes the activation job/provider.

Operator Retry (`POST /service-requests/{id}/retry`):

16. Retry is only ever offered/accepted from two statuses, because it means something different — and only these two are actually safe:
    - `failed` → `queued`, with `provider_attempts` reset to 0 (a fresh 3-attempt budget) and `provider_request_id` left untouched. Safe because every path to `failed` is three consecutive, explicit "provider did not accept this" outcomes — the provider never activated anything.
    - `uncertain` → unchanged, but the job is re-dispatched. Its existing routing calls `lookup()` only, never `activate()` again — retrying `uncertain` by re-activating would risk double-activating a real service, which is exactly what the whole `provider_request_id`/reconciliation design exists to prevent.
17. Every other status (`queued`, `processing`, `retrying`, `completed`) rejects the retry with no mutation, no event, and no dispatch — `completed` must never be reopened, and the others are already progressing on their own.
18. A short `Cache::lock` plus the action's own row lock and status re-check make a rapid double-submission of Retry safe: a second concurrent attempt is rejected before it can mutate anything or dispatch a second job.

**Cancellation/reversal is deliberately not implemented.** `ActivationProvider` has no cancel/deactivate operation, and no `cancelled` status exists in the locked status list. Implementing a cancel action would mean inventing behaviour the domain doesn't define. Adding real support would require, at minimum: a `cancel()`/`deactivate()` method on the provider abstraction (and the fake), a decision on what "cancelling" an unstarted `queued` request means versus "reversing" an already-`completed` one (materially different operations), and — if a new terminal state is genuinely required — extending the locked status list deliberately, not incidentally.

## Assumptions and Contract Changes

- The portal API's incoming `customer_id` field resolves against `customers.customer_number` (an external, organisation-scoped reference number), **not** the internal `customers.id` primary key.
- The portal API's incoming `client_id` field is validated against `organisations.client_id`, which identifies the calling organisation.
- `requested_by` currently only accepts `"customer"`, matching the documented portal contract; no other values are supported yet.
- The portal-supplied `status` field is accepted (so the documented sample payload validates) but is always ignored — the locally stored status is always `queued` on creation and is otherwise controlled only by the internal processing logic described above (`ActivateServiceRequestJob` and Operator Retry).

- The application generates `provider_request_id` locally, before the first `activate()` call, and would supply it to a real provider as the correlation identifier for that request — it is never provider-issued.
- A "timeout-after-success" outcome is reconciled using a provider `lookup()` call against that same `provider_request_id`; activation is never blindly repeated after an uncertain timeout.
- We are deliberately omitting a webhook/callback from this assessment implementation because deterministic `lookup()` already provides the required reconciliation path for the fake provider's scenarios.
- If a real provider offered **neither** a reliable `lookup()` **nor** a callback/webhook, a timed-out request would have no safe automatic path to resolution and must remain `uncertain` indefinitely for manual/operator reconciliation — this is an explicit limitation of the current design, not a claim about what a real provider supports.

## Data, Security and Processing Decisions

- Laravel's native MariaDB connection (`DB_CONNECTION=mariadb`) is used rather than the generic `mysql` driver.
- A dedicated local database user (`pzero_app`) is used for the application connection instead of the MariaDB `root` account.
- Laravel's database-backed queue (`QUEUE_CONNECTION=database`) is used for background processing.
- Secrets (database credentials) remain in the local `.env` file only and are not committed.
- `project-assets` (private assessment reference material provided outside this repository) is excluded from Git via `.gitignore`.
- Public user registration is disabled (`Features::registration()` removed in `config/fortify.php`); operator accounts are seeded directly with an organisation assignment instead, since self-registration would otherwise leave a new user without one.
- `service_requests.display_reference` is deliberately **not** database-unique: its mandated `{customer-number}-{unix-timestamp}` format can collide for two legitimate requests to the same customer within the same second, so it is a display aid, not an identifier.
- `service_requests.provider_request_id` is a UUID generated locally _before_ contacting the external provider, rather than relying on a provider-issued reference — this guarantees a correlation id exists even if a request times out before the provider's own reference reaches us.
- `service_requests.status` is stored as a plain string rather than a database enum, so new statuses can be introduced without a schema migration.
- Operator-facing timestamps (Service Requests index/detail/history) are displayed in the **operator's own browser-local timezone**, since the server cannot know it. This is SSR-safe via `components/LocalDateTime.vue`: it renders a fixed, deterministic UTC-formatted value identically during SSR and the client's first paint (so hydration never mismatches — this was a real, observed bug before this component existed), then swaps to the browser's local timezone in `onMounted()`, which only ever runs client-side, after hydration has already completed. The UTC value is a transient bridge, never the intended steady-state display.
- Portal API credentials are stored as a SHA-256 hash (`portal_credentials.token_hash`) only; the raw bearer token is never persisted.
- Portal API authentication is a small custom Bearer-token middleware (`AuthenticatePortalToken`) rather than Sanctum or another package: the raw token is hashed with SHA-256 and looked up against `portal_credentials.token_hash`; a missing, unknown, or revoked (`revoked_at` not null) token returns `401`. The resolved `Organisation` is attached to the request via a request attribute/macro (`$request->organisation()`) rather than a tenancy package, and controllers/actions always use that resolved organisation — never a client-supplied identifier — to scope every query.
- Idempotency is enforced with the existing `(organisation_id, idempotency_key)` unique constraint plus a SHA-256 `request_fingerprint` over the canonical business fields (`client_id`, `customer_id`, `service_id`, `action`, `requested_by`, `amount`, `currency` — deliberately excluding the ignored `status`), with amount/currency normalised before hashing. Same key + same fingerprint replays the existing request (`200`); same key + different fingerprint is rejected (`409`) with no mutation; a new key always creates a new request (`201`) even for an identical payload. A pre-check query handles the common case, but the database's unique constraint is the actual race guard: a concurrent duplicate insert that loses the race is caught and re-resolved against the row the winner created, rather than trusting the pre-check alone.
- Any resource belonging to another organisation (a customer, or a service request) is returned as `404`, not `403`, so a valid token can never be used to detect whether another organisation's record exists.
- The `ActivationProvider` contract (`activate()`/`lookup()`) is bound to `FakeActivationProvider` in the container, keyed deterministically by `service_id` (`6221` success, `6222` unavailable, `6223` timeout-then-active-on-lookup) — no sleeps; the fake simulates outcomes, not timing, so tests stay fast.
- `ActivateServiceRequestJob` stores only the `ServiceRequest` id (not a serialized model), re-fetching fresh state on each execution.
- `api_request_audits` rows are written for portal requests rejected before they can produce (or affect) a normal `ServiceRequest`: missing/invalid/revoked Bearer token, validation failure, `client_id` mismatch, customer not found, and idempotency conflict. Each write is best-effort (wrapped in its own try/catch, logged on failure) so a failure to record an audit row can never turn a real 4xx response into a 500. Only whitelisted fields are stored (method, route, status code, a short reason code, a small safe context such as which fields failed validation — never their values — and a hashed IP); the raw token, `Authorization` header, and request body are never persisted. Successful submissions and idempotent replays are not audited, since the `ServiceRequest` and its own history already represent those.
- The operator Service Requests list's server-side column sorting (Reference, Customer, Service ID, Amount, Status, Created) uses a strict allow-list: the request-supplied `sort` value is only ever used as a lookup key into a hardcoded PHP array, and the SQL column name passed to `orderBy()` always comes from that array's value — an unrecognised `sort` value falls back to the default (newest-created-first) rather than reaching the query at all. Sorting by "customer" joins to `customers` and orders by its `name` column, since that isn't a column on `service_requests` itself. Every sort path adds an `id` tiebreaker so pagination stays stable.
- The dashboard's per-organisation summary (Total, Completed, In Progress, Needs Attention) is a single grouped-count query scoped by `organisation_id`, deliberately without any charting/analytics library — it's a small operational summary, not a reporting feature.
- Seeded demo `ServiceRequest`/`ServiceRequestEvent` rows are written directly via Eloquent (`ServiceRequestDemoSeeder`), never through `SubmitServiceRequest` or `ActivateServiceRequestJob::dispatch()`. This guarantees seeding never inserts a row into the `jobs` table, so a running queue worker has nothing to pick up for seeded `queued`/`processing`/`retrying` rows — they stay exactly as seeded rather than being mutated by real background processing.

## Failure Classification

The activation lifecycle produces failures of genuinely different kinds, and conflating them would be incorrect — each has a different safe recovery path, which is exactly why Retry only accepts two of the six statuses (see the Operator Retry section above). This section separates what is **implemented** in this assessment from what is a **production recommendation** for a scenario this assessment deliberately doesn't build.

### A. Request/API rejection

Happens **before** a `ServiceRequest` ever exists: authentication failure, request validation failure, `client_id` mismatch, customer-not-found, and idempotency-fingerprint conflict. **Implemented:** each returns the appropriate 4xx response and is recorded in `api_request_audits`; no `ServiceRequest` row and no activation job are ever created. There is nothing to retry in the activation sense — the caller corrects the request and resubmits.

### B. Failed activation (`status = failed`)

The provider explicitly and consistently declined to accept the request across all 3 attempts (`ActivationOutcome::Unavailable` on every attempt). **Implemented:** manual Retry is offered and safe from this state — it resets `provider_attempts` to 0 and requeues from scratch. This is safe specifically because every path into `failed` is a sequence of explicit "provider did not accept this" outcomes: nothing was ever activated, so a fresh attempt cannot duplicate anything.

### C. Uncertain activation (`status = uncertain`)

The request may or may not have reached/been actioned by the provider — either a genuine timeout (`ActivationOutcome::Timeout`) or a worker crash after the "processing" claim committed but before an outcome was recorded. **Implemented:** neither the job nor manual Retry ever calls `activate()` again from this state. The only permitted operation is `lookup()` against the already-committed `provider_request_id`, which either confirms `completed` or leaves the request `uncertain` for further reconciliation. `FakeActivationProvider` supports this directly — `service_id = 6223` deterministically times out on `activate()` and then reports "active" on the following `lookup()` — which is exactly what this assessment's tests and seeded demo data exercise.

**Production implication (not implemented — a recommendation only):** this design only works safely because the fake provider supports a reliable `lookup()`. A real-world provider offering **neither** a reliable lookup/status-check endpoint **nor** a callback/webhook, and issuing no idempotency token the caller could safely present on retry, would leave a timed-out request with no automatically safe path to resolution. It would have to remain `uncertain` indefinitely for manual, out-of-band reconciliation directly with the provider (e.g. a support ticket), because blindly retrying activation could double-activate a real subscriber service. That is a limitation of integrating with any provider lacking those guarantees — not a gap in this implementation.

### D. Internal/infrastructure errors

An uncaught exception in the job itself (e.g. a database error) rather than a provider outcome. **Implemented:** `WithoutOverlapping` plus the row-locked `claimForActivation()` guarantee the "processing" claim is committed _before_ `activate()` is ever called, and that commit is never undone by a later exception. Laravel's own job retry (`$tries = 3`, `backoff()`) redelivers the job on an uncaught exception; because the request is already `processing` by the time any provider-adjacent code could throw, that redelivery finds `processing` and reconciles via `lookup()` rather than calling `activate()` again — the same safe path used for a genuine timeout.

**Not implemented (a known, deliberate limitation, not a silent gap):** if an infrastructure error recurs until Laravel's own `$tries` is exhausted, the job lands in `failed_jobs` and the `ServiceRequest` is left in `processing` with no further automatic action or alerting. A production system would want monitoring/alerting for requests stuck in `processing` beyond an expected window, and/or a scheduled sweep that reconciles them via the same `lookup()` path already used here — only auto-retrying an internal error when it can be established the external side effect (the provider call) can't have been duplicated, which is precisely what routing a recovered `processing` row through `lookup()` rather than `activate()` already achieves.

## Trade-offs

The official Laravel Vue starter kit includes more authentication functionality (via Laravel Fortify, passkeys, etc.) than the assessment strictly needs. This was retained rather than stripped out, to save implementation time and provide a conventional, easily reviewable Vue/Laravel baseline.

## Time Spent

Approximate total time spent on this assessment (environment setup, implementation, testing, and documentation): **3 hours 53 minutes**.

## Working Features

- Laravel/Vue baseline (Laravel 13, Vue 3, Inertia, TypeScript, Vite)
- MariaDB connectivity (native driver, dedicated non-root user)
- Standard Laravel migrations
- Database queue infrastructure
- Domain schema and Eloquent models (Organisation, Customer, User, PortalCredential, ServiceRequest, ServiceRequestEvent, ApiRequestAudit) with relationships
- Seeded demo data: two organisations, their customers, one seeded operator each, and one hashed portal credential each
- Public registration disabled; seeded operator login working
- Portal API: hashed Bearer-token authentication, organisation isolation, `POST /api/v1/service-requests` with idempotent submission, `GET /api/v1/service-requests`, `GET /api/v1/service-requests/{id}` (with history), `GET /api/v1/customers/{customerNumber}`
- Background activation processing: `ActivateServiceRequestJob` on the database queue, `FakeActivationProvider` (deterministic success/unavailable/timeout by `service_id`), bounded retry with backoff, terminal-state short-circuiting, and uncertain-outcome reconciliation via `lookup()`
- Session-authenticated operator interface (`/service-requests`, `/service-requests/{id}`): filtered/sortable/paginated list (status filter, allow-listed column sorting, optional customer filter), detail page with chronological history, status badges, loading/empty/error states, all explicitly scoped to the operator's own organisation
- Organisation-scoped dashboard summary (Total / Completed / In Progress / Needs Attention) with a link into the Service Requests list
- Operator Retry: safe requeue from `failed`, reconciliation-only retry from `uncertain`, rejected (no-op) from every other status, double-submission-safe
- API request auditing (`api_request_audits`) for rejected/failed portal requests, with sensitive data excluded by design
- Baseline automated tests (Pest), including focused API/idempotency/isolation, job-lifecycle, operator-web-isolation, Retry, and audit coverage
- Frontend production build

## Incomplete / Production Improvements

- No operator-facing UI for `api_request_audits` yet — it is currently an internal audit trail only.
- Cancellation/reversal of a service request is not implemented (see the Retry section above for exactly why, and what would be required to add it).
- No monitoring/alerting for a `ServiceRequest` stuck in `processing` after the job's own retries are exhausted by a recurring infrastructure error (see Failure Classification, category D). A production system would want a scheduled reconciliation sweep and/or alerting for this, rather than relying on an operator noticing.

## Tools Used

- Visual Studio Code
- Claude Code — AI-assisted development and code review
- Laravel Herd — local PHP environment
- MariaDB Community Server
- Git and GitHub
- Laravel documentation
