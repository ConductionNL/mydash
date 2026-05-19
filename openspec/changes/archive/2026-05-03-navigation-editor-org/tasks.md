# Tasks — navigation-editor-org

## 1. Backend domain model

- [ ] 1.1 Add global IAppConfig setting `mydash.org_navigation_tree` (JSON string, type `mixed`)
- [ ] 1.2 Add global IAppConfig setting `mydash.org_navigation_position` (JSON string, default `'hidden'`)
- [ ] 1.3 Create `OrgNavigationService` class with methods:
  - `getTree(): array` — return parsed tree from setting (or empty array if not set)
  - `setTree(array $tree): void` — validate and persist tree to setting
  - `filterTreeByUserGroups(array $tree, string $userId): array` — recursively filter nodes by user's group memberships
  - `validateTree(array $tree): void` — validate depth, ids, urls, return error on invalid
  - `sanitiseUrl(string $url): string` — reject javascript:, data:, vbscript: schemes

## 2. Backend API controller

- [ ] 2.1 Create `AdminOrgNavigationController` class
- [ ] 2.2 Implement `getOrgNavigation()` → `GET /api/admin/org-navigation`: fetch tree, filter by user's groups, return JSON; accessible to all logged-in users
- [ ] 2.3 Implement `updateOrgNavigation()` → `PUT /api/admin/org-navigation`: admin-only, validate tree, persist, return HTTP 200 on success or HTTP 400/403 on error
- [ ] 2.4 Register routes in `app.php` or `routes.php`:
  - `GET /api/admin/org-navigation` → `AdminOrgNavigationController::getOrgNavigation`
  - `PUT /api/admin/org-navigation` → `AdminOrgNavigationController::updateOrgNavigation`
- [ ] 2.5 Add admin permission check: return HTTP 403 if user is not admin on `PUT`

## 3. Backend validation

- [ ] 3.1 In `OrgNavigationService::validateTree()`, check tree depth (max 3 levels); return structured error if exceeded
- [ ] 3.2 Check all node ids are valid UUIDs; return error if invalid
- [ ] 3.3 Check for duplicate node ids across all levels; return error if found
- [ ] 3.4 Check each node has required `label` (non-empty string) and `id` (UUID); return error if missing
- [ ] 3.5 Check node `url` (if present) passes `sanitiseUrl()` validation; reject javascript:, data:, vbscript:
- [ ] 3.6 Check `groupVisibility` (if present) is either null or non-empty array of strings
- [ ] 3.7 Return HTTP 400 with detailed error message on any validation failure
- [ ] 3.8 Add PHPUnit test: valid tree passes validation
- [ ] 3.9 Add PHPUnit test: depth exceeded returns error
- [ ] 3.10 Add PHPUnit test: duplicate ids return error
- [ ] 3.11 Add PHPUnit test: javascript: url rejected
- [ ] 3.12 Add PHPUnit test: data: url rejected

## 4. Backend group filtering

- [ ] 4.1 In `OrgNavigationService::filterTreeByUserGroups()`, recursively traverse tree
- [ ] 4.2 For each node, check `groupVisibility`:
  - If null → node is visible
  - If array → check if user belongs to any group in array; if yes, visible; if no, hidden
- [ ] 4.3 If parent is hidden, children are also hidden (cascading)
- [ ] 4.4 Return filtered tree (non-visible nodes and their children removed)
- [ ] 4.5 Add PHPUnit test: tree with all-null visibility is fully visible
- [ ] 4.6 Add PHPUnit test: user in matching group sees restricted node
- [ ] 4.7 Add PHPUnit test: user not in any matching group does not see restricted node
- [ ] 4.8 Add PHPUnit test: hidden parent hides children

## 5. Frontend store

- [ ] 5.1 Create `src/stores/orgNavigation.js` (Pinia store) with state:
  - `tree`: array (current filtered tree)
  - `loading`: boolean
  - `error`: string or null
- [ ] 5.2 Implement actions:
  - `fetchTree()` — call `GET /api/admin/org-navigation`, store filtered tree in state
  - `updateTree(newTree)` — call `PUT /api/admin/org-navigation`, validate response, update state
- [ ] 5.3 Implement getters:
  - `visibleTree` — return current filtered tree
  - `isEmpty` — return true if tree is empty after filtering
  - `isLoading` — return loading state
- [ ] 5.4 On store initialisation, call `fetchTree()` to load tree on app startup
- [ ] 5.5 Add error handling: if `GET` fails, log error, set `error` state

## 6. Frontend navigation panel component

- [ ] 6.1 Create `src/components/OrgNavigationPanel.vue` (Vue 3 SFC)
- [ ] 6.2 On mount, subscribe to `orgNavigation` store and render filtered tree
- [ ] 6.3 Render each node as a list item with:
  - Icon (24 px, resolved per REQ-ONAV-006: URL → `<img>`, name → `IconRenderer`, null → no icon)
  - Label (text)
  - Children (expandable/collapsible, recursive)
  - Link (if `url` is present)
