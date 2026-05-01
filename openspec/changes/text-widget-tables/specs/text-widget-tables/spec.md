---
status: draft
---

# Text-Widget Tables — New Capability

## ADDED Requirements

### Requirement: REQ-TBLE-001 Table Data Model

The widget MUST store table content in a `tableData` JSON object within `styleConfig`, with the schema:

```jsonc
{
  "headerRow": boolean (default false),
  "columnAlignments": ["left"|"center"|"right", ...],
  "rows": [
    [
      {
        "text": "string (may contain HTML, required)",
        "rowSpan": 1 (default),
        "colSpan": 1 (default)
      },
      ...
    ],
    ...
  ]
}
```

All fields MUST be present on save; no partial updates.

#### Scenario: Minimal 1x1 table

- GIVEN a freshly-toggled `tableMode = true` widget
- WHEN the editor initializes
- THEN `tableData` MUST equal:
  ```json
  {"headerRow": false, "columnAlignments": ["left"], "rows": [[{"text": "", "rowSpan": 1, "colSpan": 1}]]}
  ```
- AND the user sees a single empty cell in the editor

#### Scenario: Multi-row, multi-column table

- GIVEN `tableData` with `rows = [[{text:"A"}, {text:"B"}], [{text:"C"}, {text:"D"}]]` and `columnAlignments = ["left", "center"]`
- WHEN the table is rendered
- THEN the output MUST be a 2×2 grid with content A, B (top row) and C, D (bottom row)
- AND column 1 MUST have `text-align: center` applied

#### Scenario: Merged cells with span metadata

- GIVEN `rows = [[{text:"Header", "colSpan": 2}, {text:"X"}], [{text:"A"}, {text:"B"}, {text:"C"}]]`
- WHEN the editor loads
- THEN the first row's first cell MUST show `colSpan: 2`, and subsequent rows MUST account for the wider first cell
- NOTE: This scenario documents the data structure; merged-cell layout rules are covered in REQ-TBLE-007.

#### Scenario: Column alignment array length matches columns

- GIVEN a table with 3 columns
- WHEN `columnAlignments.length === 3`
- THEN rendering proceeds normally
- AND if `columnAlignments.length !== 3` (ragged), validation in REQ-TBLE-008 MUST reject on save

### Requirement: REQ-TBLE-002 Table Mode Toggle

The editor MUST expose a `tableMode` boolean flag (or "Text | Table" mode selector) that switches rendering between markdown/HTML text and structured table data.

#### Scenario: Switch from text to table mode

- GIVEN a widget with `tableMode = false` and `text = "Some text"`
- WHEN the user toggles `tableMode = true` in the editor
- THEN `tableData` MUST be initialized to a 1×1 empty table (per REQ-TBLE-001 minimal default)
- AND the `text` field MUST remain unchanged (preserved for toggling back)
- AND the editor UI MUST switch to table editor controls

#### Scenario: Switch from table to text mode

- GIVEN a widget with `tableMode = true` and an active `tableData` object
- WHEN the user toggles `tableMode = false`
- THEN the editor UI MUST switch to text controls (textarea, font size, color, alignment)
- AND the `tableData` field MUST remain in the widget state (preserved for toggling back)

#### Scenario: Render respects tableMode flag

- GIVEN a widget placement with both `text` and `tableData` populated
- WHEN `tableMode = false`, the renderer MUST draw text (ignore `tableData`)
- AND when `tableMode = true`, the renderer MUST draw table (ignore `text`)

### Requirement: REQ-TBLE-003 Add Row Operation

The editor MUST provide controls to insert a new row above or below the current selection.

#### Scenario: Add row below current row

- GIVEN a table with 2 rows and 3 columns, user has selected row index 0
- WHEN the user clicks "Add Row Below"
- THEN a new row with 3 empty cells MUST be inserted at index 1
- AND `rows.length` MUST equal 3
- AND the new row's cells MUST have `rowSpan: 1, colSpan: 1`

#### Scenario: Add row above current row

- GIVEN a table with 2 rows, user has selected row index 1
- WHEN the user clicks "Add Row Above"
- THEN a new row MUST be inserted at index 1, shifting the old index-1 row to index 2
- AND `rows.length` MUST equal 3

#### Scenario: Add row with default alignment

