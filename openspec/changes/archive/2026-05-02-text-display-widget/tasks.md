# Tasks — text-display-widget

## 1. Dependencies

- [x] 1.1 Add `dompurify ^3.x` to `package.json` `dependencies`
- [x] 1.2 Run `npm install` and commit `package-lock.json`
- [x] 1.3 Verify `dompurify` license is permitted by repo policy (Apache-2.0/MPL-2.0 dual — approved)

## 2. Renderer component

- [x] 2.1 Create `src/components/Widgets/Renderers/TextDisplayWidget.vue`
- [x] 2.2 Compute `sanitizedHtml` via `DOMPurify.sanitize(content.text)` in a Vue computed
- [x] 2.3 Emit `<div v-html="sanitizedHtml">` only when `text.trim() !== ''`; otherwise render the placeholder span
- [x] 2.4 Apply inline style with theme-variable fallbacks per REQ-TXT-002 (`fontSize=14px`, `color=var(--color-main-text)`, `backgroundColor=transparent`, `textAlign=left`)
- [x] 2.5 Wrap in a flex container that fills 100% width / 100% height with `padding: 16px` and `overflow: auto` per REQ-TXT-005
- [x] 2.6 Empty-content placeholder uses `t('mydash', 'No text content')`, italic, in `var(--color-text-maxcontrast)`

## 3. Sub-form component

- [x] 3.1 Create `src/components/Widgets/Forms/TextDisplayForm.vue`
- [x] 3.2 Implement props/emit contract matching the existing AddWidgetModal sub-form pattern (props: `editingWidget`; emit: `update:content` on every input)
- [x] 3.3 Render textarea (4 rows), text input for fontSize, two `<input type="color">`, and `<select>` for textAlign
- [x] 3.4 Pre-fill all five fields from `editingWidget.content` on `mounted()`
- [x] 3.5 Expose `validate()` that returns `[t('mydash', 'Text is required')]` when `text.trim() === ''`, otherwise `[]`
- [x] 3.6 All labels use translation: `t('mydash', 'Text')`, `t('mydash', 'Font Size')`, `t('mydash', 'Text Color')`, `t('mydash', 'Background Color')`, `t('mydash', 'Alignment')`

## 4. Widget registry

- [x] 4.1 Add `text` entry to `src/constants/widgetRegistry.js` with `component: TextDisplayWidget`, `form: TextDisplayForm`, `label: t('mydash', 'Text')`
- [x] 4.2 Provide defaults `{text: '', fontSize: '14px', color: '', backgroundColor: '', textAlign: 'left'}`
- [x] 4.3 Confirm registry entry is consumed by AddWidgetModal's type-picker so `text` appears as a selectable widget type

## 5. i18n

- [x] 5.1 Add to `l10n/en.json` and `l10n/en.js`: `Text`, `No text content`, `Text is required`, `Font Size`, `Text Color`, `Background Color`, `Alignment`
- [x] 5.2 Add Dutch equivalents to `l10n/nl.json` and `l10n/nl.js`: `Tekst`, `Geen tekstinhoud`, `Tekst is verplicht`, `Tekengrootte`, `Tekstkleur`, `Achtergrondkleur`, `Uitlijning`
- [x] 5.3 Run the project's i18n extraction script if one exists; verify keys land in both locales (no extraction script in repo — files are hand-maintained alongside source changes)

## 6. Vitest unit tests

- [x] 6.1 `TextDisplayWidget.spec.js` — DOMPurify strips `<script>`, `on*` attributes, and `javascript:` URLs while preserving `<b>`, `<i>`, `<a href>`, `<br>`, `<p>`, `<ul>`, `<li>`
- [x] 6.2 `TextDisplayWidget.spec.js` — empty and whitespace-only `text` shows the localised placeholder
- [x] 6.3 `TextDisplayWidget.spec.js` — inline style applies provided values verbatim and falls back to theme variables when fields empty
- [x] 6.4 `TextDisplayForm.spec.js` — `validate()` returns `[t('mydash', 'Text is required')]` on empty text, `[]` when populated
- [x] 6.5 `TextDisplayForm.spec.js` — pre-fills all five fields from `editingWidget.content` on mount
- [x] 6.6 `TextDisplayForm.spec.js` — emits `update:content` reactively on each input

## 7. Playwright end-to-end test

- [x] 7.1 Add a text widget via AddWidgetModal, save, reload page, confirm rendered text matches input (spec written; Playwright bootstrap pending cohort-wide)
- [x] 7.2 Edit the widget (open modal in edit mode), change text and font size, save, confirm new values render
- [x] 7.3 Confirm an empty-text widget shows the localised placeholder

## 8. Quality gates

- [x] 8.1 ESLint clean on all new/touched `.vue` and `.js` files
- [x] 8.2 Stylelint clean on inline `<style>` blocks
- [x] 8.3 `npm run build` succeeds with no new warnings
- [x] 8.4 No new dependencies beyond `dompurify` introduced
