# Tasks — dashboard-public-share

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddPublicShares.php` with table `oc_mydash_public_shares` containing all required fields: `id` (auto-increment PK), `dashboardUuid` (VARCHAR 36), `token` (VARCHAR 64 UNIQUE NOT NULL), `passwordHash` (VARCHAR 255 NULL), `expiresAt` (TIMESTAMP NULL), `createdBy` (VARCHAR 64), `createdAt` (TIMESTAMP NOT NULL), `revokedAt` (TIMESTAMP NULL), `viewCount` (INT DEFAULT 0), `lastViewedAt` (TIMESTAMP NULL)
- [ ] 1.2 Add composite index on `(dashboardUuid, revokedAt)` for fast active-share queries (filter where `revokedAt IS NULL`)
- [ ] 1.3 Add foreign key constraint from `dashboardUuid` to `oc_mydash_dashboards.uuid` with ON DELETE CASCADE
- [ ] 1.4 Confirm migration is reversible (drop table in postSchemaChange rollback path)
- [ ] 1.5 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Create `lib/Db/PublicShare.php` Entity with all fields as properties + getters/setters (Entity `__call` pattern — no named args on setters)
- [ ] 2.2 Ensure `PublicShare::jsonSerialize()` excludes `passwordHash` (security — never expose hashed password to API responses)
- [ ] 2.3 Ensure `PublicShare` includes `url` computed property (`https://nextcloud.instance/s/{token}`)

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/PublicShareMapper.php` extending `QBMapper` with methods:
  - `findByToken(string $token): PublicShare` — returns single row or throws `DoesNotExistException`
  - `findByDashboardUuid(string $uuid): array` — all shares (active + revoked) for a dashboard
  - `findActiveByDashboardUuid(string $uuid): array` — `WHERE revokedAt IS NULL AND (expiresAt IS NULL OR expiresAt > now())`
  - `save(PublicShare $share): PublicShare` — insert or update
  - `delete(PublicShare $share): void` — hard-delete (used by cleanup job only)
  - `softRevoke(int $id): void` — `UPDATE ... SET revokedAt = now() WHERE id = ?`
  - `incrementViewCount(int $id, string $ip): void` — check debounce (max once per minute per IP per token), then `UPDATE viewCount, lastViewedAt`
- [ ] 3.2 Debounce logic: store last-seen (token, IP) pair in Redis/APCu (optional) or inline via transaction snapshot; if `lastViewedAt` is within 60 seconds ago, skip increment
- [ ] 3.3 Add fixture-based PHPUnit test covering: active shares, revoked shares (soft-delete), expired shares, debounce logic

## 4. Service layer

- [ ] 4.1 Create `lib/Service/PublicShareService.php` with methods:
  - `createPublicShare(string $dashboardUuid, ?string $password, ?string $expiresAt): PublicShare` — owner-or-admin guard, validate UUID exists, hash password via `IHasher::hash()`, generate token via `Util::generateSecureRandom(64)`, persist, return entity
  - `listActiveShares(string $dashboardUuid): array` — ownership guard, call mapper `findActiveByDashboardUuid()`, return array
  - `revokeShare(int $id): void` — owner-or-admin guard (verify share's dashboard is owned), call mapper `softRevoke()`, return success
  - `renderShareContent(string $token): array` — PUBLIC method (no auth check), validate token exists and not revoked/expired (throw `ShareExpiredException` or `ShareNotFoundException`), load dashboard and placements, return read-only JSON
  - `unlockShare(string $token, string $password): bool` — PUBLIC method, throttle check via `IThrottler` (key format: `public_share_unlock_{token}_{IP}`, allow 10/hour), verify password via `IHasher::verify()`, return bool
- [ ] 4.2 Ownership guard implementation: inject `IUserSession` to get current user, query dashboard to verify `userId` matches OR user is admin
- [ ] 4.3 Add exception classes: `ShareExpiredException`, `ShareNotFoundException`, `SharePasswordRequired`, `ShareReadOnlyException`

## 5. Controller + routes

- [ ] 5.1 Create `lib/Controller/PublicShareController.php` extending `Controller` with methods:
  - `createPublicShare(string $uuid)` — POST /api/dashboards/{uuid}/public-share, parse `password` and `expiresAt` from request body, call service, return 201 with `{token, url, passwordRequired, expiresAt}`
  - `listPublicShares(string $uuid)` — GET /api/dashboards/{uuid}/public-shares, return 200 with array of shares (active only, includes full token and view metrics)
  - `revokePublicShare(string $uuid, int $id)` — DELETE /api/dashboards/{uuid}/public-shares/{id}, call service, return 204
  - `renderPublicShare(string $token)` — GET /s/{token}, check query param `?password=` OR header `X-Share-Password`, call service with password, catch `SharePasswordRequired` and return 401 with `{passwordRequired: true}`, catch `ShareExpiredException|ShareNotFoundException` and return 404, return 200 with dashboard JSON
  - `unlockPublicShare(string $token)` — POST /s/{token}/unlock, parse `password` from request body, call service, return 200 `{access: true}` on match, return 401 `{access: false}` on mismatch, catch throttle exception and return 503 with `Retry-After` header
- [ ] 5.2 Public routes (no `#[NoAdminRequired]` semantic; manual `#[PublicPage]` or equivalent for GET /s/* endpoints)
- [ ] 5.3 Authenticated routes use standard `#[NoAdminRequired]` (user-scoped, not admin-only by attribute; guard inside service)
- [ ] 5.4 Register all 5 routes in `appinfo/routes.php`:
  - `POST /api/dashboards/{uuid}/public-share` — authenticated
  - `GET /api/dashboards/{uuid}/public-shares` — authenticated
  - `DELETE /api/dashboards/{uuid}/public-shares/{id}` — authenticated
  - `GET /s/{token}` — public
  - `POST /s/{token}/unlock` — public
