import { expect, type Page, type WebSocketRoute } from "@playwright/test";
import { ar } from "../src/i18n/dictionaries/ar";

export { ar };

export const KETTLE = "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e01";
export const OTP = "123456";

let counter = 0;

/** A fresh Saudi mobile number, so every test signs up a new customer. */
export function newPhone(): string {
  counter += 1;
  const tail = `${Date.now() % 1_000_000}${counter}`.padStart(8, "0").slice(-8);
  return `05${tail}`;
}

/** Signs in from the current sign-in page; a new customer lands on the welcome page. */
export async function signIn(page: Page, phone = newPhone(), name: string | null = "نورة") {
  await page.getByLabel(ar.auth.phoneLabel).fill(phone);
  await page.getByRole("button", { name: ar.auth.sendCode }).click();
  await page.getByLabel(ar.auth.codeLabel).fill(OTP);
  await expect(page).toHaveURL(/\/account\/welcome/);
  if (name) {
    await page.getByLabel(ar.account.name).fill(name);
    await page.getByRole("button", { name: ar.common.continue }).click();
  } else {
    await page.getByRole("link", { name: ar.auth.skip }).click();
  }
  await expect(page).not.toHaveURL(/\/account\/welcome/);
  return phone;
}

/**
 * Plays Reverb for the page (the build points NEXT_PUBLIC_REVERB_* at a port
 * nothing listens on): answers the Pusher handshake, subscribes and pings,
 * and lets the test push events and drop connections.
 */
export async function actAsReverb(page: Page) {
  const sockets: WebSocketRoute[] = [];
  const subscriptions: string[] = [];
  await page.routeWebSocket(/\/app\//, (ws) => {
    sockets.push(ws);
    ws.onMessage((message) => {
      const frame = JSON.parse(String(message)) as { event: string; data?: { channel?: string } };
      if (frame.event === "pusher:subscribe" && frame.data?.channel) {
        subscriptions.push(frame.data.channel);
        ws.send(JSON.stringify({ event: "pusher_internal:subscription_succeeded", channel: frame.data.channel, data: "{}" }));
      } else if (frame.event === "pusher:ping") {
        ws.send(JSON.stringify({ event: "pusher:pong", data: "{}" }));
      }
    });
    ws.send(JSON.stringify({ event: "pusher:connection_established", data: JSON.stringify({ socket_id: `${sockets.length}.1`, activity_timeout: 30 }) }));
  });
  return {
    subscriptions: () => [...subscriptions],
    push(channel: string, payload: unknown) {
      // As Reverb sends it: the payload is a JSON string.
      sockets.at(-1)?.send(JSON.stringify({ event: "progress.updated", channel, data: JSON.stringify(payload) }));
    },
    dropAll() {
      for (const ws of sockets) void ws.close();
    },
  };
}
