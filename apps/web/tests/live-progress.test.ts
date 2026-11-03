import { describe, expect, it, vi } from "vitest";
import type { CampaignProgress } from "@/lib/api/types";
import {
  newer,
  parseProgress,
  reverbConfig,
  subscribeToProgress,
  type PusherClient,
  type ReverbConfig,
} from "@/lib/live-progress";

const ID = "8f14e45f-ceea-467f-a8f0-9a1f3b2c4d5e";

const progress = (overrides: Partial<CampaignProgress> = {}): CampaignProgress => ({
  campaign_id: ID,
  status: "active",
  committed_qty: 53,
  min_target_qty: 100,
  max_qty: 150,
  remaining_qty: 95,
  units_to_target: 47,
  deal_confirmed_at: null,
  current_unit_price: { amount_minor: 7900, currency: "SAR", formatted: "79.00 SAR" },
  deadline_at: "2026-11-08T07:00:00Z",
  updated_at: "2026-11-02T09:00:00Z",
  ...overrides,
});

describe("where Reverb is", () => {
  it("needs a key and a host", () => {
    expect(reverbConfig({ host: "ws.example.sa" })).toBeNull();
    expect(reverbConfig({ key: "app-key" })).toBeNull();
    expect(reverbConfig({ key: " ", host: "ws.example.sa" })).toBeNull();
  });

  it("defaults to TLS on 443", () => {
    expect(reverbConfig({ key: "app-key", host: "ws.example.sa" })).toEqual({
      key: "app-key",
      host: "ws.example.sa",
      port: 443,
      tls: true,
    });
  });

  it("takes a plain local server", () => {
    expect(reverbConfig({ key: "k", host: "localhost", port: "8080", scheme: "http" })).toEqual({
      key: "k",
      host: "localhost",
      port: 8080,
      tls: false,
    });
    expect(reverbConfig({ key: "k", host: "localhost", scheme: "http" })?.port).toBe(80);
  });

  it("refuses a port that is not one", () => {
    expect(reverbConfig({ key: "k", host: "h", port: "eighty" })).toBeNull();
    expect(reverbConfig({ key: "k", host: "h", port: "70000" })).toBeNull();
  });
});

describe("a pushed payload", () => {
  it("is read from the JSON string Pusher delivers, or an object", () => {
    expect(parseProgress(JSON.stringify(progress()), ID)).toEqual(progress());
    expect(parseProgress(progress(), ID)).toEqual(progress());
  });

  it("is dropped when it is for another campaign", () => {
    expect(parseProgress(progress({ campaign_id: "another" }), ID)).toBeNull();
  });

  it("is dropped when it is not the contract's shape", () => {
    expect(parseProgress("not json", ID)).toBeNull();
    expect(parseProgress(null, ID)).toBeNull();
    expect(parseProgress({ ...progress(), status: "draft" }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), committed_qty: -1 }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), remaining_qty: 1.5 }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), current_unit_price: null }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), updated_at: "yesterday" }, ID)).toBeNull();
    expect(parseProgress({ ...progress(), deal_confirmed_at: "soon" }, ID)).toBeNull();
    const { units_to_target: _dropped, ...partial } = progress();
    expect(parseProgress(partial, ID)).toBeNull();
  });

  it("keeps a confirmed deal's time", () => {
    const confirmed = progress({ deal_confirmed_at: "2026-11-03T10:00:00Z", units_to_target: 0 });
    expect(parseProgress(confirmed, ID)).toEqual(confirmed);
  });
});

describe("when a push and a read cross", () => {
  it("keeps the later numbers", () => {
    const older = progress({ committed_qty: 53, updated_at: "2026-11-02T09:00:00Z" });
    const later = progress({ committed_qty: 60, updated_at: "2026-11-02T09:00:05Z" });
    expect(newer(older, later)).toBe(later);
    expect(newer(later, older)).toBe(later);
  });

  it("takes the incoming numbers on a tie", () => {
    const shown = progress({ committed_qty: 53 });
    const incoming = progress({ committed_qty: 54 });
    expect(newer(shown, incoming)).toBe(incoming);
  });
});

