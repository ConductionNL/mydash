---
status: implemented
---

# Organization-wide Navigation Editor Specification

## Purpose

The `navigation-editor-org` capability provides a robust, admin-curated, group-aware org-wide navigation tree distinct from the personal dashboard list. Where the existing `dashboard-switcher-sidebar` shows the dashboards a user owns or can access, this capability adds a second navigation surface — an admin-controlled tree of links and sections shared across the whole organisation. Useful for company resources, policy hubs, and tools panels.

The capability owns its own storage (per-language JSON files inside MyDash's `IAppData` folder), its own group-based filtering pipeline (driven by Nextcloud's `IGroupManager`), its own admin editor UI, and its own runtime panel/drawer renderer. A scalar global setting (`mydash.org_navigation_position`) controls where the panel renders in the workspace shell (left, right, top, or hidden).

## Data Model

### Per-language tree storage

Each language file lives at `IAppData('mydash')/org-navigation/{lang}.json` (e.g. `nl.json`, `en.json`). Maximum file size: 5 MB. Files are written wholesale by `OrgNavigationService::setTree()`; per-node CRUD endpoints are intentionally NOT exposed.

### Node schema

```json
{
  "id": "string (UUID v1..v5)",
  "label": "string (required, non-empty)",
  "icon": "string|null (icon name OR URL starting with / or http)",
  "url": "string|null (null for section nodes)",
  "openInNewTab": "boolean (default false)",
  "groupVisibility": "string[]|null (null = visible to all; array = restrict to listed group IDs)",
  "children": "array of nodes (max depth 3 including root)"
}
```

The `groupVisibility` field is a MyDash-specific addition (the reference implementation relies on filesystem ACL via GroupFolders). Filtering is implemented explicitly in `OrgNavigationService::filterTreeByUserGroups()` via the single-source-of-truth resolver `AdminTemplateService::getUserGroupIdsFor()` (REQ-TMPL-013).

### Global position setting

`mydash.org_navigation_position` is stored as a scalar in the `mydash_admin_settings` key-value table (NOT in the tree JSON). Allowed values: `'left'`, `'right'`, `'top'`, `'hidden'`. Default: `'hidden'` (the rail is opt-in).

## Requirements

### Requirement: REQ-ONAV-001 Org navigation tree storage

The system MUST persist an organisation-wide navigation tree as a JSON file on the Nextcloud filesystem at a well-known path within MyDash's `IAppData` folder. One file is stored per language: `org-navigation/{lang}.json`. The implementation MUST NOT use a Nextcloud app-config key for the tree payload. The maximum accepted file size is 5 MB (enforced on read and write).

> **v1 language scope:** MyDash v1 ships with support for `nl` (Dutch) and `en` (English). Both language files are maintained independently; changing one does not affect the other. The API accepts an optional `?lang=` query parameter (default: `nl`). A CLI copy command (`mydash:copy-org-navigation <source> <target>`) is a planned follow-up, not part of v1.

The tree is an ordered array of node objects (see Data Model above). Tree depth (including root) MUST NOT exceed 3 levels.

#### Scenario: Tree persists to file
- GIVEN an admin creates and saves an org-nav tree with 2 top-level sections and 3 subsections
- WHEN the file `org-navigation/nl.json` is read from `IAppData('mydash')`
- THEN it MUST contain valid JSON with the persisted tree structure

#### Scenario: Node id is uuid
- GIVEN an admin creates a node in the tree
- WHEN the node is saved
- THEN the node's `id` field MUST be a valid UUID (v1..v5)

#### Scenario: Tree respects max depth 3
- GIVEN a tree with root items containing children containing grandchildren
- WHEN the tree is retrieved
- THEN the depth (root → children → grandchildren) MUST NOT exceed 3 levels

#### Scenario: Label is required per node
- GIVEN a node is created without a label
- WHEN the save is attempted
- THEN the system MUST return HTTP 400 with error message containing `'label'`

### Requirement: REQ-ONAV-002 Admin read API with group filtering

