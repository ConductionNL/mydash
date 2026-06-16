# Dashboard Public Share

## Why

Dashboard owners today cannot share a read-only view of their dashboards with external stakeholders without giving them Nextcloud login access. The existing `dashboard-sharing` capability is user-to-user (Nextcloud users must be logged in). This change introduces anonymous public sharing via URL-safe tokens, with optional password protection, expiry, and view tracking, enabling organisations to expose dashboards to partners, clients, and the public without account provisioning.

## What Changes

- Add `oc_mydash_public_shares` table with `token` (UNIQUE), `passwordHash` (BCrypt), `expiresAt`, `revokedAt` (soft-delete), `viewCount`, and `lastViewedAt` columns
- Add `POST /api/dashboards/{uuid}/public-share` (owner-or-admin) to create a share with optional password and expiry; returns `{token, url, passwordRequired, expiresAt}`
- Add `GET /api/dashboards/{uuid}/public-shares` (owner-or-admin) to list active (non-revoked) shares for a dashboard
- Add `DELETE /api/dashboards/{uuid}/public-shares/{id}` (owner-or-admin) to soft-revoke a share
- Add `GET /s/{token}` (PUBLIC) to render the dashboard anonymously; requires password unlock if `passwordHash IS NOT NULL`; returns HTTP 401 with `passwordRequired: true` if locked
- Add `POST /s/{token}/unlock` (PUBLIC) to verify password and return `{access: true/false}`; throttled via `IThrottler` (10 failed per hour per IP → HTTP 503)
- All mutation endpoints (`POST /api/dashboards/*/placements`, `PUT /api/dashboard/{uuid}`, etc.) detect public-share bearer and return HTTP 403 with "Cannot modify dashboard via public share"
- View-count increments debounced per (token, IP, minute) to prevent refresh-spam inflation
- Token expiry (`expiresAt < now()`) or revocation (`revokedAt IS NOT NULL`) returns HTTP 404 (no leak of existence)
- Public-share rendering of GroupFolder-backed dashboard content MUST use a service-account read path (not the viewer's session) per the sibling `groupfolder-storage-backend` spec

## Capabilities

### New Capabilities

- `dashboard-public-share` — enables anonymous read-only rendering via password-protected and/or time-limited tokens

### Modified Capabilities

- `dashboards` — existing REQ-DASH-001..010 unchanged; no mutation endpoint impact from this spec

The existing `dashboard-sharing` capability (user-to-user) is unchanged; this is a distinct surface for public sharing.

## Impact

**Affected code:**

- `lib/Db/PublicShare.php` — new Entity for `oc_mydash_public_shares` with fields: `id`, `dashboardUuid`, `token`, `passwordHash`, `expiresAt`, `createdBy`, `createdAt`, `revokedAt`, `viewCount`, `lastViewedAt`
- `lib/Db/PublicShareMapper.php` — new Mapper with methods: `findByToken(string $token)`, `findByDashboardUuid(string $uuid)`, `findActiveByDashboardUuid(string $uuid)`, `save()`, `delete()`, `softRevoke(int $id)`, `incrementViewCount(int $id, string $ip)` (with debounce logic)
- `lib/Service/PublicShareService.php` — new Service with: `createPublicShare(string $uuid, ?string $password, ?string $expiresAt)` (owner-or-admin guard), `listActiveShares(string $uuid)`, `revokeShare(int $id)`, `renderShare(string $token, ?string $password)` (with expiry/revoke checks), `unlockShare(string $token, string $password)` (with throttle guard via `IThrottler`)
- `lib/Controller/PublicShareController.php` — new Controller (partially public, no `#[NoAdminRequired]` on all methods since some require auth):
  - `POST /api/dashboards/{uuid}/public-share` — owner-or-admin
  - `GET /api/dashboards/{uuid}/public-shares` — owner-or-admin
  - `DELETE /api/dashboards/{uuid}/public-shares/{id}` — owner-or-admin
  - `GET /s/{token}` — PUBLIC (no login required)
  - `POST /s/{token}/unlock` — PUBLIC
- `lib/Service/DashboardService.php` — extend with guard logic for mutations: check if request is public-share bearer (via context/middleware), throw `PublicShareReadOnlyException` (403) if mutating
- `appinfo/routes.php` — register 5 new routes (3 authenticated, 2 public)
- `lib/Migration/VersionXXXXDate2026...AddPublicShares.php` — schema migration adding `oc_mydash_public_shares` table with unique index on `token` and composite index on `(dashboardUuid, revokedAt)` for fast active-share queries
- `src/stores/publicShares.js` — new Vuex/Pinia store module tracking active shares and token state
- `src/views/DashboardPublicView.vue` — new public share render page (read-only, password unlock modal if needed)
- `src/views/ShareManagement.vue` — new UI in dashboard settings to create, list, and revoke shares (deferred to follow-up if preferred)

**Affected APIs:**

- 5 new routes (3 authenticated, 2 public)
- Existing `GET /api/dashboards` and mutation endpoints unchanged (but mutations now guard against public-share bearer)

**Dependencies:**

- `OCP\Security\IHasher` — already available, used for BCrypt password hashing
- `OCP\Util::generateSecureRandom(64)` — for token generation
- `OCP\Util\IThrottler` — already available, used for unlock attempt throttling
- No new composer or npm dependencies

**Migration:**

- Zero-impact: new table only. No existing data affected.
- Optional: add a database cleanup job to periodically hard-delete (not just soft-revoke) shares with `revokedAt IS NOT NULL AND revokedAt < (now - 90 days)` to reclaim storage

**GroupFolder Integration:**

Per the sibling `groupfolder-storage-backend` spec, when a dashboard's widgets reference GroupFolder-backed content, the public-share render MUST use a service-account file-read path instead of the anonymous user's non-existent session. The implementation detail is in the GroupFolder spec's `## Impact` section; this spec assumes that service-account mechanism is available via a helper method or DI.

## Standards & References

- Nextcloud security best practices: BCrypt with cost factor ≥ 10 for all password hashing
- UUID v4 for dashboard foreign keys (existing `DashboardFactory::generateUuid()` pattern)
- WCAG 2.1 AA: public-share render page must be operable via keyboard
- i18n: all error messages in both `nl` and `en` per the i18n requirement
