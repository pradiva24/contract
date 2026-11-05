# Multi-company rollout — Phase 1

## Branch
`feature/multi-company-contract` (based on `main`).

## Safety
- This is an additive migration. Back up the active database before running it.
- Run against the same database configured in the application's private `.env`.
- Do not commit `.env`, SMTP credentials, database dumps, or uploaded storage assets.
- The migration preserves existing records and assigns current business records to the Bali Tarantula Adventure tenant.
- Existing `super_admin` accounts remain global (`company_id = NULL`); other current admins are assigned to Bali Tarantula Adventure.
- Bali Swing is created as a separate tenant, but no users or records are assigned to it automatically.

## Migration
Run `database/migrations/20261105_001_multi_company_foundation.sql` once in the application's MySQL database. It creates `companies` and `company_settings`, adds nullable `company_id` keys and foreign keys to `admins`, `templates`, `certificates`, and `audit_logs`, backfills the existing tenant, and copies current global settings into the BTA tenant.

The migration is additive and uses conditional column/index/constraint creation for partial reruns. Take a backup first; MySQL DDL may implicitly commit.

## Important existing-schema drift to reconcile before deploying
The checked-in `database/schema.sql` does not match application code:
- `app/certificate.php` expects `certificate_sequences(id, last_number)`, while schema.sql defines `certificate_sequences(issue_date, last_number)`.
- `public/certificate-form.php` reads/writes `certificates.recipient_whatsapp` and `certificates.participant_photo_path`, but those columns are absent from schema.sql.

Do not rebuild a live database from schema.sql. Inspect the active database with `SHOW CREATE TABLE` and reconcile it with a separate migration before treating schema.sql as canonical.

## Next implementation gate
The migration alone does not activate tenant isolation. Before enabling Bali Swing users, application queries and write paths must be scoped by the authenticated admin's company, with explicit global-super-admin handling. Never rely on hidden navigation links as access control.