- [ ] 6.4 Implement expand/collapse state per section (tracked in component state or URL)
- [ ] 6.5 Implement active item detection: highlight node if current page URL matches or starts with node's `url`
- [ ] 6.6 Auto-expand parent if a child is active
- [ ] 6.7 On node click, navigate to `url` if present; respect `openInNewTab` (target="_blank")
- [ ] 6.8 Render tree with visual nesting: indentation and optional connecting lines
- [ ] 6.9 Add error state: if store has error, display error banner
- [ ] 6.10 Add i18n: use `$t()` for any user-visible labels
- [ ] 6.11 Add unit test: panel renders with sample tree data
- [ ] 6.12 Add unit test: clicking node navigates to url
- [ ] 6.13 Add unit test: active node receives active class

## 7. Frontend responsive layout & App.vue integration

- [ ] 7.1 In `OrgNavigationPanel.vue`, add responsive logic:
  - At viewport < 800px: render hamburger button (icon + "Navigation" label or icon only)
  - At viewport ≥ 800px: render full rail per position setting
- [ ] 7.2 Hamburger click opens drawer/slide-in overlay showing full tree
- [ ] 7.3 Drawer close button (X or back arrow) closes drawer
- [ ] 7.4 Clicking a tree node in drawer auto-closes drawer and navigates
- [ ] 7.5 In `src/App.vue`, mount `OrgNavigationPanel` as conditional rail:
  - Fetch `mydash.org_navigation_position` from store (or query backend)
  - If position = 'hidden', render nothing
  - If position = 'left', render panel in left column (flex order or CSS grid placement)
  - If position = 'right', render panel in right column
  - If position = 'top', render panel above main content (full width or constrained)
- [ ] 7.6 Do not render panel if tree is empty after filtering (REQ-ONAV-008)
- [ ] 7.7 Add CSS: hamburger button styling, drawer overlay, rail flex layout
- [ ] 7.8 Add unit test: hamburger visible on small viewport
- [ ] 7.9 Add unit test: rail visible on large viewport
- [ ] 7.10 Add unit test: drawer opens/closes correctly

## 8. Frontend admin editor component

- [ ] 8.1 Create `src/views/AdminOrgNavigationEditor.vue` (Vue 3 SFC, admin page)
- [ ] 8.2 On mount, fetch current tree from store via `orgNavigation.visibleTree` (or raw tree if admin has no filters)
- [ ] 8.3 Implement drag-and-drop tree builder:
  - Each node displays as a draggable row/card
  - Drag handle (icon or chevron area) allows reorder within parent or reparent to another parent
  - Respect depth limit: warn or disable drag if would exceed depth 3
- [ ] 8.4 Per-node controls:
  - "Edit" button → opens modal with `label`, `url`, `icon`, `openInNewTab` fields (reuse or create single-node edit form)
  - "Delete" button → remove node from tree (confirm before delete)
  - "Group visibility" button/link → opens multi-select with all NC groups, current selection checked, "Visible to everyone" toggle
  - "Add child" button (for sections) → add new child node
- [ ] 8.5 Top-level "Add section" and "Add link" buttons to add root nodes
- [ ] 8.6 Icon picker integration: in node edit modal, reuse existing link-button-widget icon picker (or create shared component)
- [ ] 8.7 Visual depth indicator: show current depth of tree, warn if approaching limit 3
- [ ] 8.8 Save button: call `orgNavigation.updateTree(tree)`, handle errors (show banner with error message, prevent save)
- [ ] 8.9 On save success, show confirmation toast/banner
- [ ] 8.10 Add i18n: all labels, buttons, error messages use `$t()`
- [ ] 8.11 Add unit test: drag reorder at same level
- [ ] 8.12 Add unit test: edit node modal opens and saves changes
- [ ] 8.13 Add unit test: delete node removes from tree
- [ ] 8.14 Add unit test: add child creates new node under parent
- [ ] 8.15 Add integration test: user edits tree with multiple changes, saves, tree persists

## 9. Backend position setting

- [ ] 9.1 In admin editor, add a "Position" dropdown/tab set with options: Left, Right, Top, Hidden
- [ ] 9.2 On change, call a new endpoint or store method to update `mydash.org_navigation_position`
- [ ] 9.3 Endpoint: `PUT /api/admin/org-navigation/position` (admin-only) — accept JSON `{ "position": "left"|"right"|"top"|"hidden" }`, validate, persist
- [ ] 9.4 Add PHPUnit test: admin can change position
- [ ] 9.5 Add PHPUnit test: position defaults to 'hidden'

## 10. Frontend position integration

- [ ] 10.1 In `src/App.vue`, fetch `mydash.org_navigation_position` from backend on app init or from store
- [ ] 10.2 Pass position as prop to `OrgNavigationPanel` or use it in conditional layout logic
- [ ] 10.3 Re-render panel position when setting changes (e.g., after admin saves new position)
- [ ] 10.4 Test: changing position in admin editor updates panel position in real-time (with page refresh or reactive store)

