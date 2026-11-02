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

## Identity and sign-in (E5)

- **Two kinds of account, kept apart.** Customers are `users`; staff are `admin_users`. Each has its own Sanctum guard (`customer`, `admin`), and a guard accepts only its own model's tokens, so a customer token is refused on admin routes and the reverse. There are no sessions or cookies: every client sends `Authorization: Bearer`.
- **Customers** sign in with a Saudi mobile number and a 6-digit SMS code. The first sign-in creates the account. Tokens last 90 days, one per device.
- **Admins** sign in with email and password, then an SMS code to their phone. Only the code step issues a token, which lasts 12 hours. Sign-ins and sign-outs are audited.
- **Codes** are stored as an HMAC bound to the challenge ID, expire after 5 minutes, and are spent after 5 wrong tries. Rate limits are in `config/groupbuy.php` under `auth`: codes per number (customers) or per admin account, whatever the IP; requests per IP; and admin password attempts per email, per email and IP, and per IP.
- **Blocking** an account stops its tokens on the next request, before they expire.
- **Roles and permissions** use spatie/laravel-permission on the `admin` guard. `App\Modules\Identity\Permissions\AdminPermissions` is the source of truth; `admin:sync-roles` writes it to the database. Each admin operation in the contract names its permission in `x-permission`, and its route checks it with `->can('<permission>')`; a contract test keeps the catalogue, the contract and the routes in step. Endpoints check permissions, never role names. `approvals.decide` (approving refunds, manual captures and voids, ledger adjustments and campaign cancellations) belongs to finance alone, not to `super_admin`, so releasing money always takes finance.
- **Admin accounts** are managed in the portal by admins with `admins.manage`. Nobody can change their own roles, status or password there, and nobody can hand out more than they hold: an admin can grant only roles whose permissions they have, and can only block (not otherwise change) an admin who holds a permission they lack. So a super admin cannot create or take over a finance account; finance admins are created and changed on the server with `admin:create` and `admin:update`. Each admin has their own phone number. Blocking an admin, resetting their password or changing their phone deletes their tokens and cancels any sign-in in progress; status changes write a status history row; every change is audited without the password.
- **Field permissions.** When one field of an operation needs more than the operation's permission (approving a campaign below the margin floor needs `campaigns.override_margin`), the contract names it in `x-field-permissions` and the controller checks it. A contract test makes sure every role holding such a permission also holds the operation's own.
- **SMS** goes through `App\Modules\Notifications\Sms\SmsSender`. The only driver so far, `log`, writes to the log and refuses to run in production, so production sign-in fails with `sms_unavailable` until a provider is configured.
- **Phone numbers** are stored in E.164. `App\Support\Phone\SaudiMobile` accepts the ways people type them (05…, 9665…, Arabic-Indic digits).

## Catalog and sourcing (E6)

