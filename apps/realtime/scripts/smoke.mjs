// End-to-end check of live progress: subscribes to a campaign channel on a
// running Reverb the way the website and app do (Pusher protocol 7), runs
// the command given as arguments with SMOKE_CAMPAIGN_ID set (CI has the API
// broadcast a ProgressUpdated for that campaign), and waits for the push.
//
//   REVERB_APP_KEY=... node scripts/smoke.mjs php ../api/artisan tinker --execute='...'
//
// Needs Node 22 or later (global WebSocket). Exits 1 on anything unexpected.

import { spawn } from "node:child_process";
import { randomUUID } from "node:crypto";

const key = process.env.REVERB_APP_KEY;
const host = process.env.REVERB_HOST || "127.0.0.1";
const port = process.env.REVERB_PORT || "8080";
const scheme = process.env.REVERB_SCHEME === "https" ? "wss" : "ws";
const command = process.argv.slice(2);

if (!key || command.length === 0) {
  console.error("Usage: REVERB_APP_KEY=... node scripts/smoke.mjs <command that broadcasts>");
  process.exit(2);
}

const campaignId = randomUUID();
const channel = `campaigns.${campaignId}`;

function fail(message) {
  console.error(`smoke: ${message}`);
  process.exit(1);
}

const timer = setTimeout(() => fail("no progress.updated within 20 seconds"), 20_000);
const socket = new WebSocket(`${scheme}://${host}:${port}/app/${key}?protocol=7&client=js&version=8.4.0&flash=false`);

socket.addEventListener("error", () => fail(`could not connect to ${host}:${port}`));
socket.addEventListener("message", (message) => {
  const frame = JSON.parse(message.data);

  switch (frame.event) {
    case "pusher:connection_established":
      socket.send(JSON.stringify({ event: "pusher:subscribe", data: { channel } }));
      break;
    case "pusher_internal:subscription_succeeded":
      broadcast();
      break;
    case "pusher:error":
      fail(`Reverb refused: ${JSON.stringify(frame.data)}`);
      break;
    case "progress.updated": {
      if (frame.channel !== channel) fail(`push on the wrong channel: ${frame.channel}`);
      // As in any Pusher event, the payload is a JSON string.
      const progress = JSON.parse(frame.data);
      if (progress.campaign_id !== campaignId) fail(`push for another campaign: ${frame.data}`);
      console.log(`smoke: received progress.updated on ${channel}: ${frame.data}`);
      clearTimeout(timer);
      socket.close();
      process.exit(0);
    }
  }
});

function broadcast() {
  const child = spawn(command[0], command.slice(1), {
    env: { ...process.env, SMOKE_CAMPAIGN_ID: campaignId },
    stdio: "inherit",
  });
  child.on("exit", (code) => {
    if (code !== 0) fail(`the broadcasting command exited with ${code}`);
  });
}
