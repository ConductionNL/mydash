---
status: implemented
---

# Link-Button Widget Specification

## Purpose

The link-button widget is a built-in MyDash widget type that lets dashboard authors drop a styled, clickable tile onto a dashboard. The tile dispatches one of three explicit action types — open an external URL in a new tab, invoke a registered in-app workflow, or create a fresh document in the user's Files area. The capability formalises a typed `actionType` enum so the action set can grow safely (no fragile auto-detect-from-extension semantics like the earlier prototype), pairs the renderer with a singleton frontend registry of named internal actions, and pairs the createFile flow with a strictly-validated server endpoint that gates new files behind an admin-configurable extension allow-list.

The capability is one widget type, one renderer, one sub-form, one registry entry, one composable, and one POST endpoint — small enough to ship and evolve independently, but deliberately sized to anchor the future "tile-based action menu" experience that other capabilities will build on top of via the `internal` action registry.

## Data Model

Link-button placements use the existing `oc_mydash_widget_placements.styleConfig` JSON column with the discriminated shape `{type: 'link', content: {...}}`. No schema migration is required.

The `content` object carries six fields:

- **label** (string, required) — the visible button text
- **url** (string, required) — semantics depend on `actionType`: a real URL for `external`, an action id for `internal`, an extension token (e.g. `docx`) for `createFile`
- **icon** (string, optional) — either an MDI registry name (e.g. `Star`), a custom URL starting with `/` or `http`, or empty for label-only
- **actionType** (`external` | `internal` | `createFile`, default `external`) — explicit click branch
- **backgroundColor** (string, default `var(--color-primary)` when empty) — any CSS colour
- **textColor** (string, default `var(--color-primary-text)` when empty) — any CSS colour

Admin-side state for the createFile flow lives in the existing `mydash_admin_settings` table under the key `link_create_file_extensions` — a JSON array of lowercase, dot-stripped extensions defaulting to `["txt","md","docx","xlsx","csv","odt"]`.

## Requirements

### Requirement: REQ-LBN-001 Renderer with three action types

The renderer MUST output a `<button>` whose click handler dispatches based on the `actionType` field of the persisted widget content. The three branches are:

1. `external` → `window.open(url, '_blank', 'noopener,noreferrer')`
2. `internal` → resolve `url` against the internal-action registry (REQ-LBN-005) and invoke the registered function; ignore (no-op) when no matching action is registered
3. `createFile` → open an inline filename-prompt modal (REQ-LBN-003)

The renderer MUST suppress all click handlers when `isAdmin === true` AND the surrounding dashboard is in edit mode, so that configuring the widget cannot accidentally fire actions. The button MUST carry a `disabled` attribute while an action is in flight (`isExecuting === true`).

#### Scenario: External link opens in new tab

- GIVEN content `{actionType: 'external', url: 'https://example.com', label: 'Docs'}`
- WHEN the user clicks the button (not in edit mode)
- THEN the system MUST call `window.open('https://example.com', '_blank', 'noopener,noreferrer')`

#### Scenario: Click in edit mode is suppressed

- GIVEN the same widget but the surrounding shell has `canEdit === true` and the widget receives `isAdmin: true`
- WHEN the user clicks the button
- THEN no `window.open` MUST fire
- AND no API call MUST fire
- AND no modal MUST open

#### Scenario: Disabled while action is in flight

- GIVEN a `createFile` action is in progress (POST `/api/files/create` not yet resolved)
- WHEN the user clicks the button again
- THEN the button MUST be `disabled` in the DOM
- AND no second request MUST fire

### Requirement: REQ-LBN-002 Icon resolution

The `icon` field of a link-button widget MUST follow the same dual-mode convention as `dashboard-icons` (REQ-ICON-005..007):

- A custom URL (starts with `/` or `http`) MUST render as `<img>` inside the button
- A bare name MUST render via the shared `IconRenderer` (built-in MDI component)
- An empty or null value MUST render no icon (label-only)

Icon size MUST be 48 px square; the label MUST be vertically stacked below the icon.

#### Scenario: Custom URL icon

- GIVEN content `{icon: '/apps/mydash/resource/x.png', label: 'Open'}`
- WHEN the widget renders
- THEN the button MUST contain `<img src="/apps/mydash/resource/x.png">` 48 px tall
- AND the label `Open` MUST appear below the image

#### Scenario: No icon

- GIVEN `{icon: '', label: 'Click me'}`
- WHEN the widget renders
- THEN no `<img>` or `<svg>` icon MUST appear
- AND only the label MUST be visible

### Requirement: REQ-LBN-003 createFile flow

When `actionType === 'createFile'`, click MUST open an inline secondary modal containing:

- Read-only display of the extension (`.docx` etc.) derived from `url`
- Editable filename input prefilled with `document_<unix-timestamp>`
- Cancel and Create buttons (Create disabled when filename empty)

On Create, the system MUST POST `/api/files/create` with body `{filename: <name>.<ext>, dir: '/', content: ''}`. On HTTP 200, the response's `url` MUST be opened in a new tab via `window.open(url, '_blank')`. On error, a translated toast MUST display `t('Failed to create document')`. The modal MUST close on Cancel or after a successful create.

#### Scenario: Document modal opens with prefilled name

- GIVEN content `{actionType: 'createFile', url: 'docx', label: 'New report'}`
- WHEN the user clicks the button
- THEN the modal MUST appear with `.docx` displayed and filename `document_<timestamp>` prefilled
- AND the Create button MUST be enabled

#### Scenario: Create posts and opens result