- [ ] 5.5 Verify every route carries correct Nextcloud auth attributes

## 6. Read-only enforcement

- [ ] 6.1 Create middleware or request context detector to identify if the current request is bearer-only a public-share token (compare against `PublicShare` table if token appears in Authorization header)
- [ ] 6.2 Extend `DashboardService` mutations (update, delete, create placements, etc.) with guard: if request is public-share bearer, throw `ShareReadOnlyException` (403)
- [ ] 6.3 Extend `WidgetService`, `PlacementService` mutations similarly
- [ ] 6.4 PHPUnit test: create public share, attempt `POST /api/dashboard/{uuid}/placements` via bearer token, verify 403

## 7. GroupFolder service-account integration

- [ ] 7.1 When `renderShareContent()` loads dashboard placements, check if any widget references GroupFolder-backed content (consult sibling spec for signal — e.g., widget `source` field)
- [ ] 7.2 If GroupFolder content is detected, switch file-read context to service account: inject `FolderManagementHandler` (or equivalent) and set impersonation context
- [ ] 7.3 PHPUnit test (mocked): public share renders GroupFolder-backed widget without leaking underlying user session

## 8. Frontend store

- [ ] 8.1 Create `src/stores/publicShares.js` (Vuex or Pinia) with state: `shares` (map), `unlockedTokens` (set), getters for active shares per dashboard
- [ ] 8.2 Actions: `createShare(uuid, password?, expiresAt?)` → POST call, store token locally
- [ ] 8.3 Actions: `fetchShares(uuid)` → GET call, update state
- [ ] 8.4 Actions: `revokeShare(uuid, id)` → DELETE call, remove from state
- [ ] 8.5 Create `src/views/DashboardPublicShareView.vue` or equivalent — PUBLIC component, no login UI, renders dashboard data (read-only), password unlock modal if needed
- [ ] 8.6 Unlock modal calls `POST /s/{token}/unlock`, caches result in localStorage or session cookie, re-renders on success

