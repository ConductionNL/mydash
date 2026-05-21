---
capability: menu-widget
delta: true
status: draft
---

# Menu Widget — Delta from change `menu-widget`

## ADDED Requirements

### Requirement: REQ-MENU-001 Widget registration and default structure

The system MUST register a widget `type: 'menu'` in `widgetRegistry.js` with default `widgetContent` shape:

```json
{
  "items": [],
  "style": "dropdown",
  "orientation": "horizontal",
  "showIcons": true,
  "expandedByDefault": false,
  "activeItemHighlight": "underline"
}
```

The `items` array MUST support objects with:
- `label` (string, required): display text
- `url` (string, optional): destination URL (internal route or external URL)
- `icon` (string, optional): icon identifier (MDI name or URL, follows REQ-MENU-010)
- `children` (array of items, optional): up to 2 levels of nesting allowed

#### Scenario: Widget registers with default config

- GIVEN the widget system initializes
- WHEN a dashboard creator opens the Add Widget modal
- THEN the menu widget MUST appear in the widget picker
- AND selecting it MUST populate `widgetContent` with the default shape above

#### Scenario: Hierarchical item structure

- GIVEN content `{items: [{label: 'Parent', url: 'https://example.com', children: [{label: 'Child', url: '/route'}]}]}`
- WHEN the widget renders
- THEN the parent and child items MUST both display
- AND the child MUST be nested under the parent visually and in the DOM tree

### Requirement: REQ-MENU-002 Config schema and depth validation

The `items` array MUST allow up to 3 levels of nesting (top-level + 2 child levels). Any item with `children` deeper than 2 levels MUST be rejected at placement-save time with HTTP 400 `{error: 'Menu items can nest at most 3 levels deep'}`.

Server-side validation MUST recursively check all items in the saved placement's `widgetContent.items` and reject the placement if depth > 3.

Valid field values:
- `style` ∈ {`'dropdown'`, `'megamenu'`, `'tree'`} (default `'dropdown'`)
- `orientation` ∈ {`'horizontal'`, `'vertical'`} (default `'horizontal'` for dropdown/megamenu; forced `'vertical'` for tree)
- `showIcons` ∈ {`true`, `false`} (default `true`)
- `expandedByDefault` ∈ {`true`, `false`} (default `false`; only meaningful for tree style)
- `activeItemHighlight` ∈ {`'background'`, `'underline'`, `'left-bar'`, `'none'`} (default `'underline'`)

#### Scenario: Depth validation rejects 4-level nesting

- GIVEN placement content with `items: [{label: 'L1', children: [{label: 'L2', children: [{label: 'L3', children: [{label: 'L4'}]}]}]}]`
- WHEN the user saves the placement
- THEN the API MUST return HTTP 400 with `{error: 'Menu items can nest at most 3 levels deep'}`
- AND the placement MUST NOT be saved

#### Scenario: Three-level nesting is valid

- GIVEN the same structure but with `children` at L3 empty or absent
- WHEN the user saves
- THEN the placement MUST save successfully

#### Scenario: Invalid style value rejected in form validation

- GIVEN the form has `style: 'invalid-style'` before save
- WHEN the form validates
- THEN it MUST reject the submission and show an error
- NOTE: Schema enforcement prevents invalid styles at the API level; form must also catch it client-side

### Requirement: REQ-MENU-003 Dropdown style

When `style === 'dropdown'`, the renderer MUST:

- Render top-level items in a horizontal bar (or vertical if `orientation === 'vertical'`).
- Open a popover dropdown below (or beside, depending on space) when a top-level item with children is clicked or focused.
- Render level-2 items in the dropdown as clickable; clicking MUST navigate to their `url` or expand their children in a flyout to the right.
- Render level-3 items inline under their parent level-2 item in the flyout.
- Close the dropdown and flyout when Esc is pressed.
- Close dropdowns and move focus to the next top-level item when Tab is pressed.

#### Scenario: Dropdown opens on click

- GIVEN content `{style: 'dropdown', items: [{label: 'Menu', children: [{label: 'Item 1', url: '/path1'}]}]}`
- WHEN the user clicks the "Menu" item
- THEN a dropdown MUST appear below it
- AND "Item 1" MUST be visible and clickable inside

#### Scenario: Level-2 with children opens flyout