## 11. Icon resolution

- [ ] 11.1 In `OrgNavigationService`, add icon resolution logic (or use existing shared logic if available):
  - URL (starts with `/` or `http`) → return as-is for `<img src>`
  - Bare name → return as-is for `IconRenderer` component
  - Null/empty → return null
- [ ] 11.2 In `OrgNavigationPanel.vue`, render icon:
  - If `icon.startsWith('/')` or `icon.startsWith('http')` → `<img :src="icon" class="icon" style="width: 24px; height: 24px">`
  - Else → `<IconRenderer :name="icon" :size="24">`
  - Else (null) → nothing

## 12. Nextcloud group integration

- [ ] 12.1 Query Nextcloud's group service (`\OCP\IGroupManager`) to get list of all groups
- [ ] 12.2 In admin editor, populate "Group visibility" multi-select with full list of groups
- [ ] 12.3 When saving tree, retrieve user's groups (`\OCP\IGroupManager::getUserGroupIds()`) to validate group-visibility filters

## 13. Internationalization

- [ ] 13.1 Create or update translation files:
  - `l10n/en.json` — English translations
  - `l10n/nl.json` — Dutch translations
- [ ] 13.2 Add i18n keys for:
  - Admin page title: `onav_admin_title` → "Organization Navigation"
  - Buttons: `onav_add_section`, `onav_add_link`, `onav_edit`, `onav_delete`, `onav_save`, etc.
  - Labels: `onav_position`, `onav_group_visibility`, `onav_visible_to_all`, etc.
  - Mobile: `onav_mobile_menu` → "Navigation"
  - Error messages: `onav_error_depth_exceeded`, `onav_error_url_scheme`, `onav_error_label_required`, etc.
- [ ] 13.3 Use `$t()` in all Vue components and PHP error messages (via Nextcloud's i18n API)
- [ ] 13.4 Add unit test: Dutch translation loads correctly

## 14. Security and validation edge cases

- [ ] 14.1 Test case: admin tries to save tree with > 64 KB JSON (setting storage limit)
- [ ] 14.2 Test case: tree with very deep nesting (level 100+) is rejected
- [ ] 14.3 Test case: tree with thousands of nodes is accepted and filtered correctly
- [ ] 14.4 Test case: url with query string and fragment (`/path?foo=bar#section`) is accepted
- [ ] 14.5 Test case: url with encoded characters (`/path%20with%20spaces`) is accepted
- [ ] 14.6 Test case: empty label on node is rejected
- [ ] 14.7 Test case: duplicate uuids are detected across multiple levels
- [ ] 14.8 Test case: groupVisibility with non-existent group ids is accepted (graceful: those groups simply match no users)

## 15. E2E Playwright tests

- [ ] 15.1 Admin creates a navigation tree with 2 sections and 3 child links, saves successfully
- [ ] 15.2 Admin changes position to 'left', verifies panel appears on left in main app
- [ ] 15.3 Admin sets group visibility on a section to 'marketing' only
- [ ] 15.4 Non-marketing user logs in, verifies that section is not visible in panel
- [ ] 15.5 Marketing user logs in, verifies that section is visible in panel
- [ ] 15.6 User clicks a link in the panel, verifies navigation to the correct URL
- [ ] 15.7 Mobile viewport: hamburger button is visible, clicking opens drawer, selecting a link closes drawer
- [ ] 15.8 Admin drags a node to reorder, saves, verifies order persists after reload
- [ ] 15.9 Active item detection: user navigates to `/apps/mydash/dashboards/sales`, verifies the matching node is highlighted
- [ ] 15.10 Admin deletes a node, saves, verifies it no longer appears

## 16. Quality gates

- [ ] 16.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 16.2 ESLint + Stylelint clean on all Vue/JS files
- [ ] 16.3 Test coverage for new code ≥ 75% (PHPUnit + Playwright combined)
- [ ] 16.4 SPDX-License-Identifier header added to all new PHP files (inside docblock)
- [ ] 16.5 No console warnings or errors in browser on main app
- [ ] 16.6 i18n keys for all user-facing strings (Dutch + English)
- [ ] 16.7 Accessibility: panel is navigable via keyboard (Tab, Enter, Arrow keys)
- [ ] 16.8 Accessibility: panel has proper ARIA labels and roles (navigation, menuitem, etc.)
- [ ] 16.9 Run all hydra-gates locally before opening PR

## 17. Documentation

- [ ] 17.1 Update or create admin documentation explaining how to configure org navigation
- [ ] 17.2 Add a note in the changelog: "Added organization-wide navigation editor for admin-curated tree of links and sections with group visibility support"
- [ ] 17.3 Document the API endpoints: `GET /api/admin/org-navigation`, `PUT /api/admin/org-navigation`
- [ ] 17.4 Document the global settings: `mydash.org_navigation_tree`, `mydash.org_navigation_position`
- [ ] 17.5 Add screenshot or animation to docs showing the admin editor UI and the rendered panel
