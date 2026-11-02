import { setupServer } from 'msw/node';

/** Mock API for component tests. Each test adds the handlers it needs. */
export const server = setupServer();

/** The base URL the portal's API client calls in tests. */
export const API = new URL(
  '/api/v1',
  globalThis.location?.origin ?? 'http://localhost:3000',
).toString();
