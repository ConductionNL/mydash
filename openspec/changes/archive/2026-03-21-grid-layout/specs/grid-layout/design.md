# Grid Layout — Technical Design

## Overview

The grid layout system is built on **GridStack 10.3.1** and rendered inside `DashboardGrid.vue`. The Vue component owns the GridStack instance lifecycle, translates GridStack change events into Pinia store updates, and delegates API persistence to `DashboardStore.updatePlacements()`. The store performs an immediate optimistic local update followed by an API call to `PUT /api/dashboard/{id}` with a `placements` array — the debounce strategy is described below.

---

## Component Architecture

```
Views.vue
  └─ DashboardGrid.vue          (GridStack host)
       ├─ TileWidget.vue         (tile placements: tileType === 'custom')
       └─ WidgetWrapper.vue      (widget placements)
            └─ WidgetRenderer.vue
                 ├─ NcDashboardWidget  (API widgets v1/v2)
                 ├─ TileWidget         (legacy tile path via widgetId prefix)
                 └─ <div ref="legacyWidgetContainer">  (legacy mount point)
```

**State ownership**

| Layer | Owns |
|---|---|
| `DashboardStore` (Pinia) | `widgetPlacements[]`, `activeDashboard`, `permissionLevel` |
| `Views.vue` | `isEditMode`, modal open/closed flags, `editingPlacement` |
| `DashboardGrid.vue` | `grid` (GridStack instance reference) |

---

## GridStack Integration

### Initialization (`DashboardGrid.vue: initGrid()`)

Called from `mounted()`. GridStack is initialised on the inner `.grid-stack` element found via `this.$refs.gridContainer.querySelector('.grid-stack')`.

```js
this.grid = GridStack.init({
  column: this.gridColumns,  // from Dashboard.gridColumns (default 12)
  cellHeight: 80,            // px
  margin: 12,                // px — applied horizontally and vertically
  float: true,               // items do not auto-compact upward
  animate: true,
  disableDrag: !this.editMode,
  disableResize: !this.editMode,
  removable: false,
}, containerEl)
```

`float: true` is intentional — items stay exactly where the user drops them and do not auto-compact upward, which matches the spec's free-placement model.

### Item Attributes

Each placement is rendered as a `.grid-stack-item` `<div>` with HTML attributes that GridStack reads:

| Attribute | Source field |
|---|---|
| `gs-id` | `placement.id` |
| `gs-x` | `placement.gridX` |
| `gs-y` | `placement.gridY` |
| `gs-w` | `placement.gridWidth` |
| `gs-h` | `placement.gridHeight` |
| `gs-min-w` | hard-coded `2` |
| `gs-min-h` | hard-coded `2` |

### Change Event Handler (`handleGridChange(items)`)

GridStack fires `change` after every drag or resize. The handler:

1. Maps over `this.placements` (current store snapshot passed as prop).
2. For each placement, looks for a matching `item` in the change list by comparing `String(item.id) === String(placement.id)`.
3. If found, spreads the updated `{ gridX, gridY, gridWidth, gridHeight }` from the GridStack node into a new placement object.
4. Emits `update:placements` with the full updated array.

`Views.vue` handles `@update:placements="updatePlacements"` which calls `DashboardStore.updatePlacements()`.

### Sync on External Placement Changes (`syncGridItems(placements)`)

When `placements` prop changes from outside (e.g. a widget is added via the picker), `DashboardGrid` reconciles the GridStack DOM:

- **Add**: if a placement ID is not found in `grid.engine.nodes`, calls `grid.makeWidget('[gs-id="..."]')` in `$nextTick` so Vue has already rendered the new DOM node.
- **Remove**: nodes whose IDs are absent from the new placements array are removed via `grid.removeWidget(el, false)` (second arg `false` keeps the DOM element so Vue can destroy it cleanly).

### Placement Key Strategy

The `v-for` key is `${placement.id}-${placement.updatedAt || Date.now()}-${JSON.stringify(placement.styleConfig || {})}`. This forces a full re-render of the item when style or timestamp changes, ensuring GridStack content updates correctly without a full grid reinitialisation.

---

## View Mode vs Edit Mode

### State

`isEditMode` is a local boolean on `Views.vue`. It defaults to `false` (view mode on load).

### Toggle

Clicking the floating Cog/Close button calls `toggleEditMode()`:

```js
toggleEditMode() {
  this.isEditMode = !this.isEditMode
  if (!this.isEditMode) {
    this.closeWidgetPicker()
    this.closeStyleEditor()
  }
}
```

### Propagation to GridStack

`DashboardGrid.vue` watches `editMode` prop:

```js
watch: {
  editMode(newVal) {
    if (this.grid) {
      if (newVal) { this.grid.enable() }
      else         { this.grid.disable() }
    }
  },
}
```

`grid.enable()` / `grid.disable()` toggle drag and resize on the live GridStack instance without reinitialising it.

### UI Controls in Edit Mode

