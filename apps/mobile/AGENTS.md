# Working in apps/mobile

Read this folder's `README.md`, then the repository's `CLAUDE.md` and `docs/architecture.md`. The product rules come from the MVP build spec linked in the root README.

## Expo moves fast

This app is on Expo SDK 58 with React Native 0.88 and React 19. APIs you remember may have moved. Check the installed package's type definitions in `node_modules` or the versioned docs at `https://docs.expo.dev/versions/v58.0.0/` before using an Expo or React Native API.

- Add packages with `npx expo install <package>` so versions match the SDK.
- Native folders (`ios/`, `android/`) are generated. Configure native behaviour in `app.json` and config plugins, never by hand.
- Every module in use ships in Expo Go. Adding a library with its own native code means a development build (`npx expo run:ios|android`), so say so in the pull request.

## Rules

- Screens live in `src/app/` and nothing else does: a stray file there becomes a route.
- Call the API only through `api` in `src/api/endpoints.ts`. Regenerate `src/api/schema.d.ts` with `npm run api:types`; never edit it.
- Money is integer halalas end to end. Format with `useFormat()`; never divide by 100 or round.
- New text goes into both `src/i18n/en.ts` and `src/i18n/ar.ts`. Use start/end, not left/right, for anything that should flip in Arabic.
- Payments, push and live updates stay behind `src/payments`, `src/push` and the progress hook, so the real providers can replace the fakes without touching screens.

## Before you push

```bash
npm run lint && npm run typecheck && npm test && npm run test:contract
```
