---
capability: grid-layout
delta: true
status: draft
---

# Grid Layout — Delta from change `responsive-grid-breakpoints`

## MODIFIED Requirements

### Requirement: REQ-GRID-007 Grid Responsiveness (concrete breakpoints)

The GridStack instance MUST be initialised with `columnOpts.breakpoints` containing exactly four entries, each `{w: <viewportWidthPx>, c: <columnCount>}`, applied in descending viewport order:

| Viewport width >= | Column count |
|---|---|
| 1400 px | 12 |
| 1100 px | 8 |
| 768 px | 4 |
| 480 px | 1 |

`columnOpts.layout` MUST be set to `'moveScale'` so widgets scale proportionally when the column count changes (rather than wrapping or collapsing). Below the smallest entry the smallest column count (1) MUST apply.

#### Scenario: 12 columns on wide desktop

- GIVEN viewport width is 1500 px
- WHEN the workspace renders
- THEN the grid MUST use 12 columns
- AND a widget originally placed at `{x: 6, w: 6}` MUST occupy the right half of the viewport

#### Scenario: Reflow at 1100 px

- GIVEN the workspace was rendered at 1500 px (12 columns) with widgets occupying the full grid width
- WHEN the viewport is resized to 1100 px (8 columns)
- THEN GridStack MUST proportionally rescale widget widths via `moveScale`
- AND a widget originally `{x: 0, w: 6}` (half) MUST become approximately `{x: 0, w: 4}` (still half, in the 8-col grid)

#### Scenario: Single-column on mobile

- GIVEN viewport width is 480 px
- WHEN the workspace renders
- THEN the grid MUST use 1 column
- AND every widget MUST occupy the full row width
- AND widgets MUST stack vertically

#### Scenario: Below smallest breakpoint

- GIVEN viewport width is 320 px (below the 480 entry)
- WHEN the workspace renders
- THEN the grid MUST use the column count of the smallest matching breakpoint (1 column)

## ADDED Requirements

### Requirement: REQ-GRID-012 Cell geometry constants

The grid MUST be initialised with `cellHeight: 60` (px) and `margin: 8` (px). These constants MUST live in a single shared module exported from the grid composable, not duplicated in component templates.

#### Scenario: Cell height read from constant

- GIVEN the grid composable defines `CELL_HEIGHT = 60`
- WHEN any other module needs the cell height (e.g. for collision math or CSS calc)
- THEN it MUST import the constant
- AND grep for hardcoded `60` in grid contexts MUST return only the composable definition

#### Scenario: 12-col, 60px, 8px margin renders predictable height

- GIVEN a widget with `gridHeight: 4` is placed
- WHEN it renders at the 12-column breakpoint
- THEN its DOM height MUST be `(4 * 60) + (3 * 8) = 264 px` (4 rows + 3 inter-row margins)

### Requirement: REQ-GRID-013 GridStack version pin

The system MUST pin `gridstack` to a major version that supports `columnOpts.breakpoints` and the `moveScale` layout (currently v10 or later, target v12+). Bumping the major version MUST be a deliberate change with a regression-test pass on this capability.

#### Scenario: Lockfile version

- GIVEN a developer inspects `package-lock.json`
- WHEN reading the resolved `gridstack` entry
- THEN the resolved version MUST be `>= 10.0.0`
- AND `package.json` MUST declare a constrained range like `"gridstack": "^12.2.1"` (or whichever major is current at apply time)
