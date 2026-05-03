# Tasks — container-widget

## 1. Renderer

- [ ] 1.1 Create `src/components/Widgets/Renderers/ContainerWidget.vue`
- [ ] 1.2 Renderer accepts `content: {placements: WidgetPlacement[], backgroundColor?: string, padding?: string, title?: string}`
- [ ] 1.3 Renders an inner `<div ref="innerGrid" class="grid-stack mydash-container-grid">` with one child element per `content.placements[]`
- [ ] 1.4 Each child element wraps `<WidgetRenderer :placement="child">` (recursive — same component tree, different placement input)
- [ ] 1.5 On mount, init a nested GridStack via `useNestedGridManager(innerGrid, content.placements)`
- [ ] 1.6 In view mode: container is non-interactive (clicks fall through to children); only background renders
- [ ] 1.7 In edit mode: container's inner grid becomes editable independently of outer grid (drag/resize child widgets within the container)
- [ ] 1.8 Cleanup: destroy inner GridStack on `beforeDestroy`

## 2. Form

- [ ] 2.1 Create `src/components/Widgets/Forms/ContainerForm.vue`
- [ ] 2.2 Three fields: backgroundColor (NcColorPicker), padding (NcSelect: none/small/medium/large), title (optional NcTextField)
- [ ] 2.3 The form does NOT manage children — children are added via the inner-grid's own add-widget flow when the container is rendered in edit mode
- [ ] 2.4 `validate()` returns no errors (all fields optional)

## 3. Nested grid composable

- [ ] 3.1 Create `src/composables/useNestedGridManager.js`
- [ ] 3.2 Wraps GridStack init with these constants: `column: 4`, `cellHeight: 40`, `margin: 4`, `acceptWidgets: true`, `disableOneColumnMode: true`
- [ ] 3.3 `placeNewWidget(spec, placements, options)` — same helper as outer grid but bounded to 4 cols
- [ ] 3.4 Persist placement changes to the parent container's `content.placements[]` (call back into the parent's update path)

## 4. Recursion-depth invariant

- [ ] 4.1 Add `lib/Service/WidgetPlacementService.php::validateContainerDepth(array $content, int $depth = 0): void` — throws `InvalidArgumentException` when `$depth > 3` AND any `placements[]` child is also a container
- [ ] 4.2 Wire `validateContainerDepth` into POST/PUT widget placement controller methods
- [ ] 4.3 On violation, controller returns HTTP 400 with `{status: 'error', error: 'container_depth_exceeded', maxDepth: 3}`

## 5. Registry

- [ ] 5.1 Add `container` entry to `src/constants/widgetRegistry.js` with renderer, form, defaultContent `{placements: [], backgroundColor: 'transparent', padding: 'medium', title: ''}`, displayName `t('mydash', 'Container')`, icon `ViewDashboard` (or similar)
- [ ] 5.2 Update `widget-registry-completeness` EXPECTED_TYPES to include `container` (this proposal lands AFTER `widget-registry-completeness` so the test will fail if EXPECTED_TYPES isn't updated in this commit)

## 6. Tests

- [ ] 6.1 Vitest: `ContainerWidget.spec.js` — renders inner grid; one child element per placement; recursive WidgetRenderer used; cleanup destroys inner GridStack
- [ ] 6.2 Vitest: `ContainerForm.spec.js` — three controls; validate() returns no errors; emits update:content
- [ ] 6.3 Vitest: `useNestedGridManager.spec.js` — placeNewWidget respects 4-col bound; persistence callback fires
- [ ] 6.4 PHPUnit: `WidgetPlacementServiceTest::testValidateContainerDepth` — depth 0/1/2/3 OK; depth 4 throws; correct error envelope on controller side
- [ ] 6.5 Vitest: `widgetRegistry.completeness.spec.js` — `container` is in EXPECTED_TYPES (asserted after the registry add)

## 7. i18n

- [ ] 7.1 Add `Container`, `Background`, `Padding`, `None`, `Small`, `Medium`, `Large`, `Title (optional)`, `Container nesting limit reached` to `l10n/{en,nl}.{js,json}` (4 files)

## 8. Quality gates

- [ ] 8.1 `composer check:strict` clean
- [ ] 8.2 `npm test` clean (incl. completeness test post-EXPECTED_TYPES update)
- [ ] 8.3 `npm run build` clean
- [ ] 8.4 `openspec validate --all --strict` 0 failed
