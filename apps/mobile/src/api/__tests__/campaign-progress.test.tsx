/**
 * useCampaignProgress with Reverb configured: pusher-js is replaced by a stand-in the test
 * drives (subscribe, push, drop), shaped like its React Native build ({ Pusher }), and the
 * API by mockApi.
 */
import { QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import { createElement, type ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { createQueryClient } from '@/api/query-client';
import { useCampaignProgress } from '@/api/queries';
import type { CampaignProgress } from '@/api/types';
import { mockApi } from '@/test/fetch';

const ID = '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0001';
const CHANNEL = `campaigns.${ID}`;
const PATH = `/campaigns/${ID}/progress`;

jest.mock('@/config', () => ({
  config: {
    apiUrl: 'http://127.0.0.1:4011',
    paymentProvider: 'fake',
    webUrl: 'http://127.0.0.1:3000',
    privacyPolicyUrl: null,
    reverb: { key: 'app-key', host: 'ws.example.sa', port: 443, tls: true },
    progressPollMs: 100,
    progressPushedPollMs: 60_000,
  },
}));

type Handler = (data?: unknown) => void;
const mockPusher = {
  options: null as unknown,
  key: null as unknown,
  handlers: new Map<string, Set<Handler>>(),
  stateHandlers: new Set<(change: { previous: string; current: string }) => void>(),
  channels: new Set<string>(),
  subscribed: false,
  state: 'connecting',
  disconnected: 0,
};

// The connection closes as soon as the last screen leaves, not 30 seconds later.
jest.mock('@/live/progress', () => {
  const actual = jest.requireActual<typeof import('@/live/progress')>('@/live/progress');
  return {
    ...actual,
    subscribeToProgress: (...args: Parameters<typeof actual.subscribeToProgress>) =>
      actual.subscribeToProgress(args[0], args[1], args[2], args[3], 0),
  };
});

jest.mock('pusher-js', () => ({
  Pusher: jest.fn().mockImplementation((key: string, options: unknown) => {
    mockPusher.key = key;
    mockPusher.options = options;
    return {
      connection: {
        get state() {
          return mockPusher.state;
        },
        bind: (_event: string, cb: (change: { previous: string; current: string }) => void) =>
          mockPusher.stateHandlers.add(cb),
        unbind: (_event: string, cb: (change: { previous: string; current: string }) => void) =>
          mockPusher.stateHandlers.delete(cb),
      },
      subscribe: (name: string) => {
        mockPusher.channels.add(name);
        return {
          get subscribed() {
            return mockPusher.subscribed;
          },
          bind: (event: string, cb: Handler) => {
            const key = `${name} ${event}`;
            mockPusher.handlers.set(key, (mockPusher.handlers.get(key) ?? new Set()).add(cb));
          },
          unbind: (event: string, cb: Handler) =>
            mockPusher.handlers.get(`${name} ${event}`)?.delete(cb),
        };
      },
      unsubscribe: (name: string) => mockPusher.channels.delete(name),
      allChannels: () => [...mockPusher.channels].map((name) => ({ name })),
      connect: () => {
        mockPusher.state = 'connecting';
      },
      disconnect: () => {
        mockPusher.disconnected += 1;
        mockPusher.state = 'disconnected';
      },
    };
  }),
}));

function fire(event: string, data?: unknown) {
  if (event === 'pusher:subscription_succeeded') mockPusher.subscribed = true;
  for (const cb of [...(mockPusher.handlers.get(`${CHANNEL} ${event}`) ?? [])]) cb(data);
}

function setState(current: string) {
  const previous = mockPusher.state;
  mockPusher.state = current;
  if (current !== 'connected') mockPusher.subscribed = false;
  for (const cb of [...mockPusher.stateHandlers]) cb({ previous, current });
}

const progress = (overrides: Partial<CampaignProgress> = {}): CampaignProgress => ({
  campaign_id: ID,
  status: 'active',
  committed_qty: 53,
  min_target_qty: 100,
  max_qty: 150,
  remaining_qty: 95,
  units_to_target: 47,
  deal_confirmed_at: null,
  current_unit_price: { amount_minor: 7900, currency: 'SAR', formatted: '79.00 SAR' },
  deadline_at: '2026-11-08T07:00:00Z',
  updated_at: '2026-11-02T09:00:00Z',
  version: 10,
  ...overrides,
});

const pause = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const clients: ReturnType<typeof createQueryClient>[] = [];

async function setup() {
  const server = mockApi().on('GET', PATH, { body: { data: progress() } });
  const reads = () => server.sent.filter((r) => r.method === 'GET' && r.path === PATH).length;
  const queryClient = createQueryClient();
  clients.push(queryClient);
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client: queryClient }, children);
  const hook = await renderHook(() => useCampaignProgress(ID, true), { wrapper });
  await waitFor(() => expect(hook.result.current.data?.committed_qty).toBe(53));
  // pusher-js loads asynchronously.
  await waitFor(() => expect(mockPusher.handlers.get(`${CHANNEL} progress.updated`)?.size).toBe(1));
  return { server, reads, hook, queryClient };
}

