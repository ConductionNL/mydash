# Widgets - Design Document

## Architecture

### Backend
- **Entity**: `Db\WidgetPlacement` - Grid position, styling, tile data, visibility flags
- **Mapper**: `Db\WidgetPlacementMapper` - CRUD, findByDashboardId, updatePositions, deleteByDashboardId
- **Service**: `Service\WidgetService` - Discovery via IManager, placement CRUD, item loading
- **Service**: `Service\PlacementService` - Low-level placement operations
- **Service**: `Service\PlacementUpdater` - Applies update data to placement entities
- **Service**: `Service\WidgetFormatter` - Formats IWidget into API response arrays
- **Service**: `Service\WidgetItemLoader` - Loads widget items via v1/v2 APIs
- **Controller**: `Controller\WidgetApiController` - REST API for widget operations

### Frontend
- **Store**: `stores/widgets.js` - Widget state management
- **Component**: `components/WidgetPicker.vue` - Widget selection UI
- **Component**: `components/WidgetRenderer.vue` - Renders widget content
- **Component**: `components/WidgetWrapper.vue` - Wrapper with title bar and controls
- **Component**: `components/WidgetStyleEditor.vue` - Custom styling UI
- **Service**: `services/widgetBridge.js` - Bridge to Nextcloud widget APIs

### Key Design Decisions
- Widgets discovered at runtime from Nextcloud IManager (no persistent widget registry)
- Placements store grid position + styling independently from widget definitions
- WidgetFormatter handles v1/v2 API differences transparently
- Tile placements reuse WidgetPlacement entity with tile-specific fields
