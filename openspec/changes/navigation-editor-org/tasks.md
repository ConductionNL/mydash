# Tasks — navigation-editor-org

## Backend Service Layer

- [ ] Task 1: Create `lib/Service/OrgNavigationService.php` with dependency injection for `IAppData`, `IGroupManager`, and `IAppConfig`
- [ ] Task 2: Implement `OrgNavigationService::getTree(string $lang)` — reads JSON from `IAppData('mydash')/org-navigation/{$lang}.json`, decodes, returns array or empty array if missing (REQ-ONAV-001)
- [ ] Task 3: Implement `OrgNavigationService::setTree(array $tree, string $lang)` — validates tree (see Task 4), writes to file, returns validated tree (REQ-ONAV-003)
- [ ] Task 4: Implement `OrgNavigationService::validateTree(array $tree)` — checks depth ≤3, all node IDs are valid UUIDs (v1..v5), no duplicate IDs, all nodes have non-empty `label`, all `url` fields pass sanitisation (Task 5), all `groupVisibility` fields are null or non-empty arrays (REQ-ONAV-003)
- [ ] Task 5: Implement `OrgNavigationService::sanitiseUrl(?string $url)` — rejects `javascript:`, `data:`, `vbscript:` schemes (case-insensitive), allows all others (REQ-ONAV-011)
- [ ] Task 6: Implement `OrgNavigationService::filterTreeByUserGroups(array $tree, ?string $userId)` — recursive filter that removes nodes hidden by `groupVisibility`, cascades parent-hidden to children, returns filtered tree; uses `IGroupManager::getUserGroupIds()` (REQ-ONAV-002, REQ-ONAV-008)
- [ ] Task 7: Implement position getter/setter in `OrgNavigationService` — `getPosition()` reads from `IAppConfig`, defaults to `'hidden'`; `setPosition(string $position)` validates enum and writes (REQ-ONAV-004)
- [ ] Task 8: Unit tests for `OrgNavigationService` — tree persists with valid JSON, max depth enforcement, duplicate ID detection, UUID validation, URL scheme rejection, group filtering with cascading, position setting defaults and persistence

## Backend Controller Layer

- [ ] Task 9: Create `lib/Controller/AdminOrgNavigationController.php` extending `OCSController`
- [ ] Task 10: Implement `GET /api/admin/org-navigation?lang={nl|en}` endpoint — reads `?lang` (default `nl`), calls `getTree()`, filters via `filterTreeByUserGroups()`, returns HTTP 200 with filtered tree (REQ-ONAV-002)
- [ ] Task 11: Implement `PUT /api/admin/org-navigation?lang={nl|en}` endpoint — admin-only (check `isAdmin()`), validates request user is admin else return HTTP 403, reads `?lang`, calls `setTree()` with validation, on error return HTTP 400 with error message, on success HTTP 200 with persisted tree (REQ-ONAV-003)
- [ ] Task 12: Implement `GET /api/admin/org-navigation/position` endpoint — any logged-in user, calls `getPosition()`, returns HTTP 200 with `{position: string}` (REQ-ONAV-004)
- [ ] Task 13: Implement `PUT /api/admin/org-navigation/position` endpoint — admin-only, validates request JSON has `position` enum, calls `setPosition()`, returns HTTP 200 with new position or HTTP 400 if invalid (REQ-ONAV-004)
- [ ] Task 14: Register routes with correct ordering — `/api/admin/org-navigation/position` routes BEFORE bare `/api/admin/org-navigation` routes (so literal `position` segment matches first)
- [ ] Task 15: Unit tests for `AdminOrgNavigationController` — endpoints return correct status codes, group filtering applied to read endpoint, write requires admin role, position setting endpoints work, error messages are clear

## Frontend State Management

