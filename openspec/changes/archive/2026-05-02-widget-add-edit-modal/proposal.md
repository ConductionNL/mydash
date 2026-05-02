# Widget add/edit modal

## Why

Today MyDash has separate code paths for "add a widget" (toolbar dropdown → per-type creator) and "edit a widget" (per-widget edit dialogs scattered across the codebase). Both flows duplicate field definitions, validation logic, and modal chrome. Adding a new widget type today means touching at least three places: the toolbar dropdown, the creation flow, and an ad-hoc edit dialog. This change collapses both flows into a single registry-driven modal whose only job is orchestration (open/close, type switch, edit pre-fill, validation routing). Per-type sub-forms become small components owned by their respective widget capabilities, and one frontend registry becomes the single source of truth for "what widget types exist".

## What Changes

- Add a unified `AddWidgetModal.vue` host component that handles both creation and editing flows.
- Add `useWidgetForm.js` composable exposing `resetForm`, `loadEditingWidget`, `validate`, `assembleContent`.
- Add `widgetRegistry.js` mapping `type → { component, label, defaults }` for the 5 widget types (`text`, `label`, `image`, `linkButton`, `ncDashboardProxy`); the toolbar dropdown, modal type selector, and grid renderer MUST all consult this registry.
- Per-type sub-form components (one per widget capability) expose `validate(): string[]`; the modal disables submit when validation returns errors.
- Modal close triggers: cancel button, backdrop click, `Escape` key — none submit.
- Type-switch in create mode resets form state (no cross-type field leakage).
- Edit mode hides the type selector (placement type is immutable) and pre-fills from `editingWidget.content`.

## Capabilities

### New Capabilities

(none — folds into the existing `widgets` capability)

### Modified Capabilities

- `widgets`: extends REQ-WDG-010 (Widget Picker) with modal orchestration semantics, and adds REQ-WDG-012 (per-type validation contract), REQ-WDG-013 (modal close discipline), REQ-WDG-014 (sub-form registry as single source of truth).

## Impact

**Affected code:**

- `src/components/Widgets/AddWidgetModal.vue` — the host modal (new)
- `src/components/Widgets/forms/<Type>Form.vue` — per-type sub-forms (one per widget capability: TextDisplay, Label, Image, LinkButton, NcDashboardProxy)
- `src/composables/useWidgetForm.js` — shared validation + submit pipeline (new)
- `src/constants/widgetRegistry.js` — single registry of widget types (new)
- `src/components/Toolbar/*` — toolbar dropdown rewired to consume the registry
- Any existing per-widget edit dialogs are removed in favour of the unified modal

**Affected APIs:**

- None. This is a pure frontend refactor; placement CRUD endpoints from the existing `widgets` capability remain unchanged.

**Dependencies:**

- No new composer or npm dependencies. `<component :is>` is native Vue 2.

**Trade-offs:**

- Switching widget type mid-form discards in-progress field input (explicit choice — recovery would require retaining shadow state per type, doubling complexity).
- Edit mode cannot change a placement's type; users must delete + re-add to switch type.
