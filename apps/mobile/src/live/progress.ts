import type { CampaignProgress, CampaignStatus } from '@/api/types';

// Live campaign progress over Laravel Reverb (Pusher protocol): event
// `progress.updated` on the public channel `campaigns.{campaign id}`, with
// the payload of GET /campaigns/{id}/progress. Pushes are best effort, so
// the screen still reads that endpoint after every (re)connect and keeps a
// slow poll; see useCampaignProgress. The website has the same module
// (apps/web/src/lib/live-progress.ts).

export type ReverbConfig = { key: string; host: string; port: number; tls: boolean };

/**
 * Where Reverb is, from EXPO_PUBLIC_REVERB_*. Null without a key or host: the
 * screens then poll every 5 seconds.
 */
export function reverbConfig(env: {
  key?: string;
  host?: string;
  port?: string;
  scheme?: string;
}): ReverbConfig | null {
  const key = env.key?.trim();
  const host = env.host?.trim();
  if (!key || !host) return null;
  const tls = (env.scheme?.trim() || 'https') === 'https';
  const port = Number(env.port?.trim() || (tls ? 443 : 80));
  if (!Number.isInteger(port) || port < 1 || port > 65535) return null;
  return { key, host, port, tls };
}

const STATUSES = new Set<CampaignStatus>([
  'scheduled',
  'active',
  'closing',
  'purchasing',
  'fulfilling',
  'completed',
  'failed',
  'cancelled',
]);

const count = (value: unknown): value is number =>
  Number.isSafeInteger(value) && (value as number) >= 0;
const time = (value: unknown): value is string =>
  typeof value === 'string' && !Number.isNaN(Date.parse(value));

/**
 * A pushed payload, if it is this campaign's progress in the contract's
 * shape. Anything else is dropped; the next read or push corrects the page.
 */
export function parseProgress(data: unknown, campaignId: string): CampaignProgress | null {
  let value = data;
  if (typeof value === 'string') {
    try {
      value = JSON.parse(value);
    } catch {
      return null;
    }
  }
  if (typeof value !== 'object' || value === null) return null;
  const p = value as Record<string, unknown>;
  const price = p.current_unit_price as Record<string, unknown> | null | undefined;
  const valid =
    p.campaign_id === campaignId &&
    STATUSES.has(p.status as CampaignStatus) &&
    count(p.committed_qty) &&
    count(p.min_target_qty) &&
    count(p.max_qty) &&
    count(p.remaining_qty) &&
    count(p.units_to_target) &&
    (p.deal_confirmed_at === null || time(p.deal_confirmed_at)) &&
    typeof price === 'object' &&
    price !== null &&
    count(price.amount_minor) &&
    typeof price.currency === 'string' &&
    time(p.deadline_at) &&
    time(p.updated_at);
  return valid ? (p as CampaignProgress) : null;
}

/**
 * The numbers to show when a push and a read cross: the later `updated_at`
 * wins, and a tie takes the incoming one (the API sends a campaign's pushes
 * in order).
 */
export function newer(current: CampaignProgress, incoming: CampaignProgress): CampaignProgress {
  return Date.parse(incoming.updated_at) >= Date.parse(current.updated_at) ? incoming : current;
}

export const CHANNEL_PREFIX = 'campaigns.';
// A template literal: the translation-key check reads quoted 'progress.*' as a key.
export const PROGRESS_EVENT = `progress.updated`;

export type ProgressSubscription = {
  /** Called with each valid push for this campaign. */
  onProgress: (progress: CampaignProgress) => void;
  /**
   * True once subscribed (again after every reconnect: catch up then),
   * false whenever the connection drops.
   */
  onLive: (live: boolean) => void;
};

// The parts of pusher-js used here, so tests can stand in for it.
type Channel = {
  subscribed: boolean;
  bind(event: string, callback: (data: unknown) => void): unknown;
  unbind(event?: string, callback?: (data: unknown) => void): unknown;
};
type Connection = {
  state: string;
  bind(event: string, callback: (change: { previous: string; current: string }) => void): unknown;
  unbind(
    event?: string,
    callback?: (change: { previous: string; current: string }) => void,
  ): unknown;
};
export type PusherClient = {
  connection: Connection;
  subscribe(channel: string): Channel;
  unsubscribe(channel: string): void;
  disconnect(): void;
};
export type PusherFactory = (config: ReverbConfig) => Promise<PusherClient>;

/**
 * pusher-js (its React Native build, which watches the network through
 * @react-native-community/netinfo), loaded on the first live campaign screen.
 */
export const loadPusher: PusherFactory = async (config) => {
  // A plain require: Jest cannot run import() without experimental flags.
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const Pusher = (require('pusher-js') as typeof import('pusher-js')).default;
  return new Pusher(config.key, {
    cluster: '',
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.tls,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
  }) as unknown as PusherClient;
};

// One connection for the app, shared by every listener and closed with the
// last. A channel is shared too (the campaign screen stays mounted under the
// checkout, both listening): it is left with its last listener.
let shared: { client: Promise<PusherClient>; users: number; channels: Map<string, number> } | null =
  null;

/**
 * Listens for one campaign's pushes. Returns the function that stops; it
 * may be called before the client has loaded.
 */
export function subscribeToProgress(
  campaignId: string,
  handlers: ProgressSubscription,
  config: ReverbConfig,
  factory: PusherFactory = loadPusher,
): () => void {
  const name = CHANNEL_PREFIX + campaignId;
  let stopped = false;
  let cleanup: (() => void) | undefined;

  if (!shared) shared = { client: factory(config), users: 0, channels: new Map() };
  const mine = shared;
  mine.users += 1;

  mine.client.then(
    (client) => {
      if (stopped) return;
      let live = false;
      const onSubscribed = () => {
        live = true;
        handlers.onLive(true);
      };
      const onPush = (data: unknown) => {
        const progress = parseProgress(data, campaignId);
        if (progress) handlers.onProgress(progress);
      };
      const onState = ({ current }: { current: string }) => {
        // pusher-js subscribes again after a reconnect, and fires
        // subscription_succeeded then.
        if (current !== 'connected' && live) {
          live = false;
          handlers.onLive(false);
        }
      };
      mine.channels.set(name, (mine.channels.get(name) ?? 0) + 1);
      const channel = client.subscribe(name);
      channel.bind('pusher:subscription_succeeded', onSubscribed);
      channel.bind(PROGRESS_EVENT, onPush);
      client.connection.bind('state_change', onState);
      // Another listener already subscribed: no second subscription_succeeded.
      if (channel.subscribed && client.connection.state === 'connected') onSubscribed();
      cleanup = () => {
        channel.unbind('pusher:subscription_succeeded', onSubscribed);
        channel.unbind(PROGRESS_EVENT, onPush);
        client.connection.unbind('state_change', onState);
        const left = (mine.channels.get(name) ?? 1) - 1;
        if (left > 0) {
          mine.channels.set(name, left);
        } else {
          mine.channels.delete(name);
          client.unsubscribe(name);
        }
      };
    },
    () => {
      // pusher-js failed to load: the screen keeps polling.
    },
  );

  return () => {
    if (stopped) return;
    stopped = true;
    cleanup?.();
    mine.users -= 1;
    if (mine.users === 0) {
      if (shared === mine) shared = null;
      mine.client.then(
        (client) => client.disconnect(),
        () => undefined,
      );
    }
  };
}
