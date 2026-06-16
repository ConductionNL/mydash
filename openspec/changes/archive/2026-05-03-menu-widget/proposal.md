# Menu widget

## Why

MyDash dashboards need a flexible, hierarchical navigation widget to surface contextual links. Users should be able to publish "links to other places" as a structured menu (up to 3 levels deep) with multiple visual styles — dropdown, megamenu, or tree. This is distinct from the application-chrome navigation. The widget must support both internal navigation (same-page routes) and external URLs, with live active-item highlighting based on the current page URL. The widget MUST be fully static (no backend API calls) with server-side validation of nesting depth.

## What Changes

- Introduce a new widget `type: 'menu'` registered in `widgetRegistry.js` with default content `{items: [], style: 'dropdown', orientation: 'horizontal', showIcons: true, expandedByDefault: false, activeItemHighlight: 'underline'}`.
- Add a strict JSON schema for menu items: 3-level nesting maximum (top-level + 2 child levels), each item has `label`, optional `url`, optional `icon`, optional `children` array.
- Implement renderer `MenuWidget.vue` with three visual styles:
  - `dropdown`: top-level items in a horizontal bar; level-2 in a popover on hover/focus; level-3 in a flyout to the side
  - `megamenu`: top-level in horizontal bar; opening any one shows a full-width panel with all level-2 items grouped and level-3 inline
  - `tree`: vertical hierarchical list with expand/collapse carets
- Add active-item detection: any item whose URL matches the current page URL is highlighted; ancestors are highlighted as "in current path".
- Keyboard navigation: Tab through top-level; arrow keys open and traverse children; Esc closes dropdowns. Conform to WAI-ARIA Menu/Menubar or Disclosure patterns.
- External URL handling: links to external hosts open in a new tab with `rel="noopener noreferrer"`.
- Edit form: drag-and-drop tree editor; max-depth validation indicator; per-item icon picker (reuse `link-button-widget`'s icon resolution from REQ-LBN-002).
- Empty state: "No menu items yet — click the gear icon to add some."
- Server-side depth validation: HTTP 400 if any item nests deeper than 3 levels on placement save.

## Capabilities

### New Capabilities

- `menu-widget` — adds REQ-MENU-001 (widget registration), REQ-MENU-002 (config schema & depth validation), REQ-MENU-003 (dropdown style), REQ-MENU-004 (megamenu style), REQ-MENU-005 (tree style), REQ-MENU-006 (active item detection), REQ-MENU-007 (keyboard navigation), REQ-MENU-008 (external link handling), REQ-MENU-009 (edit form), REQ-MENU-010 (icon resolution), REQ-MENU-011 (empty state).

### Modified Capabilities

(none — this change is fully additive)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/MenuWidget.vue` — new renderer supporting dropdown, megamenu, and tree styles
- `src/components/Widgets/Forms/MenuForm.vue` — new add/edit sub-form with drag-and-drop tree editor and depth validation
- `src/constants/widgetRegistry.js` — register `type: 'menu'` with default content shape
- `lib/Service/WidgetService.php` — server-side depth validation in `validateWidgetContent()` before placement save
- `l10n/en.js`, `l10n/nl.js` — translation strings for all visible labels and empty state

**Affected APIs:**

- 0 new routes — fully static rendering via existing placement API

**Dependencies:**

- No new composer or npm dependencies; uses existing Nextcloud CSS variables and Vue 3 SFC composables

**Migration:**

- Zero schema changes — menu shape lives inside the existing `content` JSON blob on widget placements.
- Existing dashboards continue to work unchanged; the `menu` type only appears once an admin explicitly adds a Menu widget.
