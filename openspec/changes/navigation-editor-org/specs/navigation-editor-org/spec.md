---
status: draft
---

# Organization-wide Navigation Editor

## ADDED Requirements

### Requirement: REQ-ONAV-001 Org navigation tree storage

The system MUST persist an organisation-wide navigation tree as a **JSON file on the Nextcloud filesystem** at a well-known path within the application's data directory. One file is stored per language: `appdata/mydash/org-navigation-{lang}.json` (e.g., `org-navigation-nl.json`, `org-navigation-en.json`). The implementation MUST NOT use a Nextcloud app-config key for the tree payload. The maximum accepted file size is **5 MB** (enforced on read and write).

> **v1 language scope:** MyDash v1 ships with support for `nl` (Dutch) and `en` (English). Both language files are maintained independently; changing one does not affect the other. The API accepts an optional `?lang=` query parameter (default: `nl`). A CLI copy command (`mydash:copy-org-navigation <source> <target>`) is a planned follow-up, not part of v1.

The tree is an ordered array of node objects, each with:

```json
{
  "id": "string (UUID format, required, must be unique)",
  "label": "string (required, non-empty)",
  "icon": "string (optional, icon name or URL)",
  "url": "string (optional, null for section nodes)",
  "openInNewTab": "boolean (optional, default: false)",
  "groupVisibility": "array of string (optional, null = visible to all users, populated array = restrict to listed group ids)",
  "children": "array of same shape (optional, max 3 levels total including root)"
}
```

> **NOTE — MyDash addition:** The `groupVisibility` field is a MyDash-specific design choice. The reference intranet product does not store group visibility in the navigation JSON; it relies on filesystem ACL (GroupFolder read permissions) to filter nodes. Because MyDash stores the tree in application data (not in per-user GroupFolders), no ACL is available to piggyback on. The per-node array approach (`null` = all users, populated array = restrict to listed group IDs) is the deliberate MyDash substitute. The filtering logic is implemented explicitly in `OrgNavigationService.php` using Nextcloud's `IGroupManager`.

The root level of the tree is an array; each child node follows the same schema recursively. The tree depth (including root) MUST NOT exceed 3 levels. This is a stricter limit than some reference implementations (which are unlimited) and is a deliberate MyDash choice to keep the admin UI manageable.

#### Scenario: Tree persists to file
- GIVEN an admin creates and saves an org-nav tree with 2 top-level sections and 3 subsections
- WHEN the file `appdata/mydash/org-navigation-nl.json` is read
- THEN it MUST contain valid JSON with the persisted tree structure

#### Scenario: Node id is uuid
- GIVEN an admin creates a node in the tree
- WHEN the node is saved
- THEN the node's `id` field MUST be a valid UUID (v4 or v5)

#### Scenario: Tree respects max depth 3
- GIVEN a tree with root items containing children containing grandchildren
- WHEN the tree is retrieved
- THEN the depth (root → children → grandchildren) MUST be exactly 3 levels

#### Scenario: Label is required per node
- GIVEN a node is created without a label
- WHEN the save is attempted
- THEN the system MUST return HTTP 400 with error message containing `'label'`

### Requirement: REQ-ONAV-002 Admin read API with group filtering

The system MUST expose a `GET /api/admin/org-navigation` endpoint accessible to any logged-in user. The endpoint MUST accept an optional `?lang=` query parameter (values: `nl`, `en`; default: `nl`) that selects which language file is read. The response MUST return the complete tree structure for that language, filtered to only include nodes the requesting user is permitted to see based on group memberships.

A node is visible if and only if:
- `groupVisibility` is `null` (visible to all), OR
- The user belongs to at least one group listed in `groupVisibility`

If a parent node is hidden, all its children are also hidden (cascading). Nodes with no visible children and no direct URL are rendered as empty sections or omitted (backend decision per rendering requirement REQ-ONAV-008).

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

The system MUST expose a `PUT /api/admin/org-navigation` endpoint accessible only to users with the admin role (e.g., `OC_PERMISSION_ADMIN` or app-level admin flag). The endpoint accepts an optional `?lang=` query parameter (values: `nl`, `en`; default: `nl`) that selects which language file to overwrite. The endpoint accepts a complete replacement tree for that language file (no PATCH) and validates before persisting.

Validation rules:
- The payload MUST be an array of node objects
- Each node MUST have a non-empty `label` and a valid UUID `id`
- No two nodes (at any depth) MUST have the same `id`
- Tree depth (from root to deepest leaf) MUST NOT exceed 3 levels; return HTTP 400 `'Tree depth cannot exceed 3 levels'` if violated
- The `url` field (if present) MUST NOT contain `javascript:` or `data:` schemes; return HTTP 400 `'URL scheme is not allowed'` if violated
- The `groupVisibility` field (if present) MUST be either null or a non-empty array of string group ids
- Return HTTP 403 if the requesting user is not an admin

