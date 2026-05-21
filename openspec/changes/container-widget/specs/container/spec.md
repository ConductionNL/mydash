---
capability: container
delta: true
status: draft
---

# Container Widget — Delta from change `container-widget`

## Context

Containers are a new widget type that host a sub-grid of child widget placements inside a single outer-grid cell. Children are stored as nested `placements: WidgetPlacement[]` in the container's `content` blob and dispatched through the same widget registry that drives the top-level grid, so any registered widget type — including another container — can live inside one.

The container widget uses the existing `oc_mydash_widget_placements.content` JSON column with the discriminated shape `{type: 'container', content: {...}}`. No schema migration is required.

The inner grid is initialised via `useNestedGridManager` with distinct constants (4-column layout, 40px cell height, 4px margin, locked to 4 columns). Edit-mode mutations to the inner grid do not cascade to the outer grid. View-mode clicks pass through to child widgets.

The server enforces a maximum container nesting depth of 3 levels via `WidgetPlacementService::validateContainerDepth()`.

Frontend exports and integration points:

| Export | Type | Purpose |
|---|---|---|
| `Container.vue` | Vue component | Renders the container with inner GridStack |
| `ContainerForm.vue` | Vue form component | Form for backgroundColor, padding, title |
| `useNestedGridManager` | composable | Initialises inner grid with 4-column constants |
| Container type registry entry | widget type | Registered in the widget picker (REQ-CONT-001) |

## ADDED Requirements

### Requirement: REQ-CONT-001 Container widget type registered

The widget registry MUST include a `container` widget type whose `defaultContent` is `{placements: [], backgroundColor: 'transparent', padding: 'medium', title: ''}`. The container widget MUST be selectable from the unified Add Custom Widget picker (REQ-WDG-010 + REQ-WDG-019 EXPECTED_TYPES).

#### Scenario: Container appears in picker

- GIVEN the Add Custom Widget modal is open
- WHEN the type picker is opened
- THEN `container` MUST be in the list of selectable types
- AND picking it MUST mount the `ContainerForm` sub-form

#### Scenario: Default content is applied

- GIVEN a new container widget is added with no explicit content
- WHEN the placement is persisted
- THEN `content.placements` MUST be an empty array
- AND `content.backgroundColor` MUST be `'transparent'`
- AND `content.padding` MUST be `'medium'`
- AND `content.title` MUST be an empty string

### Requirement: REQ-CONT-002 Inner grid bounded by container cell

A container widget renders an inner GridStack instance bounded by the container's outer cell. The inner grid MUST use these constants (different from the top-level grid):

- `column: 4` (vs 12 outer)
- `cellHeight: 40` (vs 60 outer)
- `margin: 4` (vs 8 outer)
- `disableOneColumnMode: true` — nested grids do NOT respond to viewport breakpoints; they retain 4 columns regardless of viewport width

The inner grid is initialised via `useNestedGridManager` (a wrapper around `useGridManager` with the inner-grid constants).

#### Scenario: Inner grid initialised with correct config

- GIVEN a container placement with `content.placements: []`
- WHEN the container renders
- THEN an inner GridStack instance MUST be initialised on a child `<div>` element
- AND the instance's `column` MUST be 4
- AND the instance's `cellHeight` MUST be 40
- AND the instance's `margin` MUST be 4

#### Scenario: Inner grid ignores viewport breakpoints

- GIVEN a container rendered on a mobile viewport (< 768px width)
- WHEN the outer grid's breakpoint logic activates (switching to 1 column)
- THEN the inner grid MUST remain at 4 columns
- AND no one-column mode activation MUST occur in the inner grid

#### Scenario: Inner grid dimensions are stable

- GIVEN a container with width 400px and two child widgets sized 2x2 each
- WHEN the container is rendered multiple times (e.g. on mount, resize, update)
- THEN the cell dimensions (40px height, margin 4px) MUST remain constant
- AND child positioning MUST be reproducible (same content → same positions)

