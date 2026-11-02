# API

The Laravel 13 API behind the customer website, the admin portal and the mobile app. Setup, ground rules and architecture are in the repository [README](../../README.md) and [docs/architecture.md](../../docs/architecture.md).

## Endpoints

The full list, including endpoints that so far exist only on the mock server, is the contract in [`openapi/v1.yaml`](openapi/v1.yaml). Operations marked `x-status: live` are served by this app:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/health` | 200 when PostgreSQL and Redis respond, 503 otherwise |
| POST | `/api/v1/auth/otp`, `/auth/otp/verify` | Customer sign-in: SMS code, then a 90-day token |
| POST | `/api/v1/auth/logout` | Revoke the current customer token |
| GET, PATCH | `/api/v1/me` | The customer's profile |
| POST | `/api/v1/me/privacy-consent` | Record consent to the current privacy policy |
| GET, POST, PATCH, DELETE | `/api/v1/me/addresses` | Saved delivery addresses |
| POST | `/api/v1/admin/auth/login`, `/admin/auth/verify` | Admin sign-in: password, then SMS code, then a 12-hour token |
| POST | `/api/v1/admin/auth/logout` | Revoke the current admin token |
| GET | `/api/v1/admin/me` | The admin, with roles and permissions |
| POST | `/api/v1/admin/me/password` | Change your own password; your other sessions end |
| GET | `/api/v1/admin/roles` | Roles, their permissions and whether you can grant each (`admins.manage`) |
| GET, POST | `/api/v1/admin/admins` | List and add admins (`admins.manage`) |
| PATCH | `/api/v1/admin/admins/{admin_id}` | Change an admin's details, roles or status, or reset their password (`admins.manage`) |
| GET | `/api/v1/categories` | Browsing categories, named in the request's language |
| GET, POST, PATCH | `/api/v1/admin/categories` | Categories (`catalog.view`, `catalog.edit`) |
| GET, POST, PATCH | `/api/v1/admin/products`, `/admin/products/{id}/variants`, `/admin/variants/{id}` | Products from Alibaba listings and their variants |
| POST, DELETE | `/api/v1/admin/products/{id}/images`, `/admin/product-images/{id}` | Product photos |
| GET, POST, PATCH | `/api/v1/admin/suppliers` | Suppliers (`suppliers.view`, `suppliers.edit`) |
| POST | `/api/v1/admin/suppliers/{id}/review` | Approve, block or reopen a supplier (`suppliers.approve`) |
| GET, POST, PATCH | `/api/v1/admin/quotations` | Supplier quotes with landed cost worked out by the server |
| POST | `/api/v1/admin/quotations/{id}/attachment` | The quote PDF or screenshot, kept private |
| GET | `/up` | Laravel's liveness check (not part of the contract) |

## Console commands

| Command | Purpose |
| --- | --- |
| `admin:create {email} --name= --phone= --role=*` | Create an admin. Asks for the password. |
| `admin:update {email} --name= --phone= --role=* --status= --reset-password` | Change an admin. Needed for admins who hold a permission the portal user lacks, such as finance admins. |
| `quotations:expire` | Mark draft and accepted quotes past their validity date (Riyadh time) as expired. Scheduled daily at 00:05 Riyadh. |
| `admin:sync-roles` | Write the roles and permissions in `AdminPermissions` to the database. Run on every deploy. |

The scheduler (`php artisan schedule:work` locally, `schedule:run` every minute in production) prunes expired tokens and old sign-in codes and expires old supplier quotes, daily.

Product photos go to the `public` disk; run `php artisan storage:link` once locally so their URLs work. Supplier quote files go to the private `local` disk. Both move to S3-compatible storage at launch (`MEDIA_DISK`, `DOCUMENTS_DISK`).
