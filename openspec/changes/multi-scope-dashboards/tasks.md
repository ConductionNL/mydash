# Tasks — multi-scope-dashboards

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddGroupIdColumn.php` adding `groupId VARCHAR(64) NULL` to `oc_mydash_dashboards`
- [ ] 1.2 Same migration adds composite index `idx_mydash_dash_type_group` on `(type, groupId)` for fast `findByGroup` and `findVisibleToUser` lookups
- [ ] 1.3 Confirm migration is reversible (drop column + index in `postSchemaChange` rollback path)
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Add `Dashboard::TYPE_GROUP_SHARED = 'group_shared'` constant alongside existing `TYPE_USER`, `TYPE_ADMIN_TEMPLATE`
- [ ] 2.2 Add `groupId` field to `Dashboard` entity with getter/setter (Entity `__call` pattern — no named args)
- [ ] 2.3 Add `Dashboard::SOURCE_USER`, `SOURCE_GROUP`, `SOURCE_DEFAULT` constants (used only in `/visible` serialisation)
- [ ] 2.4 Update `Dashboard::jsonSerialize()` to include `groupId` (nullable in output)

## 3. Mapper layer

- [ ] 3.1 Add `DashboardMapper::findByGroup(string $groupId): array` — `WHERE type = 'group_shared' AND groupId = ?`
- [ ] 3.2 Add `DashboardMapper::findVisibleToUser(string $userId, array $userGroupIds): array` — issues 3 indexed queries (personal, group-matching, default-group), unions in PHP, dedupes by UUID
- [ ] 3.3 Each result row in `findVisibleToUser` is tagged with its source (`user` / `group` / `default`) before merge so the `source` field can be set on the response
- [ ] 3.4 Add fixture-based PHPUnit test covering: user with 1 personal + 2 group + 1 default; user in 0 matching groups; UUID overlap dedup edge case

## 4. Service layer

- [ ] 4.1 In `DashboardFactory::create()` accept optional `type` and `groupId` kwargs; enforce the invariant `(type='group_shared' XOR groupId IS NULL)`; throw `\InvalidArgumentException` on mismatch
- [ ] 4.2 Add `DashboardService::createGroupShared(string $groupId, string $name, ?string $description, ?int $gridColumns)` — admin-only via `IGroupManager::isAdmin($currentUserId)` guard
- [ ] 4.3 Add `DashboardService::updateGroupShared(string $groupId, string $uuid, array $patch)` — admin guard, ownership check (`type === group_shared AND groupId matches path`)
- [ ] 4.4 Add `DashboardService::deleteGroupShared(string $groupId, string $uuid)` with last-in-group guard (HTTP 400 when removing would leave non-`default` group with zero group-shared dashboards); `default` group exempt from the guard
- [ ] 4.5 Add `DashboardService::getVisibleToUser(string $userId): array` that wires `IGroupManager::getUserGroupIds($userId)` into the mapper call and adds `source` to each result
- [ ] 4.6 Update `PermissionService::getEffectivePermissionLevel()` to return `view_only` for non-admin members on `group_shared` dashboards, `full` for admins

## 5. Controller + routes

- [ ] 5.1 Add `DashboardController::visible()` mapped to `GET /api/dashboards/visible` (logged-in user, `#[NoAdminRequired]`)
- [ ] 5.2 Add `DashboardController::listGroup(string $groupId)` mapped to `GET /api/dashboards/group/{groupId}` (logged-in)
- [ ] 5.3 Add `DashboardController::createGroup(string $groupId)` mapped to `POST /api/dashboards/group/{groupId}` (admin-only via `IGroupManager::isAdmin` check inside method body, since `#[NoAdminRequired]` semantic-auth gate requires runtime check)
- [ ] 5.4 Add `DashboardController::getGroup(string $groupId, string $uuid)` mapped to `GET /api/dashboards/group/{groupId}/{uuid}` (logged-in)
- [ ] 5.5 Add `DashboardController::updateGroup(string $groupId, string $uuid)` mapped to `PUT /api/dashboards/group/{groupId}/{uuid}` (admin-only)
- [ ] 5.6 Add `DashboardController::deleteGroup(string $groupId, string $uuid)` mapped to `DELETE /api/dashboards/group/{groupId}/{uuid}` (admin-only)
- [ ] 5.7 Register all six routes in `appinfo/routes.php` with proper requirements (groupId regex allows `default` and any valid Nextcloud group ID)
- [ ] 5.8 Confirm every new method carries the correct Nextcloud auth attribute (`#[NoAdminRequired]` + in-body admin check for mutations) — gate-route-auth + gate-semantic-auth must pass

