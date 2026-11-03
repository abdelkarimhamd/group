import type { CampaignProgress } from '@/api/types';
import {
  parseProgress,
  reverbConfig,
  subscribeToProgress,
  type PusherClient,
  type ReverbConfig,
} from '@/live/progress';

const ID = '8f14e45f-ceea-467f-a8f0-9a1f3b2c4d5e';

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

describe('where Reverb is', () => {
  it('needs a key and a host', () => {
    expect(reverbConfig({ host: 'ws.example.sa' })).toBeNull();
    expect(reverbConfig({ key: 'app-key' })).toBeNull();
    expect(reverbConfig({ key: ' ', host: 'ws.example.sa' })).toBeNull();
  });

  it('defaults to TLS on 443', () => {
    expect(reverbConfig({ key: 'app-key', host: 'ws.example.sa' })).toEqual({
      key: 'app-key',
      host: 'ws.example.sa',
      port: 443,
      tls: true,
    });
  });

  it('takes a plain local server', () => {
    expect(reverbConfig({ key: 'k', host: 'localhost', port: '8080', scheme: 'http' })).toEqual({
      key: 'k',
      host: 'localhost',
      port: 8080,
      tls: false,
    });
    expect(reverbConfig({ key: 'k', host: 'localhost', scheme: 'http' })?.port).toBe(80);
  });

  it('refuses a port that is not one', () => {
    expect(reverbConfig({ key: 'k', host: 'h', port: 'eighty' })).toBeNull();
    expect(reverbConfig({ key: 'k', host: 'h', port: '70000' })).toBeNull();
  });
});

describe('a pushed payload', () => {
  it('is read from the JSON string Pusher delivers, or an object', () => {
    expect(parseProgress(JSON.stringify(progress()), ID)).toEqual(progress());
    expect(parseProgress(progress(), ID)).toEqual(progress());
  });

  it('is dropped when it is for another campaign', () => {
    expect(parseProgress(progress({ campaign_id: 'another' }), ID)).toBeNull();
  });

  it("is dropped when it is not the contract's shape", () => {
    expect(parseProgress('not json', ID)).toBeNull();
    expect(parseProgress(null, ID)).toBeNull();
    expect(parseProgress({ ...progress(), status: 'draft' }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), committed_qty: -1 }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), remaining_qty: 1.5 }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), current_unit_price: null }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), updated_at: 'yesterday' }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), deal_confirmed_at: 'soon' }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), version: '11' }, ID)).toBeNull();
    const { units_to_target: _dropped, ...partial } = progress();
    expect(parseProgress(partial, ID)).toBeNull();
  });

  it("keeps a confirmed deal's time", () => {
    const confirmed = progress({ deal_confirmed_at: '2026-11-03T10:00:00Z', units_to_target: 0 });
    expect(parseProgress(confirmed, ID)).toEqual(confirmed);
  });
});