The system MUST expose a `GET /api/admin/org-navigation` endpoint accessible to any logged-in user. The endpoint MUST accept an optional `?lang=` query parameter (values: `nl`, `en`; default: `nl`) that selects which language file is read. The response MUST return the complete tree structure for that language, filtered to only include nodes the requesting user is permitted to see based on group memberships.

A node is visible if and only if:
- `groupVisibility` is `null` (visible to all), OR
- The user belongs to at least one group listed in `groupVisibility`

If a parent node is hidden, all its children are also hidden (cascading).

#### Scenario: All-users tree is visible to every user
- GIVEN a tree with `groupVisibility = null` on all nodes
- WHEN user A (member of group G1) and user B (member of group G2) both request the tree
- THEN both receive the full unfiltered tree

#### Scenario: Admin-only subtree filtered for non-admin
- GIVEN a section with `groupVisibility: ['admin']` and 2 child links
- AND user X is not a member of the 'admin' group
- WHEN user X requests the tree
- THEN the section and its children MUST NOT appear in the response

#### Scenario: User in one of multiple groups sees node
- GIVEN a section with `groupVisibility: ['marketing', 'sales']`
- AND user Y is a member of 'sales' (but not 'marketing')
- WHEN user Y requests the tree
- THEN the section MUST be visible

#### Scenario: Hidden parent hides children
- GIVEN a section with `groupVisibility: ['restricted']` containing 3 child links
- AND user Z is not a member of 'restricted'
- WHEN user Z requests the tree
- THEN neither the section nor its children MUST appear

### Requirement: REQ-ONAV-003 Admin write API with validation

The system MUST expose a `PUT /api/admin/org-navigation` endpoint accessible only to users with the admin role. The endpoint accepts an optional `?lang=` query parameter (values: `nl`, `en`; default: `nl`) that selects which language file to overwrite. The endpoint accepts a complete replacement tree for that language file (no PATCH) and validates before persisting.

Validation rules:
- The payload MUST be an array of node objects
- Each node MUST have a non-empty `label` and a valid UUID `id`
- No two nodes (at any depth) MUST have the same `id`
- Tree depth (from root to deepest leaf) MUST NOT exceed 3 levels; return HTTP 400 `'Tree depth cannot exceed 3 levels'` if violated
- The `url` field (if present) MUST NOT contain `javascript:`, `data:`, or `vbscript:` schemes; return HTTP 400 `'URL scheme is not allowed'` if violated
- The `groupVisibility` field (if present) MUST be either null or a non-empty array of string group ids
- Return HTTP 403 if the requesting user is not an admin

On success, write the validated tree to `IAppData('mydash')/org-navigation/{lang}.json` (wholesale file replacement) and return HTTP 200 with the persisted tree.

#### Scenario: Admin saves valid tree
- GIVEN an admin provides a valid tree with 2 sections, each with 2 child links
- WHEN `PUT /api/admin/org-navigation` is called
- THEN the response MUST be HTTP 200
- AND the tree MUST be persisted to `IAppData('mydash')/org-navigation/nl.json`

#### Scenario: Depth exceeded returns 400
- GIVEN a tree with 4 levels (root → child → grandchild → great-grandchild)
- WHEN `PUT /api/admin/org-navigation` is called
- THEN the response MUST be HTTP 400 with message `'Tree depth cannot exceed 3 levels'`
- AND the tree MUST NOT be persisted

#### Scenario: Duplicate node ids return 400
- GIVEN a tree where two nodes (at different depths) have the same UUID
- WHEN the PUT is attempted
- THEN the response MUST be HTTP 400 with message containing `'duplicate'` and `'id'`

#### Scenario: Non-admin cannot write
- GIVEN a user with role viewer (not admin)
- WHEN the user calls `PUT /api/admin/org-navigation` with a valid tree
- THEN the response MUST be HTTP 403

#### Scenario: JavaScript URL is rejected
- GIVEN a node with `url: 'javascript:alert(1)'`
- WHEN the PUT is attempted
- THEN the response MUST be HTTP 400 with message `'URL scheme is not allowed'`

### Requirement: REQ-ONAV-004 Global position setting