- [ ] Task 16: Create `src/stores/orgNavigation.js` Pinia store with state properties: `tree` (array), `language` (string, default 'nl'), `position` (string, default 'hidden'), `loading` (boolean), `error` (string|null)
- [ ] Task 17: Implement store action `fetchTree(language)` — calls `GET /api/admin/org-navigation?lang={language}`, stores result in `tree`, sets `loading` false, catches errors into `error` state
- [ ] Task 18: Implement store action `updateTree(tree, language)` — calls `PUT /api/admin/org-navigation?lang={language}` with tree payload, on success updates local `tree` and returns response, on error updates `error` state and throws
- [ ] Task 19: Implement store action `fetchPosition()` — calls `GET /api/admin/org-navigation/position`, stores `position` in state
- [ ] Task 20: Implement store action `updatePosition(newPosition)` — calls `PUT /api/admin/org-navigation/position` with `{position: newPosition}`, updates state on success
- [ ] Task 21: Implement store getters: `visibleTree` (returns `tree` filtered by user's groups; uses store context to call backend filter on read), `isEmpty` (tree length === 0), `shouldRender` (isEmpty === false AND position !== 'hidden')
- [ ] Task 22: Unit/integration tests for `orgNavigation` store — fetch tree, update tree with validation, position persistence, empty tree handling, getter reactivity

## Frontend Component: Runtime Panel

- [ ] Task 23: Create `src/components/OrgNavigationPanel.vue` Vue 2.7 SFC with props: `position` (string: 'left'|'right'|'top'|'hidden'), `isMobile` (boolean, computed from viewport width)
- [ ] Task 24: Wire Pinia store: `useOrgNavigationStore()`, fetch tree on mount (via `await store.fetchTree(store.language)`)
- [ ] Task 25: Conditional render wrapper — if `shouldRender === false`, render nothing; else render container (REQ-ONAV-008)
- [ ] Task 26: Root element CSS — `position: fixed`, top/left positioning based on `position` prop, z-index 1500, width 280px for sidebar, full width for top, CSS classes for position variant (`.org-nav--left`, `.org-nav--right`, `.org-nav--top`)
- [ ] Task 27: Mobile responsive logic — `@media (max-width: 799px)` transforms container to drawer with overlay, hamburger icon button to toggle, close button visible in drawer only (REQ-ONAV-010)
- [ ] Task 28: Render tree recursively via `<OrgNavigationItem>` for each root node in `visibleTree`, passing `v-model:expanded` object to track expand/collapse state per node
- [ ] Task 29: Drawer open/close state — tracked in component local state, hamburger click toggles `isDrawerOpen`, clicking drawer backdrop or close button sets false, drawer slide animation via CSS `transform: translateX(0 ↔ -100%)` over 0.25s
- [ ] Task 30: i18n strings — import `{ t }` from `@nextcloud/l10n`, use for "Organization navigation", "Open organization navigation", "Close navigation" labels (REQ-ONAV-012)

## Frontend Component: Recursive Node Item

- [ ] Task 31: Create `src/components/OrgNavigationItem.vue` Vue 2.7 SFC recursive component with props: `node` (object), `level` (number, depth tracking), `activeUrl` (current page URL), `expanded` (object of expanded node IDs), `onToggleExpand` (function to update parent's expanded state)
- [ ] Task 32: Render node icon per REQ-ONAV-006 — if `node.icon` starts with `/` or `http`, render `<img src="node.icon" class="org-nav-item__icon">` (24px square), else if bare name render `<span class="org-nav-icon-label">` with text, else no icon
- [ ] Task 33: Render node label as plain text or as `<a href>` if `node.url` is present — link opens per `node.openInNewTab` (target="_blank", rel="noopener noreferrer")
- [ ] Task 34: Implement active-item detection — compute `isActive` boolean by calling URL matching function (exact match OR prefix match with path-segment boundary), apply `.org-nav-item--active` class to matching node (REQ-ONAV-009)
- [ ] Task 35: Implement expand/collapse for section nodes — if `node.children.length > 0`, render expand/collapse chevron button, click toggles `expanded[node.id]`, children render only when expanded
- [ ] Task 36: Recursively render children — `<OrgNavigationItem v-for="child in node.children" :node="child" :level="level+1" :activeUrl="activeUrl" :expanded="expanded" @toggle-expand="onToggleExpand">` with indentation CSS per level
- [ ] Task 37: Auto-expand parents of active nodes — computed property `shouldExpand = isActive || hasActiveDescendant`, on mount auto-set `expanded[node.id] = true` if shouldExpand (REQ-ONAV-009)
- [ ] Task 38: CSS classes and styling — `.org-nav-item` container, `.org-nav-item--active` (highlight current), `.org-nav-item--has-active-child` (optional), `.org-nav-item__indent` per level (16px or similar), `.org-nav-item__label` for text, `.org-nav-item__icon` for icon
- [ ] Task 39: Unit tests for `OrgNavigationItem` — icon rendering (URL, name, null), link behavior, expand/collapse, active-state detection, recursive rendering, URL matching logic (exact, prefix-with-boundary, no-match)

## Frontend Component: Admin Editor (Main)

- [ ] Task 40: Create `src/components/admin/OrgNavigationEditor.vue` Vue 2.7 SFC mounted in admin section
- [ ] Task 41: Wire Pinia store: `useOrgNavigationStore()`, fetch tree on mount for the selected language
- [ ] Task 42: State properties — `workingTree` (local copy for editing), `selectedLanguage` (default 'nl'), `selectedPosition` (sync with store), `editingNodeId` (which node is being edited inline), `validationErrors` (array of error messages), `showSuccessBanner` (transient)
- [ ] Task 43: Language selector dropdown — options for 'nl' and 'en', on change call `store.fetchTree(newLanguage)` and set `workingTree = fetched tree`, reset editing state
- [ ] Task 44: Position selector dropdown — options for 'Hidden', 'Left', 'Right', 'Top', on change call `store.updatePosition()` and update local state
- [ ] Task 45: Tree builder UI — render `<OrgNavigationEditorRow>` for each root node in `workingTree`, pass `node` and `level=0` and callbacks for add/edit/delete/reorder
- [ ] Task 46: Add buttons at root level — "Add section" (creates node with `url=null`, `label="New section"`, `children=[]`, auto-generated UUID), "Add link" (creates node with `url="/"`, `label="New link"`, auto-generated UUID)
- [ ] Task 47: Save button — calls `store.updateTree(workingTree, selectedLanguage)`, catches validation errors and displays in `validationErrors` array, on success shows `showSuccessBanner` for 3 seconds then auto-hide, on error displays error messages below tree
- [ ] Task 48: Empty state — if `workingTree.length === 0`, show message "No organization navigation configured. Start by adding a section or link." with button to "Add first section"
- [ ] Task 49: i18n strings — "Organization navigation", "Add section", "Add link", "Save", "Language", "Position", "Hidden", "Left", "Right", "Top" (REQ-ONAV-012)
- [ ] Task 50: Unit tests for `OrgNavigationEditor` — language switching reloads tree, position selector updates position, save with valid tree, save with validation errors, add section/link buttons, empty state display

## Frontend Component: Admin Editor (Row)

- [ ] Task 51: Create `src/components/admin/OrgNavigationEditorRow.vue` Vue 2.7 SFC recursive component with props: `node` (object), `level` (number), `parentChildren` (array, reference to parent's children array for reordering), `allGroups` (array of group objects from Nextcloud), callbacks: `@add-child`, `@delete-node`, `@update-node`, `@move-up`, `@move-down`
- [ ] Task 52: Inline editing fields — `label` (text input), `url` (text input or null toggle for sections), `icon` (text input for bare name, OR URL input, detected by / or http prefix), `openInNewTab` (checkbox, only if url is present)
- [ ] Task 53: Group visibility selector — "Visible to everyone" toggle; if unchecked, multi-select dropdown populated from `allGroups`, showing pre-selected groups, option to switch to comma-separated text input as fallback
- [ ] Task 54: Move-up / Move-down buttons — reorder node within `parentChildren` array, disabled if node is first (up) or last (down), emits `@move-up` and `@move-down` (REQ-ONAV-007)
- [ ] Task 55: Delete button — emits `@delete-node`, no confirmation dialog (spec does not require it)
- [ ] Task 56: Add child button — disabled if `level >= 2` (depth limit 3), tooltip text "Tree depth cannot exceed 3 levels" (REQ-ONAV-007), click emits `@add-child` with new node
- [ ] Task 57: Recursive children rendering — render child rows indented, passing depth `level+1`, with same callbacks
- [ ] Task 58: CSS classes and styling — `.org-nav-editor-row` container, `.org-nav-editor-row--level-{level}` for indentation, `.org-nav-editor-row__field` for each input, buttons have clear icons/labels
- [ ] Task 59: Unit tests for `OrgNavigationEditorRow` — inline editing of label/url/icon, group visibility multi-select, move-up/down logic, delete propagation, add-child enforcement of depth limit, recursive rendering

## Frontend Wiring

- [ ] Task 60: Mount `<OrgNavigationPanel>` in `src/views/WorkspaceApp.vue` alongside `<DashboardSwitcherSidebar>`, wire `position` prop from store, responsive `isMobile` computed property from window.innerWidth < 800
- [ ] Task 61: Mount `<OrgNavigationEditor>` in `src/views/AdminSettings.vue` as a tab or section within admin area
- [ ] Task 62: Fetch `allGroups` list from Nextcloud and inject into `OrgNavigationEditor` (via prop or store mutation after backend call)

## Internationalization

- [ ] Task 63: Create `l10n/nl.json` with all Dutch translations — section/link labels, button labels ("Toevoegen sectie", "Link toevoegen", "Opslaan", "Verplaatsen omhoog", "Verplaatsen omlaag", "Zichtbaar voor iedereen", "Groepen"), error messages ("Boom diepte kan niet meer dan 3 niveaus", "URL schema niet toegestaan")
- [ ] Task 64: Create `l10n/en.json` with all English translations
- [ ] Task 65: Create `l10n/nl.js` and `l10n/en.js` (if needed by Nextcloud setup) — auto-generated or manual registration
- [ ] Task 66: Import `{ t }` from `@nextcloud/l10n` in all Vue components, use `t('string_key')` for all user-visible text
- [ ] Task 67: Unit tests for i18n — Dutch and English strings present, no untranslated keys in UI

## Deduplication Check

- [ ] Task 68: Search `openspec/specs/` and existing `lib/Service/` files for overlap with tree/navigation CRUD — verify no duplication with `admin-templates`, `dashboard-switcher-sidebar`, or other existing services
- [ ] Task 69: Document deduplication findings in task comments or separate analysis file

## Quality Assurance & Verification

- [ ] Task 70: ESLint clean — backend PHP (PSR-12) and frontend JS (Vue/ESLint)
- [ ] Task 71: Stylelint clean — all Vue component styles
- [ ] Task 72: Type checking — Vue components use JSDoc or TypeScript comments for props/emits
- [ ] Task 73: Accessibility — keyboard navigation (Tab through tree items, Esc to close drawer), ARIA labels on buttons, focus management in admin editor, screen-reader announcements for state changes
- [ ] Task 74: Browser testing — desktop (Chrome, Firefox, Safari) and mobile (iOS Safari, Chrome Mobile) at 600px, 800px, 1200px viewports
- [ ] Task 75: Manual QA checklist:
  - [ ] Empty tree state renders no panel
  - [ ] Tree with content renders and is filterable by group
  - [ ] Admin editor can add/edit/reorder/delete nodes
  - [ ] Language switching works (nl ↔ en)
  - [ ] Position selector updates position setting
  - [ ] Mobile drawer opens/closes with hamburger
  - [ ] Active item highlighting works with URL matching
  - [ ] Depth validation prevents 4-level trees
  - [ ] URL sanitisation rejects javascript: and data: URLs
  - [ ] Group visibility filtering hides restricted nodes
- [ ] Task 76: Playwright e2e tests — open/close drawer at mobile width, switch languages in admin editor, reorder nodes, save tree with validation error, position setting persists across page reload
- [ ] Task 77: Vitest unit test coverage — all service methods, store getters/actions, icon rendering, active-state logic, URL matching, depth validation

## Documentation

- [ ] Task 78: CHANGELOG.md entry — brief description of new org-nav capability, admin editor, runtime panel, group filtering, mobile responsiveness
- [ ] Task 79: Architecture ADR or design doc (optional) — details on per-language storage, service-layer delegation, Pinia store structure, if design review produces decisions

## Verification

`openspec validate` exits clean. Admin editor loads within MyDash admin section, allows tree creation/editing, position setting reflects in runtime. Runtime panel appears with correct position, filters by group, detects active items, collapses to drawer on mobile.

## Tests (company-wide ADR-008, ADR-009)

- Backend: PHP unit tests (`OrgNavigationService`, `AdminOrgNavigationController`), integration tests (file I/O, Nextcloud APIs)
- Frontend: Vitest unit tests (store, components), Playwright e2e tests (tree editing, rendering, mobile responsiveness)

## Documentation (company-wide ADR-010)

Changelog entry covering the new org-nav feature, admin editor, and runtime panel. Inline code comments for non-obvious logic (e.g., URL matching with path-segment boundary).

## i18n (company-wide ADR-007)

`nl` (Dutch) and `en` (English) per Task 63-67. Fallback to English if Dutch translation missing.
