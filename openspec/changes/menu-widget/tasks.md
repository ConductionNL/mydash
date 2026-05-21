# Tasks — menu-widget

## Tasks

- [ ] Task 1: Add `src/composables/useMenuActiveItem.js` exposing `(items, pathname) => enhancedItems` which recursively walks the items tree, detects `isActive` by exact or prefix match, marks `isInPath` for all ancestors, and returns the augmented tree for use by all three renderer styles
- [ ] Task 2: Build `src/components/Widgets/Renderers/MenuDropdown.vue` implementing REQ-MENU-003 (horizontal/vertical bar, dropdown open on click, level-2 flyout, keyboard nav per WAI-ARIA), ESC closes both dropdown and flyout, Tab moves focus and closes, Icon 16-24px left-aligned with label
- [ ] Task 3: Build `src/components/Widgets/Renderers/MenuMegamenu.vue` implementing REQ-MENU-004 (full-width panel below bar, switches on top-level click, level-3 children inline, keyboard nav), uses `useMenuActiveItem` for active-item detection and highlighting
- [ ] Task 4: Build `src/components/Widgets/Renderers/MenuTree.vue` implementing REQ-MENU-005 (vertical list, carets for items with children, expand/collapse on caret click or ArrowRight/Left, `expandedByDefault` controls initial state), separate click targets for caret (expand/collapse) and label (navigate)
- [ ] Task 5: Build `src/components/Widgets/Renderers/MenuWidget.vue` main renderer dispatching to sub-component per `style`, imports `useMenuActiveItem` and passes enhanced items to all three sub-components, renders empty-state placeholder when `items` is empty (REQ-MENU-011)
- [ ] Task 6: Implement active-item highlighting per `activeItemHighlight` style in all three sub-components: `'underline'` (bottom border), `'background'` (light BG color), `'left-bar'` (4-6 px left border), `'none'` (no highlighting); ancestors marked by `useMenuActiveItem` get the same visual treatment
- [ ] Task 7: Add external-link handling in all sub-components: detect `http://`/`https://` prefix, call `window.open(url, '_blank', 'noopener,noreferrer')` on click; internal URLs (`/` prefix) use `router.push()` or same-tab `<a href>`; fallback `<a>` tags have `rel="noopener noreferrer"`
- [ ] Task 8: Build `src/components/Widgets/Forms/MenuForm.vue` with two sections: (1) Config dropdown/toggles for `style`, `orientation` (disabled for tree), `activeItemHighlight`, `showIcons`, `expandedByDefault` (tree-only); (2) Drag-and-drop tree editor for building `items` with Add/Edit/Remove buttons, depth indicator ("Level X - max reached"), and per-item inline `label`/`url`/`icon` fields
- [ ] Task 9: Implement drag-and-drop reordering in MenuForm tree editor using `vuedraggable` (if already a dep) or lightweight custom `@dragstart/@dragover/@drop` handlers; validate drops do not create depth > 3
- [ ] Task 10: Integrate shared `IconRenderer` in all menu sub-components and in the form icon picker; support dual-mode icons (MDI name or custom URL), size 16-24 px in menus (vs. 48 px in link-button), left-aligned with label
- [ ] Task 11: Register `menu` in `src/constants/widgetRegistry.js` with defaults `{items:[], style:'dropdown', orientation:'horizontal', showIcons:true, expandedByDefault:false, activeItemHighlight:'underline'}`
- [ ] Task 12: Add server-side depth validation in `lib/Service/WidgetValidationService.php` or middleware that recursively checks placement `widgetContent.items` and rejects depth > 3 with HTTP 400 `{error: 'Menu items can nest at most 3 levels deep'}`; validation fires on placement save/update
- [ ] Task 13: PHPUnit coverage — depth validation (valid 3-level, reject 4-level), multiple items at each level, empty items array accepted
- [ ] Task 14: Vitest coverage — `useMenuActiveItem` (exact match, prefix match, ancestor marking, external URLs non-matching), active-item highlighting per style, keyboard nav (Tab/Shift+Tab, Enter/Space, ArrowUp/Down/Left/Right, Esc), drag-and-drop reorder, form validation
- [ ] Task 15: Playwright — Dropdown open/close (click, Esc, Tab), Megamenu panel switch (click different top-level), Tree expand/collapse (caret click, ArrowRight/Left), Active-item detection (pathname match, ancestor highlighting), External link opens `_blank`, Keyboard nav all three styles
- [ ] Task 16: Quality gates — `composer check:strict`, ESLint clean, OpenAPI updated (no new routes), `nl`+`en` translations for all new UI strings (Menu Widget, Style, Orientation, Horizontal, Vertical, Active Item Highlight, Underline, Background, Left Bar, None, Show Icons, Expanded by Default, Items, Add Item, Edit Item, Remove Item, Level X - Max Reached, No menu items yet, Click the gear icon to add some, Label, URL, Icon, Add Children, Cancel, Save)
- [ ] Task 17: Documentation — Changelog entry covering the new menu widget, three visual styles, active-item detection, keyboard nav support; user-guide screenshots of the form and each style in a dashboard
- [ ] Task 18: i18n — `nl_NL` + `en_US` for all UI strings listed in Task 16

## Verification

`openspec validate` exits clean. All three menu styles render with correct active-item highlighting; keyboard nav follows WAI-ARIA patterns; drag-and-drop form works without depth violations; external links open in new tab; server depth validation rejects 4-level nesting.

## Tests (company-wide ADR-009)

PHPUnit for depth validation; Vitest for composables, components, form logic; Playwright for end-to-end flows (dropdown/megamenu/tree styles, keyboard nav, active-item detection, drag-and-drop, external links).

## Documentation (company-wide ADR-010)

Changelog entry, user-guide with screenshots of all three styles and the form editor.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for all UI strings listed in Task 16.
