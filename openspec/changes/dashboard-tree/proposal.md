# Dashboard hierarchy and tree navigation

## Why

Today MyDash treats all dashboards as a flat list. Users with many dashboards experience cognitive overload — there is no way to organize them into logical groups or categories. Organizations with hierarchical departments or workflows need to mirror that structure in the dashboard interface (e.g., marketing/campaigns/q1, operations/finance/budget, etc.). This change introduces a parent-child hierarchy allowing unlimited nesting, computed path-based URLs, breadcrumb navigation, and a tree view API so the frontend can render collapsible folder structures.

## What Changes

- Add a nullable `parentUuid VARCHAR(36)` column to `oc_mydash_dashboards`. Root dashboards have `parentUuid = null`. Child dashboards reference their parent by UUID.
- Add a `slug VARCHAR(128)` column — URL-safe, unique per parent (siblings cannot share the same slug). Auto-generated from `name` on create when not supplied; admin/user can override.
- Add a `sortOrder INT` column controlling left-to-right sibling ordering. Default 0; ties broken alphabetically by `name`.
- Compute a `path` on render: slash-joined slug chain from root to this dashboard (e.g. `/marketing/campaigns/q1`). Path is computed, NOT stored.
- Compute breadcrumbs on render: ordered list of `{uuid, name, slug}` from root to this dashboard. Breadcrumbs are computed, NOT stored.
- Expose `GET /api/dashboards/tree` returning the full visible tree as nested objects `{uuid, name, slug, children: [...]}`.
- Expose `GET /api/dashboards/by-path/{*path}` to resolve slug chains (e.g., `/marketing/campaigns/q1`) to the dashboard at that path.
- Cycle prevention: setting `parentUuid` to any descendant MUST return HTTP 400.
- Depth cap: max 5 levels deep (root + 4 descendants). Setting a parent that would exceed this returns HTTP 400.
- Cascade on parent delete: deleting a dashboard with children MUST require an explicit `?cascade=true` query param; otherwise HTTP 409 with the count of children.
- Move semantics: changing `parentUuid` re-parents the subtree; the slug chain (and URL) of every descendant changes accordingly.

## Capabilities

### Modified Capabilities

- `dashboards`: adds REQ-DASH-011 (hierarchy and tree structure), REQ-DASH-012 (slug uniqueness and path resolution), REQ-DASH-013 (breadcrumb and path computation), REQ-DASH-014 (tree API endpoints), REQ-DASH-015 (cycle and depth prevention), REQ-DASH-016 (cascade delete guards). Existing REQ-DASH-001..010 are untouched.

## Impact

**Affected code:**

- `lib/Db/Dashboard.php` — extend entity with `parentUuid`, `slug`, `sortOrder` fields, path/breadcrumb computed getters
- `lib/Db/DashboardMapper.php` — add `findByParent(string $parentUuid)`, `findByPath(string $path)`, `findDescendants(string $uuid)` queries
- `lib/Service/DashboardService.php` — cycle/depth validation on update, slug auto-generation, cascade delete with guard
- `lib/Service/TreeService.php` — new service for building nested tree structures and path resolution
- `lib/Controller/DashboardController.php` — add `/api/dashboards/tree`, `/api/dashboards/by-path/{*path}` endpoints
- `appinfo/routes.php` — register two new routes
- `lib/Migration/VersionXXXXDate2026...php` — schema migration adding `parentUuid`, `slug`, `sortOrder` columns + composite index on `(parentUuid, slug)` and `sortOrder`
- `src/stores/dashboards.js` — tree and path resolution helpers in store
- `src/views/DashboardList.vue` or equivalent — update to render hierarchy (defer advanced UI to follow-up change; this change ships the backend)

**Affected APIs:**

- 2 new routes (`/tree`, `/by-path/{*path}`)
- Existing `GET /api/dashboards` and `GET /api/dashboard/{id}` unchanged — hierarchy is an opt-in feature via the new endpoints
- Existing `PUT /api/dashboard/{id}` and `DELETE /api/dashboard/{id}` handle `parentUuid` updates with cycle/depth guards

**Dependencies:**

- No new composer or npm dependencies

**Migration:**

- Zero-impact: migration adds nullable columns and new indexes. Existing rows get `parentUuid = NULL`, `slug = null`, `sortOrder = 0`. On first read, slug is auto-generated from name if null.
- No data backfill required.
