# Design — Container Widget

## Context

MyDash dashboards are built on a flat 12-column GridStack grid where every widget competes for the same real estate. Authors wanting to group related widgets (e.g. a section header + four KPI tiles, or a card with related charts) must either accept a sprawling layout or manually manage positioning. A container widget solves this by allowing dashboard authors to nest a sub-grid inside a single outer-grid cell, preserving the move-as-one-unit drag semantics while enabling logical grouping.

REQ-GRID-005 and REQ-WDG-010 already declare the intent for widget composition and picker integration. This change formalises the container type: its data shape, rendering, edit-mode behaviour, and recursion limits.

## Goals / Non-Goals

**Goals:**

- Enable logical grouping of widgets (heading + content) without flattening the layout
- Preserve drag-as-one-unit semantics for the container itself on the outer grid
- Support nested containers up to depth 3 (container → container → container → widgets)
- Ensure the inner grid is fully editable in edit mode without affecting the outer grid
- Ensure the inner grid is non-interactive in view mode (clicks pass through to children)
- Use the existing `oc_mydash_widget_placements.content` JSON column — no schema migration

**Non-Goals:**

- Horizontal/vertical layout modes (containers always use the inner-grid 4-column layout)
- Overflow scrolling inside containers (inner grid is always fully visible)
- Container-to-container drag (widgets move within their grid, not across boundaries)
- Depth limit override (3 levels is a hard invariant, not configurable)
- Complex styling per container (only backgroundColor, padding, and title)

## Decisions

### D1: Recursion depth limit of 3 levels

**Decision**: Maximum container nesting is 3 container layers. A fourth nested container is rejected at the API boundary with HTTP 400.

**Alternatives considered:**

- No limit (recursive containment allowed infinitely). Rejected because unbounded nesting scales poorly (rendering complexity, user disorientation, ambiguous expectations around mobile layout).
- Limit of 2. Rejected because it's too restrictive for common use cases (dashboard section with subsections).
- Configurable limit per instance. Rejected because it complicates the invariant and makes behaviour unpredictable.

**Rationale**: Three levels (outer → container → container → container) is the sweet spot: it allows one level of nesting (container + children) on an already-nested container (e.g. a section inside another section), but prevents pathological deep nesting. The limit is enforced server-side so front-end mutations cannot violate it.

### D2: Inner grid is always 4 columns, locked to breakpoint

**Decision**: The inner grid uses `column: 4`, `cellHeight: 40`, `margin: 4`, and `disableOneColumnMode: true`. These are not configurable per container.

**Alternatives considered:**

- Make column count and cell height configurable per container. Rejected because it complicates the form (more fields) and creates layout brittleness (each container has different packing logic).
- Inherit outer-grid responsive breakpoints. Rejected because nested grids should be independent of viewport size — a container should have predictable layout regardless of whether the outer grid is in 1-column or 12-column mode.

**Rationale**: Fixed constants ensure consistent layout and packing across all containers. The 4-column inner grid is the sweet spot for mobile and desktop viewing: narrow enough for readability on small screens (each column ≈ 25% of container width), wide enough to tile multiple widgets side-by-side.

### D3: Children stored as nested `placements[]` in `content` blob

**Decision**: Container children are stored as `content.placements: WidgetPlacement[]` (same shape as top-level placements). No separate database table.

**Alternatives considered:**

- Separate table `oc_mydash_container_children` with foreign keys. Rejected because it complicates the API (separate CRUD endpoints), transactions (multi-table updates), and serialization (requires JOIN queries).
- Flatten all nested placements into the top-level `oc_mydash_widget_placements` table with a `parentId` column. Rejected because it conflates the two grids and makes drag-from-outer-to-inner impossible.

**Rationale**: Storing children in the `content` blob keeps the container self-contained. API calls are atomic (update one row), transactions are straightforward, and the widget registry can dispatch children recursively without special-casing containers.

### D4: Edit-mode inner grid is fully independent

**Decision**: In edit mode, mutations to the inner grid (add/remove/move/resize children) do NOT propagate to the outer grid. The outer grid is unaware of inner-grid changes until the container itself is saved.

**Alternatives considered:**

- Synchronise inner-grid mutations to the outer grid in real-time (push inner-grid state into the container's `content` as the user edits). Rejected because it complicates the edit flow (which grid is the user editing?) and creates undo/redo ambiguity.
- Allow drag-from-inner-to-outer (widgets can move between grids). Rejected because it breaks the container's promise of being a cohesive unit.

**Rationale**: Edit-mode isolation is the conceptual model users expect: a container is a scope, and editing inside that scope does not affect the outside. This aligns with how nested UI components work in most frameworks.

### D5: View mode is non-interactive (clicks pass through)

**Decision**: In view mode, clicks on the container's background or inner padding are intercepted, but clicks on child widgets fall through to the child's handler. The container itself has no click handler in view mode.

**Alternatives considered:**

- Container is a clickable region that expands/collapses to hide/show children. Rejected because it changes the consumption model (containers become interactive) and complicates edit vs. view mode.
- Use event `pointer-events: none` on the container wrapper. Rejected because it prevents the user from interacting with inner grid affordances (e.g. scrollbars if children overflow) and is too coarse-grained.

**Rationale**: The container is a pure layout container in view mode. Its only job is to frame the children and provide visual context (background, title). Children are fully interactive.

## Data Shape

Container placement `content` blob:

```json
{
  "type": "container",
  "content": {
    "placements": [
      {
        "uuid": "<uuid>",
        "type": "label",
        "content": { "text": "KPI" },
        "gridX": 0,
        "gridY": 0,
        "gridWidth": 2,
        "gridHeight": 2
      }
    ],
    "backgroundColor": "transparent",
    "padding": "medium",
    "title": "Section 1"
  }
}
```

Fields:

- `placements` (`WidgetPlacement[]`) — children rendered in the inner grid
- `backgroundColor` (string) — CSS colour; `transparent` by default
- `padding` (`'none' | 'small' | 'medium' | 'large'`) — preset spacing; `medium` by default
- `title` (string) — optional heading; empty string by default (no heading rendered)

## Seed data

Example container placements for testing and documentation:

1. **Section with two label widgets**
   - `title: "Quick Stats"`
   - `backgroundColor: "rgb(240, 240, 240)"`
   - `padding: "medium"`
   - `placements: [label("24"), label("+12%")]`

2. **Nested container** (depth 2)
   - `title: "Dashboard Section"`
   - `backgroundColor: "transparent"`
   - `padding: "small"`
   - `placements: [container({title: "Subsection", placements: [...]})]`

3. **Container with mixed widget types**
   - `title: "Metrics"`
   - `backgroundColor: "transparent"`
   - `padding: "large"`
   - `placements: [tile("Sales"), tile("Revenue"), label("Total")]`

4. **Minimal container** (empty, no title)
   - `backgroundColor: "transparent"`
   - `padding: "medium"`
   - `placements: []`

5. **Styled container**
   - `title: "Alerts"`
   - `backgroundColor: "rgb(255, 240, 245)"`
   - `padding: "none"`
   - `placements: [label("Critical"), label("Warning")]`
