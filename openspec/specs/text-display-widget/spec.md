---
status: implemented
---

# Text-Display Widget Specification

## Purpose

The text-display widget renders user-authored text content inside a dashboard cell, with limited HTML support for inline formatting (bold, italics, links, line breaks). It is the primary "annotation" widget — useful for section captions, instructions, contact details, callouts.

It is intentionally broader than the `label` widget (which renders only single-line plain text without `v-html` to eliminate the XSS surface): the text widget runs author input through DOMPurify so common formatting tags survive while XSS vectors are stripped, and it ships annotation-style defaults (`14px` left-aligned, theme-aware colour) so a freshly added text widget integrates with the surrounding dashboard look without any styling input.

The capability is one widget type, one renderer, one sub-form, one registry entry — small enough to be evolved or deprecated independently of the broader widget-rendering machinery.

## Data Model

Text placements use the existing `oc_mydash_widget_placements.styleConfig` JSON column with the discriminated shape `{type: 'text', content: {...}}`. No schema migration is required.

The `content` object carries six fields, all optional except `text` (which is validated by the form):

- **text** (string) — the body string rendered inside the widget cell; interpreted as HTML or Markdown depending on `contentMode`, then sanitised by DOMPurify before injection
- **fontSize** (string, default `14px`) — any CSS length value (`14px`, `1.2em`, `clamp(...)`, etc.)
- **color** (string, default `var(--color-main-text)`) — any CSS colour value
- **backgroundColor** (string, default `transparent`) — any CSS colour value
- **textAlign** (string, default `left`) — one of `left`, `center`, `right`, `justify`
- **contentMode** (string, optional) — `'html'` or `'markdown'`; absent on legacy placements (treated as `'html'` for backward compatibility); new placements created via `widgetRegistry.text.defaultContent` are seeded with `'markdown'`

## Requirements

### Requirement: Widget renders HTML-sanitised content (REQ-TXT-001)

The renderer MUST run `text` through `DOMPurify.sanitize` before injecting into the DOM via `v-html`. Tags and attributes that pose XSS risk (`<script>`, `on*` handlers, `javascript:` URLs) MUST be stripped. Common formatting tags (`<b>`, `<i>`, `<a>`, `<br>`, `<p>`, `<ul>`, `<li>`) MUST be preserved.

#### Scenario: Allow safe formatting

- **GIVEN** content `text = "Hello <b>world</b>"`
- **WHEN** the widget renders
- **THEN** the DOM MUST contain `<b>world</b>` exactly
- **AND** the visual output MUST show "world" in bold

#### Scenario: Strip script tag

- **GIVEN** content `text = "Click <script>alert(1)</script> me"`
- **WHEN** the widget renders
- **THEN** the DOM MUST NOT contain a `<script>` element
- **AND** the visible text MUST be "Click  me" (or equivalent — sanitisation strips the tag and contents)

#### Scenario: Strip event handler attribute

- **GIVEN** content `text = '<a href="x" onclick="alert(1)">x</a>'`
- **WHEN** the widget renders
- **THEN** the rendered `<a>` element MUST NOT have an `onclick` attribute
- **AND** the `href="x"` attribute MUST be preserved

#### Scenario: Strip javascript: URL

- **GIVEN** content `text = '<a href="javascript:alert(1)">x</a>'`
- **WHEN** the widget renders
- **THEN** the rendered `<a>` element MUST NOT have an `href` attribute starting with `javascript:`

### Requirement: Style application with theme-aware fallbacks (REQ-TXT-002)

The renderer MUST apply `fontSize`, `color`, `backgroundColor`, and `textAlign` from `content` as inline styles on the wrapper element. When a field is null or empty, the renderer MUST fall back to: `fontSize='14px'`, `color='var(--color-main-text)'`, `backgroundColor='transparent'`, `textAlign='left'`.

#### Scenario: Custom font size and colour

- **GIVEN** `content = {text: 'X', fontSize: '24px', color: '#ff0000'}`
- **WHEN** the widget renders
- **THEN** the wrapper element's inline style MUST include `font-size: 24px` and `color: #ff0000`
- **AND** `background-color` MUST resolve to `transparent`
- **AND** `text-align` MUST resolve to `left`

