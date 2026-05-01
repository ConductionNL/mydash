# Link-button widget

## Why

MyDash today has no first-class action button. Users cannot drop a styled clickable tile on a dashboard to open an external URL in a new tab, kick off a registered in-app workflow, or create a fresh document in their Files app. An earlier prototype shipped an ad-hoc "internal action" placeholder with auto-detect-from-extension semantics that proved fragile (a `.docx` URL meant "create file" but a `.html` URL meant "open external", indistinguishable for opaque links). This change formalises a typed `link` widget with three explicit action types, a runtime-mutable registry of named internal actions, and a strictly-validated server endpoint for file creation, so the action set can grow safely without regressing the existing widget contract.

## What Changes

- Introduce a new widget `type: 'link'` registered in `widgetRegistry.js` with default content `{label:'', url:'', icon:'', actionType:'external', backgroundColor:'', textColor:''}`.
- Add a typed `actionType` enum: `external | internal | createFile` (no auto-detect from URL extension).
- Implement renderer `LinkButtonWidget.vue` dispatching click on `actionType`, suppressing all clicks while in admin/edit mode, disabling the button while an action is in flight.
- Add an `IconRenderer` integration for both built-in MDI names and uploaded resource URLs (dual-mode like `dashboard-icons`).
- Introduce a singleton frontend composable `useInternalActions()` exposing `register(id, fn)`, `invoke(id)`, `has(id)` — the registry starts empty; concrete actions are registered by other capabilities later.
- Implement add/edit sub-form `LinkButtonForm.vue` with all six fields, placeholder text that swaps with `actionType`, and a `validate()` requiring both `label` and `url`.
- Add a NEW server endpoint `POST /api/files/create` accepting `{filename, dir, content}` with strict regex filename validation, dir traversal rejection, an admin-configurable extension allow-list (default `txt, md, docx, xlsx, csv, odt`), overwrite-on-exists semantics, and a response payload of `{status, fileId, url}` where `url` opens the Files app viewer.
- Translation strings for all visible UI labels and toasts (English + Dutch).

## Capabilities

### New Capabilities

- `link-button-widget` — adds REQ-LBN-001 (renderer with three action types), REQ-LBN-002 (icon resolution), REQ-LBN-003 (createFile flow), REQ-LBN-004 (server-side file-creation endpoint), REQ-LBN-005 (internal action registry), REQ-LBN-006 (add/edit form), REQ-LBN-007 (default styling).

### Modified Capabilities

(none — this change is fully additive)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/LinkButtonWidget.vue` — new renderer + inline filename-prompt modal child component
- `src/components/Widgets/Forms/LinkButtonForm.vue` — new add/edit sub-form with six fields
- `src/composables/useInternalActions.js` — new singleton registry composable
- `src/constants/widgetRegistry.js` — register `type: 'link'` with default content shape
- `lib/Controller/FileController.php` — new `createFile` action mapped to `POST /api/files/create`
- `lib/Service/FileService.php` — new `createFile()` method with strict validation, allow-list enforcement, overwrite semantics
- `appinfo/routes.php` — register the new POST route
- `lib/Settings/AdminSettings.php` — surface admin-configurable extension allow-list
- `l10n/en.js`, `l10n/nl.js` — translation strings for all new labels and toasts

**Affected APIs:**

- 1 new route (`POST /api/files/create`) — no existing routes changed

**Dependencies:**

- `OCP\Files\IRootFolder` — already injected elsewhere in the app
- `OCP\IURLGenerator` — already injected elsewhere
- No new composer or npm dependencies

**Migration:**

- Zero schema changes — widget shape lives inside the existing `content` JSON blob on widget placements.
- Existing dashboards continue to work unchanged; the `link` type only appears once an admin explicitly adds a Link Button widget.