The system MUST support a global Nextcloud admin setting `org_navigation_position` (string, enum: `'left'|'right'|'top'|'hidden'`, default: `'hidden'`). This setting controls where the org-nav rail/drawer is rendered in the UI (if at all). The setting is exposed via two endpoints:

- `GET /api/admin/org-navigation/position` — any logged-in user reads the current position
- `PUT /api/admin/org-navigation/position` — admin-only; body `{position: 'left'|'right'|'top'|'hidden'}`

When `position = 'hidden'`, the org-nav rail is not rendered even if the tree is non-empty (effectively opting out).

#### Scenario: Position defaults to hidden
- GIVEN a new MyDash installation
- WHEN the app queries `GET /api/admin/org-navigation/position`
- THEN the response MUST be `{position: 'hidden'}`

#### Scenario: Admin changes position to left
- GIVEN an admin sets the position to `'left'` via `PUT /api/admin/org-navigation/position`
- WHEN all users refresh the app
- THEN the org-nav rail MUST appear on the left side

#### Scenario: Position hidden suppresses rail
- GIVEN `org_navigation_position = 'hidden'`
- AND the tree is non-empty
- WHEN the app renders
- THEN no org-nav rail MUST be visible

### Requirement: REQ-ONAV-005 Vue panel rendering

The system MUST provide a Vue 2.7 SFC `OrgNavigationPanel.vue` that renders the filtered org-nav tree as a navigable panel. The panel MUST:

1. Fetch the tree from `GET /api/admin/org-navigation` on mount via the `useOrgNavigationStore` Pinia store
2. Render each node via the recursive `OrgNavigationItem.vue` SFC with:
   - Icon (if present, 24 px square, resolved per REQ-ONAV-006)
   - Label
   - URL link (if present, `href` attribute; click opens the URL per `openInNewTab`)
   - Children (expandable/collapsible section, indented, recursive)
3. Highlight the current active node based on URL match (REQ-ONAV-009)
4. Support expand/collapse per section (state tracked in component)

#### Scenario: Tree renders with icons and labels
- GIVEN the org-nav tree contains a section "Company Resources" with icon "folder" and a child link "Handbook" with icon "file-document"
- WHEN `OrgNavigationPanel.vue` mounts
- THEN the section MUST show as an expandable item with the folder icon and label
- AND on expand, the child link MUST appear indented with the document icon and label

#### Scenario: Active item is highlighted
- GIVEN the tree contains a node with `url: '/apps/mydash/dashboards/sales'`
- AND the user is currently on the page `/apps/mydash/dashboards/sales/overview`
- WHEN the panel renders
- THEN the node MUST have the `org-nav-item--active` CSS class

#### Scenario: Child link without url is not clickable
- GIVEN a section node with `url = null`
- WHEN the user hovers or interacts with it
- THEN no link behavior MUST be triggered
- AND the node MUST render as an expandable button container

#### Scenario: openInNewTab respected
- GIVEN a node with `url: 'https://external.com'` and `openInNewTab: true`
- WHEN the user clicks the node
- THEN the URL MUST open with HTML `target="_blank"` and `rel="noopener noreferrer"`

### Requirement: REQ-ONAV-006 Icon resolution

Each node's `icon` field MUST follow a dual-mode convention compatible with the link-button-widget:

- A URL (starts with `/` or `http`) MUST render as an `<img>` element with `src=icon`
- A bare name MUST render as an inline label (the runtime panel renders the name; the admin editor uses a text input for the icon field)
- An empty or null value MUST render no icon

Icon size MUST be consistently 24 px square across all nav items.

#### Scenario: Icon from URL renders as img
- GIVEN a node with `icon: '/apps/mydash/icons/custom.png'` and `label: 'Portal'`
- WHEN the panel renders
- THEN an `<img src="/apps/mydash/icons/custom.png">` element MUST appear left of the label

#### Scenario: Icon name renders inline
- GIVEN a node with `icon: 'briefcase'` and `label: 'Business'`
- WHEN the panel renders
- THEN the icon name MUST appear as inline text/label left of the label

#### Scenario: Missing icon renders no icon
- GIVEN a node with `icon: null` and `label: 'Help'`
- WHEN the panel renders
- THEN no icon MUST be visible
- AND only the label MUST be shown