#### Scenario: Theme-aware default colour

- **GIVEN** `content = {text: 'X', color: ''}`
- **WHEN** the widget renders
- **THEN** the wrapper's CSS `color` MUST be `var(--color-main-text)` (Nextcloud theme variable, adapts to light/dark mode)

#### Scenario: Free-form font size accepted

- **GIVEN** `content = {text: 'X', fontSize: '1.2em'}`
- **WHEN** the widget renders
- **THEN** the wrapper element's inline style MUST include `font-size: 1.2em` verbatim
- **AND** the renderer MUST NOT reject or coerce non-px units

### Requirement: Empty-content placeholder (REQ-TXT-003)

When `text` is empty, missing, or whitespace-only, the renderer MUST display an italic translated placeholder `t('mydash', 'No text content')` in `var(--color-text-maxcontrast)` colour. The wrapper MUST still occupy the full cell so the widget remains a valid drop target.

#### Scenario: Empty content shows placeholder

- **GIVEN** `content = {text: ''}`
- **WHEN** the widget renders
- **THEN** the visible text MUST be the localised value of `t('mydash', 'No text content')`
- **AND** the placeholder text MUST be styled `font-style: italic` with `color: var(--color-text-maxcontrast)`
- **AND** the wrapper MUST fill the cell with `width: 100%; height: 100%`

#### Scenario: Whitespace-only content treated as empty

- **GIVEN** `content = {text: '   \n  '}`
- **WHEN** the widget renders
- **THEN** the placeholder MUST be shown (whitespace does not count as content)

### Requirement: Add/edit sub-form for AddWidgetModal (REQ-TXT-004)

The text sub-form for `AddWidgetModal` MUST expose these controls and validation rules:

| Field | Control | Required |
|---|---|---|
| `text` | `<textarea rows="4">` | yes |
| `fontSize` | `<input type="text" placeholder="14px">` | no |
| `color` | `<input type="color">` | no |
| `backgroundColor` | `<input type="color">` | no |
| `textAlign` | `<select>` with options `left` / `center` / `right` / `justify` | no |

The component MUST expose a `validate()` method that returns `[t('mydash', 'Text is required')]` when `text.trim() === ''`, and `[]` otherwise. The parent modal disables its `Add` / `Save` button while `validate()` returns a non-empty array.

#### Scenario: Form rejects empty text

- **GIVEN** the user opens the text sub-form in add mode
- **WHEN** they leave the textarea empty and press the modal's Add button
- **THEN** `validate()` MUST return a non-empty array containing `t('mydash', 'Text is required')`
- **AND** the modal's Add button MUST be disabled

#### Scenario: Form pre-fills in edit mode

- **GIVEN** the modal opens with `editingWidget = {type: 'text', content: {text: 'Hi', fontSize: '20px', color: '#00ff00'}}`
- **WHEN** the sub-form mounts
- **THEN** the textarea value MUST equal `'Hi'`
- **AND** the font-size input value MUST equal `'20px'`
- **AND** the color input value MUST equal `'#00ff00'`

#### Scenario: Form emits content updates reactively

- **GIVEN** the sub-form is mounted with empty initial content
- **WHEN** the user types `Hello` in the textarea
- **THEN** the parent modal MUST receive an updated `content.text === 'Hello'` via the standard sub-form contract
- **AND** `validate()` MUST now return `[]`

### Requirement: Layout — fill cell with padded scrollable content (REQ-TXT-005)

The widget MUST fill its grid cell (`width: 100%, height: 100%`) with `padding: 16px` and `overflow: auto`. Content MUST be horizontally aligned per `textAlign` and vertically centred when content height is less than cell height (flex centred).

#### Scenario: Overflow scrolls within the cell

- **GIVEN** `text` is long enough to overflow the cell vertically
- **WHEN** the widget renders
- **THEN** the wrapper element MUST show a scrollbar (CSS `overflow: auto`)
- **AND** content above and below the visible region MUST be reachable by scrolling within the cell

#### Scenario: Short content vertically centred

