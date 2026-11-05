import { afterEach, describe, expect, it, vi } from "vitest";

// The website calls the API from its own server, so the API sees the web
// container's address unless the customer's is passed on.
const incoming = new Headers();

vi.mock("server-only", () => ({}));
vi.mock("next/headers", () => ({
  headers: async () => incoming,
  cookies: async () => ({ get: () => undefined }),
}));

const { createApi, requestApi } = await import("@/lib/api/server");

function captureRequests() {
  const sent: Headers[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (_url: string, init: RequestInit) => {
      sent.push(new Headers(init.headers));
      return new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }),
  );
  return sent;
}

afterEach(() => {
  vi.unstubAllGlobals();
  incoming.delete("x-forwarded-for");
});

describe("calls made on a customer's behalf", () => {
  it("pass on the customer's address from the edge proxy", async () => {
    const sent = captureRequests();
    incoming.set("x-forwarded-for", "203.0.113.7");

    await (await requestApi("ar")).POST("/auth/otp", { body: { phone: "+966500000000" } });

    expect(sent[0].get("x-forwarded-for")).toBe("203.0.113.7");
    expect(sent[0].get("accept-language")).toBe("ar");
  });

  it("send no address when the request came without one", async () => {
    const sent = captureRequests();

    await (await requestApi("en", "token-1")).GET("/me");

    expect(sent[0].has("x-forwarded-for")).toBe(false);
    expect(sent[0].get("authorization")).toBe("Bearer token-1");
  });

  it("leave public reads without an address", async () => {
    const sent = captureRequests();
    incoming.set("x-forwarded-for", "203.0.113.7");

    await createApi("ar").GET("/categories");

    expect(sent[0].has("x-forwarded-for")).toBe(false);
  });
});
