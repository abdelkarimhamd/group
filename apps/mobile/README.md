# Customer mobile app

The iOS and Android app for customers: browse group deals, watch a campaign fill up ("47 more needed"), sign in with an SMS code, join with a card hold, and follow joins and orders. Arabic (right-to-left, the default) and English, prices in SAR.

Built with Expo SDK 58, Expo Router, TanStack Query and a client generated from [`apps/api/openapi/v1.yaml`](../api/openapi/v1.yaml). It runs against the Prism mock today and against Laravel by changing one URL.

## Run it

You need Node 22 and either a phone with Expo Go, an iOS simulator or an Android emulator.

```bash
cd apps/mobile
npm install
npm run mock      # terminal 1: the API mock on port 4010
npm start         # terminal 2: then press i (iOS), a (Android) or scan the QR code with Expo Go
```

The app finds the mock on the machine running Metro, so a phone on the same Wi-Fi works without setup. To use the Laravel API instead, copy `.env.example` to `.env.local` and set `EXPO_PUBLIC_API_URL` (for example `http://localhost:8000/api/v1`, or `http://10.0.2.2:8000/api/v1` from the Android emulator).

`npm run web` opens a browser preview, which is handy for a quick look but is not a supported platform.

### Try the whole flow on the mock

1. Open a deal and tap **Join**.
2. Sign in with any Saudi mobile number (`05XXXXXXXX`) and any 6-digit code. The mock accepts every code.
3. Review the order and tap **Reserve and pay**.
4. The test payment form opens. **Approve the hold** or **Decline the card** to see each outcome.

The mock answers with fixed example data, so screens after a call show the contract's examples, not what you entered.

## Payments, push and live progress

Each integration sits behind its own interface, with a fake for development and tests:

| What                   | Now                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | Later                                                                        |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------- |
| Card hold              | `src/payments/FakePaymentSheet.tsx` (the default): no card is charged. Approving sends `fake_authorized_<payment_id>` to `POST /payments/{id}/sync`, declining sends `fake_declined_<payment_id>`. The API's fake provider understands both.                                                                                                                                                                                                                                   | Done: `EXPO_PUBLIC_PAYMENT_PROVIDER=moyasar` uses Moyasar's form (see below) |
| Push notifications     | `src/push/index.ts` registers the Expo push token after sign-in (`POST /me/device-tokens`). It needs the EAS project ID in `app.json` (`eas init`), a development build (Expo Go cannot receive remote push on Android) and, on Android, the Firebase project's `google-services.json`: upload it as the EAS file variable `GOOGLE_SERVICES_JSON`, or put it in this folder for a local build (it is gitignored; `app.config.ts` picks it up). Without the ID it does nothing. | Done; the API sends through Expo (`PUSH_DRIVER=expo`)                        |
| Live campaign progress | With `EXPO_PUBLIC_REVERB_*` set, Laravel Reverb pushes changes (`src/live/progress.ts`, pusher-js); the screen reads `GET /campaigns/{id}/progress` on every (re)connect and when the app returns to the foreground, and polls every 30 seconds while pushes arrive. Without them, or while the connection is down, it polls every 5 seconds.                                                                                                                                  | Done (`apps/realtime`)                                                       |

The account screen shows a "Test payments" banner while the fake provider is active.

### Moyasar

With `EXPO_PUBLIC_PAYMENT_PROVIDER=moyasar`, `src/payments/MoyasarPaymentSheet.tsx` opens the website's `/{locale}/payments/app` page in a WebView (`react-native-webview`, included in Expo Go), with the API's checkout in the URL fragment. It is the website's own Moyasar form, so card details go from Moyasar's form to Moyasar and the app never sees them:

