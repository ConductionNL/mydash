# Tasks — text-widget-tables

## 1. Data model and schema

- [ ] 1.1 Define `tableData` JSON schema in proposal or spec as canonical reference:
  ```jsonc
  {
    "headerRow": boolean,
    "columnAlignments": ["left"|"center"|"right", ...],
    "rows": [
      [{"text": "...", "rowSpan": 1, "colSpan": 1}, ...],
      ...
    ]
  }
  ```
- [ ] 1.2 No database schema change needed — `tableData` lives in `styleConfig` JSON blob alongside existing text widget fields
- [ ] 1.3 Update domain model (`TextWidgetData` or similar DTO) to support both text mode and table mode with explicit union type or mode flag

## 2. Editor component

- [ ] 2.1 Extend `src/components/WidgetEditors/TextWidgetEditor.vue` with `tableMode` toggle (radio buttons: "Text | Table" or checkbox "Table mode")
- [ ] 2.2 Add conditional rendering: when `tableMode = false`, show existing text controls (textarea, font size, color, alignment); when `tableMode = true`, show table editor component
- [ ] 2.3 Create `src/components/WidgetEditors/TextTableEditor.vue` with:
  - A grid view rendering rows/columns as HTML table with content-editable cells (or input fields per cell)
  - Add Row Above / Below buttons
  - Add Column Left / Right buttons
  - Delete Row button (with confirmation if any cell contains text)
  - Delete Column button (with confirmation if any cell contains text)
  - Merge Selected Cells button (multi-cell selection via Shift+Click or drag)
  - Split Cell button (enabled only if selected cell has `rowSpan > 1` or `colSpan > 1`)
  - Set Column Alignment dropdown (per-column selector: left / center / right)
  - Toggle Header Row checkbox
- [ ] 2.4 Keyboard support: Tab moves focus between cells, Delete key in a cell clears text (not the cell itself)

## 3. Validation and data integrity

- [ ] 3.1 In TextTableEditor or a dedicated `TextTableValidator` class, implement:
  - Grid is rectangular: `rows[i].length === columnAlignments.length` for all i, accounting for merged cells
  - Merged cell spans: `cell.rowSpan + rowIndex <= rows.length` and `cell.colSpan + colIndex <= rows[0].length`
  - Reject on validation failure: modal shows error banner
- [ ] 3.2 On save (modal Add / Update button), run validation; HTTP 400 returned by backend if invalid (defensive, should not happen due to client-side validation)
- [ ] 3.3 Empty-table placeholder: when user toggles `tableMode = false → true`, initialize `tableData` with a single 1×1 cell with empty text

## 4. Renderer component

- [ ] 4.1 Update `src/components/WidgetRenderers/TextWidgetRenderer.vue`:
  - Accept `tableMode` flag and `tableData` object
  - If `tableMode = false`, render text path (existing code, unchanged)
  - If `tableMode = true`, render HTML `<table>` with:
    - `<thead><tr>` containing `<th>` elements if `headerRow = true` and index 0
    - Otherwise, all rows use `<tr><td>`
    - Each cell applies `rowspan` / `colspan` from metadata
    - Per-column `style="text-align: left|center|right"` based on `columnAlignments`
    - Cells are read-only in render mode (no content-editable)
- [ ] 4.2 Cell text must pass through DOMPurify (same sanitisation as text widget) before rendering via `v-html`
- [ ] 4.3 Table styling: `border-collapse: collapse`, borders on all cells, padding 8px, header background `var(--color-background-hover)`, hover effect on data cells

## 5. Store integration

- [ ] 5.1 Update `src/stores/widgets.js` to include `tableMode: boolean` and `tableData: object` fields in widget state
- [ ] 5.2 Emit updates reactively as user edits table in TextTableEditor (live sync to parent modal)
- [ ] 5.3 On mount, pre-populate TextTableEditor with existing `tableData` if present; else use empty 1×1 default

## 6. Backend service (optional, PHP validation)

- [ ] 6.1 If implementing server-side validation, create `lib/Service/TextTableValidator.php` with `validate(array $tableData): array` returning list of errors (empty if valid)
- [ ] 6.2 Called during widget update via controller before save
- [ ] 6.3 Return HTTP 400 on validation failure with error message

## 7. Bulk paste (optional)

- [ ] 7.1 (Optional) Add paste-to-table handler in TextTableEditor: on Ctrl+V, detect if clipboard is TSV/CSV
- [ ] 7.2 Parse clipboard text into rows/columns, auto-create cells, update editor state
- [ ] 7.3 Fallback: if paste is not TSV/CSV, insert text into focused cell

## 8. PHPUnit tests

- [ ] 8.1 If server-side validator created: `TextTableValidatorTest::testValidGrid` — rectangular grid passes
- [ ] 8.2 `TextTableValidatorTest::testInvalidGridRagged` — ragged row lengths fail validation
- [ ] 8.3 `TextTableValidatorTest::testMergedCellOverflow` — cell with `rowSpan` exceeding grid height fails
- [ ] 8.4 `TextTableValidatorTest::testValidHeaderRow` — `headerRow = true` passes
- [ ] 8.5 Integration test via WidgetController: create widget with `tableMode = true` and valid tableData, assert HTTP 201 returned

## 9. Frontend component tests (Vitest/Jest)

- [ ] 9.1 `TextTableEditor.test.js` — add row updates rows array
- [ ] 9.2 Delete row removes correct index, shifts remaining rows
- [ ] 9.3 Merge cells sets `rowSpan`/`colSpan`, clears merged-into cells
- [ ] 9.4 Split cell resets `rowSpan`/`colSpan` to 1
- [ ] 9.5 Toggle header row updates `headerRow` flag
- [ ] 9.6 Set alignment updates `columnAlignments` array
- [ ] 9.7 Renderer displays `<th>` when `headerRow = true`
- [ ] 9.8 Renderer applies `rowspan`/`colspan` HTML attributes correctly

## 10. End-to-end Playwright tests

- [ ] 10.1 User toggles `tableMode = false → true`, sees 1×1 empty table
- [ ] 10.2 User adds a row, adds a column, grid is now 2×2
- [ ] 10.3 User types in cells, renders with text visible
- [ ] 10.4 User merges 2 cells horizontally, HTML shows `colspan="2"` on first cell
- [ ] 10.5 User deletes row, confirms deletion prompt, row disappears
- [ ] 10.6 User toggles header row, saves widget, page reload shows `<th>` in first row
- [ ] 10.7 User sets column 1 to center alignment, renders with `text-align: center` on column 1 cells
- [ ] 10.8 User types `<script>alert(1)</script>` in a cell, saves, renders without script tag (sanitised)

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 11.3 All new PHP files have SPDX headers inside docblock (per convention)
- [ ] 11.4 i18n keys for all user-facing strings:
  - "Add row above" / "Add row below"
  - "Add column left" / "Add column right"
  - "Delete row" / "Delete column"
  - "Merge cells" / "Split cell"
  - "Header row"
  - "Left" / "Center" / "Right" (alignment options)
  - "Text is required to delete" (confirmation prompt)
  - Validation errors: "Grid is not rectangular", "Cell span exceeds grid bounds"
  - All keys in both `en` and `nl` locales

## 12. Documentation

- [ ] 12.1 Update widget README / developer guide with table mode example and schema
- [ ] 12.2 Add user-facing help text in the editor modal explaining table operations
