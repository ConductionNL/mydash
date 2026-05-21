# Tasks — Group priority order

## Backend Implementation

- [ ] **Task 1.1:** Add `GROUP_ORDER_KEY` constant to `AdminSettings` class (or create a new constants file) with value `'group_order'`
- [ ] **Task 1.2:** Create `lib/Service/AdminSettingsService.php` with three new methods (if not already present; extend if it exists):
  - `getGroupOrder(): array` — reads persisted setting, returns `[]` on missing/corrupt JSON (defensive read, log warning if JSON is malformed)
  - `setGroupOrder(array $groupIds): void` — validates all elements are strings, rejects duplicates (throw `\InvalidArgumentException` with message "Duplicate group IDs"), persists via `IAppConfig` or settings table
  - `listGroups(): array` — returns `{ active: getGroupOrder(), inactive: allKnown minus active, allKnown: IGroupManager result }`
  - `isGroupActive(string $groupId): bool` — helper, returns `in_array($groupId, getGroupOrder())`
- [ ] **Task 1.3:** Create `lib/Controller/AdminSettingsController.php` (or extend if exists) with two new methods:
  - `#[AuthorizedAdminSetting(Application::APP_ID)]` `listGroups(): JSONResponse` — calls `AdminSettingsService::listGroups()`, returns the three arrays (REQ-GOP-001)
  - `#[AuthorizedAdminSetting(Application::APP_ID)]` `updateGroupOrder(): JSONResponse` — reads request body `{groups: []}`, calls `AdminSettingsService::setGroupOrder()`, returns updated state; on validation error, return HTTP 400 with `{"message":"Invalid group list"}` (no stack trace, static message per ADR-015)
- [ ] **Task 1.4:** Register both routes in `appinfo/routes.php` BEFORE any wildcard `{slug}` routes:
  - `GET /api/admin/groups` → `AdminSettingsController#listGroups`
  - `POST /api/admin/groups` → `AdminSettingsController#updateGroupOrder`
- [ ] **Task 1.5:** Add logging to both controller methods: INFO on success (user ID, action, timestamp), WARNING on failure (include error reason, no PII beyond user ID per ADR-005)
- [ ] **Task 1.6:** Add PHPDoc `@spec openspec/changes/group-priority-order/tasks.md#task-N` tag to every new class and public method (ADR-003)
- [ ] **Task 1.7:** Add SPDX header `// SPDX-License-Identifier: EUPL-1.2` at top of new PHP files after `<?php` (ADR-014)

## Frontend Implementation

