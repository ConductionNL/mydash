# Tile Feature — Technical Design

## Overview

Custom tiles are reusable shortcut cards with a two-layer architecture: a **tile definition** (stored in `mydash_tiles`) and a **tile placement** (a row in `mydash_widget_placements` with `tile_type = 'custom'`). The placement carries a full snapshot of all tile fields so that the grid only needs to read a single table at render time. The tile definition table serves as the source of truth for the tile library but its fields are copied into the placement on creation.

---

## Class Map

### Backend

```
lib/
  Controller/
    TileApiController.php       — CRUD for tile definitions (/api/tiles)
    WidgetApiController.php     — addTile() places a tile onto a dashboard
    RequestDataExtractor.php    — extractTileData() / extractPlacementData()
    ResponseHelper.php          — shared HTTP response helpers

  Service/
    TileService.php             — createTile / updateTile / deleteTile (tile definitions)
    TileUpdater.php             — applyTileConfig() / applyTileUpdates() on WidgetPlacement
    PlacementService.php        — addWidget / addTileFromArray / updatePlacement / removePlacement
    PlacementUpdater.php        — applyGridUpdates() / applyDisplayUpdates() on WidgetPlacement
    WidgetService.php           — delegates addTileFromArray() to PlacementService

  Db/
    Tile.php                    — Entity: tile definition
    TileMapper.php              — findByUserId / findByIdAndUser / deleteByUserId
    WidgetPlacement.php         — Entity: placement row (widget OR tile)
    WidgetPlacementMapper.php   — findByDashboardId / updatePositions / getMaxSortOrder

  Migration/
    Version001001Date20260203000000.php   — creates mydash_tiles table
    Version001002Date20260204000000.php   — increases icon column to 2000 chars (SVG paths)
    Version001003Date20260204120000.php   — adds tile_* columns to mydash_widget_placements
    Version001004Date20260204150000.php   — adds custom_icon column to mydash_widget_placements
```

### Frontend

```
src/
  stores/tiles.js         — Pinia store: loadTiles / createTile / updateTile / deleteTile
  services/api.js         — getTiles / createTile / updateTile / deleteTile / addTile
  components/
    TileWidget.vue        — dashboard render component (reads from placement snapshot)
    TileEditor.vue        — modal for creating / editing a tile definition
    TileCard.vue          — compact card variant (used in tile library preview)
    DashboardGrid.vue     — decides TileWidget vs WidgetWrapper per placement
  views/
    Views.vue             — orchestrates tile editor open/save/delete, openTileEditorForEdit()
```

---

## Database Schema

### `mydash_tiles` (tile definitions)

| Column             | Type          | Constraints              | Notes                                 |
|--------------------|---------------|--------------------------|---------------------------------------|
| `id`               | BIGINT UNSIGNED | PK, auto-increment     |                                       |
| `user_id`          | VARCHAR(64)   | NOT NULL                 | Nextcloud user ID                     |
| `title`            | VARCHAR(255)  | NOT NULL                 |                                       |
| `icon`             | VARCHAR(2000) | NOT NULL                 | CSS class name, URL, emoji, or SVG path data |
| `icon_type`        | VARCHAR(20)   | NOT NULL, default 'class'| Values: `class`, `url`, `emoji`, `svg` |
| `background_color` | VARCHAR(7)    | NOT NULL, default '#0082c9' | Hex color                          |
| `text_color`       | VARCHAR(7)    | NOT NULL, default '#ffffff' | Hex color                          |
| `link_type`        | VARCHAR(20)   | NOT NULL                 | Values: `app`, `url`                  |
| `link_value`       | VARCHAR(1000) | NOT NULL                 | Nextcloud app path or external URL    |
| `created_at`       | DATETIME      | NOT NULL                 |                                       |
| `updated_at`       | DATETIME      | NOT NULL                 |                                       |

Indexes: PK on `id`; `mydash_tiles_user` on `user_id`.

Note: The spec describes a `uuid` column but the actual migration and entity do not include one. The `id` integer is used as the tile reference key throughout the implementation.

### `mydash_widget_placements` — tile-relevant columns

Tile placements share the same table as widget placements. A row is a tile placement when `tile_type IS NOT NULL`.

