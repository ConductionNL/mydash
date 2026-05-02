# Tasks — group-routing

## 1. Backend resolver

- [x] 1.1 Add `AdminTemplateService::resolvePrimaryGroup(string $userId): string` (read-only, pure) per REQ-TMPL-012
- [x] 1.2 Add internal helper `AdminTemplateService::pickFirstMatch(array $orderedGroups, array $userGroups): ?string` for clarity and direct unit testing
- [x] 1.3 Add `AdminSettingsService::getGroupOrder(): array` returning the JSON `string[]` from `admin_settings.group_order` (default `[]`) — already shipped by the parent `group-priority-order` change
- [x] 1.4 Tolerate stale group IDs in `group_order` — never throw when an entry no longer exists in Nextcloud (REQ-TMPL-012 final scenario)
- [x] 1.5 Add `Dashboard::DEFAULT_GROUP_ID = 'default'` constant so the sentinel string is named in exactly one place — already shipped by `multi-scope-dashboards`

## 2. Single source of truth wiring

- [x] 2.1 Update `PageController::index` (the actual workspace renderer; `WorkspaceController` does not exist) to call `resolvePrimaryGroup` and pass the result into dashboard resolution
- [x] 2.2 Refactor REQ-DASH-013 implementation (`DashboardService::getVisibleToUser`) to consume `AdminTemplateService::getUserGroupIdsFor` instead of injecting `IGroupManager` directly
- [x] 2.3 REQ-DASH-018 implementation (`DashboardService::resolveActiveDashboard`) already consumes the resolver via `PageController::index` passing the resolved `primaryGroupId`
- [x] 2.4 Refactor `PermissionService`, `TemplateService`, `RuleEvaluatorService` to consume `AdminTemplateService::getUserGroupIdsFor` so the only direct `IGroupManager::getUserGroupIds` call lives inside the resolver

## 3. Frontend

- [x] 3.1 Surface the resolved primary group as `primaryGroup` initial state (already plumbed by the `runtime-shell` change)
- [x] 3.2 Surface its display name via `IGroupManager::get($id)?->getDisplayName()` (or the literal `'Default'` for the `'default'` sentinel) as `primaryGroupName` (the existing initial-state contract key)
- [x] 3.3 Render the display name in the workspace header (`src/views/Views.vue`) so users can see which group's dashboards they are viewing

## 4. PHPUnit tests

- [x] 4.1 Table-driven `AdminTemplateServiceTest::testResolvePrimaryGroup*` covering every REQ-TMPL-012 scenario: priority order wins, no match returns `'default'`, empty `group_order` returns `'default'`, configured-but-not-member is skipped, deleted-group is harmless
- [x] 4.2 Single-source-of-truth grep guard test (`AdminTemplateServiceGrepGuardTest`): every `->getUserGroupIds(` call in `lib/` lives in `AdminTemplateService.php` — fail otherwise (REQ-TMPL-013)
- [x] 4.3 `AdminTemplateServiceTest::testPickFirstMatch*` direct unit tests for the helper (empty inputs, no overlap, multiple overlaps, configured-but-not-member skipped)

## 5. Quality gates

- [x] 5.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — touched files clean; remaining errors are pre-existing in unrelated files
- [x] 5.2 ESLint + Stylelint clean on touched Vue/JS files
- [x] 5.3 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention)
- [x] 5.4 `composer lint:initial-state` and `npm run lint:initial-state` both green
- [x] 5.5 i18n keys for the `'Your primary group for shared dashboards'` tooltip in both `nl` and `en` (the `'Default'` fallback string already shipped)