## 6. OpenRegister seed data

- [ ] 6.1 Add three group-shared seed dashboards to `_registers.json` per the design's Seed Data section: Welcome (default), Campaigns (marketing), Sprint (engineering)
- [ ] 6.2 Each seed includes its placements with appropriate widget types and grid positions
- [ ] 6.3 Verify seed data applies cleanly via `occ mydash:seed` or whichever local command the app uses

## 7. Frontend store

- [ ] 7.1 Extend `src/stores/dashboards.js` with `groupSharedDashboards` and `defaultGroupDashboards` getters derived from `/api/dashboards/visible` payload
- [ ] 7.2 Add `source` field plumbing — every dashboard tracked in the store carries `source` so the component layer can route subsequent edit calls (PUT to `/api/dashboard/{uuid}` for `source='user'`, PUT to `/api/dashboards/group/{groupId}/{uuid}` for `source='group'|'default'`)
- [ ] 7.3 Make the listing page call `/api/dashboards/visible` instead of the older `/api/dashboards` endpoint (the old endpoint stays available for legacy clients)
- [ ] 7.4 Defer admin-only group-shared CRUD UI to follow-up `admin-group-management` change — note this in the changelog

## 8. PHPUnit tests

- [ ] 8.1 `DashboardMapperTest::findByGroup` — basic lookup, empty-group case, non-existent group case
- [ ] 8.2 `DashboardMapperTest::findVisibleToUser` — mixed personal + group + default fixtures; user with 0 group memberships still gets default-group rows; UUID overlap deduped
- [ ] 8.3 `DashboardControllerTest` — admin-only enforcement on POST/PUT/DELETE returns 403 for non-admins
- [ ] 8.4 `DashboardControllerTest::testDeleteLastInGroupGuard` — HTTP 400 when deleting the only dashboard in a non-default group; default group exempt
- [ ] 8.5 `DashboardServiceTest::testCreateRejectsTypeGroupSharedWithoutGroupId` — invariant guard
- [ ] 8.6 `PermissionServiceTest` — `view_only` for non-admin viewing group_shared, `full` for admin viewing same record
- [ ] 8.7 Test all 3 permission levels (`view_only`, `add_only`, `full`) round-trip correctly on personal + admin_template (regression — these scopes must keep working unchanged)

## 9. End-to-end Playwright tests

- [ ] 9.1 Admin user creates a group-shared dashboard via the new endpoint (using API call from a fixture; UI ships in follow-up change), and a member of the targeted group sees it on `/visible`
- [ ] 9.2 User in 0 matching groups still sees default-group dashboards in `/visible`
- [ ] 9.3 Non-admin user attempting PUT on a group-shared dashboard via direct API call gets HTTP 403
- [ ] 9.4 Admin update to a group-shared dashboard's name is visible to a member on next page reload (no per-user copy interference)

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 10.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 10.3 Update generated OpenAPI spec / Postman collection so external API consumers see the new endpoints
- [ ] 10.4 `i18n` keys for all new error messages (`Cannot delete the only dashboard in the group`, etc.) in both `nl` and `en` per the i18n requirement
- [ ] 10.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 10.6 Run all 10 `hydra-gates` locally before opening PR
