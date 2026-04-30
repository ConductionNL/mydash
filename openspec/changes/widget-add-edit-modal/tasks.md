# Tasks — widget-add-edit-modal

## 1. Registry + composable foundations

- [ ] 1.1 Create `src/constants/widgetRegistry.js` mapping `type → {component, label, defaults}` for the 5 widget types (`text`, `label`, `image`, `linkButton`, `ncDashboardProxy`)
- [ ] 1.2 Create `src/composables/useWidgetForm.js` exposing `resetForm()`, `loadEditingWidget(widget)`, `validate()`, `assembleContent()` helpers
- [ ] 1.3 Add a `getActiveSubForm()` ref accessor so the modal can call `validate()` on the currently-mounted sub-form via `<component :is ref="...">`
- [ ] 1.4 Verify the toolbar dropdown is rewired to consume `widgetRegistry` rather than a local hard-coded list — REQ-WDG-014 single-source-of-truth

## 2. Modal host component

- [ ] 2.1 Create `src/components/Widgets/AddWidgetModal.vue` with header (`Add Widget` / `Edit Widget` based on `editingWidget` prop), conditional type `<select>`, `<component :is="activeSubFormComponent">` slot, action buttons (`Cancel` + `Add`/`Save`)
- [ ] 2.2 Implement open lifecycle: `show: false → true` triggers `resetForm()`; if `editingWidget` non-null also calls `loadEditingWidget(editingWidget)`
- [ ] 2.3 Hide type selector when `preselectedType` non-null OR `editingWidget` non-null
- [ ] 2.4 Implement type-switch handler: on `<select>` change, swap active sub-form and reset form state (no cross-type leakage)
- [ ] 2.5 Submit button computes `{type, content}` via `assembleContent()` and emits `submit` — modal performs no API calls itself

## 3. Close discipline

- [ ] 3.1 Cancel button click emits `close` (no submit)
- [ ] 3.2 Backdrop overlay click emits `close` (do not bubble inner clicks — guard via `@click.self`)
- [ ] 3.3 Esc key listener registered on `mounted` (`document.addEventListener('keydown')`) and removed on `beforeDestroy` / when `show=false` to prevent leaks; emits `close` when fired
- [ ] 3.4 On close, restore focus to the element that triggered the open (track via prop or a `data-trigger-id` attribute)

## 4. Validation pipeline

- [ ] 4.1 Each per-type sub-form exposes `validate(): string[]` — empty array == valid
- [ ] 4.2 Modal computed `isValid = activeSubFormRef.value?.validate().length === 0`
- [ ] 4.3 Action button binds `:disabled="!isValid"` so it re-enables reactively on form input
- [ ] 4.4 Optional UX: surface first validation error as button `title` / aria-describedby

## 5. Per-type sub-forms

- [ ] 5.1 `src/components/Widgets/forms/TextForm.vue` — fields per `text-display-widget` capability spec
- [ ] 5.2 `src/components/Widgets/forms/LabelForm.vue` — fields per `label-widget` capability spec
- [ ] 5.3 `src/components/Widgets/forms/ImageForm.vue` — fields per `image-widget` capability spec
- [ ] 5.4 `src/components/Widgets/forms/LinkButtonForm.vue` — fields per `link-button-widget` capability spec
- [ ] 5.5 `src/components/Widgets/forms/NcDashboardProxyForm.vue` — fields per `nc-dashboard-widget-proxy` capability spec
- [ ] 5.6 Each sub-form imports its defaults from `widgetRegistry`'s `defaults` entry on mount (single source of truth)

## 6. Tests

- [ ] 6.1 Vitest: registry-driven type select renders 5 options
- [ ] 6.2 Vitest: type switch clears irrelevant fields (no leak from `text` to `image`)
- [ ] 6.3 Vitest: edit mode pre-fills correctly per type (image, text)
- [ ] 6.4 Vitest: submit emits `{type, content}` containing only the selected type's fields
- [ ] 6.5 Vitest: validation gating — submit disabled until required fields complete; re-enables on input
- [ ] 6.6 Playwright: backdrop click, Esc key, cancel button — all emit `close`, none submit
- [ ] 6.7 Playwright: open in edit mode then close — reopen restores `editingWidget` content (not stale state)

## 7. Quality

- [ ] 7.1 ESLint clean (no warnings)
- [ ] 7.2 WCAG: focus trap inside modal, ARIA `labelledby` and `describedby` on modal root
- [ ] 7.3 Translation entries (nl + en) for `Add Widget`, `Edit Widget`, `Add`, `Save`, `Cancel`, `Type`
- [ ] 7.4 Remove any pre-existing per-widget edit dialogs replaced by the unified modal