- **GIVEN** `text` is a single short line and the cell is much taller than the content
- **WHEN** the widget renders
- **THEN** the rendered text MUST be vertically centred in the cell

### Requirement: REQ-TXMD-001 Add contentMode field to text-widget config

The text-widget MUST support a `contentMode` field in its `styleConfig.content` object, with permitted values `'html'` or `'markdown'`. The field is optional; when absent, it defaults to `'html'` (backward compatibility with existing widgets). New widgets MUST receive a default determined by the system-wide default mode (see REQ-TXMD-005).

#### Scenario: Existing widgets retain html mode

- GIVEN a text-widget created before markdown support, with `styleConfig.content` and no `contentMode` key
- WHEN the widget is rendered
- THEN the renderer MUST treat the content as HTML (REQ-TXT-001 behavior applies)
- AND the widget MUST display correctly on next load

#### Scenario: New widget receives the configured default

- GIVEN the system default for `contentMode` is `'markdown'`
- WHEN a user creates a new text-widget via AddWidgetModal
- THEN the widget's `styleConfig.content.contentMode` MUST be set to `'markdown'`
- AND subsequent renders MUST parse the text as markdown

#### Scenario: Widget can be manually switched to html mode

- GIVEN a widget with `contentMode = 'markdown'`
- WHEN the user edits the widget and selects `Mode: HTML` in the toggle
- THEN the widget's `contentMode` MUST be updated to `'html'` in styleConfig
- AND the text content MUST be re-rendered as HTML (markdown syntax is no longer parsed)

#### Scenario: Explicit html mode overrides the default

- GIVEN the system default for `contentMode` is `'markdown'`
- WHEN a user creates a new text-widget and explicitly selects `Mode: HTML` before saving
- THEN the widget's `contentMode` MUST be set to `'html'` (explicit choice overrides the default)

### Requirement: REQ-TXMD-002 CommonMark-compliant markdown parsing

When `contentMode = 'markdown'`, the renderer MUST parse the widget text using a CommonMark-compliant parser and convert it to HTML. The parser MUST handle headings (H1–H6 via `#...######` syntax), emphasis (`**bold**`, `*italic*`, `***bold-italic***`), code (inline `` `code` `` and code blocks), links, lists (bullet and ordered), block quotes, and tables.

#### Scenario: Heading shortcuts render to semantic heading tags

- GIVEN `text = "# Main\n## Sub\n### Deep"`
- WHEN the widget renders with `contentMode = 'markdown'`
- THEN the DOM MUST contain `<h1>Main</h1>`, `<h2>Sub</h2>`, `<h3>Deep</h3>` in sequence
- AND all heading levels H1–H6 MUST be supported

#### Scenario: Emphasis marks render to inline tags

- GIVEN `text = "**bold** and *italic* and ***both***"`
- WHEN the widget renders
- THEN the DOM MUST contain `<strong>bold</strong>`, `<em>italic</em>`, `<strong><em>both</em></strong>` (or equivalent semantic nesting)
- AND the visual output MUST show the correct emphasis styling

#### Scenario: Inline code renders to code tag

- GIVEN `text = "Use \`npm install\` to set up"`
- WHEN the widget renders
- THEN the DOM MUST contain `<code>npm install</code>` with appropriate styling
- AND the code MUST NOT be executed as JavaScript

#### Scenario: Links preserve href and render anchor tag

- GIVEN `text = "[OpenRegister](https://openregister.nl)"`
- WHEN the widget renders
- THEN the DOM MUST contain `<a href="https://openregister.nl">OpenRegister</a>`
- AND the link MUST be clickable

#### Scenario: Bullet lists render as ul/li

- GIVEN `text = "- Item A\n- Item B\n- Item C"`
- WHEN the widget renders
- THEN the DOM MUST contain `<ul>` with three `<li>` children
- AND nested lists MUST be supported (indentation creates nested `<ul>` or `<ol>`)

#### Scenario: Ordered lists render as ol/li

- GIVEN `text = "1. First\n2. Second\n3. Third"`
- WHEN the widget renders
- THEN the DOM MUST contain `<ol>` with three `<li>` children in sequence

