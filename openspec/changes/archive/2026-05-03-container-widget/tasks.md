# Tasks — container-widget

## 1. Renderer

- [x] 1.1 Create `src/components/Widgets/Renderers/ContainerWidget.vue`
- [x] 1.2 Renderer accepts `content: {placements: WidgetPlacement[], backgroundColor?: string, padding?: string, title?: string}`
- [x] 1.3 Renders an inner `<div ref="innerGrid" class="grid-stack mydash-container-grid">` with one child element per `content.placements[]`
- [x] 1.4 Each child element wraps the registry-driven dispatcher (`ContainerChild` — looks up the placement's `type` in the same `widgetRegistry.js` used at the top level, including the `container` entry, so nesting is naturally recursive)
- [x] 1.5 On mount, init a nested GridStack via `useNestedGridManager(innerGrid, content.placements)`
- [x] 1.6 In view mode: container is non-interactive (clicks fall through to children); only background renders
- [x] 1.7 In edit mode: container's inner grid becomes editable independently of outer grid (drag/resize child widgets within the container)
- [x] 1.8 Cleanup: destroy inner GridStack on `beforeDestroy`

## 2. Form

- [x] 2.1 Create `src/components/Widgets/Forms/ContainerForm.vue`
- [x] 2.2 Three fields: backgroundColor (NcColorPicker), padding (NcSelect: none/small/medium/large), title (optional NcTextField)
- [x] 2.3 The form does NOT manage children — children are added via the inner-grid's own add-widget flow when the container is rendered in edit mode
- [x] 2.4 `validate()` returns no errors (all fields optional)

## 3. Nested grid composable

- [x] 3.1 Create `src/composables/useNestedGridManager.js`
- [x] 3.2 Wraps GridStack init with these constants: `column: 4`, `cellHeight: 40`, `margin: 4`, `acceptWidgets: true`, `disableOneColumnMode: true`
- [x] 3.3 `placeNewWidget(spec, placements, options)` — same helper as outer grid but bounded to 4 cols
- [x] 3.4 Persist placement changes to the parent container's `content.placements[]` (call back into the parent's update path)

## 4. Recursion-depth invariant

- [x] 4.1 Add `lib/Service/WidgetPlacementService.php::validateContainerDepth(array $content, int $depth = 0): void` — throws `InvalidArgumentException` when `$depth > 3` AND any `placements[]` child is also a container
- [x] 4.2 Wire `validateContainerDepth` into POST/PUT widget placement controller methods
- [x] 4.3 On violation, controller returns HTTP 400 with `{status: 'error', error: 'container_depth_exceeded', maxDepth: 3}`

## 5. Registry

- [x] 5.1 Add `container` entry to `src/constants/widgetRegistry.js` with renderer, form, defaultContent `{placements: [], backgroundColor: 'transparent', padding: 'medium', title: ''}`, displayName `t('mydash', 'Container')`, icon `ViewDashboard` (or similar)
- [~] 5.2 EXPECTED_TYPES update deferred: the `widget-registry-completeness` change has not yet landed on this branch (test file `src/constants/__tests__/widgetRegistry.completeness.spec.js` does not exist), so there is nothing to extend. Container registration is asserted via the existing `widgetRegistry.spec.js` (REQ-CONT-001 cases added).

## 6. Tests

- [x] 6.1 Vitest: `ContainerWidget.spec.js` — renders inner grid; one child element per placement; recursive WidgetRenderer used; cleanup destroys inner GridStack
- [x] 6.2 Vitest: `ContainerForm.spec.js` — three controls; validate() returns no errors; emits update:content
- [x] 6.3 Vitest: `useNestedGridManager.spec.js` — placeNewWidget respects 4-col bound; persistence callback fires
- [x] 6.4 PHPUnit: `WidgetPlacementServiceTest::testValidateContainerDepth` — depth 0/1/2/3 OK; depth 4 throws; correct error envelope on controller side
- [~] 6.5 `widgetRegistry.completeness.spec.js` does not exist yet (see 5.2) — REQ-CONT-001 registry presence is asserted via the existing `widgetRegistry.spec.js`

## 7. i18n

- [x] 7.1 Add `Container`, `Background`, `Padding`, `None`, `Small`, `Medium`, `Large`, `Title (optional)`, `Container nesting limit reached` to `l10n/{en,nl}.{js,json}` (4 files) — `Background`, `None`, `Small`, `Medium`, `Large` already existed; new keys appended

## 8. Quality gates

- [x] 8.1 `composer check:strict` clean
- [x] 8.2 `npm test` clean
- [x] 8.3 `npm run build` clean
- [x] 8.4 `openspec validate --all --strict` 0 failed for THIS change
