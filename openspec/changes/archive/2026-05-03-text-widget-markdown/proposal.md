# Text-Widget Markdown

## Why

The text-display widget currently renders only HTML content. Users who need to author structured text (headings, lists, emphasis, links) must learn HTML syntax or hand-craft the HTML. Markdown is the de-facto standard for lightweight structured text entry and is more intuitive for non-technical users. This change extends the widget's rendering pipeline to accept either HTML or Markdown input, with automatic parsing and safe sanitisation, while maintaining full backward compatibility.

## What Changes

- Add a `contentMode` field to text-widget config (either `'html'` or `'markdown'`), stored in `styleConfig.content.contentMode`.
- When `contentMode = 'markdown'`, parse the widget's text content using a CommonMark-compliant renderer (server-side via `league/commonmark` or client-side via `marked`) and render the parsed HTML.
- Sanitise all output (both HTML and parsed-markdown) through the same allow-list so XSS vectors are blocked uniformly.
- Add a UI toggle in the text-widget edit form: `Mode: [HTML | Markdown]`.
- Existing widgets default to `html` mode (backward compat). New widgets default to `markdown` mode.
- Introduce an admin setting `mydash.text_widget_default_mode` (string, `'html'` or `'markdown'`) to control the system-wide default for newly-added widgets.
- Support heading shortcuts: `# H1`, `## H2`, ..., `###### H6`.
- Support inline marks: `**bold**`, `*italic*`, `` `code` ``, `[link text](url)`, `> quote`, `- bullet`, `1. ordered`.
- Table syntax in markdown MUST render to `<table>` (in-place table editing is out of scope — that is covered by a sibling `text-widget-tables` capability).

## Capabilities

### New Capabilities

- `text-widget-markdown`: Markdown content mode for text-display widgets with CommonMark parsing, safe sanitisation, heading shortcuts, inline marks, and admin default-mode control.

### Modified Capabilities

- `text-display-widget`: adds optional markdown mode via REQ-TXMD-001..007. Existing HTML mode (REQ-TXT-001..005) is unchanged; both coexist in the same widget.

## Impact

**Affected code:**

- `lib/Service/TextWidgetService.php` (if it exists, or inline widget rendering) — add markdown parsing logic when `contentMode = 'markdown'`
- Text-widget Vue template — detect contentMode and route to markdown renderer when applicable
- `src/components/TextWidgetForm.vue` — add contentMode toggle to edit form
- `lib/AppConfig.php` or equivalent — add `mydash.text_widget_default_mode` admin setting with default `'markdown'`
- `src/components/widgets/TextWidget.vue` — extend to handle both HTML and Markdown rendering paths
- `openspec/specs/text-display-widget/spec.md` (pending promotion to `openspec/specs/`) — will adopt REQ-TXMD-001..007 as a delta change once text-display-widget is promoted

**Affected schemas:**

- `oc_mydash_widget_placements.styleConfig` — add `content.contentMode: 'html' | 'markdown'` field (already a JSON blob, no migration required for schema structure; values are additive)

**Dependencies:**

- `league/commonmark` (already vendored in nextcloud-vue for OpenRegister specs; reuse from there if feasible)
- OR `marked` (lightweight client-side CommonMark library; add via npm if server-side lib unavailable)
- No new Nextcloud capabilities required

**Migration:**

- Zero-impact on existing data: existing widgets have `styleConfig.content.contentMode` unset, which the renderer interprets as `'html'` (backward compat).
- New widgets get `contentMode = <admin-setting>` at creation time (default `'markdown'`).

**Interoperability:**

- Renderer choice (server vs client) is an implementation detail. Server-side parsing offers better cacheability and UX (no JS parsing lag); client-side offers runtime flexibility. The spec requires CommonMark compliance and consistent sanitisation, but not the engine choice.