- The page tells the app Moyasar's payment ID as soon as the payment exists. Closing the sheet after that (for example during 3-D Secure) records the payment quietly, so a hold that went through still shows up, and the form can be opened again.
- The sheet catches the form's return to the checkout's `callback_url` before it loads, and the pay screen syncs with the API, which asks Moyasar itself. While the bank is still processing, the screen checks again every 3 seconds, 10 times, then offers **Check again**.
- On iPhone the WebView has Apple Pay turned on (`enableApplePay`), which allows no injected script: the page posts through WebKit's message handler instead. Apple Pay needs an https site whose domain is registered with Moyasar. Apple documents Apple Pay on the web for Safari and Safari views, so test it on a real iPhone before relying on it in the app.

### Live progress

pusher-js's React Native build watches the network through `@react-native-community/netinfo` (included in Expo Go; a development build needs rebuilding after this was added). The campaign screen and the checkout share one connection, and one subscription per campaign. Pushes for another campaign, older numbers (by `updated_at`) and anything not in the contract's shape are ignored, whether they come from a push or a read.

`EXPO_PUBLIC_WEB_URL` must be the same origin as the API's `WEB_URL`, because the page refuses a checkout that returns anywhere else. Without it the app uses the website's dev server (port 3000) on the machine running Metro.

## How the code is laid out

| Path                         | What it holds                                                                                                                                                              |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/app/`                   | Screens. Every file is a route (Expo Router); `(tabs)` holds Deals, My joins, Orders and Account. Keep everything else out of this folder.                                 |
| `src/api/`                   | `schema.d.ts` (generated, never edit), the fetch client with auth and language headers, one function per operation in `endpoints.ts`, and the query hooks in `queries.ts`. |
| `src/auth/`                  | The bearer token in the Keychain/Keystore and the session provider.                                                                                                        |
| `src/i18n/`                  | `ar.ts` and `en.ts` strings, Arabic plural rules, `useFormat()` for money, dates and countdowns, and the right-to-left switch.                                             |
| `src/lib/`                   | Plain functions with tests: money, phone numbers, Riyadh time, campaign progress, addresses, payment states.                                                               |
| `src/components/`            | Shared UI: progress bar, tier ladder, cards, buttons, fields.                                                                                                              |
| `src/payments/`, `src/push/` | The integration seams above.                                                                                                                                               |
| `src/test/`                  | Fixtures shaped by the contract types and a fetch stand-in for tests.                                                                                                      |

## Rules that matter here

- **Money stays in halalas.** Show amounts with `money()` or `minor()` from `useFormat()`. Never divide by 100 or use floats. The server computes every amount that is charged; the app only displays.
- **Times are shown in Riyadh time** (UTC+3) with the helpers in `src/lib/time.ts`.
- **Every string lives in both `ar.ts` and `en.ts`.** Counted strings use plural keys read with `tp()`; Arabic needs `_zero`, `_one`, `_two`, `_few`, `_many` and `_other`. A test fails if a key, a plural form, a placeholder or a status label is missing.
- **Layout follows the language.** Use `marginStart`/`paddingEnd` and `flexDirection: 'row'`, which flip in Arabic. Numbers inside Arabic text are wrapped in direction isolates by the formatters.
- **The contract is the source of truth.** After `v1.yaml` changes, run `npm run api:types` and fix what the type checker reports. CI fails if `schema.d.ts` is stale.
- **Joining is idempotent.** The review screen keeps one `client_request_id` per attempt and only makes a new one after the API gave a definite answer, so a retry after a dropped connection never joins twice.

## Checks

```bash
npm run lint             # ESLint and Prettier
npm run typecheck        # TypeScript, including every API path and body against the contract
npm run api:types:check  # schema.d.ts matches v1.yaml
npm test                 # unit, component and full join-flow tests
npm run test:contract    # every API call over HTTP against Prism with request validation on
npm run bundle:check     # the iOS and Android bundles build
```

CI runs all of them on every pull request that touches `apps/mobile` or the contract (`.github/workflows/mobile.yml`).

## Not done yet

- The app name ("Group Buy"), icon and bundle ID (`sa.groupbuy.app`) are placeholders until the brand is chosen.
- Store builds (EAS), signing and app store listings.
- Dark mode. The app is light only.
