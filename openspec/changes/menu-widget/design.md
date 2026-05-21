# Design — Menu Widget

## Context

MyDash currently has no built-in menu or navigation widget. Users and admins who want to surface curated link hierarchies must use text widgets with static HTML or purchase third-party integrations. The context-brief specifies a first-class `menu` widget supporting three visual styles (dropdown, megamenu, tree), automatic active-item detection, and a drag-and-drop editor. This design document formalises the component architecture, active-item detection strategy, and keyboard navigation patterns so implementation is straightforward and maintainable.

## Goals / Non-Goals

**Goals:**

- Support three distinct visual styles (dropdown, megamenu, tree) without duplicating event handling or active-item logic.
- Implement WAI-ARIA-compliant keyboard navigation (Tab, Shift+Tab, Enter, ArrowUp/Down/Left/Right, Esc).
- Automatically detect and highlight the active menu item based on `window.location.pathname` and its ancestors.
- Provide a user-friendly drag-and-drop tree editor in the add/edit form with visual depth indicators.
- Validate menu depth (max 3 levels) at save time on the server to catch mistakes early.
- Support external URLs (opening in new tab) and internal routes (using router.push) transparently.
- Respect icon customization per item: MDI icon names or custom image URLs, falling back to label-only.

**Non-Goals:**

- Breadcrumb rendering or "You are here" overlays — the active-item highlight is sufficient.
- Menu persistence (open/collapsed state) across page reloads — resets on each nav.
- Infinite nesting — capped at 3 levels (top + 2 child levels) to keep UI manageable.
- Animation (slide, fade) on open/close — static positioning is fine for first release.
- Touch/swipe gestures — keyboard nav and mouse clicks cover primary use cases.
- Search/filter within the menu — out of scope for initial release.

## Decisions

### D1: Three separate renderer sub-components (MenuDropdown, MenuMegamenu, MenuTree), not a unified mega-component

**Decision**: Each style (dropdown, megamenu, tree) gets its own sub-component that implements the specific rendering and keyboard behavior. The parent `MenuWidget.vue` dispatches to one sub-component based on `content.style`.

**Alternatives considered:**

- Single "mega" MenuRenderer.vue with `v-if` branches per style. Rejected because conditional blocks make keyboard nav logic hard to trace and test; each style's DOM structure is fundamentally different.
- Render function composition (no templates). Rejected because Vue 2.7 + template syntax is clearer for this team; render functions add cognitive load.

**Rationale**: Separation of concerns — each component owns its visual layout, focus management, and event handling. Easier to test in isolation and to iterate on one style without breaking others.

### D2: Active-item detection via composable `useMenuActiveItem(items, pathname)`, not inline in each sub-component

**Decision**: Extract active-item detection and ancestor marking into a single `useMenuActiveItem.js` composable that returns an enhanced `items` tree with `isActive` and `isInPath` flags. All three sub-components import and use the same composable.

**Alternatives considered:**

- Each sub-component computes `isActive` inline. Rejected — code duplication and risk of inconsistent logic across styles.
- Pass pre-computed flags from parent. Rejected — coupling the parent to all three styles' flag shapes.

**Rationale**: Single source of truth for path matching. Changes to matching logic (e.g., "longest prefix wins") apply uniformly across all styles.

### D3: External URL detection via protocol prefix (`http://`, `https://`), not by extension

**Decision**: Check if `item.url` starts with `http://` or `https://`. If yes, `window.open(url, '_blank')`. Otherwise, `router.push(url)` or same-tab `<a href>`.

**Alternatives considered:**

- Auto-detect from URL extension (`.com` → external, `/route` → internal). Rejected because opaque URLs are ambiguous.
- Let the editor require explicit `isExternal` flag. Rejected because users expect protocol detection to "just work".

**Rationale**: Protocol is the clearest signal of intent. Matches common web conventions and Nextcloud routing patterns.

### D4: Keyboard navigation follows WAI-ARIA Menu/Menubar, with modal focus trap during open dropdowns

**Decision**: Implement Tab/Shift+Tab, Enter/Space, Arrow keys, and Esc per ARIA Authoring Practices Guide — APG 1.2. When a dropdown is open, focus stays within it until Esc closes it.

**Alternatives considered:**

- Simplified keyboard nav (just Enter to click, Esc to close). Rejected — users relying on keyboard-only navigation would struggle with multi-level menus.
- Custom keyboard strategy per style. Rejected — inconsistency breaks muscle memory.

**Rationale**: Standard ARIA patterns are well-tested and familiar to users with accessibility needs. Alignment with browser native `<select>` and other menu widgets.

### D5: Drag-and-drop tree editor uses `vuedraggable` (if available in project) or a lightweight custom implementation

**Decision**: Check if the project already imports `vuedraggable` or `sortable` for other components (e.g., dashboard grid reordering). If yes, reuse it. If no, implement a lightweight custom `@dragstart/@dragover/@drop` listener chain.

**Alternatives considered:**

- Always use a heavy third-party lib (React DnD, etc.). Rejected — adds dependency bloat for a single feature.
- Always roll custom. Rejected — drag-and-drop is notoriously fiddly; reusing battle-tested lib is safer.

**Rationale**: Pragmatic — prefer reuse if the dependency is already present, but don't add a new one unless necessary. Light custom drag-drop is simpler than integrating a new library.

### D6: Depth validation at save time (server), not at edit time (client)

**Decision**: The form allows the user to build any tree structure, but when they click Save, the server validates depth ≤ 3 and returns HTTP 400 if violated. The form shows "Level X - max reached" guidance inline to prevent the user from building an invalid structure in the first place.