/** Stands in for pusher-js: records bindings and lets a test fire events. */
function fakePusher() {
  const channels = new Map<string, Map<string, Set<(data: unknown) => void>>>();
  const stateHandlers = new Set<(change: { previous: string; current: string }) => void>();
  const subscribedChannels = new Set<string>();
  // Subscribes sent and not yet answered: like pusher-js, unsubscribe then
  // only cancels, and the channel stays held.
  const pending = new Set<string>();
  const resubscribed: string[] = [];
  const client = {
    connection: {
      state: 'connecting',
      bind: (event: string, cb: (change: { previous: string; current: string }) => void) => {
        if (event === 'state_change') stateHandlers.add(cb);
      },
      unbind: (event: string, cb: (change: { previous: string; current: string }) => void) => {
        if (event === 'state_change') stateHandlers.delete(cb);
      },
    },
    subscribe: jest.fn((name: string) => {
      const events = channels.get(name) ?? new Map<string, Set<(data: unknown) => void>>();
      channels.set(name, events);
      if (!subscribedChannels.has(name)) pending.add(name);
      return {
        get subscribed() {
          return subscribedChannels.has(name);
        },
        bind: (event: string, cb: (data: unknown) => void) => {
          const set = events.get(event) ?? new Set();
          set.add(cb);
          events.set(event, set);
        },
        unbind: (event: string, cb: (data: unknown) => void) => events.get(event)?.delete(cb),
      };
    }),
    unsubscribe: jest.fn((name: string) => {
      if (pending.has(name)) return;
      channels.delete(name);
      subscribedChannels.delete(name);
    }),
    allChannels: () => [...channels.keys()].map((name) => ({ name })),
    connect: jest.fn(() => {
      client.connection.state = 'connecting';
    }),
    disconnect: jest.fn(() => {
      client.connection.state = 'disconnected';
      subscribedChannels.clear();
    }),
  };
  return {
    client: client as unknown as PusherClient & typeof client,
    fire(channel: string, event: string, data?: unknown) {
      if (event === 'pusher:subscription_succeeded') {
        pending.delete(channel);
        subscribedChannels.add(channel);
      }
      for (const cb of [...(channels.get(channel)?.get(event) ?? [])]) cb(data);
    },
    state(current: string) {
      const previous = client.connection.state;
      client.connection.state = current;
      if (current !== 'connected') {
        subscribedChannels.clear();
        pending.clear();
      }
      for (const cb of [...stateHandlers]) cb({ previous, current });
      // Then pusher-js subscribes again to every channel it holds.
      if (current === 'connected' && previous !== 'connected') {
        for (const name of channels.keys()) {
          pending.add(name);
          resubscribed.push(name);
        }
      }
    },
    resubscribed,
    listeners: () =>
      [...channels.values()].reduce(
        (n, events) => n + [...events.values()].reduce((m, s) => m + s.size, 0),
        0,
      ) + stateHandlers.size,
  };
}

const config: ReverbConfig = { key: 'app-key', host: 'ws.example.sa', port: 443, tls: true };
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const pause = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
const handlers = () => ({ onProgress: jest.fn(), onLive: jest.fn() });