| Column                 | Type          | Constraints | Notes                                        |
|------------------------|---------------|-------------|----------------------------------------------|
| `widget_id`            | VARCHAR(255)  | NOT NULL    | Set to `tile-{uniqid()}` for tile placements |
| `tile_type`            | VARCHAR(20)   | nullable    | `'custom'` for tiles, NULL for widgets       |
| `tile_title`           | VARCHAR(255)  | nullable    |                                              |
| `tile_icon`            | VARCHAR(2000) | nullable    | Same icon formats as `mydash_tiles.icon`     |
| `tile_icon_type`       | VARCHAR(20)   | nullable    | `class`, `url`, `emoji`, `svg`               |
| `tile_background_color`| VARCHAR(7)    | nullable    |                                              |
| `tile_text_color`      | VARCHAR(7)    | nullable    |                                              |
| `tile_link_type`       | VARCHAR(20)   | nullable    |                                              |
| `tile_link_value`      | VARCHAR(1000) | nullable    |                                              |
| `custom_icon`          | TEXT          | nullable    | Added in migration 004; used for widget custom icons; not tile-specific |

---

## Tile vs Widget Distinction

| Aspect | Widget | Tile |
|--------|--------|------|
| Source of data | Nextcloud `IWidget` / `IAPIWidget` interface | Fields stored directly in the placement row |
| `widget_id` value | Nextcloud widget identifier string (e.g. `activity`) | `tile-{uniqid()}` (synthetic, non-functional) |
| `tile_type` column | NULL | `'custom'` |
| `tile_*` columns | NULL (ignored) | Populated with tile content |
| Render component | `WidgetWrapper.vue` + `WidgetRenderer.vue` | `TileWidget.vue` |
| Edit modal | `WidgetStyleEditor.vue` | `TileEditor.vue` |
| Content update | Via Nextcloud widget API | Via `PUT /api/widgets/{placementId}` with `tile*` fields |
| Separate definition record | No | Yes — `mydash_tiles` row (but currently decoupled from placement) |

Detection in `DashboardGrid.vue`:
```js
isTilePlacement(placement) {
  return placement.tileType !== null && placement.tileType !== undefined
}
```

---

## Data Flow

### Creating a tile (tile library)

```
TileEditor.vue  @save
  → Views.vue   saveTile(tileData)
    → useTileStore.createTile(tileData)   (if editingTile is null → new tile)
      → api.createTile(data)
        → POST /api/tiles
          → TileApiController::create()
            → TileService::createTile()
              → TileMapper::insert()
```

### Placing a tile on the dashboard

```
WidgetPicker.vue  @add-tile
  → Views.vue   openTileEditor()   (opens TileEditor in create mode)
    → saveTile(tileData)
      → useDashboardStore.addTileToDashboard(tileData)   (editingTile is null)
        → api.addTile(dashboardId, tileData)
          → POST /api/dashboard/{dashboardId}/tile
            → WidgetApiController::addTile()
              → RequestDataExtractor::extractTileData(request)
              → WidgetService::addTileFromArray(dashboardId, tileData)
                → PlacementService::addTileFromArray()
                  → new WidgetPlacement()
                  → TileUpdater::applyTileConfig(placement, tileData)
                  → WidgetPlacementMapper::insert()
```

The tile definition (`mydash_tiles`) and the placement snapshot are currently **independent**: creating a placement does not look up a tile definition record. The `TileService` (tile definitions) and `PlacementService` (placement + snapshot) operate separately. This means editing a tile definition via `PUT /api/tiles/{id}` does NOT automatically propagate to existing placements.

### Editing a placed tile

```
DashboardGrid.vue  @tile-edit(placement)
  → Views.vue  openTileEditorForEdit(placement)
    → converts placement.tile* fields → tileData object with id = placement.id
    → openTileEditor(tileData)   (editingTile = tileData)

TileEditor.vue  @save
  → Views.vue  saveTile(tileData)
    → this.editingTile exists
      → useDashboardStore.updateWidgetPlacement(editingTile.id, { tileTitle, tileIcon, ... })
        → api.updateWidgetPlacement(placementId, data)
          → PUT /api/widgets/{placementId}
            → WidgetApiController::updatePlacement()
              → PlacementService::updatePlacement()
                → TileUpdater::applyTileUpdates(placement, data)
                → WidgetPlacementMapper::update()
```

Editing a placed tile updates the placement row directly, not the tile definition row.

### Deleting a placed tile

```
TileEditor.vue  @delete
  → Views.vue  deleteTile()
    → removeWidget(editingTile.id)
      → useDashboardStore.removeWidgetFromDashboard(placementId)
        → api.removeWidget(placementId)
          → DELETE /api/widgets/{placementId}
            → WidgetApiController::removePlacement()
              → PlacementService::removePlacement()
                → WidgetPlacementMapper::delete()
```

---

## Icon Rendering

The `icon_type` / `tileIconType` field drives conditional rendering in `TileWidget.vue` and `TileCard.vue`.