- GIVEN the same structure but Item 1 has `children: [{label: 'Sub', url: '/sub'}]`
- AND the user has clicked "Menu" to open the main dropdown
- WHEN the user hovers over or focuses "Item 1"
- THEN a flyout MUST appear to the right
- AND "Sub" MUST be visible inside

#### Scenario: Esc closes dropdown and flyout

- GIVEN the dropdown and flyout are open
- WHEN the user presses Esc
- THEN both MUST close
- AND focus MUST return to the "Menu" button

#### Scenario: Tab closes and moves focus

- GIVEN the dropdown is open with focus on an item
- WHEN the user presses Tab
- THEN the dropdown MUST close
- AND focus MUST move to the next top-level menu item (or off the widget if last)

### Requirement: REQ-MENU-004 Megamenu style

When `style === 'megamenu'`, the renderer MUST:

- Render top-level items in a horizontal bar.
- Open a full-width panel below the bar when any top-level item is clicked, containing all level-2 items grouped by their parent top-level item.
- Display each level-2 item's label and its level-3 children inline as a list.
- Navigate to the item's URL when a level-2 or level-3 item is clicked.
- Switch the panel content to a different section when another top-level item is clicked.
- Close the panel when Esc is pressed.

#### Scenario: Megamenu panel opens full-width

- GIVEN content `{style: 'megamenu', items: [{label: 'Products', children: [{label: 'Widget', url: '/widgets'}, {label: 'Gadget', url: '/gadgets'}]}]}`
- WHEN the user clicks "Products"
- THEN a full-width panel MUST appear below the bar
- AND both "Widget" and "Gadget" MUST be visible and organized in the panel

#### Scenario: Panel switches on top-level click

- GIVEN the panel is open showing Products items
- AND there is another top-level item "Services" with its own children
- WHEN the user clicks "Services"
- THEN the panel content MUST switch to show Services items
- AND Products items MUST no longer be visible

#### Scenario: Level-3 items render inline under level-2

- GIVEN Products > Widget > [FAQ, Docs] (level-3 children)
- WHEN the panel is open
- THEN "Widget" MUST be a clickable item
- AND "FAQ" and "Docs" MUST appear as an inline list below or beside "Widget"

#### Scenario: Esc closes megamenu

- GIVEN the megamenu panel is open
- WHEN the user presses Esc
- THEN the panel MUST close

### Requirement: REQ-MENU-005 Tree style

When `style === 'tree'`, the renderer MUST:

- Render items as a vertical hierarchical list.
- Render top-level items without children as clickable links; items with children MUST show a caret to the left.
- Expand or collapse children when the caret is clicked or the right arrow key is pressed.
- Expand all children on initial render when `expandedByDefault === true`.
- Render level-2 items with a caret if they have level-3 children; clicking the caret MUST expand the level-3 items.
- Render level-3 items as always-leaf nodes (no caret) that are clickable links or labels.

#### Scenario: Tree renders with collapsed children by default

- GIVEN content `{style: 'tree', items: [{label: 'Parent', children: [{label: 'Child'}]}], expandedByDefault: false}`
- WHEN the widget renders
- THEN "Parent" MUST show a closed caret (or right-pointing arrow)
- AND "Child" MUST NOT be visible initially

#### Scenario: Tree expands on caret click

- GIVEN the same structure with the caret visible
- WHEN the user clicks the caret
- THEN the caret MUST rotate or change appearance (to open/down-pointing)
- AND "Child" MUST become visible

#### Scenario: expandedByDefault expands all on load

- GIVEN the same structure but `expandedByDefault: true`
- WHEN the widget renders
- THEN all children at all levels MUST be visible
- AND all carets MUST be in the open position

#### Scenario: Tree with mixed click targets

- GIVEN a structure where Parent has a URL and children: `{label: 'Parent', url: '/parent', children: [...]}`
- WHEN the widget renders
- THEN the caret MUST be separate from the label
- AND clicking the label MUST navigate to `/parent`
- AND clicking the caret MUST only expand/collapse the children (no navigation)

### Requirement: REQ-MENU-006 Active item detection and highlighting

The renderer MUST detect the active item based on the current page URL and highlight it and its ancestors. The `activeItemHighlight` field controls the style:

- `'underline'`: bottom border on the active item and ancestor items
- `'background'`: light background colour on the active item and ancestor items
- `'left-bar'`: left border (4-6 px wide) on the active item and ancestor items
- `'none'`: no visual highlighting

