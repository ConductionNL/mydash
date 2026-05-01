# Tasks — default-dashboard-flag

## 1. Domain model

- [ ] 1.1 Confirm `isDefault SMALLINT` already exists on the `Dashboard` entity from the original `admin-templates` change (no schema migration needed)
- [ ] 1.2 Confirm `Dashboard::jsonSerialize()` already emits `isDefault` as an integer (0|1) — add to serialiser if missing

## 2. Mapper layer

- [ ] 2.1 Add `DashboardMapper::clearGroupDefaults(string $groupId, ?string $exceptUuid = null): int` — issues `UPDATE oc_mydash_dashboards SET isDefault = 0 WHERE type = 'group_shared' AND groupId = ? AND (uuid <> ? OR ? IS NULL)` and returns the row-count affected
- [ ] 2.2 Add `DashboardMapper::setGroupDefaultUuid(string $groupId, string $uuid): int` — issues `UPDATE oc_mydash_dashboards SET isDefault = 1 WHERE type = 'group_shared' AND groupId = ? AND uuid = ?` and returns the row-count affected (0 if uuid not in group)

## 3. Service layer

- [ ] 3.1 Add `DashboardService::setGroupDefault(string $groupId, string $uuid): void` — admin-only via `IGroupManager::isAdmin($currentUserId)` guard; wraps both mapper calls in a single `IDBConnection::beginTransaction()` / `commit()` / `rollBack()` block
- [ ] 3.2 If `setGroupDefaultUuid` returns 0 (uuid not in group), throw the not-found exception that maps to HTTP 404 — DO NOT clear other defaults in that case (the transaction MUST roll back so the existing default is preserved)
- [ ] 3.3 Update `DashboardService::saveGroupShared` to drop any `isDefault` field from the incoming payload before persistence (defense-in-depth against payload smuggling)
- [ ] 3.4 Update `DashboardService::updateGroupShared` to drop any `isDefault` field from the patch before applying it (REQ-DASH-017)

## 4. Controller + routes

- [ ] 4.1 Add `DashboardController::setGroupDefault(string $groupId)` accepting `uuid` from the request body, mapped to `POST /api/dashboards/group/{groupId}/default`
- [ ] 4.2 Annotate the new method with `#[NoAdminRequired]` and perform the runtime admin check inside the body via `IGroupManager::isAdmin($currentUserId)` (matches the pattern used by `updateGroup`/`deleteGroup` in `multi-scope-dashboards`); HTTP 403 on failure
- [ ] 4.3 Reject when target uuid does not belong to the given groupId — HTTP 404 (delegated to the service-layer exception from task 3.2)
- [ ] 4.4 Register the new route in `appinfo/routes.php` with the same `{groupId}` regex requirement used by the `multi-scope-dashboards` group routes
- [ ] 4.5 Confirm the new method passes `gate-route-auth` and `gate-semantic-auth`

## 5. Frontend

- [ ] 5.1 Add "Set Default" action button to the admin dashboard list row — only visible when `dash.isDefault === 0` and the current user is an admin
- [ ] 5.2 Add a "Default" badge to the row where `isDefault === 1`
- [ ] 5.3 Implement optimistic store update on click — set `isDefault=1` on the target and `isDefault=0` on all other dashboards in the same `groupId` immediately, then call the API; rollback both flips on 4xx/5xx
- [ ] 5.4 Surface 403 / 404 error toasts using existing i18n keys

## 6. PHPUnit tests

- [ ] 6.1 `DashboardServiceTest::testSetGroupDefaultFlipsOthersOff` — three dashboards in a group, one is default, calling setGroupDefault on a different one moves the flag and clears the previous default
- [ ] 6.2 `DashboardServiceTest::testSetGroupDefaultRejectsCrossGroupUuid` — uuid belongs to group A, called against group B path → throws not-found and existing default in group A is preserved
- [ ] 6.3 `DashboardServiceTest::testSetGroupDefaultIsTransactional` — simulate failure between the two UPDATE calls and assert rollback restores the previous default
- [ ] 6.4 `DashboardControllerTest::testSetGroupDefaultRejectsNonAdmin` — HTTP 403 for non-admin caller
- [ ] 6.5 `DashboardControllerTest::testCreateGroupSharedIgnoresIsDefaultInBody` — POST with `isDefault: 1` in body still results in `isDefault = 0`
- [ ] 6.6 `DashboardControllerTest::testUpdateGroupSharedDoesNotMutateIsDefault` — PUT with `isDefault: 0` on a default dashboard leaves the flag at 1; PUT with `isDefault: 1` on a non-default dashboard leaves it at 0

## 7. End-to-end Playwright tests

- [ ] 7.1 Admin clicks "Set Default" on a non-default group-shared dashboard row — badge moves to that row immediately (optimistic) and persists on reload
- [ ] 7.2 Non-admin user does not see the "Set Default" button on group-shared dashboard rows
- [ ] 7.3 Two browser tabs as the same admin: clicking "Set Default" in tab A is reflected in tab B on next reload (no two badges)

## 8. Quality gates

- [ ] 8.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 8.2 ESLint + Stylelint clean on touched Vue/JS files
- [ ] 8.3 Update generated OpenAPI spec / Postman collection to include `POST /api/dashboards/group/{groupId}/default`
- [ ] 8.4 i18n keys for new error messages and the "Default" badge / "Set Default" button label exist in both `nl` and `en`
- [ ] 8.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — `gate-spdx` must pass
- [ ] 8.6 Run all 10 `hydra-gates` locally before opening PR
