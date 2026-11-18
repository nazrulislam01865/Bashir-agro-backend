# Phase 2 — Laravel staging / deployment / rollback

**Scope:** idempotency for `POST /api/v1/assignments/{assignment}/submit`, and authenticated receipt lookup. No changes to web/admin views, assignment status policy, geofence algorithm, or legacy unauthenticated workflows.

## Deployment order (backend first)

1. In an isolated STAGING environment, back up the actual PostgreSQL database and uploaded files. Store backups **outside** the web root, encryption/access controlled. Verify restore on a separate, disposable database; never run a destructive restore on production.
2. Deploy Laravel source (preserve the server's own `.env`, existing storage and `vendor/`, do not copy the example database into production).
3. Install dependencies with the project's locked Composer dependencies, then run `php artisan migrate:status` and `php artisan migrate --path=database/migrations/2026_11_18_000001_create_submission_receipts_table.php --force` on staging.
4. Run `php artisan test --filter=SubmissionIdempotencyTest` and existing `SubmissionGeofenceApiTest` / `FullApiSuiteTest` on the staging codebase against an isolated test database. Verify assignment authorization and image validation independently.
5. Confirm `POST /api/v1/assignments/{id}/submit` accepts `Idempotency-Key` and returns `receipt.receipt_id`, `receipt.idempotency_key`, `receipt.assignment_id`, `receipt.submission_id`; test the new `GET /api/v1/assignments/{id}/submission-receipts/{uuid}` endpoint under the same authenticated user.
6. Deploy the Flutter build only after the staging/backend endpoint passes. Older app clients without the header retain their existing server response semantics.

## Real restore rehearsal (staging clone only)

Use your existing backup tooling to take a consistent snapshot of both DB and uploaded media before migration. For PostgreSQL an example is:

```bash
# Replace placeholders and run from a secured staging administrator environment.
pg_dump --format=custom --no-owner --file=/private/backups/amader_before_phase2.dump "$STAGING_DATABASE_URL"
# DBA: create a separate EMPTY disposable database, not the existing staging DB.
pg_restore --no-owner --dbname="$DISPOSABLE_RESTORE_DATABASE_URL" /private/backups/amader_before_phase2.dump
```

Verify table counts and critical files in the restored clone, then discard the clone. Staging credentials and network access were not supplied in this archive; this restore rehearsal is **not marked passed** yet.

## Rollback constraints

- Roll back Flutter binaries/code without deleting app-private `submission_queue_v2` evidence or queued JSON. Never call `clearQueue` to recover from an error.
- **Do not roll back the new receipt migration on a live deployment.** Receipt rows provide exactly-once replay protection and may already be referenced by installed mobile clients; retain table/data during application rollback.
- Resume with a fixed forward migration/release. If server receipts are no longer available, hold queued submissions as needing attention rather than blindly assuming a prior request failed.
- Do not run `migrate:fresh`, `migrate:reset`, or `db:wipe` against staging/production. Do not overwrite production media or `.env`.

## Monitoring signals

The existing log channel emits `submission.receipt_created` for new keyed commits and `submission.idempotency_conflict` for mismatched key/payload (assignment/user ID only; no GPS, photos, phone or tokens). The `submission_receipts` unique index prevents duplicate key commits under concurrent workers. Mobile dashboard reports pending and needs-attention counts; syncing records remain on-device until approved retention/cleanup policy is implemented. Alert on sustained needs-attention growth, 409 increases, API 5xx/429 spikes and missing receipts.
