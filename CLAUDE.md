# Working in this repository

Read `README.md` and `docs/architecture.md` first. The product rules come from the MVP build spec; when code and spec disagree, raise it in the pull request rather than guessing.

## Non-negotiables

- Money is integer minor units via `App\Support\Money\Money`. Never floats, never `round()` on money outside that class.
- Business numbers (campaign length, hold lifetime, capture cutoff, minimum margin) come from `config/groupbuy.php`, not literals.
- PostgreSQL decides quantities and payment state. Use row locks or conditional `UPDATE ... WHERE` for counters. Redis is never authoritative.
- Never call a payment provider inside an open database transaction: write the attempt row, commit, call the provider, then record the result.
- Every provider call (authorize, capture, void, refund) carries a unique idempotency key and is logged before it is sent.
- History tables are append-only. Protect new ones with `AppendOnly::protect()` in their migration.
- Write status changes and their `StatusHistory` row in the same transaction.
- Every API change updates `apps/api/openapi/v1.yaml` in the same pull request. Shipping an endpoint means flipping its `x-status` from `mock` to `live`; the contract tests enforce both directions.

## Commands (from `apps/api`)

```bash
php artisan test        # PostgreSQL test database: groupbuy_test
vendor/bin/pint         # code style; CI runs pint --test
```
