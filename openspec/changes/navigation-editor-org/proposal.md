# Organization-wide Navigation Editor Specification

## Why

MyDash currently provides no admin-curated, organization-wide navigation surface. The existing `dashboard-switcher-sidebar` shows only the dashboards a user owns or can access, making it personal and context-limited. Company resources, policy hubs, and shared tool panels have no first-class home. A dedicated organization-wide navigation tree — owned and curated by admins, group-aware, and persistent — closes this gap.

The new `navigation-editor-org` capability introduces a second navigation surface distinct from the personal dashboard list: an admin-controlled tree of links and sections, shared across the whole organisation with optional group-visibility filtering. Admins gain a centralized UI to build and maintain the org nav tree. Users see a collapsible panel or drawer (depending on viewport size and admin preference) with the org nav prominently featured alongside their personal dashboards.

## What Changes

- Introduce `OrgNavigationService` owning per-language JSON file storage, tree validation, group-based filtering, and URL sanitisation.
- Add `AdminOrgNavigationController` routing four REST endpoints: read/write tree (per language) and read/write position setting.
- Extend the admin section with `OrgNavigationEditor.vue` — a tree builder UI for create/edit/delete/reorder nodes, language switching, and position selection.
- Add `OrgNavigationPanel.vue` and `OrgNavigationItem.vue` — runtime rendering of the filtered tree as a navigable panel/drawer.
- Introduce `useOrgNavigationStore` Pinia store managing tree fetch, language selection, and position state.
- Implement responsive mobile collapse (hamburger + drawer at <800px viewport width).
- Implement active-item detection based on URL matching with path-segment-boundary rules.
- Add i18n for all user-facing strings in Dutch and English.
- Store the global position setting (`left`, `right`, `top`, `hidden`) in `mydash_admin_settings` key-value table.

## Capabilities

### New Capabilities

- `org-navigation` — owns all requirements REQ-ONAV-001..012 covering tree storage, admin editor, runtime rendering, group filtering, position setting, mobile responsiveness, URL sanitisation, and internationalization.

### Modified Capabilities

- `runtime-shell` — adds `<OrgNavigationPanel>` mount point and position-aware layout wiring.
- `admin-settings` — extends admin section with `OrgNavigationEditor` tab.

## Impact

**Affected code:**

- `lib/Service/OrgNavigationService.php` — core service for CRUD, validation, and filtering
- `lib/Controller/AdminOrgNavigationController.php` — REST API routes
- `src/stores/orgNavigation.js` — Pinia store for tree/position state
- `src/components/OrgNavigationPanel.vue` — runtime rail/drawer component
- `src/components/OrgNavigationItem.vue` — recursive node renderer
- `src/components/admin/OrgNavigationEditor.vue` — admin tree builder UI
- `src/components/admin/OrgNavigationEditorRow.vue` — per-node inline editor
- `src/views/WorkspaceApp.vue` — wire panel mount and position responsive layout
- `src/views/AdminSettings.vue` — add org-nav editor tab
- `src/stores/admin.js` (if needed) — admin store for editor state
- Translation catalogues: `l10n/nl.json`, `l10n/en.json`, `l10n/nl.js`, `l10n/en.js`

**Affected APIs:**

- New REST endpoints:
  - `GET /api/admin/org-navigation?lang={nl|en}` — fetch tree (any authenticated user)
  - `PUT /api/admin/org-navigation?lang={nl|en}` — save tree (admin-only)
  - `GET /api/admin/org-navigation/position` — read position (any authenticated user)
  - `PUT /api/admin/org-navigation/position` — write position (admin-only)

**Dependencies:**

- Nextcloud `IAppData` filesystem API (for tree JSON storage)
- Nextcloud `IGroupManager` (for group membership resolution)
- Nextcloud `IThrottler` or rate limiting (optional, for admin endpoint DDoS hardening)
- Pinia store library (already in use)
- No new npm dependencies beyond existing stack

**Migration:**

- Pure additive backend and frontend change.
- Tree storage initializes as empty array `[]` on first request.
- Position setting defaults to `'hidden'` (org nav opt-in via admin UI).
- No schema migration or app-config refactoring required.

## Notes

- Group visibility uses Nextcloud's native `IGroupManager`; hidden nodes cascade to children.
- Mobile collapse to drawer is automatic via CSS media query; no separate mobile branch in tree structure.
- The `groupVisibility: null` means "visible to all"; array means "restricted to these groups".
- Icon resolution mirrors the `link-button-widget` convention (URL-vs-name discriminator).
- The position setting applies globally; per-user position preferences are future work.
- No conflict with the personal `dashboard-switcher` — two separate navigational surfaces.
- Tree depth is capped at 3 levels (root → children → grandchildren) to prevent UI bloat.
