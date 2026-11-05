-- The two database roles (docs/runbook.md, Database roles). Run once per
-- database, as a user that may create roles, connected to the app's database:
--
--   GROUPBUY_OWNER_DB_PASSWORD=... GROUPBUY_APP_DB_PASSWORD=... \
--     psql "postgresql://admin@host:5432/groupbuy?sslmode=require" -f deploy/postgres/roles.sql
--
-- With compose's local-data profile the postgres image runs it on first start,
-- with the passwords from env/postgres.env.
--
-- groupbuy_owner owns the database and the schema and runs migrations
-- (env/api-owner.env). groupbuy_app is what the API connects as day to day
-- (env/api.env): after each migration, php artisan db:grant-app-role
-- groupbuy_app gives it every table, but only SELECT and INSERT on history
-- tables, so not even a bug or a stolen app password can rewrite the audit
-- log or the ledger.

\set ON_ERROR_STOP on
\getenv owner_password GROUPBUY_OWNER_DB_PASSWORD
\getenv app_password GROUPBUY_APP_DB_PASSWORD

SELECT format('CREATE ROLE groupbuy_owner LOGIN PASSWORD %L', :'owner_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'groupbuy_owner') \gexec

SELECT format('CREATE ROLE groupbuy_app LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'groupbuy_app') \gexec

-- Managed PostgreSQL: the admin user must be able to hand objects to the owner.
SELECT format('GRANT groupbuy_owner TO %I', current_user)
WHERE NOT pg_has_role(current_user, 'groupbuy_owner', 'MEMBER') \gexec

SELECT format('ALTER DATABASE %I OWNER TO groupbuy_owner', current_database()) \gexec
ALTER SCHEMA public OWNER TO groupbuy_owner;
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO groupbuy_app', current_database()) \gexec
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO groupbuy_app;