- **Products** come from Alibaba listings and are sold only through campaigns. Each has variants (SKU, options such as `{"color": "black"}`, size and weight) and photos. A product starts as a draft and can go active only with an active variant; its last active variant cannot be archived while it is active. Variant changes lock the product row, so two admins cannot race past that rule.
- **Categories** nest through `parent_id`. Moves run one at a time (a transaction advisory lock) and refuse loops (`category_cycle`).
- **Suppliers** start as prospects. Editing needs `suppliers.edit`; the approval decision is a separate endpoint needing `suppliers.approve`, so the person who adds a supplier need not be the one who approves it. Only approved suppliers will back a campaign (E7).
- **Quotations** record a supplier's quote in its currency (`unit_cost_minor`, `currency`, `fx_rate_to_sar` as an exact decimal) and the landed cost per unit in halalas, broken down into product, freight, customs duty, import VAT, clearance and last mile. The server adds up the total. A quote can be accepted only while `valid_until` has not passed in Riyadh, and `quotations:expire` marks old ones expired every night. Once a campaign past draft relies on a quote it is frozen (`quotation_frozen`); `Supplier\Services\QuotationUsage` answers that, and E7 fills it in.
- **Files.** Photos are public (`groupbuy.media.disk`); quote files are private (`groupbuy.media.documents_disk`) and reach the portal as 30-minute signed links. Files are written before the database row and removed if the transaction fails; deleted rows lose their files after the commit.
- **Updates replace.** Admin `PATCH` requests for categories, products, variants, suppliers and quotations send the whole record; optional fields left out are cleared (a category's `sort_order` goes back to 0). A status left out keeps its current value. A unique value taken by another request at the same moment is answered with the same 422 as the form check (`App\Support\Database\UniqueViolation`).
- **Supplier approval follows the store.** Changing an approved supplier's store URL or country sends it back to `prospect` for a new review; a blocked supplier stays blocked.

## Shared building blocks (`app/Support`)

| Class | Use it for |
| --- | --- |
| `Money\Money` | Every amount. Integer minor units, one currency, overflow-safe, VAT split per the spec's rounding rule. |
| `Actor` | Who did something: `Actor::system()`, `Actor::admin($id)`, `Actor::user($id)`. |
| `Audit\AuditLogger` | Record every admin action and every action that moves money. |
| `Audit\StatusHistory` | Record each status change, inside the same transaction as the change. |
| `RequestContext` | The request ID (logged) and IP and user agent (hidden from logs). It travels into queued jobs. |
| `Database\AppendOnly` | Add update, delete and truncate triggers to a history table in its migration. |
| `Phone\SaudiMobile` | Normalize a typed Saudi mobile number to E.164, or reject it. |
| `Audit\Changes` | The before and after of a model's pending changes, for the audit log. |

API errors are `{"message", "code"}`. Throw `App\Exceptions\ApiException` with a code; its message comes from `lang/{ar,en}/messages.php` under `errors.<code>`. These are expected outcomes and are not reported to the error log. List endpoints page by `id` with `CursorPage`, which also refuses a cursor that was tampered with. Form requests extend `App\Http\Requests\ApiRequest`, which normalizes phone numbers and Arabic-Indic digits before validation.

## Conventions

- **Money:** columns end in `_halalas` (or `_minor` for supplier currencies) and are `bigint`. Customer prices include 15% VAT.
- **Time:** `timestamptz` columns in UTC, and the database session time zone is pinned to UTC in `config/database.php`. Convert to `Asia/Riyadh` only for display.
- **Client IP:** set `TRUSTED_PROXIES` to the load balancer's addresses in each deployed environment, or audit logs record the balancer's IP instead of the customer's.
- **Language:** API messages follow `Accept-Language`, Arabic by default. Customer-facing text columns come in `_ar` and `_en` pairs. Models live in their module and are stored in polymorphic columns by short names (`user`, `admin_user`, …) from the morph map in `AppServiceProvider`; add new models there.
- **Statuses:** a `varchar` column with a CHECK constraint, mirrored by a PHP enum. Each change writes a `status_transitions` row.
- **History is append-only:** a trigger rejects UPDATE, DELETE and TRUNCATE on history tables. The trigger stops mistakes from the app or a console session, not the table owner, who can drop it. In production, migrations run as an owner role and the app runs as a separate role with only SELECT and INSERT on history tables (plus USAGE on their sequences). The grants are the real boundary.
- **Business numbers live in `config/groupbuy.php`:** campaign length, hold lifetime, capture cutoff, minimum margin. Each one cites the decision behind it.
- **Request IDs:** every response has an `X-Request-Id` header. The ID appears in logs, audit records and status history.

## Testing

- Tests run against PostgreSQL, not SQLite, because triggers, `jsonb` and row locks behave differently.
- Concurrency rules (for example, 200 joins racing for the last 10 units) get tests that run real parallel transactions, added with E7.
- CI lints the contract with Redocly and runs Pint and the full test suite on every pull request.
