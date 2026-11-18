# Amader Khamar - Phases 3 and 4 implementation and staging handoff

Date: 2026-11-18 | Source: supplied Flutter `Archive(4).zip`, Laravel `Archive(3).zip`.

## Release decision

**Source-level fixes prepared. Not yet staging approved or published.** `flutter`/`dart` and Laravel `vendor/` are unavailable in the packaging environment, so runtime test counts are **unverified**, and no live HTTPS endpoint, privacy page, Mapbox account or staging database was accessed.

## Phase 3 - 06, 07, 09, 12, 14

- **06 - Token migration:** `flutter_secure_storage` Keychain/Keystore (Android encrypted-store upgrade uses crash-recovery backup); legacy SharedPreferences bearer token is verified in secure storage before deletion; migration failure forces login instead of plaintext fallback. Cache and Phase 2 durable evidence are preserved. New installs disable Android auto-backup to avoid credential/storage restore inconsistencies.
- **07 - Network:** release requires `--dart-define=API_BASE_URL=https://.../api/v1` and refuses non-HTTPS requests. Redirects are not followed (including multipart), preventing bearer/header leakage to another host. Debug emulator may use HTTP via debug-only manifest.
- **09 - Privacy:** link added before login and in user profile via `PRIVACY_POLICY_URL` compile-time setting. Laravel public `/privacy-policy` route and Bengali draft supplied. **Publication, truthful disclosures, operator contact, deletion and retention commitments still need product/legal review and deployment.** The package cannot publish a public website on its own.
- **12 - Android:** main manifest `usesCleartextTraffic=false`, `allowBackup=false`; unused Bluetooth requests removed; microphone, camera and fine/coarse GPS retained for existing functions. Inspect the merged *release* manifest, not only this XML.
- **14 - Logout:** revoke current token while authenticated, then remove secure credentials and user session. If revocation is unconfirmed, show message after navigating to login. Do not wipe pending queue/media. Farmer accounts cannot log into admin web portal. Owner/admin role still allowed.

## Phase 4 - 08, 16

- **08 - Maps:** Flutter and four Laravel admin map views no longer request undocumented `mt*.google.com` raster URLs nor public OpenStreetMap tile endpoint as a silent fallback. Mapbox token must be configured (`pk.` public token); satellite/street styles and polygon overlays are retained. No token => boundary/UI remains but base map unavailable with notice. Map attribution links are wired. **Official logo artwork, license verification and real device/browser attribution checks still pending.** Never put a Mapbox secret token in a client or compiled application.
- **16 - Authorization:** Laravel farmer/owner API scopes, assignment/farm reads, submission rights, farmer directory and owner dashboard are guarded; admin web routes also use admin-role middleware. Existing farmer farm creation/edit workflow preserved with owner restrictions.
- **16 - Geofence:** authoritative Laravel geofence check now fails verification without farm coordinates; existing outside-fence workflow still flags submission instead of silently rejecting or approving it. Client-supplied override requires admin authorization; actual field-photo location is not blindly trusted.
- **16 - Uploads:** server validates MIME, JPEG/PNG/WebP, max 10 MB per file, min 100x100 dimensions, max 10 files, GPS accuracy positive, assignment ownership. New photo evidence saved to the private `local` disk; temporary signed 15-minute URLs display evidence through `/evidence/{photo}`. Phase 2 idempotent submission receipt and queue preservation are retained.
- **16 - Monitoring:** existing Phase 2 receipt logging, state handling and audit paths retained. No polling/query-heavy monitoring or unrelated database migrations added.

## Staging configuration (DO NOT check secrets into version control)

Laravel `.env` must include a real public HTTPS host, valid support address and Mapbox public token:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.YOUR-DOMAIN
MAIL_FROM_ADDRESS=support@YOUR-DOMAIN
MAPBOX_PUBLIC_TOKEN=pk.RESTRICTED_PUBLIC_TOKEN
```

Set a true HTTPS certificate and standard Laravel config values. Restrict Mapbox public token to permitted web origins and appropriate scopes, budgets, usage alerts. Keep any private Mapbox token server-only and never transmit it in web HTML.

Flutter release/staging build:

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release \
  --dart-define=API_BASE_URL=https://staging.YOUR-DOMAIN/api/v1 \
  --dart-define=PRIVACY_POLICY_URL=https://staging.YOUR-DOMAIN/privacy-policy \
  --dart-define=MAPBOX_ACCESS_TOKEN=pk.RESTRICTED_PUBLIC_TOKEN
```

