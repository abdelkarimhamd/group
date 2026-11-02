/**
 * A stand-in for the API in component and client tests: queue a response per route, then
 * read back what the app sent.
 */
type Reply = { status?: number; body?: unknown; headers?: Record<string, string> };
type Handler = Reply | ((request: Request, body: unknown) => Reply | Promise<Reply>);

export type SentRequest = { method: string; path: string; headers: Headers; body: unknown };

export function mockApi() {
  const routes = new Map<string, Handler[]>();
  const sent: SentRequest[] = [];

  const fetchMock = jest.fn(async (input: Request | string, init?: RequestInit) => {
    const request = typeof input === 'string' ? new Request(input, init) : input;
    const url = new URL(request.url);
    const text = request.method === 'GET' ? '' : await request.text();
    const body = text ? JSON.parse(text) : undefined;
    const key = `${request.method} ${url.pathname}`;
    sent.push({
      method: request.method,
      path: url.pathname + url.search,
      headers: request.headers,
      body,
    });

    const queue = routes.get(key);
    const handler = queue && (queue.length > 1 ? queue.shift()! : queue[0]);
    if (!handler) {
      return new Response(JSON.stringify({ message: `No mock for ${key}` }), { status: 599 });
    }
    const reply = typeof handler === 'function' ? await handler(request, body) : handler;
    const status = reply.status ?? 200;
    return new Response(status === 204 ? null : JSON.stringify(reply.body ?? {}), {
      status,
      headers: { 'Content-Type': 'application/json', ...reply.headers },
    });
  });

  globalThis.fetch = fetchMock as unknown as typeof fetch;

  return {
    /** Answers `METHOD /path`; several calls queue replies, the last one repeats. */
    on(method: string, path: string, handler: Handler) {
      const key = `${method} ${path}`;
      routes.set(key, [...(routes.get(key) ?? []), handler]);
      return this;
    },
    sent,
    last(method: string, path: string): SentRequest | undefined {
      return sent.filter((r) => r.method === method && r.path.split('?')[0] === path).at(-1);
    },
    fetchMock,
  };
}