An item is "active" if its `url` matches the current window location (exact match or route prefix match for internal routes). An item is "in current path" if it is an ancestor of an active item.

#### Scenario: Exact URL match highlights active item

- GIVEN items with `{label: 'Reports', url: '/reports'}`
- AND the current page is `http://localhost:3000/reports`
- WHEN the widget renders
- THEN the "Reports" item MUST be highlighted with the configured style
- AND its ancestors MUST also be highlighted

#### Scenario: Route prefix match for internal routes

- GIVEN items with `{label: 'Users', url: '/users'}` and children `{label: 'Alice', url: '/users/alice'}`
- AND the current page is `/users/alice/profile`
- WHEN the widget renders
- THEN "Alice" MUST be highlighted as active
- AND "Users" MUST be highlighted as "in current path"

#### Scenario: External URLs don't auto-highlight

- GIVEN items with `{label: 'External', url: 'https://example.com'}`
- AND the current page is not example.com
- WHEN the widget renders
- THEN the item MUST NOT be highlighted
- NOTE: External URLs are unlikely to match window.location; highlighting is skipped

#### Scenario: activeItemHighlight='left-bar' renders left border

- GIVEN content with `activeItemHighlight: 'left-bar'`
- AND an active item
- WHEN the widget renders
- THEN the active item MUST have a left border
- AND the width MUST be 4-6 px

### Requirement: REQ-MENU-007 Keyboard navigation

The renderer MUST support keyboard navigation conforming to WAI-ARIA Menu/Menubar pattern:

- **Tab**: move focus to the next top-level item; if at the last item, move focus out of the widget. Close any open dropdowns/flyouts.
- **Shift+Tab**: move focus to the previous top-level item; if at the first item, move focus out of the widget. Close any open dropdowns.
- **Enter/Space**: on a top-level item with children, open the dropdown/submenu. On a leaf item, navigate to its URL.
- **ArrowDown**: on a top-level item with children, open the dropdown and move focus to the first child. In an open dropdown, move focus to the next child.
- **ArrowUp**: in an open dropdown, move focus to the previous child.
- **ArrowRight**: on a level-2 item with level-3 children, open the flyout. In tree style, expand the item's children.
- **ArrowLeft**: close the current dropdown/flyout. In tree style, collapse the item's children.
- **Esc**: close any open dropdowns and return focus to the top-level item that opened them.

#### Scenario: Tab moves focus between top-level items

- GIVEN a dropdown menu with 3 top-level items and focus on the first item
- WHEN the user presses Tab
- THEN focus MUST move to the second item
- AND any open dropdown MUST close

#### Scenario: Enter opens dropdown and Space navigates

- GIVEN a top-level item with children and focus on it
- WHEN the user presses Enter
- THEN the dropdown MUST open
- AND focus MUST move to the first child
- NOTE: This assumes Enter behaves like ArrowDown for menu items; if Space should also open, that is implementation-dependent

#### Scenario: Esc closes and returns focus

- GIVEN an open dropdown with focus on a child item
- WHEN the user presses Esc
- THEN the dropdown MUST close
- AND focus MUST return to the parent top-level item

#### Scenario: ArrowRight expands tree node

- GIVEN a tree-style menu with a collapsed item and focus on it
- WHEN the user presses ArrowRight
- THEN the item MUST expand
- AND focus MAY move to the first child (implementation detail)

### Requirement: REQ-MENU-008 External link handling

When an item's `url` starts with `http://` or `https://`, it is treated as an external URL. Clicking an external URL MUST:

1. Open the link in a new tab via `window.open(url, '_blank', 'noopener,noreferrer')`
2. Apply `rel="noopener noreferrer"` to any fallback `<a>` tag for progressive enhancement

Internal URLs (starting with `/` or no protocol) MUST use `router.push()` or a same-tab `<a>` href.

#### Scenario: External URL opens in new tab

- GIVEN content `{label: 'GitHub', url: 'https://github.com'}`
- WHEN the user clicks the item
- THEN the system MUST call `window.open('https://github.com', '_blank', 'noopener,noreferrer')`
- AND a new tab MUST open

#### Scenario: Internal URL uses router.push

- GIVEN content `{label: 'Dashboard', url: '/dashboard'}`
- WHEN the user clicks the item
- THEN the system MUST call `router.push('/dashboard')`
- AND navigation MUST occur in the same tab

