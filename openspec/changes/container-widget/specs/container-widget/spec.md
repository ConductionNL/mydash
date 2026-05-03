# Spec delta — container-widget (NEW capability)

## ADDED Requirements

### Requirement: REQ-CONT-001 Container widget type registered

The widget registry MUST include a `container` widget type whose `defaultContent` is `{placements: [], backgroundColor: 'transparent', padding: 'medium', title: ''}`. The container widget MUST be selectable from the unified Add Custom Widget picker (REQ-WDG-010 + REQ-WDG-019 EXPECTED_TYPES).

#### Scenario: Container appears in picker

- **GIVEN** the Add Custom Widget modal is open
- **WHEN** the type picker is opened
- **THEN** `container` MUST be in the list of selectable types
- **AND** picking it MUST mount the `ContainerForm` sub-form

### Requirement: REQ-CONT-002 Inner grid bounded by container cell

A container widget renders an inner GridStack instance bounded by the container's outer cell. The inner grid MUST use these constants (different from the top-level grid):

- `column: 4` (vs 12 outer)
- `cellHeight: 40` (vs 60 outer)
- `margin: 4` (vs 8 outer)
- `disableOneColumnMode: true` — nested grids do NOT respond to viewport breakpoints; they retain 4 columns regardless of viewport width

The inner grid is initialised via `useNestedGridManager` (a wrapper around `useGridManager` with the inner-grid constants).

#### Scenario: Inner grid initialised with correct config

- **GIVEN** a container placement with `content.placements: []`
- **WHEN** the container renders
- **THEN** an inner GridStack instance MUST be initialised on a child `<div>` element
- **AND** the instance's `column` MUST be 4
- **AND** the instance's `cellHeight` MUST be 40
- **AND** the instance's `margin` MUST be 4

### Requirement: REQ-CONT-003 Recursive child rendering

Each child placement in `content.placements[]` MUST be rendered via the same `WidgetRenderer` component used by the top-level grid (recursive). This means a container can hold any widget type — including another container (subject to REQ-CONT-006 depth limit).

#### Scenario: Child widgets render via WidgetRenderer

- **GIVEN** a container with `content.placements: [{type: 'label', content: {text: 'Hi'}}, {type: 'image', content: {url: '...'}}]`
- **WHEN** the container renders
- **THEN** two child elements MUST appear inside the inner grid
- **AND** each MUST wrap a `WidgetRenderer` component
- **AND** the first child MUST render as a label widget; the second as an image widget

### Requirement: REQ-CONT-004 View-mode click delegation

In view mode, the container MUST be non-interactive: clicks MUST fall through to the child widget under the cursor. The container itself MUST only render its background colour and (optional) title — no own click handler MAY intercept events that would otherwise reach a child.

#### Scenario: Click on child fires child's handler, not container's

- **GIVEN** a container in view mode containing a tile widget with `linkType: 'app'`, `linkValue: '/apps/files'`
- **WHEN** the user clicks the tile
- **THEN** the click MUST navigate to `/apps/files`
- **AND** no container-level click handler MUST intercept the event

### Requirement: REQ-CONT-005 Edit-mode independence

In edit mode, the container's inner grid MUST become editable independently of the outer grid. The user MUST be able to add, remove, move, and resize child widgets WITHIN the container without disturbing siblings on the outer grid; outer-grid drag operations MUST NOT cascade into inner-grid mutations.

#### Scenario: Add a child widget inside a container

- **GIVEN** a container in edit mode with two child widgets
- **WHEN** the user opens the container's add-widget affordance and adds a label widget
- **THEN** the inner grid MUST gain a third child placement
- **AND** the outer grid's other widgets (siblings of the container) MUST NOT change position
- **AND** the container placement's `content.placements[]` array MUST be persisted with the new entry

### Requirement: REQ-CONT-006 Maximum nesting depth of 3

Containers MAY hold containers, but the maximum nesting depth is **3 levels**. The server MUST validate placement payloads on `POST /api/dashboards/{uuid}/widgets` and `PUT /api/dashboards/{uuid}/widgets/{id}`, rejecting any payload whose `content.placements[]` (recursively) exceeds depth 3 with HTTP 400 and envelope `{status: 'error', error: 'container_depth_exceeded', maxDepth: 3}`.

#### Scenario: Depth 3 is allowed

- **GIVEN** a payload with a container holding a container holding a container holding a label (4 layers: outer → c1 → c2 → c3 → label, but only c1, c2, c3 are containers)
- **WHEN** the payload is POSTed
- **THEN** the server MUST accept it (depth 3 == maxDepth)

#### Scenario: Depth 4 is rejected

- **GIVEN** a payload with a container holding a container holding a container holding a container holding a label (4 levels of nested containers)
- **WHEN** the payload is POSTed
- **THEN** the server MUST return HTTP 400
- **AND** the body MUST include `error: 'container_depth_exceeded'` and `maxDepth: 3`
- **AND** no rows MUST be inserted into `oc_mydash_widget_placements`

### Requirement: REQ-CONT-007 Form fields

The container's add/edit form MUST collect three fields, all optional:

- `backgroundColor` — hex colour (NcColorPicker), default `'transparent'`
- `padding` — enum `'none' | 'small' | 'medium' | 'large'` (NcSelect), default `'medium'`
- `title` — string (NcTextField), default `''` (no title rendered when empty)

Children are NOT managed via this form — they're added/removed/moved via the inner grid's own affordances when the container is in edit mode.

#### Scenario: Form has three fields

- **GIVEN** the container form is mounted
- **WHEN** rendered
- **THEN** exactly three input controls MUST be present: backgroundColor picker, padding select, title text field
- **AND** no "manage children" UI MUST be in the form
