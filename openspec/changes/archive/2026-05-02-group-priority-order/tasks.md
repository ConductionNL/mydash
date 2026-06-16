# Tasks — group-priority-order

## 1. Backend — Service & Entity

- [x] 1.1 Add constant `AdminSetting::KEY_GROUP_ORDER = 'group_order'`
- [x] 1.2 Add `AdminSettingsService::getGroupOrder(): array` — `json_decode` the value, return `[]` on null/missing/corrupt JSON, never throw
- [x] 1.3 Add `AdminSettingsService::setGroupOrder(array $groupIds): void` — validate all elements are non-empty strings, deduplicate (first occurrence wins, preserve order), persist as JSON

## 2. Backend — Controller & Routes

- [x] 2.1 Add `AdminSettingsController::listGroups()` — assemble `{active, inactive, allKnown}` from `IGroupManager::search('')` and `getGroupOrder()`; sort `inactive` by displayName (case-insensitive)
- [x] 2.2 Add `AdminSettingsController::updateGroupOrder()` — parse body, validate `groups` is an array of strings, return HTTP 400 on validation failure, else call `setGroupOrder` and return HTTP 200
- [x] 2.3 Register `GET /api/admin/groups` and `POST /api/admin/groups` in `appinfo/routes.php`
- [x] 2.4 Both endpoints admin-only — guard via `IGroupManager::isAdmin($userId)` in controller (NOT via `#[NoAdminRequired]` absence alone, because the controller may be shared with other admin-gated methods); return HTTP 403 on non-admin

## 3. Frontend

- [x] 3.1 Two-list drag-and-drop component (active vs inactive columns) extracted into `src/components/admin/GroupPriorityOrder.vue` and mounted from `src/components/admin/AdminSettings.vue`. Native HTML5 drag-and-drop is used because `vuedraggable` is NOT actually installed in this app (despite the proposal's claim) — keeping zero new deps was preferred over adding one for a single screen.
- [x] 3.2 Filter input above each list (case-insensitive substring match on `displayName || id`)
- [x] 3.3 Auto-save on every drag (`queueSave()` triggers POST), with 300ms debounce to throttle drag-spam
- [x] 3.4 Toast success/error via `@nextcloud/dialogs`
- [x] 3.5 Stale ID rendering: append "(removed)" to display name when `id ∉ allKnown`
- [x] 3.6 i18n: all UI strings in `en` + `nl` translation files (per project i18n requirement) — added to `l10n/{en,nl}.{js,json}`

## 4. Tests

- [x] 4.1 PHPUnit `AdminSettingsServiceTest`: `getGroupOrder` returns `[]` when row absent
- [x] 4.2 PHPUnit `AdminSettingsServiceTest`: `getGroupOrder` returns `[]` on corrupt JSON without throwing
- [x] 4.3 PHPUnit `AdminSettingsServiceTest`: `setGroupOrder` deduplicates while preserving order
- [x] 4.4 PHPUnit `AdminSettingsServiceTest`: `setGroupOrder` rejects non-string elements
- [x] 4.5 PHPUnit `AdminSettingsControllerTest`: replace-wholesale semantics (POST `["c","b"]` over `["a","b","c"]` → `["c","b"]`)
- [x] 4.6 PHPUnit `AdminSettingsControllerTest`: 403 on both endpoints for non-admin
- [x] 4.7 PHPUnit `AdminSettingsControllerTest`: `listGroups` returns disjoint exhaustive lists with stale ID surfacing
- [ ] 4.8 Playwright: drag from inactive → active fires POST and persists across reload — DEFERRED to wider e2e harness work
- [ ] 4.9 Playwright: stale ID renders with "(removed)" indicator and is removable — DEFERRED to wider e2e harness work
- [x] 4.10 Vitest `GroupPriorityOrder.spec.js`: load + render + click-to-move + filter + debounced auto-save + stale-affix rendering (covers REQ-ASET-012/013/014 from the JS side)

## 5. Documentation & Quality

- [ ] 5.1 OpenAPI updated for `GET /api/admin/groups` and `POST /api/admin/groups` — N/A (this app does not yet ship an OpenAPI document; routes live in `appinfo/routes.php` only)
- [x] 5.2 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [x] 5.3 Frontend lint passes
- [x] 5.4 Throttling: confirm 300ms debounce is implemented in the Vue layer (per task 3.3)
