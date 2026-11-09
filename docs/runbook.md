# Running in production

How the system is deployed, released and looked after. The architecture is in `docs/architecture.md`; every API setting is described in `apps/api/.env.example`.

## What runs

| Service | Image | What it does |
| --- | --- | --- |
| `edge` | `caddy:2` | TLS for the three public hosts (automatic Let's Encrypt) and routing (`deploy/Caddyfile`). |
| `api` | `groupbuy-api`, `ROLE=web` | FrankenPHP serving the Laravel API on port 8000. Health: `GET /up`. |
| `worker` | `groupbuy-api`, `ROLE=worker` | Queue workers (two by default): payments, closing campaigns, refunds, messages, PDFs, ZATCA. |
| `scheduler` | `groupbuy-api`, `ROLE=scheduler` | `schedule:work`. Run exactly one (`apps/api/routes/console.php` lists the jobs). |
| `migrate` | `groupbuy-api`, `ROLE=migrate` | One-off, before each release: migrations as the owner role, the app role's grants, and the admin permission catalogue (`admin:sync-roles`). |
| `realtime` | `groupbuy-realtime` | Laravel Reverb for live campaign progress (`apps/realtime/README.md`). |
| `web` | `groupbuy-web` | The customer website (Next.js) on port 3000. |
| `admin` | `groupbuy-admin` | The admin portal, static files behind nginx on port 8080. |
| `postgres`, `redis` | optional | Only with the `local-data` profile (First deploy, step 4). Managed PostgreSQL 16 and Redis 7 are better. |

Public hosts (`deploy/env/edge.env`):

- `SITE_HOST` serves the website, with `/api/*` going to the API. Moyasar's webhook and payment return pages live here.
- `ADMIN_HOST` serves the admin portal, with `/api/*` going to the API.
- `REALTIME_HOST` serves `wss://…/app/{key}` for Reverb. Reverb's HTTP API is not public: the API posts to `http://realtime:8080` inside the network.

Redis holds the queue, cache, sessions and scheduler locks. Run it with persistence (`appendonly yes`) and `maxmemory-policy noeviction`, because an evicted queue key is a lost job. PostgreSQL holds everything that matters: card holds, quantities, ledger, invoices.

## Images

`.github/workflows/images.yml` builds the four images on every pull request that touches them. On `main` it pushes them to `ghcr.io/<owner>/groupbuy-<name>`, tagged with the commit SHA and `main`. Deploy a SHA, never `main`, so a release can be repeated and rolled back.

The website's `NEXT_PUBLIC_*` settings are compiled into its image. Set them as repository variables under Settings, Secrets and variables, Actions, Variables: `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY`, `NEXT_PUBLIC_REVERB_APP_KEY`, `NEXT_PUBLIC_REVERB_HOST`, and optionally `NEXT_PUBLIC_PAYMENTS_MODE`, `NEXT_PUBLIC_APPLE_PAY_LABEL`, `NEXT_PUBLIC_REVERB_PORT` and `NEXT_PUBLIC_REVERB_SCHEME`. Until `NEXT_PUBLIC_SITE_URL` and `NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY` are set, the web image is built but not pushed, because without the key every card form is refused. A staging site needs its own build with its own values.

To build by hand from the repository root:

```bash
docker build -f apps/api/Dockerfile -t groupbuy-api .
docker build -f apps/realtime/Dockerfile -t groupbuy-realtime .
docker build -t groupbuy-admin apps/admin
docker build -t groupbuy-web --build-arg NEXT_PUBLIC_SITE_URL=https://example.sa ... apps/web
```

## First deploy

1. **Server.** A Linux host with Docker Engine and the compose plugin, ports 80 and 443 open, and DNS records for the three hosts pointing at it. A Saudi region keeps customer data in the Kingdom.
2. **Database roles.** On managed PostgreSQL, create the database `groupbuy`, then run `deploy/postgres/roles.sql` once as the admin user:

   ```bash
   GROUPBUY_OWNER_DB_PASSWORD=… GROUPBUY_APP_DB_PASSWORD=… \
     psql "postgresql://admin@db-host:5432/groupbuy?sslmode=require" -f deploy/postgres/roles.sql
   ```

   `groupbuy_owner` owns the schema and runs migrations. `groupbuy_app` is what the API connects as. It may read and write ordinary tables, but on history tables (audit log, status history, ledger, settlements, consents, shipment and campaign events) it may only `SELECT` and `INSERT`. It may not change the schema. With the bundled database (the `local-data` profile), the postgres container runs the same file on its first start, with the passwords from `postgres.env`.
3. **Settings.** In `deploy/env/`, copy each `*.example` to `*.env` and fill it in. Never commit them; `deploy/env/.gitignore` keeps them out.
   - `api.env`: the app role's database password, `APP_KEY` (`docker run --rm -e ROLE=cli groupbuy-api php artisan key:generate --show`), and every line marked NEEDED.
   - `api-owner.env`: the owner's password.
   - `realtime.env`: the same Reverb ID, key and secret as `api.env`.
   - `edge.env`, `web.env`.

   Keep `APP_KEY` safe and never change it: it encrypts the ZATCA unit's private key and secrets in the database.
4. **Migrate and start.** With the bundled PostgreSQL and Redis instead of managed ones, first put `COMPOSE_PROFILES=local-data` in `deploy/.env`, so that every compose command includes them, and start them before migrating:

   ```bash
   cd deploy
   export TAG=<commit sha>
   docker compose pull
   docker compose up -d --wait postgres redis    # only with the local-data profile
   docker compose run --rm migrate
   docker compose up -d
   docker compose exec api php artisan admin:create owner@example.sa --role=super_admin
   docker compose exec api php artisan admin:create finance@example.sa --role=finance
   ```

   The second admin with the finance role is needed from day one: refunds, manual voids, ledger adjustments and cancellations after a capture all need a second person to approve.
5. **Moyasar.** In the Moyasar dashboard, point the webhook at `https://SITE_HOST/api/v1/webhooks/moyasar`, with the secret token from `MOYASAR_WEBHOOK_SECRET`. Use live keys only after a full sandbox campaign has run end to end.
6. **Check it:**
   - `https://SITE_HOST/api/v1/campaigns` answers.
   - `docker compose logs scheduler` shows the jobs running.
   - A test sign-in code arrives by SMS.
   - The admin portal signs in.

## Each release

```bash
cd deploy
export TAG=<new commit sha>
docker compose pull
docker compose run --rm migrate          # migrations first; they must work with the old code too
docker compose up -d                     # replaces api, worker, scheduler, realtime, web, admin
docker compose exec api php artisan queue:restart
```

Workers finish the job they hold before stopping. `stop_grace_period` is 60 seconds; a longer job is cut off and retried, and the jobs are written to be safe to run again.

**Migrations.**
- Write them so the release before can still run against the new schema: add columns nullable or with defaults, and drop things one release later.
- Never edit a migration that has run in production.
- The migrate service also re-applies the app role's grants (`php artisan db:grant-app-role groupbuy_app`), so new tables are covered, and syncs the admin permissions.

**Rollback.** Deploy the previous SHA the same way. Roll back a migration only if it is safe to; otherwise fix forward.

## Changing a setting

A container reads `env/*.env` when it is created, and the API caches its configuration when it starts. After editing a file, run `docker compose up -d`: it recreates every container whose settings changed. `docker compose restart` and `docker compose exec` keep the old values.

To run a command once with different settings, without changing the running containers, start a one-off container. `ROLE=cli` runs the command without the cached configuration:

```bash
docker compose run --rm -e ROLE=cli -e SOME_SETTING=value api php artisan <command>
```

## Performance

What the code already does is listed in `docs/architecture.md` (Performance). What the setup decides:

- **Keep the API, PostgreSQL and Redis in one region and one network**, close to customers (a Saudi cloud provider or one of AWS's Middle East regions). A page makes several queries in a row, so every millisecond between the API and the database is paid several times.
- **Connection pooling.** The API opens a database connection per request. Before traffic grows, put the managed provider's pooler (or PgBouncer) in front of PostgreSQL in transaction mode. The API already sends plain queries (`DB_EMULATE_PREPARES=true`), which transaction mode needs. Point `DB_HOST`/`DB_PORT` of the `api` and `worker` containers at the pooler. Keep `migrate` pointed at the database itself: it signs in as the owner role, which the pooler does not need to know.
- **CDN for product photos.** Photos are stored as WebP, at most 1600 px, with `Cache-Control: public, max-age=31536000, immutable`. Put a CDN in front of the media bucket and set `AWS_MEDIA_URL` to it. Photos uploaded before this change keep their original format and headers.
- **More than one server.** Run more `api` and `web` containers behind a load balancer. Each holds no state of its own (sessions, cache and queue are in Redis), and the scheduler stays at exactly one.
- **Monitoring.** API errors and 10% of request traces go to Sentry (`SENTRY_TRACES_SAMPLE_RATE`). The website and the app send nothing yet: real user monitoring (Core Web Vitals from customers' phones) needs a provider.

To measure the website the way a customer on a mid-range phone and a weak 4G network sees it, build it, start it, and run Lighthouse's mobile profile with applied throttling (150 ms round trip, 1.6 Mbps, CPU 4 times slower):

```bash
npx lighthouse https://SITE/ar --only-categories=performance --throttling-method=devtools --view
```

## Scheduled jobs and alerts

The scheduler runs everything in `apps/api/routes/console.php`. A failing command exits non-zero and logs an error. The ones that move money or state are `onOneServer` and `withoutOverlapping`.

Every situation that needs a person is logged at `critical`. To get them in Slack, set `LOG_SLACK_WEBHOOK_URL` and `LOG_STACK=stderr,slack` in `api.env`. Otherwise alert on `"level_name":"CRITICAL"` in your log platform: `api.env` writes one JSON object per log line (`LOG_STDERR_FORMATTER`). With `SENTRY_LARAVEL_DSN` set, unexpected exceptions go to Sentry.

| Log | What to do |
| --- | --- |
| `payments.capture.*`, `payments.hold.expired_uncaptured` | A charge did not go through, or a hold ran out. Check the payment in the admin portal and in Moyasar. |
| `payments.refund.*`, `payments.manual_void.unresolved` | A refund or release is stuck. Finance checks it in Moyasar. |
| `payments.reconciliation.*` | The nightly check against Moyasar found mismatches. Finance works through them under Reconciliation. |
| `payments.settlements.*`, `payments.settlement.currency` | A Moyasar payout could not be read into the ledger. |
| `campaigns.cancellation.*` | A cancellation cannot finish on its own. |
| `courier.*` | A courier booking needs a hand in the courier's portal. |
| `zatca.report.overdue`, `zatca.report.rejected`, `zatca.submit.failed`, `zatca.certificate.expiring` | E-invoicing, below. ZATCA wants each invoice reported within 24 hours. |

## E-invoicing (ZATCA phase 2)

Off until `ZATCA_ENABLED=true`. Until then, invoices carry the phase 1 QR code.

1. Set the seller's CR number and national address in parts (`GROUPBUY_SELLER_*`), and the EGS details (`ZATCA_ORGANIZATION_UNIT`, `ZATCA_LOCATION`, `ZATCA_INDUSTRY`), in `api.env`. Then run `docker compose up -d` (Changing a setting, above).
2. Rehearse onboarding in one-off containers, which leave `ZATCA_ENV=core` in `api.env` untouched:

   ```bash
   # ZATCA's developer portal: any OTP works.
   docker compose run --rm -e ROLE=cli -e ZATCA_ENV=developer-portal api php artisan zatca:onboard --otp=123456
   # The simulation portal, with an OTP from it.
   docker compose run --rm -e ROLE=cli -e ZATCA_ENV=simulation api php artisan zatca:onboard --otp=<OTP>
   ```

   Read the compliance checks' warnings: one sample line has VAT a halala off its net × 15%, and its result says whether ZATCA accepts that split.
3. For production, get an OTP from the Fatoora portal and run `docker compose exec api php artisan zatca:onboard --otp=<OTP>` (`ZATCA_ENV=core`). If ZATCA refuses it because the seller's details or EGS settings were wrong, fix them in `api.env`, run `docker compose up -d`, and onboard again with `--fresh` and a new OTP.
4. Set `ZATCA_ENABLED=true` in `api.env` and run `docker compose up -d`. In production nothing is captured while phase 2 is on without an active unit for `core`.
5. Before the certificate expires (warning 30 days ahead), onboard again with a new OTP. The new unit takes over and the old one is retired.

A rejected invoice is resent with `docker compose exec api php artisan zatca:report --resend=INV-…` once its cause is fixed.

## Backups

- **PostgreSQL** is the only state that cannot be rebuilt.
  - On a managed service: daily snapshots and point-in-time recovery for at least 14 days.
  - With the `local-data` profile: `docker compose exec postgres pg_dump -U postgres -Fc groupbuy > groupbuy-$(date +%F).dump` every night, copied off the server. Test a restore every month.
- **Documents bucket** (invoices, supplier papers, receipt photos): turn on versioning. Invoices must be kept for the period ZATCA requires.
- **Redis** can be lost. Queued jobs are re-queued by the sweeps (webhooks, approved refunds, PDFs, ZATCA, notifications), because PostgreSQL remembers what is still owed.
- Keep `deploy/env/*.env` (above all `APP_KEY`) in a password manager. A database backup without its `APP_KEY` cannot sign e-invoices.

## Mobile app builds

`apps/mobile/eas.json` has two profiles.

- **`preview`**: an internal build (an APK on Android) for testers.
- **`production`**: store builds, with the version number kept by EAS.

The app's settings come from EAS environment variables (expo.dev, project, Environment variables) for the `preview` and `production` environments:

- `EXPO_PUBLIC_API_URL`, for example `https://example.sa/api/v1`.
- `EXPO_PUBLIC_WEB_URL`.
- `EXPO_PUBLIC_PAYMENT_PROVIDER=moyasar`.
- `EXPO_PUBLIC_PRIVACY_POLICY_URL`.
- `EXPO_PUBLIC_REVERB_APP_KEY`, `EXPO_PUBLIC_REVERB_HOST`, `EXPO_PUBLIC_REVERB_PORT`, `EXPO_PUBLIC_REVERB_SCHEME`.
- The file variable `GOOGLE_SERVICES_JSON`, for Android push.
- `EAS_PROJECT_ID` and `EXPO_OWNER`, once `eas init` has made the project. EAS evaluates `app.config.ts` again on its build servers, and without them there the app has no project ID and silently skips push registration.

One-time setup:

```bash
cd apps/mobile
npx eas-cli login
npx eas-cli init                       # creates the EAS project; note its ID
export EAS_PROJECT_ID=<id> EXPO_OWNER=<expo account>   # here, and as EAS variables (above)
npx eas-cli credentials                # signing keys; EAS can make and keep them
```

Builds and store submission:

```bash
npx eas-cli build --profile preview --platform all
npx eas-cli build --profile production --platform all
npx eas-cli submit --profile production --platform android   # Play Console internal track, as a draft
npx eas-cli submit --profile production --platform ios       # App Store Connect, then TestFlight
```

Store submission needs a Google Play developer account (and a service account key for `submit`) and an Apple Developer Program membership.

Links to the website open the app once both sides know each other:

- The app: `EXPO_PUBLIC_WEB_URL` is the https site (the build adds the associated domain and the Android intent filter).
- The website: in `deploy/env/web.env`, `APPLE_TEAM_ID` (Apple Developer, Membership) and `ANDROID_CERT_SHA256` (Play Console, Setup, App signing: the app signing key's SHA-256, and the upload key's, separated by commas). Then `docker compose up -d`. Check that `https://SITE_HOST/.well-known/apple-app-site-association` and `/.well-known/assetlinks.json` answer with JSON.

The store listings ask for an account deletion address: `https://SITE_HOST/ar/account#delete-account` (or `/en/`). Customers sign in there and delete the account; the app has the same under Account.
