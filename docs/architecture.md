# Architecture and conventions

## Shape of the system

One Laravel API (a modular monolith) serves the customer website, the admin portal and later the mobile app. PostgreSQL holds every record; Redis runs queues, caches and rate limits; Laravel Reverb pushes live campaign progress.

```
apps/web (Next.js) ──┐
apps/admin (React) ──┼──> apps/api (Laravel, /api/v1) ──> PostgreSQL
apps/mobile (later) ─┘                │                 ──> Redis (queues, cache)
                                      └──> Moyasar, SMS, courier APIs
```

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
- **Time:** `timestamptz` columns in UTC. Convert to `Asia/Riyadh` only for display.
- **Language:** API messages follow `Accept-Language`, Arabic by default. Customer-facing text columns come in `_ar` and `_en` pairs.
- **Statuses:** a `varchar` column with a CHECK constraint, mirrored by a PHP enum. Each change writes a `status_transitions` row.
- **History is append-only:** a trigger rejects UPDATE, DELETE and TRUNCATE on history tables. In production the app's database role should also lack those grants.
- **Business numbers live in `config/groupbuy.php`:** campaign length, hold lifetime, capture cutoff, minimum margin. Each one cites the decision behind it.
- **Request IDs:** every response has an `X-Request-Id` header. The ID appears in logs, audit records and status history.

## Testing

- Tests run against PostgreSQL, not SQLite, because triggers, `jsonb` and row locks behave differently.
- Concurrency rules (for example, 200 joins racing for the last 10 units) get tests that run real parallel transactions, added with E7.
- CI runs Pint and the full test suite on every pull request.
