# Admin portal

The React portal the team uses to run the marketplace: catalog and suppliers,
campaigns and price tiers, payments, refunds and approvals, orders, purchase
orders and shipments, reconciliation, the audit log and admin accounts. Arabic
(right to left) by default, with English one click away.

Stack: Vite, React 19, TypeScript, Ant Design 6, TanStack Query, and a typed
client generated from [`apps/api/openapi/v1.yaml`](../api/openapi/v1.yaml).

## Run it

Node 22 or newer.

```bash
cd apps/admin
npm install
cp .env.example .env.local      # optional; the defaults below need no changes
```

**Against the mock API** (works today for every screen):

```bash
npm run mock                    # Prism mock built from the contract, on :4010
npm run dev:mock                # http://localhost:5174
```

Prism accepts any well-formed email, any password and any 6-digit code. Its example admin holds
only a few permissions, so `dev:mock` sets `VITE_DEV_SHOW_ALL_ACTIONS=true` to
show every action. That flag only works in development builds; the API checks
permissions either way.

**Against Laravel** (endpoints marked `x-status: live`):

```bash
# in apps/api: php artisan serve  (see the root README to create an admin)
VITE_API_TARGET=laravel npm run dev
```

The portal always calls `/api/v1` on its own origin and Vite forwards it, so
there is no CORS setup. In production, serve `dist/` from the same origin as
the API, or set `VITE_API_BASE_URL` at build time.

## Scripts

| Command                    | What it does                                                   |
| -------------------------- | -------------------------------------------------------------- |
| `npm run dev` / `dev:mock` | Development server on port 5174                                |
| `npm run mock`             | Prism mock of the contract on port 4010                        |
| `npm test`                 | Vitest: unit tests and screen tests against a mocked API (MSW) |
| `npm run typecheck`        | TypeScript, including every translation key                    |
| `npm run lint`             | ESLint, with hooks rules and a ban on rounding money           |
| `npm run format`           | Prettier                                                       |
| `npm run api:types`        | Regenerate `src/api/schema.d.ts` from the contract             |
| `npm run api:check`        | Fail if the generated types are out of date (CI runs this)     |
| `npm run build`            | Production build in `dist/`                                    |

## Conventions

- **Contract first.** Every request and response type comes from
  `src/api/schema.d.ts`. After the API changes `v1.yaml`, run
  `npm run api:types`; the compiler then points at every screen to update.
- **Money** is integer minor units (halalas) end to end. Amounts typed by an
  admin are parsed from text with `parseMajorToMinor` (no floats) and shown
  with `formatMinor`. See `src/lib/money.ts`.
- **Time.** The API speaks UTC; screens show and accept Riyadh time
  (`src/lib/time.ts`).
- **Permissions** come from `GET /admin/me` (`permissions`), never from role
  names. Hide what an admin cannot do with `useAuth().can(...)` or `<Can>`;
  campaign actions come from the campaign's `available_actions`; roles you can
  grant come from `GET /admin/roles`.
- **Text.** Every string lives in `src/i18n/en.ts` and `src/i18n/ar.ts`. The
  compiler checks that both have the same keys and that every `t('...')` key
  exists. Codes, amounts, phone numbers and links are wrapped in `<Ltr>` so they
  read correctly inside Arabic text.
- **Business numbers** the portal needs before the API sends them (hold-expiry
  alert window, campaign length) are in `src/config.ts` and mirror
  `apps/api/config/groupbuy.php`. Keep them in step.
