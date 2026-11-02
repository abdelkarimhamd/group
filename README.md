# Group buying marketplace

A Saudi group-buying marketplace. The team picks products on Alibaba and runs time-limited **campaigns**. Customers join a campaign by placing a card hold. When the campaign closes, everyone is charged the same final tier price, and one bulk order goes to the supplier.

The product rules live in the [MVP build spec](https://claude.ai/artifact/WZRndBqV5A9pQgocirYtzn) (Overview, Campaign engine, Payments and Data model tabs). Code follows the spec; when they disagree, fix one of them in the same pull request.

## Repository layout

| Path | What it is | Status |
| --- | --- | --- |
| `apps/api` | Laravel 13 API used by every client (`/api/v1`) | Foundation in place |
| `apps/web` | Next.js customer website (pilot channel), Arabic first | Built against the contract mock; payments behind a fake form until Moyasar ([README](apps/web/README.md)) |
| `apps/admin` | React admin portal | Not started (E17) |
| `apps/mobile` | React Native app, after the website pilot | Not started (E18) |
| `docs/` | Architecture notes and conventions | |

## Run the API locally

You need PHP 8.3+ with the `pdo_pgsql` and `redis` extensions, Composer, and either Docker or local PostgreSQL 16 and Redis 7.

```bash
docker compose up -d            # PostgreSQL on 127.0.0.1:5432, Redis on 127.0.0.1:6379
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve               # http://localhost:8000/api/v1/health
```

Without Docker, create the role and both databases yourself:

```bash
sudo -u postgres psql -c "CREATE ROLE groupbuy LOGIN PASSWORD 'groupbuy'"
sudo -u postgres createdb -O groupbuy groupbuy
sudo -u postgres createdb -O groupbuy groupbuy_test
```

Tests use the separate `groupbuy_test` database (Docker creates it for you). The test suite refuses to run against any database whose name does not end in `_test`, because it drops every table between runs:

```bash
cd apps/api
php artisan test
vendor/bin/pint                 # code style
```

## Ground rules

- Money is an integer number of halalas, never a float. Use `App\Support\Money\Money`.
- Times are stored in UTC and shown in Riyadh time.
- PostgreSQL is the source of truth. Redis caches and queues; it never decides a quantity or a payment.
- History tables (audit logs, status transitions, and later the ledger) are append-only, enforced by database triggers.
- Payment, campaign and order states live on their own records. One is never used as a stand-in for another.
- Every pull request that touches money, holds or campaign quantities needs a human review before merge.

## API contract and mock server

The API contract is [`apps/api/openapi/v1.yaml`](apps/api/openapi/v1.yaml). Frontends can build against a mock that answers every endpoint in it, on http://localhost:4010:

```bash
docker compose --profile mock up api-mock
# or, without Docker:
npx @stoplight/prism-cli@5 mock apps/api/openapi/v1.yaml
```

Add `-d` to the Prism command for varied fake data, and send a `Prefer: code=409` header to get a specific documented response. Lint the contract with `npx @redocly/cli@1 lint apps/api/openapi/v1.yaml`.

## Run the website locally

```bash
cd apps/web
npm install
npm run mock    # fixture API behind a contract-checking Prism proxy on :4010
npm run dev     # http://localhost:3000, sign in with any 05x number and code 123456
```

See [apps/web/README.md](apps/web/README.md) for the fake payment form, tests and layout.

More detail: [docs/architecture.md](docs/architecture.md).
