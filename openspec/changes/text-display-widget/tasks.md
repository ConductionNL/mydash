# Tasks — text-display-widget

## 1. Dependencies

- [ ] 1.1 Add `dompurify ^3.x` to `package.json` `dependencies`
- [ ] 1.2 Run `npm install` and commit `package-lock.json`
- [ ] 1.3 Verify `dompurify` license is permitted by repo policy (Apache-2.0/MPL-2.0 dual — approved)

## 2. Renderer component

- [ ] 2.1 Create `src/components/Widgets/Renderers/TextDisplayWidget.vue`
- [ ] 2.2 Compute `sanitizedHtml` via `DOMPurify.sanitize(content.text)` in a Vue computed
- [ ] 2.3 Emit `<div v-html="sanitizedHtml">` only when `text.trim() !== ''`; otherwise render the placeholder span
- [ ] 2.4 Apply inline style with theme-variable fallbacks per REQ-TXT-002 (`fontSize=14px`, `color=var(--color-main-text)`, `backgroundColor=transparent`, `textAlign=left`)
- [ ] 2.5 Wrap in a flex container that fills 100% width / 100% height with `padding: 16px` and `overflow: auto` per REQ-TXT-005
- [ ] 2.6 Empty-content placeholder uses `t('mydash', 'No text content')`, italic, in `var(--color-text-maxcontrast)`

## 3. Sub-form component

- [ ] 3.1 Create `src/components/Widgets/Forms/TextDisplayForm.vue`
- [ ] 3.2 Implement props/emit contract matching the existing AddWidgetModal sub-form pattern (props: `editingWidget`; emit: `update:content` on every input)
- [ ] 3.3 Render textarea (4 rows), text input for fontSize, two `<input type="color">`, and `<select>` for textAlign
- [ ] 3.4 Pre-fill all five fields from `editingWidget.content` on `mounted()`
- [ ] 3.5 Expose `validate()` that returns `[t('mydash', 'Text is required')]` when `text.trim() === ''`, otherwise `[]`
- [ ] 3.6 All labels use translation: `t('mydash', 'Text')`, `t('mydash', 'Font Size')`, `t('mydash', 'Text Color')`, `t('mydash', 'Background Color')`, `t('mydash', 'Alignment')`

## 4. Widget registry

- [ ] 4.1 Add `text` entry to `src/constants/widgetRegistry.js` with `component: TextDisplayWidget`, `form: TextDisplayForm`, `label: t('mydash', 'Text')`
- [ ] 4.2 Provide defaults `{text: '', fontSize: '14px', color: '', backgroundColor: '', textAlign: 'left'}`
- [ ] 4.3 Confirm registry entry is consumed by AddWidgetModal's type-picker so `text` appears as a selectable widget type

## 5. i18n

- [ ] 5.1 Add to `l10n/en.json`: `Text`, `No text content`, `Text is required`, `Font Size`, `Text Color`, `Background Color`, `Alignment`
- [ ] 5.2 Add Dutch equivalents to `l10n/nl.json`: `Tekst`, `Geen tekstinhoud`, `Tekst is verplicht`, `Tekengrootte`, `Tekstkleur`, `Achtergrondkleur`, `Uitlijning`
- [ ] 5.3 Run the project's i18n extraction script if one exists; verify keys land in both locales

## 6. Vitest unit tests

- [ ] 6.1 `TextDisplayWidget.spec.js` — DOMPurify strips `<script>`, `on*` attributes, and `javascript:` URLs while preserving `<b>`, `<i>`, `<a href>`, `<br>`, `<p>`, `<ul>`, `<li>`
- [ ] 6.2 `TextDisplayWidget.spec.js` — empty and whitespace-only `text` shows the localised placeholder
- [ ] 6.3 `TextDisplayWidget.spec.js` — inline style applies provided values verbatim and falls back to theme variables when fields empty
- [ ] 6.4 `TextDisplayForm.spec.js` — `validate()` returns `[t('mydash', 'Text is required')]` on empty text, `[]` when populated
- [ ] 6.5 `TextDisplayForm.spec.js` — pre-fills all five fields from `editingWidget.content` on mount
- [ ] 6.6 `TextDisplayForm.spec.js` — emits `update:content` reactively on each input

## 7. Playwright end-to-end test

- [ ] 7.1 Add a text widget via AddWidgetModal, save, reload page, confirm rendered text matches input
- [ ] 7.2 Edit the widget (open modal in edit mode), change text and font size, save, confirm new values render
- [ ] 7.3 Confirm an empty-text widget shows the localised placeholder

## 8. Quality gates

- [ ] 8.1 ESLint clean on all new/touched `.vue` and `.js` files
- [ ] 8.2 Stylelint clean on inline `<style>` blocks
- [ ] 8.3 `npm run build` succeeds with no new warnings
- [ ] 8.4 No new dependencies beyond `dompurify` introduced