- The floating control bar shows a **Close** icon with label "Close" when `isEditMode === true`, replacing the Cog icon.
- An **Add** button (Plus icon) also appears to open `WidgetPicker`.
- `WidgetWrapper.vue` receives `editMode` as a prop and conditionally renders a Cog action button in the widget header only when `editMode === true`.
- `TileWidget.vue` similarly shows a settings button (`.tile-widget__edit`) only when `editMode === true`.

### Permission Guard

`canEdit` computed in `Views.vue`:

```js
canEdit() {
  return this.permissionLevel !== 'view_only'
}
```

The Customize/Close `NcButton` is rendered with `v-if="canEdit"`, so users with `view_only` permission never see the edit button and the grid stays permanently in view mode. `permissionLevel` is sourced from the API response (`getEffectiveDashboard`) and stored in `DashboardStore`.

---

## Debounce Strategy

The debounce is **not implemented with a timer in the frontend**. Instead, the current pattern is:

1. Every GridStack `change` event triggers `handleGridChange` immediately.
2. `DashboardGrid` emits `update:placements` immediately.
3. `Views.vue` calls `DashboardStore.updatePlacements(placements)`.
4. The store performs an **immediate optimistic state update** (`this.widgetPlacements = placements`) and then calls `api.updateDashboard(id, { placements: placementsData })`.

**Note**: The spec defines a 500 ms debounce, but the current implementation fires a save on every change event without a debounce timer. The store sets `saving = true` / `saving = false` around the API call but does not coalesce rapid changes. This is a known gap between spec and implementation — the optimistic update keeps the UI responsive but the API may receive multiple calls during rapid drag operations.

The API endpoint that receives these saves is:

```
PUT /api/mydash/api/dashboard/{id}
Body: { placements: [{ id, gridX, gridY, gridWidth, gridHeight }, ...] }
```

`DashboardApiController.update()` extracts the `placements` key and delegates to `DashboardService.applyDashboardUpdates()`, which calls `WidgetPlacementMapper.updatePositions()`. This performs one SQL `UPDATE` per placement in a `foreach` loop (no batch transaction currently).

---

## Responsive Behaviour

GridStack is configured without a `minWidth` breakpoint. Column width is fluid: GridStack recalculates pixel widths from the container width divided by `column` count minus margins. The container is `.mydash-container` with `width: 100%` and `overflow: auto`.

The `DashboardGrid` CSS:

```css
.mydash-grid {
  width: 100%;
  min-height: 400px;
}
```

Grid cells contain either `TileWidget` (absolutely positioned, fills 100% of the cell) or `WidgetWrapper` (flex column layout with header/content/footer). Both set `height: 100%` to fill the GridStack-assigned cell height.

GridStack handles responsive column recalculation internally when the browser window resizes; Vue coordinates are stored in the GridStack `gs-x`/`gs-y`/`gs-w`/`gs-h` attributes and remain stable across container resizes.

---

## Tile vs Widget Placements

Placements serve a dual role: they represent either a **Nextcloud widget** (identified by a string `widgetId` matching registered widgets) or a **custom tile** (identified by `tileType === 'custom'`).

Detection in `DashboardGrid`:

```js
isTilePlacement(placement) {
  return placement.tileType === 'custom'
}
```

Tile placements carry their display config directly on the placement object (fields: `tileTitle`, `tileIcon`, `tileIconType`, `tileBackgroundColor`, `tileTextColor`, `tileLinkType`, `tileLinkValue`). Widget placements carry only `widgetId` and display config (`styleConfig`, `customTitle`, `showTitle`).

The backend creates tile placements with `widgetId = 'tile-' + uniqid()` and `tileType = 'custom'` in `PlacementService.addTileFromArray()`.

---

## Backend Data Flow

```
WidgetPlacement (DB entity)
  table: mydash_widget_placements
  grid columns: grid_x, grid_y, grid_width, grid_height
  ordered by: sort_order ASC, grid_y ASC, grid_x ASC

WidgetPlacementMapper.updatePositions(updates[])
  → one UPDATE per placement (sets grid_x, grid_y, grid_width, grid_height, updated_at)

DashboardService.applyDashboardUpdates(dashboard, data)
  → handles 'placements' key → calls updatePositions
  → also handles 'name', 'description', 'gridColumns'

DashboardApiController.update(id, ...)
  → checks canEditDashboard permission
  → delegates to DashboardService.updateDashboard
```

---

## CSS Architecture

GridStack injects inline `transform: translate(x, y)` and `width`/`height` styles onto `.grid-stack-item` elements. The app applies minimal overrides:

- `.grid-stack-item-content`: `background: var(--color-main-background)`, no border/shadow by default.
- Conditional border: `:deep(.grid-stack-item-content:has(.mydash-widget))` adds `1px solid var(--color-border)` only for widget (non-tile) cells.
- Drag placeholder: `.grid-stack-placeholder > .placeholder-content` uses `var(--color-primary-element-light)` fill with a dashed `var(--color-primary-element)` border — NL Design System compatible.

All colors use CSS variables from Nextcloud's design system, ensuring compatibility with the nldesign app's token overrides.