### Requirement: REQ-CONT-003 Recursive child rendering

Each child placement in `content.placements[]` MUST be rendered via the same widget-registry dispatcher used by the top-level grid (recursive). This means a container can hold any widget type — including another container (subject to REQ-CONT-006 depth limit).

#### Scenario: Child widgets render via the registry dispatcher

- GIVEN a container with `content.placements: [{type: 'label', content: {text: 'Hi'}}, {type: 'image', content: {url: '...'}}]`
- WHEN the container renders
- THEN two child elements MUST appear inside the inner grid
- AND each MUST be dispatched through the shared widget registry
- AND the first child MUST render as a label widget; the second as an image widget

#### Scenario: Nested container renders its own inner grid

- GIVEN a container holding another container (depth 2)
- WHEN the outer container renders
- THEN the inner container MUST also initialise its own GridStack instance
- AND the inner container's grid MUST use the same 4-column constants
- AND the second inner grid's children MUST also be recursively dispatched

#### Scenario: Unknown widget type in placements is handled gracefully

- GIVEN a container's `content.placements` includes a type not registered (e.g., `{type: 'nonexistent', ...}`)
- WHEN the container renders
- THEN the widget registry dispatcher MUST either render a fallback/error state OR skip the unregistered widget
- AND the rest of the container's children MUST render normally

### Requirement: REQ-CONT-004 View-mode click delegation

In view mode, the container MUST be non-interactive: clicks MUST fall through to the child widget under the cursor. The container itself MUST only render its background colour and (optional) title — no own click handler MAY intercept events that would otherwise reach a child.

#### Scenario: Click on child fires child's handler, not container's

- GIVEN a container in view mode containing a tile widget with `linkType: 'app'`, `linkValue: '/apps/files'`
- WHEN the user clicks the tile
- THEN the click MUST navigate to `/apps/files`
- AND no container-level click handler MUST intercept the event

#### Scenario: Click on container background does nothing

- GIVEN a container in view mode with empty `content.placements: []`
- WHEN the user clicks the container's background
- THEN no action MUST occur
- AND no alert/modal MUST appear

#### Scenario: Container renders background and title in view mode

- GIVEN a container with `backgroundColor: 'rgb(240, 240, 240)'` and `title: 'Section 1'`
- WHEN the container renders in view mode
- THEN a background element with the specified colour MUST be visible
- AND text 'Section 1' MUST be rendered as a heading above the inner grid

### Requirement: REQ-CONT-005 Edit-mode independence

In edit mode, the container's inner grid MUST become editable independently of the outer grid. The user MUST be able to add, remove, move, and resize child widgets WITHIN the container without disturbing siblings on the outer grid; outer-grid drag operations MUST NOT cascade into inner-grid mutations.

#### Scenario: Add a child widget inside a container

- GIVEN a container in edit mode with two child widgets
- WHEN the user opens the container's add-widget affordance and adds a label widget
- THEN the inner grid MUST gain a third child placement
- AND the outer grid's other widgets (siblings of the container) MUST NOT change position
- AND the container placement's `content.placements[]` array MUST be persisted with the new entry

#### Scenario: Move a child widget within container does not affect outer grid

- GIVEN a container in edit mode with a child widget at inner-grid position (0, 0)
- WHEN the user drags the child to inner-grid position (2, 1)
- THEN the child's `gridX` and `gridY` MUST update to (2, 1)
- AND no outer-grid widget MUST shift position
- AND the container itself MUST remain at its outer-grid position

#### Scenario: Delete child widget from container

- GIVEN a container in edit mode with three child placements
- WHEN the user removes one child widget
- THEN `content.placements[]` MUST shrink to two entries
- AND the remaining children MUST keep their positions
- AND the container's outer-grid position MUST NOT change

#### Scenario: Container's outer-grid drag does not affect children