Before production, use the verified **production** host and approved privacy policy URL. `pubspec.lock` needs regeneration by `flutter pub get` because this environment had no Flutter SDK. Commit regenerated lockfile after QA. Verify effective Android minSdk >=23 for flutter_secure_storage v10; Android upload signing and Play identity are **Phase 5**, not altered here.

Laravel staging test/boot (use test-only database; never point PHPUnit to the live DB):

```bash
composer install --no-interaction
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=Phase34SecurityRegressionTest
php artisan test --filter=SubmissionIdempotencyTest
php artisan test --filter=SubmissionGeofenceApiTest
php artisan test
php artisan route:list --path=api/v1
php artisan route:list --path=privacy-policy
php artisan route:list --path=evidence
```

`migrate:status` is read-only; no new Laravel schema migration is introduced by Phase 3 or 4. Existing Phase 2 receipt migration must already be applied. Do **not** run `migrate:fresh` or wipe local offline submissions.

## QA gates before staging approval

| Gate | Expected result |
| --- | --- |
| S01 new login / reboot | Token only in encrypted secure store; verified session restored |
| S02 upgrade old client | Legacy plaintext token migrated and removed; pending evidence intact |
| S03 Keystore unavailable | Fail closed to login; no plaintext fallback |
| S04 authenticated logout | Current Sanctum token revoked first; old token receives 401 |
| S05 offline logout | Credentials cleared, user sees revocation uncertainty; media queue remains |
| S06 redirects/HTTP | Release refuses plaintext and does not follow untrusted redirects |
| S07 Android permissions | No Bluetooth prompts; camera/location/mic requested only with relevant feature |
| P09 public policy | Live URL HTTPS 200 without login; operator-approved disclosures and support contact |
| M01 missing Mapbox token | No unlicensed tiles requested; UI shows unavailable state |
| M02 licensed maps | Satellite/street map, polygon, attribution/official logo, quota and key restrictions verified |
| B01 unauthorized farmer | Cannot see other farmer’s assignment/farm, farmer directory, owner KPI or admin portal |
| B02 geofence | Missing farm center not passed; outside-fence flagged as before; unauthorized override denied |
| B03 photo integrity | 1x1/corrupt/oversized/wrong MIME rejected; valid photo accepted |
| B04 private media | Only signed unexpired URL works; no new evidence in public storage |
| B05 Phase 2 regression | No duplicate submissions, no queue deletion, no success without receipt |
| B06 owner journey | Authorized owner can see original admin panels, assigned photos and task progress |

## Rollback / compatibility

1. Back up Laravel application, database and both `storage/app/private` / `storage/app/public` (actual paths depend on disk config) before deploying. **Do not delete evidence.**
2. Staging-only rollout: deploy Laravel first with private evidence serving, then install updated Flutter on test devices; test legacy and new evidence links.
3. Secure-token migration forces login on Keystore failure; no fallback to plaintext token. Keep the Phase 2 durable journal and its backup intact.
4. Reverting Laravel to Phase 2 while Phase 4 private files already exist can break viewing those new files. **Preserve the signed photo route/media controller until old and new media have both been validated**, or deploy a tested forward-fix; do not bulk move files or switch disks casually.
5. Existing legacy images on `public` disk remain publicly accessible from old URLs. They are not retroactively private. Plan a separate audited migration with URL inventory and verification before retiring public URLs/symlinks.
6. Keep the approved Mapbox provider in a configurable token. Missing token must fail clearly, never activate undocumented alternate tiles.
7. In production, an expired 15-minute evidence URL requires refreshing the page/API response. Confirm CDN/proxy cache headers respect `private, no-store` and URL expiration.

## Honest remaining blockers

- Flutter compilation/widget tests and generated dependency lock are not executed yet.
- Laravel Feature tests and staging restoration are not executed yet (no project `vendor/` in provided archive).
- Privacy-policy content and `Data safety` inventory must be approved by the actual operator; page must be publicly served from a real HTTPS domain.
- Mapbox provider billing, token restrictions, logo/attribution behavior and actual browser/device tiles are not verified.
- Existing public legacy evidence requires separate audited remediation if retrospective privacy is desired.
- Previous Phase 2 idempotency test was still being debugged in chat, and its green result has not been provided. It is a hard prerequisite for final release approval.
- Phase 5 Android signing/API targeting and Phase 6 device/Play checks are intentionally out of scope.
