# Tasks — text-display-widget

## Tasks

- [ ] Task 1: Add `dompurify ^3.x` to `package.json` dependencies, commit `package-lock.json`, and confirm the Apache-2.0/MPL-2.0 dual license clears the repo policy
- [ ] Task 2: Build `src/components/Widgets/Renderers/TextDisplayWidget.vue` with a `sanitizedHtml` Vue computed (`DOMPurify.sanitize(content.text)`), `<div v-html="sanitizedHtml">` only when `text.trim() !== ''`, and the localised placeholder span otherwise
- [ ] Task 3: Apply the renderer's inline style with theme-variable fallbacks (`fontSize=14px`, `color=var(--color-main-text)`, `backgroundColor=transparent`, `textAlign=left`) inside a flex container that fills 100%/100% with `padding:16px` + `overflow:auto` per REQ-TXT-002/005
- [ ] Task 4: Empty-state placeholder uses `t('mydash', 'No text content')`, italic, `var(--color-text-maxcontrast)`
- [ ] Task 5: Build `src/components/Widgets/Forms/TextDisplayForm.vue` matching the existing AddWidgetModal sub-form contract (`editingWidget` prop, `update:content` emit on every input) with textarea (4 rows), font-size text input, two color inputs, alignment `<select>`, and pre-fill from `editingWidget.content` on mount
- [ ] Task 6: Form `validate()` returns `[t('mydash', 'Text is required')]` when `text.trim() === ''`, otherwise `[]`; all labels are translated (`Text`, `Font Size`, `Text Color`, `Background Color`, `Alignment`)
- [ ] Task 7: Register `text` in `src/constants/widgetRegistry.js` with `component: TextDisplayWidget`, `form: TextDisplayForm`, `label: t('mydash','Text')`, and defaults `{text:'', fontSize:'14px', color:'', backgroundColor:'', textAlign:'left'}`; confirm AddWidgetModal's type picker surfaces it
- [ ] Task 8: i18n — add `Text`, `No text content`, `Text is required`, `Font Size`, `Text Color`, `Background Color`, `Alignment` to `l10n/en.json`; Dutch equivalents (`Tekst`, `Geen tekstinhoud`, `Tekst is verplicht`, `Tekengrootte`, `Tekstkleur`, `Achtergrondkleur`, `Uitlijning`) to `l10n/nl.json`; run the i18n extraction script if one exists
- [ ] Task 9: Vitest renderer coverage — DOMPurify strips `<script>`, `on*` attributes, and `javascript:` URLs while preserving `<b>`/`<i>`/`<a href>`/`<br>`/`<p>`/`<ul>`/`<li>`; empty/whitespace text renders the localised placeholder; inline style applies provided values verbatim and falls back to theme vars when fields are empty
- [ ] Task 10: Vitest form coverage — `validate()` reports required-text correctly; pre-fills all five fields from `editingWidget.content`; emits `update:content` reactively on each input
- [ ] Task 11: Playwright — add a text widget via AddWidgetModal, save, reload, content matches; edit existing widget (modal in edit mode), change text + font size, content updates; empty-text widget shows the localised placeholder
- [ ] Task 12: Quality gates — ESLint clean on new/touched `.vue`/`.js`; Stylelint clean on inline `<style>`; `npm run build` succeeds with no new warnings; no new deps beyond `dompurify`

## Verification

`openspec validate` exits clean. Widget appears in the type picker, sanitises hostile HTML, and round-trips through edit mode without state leakage.

## Tests (company-wide ADR-009)

Vitest + Playwright per Tasks 9–11. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry for the new widget type; user-guide screenshot showing a rendered text widget.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 8.
