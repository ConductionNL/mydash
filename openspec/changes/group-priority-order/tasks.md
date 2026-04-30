# Tasks — group-priority-order

## 1. Backend — Service & Entity

- [ ] 1.1 Add constant `AdminSetting::KEY_GROUP_ORDER = 'group_order'`
- [ ] 1.2 Add `AdminSettingsService::getGroupOrder(): array` — `json_decode` the value, return `[]` on null/missing/corrupt JSON, never throw
- [ ] 1.3 Add `AdminSettingsService::setGroupOrder(array $groupIds): void` — validate all elements are non-empty strings, deduplicate (first occurrence wins, preserve order), persist as JSON

## 2. Backend — Controller & Routes

- [ ] 2.1 Add `AdminSettingsController::listGroups()` — assemble `{active, inactive, allKnown}` from `IGroupManager::search('')` and `getGroupOrder()`; sort `inactive` by displayName (case-insensitive)
- [ ] 2.2 Add `AdminSettingsController::updateGroupOrder()` — parse body, validate `groups` is an array of strings, return HTTP 400 on validation failure, else call `setGroupOrder` and return HTTP 200
- [ ] 2.3 Register `GET /api/admin/groups` and `POST /api/admin/groups` in `appinfo/routes.php`
- [ ] 2.4 Both endpoints admin-only — guard via `IGroupManager::isAdmin($userId)` in controller (NOT via `#[NoAdminRequired]` absence alone, because the controller may be shared with other admin-gated methods); return HTTP 403 on non-admin

## 3. Frontend

- [ ] 3.1 Two-list drag-and-drop component using existing `vuedraggable` (active vs inactive columns) in `src/views/AdminApp.vue`
- [ ] 3.2 Filter input above each list (case-insensitive substring match on `displayName || id`)
- [ ] 3.3 Auto-save on every drag (`@change` triggers POST), with 300ms debounce to throttle drag-spam
- [ ] 3.4 Toast success/error via `@nextcloud/dialogs`
- [ ] 3.5 Stale ID rendering: append "(removed)" to display name when `id ∉ allKnown`
- [ ] 3.6 i18n: all UI strings in `en` + `nl` translation files (per project i18n requirement)

## 4. Tests

- [ ] 4.1 PHPUnit `AdminSettingsServiceTest`: `getGroupOrder` returns `[]` when row absent
- [ ] 4.2 PHPUnit `AdminSettingsServiceTest`: `getGroupOrder` returns `[]` on corrupt JSON without throwing
- [ ] 4.3 PHPUnit `AdminSettingsServiceTest`: `setGroupOrder` deduplicates while preserving order
- [ ] 4.4 PHPUnit `AdminSettingsServiceTest`: `setGroupOrder` rejects non-string elements
- [ ] 4.5 PHPUnit `AdminSettingsControllerTest`: replace-wholesale semantics (POST `["c","b"]` over `["a","b","c"]` → `["c","b"]`)
- [ ] 4.6 PHPUnit `AdminSettingsControllerTest`: 403 on both endpoints for non-admin
- [ ] 4.7 PHPUnit `AdminSettingsControllerTest`: `listGroups` returns disjoint exhaustive lists with stale ID surfacing
- [ ] 4.8 Playwright: drag from inactive → active fires POST and persists across reload
- [ ] 4.9 Playwright: stale ID renders with "(removed)" indicator and is removable

## 5. Documentation & Quality

- [ ] 5.1 OpenAPI updated for `GET /api/admin/groups` and `POST /api/admin/groups`
- [ ] 5.2 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 5.3 Frontend lint passes
- [ ] 5.4 Throttling: confirm 300ms debounce is implemented in the Vue layer (per task 3.3)
