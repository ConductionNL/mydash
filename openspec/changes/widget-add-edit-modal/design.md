# Design: Widget add/edit modal

**App:** MyDash  
**Change:** widget-add-edit-modal  
**Created:** 2026-05-21

## Overview

This change introduces a unified modal interface for adding and editing widgets on the MyDash dashboard. Today, "add widget" and "edit widget" flows are fragmented across multiple dialogs and code paths. This design consolidates both into a single, registry-driven `AddWidgetModal.vue` component that orchestrates type selection, per-type configuration, and form validation.

The modal is not a form builder — it's a host that routes control to per-type sub-forms (TextForm, LabelForm, ImageForm, LinkButtonForm, NcDashboardProxyForm) owned by their respective widget capabilities. A frontend registry is the single source of truth for "what widget types exist" and their form components.

## Architecture

### Component Structure

```
src/
├── components/Widgets/
│   ├── AddWidgetModal.vue          (modal host — new)
│   ├── forms/
│   │   ├── TextForm.vue            (sub-form)
│   │   ├── LabelForm.vue           (sub-form)
│   │   ├── ImageForm.vue           (sub-form)
│   │   ├── LinkButtonForm.vue      (sub-form)
│   │   └── NcDashboardProxyForm.vue (sub-form)
│   └── Toolbar.vue                 (consumes widgetRegistry)
│
├── composables/
│   └── useWidgetForm.js            (form state + validation — new)
│
└── constants/
    └── widgetRegistry.js           (type → component mapping — new)
```

### Data Flow

```
User clicks "Add Widget"
         ↓
Toolbar emits { preselectedType? }
         ↓
Parent renders <AddWidgetModal :show="true" :preselectedType="type" />
         ↓
Modal opens with conditional type selector
         ↓
useWidgetForm.resetForm() initializes state
         ↓
<component :is="activeSubForm"> mounts the per-type form
         ↓
User fills fields → sub-form.validate() → button enabled/disabled
         ↓
Submit click → assembleContent() → emit {type, content}
         ↓
Parent calls API and closes modal
```

## Reuse Analysis

This change consumes the following shared OR abstractions and platform components:

| Abstraction | Usage |
|---|---|
| `@conduction/nextcloud-vue :: NcModal` | Modal wrapper (zero custom logic) |
| `@conduction/nextcloud-vue :: NcButton` | Action buttons (cancel, submit) |
| `@conduction/nextcloud-vue :: NcSelect` | Type selector in create mode |
| Vue 2 Options API | Per-type sub-form components |
| Pinia store (existing dashboard widgets store) | Dashboard state management (no new store) |

**Deduplication Check:**
- No existing dashboard widget picker found in `openspec/specs/`.
- `ObjectService` is not used (widgets are stored in user dashboard config, not OpenRegister objects).
- No modal pattern conflict with other MyDash features.
- Per-type validation follows the same pattern as existing form sub-components in decidesk / procest.

**Verdict:** No duplication found. This is new capability, cleanly scoped.

## Component Details

### AddWidgetModal.vue (host)

**Props:**
- `show: boolean` — whether modal is visible
- `preselectedType: string | null` — if provided, type selector is hidden
- `editingWidget: object | null` — `{type, content}` for edit mode; toggles action button text
- `triggerElementId: string` — for focus restoration on close

**Events:**
- `@submit="{type, content}"` — form submitted
- `@close` — modal closed (cancel, backdrop, esc)

**Internal:**
- Conditional render of type `<select>` (hidden if `preselectedType` or `editingWidget`)
- `<component :is="activeSubForm">` slot hosting the per-type form
- Cancel button (emit `close`)
- Disabled-by-default action button (enabled when `validate()` green)
- Esc key listener with cleanup
- Backdrop click handler

**Validation:**
- Calls `activeSubFormRef.value?.validate()` on every form input
- Disables submit button when validation returns errors
- Displays first error in button title (ARIA describedby)

### useWidgetForm.js (composable)

**Methods:**
- `resetForm()` — initialize all state to registry defaults; called on modal open and type switch
- `loadEditingWidget(widget)` — pre-fill form from `{type, content}` object; called on edit mode open
- `validate()` — return validation errors (delegates to active sub-form); called reactively on input
- `assembleContent()` — return `{type, content}` with only selected-type fields; called on submit
- `getActiveSubForm()` — return ref to currently-mounted sub-form component for imperative method calls

**State:**
- `activeType: ref<string>` — currently selected type
- `activeSubFormRef: ref<Component>` — ref to active sub-form for validation calls
- `isEditMode: ref<boolean>` — toggle edit/create button text

### widgetRegistry.js (constant)

```javascript
export const widgetRegistry = [
  {
    type: 'text',
    component: TextForm,
    label: t('app', 'Text'),
    defaults: { text: '', fontSize: '16px', color: '#000000' }
  },
  {
    type: 'label',
    component: LabelForm,
    label: t('app', 'Label'),
    defaults: { label: '', align: 'left' }
  },
  {
    type: 'image',
    component: ImageForm,
    label: t('app', 'Image'),
    defaults: { url: '', alt: '', fit: 'cover' }
  },
  {
    type: 'linkButton',
    component: LinkButtonForm,
    label: t('app', 'Link Button'),
    defaults: { label: '', href: '', target: '_blank' }
  },
  {
    type: 'ncDashboardProxy',
    component: NcDashboardProxyForm,
    label: t('app', 'Nextcloud Widget'),
    defaults: { widgetId: '' }
  }
];

export const getWidgetByType = (type) => widgetRegistry.find(w => w.type === type);
export const getWidgetLabel = (type) => getWidgetByType(type)?.label || type;
```

