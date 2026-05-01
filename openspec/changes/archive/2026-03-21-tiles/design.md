# Custom Tiles - Design Document

## Architecture

### Backend
- **Entity**: `Db\Tile` - User-owned shortcut card with icon, colors, link
- **Mapper**: `Db\TileMapper` - CRUD, findByUserId, findByIdAndUser
- **Service**: `Service\TileService` - Business logic for tile CRUD
- **Controller**: `Controller\TileApiController` - REST API for tile management

### Frontend
- **Component**: `components/TileCard.vue` - Renders tile as clickable card
- **Component**: `components/TileEditor.vue` - Tile creation/editing form

### Data Flow
1. User creates tile definition via POST /api/tiles
2. Tile placed on dashboard via POST /api/dashboard/{id}/tile
3. PlacementService copies tile data inline onto WidgetPlacement
4. TileCard renders from placement's tile fields (independent snapshot)

### Key Design Decisions
- Tiles are simple static cards (no dynamic content like widgets)
- Tile placements store COPIES of tile data (not references)
- Changes to tile definition do NOT propagate to existing placements
- widgetId for tile placements: 'tile-' + uniqid()
- Icon types: class, url, emoji, svg
