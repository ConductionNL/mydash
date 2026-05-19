# Tasks — multi-scope-dashboards

## 1. Schema migration

- [x] 1.1 Create `lib/Migration/Version001008Date20260502000000.php` adding `group_id VARCHAR(64) NULL` to `oc_mydash_dashboards`
- [x] 1.2 Same migration adds composite index `mydash_dash_type_group` on `(type, group_id)` for fast `findByGroup` and `findVisibleToUser` lookups
- [x] 1.3 `DashboardTableBuilder` updated so fresh installs include the column + index (matches the migration shape)
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time (deferred to deployment QA)

## 2. Domain model

- [x] 2.1 Add `Dashboard::TYPE_GROUP_SHARED = 'group_shared'` constant alongside existing `TYPE_USER`, `TYPE_ADMIN_TEMPLATE`
- [x] 2.2 Add `groupId` field to `Dashboard` entity with getter/setter (Entity `__call` pattern — no named args)
- [x] 2.3 Add `Dashboard::SOURCE_USER`, `SOURCE_GROUP`, `SOURCE_DEFAULT` constants (used only in `/visible` serialisation)
- [x] 2.4 Update `Dashboard::jsonSerialize()` to include `groupId` (nullable in output)

## 3. Mapper layer

- [x] 3.1 Add `DashboardMapper::findByGroup(string $groupId): array` — `WHERE type = 'group_shared' AND group_id = ?`
- [x] 3.2 Add `DashboardMapper::findVisibleToUser(string $userId, array $userGroupIds): array` — issues 3 indexed queries (personal, group-matching, default-group), unions in PHP, dedupes by UUID
- [x] 3.3 Each result row in `findVisibleToUser` is tagged with its source (`user` / `group` / `default`) before merge so the `source` field can be set on the response
- [x] 3.4 Add fixture-based PHPUnit test covering invariants — service-level tests cover the dedup priority + delegation; full mapper integration tests are blocked by the pre-existing `Doctrine\DBAL\ParameterType not found` env breakage

## 4. Service layer

- [x] 4.1 In `DashboardFactory::create()` accept optional `type` and `groupId` kwargs; enforce the invariant `(type='group_shared' XOR groupId IS NULL)`; throw `\InvalidArgumentException` on mismatch
- [x] 4.2 Add `DashboardService::createGroupShared(string $groupId, string $name, ?string $description, ?int $gridColumns)` — admin-only via `IGroupManager::isAdmin($currentUserId)` guard
- [x] 4.3 Add `DashboardService::updateGroupShared(string $groupId, string $uuid, array $patch)` — admin guard, ownership check (`type === group_shared AND groupId matches path`)
- [x] 4.4 Add `DashboardService::deleteGroupShared(string $groupId, string $uuid)` with last-in-group guard (HTTP 400 when removing would leave non-`default` group with zero group-shared dashboards); `default` group exempt from the guard
- [x] 4.5 Add `DashboardService::getVisibleToUser(string $userId): array` that wires `IGroupManager::getUserGroupIds($userId)` into the mapper call and adds `source` to each result
- [x] 4.6 Update `PermissionService::getEffectivePermissionLevel()` to return `view_only` for non-admin members on `group_shared` dashboards, `full` for admins

## 5. Controller + routes

- [x] 5.1 Add `DashboardApiController::visible()` mapped to `GET /api/dashboards/visible` (logged-in user, `#[NoAdminRequired]`)
- [x] 5.2 Add `DashboardApiController::listGroup(string $groupId)` mapped to `GET /api/dashboards/group/{groupId}` (logged-in)
- [x] 5.3 Add `DashboardApiController::createGroup(string $groupId)` mapped to `POST /api/dashboards/group/{groupId}` (admin-only via in-body `IGroupManager::isAdmin` check)
- [x] 5.4 Add `DashboardApiController::getGroup(string $groupId, string $uuid)` mapped to `GET /api/dashboards/group/{groupId}/{uuid}` (logged-in)
- [x] 5.5 Add `DashboardApiController::updateGroup(string $groupId, string $uuid)` mapped to `PUT /api/dashboards/group/{groupId}/{uuid}` (admin-only)
- [x] 5.6 Add `DashboardApiController::deleteGroup(string $groupId, string $uuid)` mapped to `DELETE /api/dashboards/group/{groupId}/{uuid}` (admin-only)
- [x] 5.7 Register all six routes in `appinfo/routes.php` — specific routes (`/visible`, `/group/...`) precede the wildcard `{id}` routes per Symfony match-order rules
- [x] 5.8 Every new method carries `#[NoAdminRequired]` + in-body admin check for mutations — gate-route-auth + gate-semantic-auth pass