| `iconType` value | Storage format | Render method |
|------------------|---------------|---------------|
| `svg`            | MDI or custom SVG path string (`M 0 0 L 24 24 ...`) | `<svg viewBox="0 0 24 24"><path :d="tile.icon" /></svg>` with `fill` set to `textColor` |
| `url`            | Full URL (`https://...`) or path to image file | `<img :src="tile.icon">` constrained to 64x64px with `object-fit: contain` |
| `emoji`          | Unicode emoji character (e.g. `📅`) | `<span class="tile-widget__emoji">{{ tile.icon }}</span>` at 64px font-size, `filter: none` to prevent Nextcloud icon filter |
| `class`          | Nextcloud CSS class name (e.g. `icon-folder`) | `<span :class="['icon', tile.icon]">` with `filter: brightness(0) invert(1)` to ensure white icons on colored backgrounds |

In `TileEditor.vue`, the user picks from a predefined icon list (`@mdi/js` SVG paths + NL Design System icon URLs). The selected icon's SVG path (or NL Design icon URL) is stored as the `icon` value at save time. The `iconType` is determined by:
- `'svg'` — icon selected from the MDI predefined list
- `'nldesign'` — icon URL from `/apps/nldesign/img/icons/{Name}.svg` (rendered as `<img>`)
- The editor defaults to `iconType: 'svg'`

NL Design System icons are loaded as `<img>` elements in `TileEditor.vue` using the `type === 'nldesign'` check, but are stored with `iconType: 'url'` in the database (the editor does not distinguish them at save time from other URL icons; TileCard uses `tile.iconType === 'url'` to render them as `<img>`).

### NL Design System color override

`TileWidget.vue` injects a per-tile `<style>` block at mount time to override NL Design System's aggressive `!important` CSS rules that would otherwise overwrite the tile's custom `textColor`:

```js
const style = document.createElement('style')
style.textContent = `.tile-widget[data-tile-id="${tile.id}"] .tile-widget__title {
  color: ${tile.textColor} !important;
}`
document.head.appendChild(style)
```

---

## API Endpoints

| Method | URL | Handler | Purpose |
|--------|-----|---------|---------|
| GET    | `/api/tiles` | `TileApiController::index()` | List tile definitions for current user |
| POST   | `/api/tiles` | `TileApiController::create()` | Create a tile definition |
| PUT    | `/api/tiles/{id}` | `TileApiController::update()` | Update a tile definition |
| DELETE | `/api/tiles/{id}` | `TileApiController::destroy()` | Delete a tile definition |
| POST   | `/api/dashboard/{dashboardId}/tile` | `WidgetApiController::addTile()` | Place a tile snapshot onto a dashboard |
| PUT    | `/api/widgets/{placementId}` | `WidgetApiController::updatePlacement()` | Update placed tile fields (shared with widget placement updates) |
| DELETE | `/api/widgets/{placementId}` | `WidgetApiController::removePlacement()` | Remove a tile placement |

Default values applied by `TileApiController::create()` when parameters are omitted:
- `title` → `'New Tile'`
- `icon` → `'icon-link'`
- `iconType` → `'class'`
- `backgroundColor` → `'#0082c9'` (Nextcloud primary blue)
- `textColor` → `'#ffffff'`
- `linkType` → `'url'`
- `linkValue` → `'#'`

---

## Key Design Decisions

1. **Placement snapshot model**: Tile fields are duplicated into the placement row. This avoids a JOIN on every dashboard load and allows a placed tile to be edited independently of the definition, but means tile definition edits do not auto-propagate to placements.

2. **Shared placements table**: Tiles reuse `mydash_widget_placements` rather than having a separate placements table. Discrimination is done via `tile_type IS NOT NULL`. This simplifies grid ordering, position management, and visibility toggling (all placement fields are available to both tiles and widgets).

3. **Synthetic `widget_id`**: Tile placements use `tile-{uniqid()}` as `widget_id`. This satisfies the `NOT NULL` constraint on the column and avoids ambiguity with real Nextcloud widget IDs without requiring a schema change.

4. **Frontend decoupling**: The `useTileStore` manages tile definitions (the library). The `useDashboardStore` manages placements (what appears on the grid). When a tile is placed, `addTileToDashboard` in `useDashboardStore` calls the placement API directly — it does not go through `useTileStore`. Editing a placed tile updates the placement, not the definition.

5. **Icon storage**: The `icon` column stores the raw value (path string, URL, or emoji character). The `icon_type` column provides the rendering hint. SVG path data can be up to 2000 characters (migration 002 increased this from the original 500).