#### Scenario: Fallback <a> tag has rel attribute

- GIVEN an item with `url: 'https://external.com'`
- AND fallback rendering for JavaScript-disabled scenarios
- WHEN the HTML is inspected
- THEN the `<a>` MUST have `rel="noopener noreferrer"`

### Requirement: REQ-MENU-009 Add/edit form with drag-and-drop editor

The menu sub-form for `AddWidgetModal` MUST include:

1. A **Config section** with dropdowns for `style`, `orientation` (disabled for tree), `activeItemHighlight`, and toggles for `showIcons` and `expandedByDefault` (only shown for tree).
2. A **Tree editor** for building the `items` array:
   - Drag-and-drop reordering of items at all levels
   - Add/remove/edit buttons for each item
   - Validation: reject drops that would create depth > 3 levels
   - Depth indicator showing current nesting level (e.g., "Level 3 - max reached" for items with 2 ancestors)
   - Per-item inline fields: `label`, `url`, `icon` (via icon picker reusing REQ-MENU-010), optional "add children" button

When editing an existing widget, all fields MUST pre-fill from `editingWidget.content`.

#### Scenario: Config section controls all fields

- GIVEN the form is open
- THEN there MUST be dropdowns to select style, orientation, activeItemHighlight
- AND toggles for showIcons and expandedByDefault (tree only)
- AND changes MUST update the content before save

#### Scenario: Drag-and-drop reorders items

- GIVEN a tree editor with two top-level items [A, B]
- WHEN the user drags A below B
- THEN the order in `items` MUST reverse to [B, A]

#### Scenario: Depth validation prevents overly nested drops

- GIVEN an item that already has 2 ancestors
- WHEN the user tries to add children to it
- THEN the UI MUST show "Level 3 - max reached"
- AND the form MUST prevent adding children (disable the "add children" button)

#### Scenario: Icon picker per item

- GIVEN the tree editor with an item selected
- WHEN the user clicks the icon field
- THEN an icon picker MUST appear (reusing link-button-widget's icon resolution)
- AND the user MUST be able to select or upload an icon

### Requirement: REQ-MENU-010 Icon resolution

The `icon` field of a menu item MUST follow the same dual-mode convention as `link-button-widget` (REQ-LBN-002):

- A custom URL (starts with `/` or `http`) MUST render as `<img>` inside the menu item
- A bare name MUST render via the shared `IconRenderer` (built-in MDI component)
- An empty or null value MUST render no icon (label-only)

Icon size MUST be 16-24 px (smaller than REQ-LBN-002's 48 px, to fit in a menu list). The icon MUST appear to the left of the label in horizontal layouts and to the left in vertical/tree layouts.

#### Scenario: MDI icon in dropdown

- GIVEN content `{style: 'dropdown', items: [{label: 'Settings', icon: 'mdiCog'}]}`
- WHEN the widget renders
- THEN the item MUST display the settings icon to the left of "Settings"

#### Scenario: Custom URL icon in tree

- GIVEN content `{style: 'tree', items: [{label: 'Custom', icon: '/apps/mydash/icons/custom.svg'}]}`
- WHEN the widget renders
- THEN the item MUST display the custom SVG icon to the left of "Custom"

### Requirement: REQ-MENU-011 Empty state

When the `items` array is empty or not provided, the renderer MUST display a placeholder message: **"No menu items yet — click the gear icon to add some."**

The gear icon in the message MUST be clickable and open the edit form if the user is an admin/dashboard editor. For non-editors, the message MUST be non-interactive.

#### Scenario: Empty menu shows placeholder

- GIVEN content `{items: []}`
- AND the user is viewing the dashboard (not editing)
- WHEN the widget renders
- THEN the message "No menu items yet — click the gear icon to add some." MUST appear
- AND the gear icon MUST be visible

#### Scenario: Edit mode enables gear icon click

- GIVEN the same empty menu
- AND the user is in edit mode
- WHEN the user clicks the gear icon
- THEN the edit form MUST open
- AND they can add items

#### Scenario: Non-empty menu hides placeholder

- GIVEN content `{items: [{label: 'Item', url: '/path'}]}`
- WHEN the widget renders
- THEN the placeholder message MUST NOT appear
- AND the menu MUST be rendered normally
