/**
 * loadPusher against the React Native build of pusher-js, which the app bundles: it exports
 * `{ Pusher }` and no default (Jest itself resolves the Node build).
 */
import { loadPusher } from '@/live/progress';

jest.mock('pusher-js', () => jest.requireActual('pusher-js/dist/react-native/pusher.js'));

const opened: string[] = [];

class FakeWebSocket {
  onopen: (() => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onmessage: (() => void) | null = null;
  readyState = 0;
  constructor(url: string) {
    opened.push(url);
  }
  send() {}
  close() {
    this.readyState = 3;
  }
}

const realWebSocket = globalThis.WebSocket;
beforeAll(() => {
  (globalThis as { WebSocket: unknown }).WebSocket = FakeWebSocket;
});
afterAll(() => {
  (globalThis as { WebSocket: unknown }).WebSocket = realWebSocket;
});

it('builds a client from the React Native build and opens a socket to Reverb', async () => {
  const client = await loadPusher({ key: 'app-key', host: 'ws.example.sa', port: 443, tls: true });
  expect(typeof client.subscribe).toBe('function');
  await new Promise((resolve) => setTimeout(resolve, 10));
  expect(opened[0]).toMatch(/^wss:\/\/ws\.example\.sa:443\/app\/app-key\?/);
  client.disconnect();
  expect(client.connection.state).toBe('disconnected');
});
