# Tasks — label-widget

## 1. Renderer component

- [x] 1.1 Create `src/components/Widgets/Renderers/LabelWidget.vue` with a `<div><span>{{ displayText }}</span></div>` template — interpolation only, never `v-html`
- [x] 1.2 Implement `displayText` computed: returns `content.text` when `text.trim() !== ''`, else returns `t('mydash', 'Label')`
- [x] 1.3 Implement `wrapperStyle` computed returning `width:100%; height:100%; padding:12px; display:flex; align-items:center; justify-content:center; background-color: <bg or transparent>`
- [x] 1.4 Implement `spanStyle` computed returning `font-size; font-weight; text-align; color; overflow-wrap: break-word`, falling back to defaults per REQ-LBL-002 when fields are missing
- [x] 1.5 Default `color` MUST resolve to `var(--color-main-text)` so theming works in both light and dark mode
- [x] 1.6 Add component-scoped CSS for `overflow-wrap: break-word` as a safety net even if inline style is overridden

## 2. Form component

- [x] 2.1 Create `src/components/Widgets/Forms/LabelForm.vue` with the six controls listed in REQ-LBL-005 (text input, fontSize text input, color picker, backgroundColor picker, fontWeight select, textAlign select)
- [x] 2.2 Pre-fill every control from `editingWidget.content` when in edit mode (per REQ-LBL-005)
- [x] 2.3 Implement `validate()` returning `[t('mydash', 'Label text is required')]` when `text.trim() === ''`, otherwise an empty array
- [x] 2.4 Wire form input events to `$emit('update:content', {...})` so the parent modal sees live changes
- [x] 2.5 Use translation keys `Font Weight` and `Alignment` for the two select labels

## 3. Widget registry

- [x] 3.1 Add a `label` entry to `src/constants/widgetRegistry.js` with `renderer: LabelWidget`, `form: LabelForm`
- [x] 3.2 Set `defaultContent: {text: '', fontSize: '16px', color: '', backgroundColor: '', fontWeight: 'bold', textAlign: 'center'}`
- [x] 3.3 Add an icon and `displayName: t('mydash', 'Label')` so the type appears as a selectable option in the Add Widget modal
- [x] 3.4 Verify the `label` type is distinct from `text` in the modal's type-picker UI

## 4. Translations

- [x] 4.1 Add the four English keys to `src/l10n/en.json`: `Label`, `Label text is required`, `Font Weight`, `Alignment`
- [x] 4.2 Add the four Dutch translations to `src/l10n/nl.json`: `Label` → `Label`, `Label text is required` → `Labeltekst is verplicht`, `Font Weight` → `Letterdikte`, `Alignment` → `Uitlijning`

## 5. Vitest unit tests

- [x] 5.1 `LabelWidget.spec.js`: HTML in `text` appears as literal text — assert no `<b>` element appears in mounted DOM (REQ-LBL-001)
- [x] 5.2 `LabelWidget.spec.js`: defaults applied when `content = {text: 'Hi'}` — assert inline style contains `font-size: 16px`, `font-weight: bold`, `text-align: center` (REQ-LBL-002)
- [x] 5.3 `LabelWidget.spec.js`: long single word wraps — mount in narrow container, assert no horizontal overflow on inner span (REQ-LBL-003)
- [x] 5.4 `LabelWidget.spec.js`: empty `text` shows `t('Label')` fallback (REQ-LBL-004)
- [x] 5.5 `LabelForm.spec.js`: `validate()` returns error on empty text, empty array on non-empty text (REQ-LBL-005)
- [x] 5.6 `LabelForm.spec.js`: pre-fills all six controls from `editingWidget.content` (REQ-LBL-005)
- [x] 5.7 Registry test: importing `widgetRegistry.js` exposes a `label` entry with the correct `defaultContent` (REQ-LBL-007)

## 6. Playwright end-to-end test

- [x] 6.1 Add `tests/e2e/label-widget.spec.ts` covering: open Add Widget modal → pick Label → fill text + change fontSize to `24px` + change colour → save
- [x] 6.2 Same test reopens the placement in edit mode and asserts all six fields round-trip identically
- [x] 6.3 Same test verifies that pasting `<b>HTML</b>` into the text field renders as literal text (no bold styling) on the dashboard

## 7. Quality gates

- [x] 7.1 ESLint clean on the two new `.vue` files and the modified `widgetRegistry.js`
- [x] 7.2 `npm run build` succeeds without warnings
- [x] 7.3 No new console errors in the browser when the widget is rendered, edited, or removed
- [x] 7.4 Manual smoke test in `nldesign` theme to confirm the default `var(--color-main-text)` colour resolves correctly in both light and dark mode