**Alternatives considered:**

- Hard-block nesting in the form (disable "add children" after 2 ancestor levels). Rejected — removes user agency; some users might want to see the error.
- No validation at all. Rejected — invalid data corrupts the dashboard.

**Rationale**: Depth validation at the server is a hard safety net; client-side guidance prevents errors proactively. Two-layer defense-in-depth.

### D7: Active-item ancestor marking uses a transitive closure: mark all ancestors of the active item, not just immediate parent

**Decision**: When an item is marked `isActive: true`, walk its ancestor chain and mark each with `isInPath: true`. The renderer highlights ancestors with the same visual style as the active item.

**Alternatives considered:**

- Only highlight the active item itself. Rejected — users lose context about where they are in the hierarchy.
- Only highlight the immediate parent. Rejected — in deep trees, immediate parent alone is insufficient.

**Rationale**: Full ancestor chain makes the user's position clear at a glance (breadcrumb-like visual). Especially important for the dropdown and megamenu styles where the tree is collapsed.

### D8: Icon size 16-24 px (vs. link-button's 48 px), positioned to the left of the label

**Decision**: Menu icons are smaller (16-24 px) than link-button icons because they appear in a dense list. Icon is left-aligned with label to the right.

**Alternatives considered:**

- Match link-button's 48 px and vertical stacking. Rejected — makes menu items too large; defeats the purpose of a compact navigation widget.
- Icon on the right (label first, icon after). Rejected — Western reading order (left-to-right) expects icon first for affordance.

**Rationale**: Icon-label pairs in lists are a well-established UI pattern. 16-24 px is readable but compact.

## Migration Plan

1. **Composable + utilities land first** — `useMenuActiveItem.js` and unit tests. No components yet.
2. **Sub-components**: `MenuDropdown.vue`, `MenuMegamenu.vue`, `MenuTree.vue` with keyboard nav, tested per style.
3. **Main renderer**: `MenuWidget.vue` dispatches to sub-components, tested for style switching.
4. **Form**: `MenuForm.vue` with drag-and-drop editor and depth visualization.
5. **Server-side validation**: Add depth-validation middleware to placement-save handler.
6. **Integration tests**: Playwright tests covering all three styles, keyboard nav, and active-item detection.
7. **Rollback**: Pure frontend change + one backend validation addition. Reverting the PR restores previous behavior with no data loss.

## Open Questions

- Should the drag-and-drop editor allow inline editing of items (double-click label to rename), or is the current "edit modal" approach sufficient?
- Should expanded/collapsed state in tree style persist in localStorage across page reloads, or reset on each nav?
- Should external URLs with deep paths (e.g., `https://example.com/docs/api/v1`) ever match the active-item highlight, or are they always non-matching?

## Seed Data

Example menu configurations for testing and documentation:

### Dropdown Style (Horizontal, 2 Levels)

```json
{
  "type": "menu",
  "content": {
    "items": [
      {
        "label": "Documentatie",
        "url": "/docs",
        "icon": "mdiBook",
        "children": [
          { "label": "API Referentie", "url": "/docs/api" },
          { "label": "Gebruikersgids", "url": "/docs/guide" }
        ]
      },
      {
        "label": "Ondersteuning",
        "url": "https://support.example.com",
        "icon": "mdiHelpCircle"
      }
    ],
    "style": "dropdown",
    "orientation": "horizontal",
    "showIcons": true,
    "expandedByDefault": false,
    "activeItemHighlight": "underline"
  }
}
```

### Megamenu Style (Full-Width Panel, 3 Levels)

```json
{
  "type": "menu",
  "content": {
    "items": [
      {
        "label": "Producten",
        "icon": "mdiPackageVariantClosed",
        "children": [
          {
            "label": "MyDash",
            "url": "/products/mydash",
            "children": [
              { "label": "Demo", "url": "/products/mydash/demo" },
              { "label": "Prijzen", "url": "/products/mydash/pricing" }
            ]
          },
          { "label": "OpenRegister", "url": "/products/openregister" }
        ]
      },
      {
        "label": "Diensten",
        "icon": "mdiGear",
        "children": [
          { "label": "Consulting", "url": "/services/consulting" },
          { "label": "Trainingen", "url": "/services/training" }
        ]
      }
    ],
    "style": "megamenu",
    "orientation": "horizontal",
    "showIcons": true,
    "expandedByDefault": false,
    "activeItemHighlight": "background"
  }
}
```

### Tree Style (Vertical, Collapsible)

```json
{
  "type": "menu",
  "content": {
    "items": [
      {
        "label": "Mijn Dashboard",
        "url": "/dashboard",
        "icon": "mdiViewDashboard",
        "children": [
          { "label": "Instellingen", "url": "/dashboard/settings" },
          { "label": "Templates", "url": "/dashboard/templates" }
        ]
      },
      {
        "label": "Instellingen",
        "icon": "mdiCog",
        "children": [
          { "label": "Gebruikers", "url": "/settings/users" },
          { "label": "Groepen", "url": "/settings/groups" },
          { "label": "Integraties", "url": "/settings/integrations" }
        ]
      },
      {
        "label": "Help",
        "url": "https://docs.example.com",
        "icon": "mdiHelpCircle"
      }
    ],
    "style": "tree",
    "orientation": "vertical",
    "showIcons": true,
    "expandedByDefault": false,
    "activeItemHighlight": "left-bar"
  }
}
```
