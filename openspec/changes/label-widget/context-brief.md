---
status: implemented
---

# Label Widget Specification

## Purpose

The label widget is a built-in MyDash widget type that lets dashboard authors drop a short, single-line, plain-text heading onto a dashboard to title a row of widgets or mark a zone. It is intentionally narrower than the `text` widget (which carries multi-line HTML content via `v-html`): the label widget renders content with Vue interpolation only, eliminating the XSS surface entirely, and ships heading-style defaults (`16px` bold centred) so a freshly added label looks correct without any styling input.

The capability is one widget type, one renderer, one sub-form, one registry entry — small enough to be evolved or deprecated independently of the broader widget-rendering machinery, and small enough that adding heading semantics (`<h3>`, `aria-level`) later does not entangle it with the `text` widget's content-block semantics.

## Data Model

Label placements use the existing `oc_mydash_widget_placements.styleConfig` JSON column with the discriminated shape `{type: 'label', content: {...}}`. No schema migration is required.

The `content` object carries six fields, all optional except `text` (which is validated by the form):

- **text** (string) — the label string rendered inside the widget cell
- **fontSize** (string, default `16px`) — any CSS length value
- **color** (string, default `var(--color-main-text)`) — any CSS colour value
- **backgroundColor** (string, default `transparent`) — any CSS colour value
- **fontWeight** (string, default `bold`) — one of `normal`, `bold`, `600`, `700`, `800`
- **textAlign** (string, default `center`) — one of `left`, `center`, `right`

## Requirements

### Requirement: REQ-LBL-001 Plain-text only rendering

The renderer MUST output `text` via Vue interpolation (`{{ text }}`). It MUST NOT use `v-html` or any other HTML-injection technique. Embedded HTML in `text` MUST appear as literal text on screen, eliminating the XSS surface entirely.

#### Scenario: HTML in text appears as literal characters

- **GIVEN** a label widget with `content.text = "Sales <b>Q4</b>"`
- **WHEN** the widget renders
- **THEN** the visible output MUST be the literal string `Sales <b>Q4</b>`
- **AND** the DOM MUST NOT contain a `<b>` element generated from the content

#### Scenario: Script tag in text appears as literal characters

- **GIVEN** a label widget with `content.text = "<script>alert(1)</script>"`
- **WHEN** the widget renders
- **THEN** the visible output MUST be the literal string `<script>alert(1)</script>`
- **AND** no script MUST execute

### Requirement: REQ-LBL-002 Default-styled as a centred bold heading

When form fields are absent, empty, or null, the renderer MUST apply these defaults: `fontSize='16px'`, `color='var(--color-main-text)'`, `backgroundColor='transparent'`, `fontWeight='bold'`, `textAlign='center'`. Provided values MUST override the matching default while leaving other defaults intact.

#### Scenario: Defaults applied to bare content

- **GIVEN** a label widget with `content = {text: 'Header'}` (no other fields)
- **WHEN** the widget renders
- **THEN** the inline style on the inner `<span>` MUST include `font-size: 16px`
- **AND** `font-weight: bold`
- **AND** `text-align: center`
- **AND** a theme-aware color resolved from `var(--color-main-text)`

#### Scenario: Override with custom values leaves untouched defaults intact

- **GIVEN** content `{text: 'X', fontSize: '32px', fontWeight: 'normal', textAlign: 'left'}`
- **WHEN** the widget renders
- **THEN** the inline style MUST reflect `font-size: 32px; font-weight: normal; text-align: left`
- **AND** `backgroundColor` MUST default to `transparent`
- **AND** `color` MUST default to the theme-variable colour

### Requirement: REQ-LBL-003 Long single words wrap

The renderer MUST set `overflow-wrap: break-word` on the rendered `<span>` so that overflowing single words wrap to a new visual line within the cell rather than overflowing horizontally.

#### Scenario: Very long word wraps within narrow cell

