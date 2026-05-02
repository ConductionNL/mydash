# Text-display widget

A new widget type `text` that renders multi-line text content with HTML allowed (sanitised via DOMPurify). Supports inline styling controls (font size, colour, background, alignment) for ad-hoc dashboard annotations, instructions, or callouts.

## Affected code units

- `src/components/Widgets/Renderers/TextDisplayWidget.vue` — renderer
- `src/components/Widgets/Forms/TextDisplayForm.vue` — sub-form for AddWidgetModal
- `src/constants/widgetRegistry.js` — register `type: 'text'`
- New capability `text-display-widget`

## Why a new capability

Each widget type is a stable feature contract with its own persisted content schema, validation, defaults, and renderer. Co-locating these in a per-widget capability makes the type easy to evolve, test, and document independently.

## Approach

- Persisted shape: `{type: 'text', content: {text, fontSize, color, backgroundColor, textAlign}}` inside a widget placement record.
- Renderer wraps `<div v-html="DOMPurify.sanitize(text)">` — explicitly allows HTML so users can use `<b>`, `<a>`, etc.
- Sub-form: textarea (4 rows) + font-size text input + colour pickers + alignment select.
- Defaults: `fontSize: '14px'`, `textAlign: 'left'`, `color`/`backgroundColor` inherit theme variables.
- Validation: `text` MUST be non-empty.

## Notes

- Use of `v-html` is the deliberate trade-off — without it the widget can't render formatted text. DOMPurify mitigates XSS.
- Free-form `fontSize` text input is intentional (allows `1.2em`, `clamp(...)`, etc.) — could be replaced with a typed picker later.
