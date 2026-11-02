# API

The Laravel 13 API behind the customer website, the admin portal and the mobile app. Setup, ground rules and architecture are in the repository [README](../../README.md) and [docs/architecture.md](../../docs/architecture.md).

## Endpoints

The full list, including endpoints that so far exist only on the mock server, is the contract in [`openapi/v1.yaml`](openapi/v1.yaml). Operations marked `x-status: live` are served by this app:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/health` | 200 when PostgreSQL and Redis respond, 503 otherwise |
| GET | `/up` | Laravel's liveness check (not part of the contract) |