### Requirement: REQ-ONAV-007 Admin editor

The system MUST provide an admin editor UI (mounted inside the existing MyDash admin section) with the following features:

1. A tree builder displaying the current org-nav tree (or empty state if none exists)
2. Per-node controls:
   - Inline editing of `label`, `url`, `icon`, `openInNewTab` directly in each row
   - Move-up / Move-down buttons (drag-and-drop is a planned UX enhancement; the spec scenarios are satisfied by explicit reorder buttons)
   - Delete button
   - Group-visibility selector ("Visible to everyone" toggle plus a multi-select populated from the injected `allGroups` payload, falling back to a comma-separated text input)
3. Add buttons:
   - "Add section" (creates a node with `url = null`, `label = "New section"`, `children = []`)
   - "Add link" (creates a node with `url = "/"`, `label = "New link"`)
   - "Add child" (per-row; disabled when adding would exceed depth 3)
4. Language selector (NL / EN) — switching reloads the working tree from the selected language file
5. Position selector (Hidden / Left / Right / Top) — selecting a value calls the position update endpoint
6. Save button: calls `PUT /api/admin/org-navigation` with the edited tree; on success a transient confirmation banner appears; on error (depth exceeded, duplicate id, etc.) the error message is displayed and the tree is NOT persisted

#### Scenario: Drag/Move to reorder at same level
- GIVEN the editor shows 3 top-level sections A, B, C
- WHEN the user clicks Move-up on section B
- THEN the tree order MUST become [B, A, C]

#### Scenario: Add child enforces depth limit
- GIVEN a tree already at max depth 3
- WHEN the user views the deepest row
- THEN the "Add child" button MUST be disabled
- AND its tooltip MUST contain `'Tree depth cannot exceed 3 levels'`

#### Scenario: Group visibility multi-select
- GIVEN the editor is open on a node with `groupVisibility: ['marketing', 'sales']`
- WHEN the user opens the group visibility selector
- THEN options MUST be available for all NC groups, with 'marketing' and 'sales' pre-selected
- AND a "Visible to everyone" toggle MUST be available to clear the array

#### Scenario: Save valid tree persists
- GIVEN the user edits the tree (add 2 sections, reorder, set group visibility)
- WHEN the user clicks Save
- AND no validation errors occur
- THEN `PUT /api/admin/org-navigation` MUST be called and the tree written to `IAppData('mydash')/org-navigation/{lang}.json`
- AND a success message MUST appear

#### Scenario: Save with error prevents persist
- GIVEN the edited tree violates depth limit or contains duplicate ids
- WHEN the user clicks Save
- THEN validation errors MUST appear on the editor
- AND the tree MUST NOT be persisted

### Requirement: REQ-ONAV-008 Empty tree and no-visible-nodes handling

If the org-nav tree is empty (no nodes), OR if the user has no visible nodes after group-visibility filtering, the `OrgNavigationPanel.vue` MUST NOT render any visible content. The rail/drawer MUST not be displayed even if the position setting is `'left'`, `'right'`, or `'top'`.

This prevents visual clutter when the tree is disabled or not yet configured. The store-level `shouldRender` getter encodes the rule.

#### Scenario: Empty tree renders nothing
- GIVEN `org-navigation/nl.json` contains an empty array `[]`
- AND `org_navigation_position = 'left'`
- WHEN the app renders
- THEN no org-nav rail MUST be visible

#### Scenario: Filtered to zero nodes renders nothing
- GIVEN the tree has 1 section with `groupVisibility: ['admin']`
- AND the user is not an admin
- WHEN the user views the app
- THEN no org-nav rail MUST be visible (all nodes filtered out)

#### Scenario: Partial filtering still renders visible nodes
- GIVEN the tree has 2 sections: one visible to all, one restricted to admins
- AND the user is not an admin
- WHEN the user views the app
- THEN the first section MUST be visible in the rail
- AND the restricted section MUST NOT be visible

### Requirement: REQ-ONAV-009 Active item detection

