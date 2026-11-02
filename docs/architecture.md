# Architecture and conventions

## Shape of the system

One Laravel API (a modular monolith) serves the customer website, the admin portal and later the mobile app. PostgreSQL holds every record; Redis runs queues, caches and rate limits; Laravel Reverb pushes live campaign progress.

```
apps/web (Next.js) ──┐
apps/admin (React) ──┼──> apps/api (Laravel, /api/v1) ──> PostgreSQL
apps/mobile (Expo) ──┘                │                 ──> Redis (queues, cache)
                                      └──> Moyasar, SMS, courier APIs
```

## API contract

`apps/api/openapi/v1.yaml` (OpenAPI 3.0) is the contract every client builds against. It covers the customer and admin endpoints for the whole MVP, ahead of the code:

- Each operation says which epic ships it (`x-epic`) and whether Laravel serves it yet (`x-status: live`) or only the mock server does (`x-status: mock`).
- The mock server (Prism) answers every operation from the contract, so the website and admin portal can be built before the backend. It also rejects requests that break the contract.
- In the test suite, every response from an `/api/v1` route is checked against the contract, and a coverage test fails if a route is missing from the contract, if a served route is still marked `mock`, or if a `live` operation has no route.
- An API change and its contract change go in the same pull request. When an endpoint ships, flip its `x-status` to `live` in that pull request.

## Customer website

`apps/web` is a Next.js app that renders on the server and calls the API from there, with the customer's token kept in an httpOnly cookie. The browser calls the API directly only for public reads (campaign progress polling). Types come from the contract (`npm run api:types`), and CI fails if they drift. Its browser tests run against a stateful fixture API behind Prism in proxy mode, so a request that breaks the contract fails the build. Details: [apps/web/README.md](../apps/web/README.md).

## Domain modules

Business code is grouped by domain under `apps/api/app/Modules/<Domain>`, added as each epic starts:

| Module | Owns | Epic |
| --- | --- | --- |
| Identity | customers, OTP, addresses, admin users, roles | E5 |
| Catalog | products, variants, images, categories | E6 |
| Supplier | suppliers, quotations, landed cost | E6 |
| Campaign | campaigns, participations, reservations, closing job | E7, E10 |
| Pricing | tiers, hold price, final price, margin check | E8 |
| Payments | holds, captures, voids, refunds, webhooks, reconciliation | E9, E15 |
| Orders | customer orders, invoices, supplier purchase orders | E11, E12 |
| Fulfillment | inbound receipts, shipments, tracking | E13 |
| Notifications | SMS and push messages | E14 |
| Finance | ledger, approvals | E15 |

A module may call another module's public services, never its tables directly. Shared building blocks live in `app/Support`.

## Shared building blocks (`app/Support`)

| Class | Use it for |
| --- | --- |
| `Money\Money` | Every amount. Integer minor units, one currency, overflow-safe, VAT split per the spec's rounding rule. |
| `Actor` | Who did something: `Actor::system()`, `Actor::admin($id)`, `Actor::user($id)`. |
| `Audit\AuditLogger` | Record every admin action and every action that moves money. |
| `Audit\StatusHistory` | Record each status change, inside the same transaction as the change. |
| `RequestContext` | The request ID (logged) and IP and user agent (hidden from logs). It travels into queued jobs. |
| `Database\AppendOnly` | Add update, delete and truncate triggers to a history table in its migration. |

## Conventions

- **Money:** columns end in `_halalas` (or `_minor` for supplier currencies) and are `bigint`. Customer prices include 15% VAT.
- **Time:** `timestamptz` columns in UTC, and the database session time zone is pinned to UTC in `config/database.php`. Convert to `Asia/Riyadh` only for display.
- **Client IP:** set `TRUSTED_PROXIES` to the load balancer's addresses in each deployed environment, or audit logs record the balancer's IP instead of the customer's.
- **Language:** API messages follow `Accept-Language`, Arabic by default. Customer-facing text columns come in `_ar` and `_en` pairs.
- **Statuses:** a `varchar` column with a CHECK constraint, mirrored by a PHP enum. Each change writes a `status_transitions` row.
- **History is append-only:** a trigger rejects UPDATE, DELETE and TRUNCATE on history tables. The trigger stops mistakes from the app or a console session, not the table owner, who can drop it. In production, migrations run as an owner role and the app runs as a separate role with only SELECT and INSERT on history tables (plus USAGE on their sequences). The grants are the real boundary.
- **Business numbers live in `config/groupbuy.php`:** campaign length, hold lifetime, capture cutoff, minimum margin. Each one cites the decision behind it.
- **Request IDs:** every response has an `X-Request-Id` header. The ID appears in logs, audit records and status history.

## Testing

- Tests run against PostgreSQL, not SQLite, because triggers, `jsonb` and row locks behave differently.
- Concurrency rules (for example, 200 joins racing for the last 10 units) get tests that run real parallel transactions, added with E7.
- CI lints the contract with Redocly and runs Pint and the full test suite on every pull request.