#### Scenario: Block quotes render as blockquote

- GIVEN `text = "> This is a quote\n> from someone"`
- WHEN the widget renders
- THEN the DOM MUST contain `<blockquote>` with the quoted text

#### Scenario: Tables render to semantic table markup

- GIVEN `text = "| Header A | Header B |\n|---|---|\n| Cell A1 | Cell B1 |"`
- WHEN the widget renders
- THEN the DOM MUST contain `<table>` with `<thead>`, `<tbody>`, and appropriate `<tr>`, `<th>`, `<td>` elements
- NOTE: In-place table editing UI is not required (see `text-widget-tables` capability)

### Requirement: REQ-TXMD-003 Sanitisation of parsed markdown output

All HTML output produced by the markdown parser MUST be passed through the same XSS allow-list sanitiser used by REQ-TXT-001 (HTML mode). Tags and attributes that pose security risk MUST be stripped. Safe tags (heading, emphasis, link, list, blockquote, table) MUST be preserved. Anchors MUST receive `rel="noopener noreferrer"` whenever they carry `target="_blank"`, to mitigate reverse-tabnabbing.

#### Scenario: Script tags in markdown are stripped

- GIVEN `text = "<script>alert(1)</script> and **bold**"`
- WHEN the widget renders with `contentMode = 'markdown'`
- THEN the DOM MUST NOT contain a `<script>` element
- AND the visible text MUST be " and **bold**" (script is stripped, markdown bold is parsed)

#### Scenario: Event handlers are removed from parsed elements

- GIVEN `text = "[Click me](javascript:alert(1))"`
- WHEN the widget renders
- THEN the `<a>` element's `href` attribute MUST NOT start with `javascript:`
- AND the link MUST be either stripped entirely or converted to a safe no-op

#### Scenario: Data attributes are removed from links

- GIVEN `text = "<a href='http://example.com' data-malicious='xss'>link</a>" (HTML in markdown)`
- WHEN the widget renders
- THEN the sanitiser MUST remove the `data-malicious` attribute
- AND the `href` MUST be preserved (if it is safe)

#### Scenario: target="_blank" links get rel attribute

- GIVEN `text = '<a href="https://example.com" target="_blank">x</a>'`
- WHEN the widget renders
- THEN the `<a>` element MUST have `target="_blank"` AND `rel="noopener noreferrer"`

### Requirement: REQ-TXMD-004 Mode toggle in the edit form

The text-widget edit sub-form MUST display a `Mode` toggle or radio/select group with two options: `HTML` and `Markdown`. The toggle's state MUST be bound to `contentMode` in the widget's `styleConfig.content` object. Switching modes MUST NOT lose the text content — only the parsing behavior changes.

#### Scenario: HTML mode is shown by default for existing widgets

- GIVEN the edit form opens for a widget with no explicit `contentMode` (or `contentMode = 'html'`)
- WHEN the form renders
- THEN the Mode toggle MUST default to `HTML` (selected/checked state)

#### Scenario: Markdown mode is shown for markdown-mode widgets

- GIVEN the edit form opens for a widget with `contentMode = 'markdown'`
- WHEN the form renders
- THEN the Mode toggle MUST show `Markdown` as selected

#### Scenario: Toggling mode preserves text content

- GIVEN a widget with `contentMode = 'markdown'` and `text = "# Heading"`
- WHEN the user switches the Mode toggle to `HTML`
- THEN the `text` field value MUST remain `"# Heading"` (unchanged)
- AND on next render, the text MUST be displayed as literal HTML (the `#` is rendered as-is, not parsed as markdown)

#### Scenario: Mode change is saved with other form fields

- GIVEN the user changes `contentMode` from `HTML` to `Markdown`
- WHEN they click Save in the edit form
- THEN the PUT request MUST include the updated `styleConfig.content.contentMode` field
- AND the widget MUST render in markdown mode on next load

#### Scenario: New widgets default to the configured mode

- GIVEN the system default for `contentMode` is `'markdown'`
- WHEN the AddWidgetModal form opens to add a new text-widget
- THEN the Mode toggle MUST default to `Markdown` (not HTML)

