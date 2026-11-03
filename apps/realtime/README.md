# Realtime

Laravel Reverb, the WebSocket server that pushes live campaign progress to the website and the mobile app. The API sends each change here over Reverb's HTTP API (`BROADCAST_CONNECTION=reverb` in `apps/api`); this app only runs the server. It has no routes and no database.

It is a separate app because Reverb 1.x needs `guzzlehttp/psr7` 2, which would take the API back to Guzzle 7. Guzzle 7 reports a read timeout as a connection failure, and the API's provider calls rely on telling the two apart (`App\Support\Http\Failures`) so a capture or SMS is never sent twice. Once a Reverb release accepts `guzzlehttp/psr7` 3, this can move into the API.

```bash
cp .env.example .env    # same REVERB_APP_ID/KEY/SECRET as apps/api/.env
composer install
php artisan reverb:start
```

`php artisan reverb:restart` stops a running server after a deploy (the process manager starts it again).

## What goes out

`progress.updated` on the public channel `campaigns.{campaign uuid}`, with the `CampaignProgress` payload of `GET /api/v1/campaigns/{id}/progress`. Clients need only the app key. See "Live progress" in `apps/api/openapi/v1.yaml`.

## Production

Run `php artisan reverb:start` under a process manager, behind the load balancer, which ends TLS on the public `wss://` host and forwards WebSocket upgrades to `REVERB_SERVER_PORT`. Each open page holds one connection, so raise the process's open-file limit (`ulimit -n`). To run more than one process, set `REVERB_SCALING_ENABLED=true` so they share events through Redis.

## Check

`scripts/smoke.mjs` subscribes like a client, has the API broadcast, and waits for the push. CI runs it on every change here (`.github/workflows/realtime.yml`).