- **GIVEN** content `text = "Pneumonoultramicroscopicsilicovolcanoconiosis"` placed in a 2-column-wide cell
- **WHEN** the widget renders
- **THEN** the word MUST wrap across multiple visual lines within the cell bounds
- **AND** the cell MUST NOT produce a horizontal scrollbar

### Requirement: REQ-LBL-004 Empty-content placeholder

When `text` is empty, whitespace-only, or undefined, the renderer MUST display the translated literal `t('Label')` as a fallback so the widget remains visible during editing.

#### Scenario: Empty text shows translated fallback

- **GIVEN** `content = {text: ''}`
- **WHEN** the widget renders
- **THEN** the visible text MUST be the translation of `'Label'`

#### Scenario: Whitespace-only text shows translated fallback

- **GIVEN** `content = {text: '   '}`
- **WHEN** the widget renders
- **THEN** the visible text MUST be the translation of `'Label'`

### Requirement: REQ-LBL-005 Add/edit form

The label sub-form for `AddWidgetModal` MUST expose six controls: a required text input for `text`, an optional text input for `fontSize` (placeholder `16px`), a colour picker for `color`, a colour picker for `backgroundColor`, a select for `fontWeight` with options `normal`, `bold`, `600`, `700`, `800`, and a select for `textAlign` with options `left`, `center`, `right`. The form's `validate()` method MUST return `[t('Label text is required')]` when `text.trim() === ''` and an empty array otherwise. On open in edit mode the form MUST pre-fill every control from `editingWidget.content`.

#### Scenario: Form rejects empty text

- **GIVEN** the user opens the label sub-form with no pre-filled text
- **WHEN** they leave the text input empty and click validate
- **THEN** `validate()` MUST return a single-element error array containing the translation of `'Label text is required'`
- **AND** the modal Add button MUST be disabled

#### Scenario: Form pre-fills all six fields when editing

- **GIVEN** an existing label widget with `content = {text: 'Hi', fontSize: '20px', color: '#ff0000', backgroundColor: '#ffffff', fontWeight: '700', textAlign: 'right'}`
- **WHEN** the user opens that widget in the edit modal
- **THEN** every one of the six form controls MUST display its corresponding value from `content`

### Requirement: REQ-LBL-006 Layout fills cell with centred content

The renderer wrapper MUST occupy the full grid cell using `width: 100%; height: 100%` and use flexbox to centre the inner `<span>` both vertically and horizontally. The wrapper MUST apply `padding: 12px`.

#### Scenario: Centred in cell with padding

- **GIVEN** a label widget placed in a 4-column by 2-row cell
- **WHEN** the widget renders
- **THEN** the visible text MUST be centred horizontally and vertically within the cell
- **AND** the wrapper element MUST have `padding: 12px`
- **AND** the wrapper MUST have `width: 100%` and `height: 100%`

### Requirement: REQ-LBL-007 Widget registry registration

The widget type `label` MUST be registered in `src/constants/widgetRegistry.js` with a renderer reference to `LabelWidget.vue`, a form reference to `LabelForm.vue`, and a `defaultContent` of `{text: '', fontSize: '16px', color: '', backgroundColor: '', fontWeight: 'bold', textAlign: 'center'}`. The persisted shape of a label placement MUST be `{type: 'label', content: {...}}`.

#### Scenario: Newly added label uses registry defaults

- **GIVEN** a user adds a new label widget via the Add Widget modal without overriding any field
- **WHEN** the widget is persisted
- **THEN** the stored placement MUST have `type = 'label'`
- **AND** `content.fontSize` MUST equal `'16px'`
- **AND** `content.fontWeight` MUST equal `'bold'`
- **AND** `content.textAlign` MUST equal `'center'`

#### Scenario: Registry exposes label as a selectable widget type

- **GIVEN** the Add Widget modal queries the widget registry
- **WHEN** the type list is rendered
- **THEN** `label` MUST appear as a selectable option distinct from `text`