### Per-type Sub-forms

Each sub-form (`TextForm`, `LabelForm`, etc.) is a simple Options API component with:

**Props:**
- `modelValue: object` — the `content` object for the current type
- `defaults: object` — from `widgetRegistry[type].defaults`

**Methods:**
- `validate(): string[]` — return validation errors (e.g., `[]` if text is non-empty, `['Text is required']` if empty)

**Events:**
- `@update:modelValue="newContent"` — emitted on any field change

**Validation Rules (per type):**
- `text`: text field required
- `label`: label field required, max 100 chars
- `image`: URL required, alt text recommended
- `linkButton`: label + href required
- `ncDashboardProxy`: widgetId required

## API Integration

**No backend changes.** The modal is a pure frontend refactor:
- Existing widget placement CRUD endpoints (`POST /api/dashboard/widgets`, `PUT /api/dashboard/widgets/{id}`, `DELETE /api/dashboard/widgets/{id}`) are unchanged
- The parent component (dashboard view) calls the API after modal emits `submit`
- Modal does not orchestrate the HTTP call

## Accessibility

- Modal has `role="dialog"` and `aria-labelledby="modal-title"`
- Type `<select>` has associated `<label>`
- Form fields in sub-forms are labeled (responsibility of per-type components)
- Action buttons have text labels (no icon-only buttons)
- Focus trap: Esc key closes; Tab cycles through focusable elements in modal
- First error message surfaced in button title (ARIA describedby)
- `prefers-reduced-motion` respected (no animation on modal open/close unless user opts in)

## i18n

Translation keys (English primary, Dutch in `l10n/nl.json`):
- `Add Widget` — modal title when creating
- `Edit Widget` — modal title when editing
- `Add` — action button text in create mode
- `Save` — action button text in edit mode
- `Cancel` — cancel button
- `Type` — label for type selector
- `Select widget type` — placeholder for type selector
- Per-type labels via `widgetRegistry[].label`

Dutch translations in `l10n/nl.json`:
- `Widget toevoegen`
- `Widget bewerken`
- `Toevoegen`
- `Opslaan`
- `Annuleren`
- `Type`
- `Selecteer widgettype`

## State Management

**No new Pinia store.** Modal state is local (`useWidgetForm` composable, ref-based). The parent component holds the dashboard's widget list in the existing dashboard store.

**Existing dashboard store responsibility:**
- `widgets: Widget[]` — list of dashboard widgets
- `addWidget(type, content)` — append widget
- `updateWidget(id, content)` — mutate widget
- `deleteWidget(id)` — remove widget

**Modal responsibility:**
- Form orchestration only
- Validation
- Emitting the payload — does not mutate the store

## Error Handling

**Form-level validation:** Each sub-form's `validate()` method surfaces field-specific errors. The modal disables submit and displays the first error.

**API errors:** Handled by the parent component (dashboard view) after modal emits `submit`. Modal does not call the API, so HTTP errors are parent's concern.

**Edge cases:**
- Widget type removed from registry after modal opens: graceful fallback to first type in registry on reopen
- Parent closes modal while API call is in-flight: no issue (modal is pure presenter)
- User switches type mid-edit: form state resets (explicit trade-off per proposal)

## Testing Strategy

**Unit (Vitest):**
- `widgetRegistry` exports exactly 5 types with correct structure
- `useWidgetForm::resetForm()` initializes state to registry defaults
- `useWidgetForm::loadEditingWidget()` pre-fills form correctly per type
- `useWidgetForm::validate()` delegates to active sub-form and returns errors
- `useWidgetForm::assembleContent()` returns only selected-type fields
- Type switch clears irrelevant fields (no text→image leakage)

**Integration (Playwright):**
- Modal opens in create mode with type selector visible
- Modal opens in edit mode with pre-filled content, type selector hidden
- Backdrop click / Esc key / Cancel button all emit `close` (no submit)
- Submit emits `{type, content}` payload
- Focus is restored to trigger element on close
- Per-type sub-form validation disables/enables submit button

**Manual (browser):**
- Smoke test each widget type: add, edit, delete flows
- Check WCAG AA compliance: keyboard navigation, color contrast, focus visibility
- Verify i18n: toggle language, check all UI strings

## Deployment

- No database migrations (no backend changes)
- No app version bump required (frontend-only refactor)
- Backward compatible with existing widget placements (no schema change)
- Feature flag: none needed (modal is replacement for existing dialogs, not an opt-in)

## Deferred Work

- Phase 2: Advanced widget customization (threshold values, date ranges for KPI widgets)
- Phase 3: Widget sharing between users (shared dashboard collections)
- Phase 4: Custom widget creation (end-user widget builder)
- Phase 5: Widget preview before adding (thumbnail gallery)
