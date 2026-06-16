# Text-widget-tables

## Why

The text-display widget currently supports markdown text rendering and inline HTML formatting, covering annotations and rich text callouts. However, structured tabular data lacks a native editor within the dashboard — users must either (a) paste a pre-formatted markdown table (render-only, no in-place editing) or (b) embed an external spreadsheet widget. This change introduces a visual table editor into the text widget, allowing users to create and modify tables cell-by-cell directly in the dashboard without leaving the app.

## What Changes

- Extend `text-display-widget` with a new `tableMode: boolean` toggle (or a content-type selector: "Text | Table").
- Add a `tableData` JSON schema to the widget's `styleConfig` blob, storing header-row flag, column alignments, and a 2D row/cell matrix with `rowSpan` / `colSpan` metadata.
- When `tableMode = true`, the renderer draws an HTML `<table>` with `<th>` for the header row (if enabled), `<td>` otherwise, respecting cell merging and per-column text alignment.
- When `tableMode = false`, render the existing markdown/HTML text path (unchanged).
- Editor modal gains a table-editing UI with operations: add/delete row, add/delete column, merge/split cells, set alignment, toggle header row.
- Validation on save: grid must be rectangular (accounting for merged cells), cell spans must not overflow bounds.
- Cell text sanitised the same way as text-widget HTML (DOMPurify, no `<script>`, safe tags preserved).
- Empty-table placeholder: a freshly toggled `tableMode = true` defaults to a 1×1 cell grid.
- Optional: bulk paste TSV/CSV into the editor auto-parses into rows and columns.

## Capabilities

### New Capabilities

- `text-widget-tables`: a standalone new capability covering table data model, editor operations, rendering, and validation. Reason: the parent `text-display-widget` capability is not yet promoted to `openspec/specs/`; sibling `text-widget-markdown` is also a new capability in the same change. Keeping these as separate new capabilities allows independent merging and avoids coupling.

## Impact

**Affected code:**

- `src/components/WidgetEditors/TextWidgetEditor.vue` — add `tableMode` toggle and conditional table editor sub-component
- `src/components/WidgetEditors/TextTableEditor.vue` (new) — visual table editor with row/column add/delete, cell merge/split, alignment controls
- `src/components/WidgetRenderers/TextWidgetRenderer.vue` — render either text or table based on `tableMode`
- `src/stores/widgets.js` — track `tableData` in widget state alongside existing `text`, `fontSize`, `color` fields
- `lib/Service/TextWidgetService.php` (new or extend existing) — validate table grid shape, cell span bounds, merge cell logic
- `appinfo/styles.css` — table styling (border, cell padding, header background, hover states)

**Affected APIs:**

- None — the table data lives entirely within the widget's `styleConfig` JSON blob (no new endpoints)

**Dependencies:**

- DOMPurify (already vendored for text sanitisation) — reused for table cell text
- No new Composer or npm dependencies

**Migration:**

- Zero-impact: existing widgets get `tableMode = false` (text mode), so `tableData` is ignored until the user explicitly toggles `tableMode = true`. No data backfill needed.

## Scope Boundary

This change covers the data model, editor operations, render path, and validation for table mode. It does NOT cover:

- Bulk copy/paste from external spreadsheets (optional per spec; can be added in a follow-up if deemed high-value).
- Export to CSV/Excel (out of scope — text widget is annotation/display, not reporting).
- Advanced spreadsheet features (formulas, auto-sum, conditional formatting) — the widget is a simple table editor, not a calculation engine.
