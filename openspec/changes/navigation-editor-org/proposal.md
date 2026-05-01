# Organization-wide Navigation Editor

An admin-curated org-wide navigation tree that is distinct from each user's personal sidebar list. Where the existing dashboard-switcher-sidebar shows the dashboards a user owns or can access, this capability adds a second navigation surface — an admin-controlled tree of links and sections shared across the whole organisation. Useful for company resources, policy hubs, and tools panels.

## Affected code units

- `src/stores/orgNavigation.js` — new Pinia store managing org-nav tree state and read/write operations
- `src/components/OrgNavigationPanel.vue` — new SFC rendering the filterable org-nav tree as a collapsible rail or drawer
- `src/views/AdminOrgNavigationEditor.vue` — new admin editor with drag-and-drop tree builder, group visibility multi-select, icon picker integration
- `src/App.vue` — mount `OrgNavigationPanel` as left/right/top rail or hidden (conditional on position setting)
- `lib/AdminSettingsService.php` — extend to store/retrieve `mydash.org_navigation_position` global setting (scalar; unchanged)
- `lib/OrgNavigationFileService.php` — new service: read/write `appdata/mydash/org-navigation-{lang}.json` (up to 5 MB), validate tree depth (max 3), URL sanitise, filter tree by user's group memberships via `IGroupManager`, resolve icon URLs
- `Controller/AdminOrgNavigationController.php` — new controller: `GET /api/admin/org-navigation` (read+filter), `PUT /api/admin/org-navigation` (admin-only, validate, persist)

## Why a capability

The dashboard-switcher-sidebar serves a fundamentally different purpose: it lists personalised content (dashboards a user owns or can access). The org-nav tree is a global administrative interface — not personalised, not user-searchable, purely admin-curated. This requires separate storage, separate filtering logic, separate UI components, and a distinct read-permission model (any logged-in user may read filtered tree; only admin may write). A new capability cleanly separates concerns.

## Approach

- **Storage** — per-language JSON files at `appdata/mydash/org-navigation-{lang}.json` (up to 5 MB each; not stored in app-config). v1 supports `nl` and `en`. The tree is wholesale-replaced per language via `PUT /api/admin/org-navigation?lang={lang}`. Each node is a UUID-identified object with `label`, `icon`, `url` (optional for section nodes), `openInNewTab`, `groupVisibility` (null = all users; array = restrict to groups — a MyDash-specific field, not inherited from the reference implementation), and `children`.
- **Filtering** — `GET /api/admin/org-navigation?lang={lang}` returns the full tree for that language but filters out nodes the user cannot see (per group memberships via `IGroupManager`). Cascading: hidden parents hide children. No filesystem ACL is used; group-membership checks are explicit.
- **Validation** — `PUT /api/admin/org-navigation` enforces max depth 3 (HTTP 400 on violation — stricter than reference), UUID id values, no duplicate ids, URL sanitisation (reject `javascript:`, `data:`), required fields per node.
- **Admin UI** — drag-and-drop tree editor in admin section with per-node group-visibility multi-select, icon picker (reusing existing icon-resolution logic), depth indicator.
- **Rendering** — Vue 3 SFC `OrgNavigationPanel.vue` mounted by `App.vue`. Responsive: rail at viewport ≥800px, hamburger+drawer at <800px. Position is configurable via `mydash.org_navigation_position` (left, right, top, hidden; default hidden). Active item detection based on URL match.
- **Empty tree** — if the tree is empty OR the user has no visible nodes after filtering, the rail is not rendered even if position is set.

## Capabilities

**New Capabilities:**

- `navigation-editor-org` (admin-curated org-wide navigation tree)

**Modified Capabilities:**

- `admin-settings` — gain new global settings key: `mydash.org_navigation_position` (scalar; the tree itself is stored as filesystem files, not in app-config)

## Notes

- No conflict with personal navigation (dashboard-switcher-sidebar) — two separate surfaces.
- Group visibility uses Nextcloud's native group membership; hidden nodes cascade to children.
- Mobile collapse to drawer is automatic via responsive CSS; no separate mobile branch in tree structure.
- Icon resolution reuses existing link-button-widget logic (URL images vs. MDI names).
- Position setting applies globally; per-user position preferences are out of scope for this capability.
