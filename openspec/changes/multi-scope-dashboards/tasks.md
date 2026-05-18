# Tasks — multi-scope-dashboards

## Tasks

- [ ] Task 1: Ship the migration adding `groupId VARCHAR(64) NULL` to `oc_mydash_dashboards` plus composite index `idx_mydash_dash_type_group(type, groupId)`; reversible drop in `postSchemaChange` rollback; applied cleanly on sqlite/mysql/postgres
- [ ] Task 2: Extend `Dashboard` entity with `TYPE_GROUP_SHARED` + `SOURCE_USER|SOURCE_GROUP|SOURCE_DEFAULT` constants, `groupId` getter/setter (no named args), and `jsonSerialize()` exposing `groupId` (nullable)
- [ ] Task 3: Add `DashboardMapper::findByGroup(groupId)` and `DashboardMapper::findVisibleToUser(userId, userGroupIds)` (3 indexed queries — personal/group/default — unioned + deduped by UUID, each row tagged with its `source`)
- [ ] Task 4: Enforce the `(type='group_shared' XOR groupId IS NULL)` invariant in `DashboardFactory::create()` with `\InvalidArgumentException` on mismatch
- [ ] Task 5: Add `DashboardService::createGroupShared / updateGroupShared / deleteGroupShared / getVisibleToUser` with admin guards via `IGroupManager::isAdmin`, ownership checks, and last-in-non-default-group delete guard (HTTP 400; `default` group exempt)
- [ ] Task 6: Update `PermissionService::getEffectivePermissionLevel()` so non-admins on `group_shared` get `view_only` and admins get `full`; personal + admin_template scopes keep their current matrix
- [ ] Task 7: Add 6 controller endpoints (`GET /api/dashboards/visible`, `GET /api/dashboards/group/{groupId}`, `POST /api/dashboards/group/{groupId}`, `GET|PUT|DELETE /api/dashboards/group/{groupId}/{uuid}`) registered in `appinfo/routes.php` with `#[NoAdminRequired]` + in-body admin checks on mutations; `groupId` regex accepts `default` + any valid NC group id
- [ ] Task 8: Seed three group-shared dashboards (Welcome→`default`, Campaigns→marketing, Sprint→engineering) with their placements in `_registers.json`; verify the local seed command applies them cleanly
- [ ] Task 9: Update `src/stores/dashboards.js` to consume `/api/dashboards/visible`, expose `groupSharedDashboards` + `defaultGroupDashboards` getters, and route subsequent edits via the `source` field (personal vs group)
- [ ] Task 10: PHPUnit — mapper coverage (findByGroup empty/nonexistent, findVisibleToUser mixed fixtures + 0-group user + UUID-overlap dedup), controller admin enforcement (403 on mutation by non-admin), last-in-group guard (400, default exempt), invariant guard, permission matrix (incl. regression on personal + admin_template)
- [ ] Task 11: Playwright — admin creates group-shared via API and member sees it on `/visible`; 0-group user still sees default-group rows; non-admin PUT to group-shared dashboard returns 403; admin rename propagates to members on next reload
- [ ] Task 12: Quality gates — `composer check:strict`, ESLint+Stylelint, OpenAPI/Postman regen, `nl`+`en` i18n for new error strings, SPDX-in-docblock on new PHP, all 10 hydra-gates green
- [ ] Task 13: File the follow-up `admin-group-management` change for the admin-facing group-shared CRUD UI and note the deferral in the changelog

## Verification

`openspec validate` exits clean. `/api/dashboards/visible` returns the correct merged + deduped set with `source` tags; admin-only mutation routes return 403 for non-admins.

## Tests (company-wide ADR-009)

PHPUnit per Task 10; Playwright per Task 11. Newman/Postman updated with the 6 new endpoints (Task 12).

## Documentation (company-wide ADR-010)

Changelog entry covering the new scope (group-shared dashboards), the `default` group convention, and the deferred admin UI follow-up.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for the new error messages (e.g. `Cannot delete the only dashboard in the group`).
