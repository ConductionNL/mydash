# Tasks — unified-add-widget-flow

## 1. Tile widget type — renderer

- [ ] 1.1 Create `src/components/Widgets/Renderers/TileWidget.vue` — renders icon + title + colors + click→link, mirroring the existing tile placement renderer
- [ ] 1.2 Renderer accepts BOTH legacy `placement.tile* ` fields AND new `placement.content.{title, icon, iconType, backgroundColor, textColor, linkType, linkValue}` shape (forward compat for existing rows)
- [ ] 1.3 Click handler: when `linkType === 'app'`, navigate to NC app route via `window.location.href = generateUrl(linkValue)`; when `linkType === 'url'`, `window.open(linkValue, '_blank', 'noopener,noreferrer')`
- [ ] 1.4 Suppress click in admin/edit mode (per REQ-WDG-014 close discipline)

## 2. Tile widget type — form

- [ ] 2.1 Create `src/components/Widgets/Forms/TileForm.vue` — six fields (title, icon + iconType, backgroundColor, textColor, linkType, linkValue) lifted from existing tile-creation modal
- [ ] 2.2 Use `IconPicker` (from `dashboard-icons`) for the icon field, supporting all 4 iconType values (class, url, emoji, svg)
- [ ] 2.3 `validate()` returns errors for missing title or empty linkValue
- [ ] 2.4 Wire form input events to `$emit('update:content', {...})`

## 3. Registry entry

- [ ] 3.1 Add `tile` entry to `src/constants/widgetRegistry.js` with `renderer: TileWidget, form: TileForm, defaultContent: {title:'', icon:'', iconType:'class', backgroundColor:'#3b82f6', textColor:'#ffffff', linkType:'app', linkValue:''}, displayName: t('mydash', 'Tile'), icon: 'ViewGrid'`
- [ ] 3.2 Verify `listWidgetTypes(t)` now returns the tile type alongside label/text/image/link/nc-widget

## 4. Remove old tile creation flow

- [ ] 4.1 Delete `src/components/AddTileModal.vue` (or whatever the standalone tile-creation modal is named)
- [ ] 4.2 Remove "Add tile…" menu entry from `DashboardConfigMenu.vue` (also covered by `runtime-shell-trim` task 4.1)
- [ ] 4.3 Remove "Add widget…" menu entry (also covered by `runtime-shell-trim` task 4.2) — the picker now lives inside "Add custom widget"

## 5. Deprecate TileService write methods

- [ ] 5.1 Mark `lib/Service/TileService.php::createTile/updateTile/deleteTile` with `@deprecated 1.0 — use widget placement flow instead`
- [ ] 5.2 In `lib/Controller/TileController.php`, change POST/PUT/DELETE handlers to return HTTP 410 Gone with `{status: 'gone', message, replacement}` payload
- [ ] 5.3 Keep GET endpoints functional (read-only) so admin tooling can still inspect existing rows
- [ ] 5.4 Update PHPUnit tests for TileController — assert 410 response shape

## 6. Tests

- [ ] 6.1 Vitest: `TileWidget.spec.js` — renders title + icon + colors; click for both linkTypes; click suppressed in edit mode; legacy + new content shapes both render
- [ ] 6.2 Vitest: `TileForm.spec.js` — six controls render, validate() catches missing title + linkValue, IconPicker integration
- [ ] 6.3 Vitest: `widgetRegistry.spec.js` — new test asserts `tile` entry exists with the documented defaultContent
- [ ] 6.4 PHPUnit: `TileControllerTest` — POST/PUT/DELETE return 410 Gone with the correct envelope; GET still returns existing rows
- [ ] 6.5 Playwright spec: open the unified Add Custom Widget modal, pick "Tile", fill the form, save → tile appears on the dashboard with correct icon + colors

## 7. i18n

- [ ] 7.1 Add `Tile` (display name) + form labels to `l10n/{en,nl}.{js,json}` if not already present
- [ ] 7.2 Add the 410-Gone error message and replacement-pointer copy to en/nl

## 8. Quality gates

- [ ] 8.1 `composer check:strict` clean (TileService deprecation tags don't trigger PHPMD)
- [ ] 8.2 `npm test` clean
- [ ] 8.3 `npm run build` clean
- [ ] 8.4 `openspec validate --all --strict` 0 failed

## 9. Rollback plan

- [ ] 9.1 If a customer reports breakage on tile placements: the renderer's dual-shape support means rollback is limited to re-enabling the menu entries — the data is intact in `oc_mydash_widget_placements`. Document this in DEVELOPMENT.md.
