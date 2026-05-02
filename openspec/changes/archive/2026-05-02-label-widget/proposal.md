# Label widget

## Why

Dashboard authors today have only the `text` widget for adding non-data text to a dashboard. The text widget supports HTML (via `v-html`), is multi-line, scrolls on overflow, and carries the full XSS surface of any HTML-injection feature. None of those properties match the most common authoring need: a short, single-line, plain-text heading that titles a row of widgets or marks a dashboard zone. Folding heading-style behaviour into the existing `text` widget would either compromise its content-block semantics or bury an extra "is this a heading?" toggle in its form. A dedicated `label` widget is simpler, safer (no `v-html` at all), and leaves room for future heading semantics (`<h3>`, `aria-level`) to diverge without entangling the two types.

## What Changes

- Introduce a new widget type `label` with persisted shape `{type: 'label', content: {text, fontSize, color, backgroundColor, fontWeight, textAlign}}`.
- Add `src/components/Widgets/Renderers/LabelWidget.vue` rendering `{{ text }}` (Vue interpolation only — no `v-html`) inside a flex-centred wrapper.
- Add `src/components/Widgets/Forms/LabelForm.vue` exposing six controls (text, fontSize, color, backgroundColor, fontWeight select, textAlign select) with `validate()` requiring non-empty trimmed text.
- Register `label` in `src/constants/widgetRegistry.js` with defaults `{text:'', fontSize:'16px', color:'', backgroundColor:'', fontWeight:'bold', textAlign:'center'}`.
- Apply `overflow-wrap: break-word` so long single words wrap rather than overflow.
- When `text` is empty or whitespace, render the translated literal `t('Label')` so the widget is still visible during editing.

## Capabilities

### New Capabilities

- `label-widget`: REQ-LBL-001 (plain-text only), REQ-LBL-002 (default-styled centred bold heading), REQ-LBL-003 (long-word wrap), REQ-LBL-004 (empty-content placeholder), REQ-LBL-005 (add/edit form), REQ-LBL-006 (cell-filling layout), REQ-LBL-007 (registry registration).

### Modified Capabilities

(none — the existing `text-display-widget` capability is intentionally left untouched; the label widget is a parallel, separate type)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/LabelWidget.vue` — new file, single-file Vue component
- `src/components/Widgets/Forms/LabelForm.vue` — new file, sub-form for `AddWidgetModal`
- `src/constants/widgetRegistry.js` — add `label` entry with renderer + form references and `defaultContent`
- `src/l10n/en.json`, `src/l10n/nl.json` — add translation keys `Label`, `Label text is required`, `Font Weight`, `Alignment`

**Affected APIs:**

- No backend / HTTP API changes. The widget placement persistence layer already accepts arbitrary `{type, content}` blobs.

**Dependencies:**

- No new composer or npm dependencies.

**Migration:**

- Zero migration impact. Existing widget placements are unaffected. New label placements coexist with all other widget types in the same `oc_mydash_widget_placements.content` JSON column.

**Accessibility:**

- This change ships a `<div><span>...</span></div>` structure. Heading semantics (`<h3>`, `aria-level`) are intentionally deferred to a follow-up change so the admin can pick a heading level.

## Notes

- Considered folding into the existing `text-display-widget` capability — rejected because the intent (heading vs. content block) and the security posture (no HTML vs. sanitised HTML) differ enough to deserve a separate type.
- `overflow-wrap: break-word` is preferred over `word-break: break-all` so normal multi-word text continues to break at word boundaries.