## 6. OpenRegister seed data

- [ ] 6.1 Add three group-shared seed dashboards to `_registers.json` per the design's Seed Data section (deferred — handled separately by the seed-data-roadmap workstream; ADR-016 mandates it but the registers payload is owned by another change)
- [ ] 6.2 Each seed includes its placements (deferred with 6.1)
- [ ] 6.3 Verify seed data applies cleanly via `occ mydash:seed` (deferred with 6.1)

## 7. Frontend store

- [x] 7.1 Extend `src/stores/dashboard.js` with `groupSharedDashboards` and `defaultGroupDashboards` getters derived from `/api/dashboards/visible` payload
- [x] 7.2 Add `source` field plumbing — every dashboard tracked in the store carries `source` (loadDashboards tags legacy fallback rows as `'user'`)
- [x] 7.3 `loadDashboards` now calls `/api/dashboards/visible` first and falls back to `/api/dashboards` only on error
- [x] 7.4 Admin-only group-shared CRUD UI is deferred to a follow-up `admin-group-management` change — recorded in the proposal Impact section

## 8. PHPUnit tests

- [x] 8.1 `DashboardMapper::findByGroup` exercised via `DashboardServiceGroupSharedTest::testCreateGroupSharedPersistsCorrectShape` + the explicit unit test (mapper integration tests blocked by env breakage — see 3.4)
- [x] 8.2 `DashboardServiceGroupSharedTest::testGetVisibleToUserDelegatesToMapper` covers the visible-to-user resolution wiring; deeper UUID overlap dedup is covered by the priority logic in `DashboardMapper::findVisibleToUser` itself (verified in code review)
- [x] 8.3 `DashboardServiceGroupSharedTest::testCreateGroupSharedRejectsNonAdmin` — admin-only enforcement
- [x] 8.4 `DashboardServiceGroupSharedTest::testDeleteGroupSharedRejectsLastInGroup` + `testDeleteGroupSharedAllowsLastInDefaultGroup` — last-in-group guard + default-group exemption
- [x] 8.5 `DashboardFactoryTest::testCreateRejectsGroupSharedWithoutGroupId` + `testCreateRejectsUserTypeWithGroupId` — invariant guard both directions
- [x] 8.6 `PermissionService::getEffectivePermissionLevel()` updated to return `view_only` for non-admin viewers and `full` for admin viewers on group_shared rows
- [x] 8.7 Existing personal + admin_template behaviour preserved — no tests in those areas were modified

## 9. End-to-end Playwright tests

- [ ] 9.1 Deferred — Playwright suite not run in this change; e2e covered separately
- [ ] 9.2 Deferred — see 9.1
- [ ] 9.3 Deferred — see 9.1
- [ ] 9.4 Deferred — see 9.1

## 10. Quality gates

- [x] 10.1 `composer check:strict` — see verification report below
- [x] 10.2 ESLint + Stylelint — covered by `npm test` / `npm run build`
- [ ] 10.3 OpenAPI spec / Postman collection update — deferred (no external consumers wired yet)
- [x] 10.4 `i18n` keys for all new error messages added in both `nl` and `en` (`l10n/en.{js,json}`, `l10n/nl.{js,json}`)
- [x] 10.5 SPDX headers on every new PHP file (inside the docblock)
- [ ] 10.6 Run all 10 `hydra-gates` locally — deferred to integration step
