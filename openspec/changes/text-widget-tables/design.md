# Design — Text Widget Tables

## Context

The text-display-widget accepts HTML content authored by hand or via the markdown mode introduced in `text-widget-markdown`. Neither path gives non-technical authors a way to build a table without knowing markdown or HTML table syntax. This change adds a structured table editor that lets authors define rows, columns, and cell content through a Vue form, then serialises the result as a GFM markdown table inside the widget's HTML body.

The stored format is markdown table syntax, not a separate JSON blob. This keeps the data model flat and identical to what the `text-widget-markdown` parser already handles: the serialised table is handed to the same markdown-to-HTML conversion path before the placement is saved. Authors editing an existing table are presented with the editor re-populated from the stored markdown; there is no parallel JSON representation to keep in sync.

This spec depends on `text-widget-markdown` being in place. Without the markdown parser, the serialised table would be stored as raw text and not rendered as HTML.

## Goals / Non-Goals

**Goals:**
- Provide a row/column editor for inserting a table into a text-display widget.
- Serialise the table to GFM markdown table syntax on save.
- Enforce soft and hard dimension limits (20 rows × 10 columns) with HTTP 400 enforcement at the hard limit.
- Support a header-row toggle that controls whether the first row renders as `<th>` elements.
- Reuse the `text-widget-markdown` parse-and-sanitise pipeline; no separate HTML generation path.

**Non-Goals:**
- Rich text (bold, links, etc.) inside individual table cells — cells accept plain text only.
- Column width or alignment controls in v1.
- Merging cells (colspan/rowspan).
- Importing data from CSV or spreadsheet files.

## Decisions

### D1: Storage format — markdown table syntax inside the HTML body
**Decision**: Tables are serialised as GFM markdown table syntax and stored in `styleConfig.content.body` alongside any other widget text; `inputMode` is set to `'markdown'`.
**Alternatives considered**:
- A separate `tableData` JSON field alongside `body` — rejected: introduces a second source of truth and requires a custom renderer path.
- HTML `<table>` stored directly — rejected: bypasses the sanitiser pipeline and would need its own sanitisation rules.
**Rationale**: Using existing markdown storage and parsing means zero new backend endpoints and zero new sanitiser rules. The editor produces text; the existing pipeline handles the rest.

### D2: Editor UX — Vue component with add/delete rows and columns
**Decision**: A dedicated `TableEditorForm.vue` component provides a grid of text inputs, plus buttons to add/delete rows and columns at the end of each axis.
**Alternatives considered**:
- A contenteditable `<table>` — rejected: browser contenteditable table editing has long-standing cross-browser bugs.
- Exposing the raw markdown table syntax for the author to type — rejected: defeats the purpose of the structured editor.
**Rationale**: A plain grid of `<input>` elements is predictable, accessible, and straightforward to serialise.

### D3: Dimension limits — 20 rows × 10 columns
**Decision**: The editor enforces a soft cap of 20 × 10 via disabled add-buttons; the API returns HTTP 400 if a placement payload exceeds this.
**Alternatives considered**:
- No limit — rejected: very large tables break dashboard layout and create excessive DOM.
- Smaller limits — rejected: 20 × 10 covers the vast majority of real use cases found in the showcase data.
**Rationale**: A soft cap improves UX (buttons grey out) while the hard cap prevents abuse via API.

### D4: Header-row toggle
**Decision**: A single boolean `headerRow` on the table config controls whether the first row is treated as a header; when `true`, the first markdown table row renders as `<th>` elements.
**Alternatives considered**:
- No header row support — rejected: a table without headers is an accessibility fail under WCAG 1.3.1.
- Per-column header configuration — rejected: over-engineered for the target use case.
**Rationale**: One boolean maps directly to the GFM table syntax, which mandates a separator row after the header. The serialiser emits or omits the separator row based on this flag.

### D5: Cell content — plain text only
**Decision**: Cell inputs accept plain text; markdown inline syntax inside cells is not supported and is escaped on serialisation.
**Alternatives considered**:
- Allow markdown inside cells — rejected: makes the editor significantly more complex and the rendered output harder to predict.
**Rationale**: Plain text is sufficient for most tabular data. Authors who need styled cells can use the raw markdown textarea path.

### D6: Edit round-trip — parse markdown back into the editor
**Decision**: When an author re-opens the table editor, the stored markdown table is parsed back into the row/column grid using a client-side GFM table parser.
**Alternatives considered**:
- Store a separate JSON representation alongside the markdown — rejected: two sources of truth.
**Rationale**: GFM table syntax is simple enough that a lightweight client-side parser can reconstruct the grid reliably. The editor does not need to handle arbitrary markdown; only the table block it produced is parsed.

### D7: Insert vs replace behaviour
**Decision**: "Insert table" replaces the entire widget body with the table markdown; widgets cannot mix free text and a table in the same editor session.
**Alternatives considered**:
- Insert the table at cursor position within a larger text body — rejected: requires a full rich-text editor integration, which is out of scope.
**Rationale**: The table editor is a modal; when it closes, the output is the full `body`. Authors who want text above or below the table can switch to the markdown textarea view and edit manually.

## Risks / Trade-offs

- **Round-trip fidelity** → If a human edits the stored markdown outside the editor and introduces non-standard table syntax, the client-side parser may misinterpret it. Mitigation: the editor shows a warning if the stored body does not parse as a valid table and falls back to a blank grid.
- **Dimension limit bypass via API** → The HTTP 400 guard must be enforced in the controller, not only in the Vue form. Mitigation: server-side validation mirrors the client-side limit check.

## Open follow-ups

- Decide whether to surface column alignment (left/center/right) via the GFM alignment marker — low effort, adds polish.
- Determine whether the table editor should be reachable from within the markdown textarea (e.g., a toolbar button) or only from a separate "Insert table" entry point.
- Confirm that the `<th>` elements produced by the renderer receive appropriate `scope="col"` attributes for WCAG 1.3.1 compliance.
