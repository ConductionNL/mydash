# Default widget bundle

Every newly-created personal dashboard ships with four pre-configured
widgets so first-create users land on a non-empty grid. Plus a loading
shim that hides the empty-state CTA during the initial fetch.

## What you get

A new dashboard renders with this layout on a 12-column grid:

| Position | Widget | Where it points |
|---|---|---|
| top-left (0–3, 0–2) | Conduction tile | https://conduction.nl |
| top-middle (4–7, 0–2) | Sendent tile | https://sendent.com |
| top-right (8–11, 0–2) | Nextcloud tile | https://nextcloud.com |
| bottom (0–11, 3–7) | Files widget | user's root folder |

Users can immediately drag, resize, replace, or remove any of these.

## When it fires

| Path | Seeded? |
|---|---|
| Sidebar **+ Add dashboard** button (`POST /api/dashboard`) | ✅ yes |
| First-login bootstrap when no admin template applies | ✅ yes (via `createDefaultPlacements()` delegate) |
| First-login bootstrap when an admin template applies | ❌ no — template-defined widgets only |
| First-login bootstrap when role-defaults seed something | ❌ no — role-defaults take precedence |

## How it's wired

```
POST /api/dashboard
  → DashboardApiController::create()
    → DashboardService::createDashboard(seedDefaults: true)
      → DashboardService::seedDefaultWidgets($dashboardId)
        → 4 × placementMapper->insert()
  ← envelope: { dashboard, placements: [4 entries] }
```

The frontend store reads `response.data.placements ?? []` and
populates `widgetPlacements` directly — no second round-trip.

## Why `tileType='preset'`

Each tile has `tileType='preset'`. This is required for
[`WidgetPlacement::jsonSerialize()`](../../lib/Db/WidgetPlacement.php)
to emit the flat `tile*` fields the renderer reads. The `'preset'`
sentinel is intentionally distinct from the legacy `'custom'` value
used by the deprecated `oc_mydash_tiles` table — `'custom'` routes
through the pre-registry tile path in `DashboardGrid.vue`, while
`'preset'` keeps the placements on the registry-backed `TileWidget`
renderer.

## Loading shim

The empty-state CTA ("No dashboard yet" / Create dashboard) used to
flash during the initial fetch because `activeDashboard` is null
until `loadDashboards()` resolves. [Views.vue](../../src/views/Views.vue)
now renders `NcLoadingIcon` while `loading=true` and
`activeDashboard` is null:

```html
<DashboardGrid v-if="activeDashboard" … />
<div v-else-if="loading" class="mydash-loading">
    <NcLoadingIcon :size="48" />
</div>
<div v-else class="mydash-empty">
    <NcEmptyContent … />
</div>
```

The empty-state still renders for the legitimate "no dashboard
exists yet" case (e.g. personal-dashboards disabled by admin AND no
group default).

## Tutorials

- [Open MyDash for the first time](/docs/tutorials/user/first-launch)
- [Create a new dashboard](/docs/tutorials/user/create-dashboard)

## Spec reference

[`openspec/specs/default-widget-bundle/spec.md`](../../openspec/specs/default-widget-bundle/spec.md)
— REQ-DWB-001 through REQ-DWB-006.

## API endpoint shape

```http
POST /api/dashboard
Content-Type: application/json

{
  "name": "My Dashboard",
  "description": "Optional",
  "icon": null
}

→ 201 Created
{
  "data": {
    "dashboard": { "id": …, "uuid": …, "name": "My Dashboard", … },
    "placements": [
      {
        "widgetId": "tile",
        "tileType": "preset",
        "tileTitle": "Conduction",
        "tileLinkValue": "https://conduction.nl",
        "gridX": 0, "gridY": 0, "gridWidth": 4, "gridHeight": 3
      },
      … (3 more)
    ]
  }
}
```
