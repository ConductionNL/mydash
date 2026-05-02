---
capability: text-display-widget
delta: true
status: draft
---

# Text-Display Widget — Delta from change `text-display-widget`

## Purpose

The text-display widget renders user-authored text content inside a dashboard cell, with limited HTML support for inline formatting (bold, italics, links, line breaks). It is the primary "annotation" widget — useful for section captions, instructions, contact details, callouts.

## Data Model

The widget's persisted content lives in `oc_mydash_widget_placements.styleConfig` (JSON blob), as an object of shape:

```jsonc
{
  "type": "text",
  "content": {
    "text": "string (required, may contain HTML)",
    "fontSize": "string (CSS length, default '14px')",
    "color": "string (CSS colour, default theme variable)",
    "backgroundColor": "string (CSS colour, default 'transparent')",
    "textAlign": "'left' | 'center' | 'right' | 'justify' (default 'left')"
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