On success, write the validated tree to `appdata/mydash/org-navigation-{lang}.json` (wholesale file replacement) and return HTTP 200 with the persisted tree (unchanged, not re-filtered). The 3-level depth limit returns HTTP 400 rather than silently truncating — this is intentionally stricter than reference implementations that silently discard excess depth.

#### Scenario: Admin saves valid tree
- GIVEN an admin provides a valid tree with 2 sections, each with 2 child links
- WHEN `PUT /api/admin/org-navigation` is called
- THEN the response MUST be HTTP 200
- AND the tree MUST be persisted to `appdata/mydash/org-navigation-nl.json` (default language)

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

The system MUST support a global Nextcloud app setting `mydash.org_navigation_position` (string, enum: `'left'|'right'|'top'|'hidden'`, default: `'hidden'`). This setting controls where the org-nav rail/drawer is rendered in the UI (if at all).

The setting MUST be configurable by admins via the admin editor (a dropdown or tab set on the navigation editor page). The position is global, not per-user; all users see the same position.

When `position = 'hidden'`, the org-nav rail is not rendered even if the tree is non-empty (effectively opting out).

#### Scenario: Position defaults to hidden
- GIVEN a new MyDash installation
- WHEN the app queries `mydash.org_navigation_position`
- THEN it MUST return `'hidden'`

#### Scenario: Admin changes position to left
- GIVEN an admin sets `mydash.org_navigation_position = 'left'`
- WHEN all users refresh the app
- THEN the org-nav rail MUST appear on the left side

#### Scenario: Position hidden suppresses rail
- GIVEN `mydash.org_navigation_position = 'hidden'`
- AND the tree is non-empty
- WHEN the app renders
- THEN no org-nav rail MUST be visible

### Requirement: REQ-ONAV-005 Vue panel rendering

The system MUST provide a Vue 3 SFC `OrgNavigationPanel.vue` that renders the filtered org-nav tree as a navigable panel. The panel MUST:

1. Fetch the tree from `GET /api/admin/org-navigation` on mount
2. Render each node as a list item with:
   - Icon (if present, 24 px square, resolved per REQ-ONAV-006)
   - Label (truncate or wrap if necessary)
   - URL link (if present, `href` attribute; click opens the URL per `openInNewTab`)
   - Children (expandable/collapsible section, indented, recursive)
3. Highlight the current active node based on URL match: if the current page URL matches or starts with the node's `url`, the node receives an `active` CSS class
4. Support expand/collapse per section (state tracked in component)
5. Render as a tree with visual nesting (indentation and connecting lines optional but recommended)

Desktop rendering: panel is a vertical rail (left, right, or top depending on REQ-ONAV-004 position). Mobile rendering: see REQ-ONAV-010.

#### Scenario: Tree renders with icons and labels
- GIVEN the org-nav tree contains a section "Company Resources" with icon "folder" and a child link "Handbook" with icon "file-document"
- WHEN `OrgNavigationPanel.vue` mounts
- THEN the section MUST show as an expandable item with the folder icon and label
- AND on expand, the child link MUST appear indented with the document icon and label

#### Scenario: Active item is highlighted
- GIVEN the tree contains a node with `url: '/apps/mydash/dashboards/sales'`
- AND the user is currently on the page `/apps/mydash/dashboards/sales/overview`
- WHEN the panel renders
- THEN the node MUST have the `active` CSS class (or visual indication of current location)

#### Scenario: Child link without url is not clickable
- GIVEN a section node with `url = null` (e.g., a section header)
- WHEN the user hovers or interacts with it
- THEN no link behavior MUST be triggered
- AND the node MUST render as static text or an expandable container

#### Scenario: openInNewTab respected
- GIVEN a node with `url: 'https://external.com'` and `openInNewTab: true`
- WHEN the user clicks the node
- THEN the URL MUST open in a new browser tab (HTML `target="_blank"` or `window.open()`)

### Requirement: REQ-ONAV-006 Icon resolution

Each node's `icon` field MUST follow the same dual-mode convention as the link-button-widget (REQ-LBN-002):

- A URL (starts with `/` or `http`) MUST render as an `<img>` element with `src=icon`
- A bare name (e.g., `'folder'`, `'file-document'`) MUST render via the shared `IconRenderer` component (MDI icon set)
- An empty or null value MUST render no icon

Icon size MUST be consistently 24 px square across all nav items. The icon MUST appear to the left of the label in the horizontal direction.