/** Stands in for pusher-js: records bindings and lets a test fire events. */
function fakePusher() {
  const channels = new Map<string, Map<string, Set<(data: unknown) => void>>>();
  const stateHandlers = new Set<(change: { previous: string; current: string }) => void>();
  const subscribedChannels = new Set<string>();
  const client = {
    connection: {
      state: "connecting",
      bind: (event: string, cb: (change: { previous: string; current: string }) => void) => {
        if (event === "state_change") stateHandlers.add(cb);
      },
      unbind: (event: string, cb: (change: { previous: string; current: string }) => void) => {
        if (event === "state_change") stateHandlers.delete(cb);
      },
    },
    subscribe: vi.fn((name: string) => {
      const events = channels.get(name) ?? new Map<string, Set<(data: unknown) => void>>();
      channels.set(name, events);
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
    unsubscribe: vi.fn((name: string) => {
      channels.delete(name);
      subscribedChannels.delete(name);
    }),
    disconnect: vi.fn(),
  };
  return {
    client: client as unknown as PusherClient & typeof client,
    fire(channel: string, event: string, data?: unknown) {
      if (event === "pusher:subscription_succeeded") subscribedChannels.add(channel);
      for (const cb of [...(channels.get(channel)?.get(event) ?? [])]) cb(data);
    },
    state(current: string) {
      const previous = client.connection.state;
      client.connection.state = current;
      if (current !== "connected") subscribedChannels.clear();
      for (const cb of [...stateHandlers]) cb({ previous, current });
    },
    listeners: () => [...channels.values()].reduce((n, events) => n + [...events.values()].reduce((m, s) => m + s.size, 0), 0) + stateHandlers.size,
  };
}

const config: ReverbConfig = { key: "app-key", host: "ws.example.sa", port: 443, tls: true };
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe("listening for a campaign's pushes", () => {
  it("subscribes to its channel and hands over valid pushes only", async () => {
    const pusher = fakePusher();
    const onProgress = vi.fn();
    const onLive = vi.fn();
    const stop = subscribeToProgress(ID, { onProgress, onLive }, config, async () => pusher.client);
    await flush();

    expect(pusher.client.subscribe).toHaveBeenCalledWith(`campaigns.${ID}`);
    pusher.fire(`campaigns.${ID}`, "pusher:subscription_succeeded");
    expect(onLive).toHaveBeenLastCalledWith(true);

    pusher.fire(`campaigns.${ID}`, "progress.updated", JSON.stringify(progress({ committed_qty: 60 })));
    pusher.fire(`campaigns.${ID}`, "progress.updated", JSON.stringify(progress({ campaign_id: "another" })));
    pusher.fire(`campaigns.${ID}`, "progress.updated", "{broken");
    expect(onProgress).toHaveBeenCalledTimes(1);
    expect(onProgress.mock.calls[0][0].committed_qty).toBe(60);

    stop();
  });

  it("reports a dropped connection, and live again after the resubscribe", async () => {
    const pusher = fakePusher();
    const onLive = vi.fn();
    const stop = subscribeToProgress(ID, { onProgress: vi.fn(), onLive }, config, async () => pusher.client);
    await flush();

    pusher.state("connected");
    pusher.fire(`campaigns.${ID}`, "pusher:subscription_succeeded");
    pusher.state("unavailable");
    pusher.state("connecting");
    pusher.state("connected");
    pusher.fire(`campaigns.${ID}`, "pusher:subscription_succeeded");

    expect(onLive.mock.calls.map(([live]) => live)).toEqual([true, false, true]);
    stop();
  });

  it("shares one connection and closes it with the last listener", async () => {
    const pusher = fakePusher();
    const factory = vi.fn(async () => pusher.client);
    const stopFirst = subscribeToProgress(ID, { onProgress: vi.fn(), onLive: vi.fn() }, config, factory);
    const stopSecond = subscribeToProgress("another", { onProgress: vi.fn(), onLive: vi.fn() }, config, factory);
    await flush();

    expect(factory).toHaveBeenCalledTimes(1);
    stopFirst();
    stopFirst();
    await flush();
    expect(pusher.client.unsubscribe).toHaveBeenCalledWith(`campaigns.${ID}`);
    expect(pusher.client.disconnect).not.toHaveBeenCalled();

    stopSecond();
    await flush();
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);
    expect(pusher.listeners()).toBe(0);
  });

  it("lets two listeners share a campaign's channel, which stays until the last leaves", async () => {
    const pusher = fakePusher();
    const factory = async () => pusher.client;
    const first = { onProgress: vi.fn(), onLive: vi.fn() };
    const second = { onProgress: vi.fn(), onLive: vi.fn() };
    const stopFirst = subscribeToProgress(ID, first, config, factory);
    await flush();
    pusher.state("connected");
    pusher.fire(`campaigns.${ID}`, "pusher:subscription_succeeded");

    // Already subscribed: the second hears it at once.
    const stopSecond = subscribeToProgress(ID, second, config, factory);
    await flush();
    expect(second.onLive).toHaveBeenLastCalledWith(true);

    stopFirst();
    expect(pusher.client.unsubscribe).not.toHaveBeenCalled();
    pusher.fire(`campaigns.${ID}`, "progress.updated", JSON.stringify(progress({ committed_qty: 61 })));
    expect(first.onProgress).not.toHaveBeenCalled();
    expect(second.onProgress).toHaveBeenCalledTimes(1);

    stopSecond();
    await flush();
    expect(pusher.client.unsubscribe).toHaveBeenCalledWith(`campaigns.${ID}`);
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);
  });

  it("does nothing when stopped before pusher-js has loaded", async () => {
    const pusher = fakePusher();
    const stop = subscribeToProgress(ID, { onProgress: vi.fn(), onLive: vi.fn() }, config, async () => pusher.client);
    stop();
    await flush();

    expect(pusher.client.subscribe).not.toHaveBeenCalled();
    expect(pusher.client.disconnect).toHaveBeenCalledTimes(1);
  });

  it("keeps the page polling when pusher-js cannot load", async () => {
    const onLive = vi.fn();
    const stop = subscribeToProgress(ID, { onProgress: vi.fn(), onLive }, config, () => Promise.reject(new Error("chunk")));
    await flush();

    expect(onLive).not.toHaveBeenCalled();
    stop();
    await flush();
    // A later page gets a fresh try.
    const pusher = fakePusher();
    const again = subscribeToProgress(ID, { onProgress: vi.fn(), onLive: vi.fn() }, config, async () => pusher.client);
    await flush();
    expect(pusher.client.subscribe).toHaveBeenCalled();
    again();
  });
});
