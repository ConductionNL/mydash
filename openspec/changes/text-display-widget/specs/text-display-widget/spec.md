---
capability: text-display-widget
delta: true
status: draft
---

# Text-Display Widget — Delta from change `text-display-widget`

## Purpose

The text-display widget renders user-authored text content inside a dashboard cell with support for both HTML (sanitised via DOMPurify) and CommonMark Markdown formatting. It is the primary "annotation" widget — useful for section captions, instructions, contact details, callouts, and structured content.

## Data Model

The widget's persisted content lives in `oc_mydash_widget_placements.styleConfig` (JSON blob), as an object of shape:

```jsonc
{
  "type": "text",
  "content": {
    "text": "string (required, may contain HTML or Markdown depending on contentMode)",
    "fontSize": "string (CSS length, default '14px')",
    "color": "string (CSS colour, default theme variable)",
    "backgroundColor": "string (CSS colour, default 'transparent')",
    "textAlign": "'left' | 'center' | 'right' | 'justify' (default 'left')",
    "contentMode": "'html' | 'markdown' (optional, defaults to 'html' for backward compatibility; new widgets default to 'markdown')"
  }
}
```

## ADDED Requirements

### Requirement: Widget renders HTML-sanitised content (REQ-TXT-001)

The renderer MUST run `text` through `DOMPurify.sanitize` before injecting into the DOM via `v-html`. Tags and attributes that pose XSS risk (`<script>`, `on*` handlers, `javascript:` URLs) MUST be stripped. Common formatting tags (`<b>`, `<i>`, `<a>`, `<br>`, `<p>`, `<ul>`, `<li>`) MUST be preserved.

#### Scenario: Allow safe formatting

- GIVEN content `text = "Hello <b>world</b>"`
- WHEN the widget renders
- THEN the DOM MUST contain `<b>world</b>` exactly
- AND the visual output MUST show "world" in bold

#### Scenario: Strip script tag

- GIVEN content `text = "Click <script>alert(1)</script> me"`
- WHEN the widget renders
- THEN the DOM MUST NOT contain a `<script>` element
- AND the visible text MUST be "Click  me" (or equivalent — sanitisation strips the tag and contents)

#### Scenario: Strip event handler attribute

- GIVEN content `text = '<a href="x" onclick="alert(1)">x</a>'`
- WHEN the widget renders
- THEN the rendered `<a>` element MUST NOT have an `onclick` attribute
- AND the `href="x"` attribute MUST be preserved

#### Scenario: Strip javascript: URL

- GIVEN content `text = '<a href="javascript:alert(1)">x</a>'`
- WHEN the widget renders
- THEN the rendered `<a>` element MUST NOT have an `href` attribute starting with `javascript:`

### Requirement: Style application with theme-aware fallbacks (REQ-TXT-002)

The renderer MUST apply `fontSize`, `color`, `backgroundColor`, and `textAlign` from `content` as inline styles on the wrapper element. When a field is null or empty, the renderer MUST fall back to: `fontSize='14px'`, `color='var(--color-main-text)'`, `backgroundColor='transparent'`, `textAlign='left'`.

#### Scenario: Custom font size and colour

- GIVEN `content = {text: 'X', fontSize: '24px', color: '#ff0000'}`
- WHEN the widget renders
- THEN the wrapper element's inline style MUST include `font-size: 24px` and `color: #ff0000`
- AND `background-color` MUST resolve to `transparent`
- AND `text-align` MUST resolve to `left`

#### Scenario: Theme-aware default colour

- GIVEN `content = {text: 'X', color: ''}`
- WHEN the widget renders
- THEN the wrapper's CSS `color` MUST be `var(--color-main-text)` (Nextcloud theme variable, adapts to light/dark mode)

#### Scenario: Free-form font size accepted

- GIVEN `content = {text: 'X', fontSize: '1.2em'}`
- WHEN the widget renders
- THEN the wrapper element's inline style MUST include `font-size: 1.2em` verbatim
- AND the renderer MUST NOT reject or coerce non-px units

### Requirement: Empty-content placeholder (REQ-TXT-003)

When `text` is empty, missing, or whitespace-only, the renderer MUST display an italic translated placeholder `t('mydash', 'No text content')` in `var(--color-text-maxcontrast)` colour. The wrapper MUST still occupy the full cell so the widget remains a valid drop target.

#### Scenario: Empty content shows placeholder

- GIVEN `content = {text: ''}`
- WHEN the widget renders
- THEN the visible text MUST be the localised value of `t('mydash', 'No text content')`
- AND the placeholder text MUST be styled `font-style: italic` with `color: var(--color-text-maxcontrast)`
- AND the wrapper MUST fill the cell with `width: 100%; height: 100%`

#### Scenario: Whitespace-only content treated as empty

- GIVEN `content = {text: '   \n  '}`
- WHEN the widget renders
- THEN the placeholder MUST be shown (whitespace does not count as content)

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

- GIVEN the user opens the text sub-form in add mode
- WHEN they leave the textarea empty and press the modal's Add button
- THEN `validate()` MUST return a non-empty array containing `t('mydash', 'Text is required')`
- AND the modal's Add button MUST be disabled

#### Scenario: Form pre-fills in edit mode

- GIVEN the modal opens with `editingWidget = {type: 'text', content: {text: 'Hi', fontSize: '20px', color: '#00ff00'}}`
- WHEN the sub-form mounts
- THEN the textarea value MUST equal `'Hi'`
- AND the font-size input value MUST equal `'20px'`
- AND the color input value MUST equal `'#00ff00'`

#### Scenario: Form emits content updates reactively

- GIVEN the sub-form is mounted with empty initial content
- WHEN the user types `Hello` in the textarea
- THEN the parent modal MUST receive an updated `content.text === 'Hello'` via the standard sub-form contract
- AND `validate()` MUST now return `[]`

### Requirement: Layout — fill cell with padded scrollable content (REQ-TXT-005)

The widget MUST fill its grid cell (`width: 100%, height: 100%`) with `padding: 16px` and `overflow: auto`. Content MUST be horizontally aligned per `textAlign` and vertically centred when content height is less than cell height (flex centred).

#### Scenario: Overflow scrolls within the cell

- GIVEN `text` is long enough to overflow the cell vertically
- WHEN the widget renders
- THEN the wrapper element MUST show a scrollbar (CSS `overflow: auto`)
- AND content above and below the visible region MUST be reachable by scrolling within the cell

#### Scenario: Short content vertically centred

- GIVEN `text` is a single short line and the cell is much taller than the content
- WHEN the widget renders
- THEN the rendered text MUST be vertically centred in the cell

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