The `OrgNavigationPanel.vue` MUST detect and highlight the currently active node based on the current page URL. A node is considered active if:

- The node has a non-null `url` field, AND
- The current page URL exactly matches the node's `url`, OR
- The current page URL starts with the node's `url` followed by a path-segment boundary (`/`, `?`, `#`, or end-of-string)

The active node MUST receive an `org-nav-item--active` CSS class. Sections containing an active descendant MUST auto-expand on mount and MAY receive a `org-nav-item--has-active-child` style.

#### Scenario: Exact URL match
- GIVEN a node with `url: '/apps/mydash/policies'`
- AND the current page is exactly `/apps/mydash/policies`
- WHEN the panel renders
- THEN the node MUST have the `org-nav-item--active` class

#### Scenario: Prefix match with path-segment boundary
- GIVEN a node with `url: '/apps/mydash/dashboards'`
- AND the current page is `/apps/mydash/dashboards/sales/details`
- WHEN the panel renders
- THEN the node MUST have the `org-nav-item--active` class

#### Scenario: Prefix without boundary does not match
- GIVEN a node with `url: '/apps/mydash/hub'`
- AND the current page is `/apps/mydash/hubris`
- WHEN the panel renders
- THEN the node MUST NOT have the `org-nav-item--active` class

#### Scenario: Parent auto-expands if child is active
- GIVEN a section "Dashboards" with a child "Sales Dashboard"
- AND the child has `url: '/apps/mydash/dashboards/sales'`
- AND the user is on `/apps/mydash/dashboards/sales`
- WHEN the panel renders
- THEN the parent section MUST be expanded
- AND the child MUST have the `org-nav-item--active` class

### Requirement: REQ-ONAV-010 Mobile responsive layout

At viewport widths less than 800 px, the org-nav rail MUST collapse to a hamburger button. Clicking the hamburger MUST open the org-nav tree as a slide-in drawer that overlays the main content. The drawer MUST include:

- A close button
- The full tree rendered as per REQ-ONAV-005

At 800 px and above, the drawer MUST close and the full rail/sidebar MUST be visible per the position setting (REQ-ONAV-004). The breakpoint is implemented as a CSS `@media (max-width: 799px)` query — no JS resize listener is required.

Internally, the tree structure and content MUST remain the same; only the container and toggle mechanism change.

#### Scenario: Mobile hamburger visible
- GIVEN the viewport width is 600 px
- AND the org-nav tree is non-empty
- WHEN the app renders
- THEN a hamburger button MUST appear (no full rail visible)

#### Scenario: Click hamburger opens drawer
- GIVEN the hamburger button is visible
- WHEN the user clicks the hamburger
- THEN a drawer MUST slide in from the side, showing the full tree

#### Scenario: Close drawer on selection
- GIVEN the drawer is open and showing the tree
- WHEN the user clicks a node with a URL
- THEN the URL MUST be navigated to
- AND the drawer MUST auto-close

#### Scenario: Desktop rail visible at 800px
- GIVEN the viewport width is 800 px or greater
- AND the org-nav tree is non-empty
- AND `org_navigation_position = 'left'`
- WHEN the app renders
- THEN the full rail MUST appear on the left side
- AND no hamburger button MUST be visible

### Requirement: REQ-ONAV-011 URL sanitisation and validation

When admin saves an org-nav tree via `PUT /api/admin/org-navigation`, each node's `url` field (if present) MUST be validated to reject potentially dangerous schemes:

- Reject URLs starting with `javascript:` (case-insensitive)
- Reject URLs starting with `data:` (case-insensitive)
- Reject URLs starting with `vbscript:` (case-insensitive)
- Allow all other schemes: `http`, `https`, relative paths, and fragment-only URLs

Return HTTP 400 with the message `'URL scheme is not allowed'` if validation fails. This protects against XSS and other injection attacks via the admin editor.

#### Scenario: JavaScript URL rejected
- GIVEN a node with `url: 'JavaScript:alert("xss")'`
- WHEN the admin saves
- THEN HTTP 400 MUST be returned with error message `'URL scheme is not allowed'`

