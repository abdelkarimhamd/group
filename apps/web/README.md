# Customer website (`apps/web`)

The pilot sales channel: Next.js 16 (App Router, Cache Components), Arabic first and right to left, with English, prices in SAR. It talks only to the API in [`../api/openapi/v1.yaml`](../api/openapi/v1.yaml), through a typed client generated from that contract.

## Run it

Node 22.18 or newer.

```bash
cd apps/web
npm install
cp .env.example .env.local
npm run mock        # fixture API behind Prism on http://localhost:4010 (see below)
npm run dev         # http://localhost:3000 (redirects to /ar)
```

Sign in with any Saudi mobile number (`05x xxx xxxx`) and the code **123456**. A new customer starts with a saved Jeddah address, one delivered order and one released join, so every account page has something to show.

To use the real API instead, set `API_BASE_URL=http://localhost:8000/api/v1` in `.env.local`.

## The mock stack

`npm run mock` starts two processes:

- `mock/server.mts` (port 4011): a small stateful fixture API. It implements every customer endpoint with realistic campaigns (tiers, a confirmed deal, a nearly sold-out campaign, an upcoming, a completed and a failed one), joins, payments, orders with tracking and invoices. Another shopper joins an open campaign every 8 seconds, so live progress moves; `MOCK_SIMULATE=0` turns that off.
- Prism in proxy mode (port 4010): forwards to the fixtures and checks every request and response against the contract. `MOCK_STRICT=1` turns violations into error responses. It is off by default because Prism ignores `nullable` on properties of schemas reached through `allOf` (for example `CampaignDetail.final_unit_price`), so valid nulls are reported as violations.

The plain contract mock (`npm run mock:prism`, or `docker compose --profile mock up api-mock` at the root) also works, but its example data is static.

## Payments

Card holds go through Moyasar's hosted form, which is the last integration to land. Until then `NEXT_PUBLIC_PAYMENTS_MODE=fake` (the default) shows a test form after a join with two buttons. They send the customer to `/{locale}/payments/return?payment_id=<payment id>&id=fake_authorized_<payment id>` (or `fake_declined_…`), the same return page Moyasar will use. That page never trusts the redirect: it calls `POST /payments/{id}/sync` and shows what the API decided. The API accepts these fake IDs only in its fake payment mode.

## How the code is organised

| Path | What it holds |
| --- | --- |
| `src/app/[locale]/…` | Pages. Every API read sits inside `<Suspense>`, so the static shell prerenders and data streams in. |
| `src/actions/` | Server Actions: sign-in, joining, payments, profile, addresses. The browser never sees the API token. |
| `src/lib/api/` | The generated contract types (`schema.d.ts`), the typed client and error handling. |
| `src/i18n/` | Locales, dictionaries (Arabic is the source; English must match its shape) and formatting. |
| `src/components/` | UI. `campaign-live.tsx` keeps the campaign page live by polling `GET /campaigns/{id}/progress` every 5 seconds; a Reverb subscription can replace the poll later. |
| `src/proxy.ts` | Adds the locale to URLs and sends signed-out visitors on account pages to sign-in. |
| `mock/` | The fixture API used in development and browser tests. |

Rules the code follows:

- Money arrives as integer halalas and is formatted from decimal strings built from integers, never floats (`src/i18n/format.ts`). Amounts the customer is charged are always the API's; the page only previews `price × quantity`.
- Digits are Western (`ar-SA-u-nu-latn`) and dates Gregorian, in Riyadh time.
- The session token lives in an httpOnly cookie (`gb_session`) and is sent to the API only from the server.

## Checks

```bash
npm run api:types       # regenerate src/lib/api/schema.d.ts after a contract change
npm run api:types:check # CI: the generated types match the contract
npm run lint
npm run typecheck
npm test                # unit tests (vitest)
API_BASE_URL=http://127.0.0.1:4010 npm run build
npm run test:e2e        # Playwright: starts the mock stack and `next start` on :3100
```

The browser tests run on a desktop and a phone viewport. They fail if the site sends any request the contract does not allow (Prism's request violations are collected in `.mock/contract-violations.log`).