## 9. PHPUnit tests

- [ ] 9.1 `PublicShareMapperTest::findByToken` — valid token, invalid token (DoesNotExistException), token case-sensitivity
- [ ] 9.2 `PublicShareMapperTest::findActiveByDashboardUuid` — revoked shares filtered, expired shares filtered (by timestamp comparison), active shares included
- [ ] 9.3 `PublicShareMapperTest::incrementViewCount` — debounce logic: same IP within 60s skips increment, different IP increments, beyond 60s resets debounce
- [ ] 9.4 `PublicShareServiceTest::createPublicShare` — password hashing, token generation, response includes `{token, url, passwordRequired, expiresAt}`
- [ ] 9.5 `PublicShareServiceTest::unlockShare` — correct password verifies, wrong password fails, throttle after 10 failures
- [ ] 9.6 `PublicShareServiceTest::renderShareContent` — expired share throws exception, revoked share throws exception, valid share returns dashboard + placements
- [ ] 9.7 `PublicShareControllerTest::revokeShare` — non-owner returns 403, revoke sets `revokedAt`, idempotent on already-revoked
- [ ] 9.8 `DashboardServiceTest` — mutations guarded: public-share bearer on POST /placements returns 403, same on PUT /dashboard/{uuid}

## 10. End-to-end Playwright tests

- [ ] 10.1 Dashboard owner creates a public share (no password) via API, anonymous user visits `/s/{token}`, dashboard renders read-only
- [ ] 10.2 Dashboard owner creates password-protected share, anonymous user visits `/s/{token}`, receives 401 with `passwordRequired: true`, POSTs unlock with correct password, gets 200 + dashboard
- [ ] 10.3 Dashboard owner creates share with expiry 1 minute in future, token renders successfully, test waits > 1 minute, token returns 404
- [ ] 10.4 Dashboard owner revokes a share, anonymous user visits token, gets 404
- [ ] 10.5 Dashboard owner lists public shares, sees all active shares with correct token, passwordRequired, viewCount, lastViewedAt
- [ ] 10.6 Multiple renders from same IP within 60s increment viewCount once; renders > 60s apart both increment
- [ ] 10.7 View count debounce: user reloads page 5 times in 30s, viewCount increments by 1; waits 65s, reloads again, increments by 1 more

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 11.3 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 11.4 i18n: add message keys for `share_created`, `share_password_required`, `share_expired`, `share_revoked`, `share_view_count`, `cannot_modify_public_share`, `unlock_throttled` in both `nl` and `en` per the i18n requirement
- [ ] 11.5 Update OpenAPI spec / Postman collection with 5 new endpoints
- [ ] 11.6 Test coverage: all 10 `hydra-gates` passing locally before opening PR
- [ ] 11.7 Performance: public share token lookup (via indexed query on unique `token`) must complete in < 50ms locally
- [ ] 11.8 No hardcoded domain in URLs; use `OCP\Server::get(IURLGenerator::class)->absolute('s/' . $token)` for URL generation

## 12. Documentation

- [ ] 12.1 Add public-share workflow to `openspec/specs/dashboards/spec.md` (if deemed necessary, or leave as separate capability spec)
- [ ] 12.2 Add API documentation example (request/response JSON) in `docs/` directory or OpenAPI spec
- [ ] 12.3 Add "Sharing dashboards publicly" how-to guide in user-facing docs

## 13. Optional follow-up changes (deferred)

- [ ] 13.1 Admin UI for managing shares from dashboard settings (create, revoke via form instead of API)
- [ ] 13.2 Share analytics dashboard (chart of views over time per share)
- [ ] 13.3 Token regeneration (revoke old, create new with same settings)
- [ ] 13.4 Whitelist-by-email feature (share only with specific email-confirmed users)
- [ ] 13.5 Database cleanup job to hard-delete revoked shares older than 90 days