- [ ] **Task 2.1:** Create `src/views/GroupOrderSection.vue` — new admin UI component with:
  - Two columns: "Active groups" (left, ordered) and "Inactive groups" (right, alphabetical)
  - Search/filter inputs for each column (case-insensitive substring match)
  - `vuedraggable` integration allowing drag from left ↔ right, drag within left to reorder
  - "(deleted)" label for stale group IDs in the active list (IDs in active that don't exist in allKnown)
  - Auto-save button (or auto-save on drag with 300ms debounce per REQ-GOP-005)
  - Loading indicator while fetching groups
  - Error message display if the API call fails (user-facing message only, no stack trace)
- [ ] **Task 2.2:** Integrate `GroupOrderSection.vue` into `src/views/AdminApp.vue` as a new section
- [ ] **Task 2.3:** Create or update `src/store/modules/groupOrder.js` (or similar) with Pinia store actions:
  - `fetchGroups()` — calls `GET /api/admin/groups`, stores the result
  - `saveGroupOrder(groups: string[])` — calls `POST /api/admin/groups`, updates store on success
  - Both actions wrapped in `try/catch` with user-facing error feedback (per ADR-004)
- [ ] **Task 2.4:** All UI strings translated:
  - `l10n/en.json` — add keys like `"Active groups"`, `"Inactive groups"`, `"Search groups"`, `"No groups found"`, `"(deleted)"`, `"Save failed: {error}"`, etc.
  - `l10n/nl.json` — Dutch translations with 100% key parity to English (per ADR-007)
- [ ] **Task 2.5:** Add SPDX header `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line of new Vue files (ADR-014)
- [ ] **Task 2.6:** Verify every `<Nc*>` or `<Cn*>` component is imported AND listed in `components: {}` (ADR-015)

## Integration & Testing

- [ ] **Task 3.1:** **Deduplication check** — search `openspec/specs/` for prior group-order or group-priority features; grep `lib/Service/` + `openregister/lib/Service/` for `group_order`, `groupPriority`, `primaryGroup`; verify `@conduction/nextcloud-vue` does not already expose group-ordering; record findings (even "no overlap found") in a comment block at the top of `AdminSettingsService.php` (ADR-012)
- [ ] **Task 3.2:** **PHPUnit for AdminSettingsService** — `tests/Unit/Service/AdminSettingsServiceTest.php`:
  - Test `getGroupOrder()` returns `[]` when missing
  - Test `getGroupOrder()` returns `[]` when JSON is corrupt (log warning, no throw)
  - Test `setGroupOrder([])` accepts empty array
  - Test `setGroupOrder(['a','b','c'])` persists and retrieves correctly
  - Test `setGroupOrder(['a','a'])` throws `InvalidArgumentException` (duplicates)
  - Test `listGroups()` returns three arrays (active, inactive, allKnown) with correct contents
  - Test `isGroupActive()` returns true/false correctly
- [ ] **Task 3.3:** **PHPUnit for AdminSettingsController** — `tests/Unit/Controller/AdminSettingsControllerTest.php`:
  - Test non-admin calls return 403
  - Test `GET /api/admin/groups` returns 200 with correct response shape
  - Test `POST /api/admin/groups` with valid body returns 200 + updated state
  - Test `POST /api/admin/groups` with duplicate group IDs returns 400 + static message (no details)
  - Test `POST /api/admin/groups` with non-string element returns 400 + static message
  - Test `POST /api/admin/groups` missing `groups` key returns 400 + static message
- [ ] **Task 3.4:** **Newman/Postman collection** — `tests/integration/group-order.postman_collection.json`:
  - Happy path: `GET /api/admin/groups` (admin creds) → 200 + correct response
  - Happy path: `POST /api/admin/groups` (admin creds, valid body) → 200 + updated state
  - Error path: `GET /api/admin/groups` (non-admin user) → 403
  - Error path: `POST /api/admin/groups` (non-admin user) → 403
  - Error path: `POST /api/admin/groups` (invalid body, duplicates) → 400
  - Error path: `POST /api/admin/groups` (invalid body, non-string) → 400
- [ ] **Task 3.5:** **Playwright browser tests** — `tests/e2e/group-order.spec.js`:
  - Admin navigates to admin settings and sees GroupOrderSection
  - Admin drags a group from inactive to active → auto-saves, group appears in active list
  - Admin reorders active groups by dragging → priority order updated
  - Stale group ID (in active but not in allKnown) displays "(deleted)" label
  - Drag from active back to inactive removes group from order

## Quality & Compliance

- [ ] **Task 4.1:** **Smoke tests** (ADR-008):
  - Manually call `GET /api/admin/groups` with curl (admin auth) — verify response shape
  - Manually call `POST /api/admin/groups` with curl (valid body) — verify 200 + state change
  - Manually call both endpoints without auth — verify 403
  - Call `POST` with malformed JSON (duplicate groups) — verify 400 + static message
- [ ] **Task 4.2:** **Code quality** — run before commit:
  - `composer check:strict` — phpcs, phpmd, psalm, phpstan all green
  - `npm run lint` — ESLint + Stylelint clean on new/modified Vue + JS
  - No forbidden patterns: no `var_dump`, `die`, `error_log`, `print_r`, `dd`, `@file_*`, `eval` in new code
  - No stub code: no empty method bodies, no "In a complete implementation" comments
- [ ] **Task 4.3:** **SPDX compliance**:
  - Every new PHP file has `@license` and `@copyright` PHPDoc tags in file header (ADR-014)
  - Every new Vue file has `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
  - Every new JS file has `// SPDX-License-Identifier: EUPL-1.2` as first line
- [ ] **Task 4.4:** **Auth consistency** (ADR-005 + ADR-016):
  - Both endpoints use `#[AuthorizedAdminSetting(Application::APP_ID)]` (middleware-level enforcement)
  - Controller body does NOT call `requireAdmin()` or `isAdmin()` — the middleware already enforced it
  - Error responses use static messages (`"Invalid group list"`, `"Not authorized"`), never `$e->getMessage()` (ADR-015)
- [ ] **Task 4.5:** **Hydra gates** — all 10 gates must pass:
  - Gate 1 (composer-audit): no known PHP vulnerabilities
  - Gate 2 (phpcs): PHPCS standard clean
  - Gate 3 (phpstan): level 8 clean
  - Gate 4 (psalm): no errors
  - Gate 5 (route-auth): both routes have `#[AuthorizedAdminSetting]`
  - Gate 6 (orphan-auth): no defined-but-unused auth methods
  - Gate 7 (semantic-auth): attributes match method bodies (both have middleware-only auth, no body checks)
  - Gate 8 (spdx-headers): all PHP files have `@license` + `@copyright`
  - Gate 9 (no-admin-idor): endpoints are admin-only (no IDOR surface)
  - Gate 10 (forbidden-patterns): no dangerous functions

## Documentation

- [ ] **Task 5.1:** Add section to `docs/admin-settings.md` (or create `docs/group-priority-order.md`):
  - Explain purpose: "Group priority determines which group's features and defaults are applied to users in multiple groups"
  - Show screenshot of the two-list drag-drop UI
  - Explain how to move groups between active and inactive
  - Explain stale-group handling (marked as "(deleted)")
  - Link to downstream features that consume `group_order` (group-routing, role-based-content)
- [ ] **Task 5.2:** Update app README or CHANGELOG with a note: "New admin feature: Group priority order. Admins can now configure which Nextcloud groups are active in MyDash and set their priority for multi-group users."

## Verification Checklist

Before marking complete:
- [ ] `openspec validate` exits clean
- [ ] All 10 hydra gates pass
- [ ] PHPUnit + Newman tests all green
- [ ] Playwright tests all green
- [ ] Manual smoke tests pass (curl + browser)
- [ ] i18n: `l10n/en.json` + `l10n/nl.json` key parity confirmed
- [ ] Documentation screenshots included and accurate
- [ ] No TODO comments, no stub code
- [ ] All new classes have `@spec` PHPDoc tags
- [ ] SPDX headers on all new files
