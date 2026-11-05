# Group buying marketplace

A Saudi group-buying marketplace. The team picks products on Alibaba and runs time-limited **campaigns**. Customers join a campaign by placing a card hold. When the campaign closes, everyone is charged the same final tier price, and one bulk order goes to the supplier.

The product rules live in the [MVP build spec](https://claude.ai/artifact/WZRndBqV5A9pQgocirYtzn) (Overview, Campaign engine, Payments and Data model tabs). Code follows the spec; when they disagree, fix one of them in the same pull request.

## Repository layout

| Path | What it is | Status |
| --- | --- | --- |
| `apps/api` | Laravel 13 API used by every client (`/api/v1`) | Foundation in place |
| `apps/web` | Next.js customer website (pilot channel), Arabic first | Built against the contract mock; payments behind a fake form until Moyasar ([README](apps/web/README.md)) |
| `apps/admin` | React admin portal ([how to run it](apps/admin/README.md)) | All screens built against the contract (E17) |
| `apps/mobile` | Customer app for iOS and Android (Expo, React Native) | Built against the contract mock; payments and push behind fakes (E18) |
| `apps/realtime` | Laravel Reverb, the WebSocket server for live campaign progress ([README](apps/realtime/README.md)) | Ready; needs a host and keys |
| `docs/` | Architecture notes and conventions | |

## Run the API locally

You need PHP 8.3+ with the `pdo_pgsql`, `redis` and `pcntl` extensions (`pcntl` for the concurrency tests), Composer, and either Docker or local PostgreSQL 16 and Redis 7.

```bash
docker compose up -d            # PostgreSQL on 127.0.0.1:5432, Redis on 127.0.0.1:6379
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed       # --seed writes the admin roles and permissions
php artisan serve               # http://localhost:8000/api/v1/health
```

Sign-in codes are written to `storage/logs/laravel.log` instead of being texted (`SMS_DRIVER=log`) until an SMS provider is chosen. To use the admin portal, create an admin; the command asks for a password:

```bash
php artisan admin:create you@example.com --name="Your Name" --phone=05XXXXXXXX --role=super_admin
```

Finance admins are created and changed only here (`--role=finance`, or `php artisan admin:update`), because the portal never lets an admin grant permissions they lack, and only finance approves money movements.

On every deploy, run `php artisan admin:sync-roles` after migrating so the roles match `AdminPermissions`.

Campaigns need the scheduler and a queue worker. The scheduler runs `campaigns:tick` every minute, which opens campaigns at their start, frees unpaid reservations after 15 minutes and closes campaigns at their deadline. The worker voids card holds. Locally:

```bash
php artisan schedule:work       # or cron: * * * * * php artisan schedule:run
php artisan queue:work
```

Campaign pages update live through Laravel Reverb, the WebSocket server that ships with Laravel. It runs from its own small app, `apps/realtime` (see its README for why). The queue worker sends each change to Reverb, and Reverb pushes it to the website and the app. Clients without Reverb settings, or whose connection is down, refresh every 5 seconds instead; while connected they still read every 30 seconds, as a safety net. The API's default `BROADCAST_CONNECTION=log` sends nothing, so leave the clients' Reverb settings unset until the API pushes too. To run it locally, pick any app ID, key and secret, put them in both `apps/api/.env` and `apps/realtime/.env` (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`), set `BROADCAST_CONNECTION=reverb` in the API, then:

```bash
cd apps/realtime && composer install && php artisan reverb:start
```

Give the website the key and address (`NEXT_PUBLIC_REVERB_APP_KEY`, `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME`) and the mobile app the `EXPO_PUBLIC_REVERB_*` equivalents. Production setup is in [apps/realtime/README.md](apps/realtime/README.md).

Card payments go through a fake Moyasar (`MOYASAR_DRIVER=fake`) until the merchant account is ready. A payment whose Moyasar ID is `fake_authorized_<payment id>` counts as held; `fake_declined_<payment id>` is a declined card. Set `WEB_URL` to the website's address so the payment form returns there. The website calls `POST /api/v1/payments/{id}/sync` with Moyasar's payment ID as soon as the form reports it, and again on the return page. With `MOYASAR_DRIVER=moyasar` the website needs `NEXT_PUBLIC_PAYMENTS_MODE=moyasar` and `NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY` set to the same publishable key (see `apps/web/README.md`), and the mobile app `EXPO_PUBLIC_PAYMENT_PROVIDER=moyasar` with `EXPO_PUBLIC_WEB_URL` set to the same site (the app shows the website's form; see `apps/mobile/README.md`). Moyasar's webhooks go to `POST /api/v1/webhooks/moyasar`; set `MOYASAR_WEBHOOK_SECRET` to the secret token on the webhook in the Moyasar dashboard, or every webhook is refused.

Until the first production deploy, migrations already on `main` may still be edited rather than followed by new ones. If `migrate` fails after you pull, rebuild your local database with `php artisan migrate:fresh --seed`.

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

## Run the mobile app

```bash
cd apps/mobile
npm install
npm run mock      # the contract mock on port 4010
npm start         # then press i, a, or scan the QR code with Expo Go
```

See [apps/mobile/README.md](apps/mobile/README.md) for pointing it at Laravel, the fake payment form and the checks CI runs.

More detail: [docs/architecture.md](docs/architecture.md). Deploying and running it: [docs/runbook.md](docs/runbook.md).