- GIVEN a table with `columnAlignments = ["left", "center", "right"]`
- WHEN a new row is added
- THEN the new row's cells MUST match the `columnAlignments` length (3 columns)
- AND alignment rendering in REQ-TBLE-007 applies to the new row

#### Scenario: Add row into merged-cell context

- GIVEN a table where row 0, column 0 has `colSpan: 2`
- WHEN a new row is added below
- THEN the new row MUST also have 2 cells in column 0/1 (not 3, accounting for the merge)
- NOTE: Grid accounting is tested in REQ-TBLE-008 validation

### Requirement: REQ-TBLE-004 Add Column Operation

The editor MUST provide controls to insert a new column left or right of the current selection.

#### Scenario: Add column right of current column

- GIVEN a table with 2 rows and 2 columns, user has selected column index 0
- WHEN the user clicks "Add Column Right"
- THEN a new column MUST be inserted at index 1
- AND every row MUST have a new empty cell at index 1
- AND `columnAlignments.length` MUST equal 3 (was 2)
- AND the new column's alignment MUST default to `"left"`

#### Scenario: Add column left of current column

- GIVEN a table with 2 rows and 2 columns, user has selected column index 1
- WHEN the user clicks "Add Column Left"
- THEN a new column MUST be inserted at index 1, shifting the old index-1 column to index 2
- AND every row's cell count MUST increase by 1
- AND `columnAlignments.length` MUST equal 3

#### Scenario: Add column with cell initialization

- GIVEN any table with N rows
- WHEN a new column is added
- THEN each of the N rows MUST receive a new cell object: `{text: "", rowSpan: 1, colSpan: 1}`

### Requirement: REQ-TBLE-005 Delete Row and Delete Column Operations

The editor MUST provide controls to remove rows or columns, with confirmation if the target contains text.

#### Scenario: Delete empty row

- GIVEN a table with 3 rows, row 1 is empty (all cells have `text: ""`)
- WHEN the user selects row 1 and clicks "Delete Row" WITHOUT a confirmation prompt
- THEN row 1 MUST be removed
- AND `rows.length` MUST equal 2

#### Scenario: Delete row with text — confirmation required

- GIVEN a table with 3 rows, row 1 has at least one cell with non-empty `text`
- WHEN the user selects row 1 and clicks "Delete Row"
- THEN a confirmation dialog MUST appear with message (translated): "This row contains text. Delete?"
- AND deletion proceeds only if the user confirms
- AND the row MUST be removed on confirmation

#### Scenario: Delete column with confirmation

- GIVEN a table with 3 columns, column 1 has any cell with non-empty `text`
- WHEN the user selects column 1 and clicks "Delete Column"
- THEN a confirmation dialog MUST appear
- AND `columnAlignments` MUST have the item at index 1 removed
- AND every row MUST have the cell at index 1 removed

#### Scenario: Cancel deletion

- GIVEN a confirmation dialog is open for delete row
- WHEN the user cancels
- THEN the row MUST remain unchanged
- AND `rows.length` MUST not change

### Requirement: REQ-TBLE-006 Merge and Split Cells

The editor MUST allow users to merge multiple selected cells horizontally or vertically, and to split a merged cell back to 1×1.

#### Scenario: Merge 2 horizontal cells

- GIVEN a table with row 0 containing 4 cells, user selects cells at column 0 and column 1
- WHEN the user clicks "Merge Cells"
- THEN cell[0][0] MUST have `colSpan: 2`
- AND cell[0][1] MUST be emptied (text cleared, kept as placeholder to maintain column count)
- AND rendering in REQ-TBLE-007 MUST skip cell[0][1] (HTML colspan spans over it)

#### Scenario: Merge 2 vertical cells

- GIVEN a table with 3 rows and 2 columns, user selects cell[0][0] and cell[1][0]
- WHEN the user clicks "Merge Cells"
- THEN cell[0][0] MUST have `rowSpan: 2`
- AND cell[1][0] MUST be emptied (placeholder)

#### Scenario: Merge 2x2 block

- GIVEN a table with 3×3 grid, user selects a 2×2 block (cells [0,0], [0,1], [1,0], [1,1])
- WHEN the user clicks "Merge Cells"
- THEN cell[0][0] MUST have `rowSpan: 2, colSpan: 2`
- AND cells [0][1], [1][0], [1][1] MUST be emptied (placeholders)

#### Scenario: Split merged cell

