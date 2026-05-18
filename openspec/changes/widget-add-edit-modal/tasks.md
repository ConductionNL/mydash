# Tasks — widget-add-edit-modal

## Tasks

- [ ] Task 1: Create `src/constants/widgetRegistry.js` mapping `type → {component, label, defaults}` for the 5 widget types (`text`, `label`, `image`, `linkButton`, `ncDashboardProxy`) — single source of truth (REQ-WDG-014)
- [ ] Task 2: Create `src/composables/useWidgetForm.js` exposing `resetForm()`, `loadEditingWidget(widget)`, `validate()`, `assembleContent()`, and a `getActiveSubForm()` ref accessor so the modal can call `validate()` on the currently-mounted sub-form
- [ ] Task 3: Rewire the toolbar dropdown to consume `widgetRegistry` instead of any hard-coded local list
- [ ] Task 4: Build `src/components/Widgets/AddWidgetModal.vue` with conditional header (`Add Widget` vs `Edit Widget`), conditional type `<select>`, `<component :is="activeSubFormComponent">` slot, and Cancel/Add(Save) action buttons; the modal performs NO API calls itself
- [ ] Task 5: Implement open lifecycle — `show:false→true` triggers `resetForm()`; non-null `editingWidget` also calls `loadEditingWidget(editingWidget)`; hide the type selector whenever `preselectedType` or `editingWidget` is non-null
- [ ] Task 6: Type-switch handler swaps the active sub-form and resets form state (no cross-type leakage); submit computes `{type, content}` via `assembleContent()` and emits `submit`
- [ ] Task 7: Close discipline — Cancel emits `close`; backdrop `@click.self` emits `close`; Esc key listener added on mount, removed on `beforeDestroy`/`show=false` (no leaks), emits `close`; on close restore focus to the trigger element via prop or `data-trigger-id`
- [ ] Task 8: Validation pipeline — each sub-form exposes `validate(): string[]` (empty = valid); modal computes `isValid = activeSubFormRef.value?.validate().length === 0`; action button binds `:disabled="!isValid"` and surfaces first error via `title`/`aria-describedby`
- [ ] Task 9: Ship per-type sub-forms under `src/components/Widgets/forms/` — `TextForm`, `LabelForm`, `ImageForm`, `LinkButtonForm`, `NcDashboardProxyForm` — each importing its defaults from `widgetRegistry.defaults` on mount
- [ ] Task 10: Vitest — registry-driven select renders 5 options; type switch clears irrelevant fields (no text→image leak); edit mode pre-fills correctly per type; submit emits `{type, content}` with only the selected type's fields; validation gating disables/re-enables correctly on input
- [ ] Task 11: Playwright — backdrop click + Esc key + Cancel all emit `close` (none submit); open in edit mode, close, reopen — `editingWidget` content restored (no stale state)
- [ ] Task 12: Quality + a11y — ESLint clean; focus trap inside modal; ARIA `labelledby`/`describedby` on modal root; `nl`+`en` translations for `Add Widget`, `Edit Widget`, `Add`, `Save`, `Cancel`, `Type`; remove pre-existing per-widget edit dialogs replaced by the unified modal

## Verification

`openspec validate` exits clean. Modal works for both add + edit flows on all 5 widget types; no stale state between opens.

## Tests (company-wide ADR-009)

Vitest + Playwright per Tasks 10–11. No backend surface.

## Documentation (company-wide ADR-010)

Changelog entry noting the unified add/edit modal replaces per-widget dialogs; user-guide screenshot of the unified flow.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` per Task 12.
