# Tasks — dashboard-public-share

## Tasks

- [ ] Task 1: Ship the migration `lib/Migration/VersionXXXXDateXXXXAddPublicShares.php` creating `oc_mydash_public_shares` (PK `id`, `dashboardUuid` VARCHAR(36), `token` VARCHAR(64) UNIQUE, `passwordHash` VARCHAR(255), `expiresAt`, `createdBy`, `createdAt`, `revokedAt`, `viewCount`, `lastViewedAt`) with composite index `(dashboardUuid, revokedAt)`, FK to `oc_mydash_dashboards.uuid` ON DELETE CASCADE, and a reversible drop path — applied cleanly on sqlite/mysql/postgres
- [ ] Task 2: Add `lib/Db/PublicShare` entity with full getters/setters (no named args), `jsonSerialize()` that strips `passwordHash`, and a computed `url` property using `IURLGenerator::absolute('s/' . $token)`
- [ ] Task 3: Add `lib/Db/PublicShareMapper` (extends `QBMapper`) with `findByToken`, `findByDashboardUuid`, `findActiveByDashboardUuid` (filter revoked + expired), `save`, `delete`, `softRevoke`, `incrementViewCount` (60-second per-IP per-token debounce via APCu/Redis or transaction snapshot)
- [ ] Task 4: Add exception types `ShareNotFoundException`, `ShareExpiredException`, `SharePasswordRequired`, `ShareReadOnlyException`
- [ ] Task 5: Implement `lib/Service/PublicShareService` with `createPublicShare` (owner-or-admin guard, `IHasher::hash` for password, `Util::generateSecureRandom(64)` for token), `listActiveShares`, `revokeShare`, `renderShareContent` (public, validates token + expiry, returns read-only payload), `unlockShare` (public, `IThrottler` 10/hour on `public_share_unlock_{token}_{ip}`)
- [ ] Task 6: Add `lib/Controller/PublicShareController` with 5 endpoints — `POST /api/dashboards/{uuid}/public-share`, `GET /api/dashboards/{uuid}/public-shares`, `DELETE /api/dashboards/{uuid}/public-shares/{id}`, `GET /s/{token}` (`#[PublicPage]`), `POST /s/{token}/unlock` (`#[PublicPage]`) — and register all routes in `appinfo/routes.php` with correct auth attributes
- [ ] Task 7: Add bearer-context detection (token matches `oc_mydash_public_shares.token`) and harden `DashboardService` / `WidgetService` / `PlacementService` mutation paths to throw `ShareReadOnlyException` (HTTP 403) when called from a public-share bearer
- [ ] Task 8: When `renderShareContent()` loads GroupFolder-backed widget content, switch file-read context to the service account (`FolderManagementHandler` impersonation) so anonymous viewers never re-use a user session
- [ ] Task 9: Frontend `src/stores/publicShares.js` with `createShare`, `fetchShares`, `revokeShare` actions plus `unlockedTokens` state; ship `src/views/DashboardPublicShareView.vue` (no login UI, password-unlock modal that persists success in localStorage)
- [ ] Task 10: PHPUnit coverage — mapper (token lookup, active filter, debounce), service (create/unlock/render/expired/revoked), controller (revoke 403 for non-owner, idempotent), guard layer (mutation returns 403 under bearer)
- [ ] Task 11: Playwright coverage — anonymous renders unprotected share; password-protected unlock flow; expired share → 404; revoked share → 404; view-count debounce window (same IP within 60s = 1 increment, 65s apart = 2)
- [ ] Task 12: Quality gates — `composer check:strict`, ESLint+Stylelint clean, SPDX-in-docblock on new PHP, `nl`+`en` i18n for `share_*`/`cannot_modify_public_share`/`unlock_throttled`, OpenAPI/Postman update for the 5 new endpoints, token lookup verified <50ms locally on the indexed query
- [ ] Task 13: Documentation — add the "Sharing dashboards publicly" how-to under `docs/user-guide/` with the API + UI flow and the password/expiry/revoke semantics
- [ ] Task 14: File follow-up issues for deferred work (admin UI for share management, view analytics, token regeneration, email whitelist, hard-delete cleanup job >90d)

## Verification

`openspec validate` exits clean. All public endpoints round-trip via Playwright; `composer check:strict` green.

## Tests (company-wide ADR-009)

PHPUnit + Playwright per Tasks 10–11. Newman/Postman updated for the 5 new public-share endpoints.

## Documentation (company-wide ADR-010)

Per Task 13 — user guide page plus a changelog entry noting the new public-share capability.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for share-related messages enumerated in Task 12.
