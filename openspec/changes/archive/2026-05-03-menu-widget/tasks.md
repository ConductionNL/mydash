# Tasks — menu-widget

## 1. Server-side validation

- [ ] 1.1 Create `lib/Service/MenuService.php::validateMenuItems(array $items): void`
- [ ] 1.2 Recursively validate nesting depth; reject if any item nests > 3 levels deep
- [ ] 1.3 Throw `BadRequestException` with message `'Menu items can nest at most 3 levels deep'`
- [ ] 1.4 Integrate validation into `lib/Service/WidgetService.php::validateWidgetContent()` before placement save
- [ ] 1.5 Validate that `style` field is one of `dropdown`, `megamenu`, `tree`
- [ ] 1.6 Validate that `activeItemHighlight` is one of `background`, `underline`, `left-bar`, `none`

## 2. Dropdown renderer

- [ ] 2.1 Create `src/components/Widgets/Renderers/MenuWidget.vue` with style handler
- [ ] 2.2 Implement dropdown layout: top-level items in horizontal bar (or vertical if `orientation: 'vertical'`)
- [ ] 2.3 Implement popover dropdown on click/focus of top-level items with children
- [ ] 2.4 Implement flyout for level-2 items with level-3 children, appearing to the right on hover
- [ ] 2.5 Close dropdown on Esc key press
- [ ] 2.6 Close dropdown when Tab moves focus away
- [ ] 2.7 Render level-2 and level-3 items clickable or navigable (REQ-MENU-008)

## 3. Megamenu renderer

- [ ] 3.1 Implement megamenu layout: top-level items in horizontal bar
- [ ] 3.2 On top-level click, open full-width panel below showing all level-2 items grouped by parent
- [ ] 3.3 Display level-3 items inline as a list under/beside their level-2 parent
- [ ] 3.4 Switch panel content when user clicks another top-level item
- [ ] 3.5 Close panel on Esc key press

## 4. Tree renderer

- [ ] 4.1 Implement tree layout: vertical hierarchical list with carets for expandable items
- [ ] 4.2 Show closed caret (right-pointing) for items with children; open caret (down-pointing) when expanded
- [ ] 4.3 Toggle expand/collapse on caret click or ArrowRight/ArrowLeft keys
- [ ] 4.4 When `expandedByDefault: true`, expand all items on initial render
- [ ] 4.5 Separate caret from label: caret toggles children, label is clickable if item has URL
- [ ] 4.6 Indent level-2 and level-3 items appropriately (e.g., 20-24 px per level)

## 5. Active item detection

- [ ] 5.1 Implement `isActiveItem(itemUrl, currentLocation): boolean` helper
- [ ] 5.2 Match item URL against `window.location.pathname` (exact match or prefix for internal routes)
- [ ] 5.3 Skip matching for external URLs (http/https)
- [ ] 5.4 Recursively mark ancestors of active items as "in current path"
- [ ] 5.5 Render active items and ancestors with appropriate highlight style from `activeItemHighlight` field:
  - [ ] 5.5a `underline`: bottom border (3-4 px solid, use primary color)
  - [ ] 5.5b `background`: light background color (use primary at 10% opacity)
  - [ ] 5.5c `left-bar`: left border (4-6 px solid, use primary color)
  - [ ] 5.5d `none`: no visual change

## 6. Keyboard navigation

- [ ] 6.1 Implement Tab/Shift+Tab to move focus between top-level items; close dropdowns
- [ ] 6.2 Implement Enter/Space on top-level item with children to open dropdown
- [ ] 6.3 Implement ArrowDown to open dropdown or move focus to next child
- [ ] 6.4 Implement ArrowUp to move focus to previous child in open dropdown
- [ ] 6.5 Implement ArrowRight to open flyout (dropdown/megamenu) or expand tree node
- [ ] 6.6 Implement ArrowLeft to close flyout or collapse tree node
- [ ] 6.7 Implement Esc to close all open dropdowns/flyouts and return focus to triggering item
- [ ] 6.8 Set `aria-haspopup="menu"` on top-level items with children
- [ ] 6.9 Set `role="menubar"` on the top-level item container
- [ ] 6.10 Set `role="menu"` on dropdown/flyout containers; `role="menuitem"` on items
- [ ] 6.11 Manage `aria-expanded` attribute on top-level items (true when dropdown open)

## 7. External link handling

- [ ] 7.1 Detect external URLs: check if `url` starts with `http://` or `https://`
- [ ] 7.2 For external URLs, call `window.open(url, '_blank', 'noopener,noreferrer')`
- [ ] 7.3 For internal URLs, call `router.push(url)` or use `<a href>`
- [ ] 7.4 Add `rel="noopener noreferrer"` to fallback `<a>` tags
- [ ] 7.5 Do NOT navigate same-tab on external URLs

## 8. Icon rendering

- [ ] 8.1 Implement icon resolution helper (reuse from link-button-widget if possible)
- [ ] 8.2 For URLs starting with `/` or `http`, render `<img>` tag
- [ ] 8.3 For bare names, render via `IconRenderer` component (MDI built-ins)
- [ ] 8.4 For empty/null, render no icon
- [ ] 8.5 Set icon size to 16-24 px (CSS `width: 20px; height: 20px;` as default)
- [ ] 8.6 Position icon to the left of label in all layouts
- [ ] 8.7 When `showIcons: false`, hide all icons but don't remove them from DOM (preserve layout)

## 9. Edit form