- GIVEN cell[0][0] has `colSpan: 2, rowSpan: 1`
- WHEN the user selects that cell and clicks "Split Cell"
- THEN cell[0][0] MUST reset to `colSpan: 1, rowSpan: 1`
- AND cell[0][1] (the placeholder) MUST also have `colSpan: 1, rowSpan: 1`
- AND the user-visible table MUST revert to 1×1 per cell

#### Scenario: Split without merge (idempotent)

- GIVEN cell[0][0] has `colSpan: 1, rowSpan: 1` (not merged)
- WHEN the user clicks "Split Cell"
- THEN nothing MUST change (idempotent)

### Requirement: REQ-TBLE-007 Column Alignment and Header Row Toggle

The editor MUST expose per-column alignment controls and a header-row flag toggle.

#### Scenario: Set column alignment to center

- GIVEN a table with 3 columns, all aligned "left"
- WHEN the user selects column 1 and sets alignment to "center"
- THEN `columnAlignments[1]` MUST equal `"center"`
- AND the renderer MUST apply `style="text-align: center"` to all `<td>` or `<th>` in column 1 (REQ-TBLE-007 render rule)

#### Scenario: Set multiple columns to different alignments

- GIVEN a 3-column table
- WHEN the user sets column 0 to "left", column 1 to "center", column 2 to "right"
- THEN `columnAlignments` MUST equal `["left", "center", "right"]`
- AND rendering MUST apply the correct alignment to each column

#### Scenario: Toggle header row flag

- GIVEN a table with `headerRow: false`
- WHEN the user clicks the "Header Row" checkbox
- THEN `headerRow` MUST be set to `true`
- AND the renderer MUST render row[0] as `<th>` instead of `<td>` (REQ-TBLE-007 render rule)

#### Scenario: Header row toggle is independent of content

- GIVEN any table with any content
- WHEN the user toggles `headerRow` on or off
- THEN the cell text MUST remain unchanged
- AND only the rendering format (th vs td) MUST change

### Requirement: REQ-TBLE-008 Table Validation — Grid Integrity

On save, the system MUST validate that the table grid is rectangular and cell spans do not exceed bounds.

#### Scenario: Valid rectangular grid without merges

- GIVEN `tableData` with 3 rows, each with exactly 3 cells, `columnAlignments.length = 3`, all cells have `rowSpan: 1, colSpan: 1`
- WHEN the user saves the widget
- THEN validation MUST pass (HTTP 201 or 200 returned)
- AND `tableData` MUST be persisted unchanged

#### Scenario: Ragged rows rejected

- GIVEN `tableData` with row[0] containing 3 cells and row[1] containing 2 cells (and no merges to account for the difference)
- WHEN the user saves
- THEN validation MUST fail with error message (translated): "Grid is not rectangular"
- AND the save MUST be blocked (HTTP 400 returned)
- NOTE: Client-side validation in REQ-TBLE-003/004/005 should prevent this, but server-side validation is defensive.

#### Scenario: Cell span exceeds grid bounds

- GIVEN `tableData` with `rows.length = 2` and cell[1][0] has `rowSpan: 2` (exceeds from row 1 by 2 rows total = 3, but grid only has 2 rows)
- WHEN the user saves
- THEN validation MUST fail with error message (translated): "Cell span exceeds grid bounds"
- AND HTTP 400 returned

#### Scenario: Column alignment length mismatch

- GIVEN a table with 3 actual columns (accounting for merges) but `columnAlignments.length = 2`
- WHEN the user saves
- THEN validation MUST fail
- AND save MUST be blocked

#### Scenario: Valid table with merged cells

- GIVEN `tableData` with a valid 3×3 grid where cell[0][0] has `colSpan: 2`, and rows[0] is laid out as `[{colSpan:2}, {text:...}, {text:...}]` (3 columns spanned), `columnAlignments.length = 3`
- WHEN the user saves
- THEN validation MUST pass (grid is logically rectangular when spans are accounted for)

### Requirement: REQ-TBLE-009 Render HTML Table with Cell Merging

The renderer MUST output an HTML `<table>` with correct `<th>` / `<td>` elements, `rowspan` / `colspan` attributes, and per-column text alignment.

#### Scenario: Render basic 2x2 table

- GIVEN `tableData = {headerRow: false, columnAlignments: ["left", "left"], rows: [[{text:"A"}, {text:"B"}], [{text:"C"}, {text:"D"}]]}`
- WHEN the widget renders
- THEN the output MUST be:
  ```html
  <table>
    <tr><td style="text-align: left">A</td><td style="text-align: left">B</td></tr>
    <tr><td style="text-align: left">C</td><td style="text-align: left">D</td></tr>
  </table>
  ```

