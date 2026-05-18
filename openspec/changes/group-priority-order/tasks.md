# Tasks — group-priority-order

## Tasks

- [ ] Task 1: Add `AdminSetting::KEY_GROUP_ORDER = 'group_order'` and `AdminSettingsService::getGroupOrder(): array` — `json_decode` the value, return `[]` on null/missing/corrupt JSON, never throw
- [ ] Task 2: Add `AdminSettingsService::setGroupOrder(array $groupIds): void` — validate all elements are non-empty strings, deduplicate (first occurrence wins, preserve order), persist as JSON
- [ ] Task 3: Add `AdminSettingsController::listGroups()` assembling `{active, inactive, allKnown}` from `IGroupManager::search('')` + `getGroupOrder()`; `inactive` sorted by `displayName` (case-insensitive)
- [ ] Task 4: Add `AdminSettingsController::updateGroupOrder()` — parse body, validate `groups` is array-of-strings, return 400 on invalid payload, else call `setGroupOrder` and return 200
- [ ] Task 5: Register `GET /api/admin/groups` and `POST /api/admin/groups` in `appinfo/routes.php`; both endpoints admin-only via in-body `IGroupManager::isAdmin($userId)` guard returning 403 for non-admins
- [ ] Task 6: Frontend — two-list drag-and-drop (active vs inactive) in `src/views/AdminApp.vue` using existing `vuedraggable`, with case-insensitive substring filter input above each list (matches `displayName || id`)
- [ ] Task 7: Frontend — auto-save on each drag (`@change` triggers POST) with 300ms debounce to throttle drag-spam; success/error toast via `@nextcloud/dialogs`
- [ ] Task 8: Frontend — stale ID rendering appends "(removed)" to display name when `id ∉ allKnown`, and the row stays removable
- [ ] Task 9: PHPUnit service — `getGroupOrder` returns `[]` when row absent + on corrupt JSON without throwing; `setGroupOrder` deduplicates preserving order + rejects non-string elements
- [ ] Task 10: PHPUnit controller — replace-wholesale semantics (POST `["c","b"]` over `["a","b","c"]` → `["c","b"]`); 403 on both endpoints for non-admin; `listGroups` returns disjoint exhaustive lists with stale-ID surfacing
- [ ] Task 11: Playwright — drag from inactive → active fires POST and persists across reload; stale ID renders with "(removed)" indicator and is removable
- [ ] Task 12: Quality + docs — OpenAPI updated for both endpoints; `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan); frontend lint passes; confirm the 300ms debounce is implemented per Task 7
- [ ] Task 13: i18n — `nl_NL` + `en_US` for all new UI strings (filter placeholder, "(removed)" indicator, toast copy)

## Verification

`openspec validate` exits clean. Drag reorders persist across reload; stale IDs render with the "(removed)" affordance.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 9–10; Playwright per Task 11. Newman/Postman updated with both endpoints (Task 12).

## Documentation (company-wide ADR-010)

Changelog entry for the new admin priority-order surface.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 13.