describe("listening for a campaign's pushes", () => {
  it('subscribes to its channel and hands over valid pushes only', async () => {
    const pusher = fakePusher();
    const onProgress = jest.fn();
    const onLive = jest.fn();
    const stop = subscribeToProgress(
      ID,
      { onProgress, onLive },
      config,
      async () => pusher.client,
      0,
    );
    await flush();

    expect(pusher.client.subscribe).toHaveBeenCalledWith(`campaigns.${ID}`);
    pusher.fire(`campaigns.${ID}`, 'pusher:subscription_succeeded');
    expect(onLive).toHaveBeenLastCalledWith(true);

    pusher.fire(
      `campaigns.${ID}`,
      'progress.updated',
      JSON.stringify(progress({ committed_qty: 60 })),
    );
    pusher.fire(
      `campaigns.${ID}`,
      'progress.updated',
      JSON.stringify(progress({ campaign_id: 'another' })),
    );
    pusher.fire(`campaigns.${ID}`, 'progress.updated', '{broken');
    expect(onProgress).toHaveBeenCalledTimes(1);
    expect(onProgress.mock.calls[0][0].committed_qty).toBe(60);

    stop();
  });

  it('reports a dropped connection, and live again after the resubscribe', async () => {
    const pusher = fakePusher();
    const onLive = jest.fn();
    const stop = subscribeToProgress(
      ID,
      { onProgress: jest.fn(), onLive },
      config,
      async () => pusher.client,
      0,
    );
    await flush();

    pusher.state('connected');
    pusher.fire(`campaigns.${ID}`, 'pusher:subscription_succeeded');
    pusher.state('unavailable');
    pusher.state('connecting');
    pusher.state('connected');
    pusher.fire(`campaigns.${ID}`, 'pusher:subscription_succeeded');

    expect(onLive.mock.calls.map(([live]) => live)).toEqual([true, false, true]);
    stop();
  });

  it('shares one connection and closes it a while after the last listener leaves', async () => {
    const pusher = fakePusher();
    const factory = jest.fn(async () => pusher.client);
    const stopFirst = subscribeToProgress(ID, handlers(), config, factory, 0);
    const stopSecond = subscribeToProgress('another', handlers(), config, factory, 0);
    await flush();

    expect(factory).toHaveBeenCalledTimes(1);
    stopFirst();
    stopFirst();
    await flush();
    expect(pusher.client.unsubscribe).toHaveBeenCalledWith(`campaigns.${ID}`);
    expect(pusher.client.disconnect).not.toHaveBeenCalled();

    stopSecond();
    await flush();
    await flush();
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);
    // Only the client's own check on reconnecting is left.
    expect(pusher.listeners()).toBe(1);
  });

  it('keeps the connection when the next live screen opens soon, and reuses the client after an idle close', async () => {
    const pusher = fakePusher();
    const factory = jest.fn(async () => pusher.client);
    const stopFirst = subscribeToProgress(ID, handlers(), config, factory, 50);
    await flush();
    stopFirst();

    // Moving to another campaign: same connection, nothing closed.
    const stopSecond = subscribeToProgress('another', handlers(), config, factory, 50);
    await pause(80);
    expect(pusher.client.disconnect).not.toHaveBeenCalled();

    stopSecond();
    await pause(80);
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);

    // Later: the same client connects again; pusher-js never builds a second.
    const stopThird = subscribeToProgress(ID, handlers(), config, factory, 50);
    await flush();
    expect(factory).toHaveBeenCalledTimes(1);
    expect(pusher.client.connect).toHaveBeenCalledTimes(1);
    expect(pusher.client.subscribe).toHaveBeenLastCalledWith(`campaigns.${ID}`);
    stopThird();
  });

  it("lets two listeners share a campaign's channel, which stays until the last leaves", async () => {
    const pusher = fakePusher();
    const factory = async () => pusher.client;
    const first = handlers();
    const second = handlers();
    const stopFirst = subscribeToProgress(ID, first, config, factory, 0);
    await flush();
    pusher.state('connected');
    pusher.fire(`campaigns.${ID}`, 'pusher:subscription_succeeded');

    // Already subscribed: the second hears it at once.
    const stopSecond = subscribeToProgress(ID, second, config, factory, 0);
    await flush();
    expect(second.onLive).toHaveBeenLastCalledWith(true);

    stopFirst();
    expect(pusher.client.unsubscribe).not.toHaveBeenCalled();
    pusher.fire(
      `campaigns.${ID}`,
      'progress.updated',
      JSON.stringify(progress({ committed_qty: 61 })),
    );
    expect(first.onProgress).not.toHaveBeenCalled();
    expect(second.onProgress).toHaveBeenCalledTimes(1);

    stopSecond();
    await flush();
    await flush();
    expect(pusher.client.unsubscribe).toHaveBeenCalledWith(`campaigns.${ID}`);
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);
  });

  it('stops following a campaign left while its subscribe was unanswered, once the connection comes back', async () => {
    const pusher = fakePusher();
    const factory = async () => pusher.client;
    const stopLeft = subscribeToProgress(ID, handlers(), config, factory, 0);
    const stopStaying = subscribeToProgress('another', handlers(), config, factory, 0);
    await flush();
    pusher.state('connected');

    // The screen leaves before Reverb answers its subscribe, then the
    // connection drops and comes back.
    stopLeft();
    pusher.state('unavailable');
    pusher.state('connecting');
    pusher.resubscribed.length = 0;
    pusher.state('connected');

    expect(pusher.resubscribed).toEqual(['campaigns.another']);
    stopStaying();
  });

  it('does nothing when stopped before pusher-js has loaded', async () => {
    const pusher = fakePusher();
    const stop = subscribeToProgress(ID, handlers(), config, async () => pusher.client, 0);
    stop();
    await flush();
    await flush();

    expect(pusher.client.subscribe).not.toHaveBeenCalled();
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);
  });

  it('keeps the screen polling when pusher-js cannot load, and tries again on the next screen', async () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);
    const pusher = fakePusher();
    const factory = jest.fn<Promise<PusherClient>, [ReverbConfig]>();
    factory.mockRejectedValueOnce(new Error('bundle')).mockResolvedValueOnce(pusher.client);
    const onLive = jest.fn();
    const stop = subscribeToProgress(ID, { onProgress: jest.fn(), onLive }, config, factory, 0);
    await flush();

    expect(onLive).not.toHaveBeenCalled();
    expect(warn).toHaveBeenCalled();
    stop();
    const again = subscribeToProgress(ID, handlers(), config, factory, 0);
    await flush();
    expect(factory).toHaveBeenCalledTimes(2);
    expect(pusher.client.subscribe).toHaveBeenCalled();
    again();
    warn.mockRestore();
  });
});