#### Scenario: Render with header row

- GIVEN `tableData = {headerRow: true, columnAlignments: ["left", "center"], rows: [[{text:"Name"}, {text:"Score"}], [{text:"Alice"}, {text:"95"}]]}`
- WHEN the widget renders
- THEN row[0] MUST use `<th>` elements:
  ```html
  <table>
    <tr><th style="text-align: left">Name</th><th style="text-align: center">Score</th></tr>
    <tr><td style="text-align: left">Alice</td><td style="text-align: center">95</td></tr>
  </table>
  ```

#### Scenario: Render with colspan

- GIVEN cell[0][0] has `text: "Header", colSpan: 2` and cell[0][1] is a placeholder `{text: "", colSpan: 1}`
- WHEN the widget renders
- THEN cell[0][0] MUST render as `<td colspan="2">Header</td>` and cell[0][1] MUST NOT be rendered (skipped due to colspan span)
- AND the table MUST be visually rectangular

#### Scenario: Render with rowspan

- GIVEN cell[0][0] has `rowSpan: 2` and cell[1][0] is a placeholder
- WHEN the widget renders
- THEN cell[0][0] MUST render as `<td rowspan="2">...</td>` and cell[1][0] MUST NOT be rendered in the HTML

#### Scenario: Alignment applied per column

- GIVEN `columnAlignments = ["left", "center", "right"]`
- WHEN any row is rendered
- THEN all `<td>` / `<th>` in column 0 MUST have `style="text-align: left"`
- AND all in column 1 MUST have `style="text-align: center"`
- AND all in column 2 MUST have `style="text-align: right"`

### Requirement: REQ-TBLE-010 Cell Text Sanitisation

Cell text MUST be sanitised using DOMPurify before rendering to prevent XSS attacks.

#### Scenario: Safe HTML tags preserved

- GIVEN cell `{text: "Hello <b>world</b> and <i>thanks</i>"}`
- WHEN the table is rendered
- THEN the DOM MUST contain `<b>world</b>` and `<i>thanks</i>` exactly
- AND the visual output MUST show "world" in bold and "thanks" in italics

#### Scenario: Script tag removed

- GIVEN cell `{text: "<script>alert(1)</script> text"}`
- WHEN the table is rendered
- THEN the DOM MUST NOT contain a `<script>` element
- AND the visible text MUST be "text" (script and contents stripped)

#### Scenario: Event handler stripped

- GIVEN cell `{text: '<a href="#" onclick="alert(1)">link</a>'}`
- WHEN the table is rendered
- THEN the `<a>` element MUST NOT have an `onclick` attribute
- AND the `href="#"` attribute MUST be preserved

#### Scenario: javascript: URL stripped

- GIVEN cell `{text: '<a href="javascript:alert(1)">click</a>'}`
- WHEN the table is rendered
- THEN the `<a>` element MUST NOT have an `href` starting with `javascript:`

### Requirement: REQ-TBLE-011 Empty-Table Placeholder

When a table is freshly created (empty cells, no user text), the renderer MUST display a subtle placeholder to indicate the table is empty.

#### Scenario: Empty 1x1 table shows placeholder

- GIVEN `tableData = {headerRow: false, columnAlignments: ["left"], rows: [[{text: ""}]]}`
- WHEN the widget renders
- THEN the cell MUST display a translated placeholder text (e.g., `t('mydash', 'Empty table')`)
- AND the placeholder text MUST be styled with `color: var(--color-text-maxcontrast)` and `font-style: italic`
- AND the placeholder MUST NOT be persisted (if the user saves, cell still has `text: ""`)

#### Scenario: Placeholder disappears on user input

- GIVEN the renderer displays a placeholder in an empty cell
- WHEN the user clicks the cell and types text in edit mode
- THEN the placeholder MUST disappear
- AND the user's text MUST be visible

#### Scenario: Table with any non-empty cell hides placeholder

- GIVEN a 2×2 table where only cell[0][0] has `text: "Data"`
- WHEN the widget renders
- THEN cells[0][1], [1][0], [1][1] MAY show placeholder (per-cell), but the table overall is not empty
- NOTE: Placeholder is per-cell, not per-table.