#### Scenario: Data URL rejected
- GIVEN a node with `url: 'data:text/html,<script>alert(1)</script>'`
- WHEN the admin saves
- THEN HTTP 400 MUST be returned

#### Scenario: HTTPS URL allowed
- GIVEN a node with `url: 'https://example.com/secure'`
- WHEN the admin saves
- THEN the URL MUST be accepted and persisted

#### Scenario: Relative path allowed
- GIVEN a node with `url: '/apps/mydash/dashboards/sales'`
- WHEN the admin saves
- THEN the URL MUST be accepted

### Requirement: REQ-ONAV-012 Internationalization

All user-facing strings in the org-nav tree editor and panel MUST support i18n (Dutch and English at minimum, per company standard). Strings include:

- Admin section title: "Organization navigation"
- Editor controls: "Add section", "Add link", "Edit", "Delete", "Save", "Move up", "Move down", "Add child", "Visible to everyone", "Group ids, comma separated"
- Panel labels: "Organization navigation", "Open organization navigation", "Close navigation", "Navigation"
- Position selector: "Position", "Hidden", "Left", "Right", "Top"
- Language selector: "Language", "Dutch", "English"
- Empty / success / error messages

Translations MUST be stored in `l10n/{nl,en}.json` and `l10n/{nl,en}.js` and loaded via Nextcloud's i18n integration. Vue components MUST use the `t()` function from `@nextcloud/l10n` for all user-visible text.

#### Scenario: Dutch translation available
- GIVEN the user's language is set to Dutch
- WHEN the navigation editor loads
- THEN labels, buttons, and messages MUST be in Dutch (e.g. "Sectie toevoegen" for "Add section")

#### Scenario: English translation fallback
- GIVEN a string has no Dutch translation
- WHEN the user's language is Dutch
- THEN the English translation MUST be displayed (or the source key as a final fallback)

## Architecture Notes

### Service surface

`OrgNavigationService` (lib/Service/OrgNavigationService.php) owns:
- `getTree(language)` — read + decode the per-language JSON file
- `setTree(tree, language)` — validate then wholesale-replace
- `validateTree(tree)` — depth, UUID, duplicates, label, URL scheme, group-visibility shape
- `filterTreeByUserGroups(tree, userId)` — recursive group-based filter (delegates to `AdminTemplateService::getUserGroupIdsFor()` per REQ-TMPL-013)
- `sanitiseUrl(url)` — central URL-scheme guard

### Controller surface

`AdminOrgNavigationController` (lib/Controller/AdminOrgNavigationController.php) routes:
- `GET  /api/admin/org-navigation?lang={nl|en}` — any logged-in user
- `PUT  /api/admin/org-navigation?lang={nl|en}` — admin-only
- `GET  /api/admin/org-navigation/position` — any logged-in user
- `PUT  /api/admin/org-navigation/position` — admin-only

The `/position` routes are registered BEFORE the bare `/org-navigation` routes so the literal `position` segment matches before any wildcard parsing.

### Frontend surface

- `src/stores/orgNavigation.js` — Pinia store with `tree`, `language`, `position`, `loading`, `error` state and `fetchTree`/`updateTree`/`fetchPosition`/`updatePosition` actions plus `visibleTree`/`isEmpty`/`shouldRender` getters
- `src/components/OrgNavigationPanel.vue` — runtime rail/drawer mounted by `WorkspaceApp.vue`; renders `OrgNavigationItem` recursively
- `src/components/OrgNavigationItem.vue` — recursive node renderer (link / section / icon resolution / active-state detection)
- `src/components/admin/OrgNavigationEditor.vue` — admin editor mounted inside `AdminSettings.vue`; uses `OrgNavigationEditorRow.vue` recursively

## Notes

- No conflict with personal navigation (`dashboard-switcher-sidebar`) — two separate surfaces.
- Group visibility uses Nextcloud's native group membership; hidden nodes cascade to children.
- Mobile collapse to drawer is automatic via responsive CSS; no separate mobile branch in tree structure.
- Icon resolution mirrors the link-button-widget convention (URL-vs-name discriminator).
- Position setting applies globally; per-user position preferences are out of scope for this capability.
