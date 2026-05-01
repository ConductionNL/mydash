# Grid Layout - Design Document

## Architecture

### Frontend (primary)
- **Component**: `components/DashboardGrid.vue` - GridStack 10.3.1 wrapper
- **Library**: GridStack 10.3.1 (npm dependency)

### Configuration
- 12-column default grid (configurable per dashboard via gridColumns)
- Cell height: 80px fixed
- Margins: 12px horizontal and vertical
- Float mode enabled (items stay at exact position)
- Animation enabled
- Minimum widget size: 2x2

### Modes
- **View mode**: Static grid, no drag-and-drop
- **Edit mode**: Drag-and-drop enabled, resize handles visible

### Data Flow
1. Dashboard loads -> DashboardGrid receives placements as props
2. GridStack initializes with dashboard.gridColumns config
3. Each placement rendered at (gridX, gridY, gridWidth, gridHeight)
4. User drags/resizes in edit mode -> GridStack emits change event
5. Parent component persists position changes via API

### Key Design Decisions
- GridStack manages DOM directly (not Vue-reactive for performance)
- Position changes emitted as Vue events (not persisted by grid component)
- 0-based coordinate system (gridX: 0-11 for 12 columns)
- gs-id, gs-x, gs-y, gs-w, gs-h attributes on grid items