#### Scenario: Icon from URL renders as img
- GIVEN a node with `icon: '/apps/mydash/icons/custom.png'` and `label: 'Portal'`
- WHEN the panel renders
- THEN an `<img src="/apps/mydash/icons/custom.png">` element MUST appear left of the label

#### Scenario: Icon name renders via IconRenderer
- GIVEN a node with `icon: 'briefcase'` (an MDI name) and `label: 'Business'`
- WHEN the panel renders
- THEN the MDI briefcase icon MUST appear left of the label

#### Scenario: Missing icon renders no icon
- GIVEN a node with `icon: null` (or omitted) and `label: 'Help'`
- WHEN the panel renders
- THEN no icon MUST be visible
- AND only the label MUST be shown

### Requirement: REQ-ONAV-007 Drag-and-drop admin editor

The system MUST provide an admin editor UI (accessible at a route like `/apps/mydash/admin/navigation`) with the following features:

1. A drag-and-drop tree builder displaying the current org-nav tree (or empty state if none exists)
2. Per-node controls:
   - Edit button (inline or modal): edit `label`, `url`, `icon`, `openInNewTab`
   - Drag handle (standard drag-to-reorder within parent or across levels, respecting depth limit)
   - Delete button
   - Group visibility multi-select (list all Nextcloud groups; "Visible to everyone" = null; else array of selected group ids)
   - Icon picker (reuse existing link-button-widget icon picker logic, or integrate with link editor modal)
3. Add node buttons:
   - "Add section" (creates a node with `url = null`, `label = "New Section"`, `children = []`)
   - "Add link" (creates a node with required `url`, optional `label`, optional `icon`)
4. Visual depth indicator: warn if user drags a node to exceed depth 3
5. Save button: calls `PUT /api/admin/org-navigation` with the edited tree; on success, display confirmation; on error (e.g., depth exceeded, duplicate id), show error banner and prevent save

#### Scenario: Drag to reorder at same level
- GIVEN the editor shows 3 top-level sections A, B, C
- WHEN the user drags section B above section A
- THEN the tree order MUST become [B, A, C]

#### Scenario: Drag to nest under parent
- GIVEN 3 top-level sections with section A containing 2 children
- WHEN the user drags section B into section A (as a grandchild)
- AND the result would be 2 levels deep
- THEN the drag MUST succeed

#### Scenario: Depth limit warn on drag
- GIVEN a tree already at max depth 3
- WHEN the user attempts to drag a node to nest it further
- THEN a visual warning or disable MUST prevent the drag
- OR a tooltip MUST indicate `'Tree depth cannot exceed 3 levels'`

#### Scenario: Group visibility multi-select
- GIVEN the editor is open on a node with `groupVisibility: ['marketing', 'sales']`
- WHEN the user opens the group visibility selector
- THEN checkboxes or pills MUST show for all NC groups, with 'marketing' and 'sales' pre-checked
- AND a "Visible to everyone" toggle MUST be available to clear the array

#### Scenario: Save valid tree persists
- GIVEN the user edits the tree (add 2 sections, reorder, set group visibility)
- WHEN the user clicks Save
- AND no validation errors occur
- THEN `PUT /api/admin/org-navigation` MUST be called and the tree written to `appdata/mydash/org-navigation-{lang}.json`
- AND a success message MUST appear

#### Scenario: Save with error prevents persist
- GIVEN the edited tree violates depth limit or contains duplicate ids
- WHEN the user clicks Save
- THEN validation errors MUST appear on the editor
- AND the tree MUST NOT be persisted

### Requirement: REQ-ONAV-008 Empty tree and no-visible-nodes handling

If the org-nav tree is empty (no nodes), OR if the user has no visible nodes after group-visibility filtering (REQ-ONAV-002), the `OrgNavigationPanel.vue` MUST NOT render any visible content. The rail/drawer MUST not be displayed even if `mydash.org_navigation_position` is set to `'left'`, `'right'`, or `'top'`.

This prevents visual clutter when the tree is disabled or not yet configured.

#### Scenario: Empty tree renders nothing
- GIVEN `appdata/mydash/org-navigation-nl.json` contains an empty array `[]`
- AND `mydash.org_navigation_position = 'left'`
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
- The current page URL starts with the node's `url` as a prefix (e.g., current is `/apps/mydash/dashboards/sales/overview`, node's `url` is `/apps/mydash/dashboards/sales`)

The active node MUST receive an `active` CSS class (or similar visual indicator, e.g., background highlight, bold label, accent border). Parent nodes of an active child MUST be visually indicated as expanded (if collapsed) and may receive a "contains active descendant" style.

