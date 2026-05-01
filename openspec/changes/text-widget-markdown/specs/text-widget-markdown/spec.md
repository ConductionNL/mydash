---
status: draft
---

# Text-Widget Markdown

## ADDED Requirements

### Requirement: REQ-TXMD-001 Add contentMode field to text-widget config

The text-widget MUST support a new `contentMode` field in its `styleConfig.content` object, with permitted values `'html'` or `'markdown'`. The field is optional; when absent, it defaults to `'html'` (backward compatibility with existing widgets). New widgets MUST receive a default determined by the admin setting `mydash.text_widget_default_mode` (see REQ-TXMD-007).

#### Scenario: Existing widgets retain html mode

- GIVEN a text-widget created before markdown support, with `styleConfig.content` and no `contentMode` key
- WHEN the widget is rendered
- THEN the renderer MUST treat the content as HTML (REQ-TXT-001 behavior applies)
- AND the widget MUST display correctly on next load

#### Scenario: New widget receives admin-controlled default

- GIVEN the admin setting `mydash.text_widget_default_mode = 'markdown'`
- WHEN a user creates a new text-widget via AddWidgetModal
- THEN the widget's `styleConfig.content.contentMode` MUST be set to `'markdown'`
- AND subsequent renders MUST parse the text as markdown

#### Scenario: Widget can be manually switched to html mode

- GIVEN a widget with `contentMode = 'markdown'`
- WHEN the user edits the widget and selects `Mode: HTML` in the toggle
- THEN the widget's `contentMode` MUST be updated to `'html'` in styleConfig
- AND the text content MUST be re-rendered as HTML (markdown syntax is no longer parsed)

#### Scenario: Explicit html mode overrides admin default

- GIVEN the admin setting `mydash.text_widget_default_mode = 'markdown'`
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

All HTML output produced by the markdown parser MUST be passed through the same XSS allow-list sanitiser used by REQ-TXT-001 (HTML mode). Tags and attributes that pose security risk MUST be stripped. Safe tags (heading, emphasis, link, list, blockquote, table) MUST be preserved. Attributes on links MUST include `rel="noopener noreferrer"` for `target="_blank"` links to prevent opener hijacking.

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

- GIVEN `text = "[External](https://example.com){target=_blank}" (or per the markdown parser's syntax for target)`
- WHEN the widget renders
- THEN the `<a>` element MUST have `target="_blank"` AND `rel="noopener noreferrer"`

### Requirement: REQ-TXMD-004 Mode toggle in the edit form

The text-widget edit sub-form MUST display a `Mode` toggle or radio button group with two options: `HTML` and `Markdown`. The toggle's state MUST be bound to `contentMode` in the widget's `styleConfig.content` object. Switching modes MUST NOT lose the text content — only the parsing behavior changes.

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

#### Scenario: New widgets default to admin-controlled mode

- GIVEN the admin setting `mydash.text_widget_default_mode = 'markdown'`
- WHEN the AddWidgetModal form opens to add a new text-widget
- THEN the Mode toggle MUST default to `Markdown` (not HTML)

### Requirement: REQ-TXMD-005 Admin setting for default content mode

The system MUST provide an admin-configurable setting `mydash.text_widget_default_mode` with permitted values `'html'` or `'markdown'`. This setting controls the default `contentMode` for newly-created text-widgets. The setting MUST be stored in Nextcloud's app config (e.g., via `IAppConfig` or equivalent) and MUST default to `'markdown'` (Markdown is the primary mode).

#### Scenario: Setting is read from app config

- GIVEN the admin setting `mydash.text_widget_default_mode` is set to `'html'`
- WHEN a new text-widget is created via POST /api/widgets
- THEN the widget MUST receive `styleConfig.content.contentMode = 'html'`

#### Scenario: Default is markdown if setting is unset

- GIVEN the admin setting `mydash.text_widget_default_mode` is not explicitly configured
- WHEN a new text-widget is created
- THEN the widget MUST receive `contentMode = 'markdown'` (system default)

#### Scenario: Invalid setting values are rejected

- GIVEN an admin attempts to set `mydash.text_widget_default_mode = 'rst'` (invalid value)
- WHEN the value is persisted
- THEN the system MUST reject the write with a validation error OR silently coerce to `'markdown'`
- AND no widget creation MUST be affected by the invalid write

#### Scenario: Setting affects only new widgets, not existing ones

- GIVEN existing widgets already have `contentMode = 'html'`
- WHEN the admin changes `mydash.text_widget_default_mode` from `'html'` to `'markdown'`
- THEN all existing widgets MUST continue to render in their original mode
- AND only widgets created after the change MUST use the new default

### Requirement: REQ-TXMD-006 Backward compatibility — existing HTML-mode widgets unaffected

All widgets with `contentMode = 'html'` (the default for existing widgets) MUST continue to render exactly as before per REQ-TXT-001..005. The markdown parser MUST NOT be invoked. The introduction of markdown mode MUST NOT degrade or change the behavior of HTML mode.

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
