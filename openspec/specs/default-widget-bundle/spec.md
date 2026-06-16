---
capability: default-widget-bundle
status: implemented
---

# Default Widget Bundle Specification

## Purpose

Every newly-created personal dashboard ships with a preconfigured set
of four widget placements so the user lands on a non-empty grid.
Empty grids on first-create were a documented friction point — the
"No dashboard yet" empty-state CTA flashed on every cold load and new
dashboards rendered as a blank canvas with no way to discover the
widget catalog.

This spec covers the seed bundle on user-initiated dashboard creation
plus the loading-shim that hides the empty-state during initial fetch.

## Context

Two related changes ship under one capability because they address
the same cold-load gap:

1. **Seed bundle** — three preconfigured `tile` widgets
   (Conduction, Sendent, Nextcloud) on the top row plus a `files`
   widget below, applied on every user-initiated dashboard creation.
2. **Loading shim** — `Views.vue` renders `NcLoadingIcon` while the
   store's `loading` flag is set and `activeDashboard` is null,
   hiding the empty-state until `loadDashboards()` resolves.

Admin-template dashboards intentionally bypass the seed (templates
ship the widget set their author intended) and the bootstrap
`tryCreateFromTemplate()` path keeps its own role-default-driven
seed.

## Requirements

### Requirement: REQ-DWB-001 Four widgets seeded on user-initiated create

WHEN a user creates a new dashboard via `POST /api/dashboard` (e.g.
the sidebar's "+" button)
THEN the controller MUST call `DashboardService::createDashboard()`
with `seedDefaults: true` AND the service MUST insert exactly four
widget placements via the new private `seedDefaultWidgets()` helper:

| Position | widgetId | tileType | tileTitle | Grid (x,y,w,h) |
|---|---|---|---|---|
| 0 | `tile` | `preset` | `Conduction` | (0, 0, 4, 3) |
| 1 | `tile` | `preset` | `Sendent` | (4, 0, 4, 3) |
| 2 | `tile` | `preset` | `Nextcloud` | (8, 0, 4, 3) |
| 3 | `files` | NULL | NULL | (0, 3, 12, 5) |

Conduction tile MUST link to `https://conduction.nl` with the
Conduction PNG asset. Sendent tile MUST link to
`https://sendent.com` with the Sendent PNG asset. Nextcloud tile
MUST link to `https://nextcloud.com` with the `icon-nextcloud` CSS
class (`tileIconType: 'class'`).

### Requirement: REQ-DWB-002 `tileType='preset'` is required for serialization

`WidgetPlacement::jsonSerialize()` only emits the flat `tile*`
columns when `tileType !== null`. The seeded tiles MUST set
`tileType='preset'` so the renderer's flat-column path receives the
title / icon / link fields.

The `'preset'` sentinel intentionally differs from the legacy
`'custom'` value used by `oc_mydash_tiles` placements — `'custom'`
routes through the pre-registry tile path in `DashboardGrid.vue`,
while `'preset'` keeps the placements on the registry-backed
`TileWidget` renderer (REQ-WDG-022).

### Requirement: REQ-DWB-003 Bootstrap path keeps `seedDefaults: false`

The first-login bootstrap (`DashboardService::tryCreateFromTemplate()`)
calls `createDashboard(..., seedDefaults: false)` because it has its
own role-default-driven seed via
`RoleFeaturePermissionService::seedLayoutFromRoleDefaults()`. The
bootstrap fallback `createDefaultPlacements()` is now a thin
delegate to `seedDefaultWidgets()` so first-login users get the same
four-widget bundle when no admin template applies.

### Requirement: REQ-DWB-004 Admin-template path bypasses the seed

WHEN an admin template applies (the
`DashboardResolver::handleTemplateResult()` path)
THEN the resolver MUST seed the dashboard with the template's
declared widget set, NOT the four-widget bundle. Template authors
own the layout; the default bundle is for users who didn't get an
admin template.

### Requirement: REQ-DWB-005 Create response returns placements envelope

`POST /api/dashboard` MUST return the same envelope shape as
`GET /api/dashboard` (`getActive`):

```json
{
  "dashboard": { ... },
  "placements": [ ... ]
}
```

The frontend store reads `response.data.placements ?? []` and
populates `widgetPlacements` directly — no second round-trip to
fetch placements after create. Callers running against an older
backend that omits the `placements` key fall back to `[]`
gracefully.

### Requirement: REQ-DWB-006 Loading shim hides empty-state during fetch

WHEN `Views.vue` is rendered AND `activeDashboard` is null AND the
dashboard store's `loading` flag is true
THEN the component MUST render an `NcLoadingIcon` shim instead of
the "No dashboard yet" empty-state.

Template precedence (top to bottom):

```
v-if="activeDashboard"          → DashboardGrid
v-else-if="loading"              → NcLoadingIcon (the shim)
v-else                           → NcEmptyContent (empty-state CTA)
```

This eliminates the flash where the empty-state rendered briefly
during the initial `loadDashboards()` call.

## Test coverage

- `tests/Unit/Service/DashboardServiceCreateDefaultsTest.php` —
  asserts the four placements ship with the correct widgetId,
  tileType, tileTitle, tileLinkValue, and grid coordinates.
- `src/views/__tests__/Views.loadingState.spec.js` — asserts the
  loading shim renders when `loading=true` and the empty-state
  renders when `loading=false`.
- `tests/integration/mydash.postman_collection.json` — Newman
  pins the create-response envelope: `placements.length === 4`
  with the per-tile shape and grid coordinates listed in
  REQ-DWB-001 above.

## References

- Implementation: PR #129 (default widget bundle + loading shim).
- Tile-renderer flat-column compatibility: `REQ-WDG-022` and
  `REQ-TILE-PLACEMENT` in the widgets capability.
- Frontend reference: [docs/features/default-widget-bundle.md](../../../docs/features/default-widget-bundle.md).