// Drops the queries' garbage-collection timers, which would keep Jest running.
afterEach(() => {
  for (const client of clients.splice(0)) client.clear();
});

// The app keeps one pusher-js client for its life, so the one the first test builds serves
// them all: its own connection handler stays bound; each test's listeners unbind on unmount.
beforeEach(() => {
  mockPusher.handlers.clear();
  mockPusher.subscribed = false;
  mockPusher.state = 'connecting';
  mockPusher.disconnected = 0;
});

it('connects to Reverb with the app key, over TLS only', async () => {
  const { hook } = await setup();
  expect(mockPusher.key).toBe('app-key');
  expect(mockPusher.options).toMatchObject({
    wsHost: 'ws.example.sa',
    wssPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
  });
  await hook.unmount();
  await waitFor(() => expect(mockPusher.disconnected).toBe(1));
});

it('shows pushed numbers, and never older ones from a push or a read', async () => {
  const { server, hook } = await setup();

  await act(async () => {
    setState('connected');
    fire('pusher:subscription_succeeded');
    fire('progress.updated', JSON.stringify(progress({ committed_qty: 60, version: 12 })));
  });
  await waitFor(() => expect(hook.result.current.data?.committed_qty).toBe(60));

  // An older push, a push for another campaign and a broken one change nothing.
  await act(async () => {
    fire('progress.updated', JSON.stringify(progress({ committed_qty: 1, version: 11 })));
    fire(
      'progress.updated',
      JSON.stringify(progress({ campaign_id: 'another', committed_qty: 2 })),
    );
    fire('progress.updated', '{broken');
  });
  // A read that left before the push arrives after it, with older numbers: still 60.
  server.on('GET', PATH, { body: { data: progress({ committed_qty: 58, version: 11 }) } });
  await act(async () => {
    await hook.result.current.refetch();
  });
  expect(hook.result.current.data?.committed_qty).toBe(60);
  await hook.unmount();
});

it('reads on every subscribe, polls slowly while connected, and fast when the connection drops', async () => {
  const { reads, hook } = await setup();
  // Not connected yet: the 100 ms poll runs.
  await waitFor(() => expect(reads()).toBeGreaterThanOrEqual(3));

  await act(async () => {
    setState('connected');
    fire('pusher:subscription_succeeded');
  });
  // The catch-up read, then nothing more for a while.
  await act(() => pause(250));
  const whilePushing = reads();
  await act(() => pause(400));
  expect(reads()).toBe(whilePushing);

  await act(async () => {
    setState('unavailable');
  });
  await waitFor(() => expect(reads()).toBeGreaterThanOrEqual(whilePushing + 2));

  // Back: subscribed again, and it reads to catch up.
  const beforeReconnect = reads();
  await act(async () => {
    setState('connected');
    fire('pusher:subscription_succeeded');
  });
  await waitFor(() => expect(reads()).toBeGreaterThan(beforeReconnect));
  await hook.unmount();
});

it('reads when the app comes back to the foreground', async () => {
  const listeners: ((state: AppStateStatus) => void)[] = [];
  // AppState is already a mock here: swap its implementation for this test only (restoring a
  // spy on it would wipe the implementation the other tests rely on).
  await jest.mocked(AppState.addEventListener).withImplementation(
    (_type, listener) => {
      listeners.push(listener as (state: AppStateStatus) => void);
      return { remove: () => undefined } as ReturnType<typeof AppState.addEventListener>;
    },
    async () => {
      const { reads, hook } = await setup();
      await act(async () => {
        setState('connected');
        fire('pusher:subscription_succeeded');
      });
      await act(() => pause(250));
      const before = reads();

      await act(async () => {
        for (const listener of listeners) listener('active');
      });
      await waitFor(() => expect(reads()).toBe(before + 1));
      await hook.unmount();
    },
  );
});
