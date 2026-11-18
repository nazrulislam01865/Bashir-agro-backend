# Amader Khamar — Phase 2 implementation

## Scope delivered in source

- `durable_queue_store.dart`: persistent support-dir photo staging, atomic JSON journal + matching secondary snapshot, SHA-256 integrity verification, legacy queue backup and quarantine, no auto-delete.
- `offline_sync_service.dart` and `submission_model.dart`: stable UUID v4 keys, attempt count/retry scheduling, single-flight sync, account match enforcement, receipt-confirmed success, no 400/404/409 false completion, explicit needs-attention and non-destructive error handling.
- `api_service.dart`, `api_client.dart`: idempotency header and authenticated receipt lookup. No extra queries for unrelated API endpoints.
- `image_compression_service.dart`: real camera JPEG/PNG decoding and reduction in a worker isolate, minimum-size validation; original queue evidence stays untouched.
- Photo review and dashboard: distinguish local pending vs server accepted, capture retryable 408/429/5xx/network errors, retain same key after uncertain response, show pending/attention counts and manual retry.
- Flutter tests: queue recreation, valid original photo after temp deletion, receipt replay safety, corrupted journal recovery, v1 migration, 409 rejection, server 503 backoff, concurrent single-flight, image validity.

## Safety invariants

- A local queue write is not acknowledged until journal and backup are flushed and renamed.
- No simulated file is generated for a missing/corrupt image. Original evidence is not destroyed during compression or sync.
- `synced` requires a stable receipt matching the queued idempotency key and assignment ID. Any unknown/failed response keeps evidence.
- v1 items have no trustworthy owner binding and are quarantined. They must not silently auto-upload under a new login.
- No unattended queue deletion or rollback migration. Retention and private-media policies remain a later controlled release decision.

## Verification status

Source edits and PHP syntax/static structural checks were completed in this workspace. The Flutter/Dart SDK, Composer vendor packages, production-like staging API credentials and a physical Android device were unavailable here. Therefore the added Flutter tests, Laravel feature tests, actual DB migration/restore and signed build **must be run by QA** before release. Full pass has not been claimed.
