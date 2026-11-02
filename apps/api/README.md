# API

The Laravel 13 API behind the customer website, the admin portal and the mobile app. Setup, ground rules and architecture are in the repository [README](../../README.md) and [docs/architecture.md](../../docs/architecture.md).

## Endpoints so far

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/health` | 200 when PostgreSQL and Redis respond, 503 otherwise |
| GET | `/up` | Laravel's liveness check |