- GIVEN the modal is open and the user types `Q4-report` and clicks Create
- WHEN the form submits
- THEN the system MUST POST `/api/files/create` with body `{filename: 'Q4-report.docx', dir: '/', content: ''}`
- AND on 200 with response `{url: 'https://nc/index.php/apps/files/?openfile=42'}` it MUST `window.open(url, '_blank')`
- AND the modal MUST close

#### Scenario: Empty filename disables Create

- GIVEN the user clears the filename input
- WHEN the modal renders
- THEN the Create button MUST be `disabled`

### Requirement: REQ-LBN-004 Server-side file-creation endpoint

The system MUST expose `POST /api/files/create` accepting `{filename: string, dir: string = '/', content: string = ''}`. The endpoint MUST:

1. Validate filename: non-empty, ≤255 chars, no `..`, no `/`, no `\`, no null byte, must match `^[a-zA-Z0-9_\-. ]+$`. Otherwise return HTTP 400 `{error: 'Invalid filename'}`.
2. Validate dir: no `..`, no null byte. Otherwise return HTTP 400.
3. Validate extension: must be in the admin-configured allow-list (default: `txt, md, docx, xlsx, csv, odt`). Otherwise return HTTP 400 `{error: 'File type not allowed'}`.
4. Resolve user folder via `IRootFolder::getUserFolder($userId)` and create the subdirectory if missing.
5. If a file with the same name already exists at the target path, OVERWRITE its content.
6. Return `{status: 'success', fileId: int, url: string}` where `url` opens the Files app at `openfile={fileId}` via `URLGenerator::linkToRouteAbsolute('files.view.index', ['openfile' => fileId])`.

Internal exceptions MUST be wrapped; raw exception messages MUST NOT be returned to the caller.

#### Scenario: Path traversal rejected

- GIVEN body `{filename: '../../etc/passwd'}`
- WHEN POSTed
- THEN the system MUST return HTTP 400 with error `Invalid filename`
- AND no file MUST be created on disk

#### Scenario: Disallowed extension rejected

- GIVEN allow-list `[txt, md, docx]` AND body `{filename: 'foo.exe'}`
- WHEN POSTed
- THEN the system MUST return HTTP 400 with `{error: 'File type not allowed'}`

#### Scenario: Existing file overwritten

- GIVEN a file `report.docx` already exists at `/`
- WHEN body `{filename: 'report.docx', content: ''}` is POSTed
- THEN the existing file's content MUST be replaced with empty content
- AND the response MUST return its `fileId` and a Files-app open URL
- NOTE: This is a deliberate convenience for "create from button" workflows; UI must warn the user when overwriting.

### Requirement: REQ-LBN-005 Internal action registry

The system MUST expose a frontend composable `useInternalActions()` returning a singleton `Map<actionId, () => void | Promise<void>>` plus three methods: `register(id, fn)`, `invoke(id)`, and `has(id)`. Other frontend modules MAY register actions at any time. Click on an `internal` link button MUST look up `url` (the action ID) in the map and invoke the registered function. Missing IDs MUST log `console.warn('Unknown internal action: <id>')` but MUST NOT throw.

#### Scenario: Register and invoke an internal action

- GIVEN a module registered `useInternalActions().register('open-talk', () => router.push('/talk'))`
- AND content `{actionType: 'internal', url: 'open-talk'}`
- WHEN the user clicks the button
- THEN the system MUST invoke the registered function exactly once

#### Scenario: Unknown action ID warns but does not crash

- GIVEN content `{actionType: 'internal', url: 'does-not-exist'}`
- WHEN the user clicks the button
- THEN the system MUST log `console.warn('Unknown internal action: does-not-exist')`
- AND no error MUST propagate to break the page

### Requirement: REQ-LBN-006 Add/edit form

The link sub-form for `AddWidgetModal` MUST expose six fields:

| Field | Control | Required |
|---|---|---|
| `label` | text input | yes |
| `actionType` | select with options external/internal/createFile | yes |
| `url` | text input; placeholder switches by actionType (`https://...`, `action-id`, `docx`) | yes |
| `icon` | IconPicker (built-in dropdown + upload) | no |
| `backgroundColor` | colour picker | no |
| `textColor` | colour picker | no |

Validation: `validate()` MUST require `label` AND `url` non-empty and return a non-empty error array otherwise. The form MUST pre-fill from `editingWidget.content` when editing an existing widget.

#### Scenario: Validation requires both label and url

- GIVEN the user has filled `label = 'X'` but left `url` empty
- WHEN the form runs `validate()`
- THEN it MUST return a non-empty error array
- AND the modal Add button MUST be disabled

#### Scenario: Placeholder swaps with actionType

- GIVEN the user selects `actionType = 'createFile'`
- WHEN the form re-renders
- THEN the `url` input placeholder MUST read `docx` (or similar extension hint)
- AND when the user switches back to `external`, the placeholder MUST read `https://...`

### Requirement: REQ-LBN-007 Default styling

When the colour fields are empty, the renderer MUST default to `backgroundColor: var(--color-primary)` and `textColor: var(--color-primary-text)` (Nextcloud theme primary). Hover MUST translate the button up by 2 px and add a soft drop shadow.

#### Scenario: Theme defaults

- GIVEN content `{label: 'X', url: 'y', actionType: 'external', backgroundColor: '', textColor: ''}`
- WHEN the widget renders
- THEN the button's CSS background MUST equal `var(--color-primary)`
- AND the text colour MUST equal `var(--color-primary-text)`

#### Scenario: Hover lift effect

- GIVEN the rendered button is on screen
- WHEN the user hovers the pointer over it
- THEN the button MUST translate up by 2 px
- AND a soft drop shadow MUST be applied