#### Scenario: Exact URL match
- GIVEN a node with `url: '/apps/mydash/policies'`
- AND the current page is exactly `/apps/mydash/policies`
- WHEN the panel renders
- THEN the node MUST have the `active` class

#### Scenario: Prefix match
- GIVEN a node with `url: '/apps/mydash/dashboards'`
- AND the current page is `/apps/mydash/dashboards/sales/details`
- WHEN the panel renders
- THEN the node MUST have the `active` class

#### Scenario: Parent auto-expands if child is active
- GIVEN a section "Dashboards" with a child "Sales Dashboard"
- AND the child has `url: '/apps/mydash/dashboards/sales'`
- AND the user is on `/apps/mydash/dashboards/sales`
- WHEN the panel renders
- THEN the parent section MUST be expanded (if it was collapsed)
- AND the child MUST have the `active` class

### Requirement: REQ-ONAV-010 Mobile responsive layout

At viewport widths less than 800 px, the org-nav rail MUST collapse to a hamburger button (icon + "Navigation" label or icon only). Clicking the hamburger MUST open the org-nav tree as a slide-in drawer (left-to-right or bottom-up, consistent with the position setting) that overlays the main content. The drawer MUST include:

- A close button (X or back arrow)
- The full tree rendered as per REQ-ONAV-005

At 800 px and above, the drawer MUST close and the full rail/sidebar MUST be visible per the position setting (REQ-ONAV-004).

Internally, the tree structure and content MUST remain the same; only the container and toggle mechanism change.

#### Scenario: Mobile hamburger visible
- GIVEN the viewport width is 600 px
- AND the org-nav tree is non-empty
- WHEN the app renders
- THEN a hamburger button MUST appear (no full rail visible)

#### Scenario: Click hamburger opens drawer
- GIVEN the hamburger button is visible
- WHEN the user clicks the hamburger
- THEN a drawer/modal MUST slide in from the side, showing the full tree

#### Scenario: Close drawer on selection
- GIVEN the drawer is open and showing the tree
- WHEN the user clicks a node with a URL
- THEN the URL MUST be navigated to
- AND the drawer MUST auto-close

#### Scenario: Desktop rail visible at 800px
- GIVEN the viewport width is 800 px or greater
- AND the org-nav tree is non-empty
- AND `mydash.org_navigation_position = 'left'`
- WHEN the app renders
- THEN the full rail MUST appear on the left side
- AND no hamburger button MUST be visible

### Requirement: REQ-ONAV-011 URL sanitisation and validation

When admin saves an org-nav tree via `PUT /api/admin/org-navigation`, each node's `url` field (if present) MUST be validated to reject potentially dangerous schemes:

- Reject URLs starting with `javascript:` (case-insensitive)
- Reject URLs starting with `data:` (case-insensitive)
- Reject URLs starting with `vbscript:` (case-insensitive)
- Allow all other schemes: `http`, `https`, relative paths (e.g., `/apps/mydash/...`), and fragment-only URLs (e.g., `#section`)

Return HTTP 400 with a message like `'URL contains a disallowed scheme'` if validation fails. This protects against XSS and other injection attacks via the admin editor.

#### Scenario: JavaScript URL rejected
- GIVEN a node with `url: 'JavaScript:alert("xss")'`
- WHEN the admin saves
- THEN HTTP 400 MUST be returned with error message

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

All user-facing strings in the org-nav tree editor and panel MUST support i18n (Dutch and English at minimum, per company standard). Strings to translate include:

- Admin page title: "Organization Navigation"
- Section labels: "Add section", "Add link", "Edit", "Delete", "Visible to everyone", "Group visibility"
- Error messages: "Tree depth cannot exceed 3 levels", "URL contains a disallowed scheme", "Label is required", etc.
- Mobile: "Navigation" (hamburger label)
- Empty states: "No navigation configured" or similar

Translations MUST be stored in the standard location (e.g., `l10n/en.json`, `l10n/nl.json`) and loaded via Nextcloud's i18n integration. The `OrgNavigationPanel.vue` and admin editor Vue components MUST use the `$t()` function for all user-visible text.

#### Scenario: Dutch translation available
- GIVEN the user's language is set to Dutch
- WHEN the navigation editor loads
- THEN all labels, buttons, and messages MUST be in Dutch

#### Scenario: English translation fallback
- GIVEN a string has no Dutch translation
- WHEN the user's language is Dutch
- THEN the English translation MUST be displayed (or the key itself if no English exists)

---

## Summary

The `navigation-editor-org` capability provides a robust, admin-curated, group-aware org-nav tree distinct from personal dashboard lists. It combines centralised storage, flexible group visibility, drag-and-drop editing, responsive mobile support, and security safeguards to deliver a professional navigation surface for organisations.
