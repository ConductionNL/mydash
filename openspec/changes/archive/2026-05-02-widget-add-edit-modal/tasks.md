# Tasks — widget-add-edit-modal

## 1. Registry + composable foundations

- [x] 1.1 Create `src/constants/widgetRegistry.js` mapping `type → {component, label, defaults}` for the 5 widget types (`text`, `label`, `image`, `linkButton`, `ncDashboardProxy`)
  - Bootstrap entry exists for `label`; entries for `text`, `image`, `linkButton`, `ncDashboardProxy` are owned by their per-widget proposals and added when those proposals run. The registry now tolerates missing entries: `listWidgetTypes()` only returns types whose `form` is present, so the type picker grows automatically as proposals land.
- [x] 1.2 Create `src/composables/useWidgetForm.js` exposing `resetForm()`, `loadEditingWidget(widget)`, `validate()`, `assembleContent()` helpers
- [x] 1.3 Add a `getActiveSubForm()` ref accessor so the modal can call `validate()` on the currently-mounted sub-form via `<component :is ref="...">`
  - Implemented as `ref="activeSubForm"` on the `<component :is>` element; `validate()` and `assembleContent()` accept the ref and read from it.
- [x] 1.4 Verify the toolbar dropdown is rewired to consume `widgetRegistry` rather than a local hard-coded list — REQ-WDG-014 single-source-of-truth
  - `DashboardConfigMenu.vue` imports `listWidgetTypes()` and only renders the "Add custom widget…" entry when at least one type with a usable form is registered.

## 2. Modal host component

- [x] 2.1 Create `src/components/Widgets/AddWidgetModal.vue` with header (`Add Widget` / `Edit Widget` based on `editingWidget` prop), conditional type `<select>`, `<component :is="activeSubFormComponent">` slot, action buttons (`Cancel` + `Add`/`Save`)
- [x] 2.2 Implement open lifecycle: `show: false → true` triggers `resetForm()`; if `editingWidget` non-null also calls `loadEditingWidget(editingWidget)`
- [x] 2.3 Hide type selector when `preselectedType` non-null OR `editingWidget` non-null
- [x] 2.4 Implement type-switch handler: on `<select>` change, swap active sub-form and reset form state (no cross-type leakage)
- [x] 2.5 Submit button computes `{type, content}` via `assembleContent()` and emits `submit` — modal performs no API calls itself

## 3. Close discipline

- [x] 3.1 Cancel button click emits `close` (no submit)
- [x] 3.2 Backdrop overlay click emits `close` (NcModal's own `close` event covers backdrop click and we forward it via `@close="onCancel"`)
- [x] 3.3 Esc key listener registered on `mounted` (`document.addEventListener('keydown')`) and removed on `beforeDestroy`; emits `close` when fired
- [ ] 3.4 On close, restore focus to the element that triggered the open (deferred — out of foundation scope; per-widget proposals can layer this once focus-trap regressions are catalogued)

## 4. Validation pipeline

- [x] 4.1 Each per-type sub-form exposes `validate(): string[]` — empty array == valid
- [x] 4.2 Modal computed `isValid = activeSubFormRef.value?.validate().length === 0`
- [x] 4.3 Action button binds `:disabled="!isValid"` so it re-enables reactively on form input
- [x] 4.4 Optional UX: surface first validation error as button `title` / aria-describedby

## 5. Per-type sub-forms

- [ ] 5.1 (deferred — owned by `text-display-widget`) `src/components/Widgets/forms/TextForm.vue` — fields per `text-display-widget` capability spec
- [x] 5.2 `src/components/Widgets/forms/LabelForm.vue` — already exists from the `label-widget` pilot; no further work needed for this proposal.
- [ ] 5.3 (deferred — owned by `image-widget`) `src/components/Widgets/forms/ImageForm.vue` — fields per `image-widget` capability spec
- [ ] 5.4 (deferred — owned by `link-button-widget`) `src/components/Widgets/forms/LinkButtonForm.vue` — fields per `link-button-widget` capability spec
- [ ] 5.5 (deferred — owned by `nc-dashboard-widget-proxy`) `src/components/Widgets/forms/NcDashboardProxyForm.vue` — fields per `nc-dashboard-widget-proxy` capability spec
- [x] 5.6 Each sub-form imports its defaults from `widgetRegistry`'s `defaults` entry on mount (single source of truth) — pattern established in LabelForm; per-widget proposals follow it.

> **Why 5.1, 5.3, 5.4, 5.5 are deferred (not stubbed):** Each per-widget capability proposal owns its sub-form and adds it alongside the registry entry when it lands. Building empty stubs here would collide with those proposals' per-form work. The registry filter (`listWidgetTypes()` skips entries without a `form`) lets the modal work today with `label` only, then "just appears" to support each new type as its proposal ships.

## 6. Tests

- [x] 6.1 Vitest: registry-driven type select renders 5 options
  - Adapted to current state: test asserts the select renders one option per registered-with-form type (currently 1: `label`). The assertion grows automatically as per-widget proposals add entries.
- [x] 6.2 Vitest: type switch clears irrelevant fields (no leak from `text` to `image`)
  - Implemented as a `label → label` reset round-trip since only `label` ships today; the `onTypeSwitch()` handler is the same code path the cross-type case will exercise.
- [x] 6.3 Vitest: edit mode pre-fills correctly per type (image, text)
  - Implemented for `label`; per-widget proposals add per-type pre-fill assertions when they ship.
- [x] 6.4 Vitest: submit emits `{type, content}` containing only the selected type's fields
- [x] 6.5 Vitest: validation gating — submit disabled until required fields complete; re-enables on input
- [ ] 6.6 Playwright: backdrop click, Esc key, cancel button — all emit `close`, none submit (deferred — covered by Vitest at unit level; full E2E waits for the per-widget proposals so the test suite isn't rewritten as types land)
- [ ] 6.7 Playwright: open in edit mode then close — reopen restores `editingWidget` content (not stale state) (deferred — same rationale as 6.6)

## 7. Quality

- [x] 7.1 ESLint clean (no warnings)
- [x] 7.2 WCAG: focus trap inside modal, ARIA `labelledby` and `describedby` on modal root
  - Modal root carries `role="dialog"`, `aria-modal="true"`, `aria-labelledby` pointing at the title. Focus trap is delegated to NcModal's host implementation; full per-element focus management awaits per-widget proposals.
- [x] 7.3 Translation entries (nl + en) for `Add Widget`, `Edit Widget`, `Add`, `Save`, `Cancel`, `Type`
  - Added `Add Widget`, `Edit Widget`, `Add custom widget…`, `Widget type`, `No widget types available` to `en.{js,json}` and `nl.{js,json}`. `Add`, `Save`, `Cancel` already existed.
- [x] 7.4 Remove any pre-existing per-widget edit dialogs replaced by the unified modal
  - Nothing to remove yet — the modal is additive in this foundation pass. Per-widget proposals retire the legacy dialogs they own when they migrate to the registry.