### Requirement: REQ-TXMD-005 System default for new widget content mode

The widget registry's `text.defaultContent.contentMode` MUST seed every new text widget with a known-good mode. The shipped default MUST be `'markdown'` (the primary authoring mode). Invalid mode values written through the form MUST be rejected so that placements only ever persist `'html'` or `'markdown'`. A future admin setting (`mydash.text_widget_default_mode`, deferred to the `admin-text-widget-settings` change) MAY override the registry default at runtime.

#### Scenario: Default is markdown when nothing else applies

- GIVEN no override is configured
- WHEN a new text-widget is created
- THEN the widget MUST receive `contentMode = 'markdown'` (system default)

#### Scenario: Invalid mode writes are rejected

- GIVEN the form attempts to set `contentMode = 'rst'` (an unsupported value)
- WHEN the field updater runs
- THEN the form MUST ignore the write and keep the previous mode
- AND no widget save MUST persist an invalid mode

#### Scenario: Setting affects only new widgets, not existing ones

- GIVEN existing widgets already have `contentMode = 'html'`
- WHEN the system default changes from `'html'` to `'markdown'`
- THEN all existing widgets MUST continue to render in their original mode
- AND only widgets created after the change MUST use the new default

### Requirement: REQ-TXMD-006 Backward compatibility — existing HTML-mode widgets unaffected

All widgets with `contentMode = 'html'` (the default for existing widgets) MUST continue to render exactly as before per REQ-TXT-001..005. The markdown parser MUST NOT be invoked for these placements. The introduction of markdown mode MUST NOT degrade or change the behavior of HTML mode.

#### Scenario: Existing widget renders unchanged

- GIVEN a widget created before markdown support with `styleConfig.content = {text: '<b>bold</b>'}`
- WHEN it is rendered after the markdown feature is deployed
- THEN the DOM MUST contain `<b>bold</b>` exactly
- AND the visual output MUST be identical to before

#### Scenario: HTML mode ignores markdown syntax

- GIVEN a widget explicitly set to `contentMode = 'html'` with `text = "# Heading"`
- WHEN the widget renders
- THEN the visible output MUST be `# Heading` (literal text, not parsed as markdown)
- AND no `<h1>` tag MUST appear

#### Scenario: Existing sanitisation rules apply to HTML mode

- GIVEN a widget with `contentMode = 'html'` and `text = "<script>alert(1)</script>"`
- WHEN the widget renders
- THEN the same XSS stripping rules from REQ-TXT-001 MUST apply
- AND no `<script>` element MUST appear

### Requirement: REQ-TXMD-007 Heading levels H1–H6 with markdown shortcuts

The markdown parser MUST recognise and render heading levels via the standard `#`-prefix syntax: `#` for H1, `##` for H2, ..., `######` for H6. Each heading level MUST produce a corresponding semantic HTML tag (`<h1>` through `<h6>`).

#### Scenario: Single hash produces h1

- GIVEN `text = "# Main Title"`
- WHEN the widget renders with `contentMode = 'markdown'`
- THEN the DOM MUST contain exactly `<h1>Main Title</h1>`

#### Scenario: Double hash produces h2

- GIVEN `text = "## Subsection"`
- WHEN the widget renders
- THEN the DOM MUST contain exactly `<h2>Subsection</h2>`

#### Scenario: Up to six hashes for h6

- GIVEN `text = "###### Tiny"`
- WHEN the widget renders
- THEN the DOM MUST contain exactly `<h6>Tiny</h6>`

#### Scenario: Seven or more hashes treated as literal text

- GIVEN `text = "####### Too many"`
- WHEN the widget renders
- THEN CommonMark parsing MUST treat `####### Too many` as a paragraph (not a heading)
- AND the output MUST be `<p>####### Too many</p>` or equivalent

#### Scenario: Heading followed by content

- GIVEN `text = "# Title\nThis is content.\n## Subsection\nMore content."`
- WHEN the widget renders
- THEN the DOM MUST contain `<h1>Title</h1><p>This is content.</p><h2>Subsection</h2><p>More content.</p>` (or semantic equivalent)

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
