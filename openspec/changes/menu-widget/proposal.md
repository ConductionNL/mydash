# Menu Widget

## Why

MyDash today has no hierarchical navigation widget. Admins cannot publish curated link trees on dashboards for users to navigate internal resources (knowledge base, project management, communication channels) or third-party services. An earlier ad-hoc approach embedded navigation in static text widgets, but this is unmaintainable and offers no visual affordances (icons, highlighting, collapsing branches). This change introduces a first-class `menu` widget with three visual styles (dropdown, megamenu, tree), automatic active-item detection based on current URL, and a drag-and-drop editor for building menu hierarchies, so dashboard authors can compose navigation experiences without writing custom Vue code.

## What Changes

- Introduce a new widget `type: 'menu'` registered in `widgetRegistry.js` with default `widgetContent` shape carrying `items` (array), `style` (dropdown/megamenu/tree), `orientation` (horizontal/vertical), `showIcons` (boolean), `expandedByDefault` (boolean, tree-only), and `activeItemHighlight` (underline/background/left-bar/none).
- Implement renderer `MenuWidget.vue` dispatching visual rendering per `style` (REQ-MENU-003 dropdown, REQ-MENU-004 megamenu, REQ-MENU-005 tree), with automatic active-item detection against `window.location.pathname` (REQ-MENU-006).
- Implement three renderer sub-components: `MenuDropdown.vue`, `MenuMegamenu.vue`, `MenuTree.vue` — each with full keyboard navigation per WAI-ARIA Menu/Menubar pattern (REQ-MENU-007).
- Integrate shared `IconRenderer` for dual-mode icon resolution (MDI names or custom URLs, 16-24 px, REQ-MENU-010).
- Add external-link handling: `http://`/`https://` URLs open in new tab with `noopener,noreferrer` (REQ-MENU-008).
- Implement add/edit sub-form `MenuForm.vue` with config section (style, orientation, highlight, showIcons, expandedByDefault toggles) and drag-and-drop tree editor for building the `items` hierarchy with depth validation and per-item inline fields (REQ-MENU-009).
- Server-side depth validation: reject placements with `items` nesting > 3 levels at save time with HTTP 400 (REQ-MENU-002).
- Empty-state placeholder: "No menu items yet — click the gear icon to add some" when `items` is empty (REQ-MENU-011).
- Translation strings for all visible UI labels (English + Dutch).

## Capabilities

### New Capabilities

- `menu-widget` — adds REQ-MENU-001 (widget registration), REQ-MENU-002 (depth validation), REQ-MENU-003 (dropdown style), REQ-MENU-004 (megamenu style), REQ-MENU-005 (tree style), REQ-MENU-006 (active item detection), REQ-MENU-007 (keyboard navigation), REQ-MENU-008 (external link handling), REQ-MENU-009 (add/edit form with drag-and-drop), REQ-MENU-010 (icon resolution), REQ-MENU-011 (empty state).

### Modified Capabilities

(none — this change is fully additive)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/MenuWidget.vue` — main renderer with style dispatch
- `src/components/Widgets/Renderers/MenuDropdown.vue` — dropdown/megamenu sub-renderer
- `src/components/Widgets/Renderers/MenuMegamenu.vue` — megamenu-specific rendering and keyboard nav
- `src/components/Widgets/Renderers/MenuTree.vue` — tree-style sub-renderer with expand/collapse
- `src/components/Widgets/Forms/MenuForm.vue` — add/edit form with drag-and-drop tree editor
- `src/composables/useMenuActiveItem.js` — composable for active-item detection and ancestor highlighting
- `src/constants/widgetRegistry.js` — register `type: 'menu'` with default content shape
- `lib/Controller/WidgetController.php` or `lib/Service/WidgetValidationService.php` — add depth-validation logic called at placement save time
- `appinfo/routes.php` — ensure validation hook fires on placement save (no new route)
- `l10n/en.js`, `l10n/nl.js` — translation strings for all new labels, tooltips, error messages

**Affected APIs:**

- 0 new routes — validation is called from existing `PUT /api/dashboards/{dashboardId}/placements/{placementId}` endpoint

**Dependencies:**

- `OCP\Calendar\IManager` (already available, shared from other widgets)
- Vue 2.7 drag-and-drop libraries (e.g., `vuedraggable` if not already present; check project deps)
- No new composer or npm dependencies (reuse existing drag-drop lib if available)

**Migration:**

- Zero schema changes — widget shape lives inside the existing `content` JSON blob on widget placements.
- Existing dashboards continue to work unchanged; the `menu` type only appears once an admin explicitly adds a Menu widget.

## Risks / Trade-offs

- **Risk:** Three rendering styles + keyboard nav = significant Vue/CSS scope. → **Mitigation:** Sub-components keep each style's logic isolated; keyboard nav tested per style.
- **Risk:** Drag-and-drop tree editor adds complexity to the form; nested items are error-prone to edit. → **Mitigation:** Visual depth indicator and "max reached" messaging guide users away from invalid nesting.
- **Risk:** Active-item detection based on pathname prefix could over-match (/users matches /users/alice/profile). → **Mitigation:** Exact match first, then longest-prefix match; document expected behavior in user guide.
