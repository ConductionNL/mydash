# Tile Tasks

- [x] **T01**: Create `mydash_tiles` migration — adds the tile definition table with columns `id`, `user_id`, `title`, `icon` (VARCHAR 2000), `icon_type`, `background_color`, `text_color`, `link_type`, `link_value`, `created_at`, `updated_at` — `lib/Migration/Version001001Date20260203000000.php`

- [x] **T02**: Increase `icon` column size to 2000 characters to support full SVG path data — `lib/Migration/Version001002Date20260204000000.php`

- [x] **T03**: Add `tile_*` columns to `mydash_widget_placements` — adds `tile_type`, `tile_title`, `tile_icon`, `tile_icon_type`, `tile_background_color`, `tile_text_color`, `tile_link_type`, `tile_link_value` to allow placement rows to carry a full tile snapshot — `lib/Migration/Version001003Date20260204120000.php`

- [x] **T04**: Add `custom_icon` column to `mydash_widget_placements` — TEXT column supporting large icon values for widget placements — `lib/Migration/Version001004Date20260204150000.php`

- [x] **T05**: Implement `Tile` entity — `OCP\AppFramework\Db\Entity` subclass with all tile fields, typed constructor, and `jsonSerialize()` — `lib/Db/Tile.php`

- [x] **T06**: Implement `TileMapper` — `QBMapper` with `findByUserId()`, `findByIdAndUser()`, and `deleteByUserId()` methods — `lib/Db/TileMapper.php`

- [x] **T07**: Add tile-related fields to `WidgetPlacement` entity — `tileType`, `tileTitle`, `tileIcon`, `tileIconType`, `tileBackgroundColor`, `tileTextColor`, `tileLinkType`, `tileLinkValue`, `customIcon`; conditional inclusion in `jsonSerialize()` when `tileType` is not null — `lib/Db/WidgetPlacement.php`

- [x] **T08**: Implement `TileService` — `getUserTiles()`, `createTile()`, `updateTile()`, `deleteTile()` wrapping `TileMapper`; `createTile()` stamps `createdAt` / `updatedAt` — `lib/Service/TileService.php`

- [x] **T09**: Implement `TileUpdater` — `applyTileConfig()` sets all tile fields on a new placement from a data array; `applyTileUpdates()` applies partial field updates to an existing placement — `lib/Service/TileUpdater.php`

- [x] **T10**: Implement `PlacementService::addTileFromArray()` — creates a `WidgetPlacement` with synthetic `widget_id = 'tile-{uniqid()}'`, sets grid position from tile data, delegates tile field population to `TileUpdater::applyTileConfig()` — `lib/Service/PlacementService.php`

- [x] **T11**: Implement `PlacementUpdater` — `applyGridUpdates()` and `applyDisplayUpdates()` for updating grid position, visibility, title, icon, and style config on existing placements — `lib/Service/PlacementUpdater.php`

- [x] **T12**: Implement `RequestDataExtractor::extractTileData()` — extracts `title`, `icon`, `iconType`, `bgColor`, `txtColor`, `linkType`, `linkVal`, `gridX`, `gridY` from request parameters with defaults — `lib/Controller/RequestDataExtractor.php`

- [x] **T13**: Implement `RequestDataExtractor::extractPlacementData()` — extracts all known placement update fields (grid, display, and all `tile*` fields) as a filtered non-null map — `lib/Controller/RequestDataExtractor.php`

- [x] **T14**: Implement `TileApiController` — `index()`, `create()`, `update()` (with JSON body or parameter resolution via `resolveUpdateData()`), and `destroy()` — `lib/Controller/TileApiController.php`

- [x] **T15**: Register tile API routes — `GET /api/tiles`, `POST /api/tiles`, `PUT /api/tiles/{id}`, `DELETE /api/tiles/{id}` — `appinfo/routes.php`

- [x] **T16**: Implement `WidgetApiController::addTile()` — delegates to `WidgetService::addTileFromArray()` using `RequestDataExtractor::extractTileData()`; enforces `canAddWidget` permission check — `lib/Controller/WidgetApiController.php`

- [x] **T17**: Register tile placement route — `POST /api/dashboard/{dashboardId}/tile` mapped to `widget_api#addTile` — `appinfo/routes.php`

- [x] **T18**: Implement `TileWidget.vue` — renders a placed tile from placement snapshot fields; supports `iconType` values `svg`, `class`, `url`, `emoji`; injects per-tile `<style>` block at mount to override NL Design System `!important` color rules — `src/components/TileWidget.vue`

- [x] **T19**: Implement `TileCard.vue` — compact tile card for use in the tile library; renders all four icon types; shows edit/remove buttons on hover in edit mode — `src/components/TileCard.vue`

- [x] **T20**: Implement `TileEditor.vue` — `NcModal`-based form with title, icon picker (`NcSelect` with MDI + NL Design System icons), background color picker, text color picker, URL field, live preview, and Save/Cancel/Delete actions — `src/components/TileEditor.vue`

- [x] **T21**: Populate icon picker with MDI SVG path options — imports 24 icons from `@mdi/js` and maps them to `{ id, label, icon }` objects; saves the SVG path string as the `icon` value — `src/components/TileEditor.vue`

- [x] **T22**: Add NL Design System icon options to TileEditor — 27 government-design icons loaded from `/apps/nldesign/img/icons/{Name}.svg`; rendered as `<img>` in picker; stored with `iconType: 'url'` — `src/components/TileEditor.vue`

- [x] **T23**: Implement tile detection in `DashboardGrid.vue` — `isTilePlacement(placement)` checks `tileType !== null`; routes to `TileWidget` vs `WidgetWrapper` accordingly; emits `tile-edit` event — `src/components/DashboardGrid.vue`

- [x] **T24**: Implement `getTileData(placement)` helper in `DashboardGrid.vue` — maps `tile*` fields from placement to the object shape expected by `TileWidget` — `src/components/DashboardGrid.vue`

- [x] **T25**: Implement `useTileStore` (Pinia) — `loadTiles`, `createTile`, `updateTile`, `deleteTile` backed by `api.js` tile endpoints; manages `tiles[]` array state — `src/stores/tiles.js`

- [x] **T26**: Add tile API methods to `api.js` — `getTiles()`, `createTile(data)`, `updateTile(id, data)`, `deleteTile(id)`, `addTile(dashboardId, data)` — `src/services/api.js`

- [x] **T27**: Integrate tile editor into `Views.vue` — `openTileEditor()`, `openTileEditorForEdit(placement)`, `closeTileEditor()`, `saveTile()`, `deleteTile()`; `openTileEditorForEdit` maps placement `tile*` fields back to tile-shaped object; `saveTile` branches on `editingTile` to either update a placement or create a new placement — `src/views/Views.vue`

- [x] **T28**: Load tile store on app init — `useTileStore().loadTiles()` called in `Views.vue` `created()` hook alongside dashboard and widget loads — `src/views/Views.vue`

- [x] **T29**: Wire tile editor trigger from `WidgetPicker.vue` — `@add-tile` event from picker calls `openTileEditor()` in `Views.vue` — `src/views/Views.vue`
