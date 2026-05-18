# Tasks — default-dashboard-flag

## Tasks

- [ ] Task 1: Confirm `isDefault SMALLINT` already exists on the `Dashboard` entity (no migration) and ensure `jsonSerialize()` emits it as 0|1 — add to the serialiser if missing
- [ ] Task 2: Add `DashboardMapper::clearGroupDefaults($groupId, ?$exceptUuid = null): int` — `UPDATE oc_mydash_dashboards SET isDefault = 0 WHERE type = 'group_shared' AND groupId = ? AND (uuid <> ? OR ? IS NULL)`, returns affected row count
- [ ] Task 3: Add `DashboardMapper::setGroupDefaultUuid($groupId, $uuid): int` — `UPDATE ... SET isDefault = 1 WHERE type='group_shared' AND groupId=? AND uuid=?`, returns 0 when the uuid isn't in the group
- [ ] Task 4: Add `DashboardService::setGroupDefault($groupId, $uuid): void` — admin-only via `IGroupManager::isAdmin`; both mapper calls wrapped in `IDBConnection::beginTransaction()` / `commit()` / `rollBack()`; if `setGroupDefaultUuid` returns 0 throw the HTTP-404 not-found exception and roll back so the existing default is preserved
- [ ] Task 5: Defense-in-depth — `DashboardService::saveGroupShared` and `updateGroupShared` strip any `isDefault` field from incoming payload/patch before persistence (REQ-DASH-017)
- [ ] Task 6: Add `DashboardController::setGroupDefault($groupId)` reading `uuid` from body, mapped to `POST /api/dashboards/group/{groupId}/default`; `#[NoAdminRequired]` + in-body `IGroupManager::isAdmin` check returning 403 on failure; service-layer not-found surfaces as 404; route registered in `appinfo/routes.php` with the same `{groupId}` regex as `multi-scope-dashboards`
- [ ] Task 7: Frontend — add "Set Default" action button to the admin dashboard list row (visible only when `dash.isDefault === 0` AND current user is admin) and a "Default" badge where `isDefault === 1`
- [ ] Task 8: Frontend — optimistic store update on click flips target to `isDefault=1` and all other dashboards in the same `groupId` to 0, calls the API, rolls back both flips on 4xx/5xx, surfaces 403/404 toasts via existing i18n keys
- [ ] Task 9: PHPUnit service coverage — `setGroupDefault` flips others off; cross-group uuid throws not-found and preserves the source-group default; transaction rolls back when the second UPDATE fails
- [ ] Task 10: PHPUnit controller coverage — non-admin gets 403; POST with `isDefault: 1` in body still persists `isDefault=0`; PUT with `isDefault` in patch does not mutate the flag in either direction (REQ-DASH-017)
- [ ] Task 11: Playwright — admin "Set Default" badge moves optimistically and persists on reload; non-admins do not see the button on group-shared rows; two-tab same-admin scenario shows only one badge after reload
- [ ] Task 12: Quality gates — `composer check:strict`, ESLint+Stylelint, OpenAPI/Postman regen for the new endpoint, `nl`+`en` i18n for the new error messages + "Default" badge + "Set Default" label, SPDX-in-docblock on new PHP, all 10 hydra-gates green

## Verification

`openspec validate` exits clean. Setting the default is transactional + admin-only and cross-group/payload-smuggling vectors are closed.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 9–10; Playwright per Task 11. Newman/Postman updated for the new endpoint.

## Documentation (company-wide ADR-010)

Changelog entry covering the new default-flag transaction semantics and the admin UI affordance.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 12.