- GIVEN a container in edit mode with child widgets
- WHEN the user drags the container itself on the outer grid (to reposition it)
- THEN the outer-grid position MUST change (outer `gridX`, `gridY`)
- AND the children's inner-grid positions MUST NOT change (inner `gridX`, `gridY` relative to container)
- AND the container's `content.placements[]` MUST be unchanged

### Requirement: REQ-CONT-006 Maximum nesting depth of 3

Containers MAY hold containers, but the maximum nesting depth is **3 levels**. The server MUST validate placement payloads on `POST /api/dashboards/{uuid}/widgets` and `PUT /api/dashboards/{uuid}/widgets/{id}`, rejecting any payload whose `content.placements[]` (recursively) exceeds depth 3 with HTTP 400 and envelope `{status: 'error', error: 'container_depth_exceeded', maxDepth: 3}`.

#### Scenario: Depth 3 is allowed

- GIVEN a payload with a container holding a container holding a container holding a label (3 container layers: c1 → c2 → c3 → label)
- WHEN the payload is POSTed to `POST /api/dashboards/{uuid}/widgets`
- THEN the server MUST accept it (depth 3 == maxDepth)
- AND the placement row MUST be inserted
- AND the response MUST include `status: 'success'`

#### Scenario: Depth 4 is rejected

- GIVEN a payload with a container holding a container holding a container holding a container holding a label (4 levels of nested containers: c1 → c2 → c3 → c4 → label)
- WHEN the payload is POSTed to `POST /api/dashboards/{uuid}/widgets`
- THEN the server MUST return HTTP 400
- AND the body MUST include `status: 'error'`, `error: 'container_depth_exceeded'`, and `maxDepth: 3`
- AND no rows MUST be inserted into `oc_mydash_widget_placements`

#### Scenario: Update to exceed depth is also rejected

- GIVEN an existing container placement at depth 1
- WHEN the user edits it to add a nested container holding a nested container holding a nested container (pushing total depth to 4)
- WHEN the payload is PUTed to `PUT /api/dashboards/{uuid}/widgets/{id}`
- THEN the server MUST return HTTP 400 with `error: 'container_depth_exceeded'`
- AND the original placement MUST remain unchanged

#### Scenario: Depth validation is recursive

- GIVEN a child placement within a container (depth 2) that itself holds another container (would be depth 3)
- WHEN the child container holds another container (would be depth 4)
- THEN the validation MUST traverse all nested levels and reject at depth 4

### Requirement: REQ-CONT-007 Form fields

The container's add/edit form MUST collect three fields, all optional:

- `backgroundColor` — hex colour (NcColorPicker), default `'transparent'`
- `padding` — enum `'none' | 'small' | 'medium' | 'large'` (NcSelect), default `'medium'`
- `title` — string (NcTextField), default `''` (no title rendered when empty)

Children are NOT managed via this form — they're added/removed/moved via the inner grid's own affordances when the container is in edit mode.

#### Scenario: Form has three fields

- GIVEN the container form is mounted
- WHEN rendered
- THEN exactly three input controls MUST be present: backgroundColor picker, padding select, title text field
- AND no "manage children" UI MUST be in the form

#### Scenario: Form values map to content fields

- GIVEN the form is populated with `backgroundColor: '#ff0000'`, `padding: 'large'`, `title: 'My Section'`
- WHEN the form is submitted
- THEN the container's `content.backgroundColor` MUST equal `'#ff0000'`
- AND `content.padding` MUST equal `'large'`
- AND `content.title` MUST equal `'My Section'`

#### Scenario: Default values are applied

- GIVEN a new container form is mounted with no initial data
- WHEN rendered
- THEN `backgroundColor` picker MUST show `'transparent'` as the default
- AND `padding` select MUST show `'medium'` as the default
- AND `title` field MUST be empty

#### Scenario: Title is optional

- GIVEN the form is submitted with an empty title field
- WHEN the placement is persisted
- THEN `content.title` MUST be an empty string
- AND no heading MUST be rendered on the container in view mode
