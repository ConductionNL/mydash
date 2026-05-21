# Tasks — text-display-widget

## Tasks

### HTML Mode (REQ-TXT-001..005)

- [ ] Task 1: Add `dompurify ^3.x` to `package.json` dependencies, commit `package-lock.json`, and confirm the Apache-2.0/MPL-2.0 dual license clears the repo policy
- [ ] Task 2: Add a CommonMark parser library (e.g. `marked ^13.0.0` or `markdown-it ^14.0.0`) to `package.json` dependencies; confirm license compatibility and bundle size impact
- [ ] Task 3: Build `src/components/Widgets/Renderers/TextDisplayWidget.vue` with a `sanitizedHtml` Vue computed that calls the appropriate renderer based on `content.contentMode`:
  - HTML mode: `DOMPurify.sanitize(content.text)`
  - Markdown mode: parse `content.text` via the CommonMark parser, then `DOMPurify.sanitize(parsed)` (see Task 4)
  - Render via `<div v-html="sanitizedHtml">` only when `text.trim() !== ''`, otherwise show localised placeholder
- [ ] Task 4: Implement markdown rendering in `src/utils/markdownParser.ts` (or integrate into the renderer):
  - Parse `text` via the CommonMark library (marked / markdown-it)
  - Pass the output through `DOMPurify.sanitize()` with default config
  - Ensure `<a target="_blank">` links receive `rel="noopener noreferrer"` before returning (sanitiser may strip data attrs but must not strip `rel`)
  - Fallback to plain-text render if parse fails
- [ ] Task 5: Apply the renderer's inline style with theme-variable fallbacks (`fontSize=14px`, `color=var(--color-main-text)`, `backgroundColor=transparent`, `textAlign=left`) inside a flex container that fills 100%/100% with `padding:16px` + `overflow:auto` per REQ-TXT-002/005
- [ ] Task 6: Empty-state placeholder uses `t('mydash', 'No text content')`, italic, `var(--color-text-maxcontrast)`
- [ ] Task 7: Build `src/components/Widgets/Forms/TextDisplayForm.vue` matching the existing AddWidgetModal sub-form contract (`editingWidget` prop, `update:content` emit on every input) with:
  - textarea (4 rows) for `text`
  - text input (placeholder `14px`) for `fontSize`
  - two color inputs for `color` and `backgroundColor`
  - select for `textAlign` with options `left`, `center`, `right`, `justify`
  - new mode toggle/radio group for `contentMode` with options `HTML` and `Markdown`
  - pre-fill all six fields from `editingWidget.content` on mount; default `contentMode` to `'html'` for existing widgets
- [ ] Task 8: Form `validate()` returns `[t('mydash', 'Text is required')]` when `text.trim() === ''`, otherwise `[]`; all labels are translated (`Text`, `Font Size`, `Text Color`, `Background Color`, `Alignment`, `Mode`)
- [ ] Task 9: Form validation for `contentMode` — reject invalid values (anything other than `'html'` or `'markdown'`) and keep the previous mode
- [ ] Task 10: Register `text` in `src/constants/widgetRegistry.js` with:
  - `component: TextDisplayWidget`
  - `form: TextDisplayForm`
  - `label: t('mydash','Text')`
  - defaults: `{text:'', fontSize:'14px', color:'', backgroundColor:'', textAlign:'left', contentMode:'markdown'}`
  - confirm AddWidgetModal's type picker surfaces it
- [ ] Task 11: i18n — add all new strings to `l10n/en.json`:
  - `Text`, `No text content`, `Text is required`, `Font Size`, `Text Color`, `Background Color`, `Alignment`, `Mode`, `HTML`, `Markdown`
  - Dutch equivalents to `l10n/nl.json`:
  - `Tekst`, `Geen tekstinhoud`, `Tekst is verplicht`, `Tekengrootte`, `Tekstkleur`, `Achtergrondkleur`, `Uitlijning`, `Modus`, `HTML`, `Markdown`
  - run the i18n extraction script if one exists
- [ ] Task 12: Vitest renderer coverage for HTML mode (REQ-TXT-001..005):
  - DOMPurify strips `<script>`, `on*` attributes, and `javascript:` URLs while preserving `<b>`/`<i>`/`<a href>`/`<br>`/`<p>`/`<ul>`/`<li>`
  - empty/whitespace text renders the localised placeholder
  - inline style applies provided values verbatim and falls back to theme vars when fields are empty
  - widgets with no explicit `contentMode` default to HTML mode on render
- [ ] Task 13: Vitest renderer coverage for Markdown mode (REQ-TXMD-002, 003, 007):
  - Markdown headings (`#`-`######`) render to `<h1>`-`<h6>` semantic tags
  - Emphasis (`**bold**`, `*italic*`, `***both***`) renders correctly
  - Inline code, block code, links, lists (bullet and ordered), blockquotes, tables all render to semantic HTML
  - DOMPurify strips XSS vectors from parsed markdown (script tags, event handlers, javascript: URLs)
  - `rel="noopener noreferrer"` is added to `<a target="_blank">` links
- [ ] Task 14: Vitest form coverage (REQ-TXT-004, REQ-TXMD-004):
  - `validate()` reports required-text correctly
  - pre-fills all six fields from `editingWidget.content`
  - emits `update:content` reactively on each input
  - mode toggle switches between `HTML` and `Markdown` without losing text content
  - new widgets default to `contentMode='markdown'` in the form
  - existing widgets (no contentMode) show `HTML` mode selected by default
- [ ] Task 15: Playwright integration tests (REQ-TXT-005, REQ-TXMD-001..007):
  - add a text widget in HTML mode via AddWidgetModal, save, reload, content matches with XSS vectors stripped
  - add a text widget in Markdown mode via AddWidgetModal with heading + list, save, reload, rendered HTML matches expected structure
  - edit existing widget (modal in edit mode), change text + font size + mode, verify updates persist
  - toggle mode for an existing widget between HTML and Markdown, verify text content is preserved
  - empty-text widget shows the localised placeholder
  - long content in the widget scrolls within the cell boundary
- [ ] Task 16: Quality gates — ESLint clean on new/touched `.vue`/`.js`; Stylelint clean on inline `<style>`; `npm run build` succeeds with no new warnings; no new deps beyond `dompurify` and the CommonMark parser

## Verification

`openspec validate` exits clean. Widget appears in the type picker, supports both HTML and Markdown modes, sanitises hostile HTML in both paths, and round-trips through edit mode without state leakage or content loss when mode is toggled.

## Tests (company-wide ADR-009)

Vitest coverage per Tasks 12–14 (renderer + form, both HTML and Markdown modes). Playwright integration tests per Task 15. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry for the new widget type; user-guide screenshots showing a rendered text widget in both HTML and Markdown modes; note on backward compatibility for existing HTML-mode widgets.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 11. All user-facing strings translated in both languages.