- [ ] 9.1 Create `src/components/Widgets/Forms/MenuForm.vue`
- [ ] 9.2 Implement config section:
  - [ ] 9.2a Dropdown for `style` with options: dropdown, megamenu, tree
  - [ ] 9.2b Dropdown for `orientation` with options: horizontal, vertical (disable for tree)
  - [ ] 9.2c Dropdown for `activeItemHighlight` with options: background, underline, left-bar, none
  - [ ] 9.2d Toggle for `showIcons` (default true)
  - [ ] 9.2e Toggle for `expandedByDefault` (only visible for tree style)
- [ ] 9.3 Implement tree editor for `items` array:
  - [ ] 9.3a Render each item as an editable row with: label input, url input, icon picker, buttons
  - [ ] 9.3b "Add children" button for items at depth < 2
  - [ ] 9.3c "Remove item" button for each item
  - [ ] 9.3d Drag-and-drop reordering within the same parent level
  - [ ] 9.3e Depth indicator next to each item (e.g., "Level 2", "Level 3 - max reached")
- [ ] 9.4 Icon picker per item:
  - [ ] 9.4a Reuse or adapt icon picker from link-button-widget (REQ-LBN-002)
  - [ ] 9.4b Allow selection of built-in MDI icons OR upload custom URL
- [ ] 9.5 Validate on form submit: reject if depth validation fails (call `MenuService::validateMenuItems`)
- [ ] 9.6 Pre-fill all fields from `editingWidget.content` when editing existing widget
- [ ] 9.7 Handle creation of new items: default shape `{label: '', url: '', icon: '', children: []}`

## 10. Widget registration

- [ ] 10.1 Add `menu` entry to `src/constants/widgetRegistry.js`
- [ ] 10.2 Set default content shape: `{items: [], style: 'dropdown', orientation: 'horizontal', showIcons: true, expandedByDefault: false, activeItemHighlight: 'underline'}`
- [ ] 10.3 Map to renderer component `MenuWidget.vue`
- [ ] 10.4 Map to form component `MenuForm.vue`

## 11. Empty state

- [ ] 11.1 In `MenuWidget.vue` renderer, detect empty `items` array
- [ ] 11.2 Display message: "No menu items yet — click the gear icon to add some."
- [ ] 11.3 Display a gear/settings icon within the message
- [ ] 11.4 If user is editor/admin, make gear icon clickable to trigger edit mode
- [ ] 11.5 Hide message when `items.length > 0`

## 12. Styling

- [ ] 12.1 Use CSS variables for colours: `--color-primary`, `--color-primary-text`, `--color-main-background`
- [ ] 12.2 Dropdown: add subtle background and border to popover/flyout
- [ ] 12.3 Megamenu: add subtle background and border to full-width panel
- [ ] 12.4 Tree: indent children with consistent spacing
- [ ] 12.5 Apply active item highlight styles per `activeItemHighlight` field
- [ ] 12.6 Add hover effects: slight background change or text emphasis on non-active items
- [ ] 12.7 Ensure focus indicators visible for keyboard navigation (e.g., outline on focused items)

## 13. Tests

- [ ] 13.1 PHPUnit: depth validation passes for 3-level nesting
- [ ] 13.2 PHPUnit: depth validation rejects 4-level nesting with correct error message
- [ ] 13.3 PHPUnit: invalid `style`, `orientation`, `activeItemHighlight` values rejected
- [ ] 13.4 Vitest: active item detection matches internal routes (exact + prefix)
- [ ] 13.5 Vitest: active item detection skips external URLs
- [ ] 13.6 Vitest: ancestors of active items marked as "in current path"
- [ ] 13.7 Vitest: active item highlight styles applied correctly
- [ ] 13.8 Vitest: dropdown opens and closes on click/Esc
- [ ] 13.9 Vitest: megamenu panel switches on top-level click
- [ ] 13.10 Vitest: tree expands/collapses on caret click and arrow keys
- [ ] 13.11 Vitest: expandedByDefault expands all items on initial render
- [ ] 13.12 Vitest: Tab moves focus; closes dropdowns
- [ ] 13.13 Vitest: external URLs open in new tab; internal URLs use router.push
- [ ] 13.14 Vitest: icons render (MDI, custom URL, no icon cases)
- [ ] 13.15 Vitest: empty state displays and hides appropriately
- [ ] 13.16 Vitest: form validation rejects depth > 3
- [ ] 13.17 Vitest: form pre-fills existing widget content
- [ ] 13.18 Playwright: dropdown style end-to-end (open, click item, navigate)
- [ ] 13.19 Playwright: megamenu style end-to-end (switch panels, click item)
- [ ] 13.20 Playwright: tree style end-to-end (expand, collapse, navigate)
- [ ] 13.21 Playwright: keyboard navigation (Tab, arrow keys, Esc)

## 14. Quality

- [ ] 14.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 14.2 ESLint clean
- [ ] 14.3 OpenAPI spec updated (no new routes; static rendering only)
- [ ] 14.4 Translation entries added in `l10n/en.js` and `l10n/nl.js`:
  - [ ] `Menu`, `Menu Widget`, `No menu items yet — click the gear icon to add some.`
  - [ ] `Menu Style`, `Dropdown`, `Megamenu`, `Tree`
  - [ ] `Orientation`, `Horizontal`, `Vertical`
  - [ ] `Active Item Highlight`, `Underline`, `Background`, `Left Bar`, `None`
  - [ ] `Show Icons`, `Expanded by Default`
  - [ ] `Add Item`, `Remove Item`, `Add Children`, `Label`, `URL`, `Icon`
  - [ ] `Items`, `Level 1`, `Level 2`, `Level 3 - max reached`
