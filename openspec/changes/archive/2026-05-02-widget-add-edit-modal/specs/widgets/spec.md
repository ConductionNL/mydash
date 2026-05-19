---
capability: widgets
delta: true
status: draft
---

# Widgets — Delta from change `widget-add-edit-modal`

## MODIFIED Requirements

### Requirement: Widget Picker (REQ-WDG-010)

The widget picker MUST be implemented as a single modal that handles both creation and editing flows. The modal MUST present a type selector at the top (unless a type was preselected by the caller) followed by the per-type configuration sub-form for the currently selected type. Submit MUST emit `{type, content}` where `content` carries only the fields relevant to the selected type — fields belonging to other types MUST NOT be included.

#### Scenario: Open in create mode without preselected type

- GIVEN no widget is being edited and no type was preselected
- WHEN the user opens the modal with `show=true, preselectedType=null, editingWidget=null`
- THEN the modal MUST render a `<select>` listing every registered widget type
- AND the form area MUST render the sub-form for the first type (alphabetical or registry order)
- AND the action button MUST read `t('Add')`

#### Scenario: Open in create mode with preselected type

- GIVEN the toolbar dropdown invoked the modal with a specific type
- WHEN the user opens the modal with `show=true, preselectedType='text', editingWidget=null`
- THEN the type `<select>` MUST NOT be visible
- AND the form area MUST render the text sub-form
- AND the action button MUST read `t('Add')`

#### Scenario: Open in edit mode

- GIVEN an existing widget placement is being edited
- WHEN the user opens the modal with `editingWidget={type:'image', content:{url:'/img/x.png', alt:'X', fit:'cover'}}`
- THEN the type `<select>` MUST be hidden (cannot change a placement's type via edit)
- AND the image sub-form's fields MUST be pre-filled from the editing widget's `content`
- AND the action button MUST read `t('Save')`
- AND the modal title MUST read `t('Edit Widget')` instead of `t('Add Widget')`

#### Scenario: Switching type resets form state

- GIVEN the modal is open in create mode with `text` type and the user has typed text
- WHEN the user switches the type to `image` via the `<select>`
- THEN the form MUST swap to the image sub-form
- AND any previously-entered text MUST NOT leak into the image sub-form
- AND switching back to `text` MUST reset its fields to defaults (no recovery of the lost input — explicit trade-off)

#### Scenario: Submit emits only relevant fields

- GIVEN the user fills the text sub-form with `{text: "Hello", fontSize: "16px"}`
- WHEN they click `Add`
- THEN the modal MUST emit `submit({type: 'text', content: {text: 'Hello', fontSize: '16px'}})`
- AND `content` MUST NOT contain image fields like `url`, `alt`, etc.

## ADDED Requirements

### Requirement: Per-type validation contract (REQ-WDG-012)

Each per-type sub-form component MUST expose a `validate(): string[]` method that returns an array of human-readable error messages (empty array = valid). The modal MUST disable its primary action button when the active sub-form's `validate()` returns a non-empty array. The button MUST re-enable as soon as `validate()` returns empty (reactive on form input).

#### Scenario: Required field empty disables submit

- GIVEN the text sub-form requires `text` to be non-empty
- AND the user has not entered any text
- WHEN the modal renders
- THEN the `Add` button MUST be disabled
- AND tooltip / aria-describedby MAY surface the validation message (UX choice)

#### Scenario: Filling required field enables submit

- GIVEN the `Add` button is disabled because text is empty
- WHEN the user types `Hello`
- THEN the button MUST become enabled within the next render cycle

### Requirement: Modal close discipline (REQ-WDG-013)

The modal MUST close on three triggers:

1. Click on the cancel button (emit `close`)
2. Click on the backdrop overlay (emit `close`)
3. Press the `Escape` key while the modal is focused (emit `close`)

Closing the modal MUST NOT submit. Reopening after a close MUST reset all form state to defaults (or re-load `editingWidget` if still set).

#### Scenario: Backdrop click closes without submit

- GIVEN the modal is open with valid form state
- WHEN the user clicks the backdrop
- THEN the modal MUST emit `close` only
- AND MUST NOT emit `submit`

#### Scenario: Esc key closes modal

- GIVEN the modal is open
- WHEN the user presses `Escape`
- THEN the modal MUST emit `close`
- AND focus MUST return to the element that triggered the open

#### Scenario: Cancel button closes without submit

- GIVEN the modal is open with valid form state
- WHEN the user clicks the cancel button
- THEN the modal MUST emit `close` only
- AND MUST NOT emit `submit`

### Requirement: Sub-form registry (REQ-WDG-014)

The set of supported widget types MUST come from a single in-frontend registry that maps `type → { component, label, defaults }`. The toolbar dropdown, the modal type selector, and the grid renderer MUST all consult the same registry.

#### Scenario: Adding a new widget type

- GIVEN a developer needs to register a new widget type
- WHEN they add a new entry to the widget registry (`{component, label, defaults}`)
- THEN the new type MUST automatically appear in the toolbar dropdown
- AND the modal type selector MUST list it
- AND the grid renderer MUST render placements of the new type
- AND no other UI code MUST need to be changed to support the new type

#### Scenario: Registry is the single source of truth

- GIVEN the widget registry contains exactly 5 entries (`text`, `label`, `image`, `linkButton`, `ncDashboardProxy`)
- WHEN any consumer (toolbar, modal, renderer) enumerates widget types
- THEN it MUST list those 5 types
- AND MUST NOT hard-code any type name elsewhere in the codebase
