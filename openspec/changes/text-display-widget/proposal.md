# Text-display widget

A new widget type `text` that renders multi-line user-authored text with limited HTML support (sanitised via DOMPurify) or CommonMark-compliant Markdown. Supports inline styling controls (font size, colour, background, alignment) and a content-mode toggle (HTML/Markdown) for ad-hoc dashboard annotations, instructions, callouts, and structured content.

## Affected code units

- `src/components/Widgets/Renderers/TextDisplayWidget.vue` — renderer with HTML and Markdown support
- `src/components/Widgets/Forms/TextDisplayForm.vue` — sub-form for AddWidgetModal with contentMode toggle
- `src/constants/widgetRegistry.js` — register `type: 'text'` with markdown default
- `src/utils/markdownParser.ts` — new CommonMark parser wrapper (or integration point for `marked`/`markdown-it`)
- New capability `text-display-widget`

## Why a new capability

Each widget type is a stable feature contract with its own persisted content schema, validation, defaults, and renderer. Co-locating these in a per-widget capability makes the type easy to evolve, test, and document independently.

## Approach

- **Persisted shape**: `{type: 'text', content: {text, fontSize, color, backgroundColor, textAlign, contentMode}}` in `oc_mydash_widget_placements.styleConfig` (no schema migration).
- **Dual rendering paths**: 
  - HTML mode (`contentMode = 'html'`): text is passed through `DOMPurify.sanitize()` and rendered via `v-html`, preserving safe tags (`<b>`, `<i>`, `<a>`, `<br>`, etc.).
  - Markdown mode (`contentMode = 'markdown'`): text is parsed via CommonMark parser, then the resulting HTML is sanitised and rendered.
- **Backward compatibility**: Existing widgets with no explicit `contentMode` default to `'html'` on render; only new widgets receive `contentMode = 'markdown'` in the registry default.
- **Sub-form**: textarea (4 rows) + font-size text input + colour pickers + alignment select + mode toggle (HTML/Markdown).
- **Defaults**: `fontSize: '14px'`, `textAlign: 'left'`, `color`/`backgroundColor` inherit theme variables, `contentMode: 'markdown'` (new widgets only).
- **Validation**: `text` MUST be non-empty; `contentMode` MUST be `'html'` or `'markdown'` (invalid values are ignored on save).

## Capabilities

**New Capabilities:**

- `text-display-widget` — text widget type with HTML and Markdown rendering

**Modified Capabilities:**

- `widget-rendering` (adds REQ-WDG-009)
- `dashboard-widget-ui` (adds REQ-DWUI-005)

## Notes

- Use of `v-html` is the deliberate trade-off — without it the widget can't render formatted text or parsed Markdown. DOMPurify mitigates XSS in both paths.
- Free-form `fontSize` text input is intentional (allows `1.2em`, `clamp(...)`, etc.) — could be replaced with a typed picker later.
- The table mode mentioned in some requirements (REQ-TBLE-001 through REQ-TBLE-008) is deferred to a separate `text-widget-tables` capability and is NOT part of this change.
- Markdown support depends on a CommonMark library; the implementation may use `marked`, `markdown-it`, or a lightweight alternative depending on bundle-size constraints.
