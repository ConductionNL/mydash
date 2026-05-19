# Tasks — label-widget

## Tasks

- [ ] Task 1: Create `src/components/Widgets/Renderers/LabelWidget.vue` with a `<div><span>{{ displayText }}</span></div>` template — interpolation only, NEVER `v-html`
- [ ] Task 2: Implement `displayText` computed returning `content.text` when `text.trim() !== ''`, otherwise the localised fallback `t('mydash', 'Label')`
- [ ] Task 3: Implement `wrapperStyle` (`width:100%; height:100%; padding:12px; display:flex; align-items:center; justify-content:center; background-color: <bg or transparent>`) and `spanStyle` (`font-size; font-weight; text-align; color; overflow-wrap: break-word`) with REQ-LBL-002 defaults; `color` default resolves to `var(--color-main-text)` so theming works light/dark
- [ ] Task 4: Component-scoped CSS includes `overflow-wrap: break-word` as a safety net even when inline style is overridden
- [ ] Task 5: Create `src/components/Widgets/Forms/LabelForm.vue` with the six REQ-LBL-005 controls (text input, fontSize text, color picker, backgroundColor picker, fontWeight `<select>`, textAlign `<select>`); pre-fill every control from `editingWidget.content` in edit mode and emit `update:content` on every input
- [ ] Task 6: Implement form `validate()` returning `[t('mydash', 'Label text is required')]` when `text.trim() === ''`, otherwise `[]`; use translation keys `Font Weight` and `Alignment` for the two select labels
- [ ] Task 7: Register `label` in `src/constants/widgetRegistry.js` with `renderer: LabelWidget`, `form: LabelForm`, `defaultContent: {text:'', fontSize:'16px', color:'', backgroundColor:'', fontWeight:'bold', textAlign:'center'}`, icon + `displayName: t('mydash','Label')`, and verify the type is distinct from `text` in the picker
- [ ] Task 8: Translations — add `Label`, `Label text is required`, `Font Weight`, `Alignment` to `src/l10n/en.json`; Dutch equivalents (`Label`, `Labeltekst is verplicht`, `Letterdikte`, `Uitlijning`) to `src/l10n/nl.json`
- [ ] Task 9: Vitest renderer — HTML in `text` appears as literal text (no `<b>` element in DOM — REQ-LBL-001); defaults applied to `{text:'Hi'}` set `font-size:16px`, `font-weight:bold`, `text-align:center` (REQ-LBL-002); long single word wraps in a narrow container (REQ-LBL-003); empty text shows the `t('Label')` fallback (REQ-LBL-004)
- [ ] Task 10: Vitest form + registry — `validate()` reports the required-text message correctly; pre-fills all six controls from `editingWidget.content`; importing `widgetRegistry.js` exposes a `label` entry with the correct `defaultContent` (REQ-LBL-007)
- [ ] Task 11: Playwright (`tests/e2e/label-widget.spec.ts`) — open Add Widget modal → pick Label → fill text + change fontSize to `24px` + change colour → save → reopen in edit mode and confirm all six fields round-trip; pasting `<b>HTML</b>` renders as literal text on the dashboard
- [ ] Task 12: Quality — ESLint clean on the two new `.vue` files + modified `widgetRegistry.js`; `npm run build` succeeds with no warnings; no new browser console errors on render/edit/remove; manual smoke test in `nldesign` theme confirms the default colour resolves correctly in light + dark mode

## Verification

`openspec validate` exits clean. Label rendering remains XSS-safe (literal text); add/edit round-trip preserves all six controls.

## Tests (company-wide ADR-009)

Vitest per Tasks 9–10; Playwright per Task 11. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry for the new widget type; user-guide screenshot of a styled label widget.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 8.
