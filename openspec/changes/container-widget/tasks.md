# Tasks — container-widget

## Tasks

- [ ] Task 1: Create `src/constants/containerDefaults.js` exporting `CONTAINER_DEFAULTS`, `INNER_GRID_CONFIG` with constants: `column: 4`, `cellHeight: 40`, `margin: 4`, `disableOneColumnMode: true`; and default content shape: `{placements: [], backgroundColor: 'transparent', padding: 'medium', title: ''}`

- [ ] Task 2: Create `src/composables/useNestedGridManager.js` — a wrapper around `useGridManager` that initialises a GridStack instance with the inner-grid constants from `CONTAINER_DEFAULTS.INNER_GRID_CONFIG`; exports `useNestedGridManager(containerElement)` returning the grid instance

- [ ] Task 3: Create `src/components/widgets/Container.vue` rendering the container structure: outer wrapper with `backgroundColor`, optional title heading, and inner `<div>` for the GridStack instance; template branches on edit vs. view mode (edit shows draggable inner grid, view shows non-interactive children); dispatches children via the widget registry (REQ-CONT-002, REQ-CONT-003, REQ-CONT-004)

- [ ] Task 4: Implement `Container.vue` edit-mode logic: initialise the inner GridStack via `useNestedGridManager`, wire up add-widget/remove/move/resize affordances (same as outer grid), ensure mutations only affect `content.placements[]` and do NOT cascade to the outer grid (REQ-CONT-005)

- [ ] Task 5: Implement `Container.vue` view-mode logic: render children without GridStack interaction, ensure clicks on children pass through to their handlers, render background colour and optional title (REQ-CONT-004)

- [ ] Task 6: Create `src/components/forms/ContainerForm.vue` collecting three form fields: `backgroundColor` (NcColorPicker, default `'transparent'`), `padding` (NcSelect with options `'none' | 'small' | 'medium' | 'large'`, default `'medium'`), `title` (NcTextField, default `''`); template MUST NOT include child-management UI (REQ-CONT-007)

- [ ] Task 7: Register `container` widget type in the widget registry (`src/constants/widgets.js` or equivalent) with `type: 'container'`, `defaultContent` from `CONTAINER_DEFAULTS`, `component: Container`, `form: ContainerForm`, and ensure it appears in the Add Custom Widget picker (REQ-CONT-001)

- [ ] Task 8: Create `lib/Service/WidgetPlacementService.php` method `validateContainerDepth(array $content, int $depth = 0)` that walks `$content['placements'][]` recursively; throws `InvalidArgumentException('container_depth_exceeded')` when a fourth nested container would be created; called from `addWidget` and `updatePlacement` endpoints (REQ-CONT-006)

- [ ] Task 9: Integrate `validateContainerDepth` into the widget controller's `POST /api/dashboards/{uuid}/widgets` and `PUT /api/dashboards/{uuid}/widgets/{id}` handlers; catch the exception and return HTTP 400 with envelope `{status: 'error', error: 'container_depth_exceeded', maxDepth: 3}` (REQ-CONT-006)

- [ ] Task 10: Vitest — `useNestedGridManager` returns a GridStack instance with correct config (column 4, cellHeight 40, margin 4, disableOneColumnMode true); Container.vue edit-mode adds/removes/moves children without affecting outer grid siblings; Container.vue view-mode click delegation passes through to child handlers; form submission populates content fields correctly

- [ ] Task 11: Vitest — depth validation rejects 4+ nested containers and accepts depth 3; validation is recursive and checks all nested levels; invalid payloads do not insert rows

- [ ] Task 12: E2E test — add a container to a dashboard, edit it (add children, move children, apply background colour + title), save, and verify view-mode rendering and click delegation; verify container appears in the widget picker

- [ ] Task 13: E2E test — attempt to add a fourth-level nested container; verify the API rejects with HTTP 400 and the frontend shows an error message

- [ ] Task 14: Visual snapshot (Storybook or component preview) of Container.vue in edit and view modes with seed data (empty, single child, multiple children, nested container)

- [ ] Task 15: Padding enum mapping — define CSS class names or margin values for `'none' | 'small' | 'medium' | 'large'` (e.g., `none: 0px`, `small: 8px`, `medium: 16px`, `large: 24px`) applied to the inner grid wrapper

- [ ] Task 16: Quality gates — ESLint clean on new components/composables/services; PHP CodeSniffer clean on `WidgetPlacementService.php`; TypeScript (if applicable) type safety on Container.vue and forms; production bundle size impact < 5 KB gzipped

- [ ] Task 17: Documentation — PHPDoc on `WidgetPlacementService::validateContainerDepth()` explaining the 3-level limit and exception; changelog entry covering container widget introduction, nesting limits, and form fields

## Verification

`openspec validate` exits clean. Container widget is selectable in the picker. Container renders and is editable in edit mode. Inner grid remains independent of outer grid. View-mode click delegation works. Depth validation rejects 4+ levels.

## Tests (company-wide ADR-008)

Vitest per Tasks 10-11; E2E per Tasks 12-13; visual snapshot per Task 14. Backend unit tests for depth validation (Task 11).

## Documentation (company-wide ADR-009)

PHPDoc on `WidgetPlacementService` per Task 17; changelog entry per Task 17. No user-facing strings beyond form labels (backgroundColor, padding, title — already i18n keys if using Nextcloud conventions).

## i18n (company-wide ADR-007)

Form labels for backgroundColor, padding, title should be i18n-wrapped. Padding enum values (`'none' | 'small' | 'medium' | 'large'`) are technical enum names, not user-visible labels — if user-visible labels are needed, add an i18n map in ContainerForm.vue.
