# Design — Link-Button Widget

## Context

MyDash today lacks a first-class action button. Users cannot drop a styled clickable tile onto a dashboard to open an external URL in a new tab, invoke a registered in-app workflow, or create a fresh document in their Files app. An earlier prototype shipped an ad-hoc "internal action" placeholder with auto-detect-from-extension semantics (a `.docx` URL meant "create file" but a `.html` URL meant "open external"), which proved fragile and error-prone. This design formalises a typed `link` widget with three explicit, discriminated action types (`external`, `internal`, `createFile`), a runtime-mutable singleton registry of named internal actions, and a strictly-validated server endpoint for file creation with an admin-configurable extension allow-list.

Additionally, this change establishes the foundational `link` widget type and `useInternalActions()` registry as the anchor for a future "tile-based action menu" experience — other capabilities will build on top by registering actions in the shared registry and extending the widget's rendering modes (button vs. list).

## Goals / Non-Goals

**Goals:**

- Provide a reusable, typed action button widget that dispatches three explicit action types without ambiguity.
- Establish a singleton frontend registry (`useInternalActions()`) where other capabilities can register named internal actions, enabling loose coupling between the widget and action implementations.
- Implement a strictly-validated, admin-controlled file-creation endpoint (`POST /api/files/create`) that rejects path traversal, invalid filenames, and disallowed extensions.
- Suppress all click handlers when in admin/edit mode so dashboard configuration is safe and intention-preserving.
- Support custom icons (uploaded URLs) and built-in MDI icons via a dual-mode resolver, matching the pattern established by dashboard-icons.
- Provide a clean edit form with dynamic placeholder text that reflects the selected action type, easing the author's mental model.

**Non-Goals:**

- List-mode rendering of multiple links in a single widget (deferred to a follow-up `link-button-widget-list-mode` change).
- Automated action discovery or reflection — actions are explicitly registered; there is no scan-for-matching-methods pattern.
- Workflow engine integration — internal actions are simple synchronous functions; async workflows are out of scope.
- Icon upload/asset management — icons are either MDI names or references to existing URLs; no new asset service.
- Client-side file preview or validation — the server validates filenames and extensions; client side only enforces shape.

## Decisions

### D1: Typed discriminated union for `actionType`, not auto-detect from URL extension

**Decision**: The widget content includes an explicit `actionType` enum field with three values: `external`, `internal`, `createFile`. Click dispatch is strictly based on this field. The `url` field semantics depend on `actionType`, but the URL string itself never determines the action type.

**Alternatives considered:**

- Auto-detect from `url` extension (e.g., `.docx` → `createFile`, `.html` → `external`, `#action-name` → `internal`). Rejected — fragile and error-prone; it conflates two concerns (intent and data) and leads to silent-failure bugs when the URL format is ambiguous.
- Single action type with conditional fallbacks (e.g., "try internal, fall back to external"). Rejected — hides intent and makes debugging harder.

**Rationale**: Explicit discriminated unions are the standard FP pattern for encoding intent. The author's intent is always clear, and the renderer dispatches deterministically. Future action types can be added to the enum without breaking existing content.

### D2: Singleton module-level `Map` for the internal action registry, not per-component

**Decision**: `useInternalActions()` returns a singleton reference to a module-level `Map<actionId, fn>`. The composable is called multiple times (once per link-button widget on the page), but all calls see the same registry. Actions are registered once at app bootstrap and persist for the session lifetime.

**Alternatives considered:**

- Per-component registries (each widget instance maintains its own registry). Rejected — actions are app-wide concerns, not widget-local, and per-widget registries would require painful duplication and discovery.
- Vuex/Pinia store. Rejected — overkill for a simple `Map`; the module-level singleton is more lightweight and aligns with the composable pattern.

**Rationale**: A single shared registry allows actions to be registered by any capability once at bootstrap, and any widget can invoke them. The singleton pattern is simple, debuggable (you can `console.log(useInternalActions().map)` in the browser), and matches the pattern used by existing registries in the codebase.

### D3: Server-side extension allow-list stored in `mydash_admin_settings`, not schema-driven

**Decision**: The admin-configurable extension allow-list is stored as a JSON array in the existing `mydash_admin_settings` table under key `link_create_file_extensions`. The default is `["txt","md","docx","xlsx","csv","odt"]`. It is read at request time from `AdminSettingsService` (or equivalent), not embedded in the schema.

**Alternatives considered:**

- Hardcoded whitelist in code (e.g., a constant). Rejected — inflexible for deployments with different security policies (some may allow only `txt`, others may allow `pdf`).
- OpenRegister schema for allowed extensions. Rejected — this is admin config, not domain data, and shouldn't go in OpenRegister per ADR-001.
- Schema migration to add an `allowed_extensions` column. Rejected — unnecessary table changes when the existing JSON config table works.

**Rationale**: Admin settings are the idiomatic place for operational config. The JSON array is human-readable and can be edited via the admin settings form without DB schema changes. The default set covers common office/document types and is conservative (no executables, archives, or scripts).

### D4: Overwrite existing files silently, not error or rename

**Decision**: When `POST /api/files/create` is called with a filename that already exists at the target path, the endpoint silently overwrites the existing file's content. The response includes the existing file's ID and URL.

**Alternatives considered:**

- Error with HTTP 409 Conflict and ask the user to choose a different name. Rejected — creates extra UX friction for a convenience feature; the widget form can warn the user before submitting.
- Auto-rename (e.g., `report_1.docx`, `report_2.docx`). Rejected — unpredictable results and harder to test.

**Rationale**: The "create from button" workflow is intended to be a quick action. If the user triggers it twice, they likely want the same file updated, not a duplicate. The frontend form can (and should) warn before overwriting, giving the user agency.

### D5: Modal for file-creation prompt, not inline form in the button content

**Decision**: When the user clicks a `createFile` action button, an overlay modal appears containing: the file extension (read-only), a filename input (prefilled with `document_<unix-timestamp>`), and Cancel/Create buttons. The modal is a child component of the renderer, mounted conditionally when the action is triggered.

**Alternatives considered:**

- Inline form that expands within the button/widget space. Rejected — clutters the dashboard and breaks the button's visual affordance.
- Page-level form (navigate to a separate "Create file" page). Rejected — heavyweight for a quick action; users expect a modal popup.
- No prompt; just create with a server-generated name. Rejected — removes user agency; the feature feels like magic, not control.

**Rationale**: Modals are the Nextcloud convention for immediate secondary actions. The prefilled name (with timestamp) gives the user a sensible starting point and reduces typing. The read-only extension display prevents accidental mismatches.

### D6: Inline IconRenderer component, not separate icon-selection UI

**Decision**: The renderer resolves the `icon` field on every render and outputs either `<img src="...">` (for custom URLs) or an `<IconRenderer name="...">` component (for MDI names), or nothing (if `icon` is empty). There is no separate icon-picker component; the form uses the existing `IconPicker` from `@conduction/nextcloud-vue`.

**Alternatives considered:**

- Custom icon upload UI in the form that stores uploaded icons in a separate table. Rejected — overengineered; the form can reference existing apps' icons (e.g., `/apps/mydash/resources/icon.png`) without asset management.
- Icon preview in the form. Rejected — adds complexity; the icon will be previewed in the rendered widget.

**Rationale**: Dual-mode icon resolution (custom URL or MDI name) is already established by the dashboard-icons widget (REQ-ICON-005..007). Reusing the pattern and the existing `IconPicker` component keeps the codebase DRY and reduces new code.

### D7: CSS variable defaults for empty colour fields, not hardcoded fallbacks

**Decision**: When `backgroundColor` or `textColor` is an empty string, the renderer outputs `var(--color-primary)` and `var(--color-primary-text)` respectively. These are CSS variables (no computed fallback in JS); the browser resolves them at render time based on the active theme.

**Alternatives considered:**

- Compute fallbacks in JavaScript (e.g., `backgroundColor || getComputedStyle(doc.root)['--color-primary']`). Rejected — fragile and requires DOM reads; CSS handles theming natively.
- Default to a hardcoded colour (e.g., `#0066cc`). Rejected — breaks theme switching and accessibility (doesn't adapt to light/dark mode).

**Rationale**: CSS variables are the idiomatic way to handle theming in Nextcloud apps per ADR-004. The renderer is lightweight and the theme is automatically applied when the user switches themes.

### D8: No schema migration; reuse existing `oc_mydash_widget_placements.styleConfig` column

**Decision**: Link-button widget content is stored in the existing `oc_mydash_widget_placements.styleConfig` JSON column with a discriminated shape `{type: 'link', content: {...}}`. No new table column or migration is required.

**Alternatives considered:**

- Add a new `link_button_config` table. Rejected — unnecessary; the existing JSON column is flexible enough and avoids migration friction.
- Add a dedicated column to the placements table. Rejected — same reason as above.

**Rationale**: The existing `styleConfig` column was designed for widget-specific config. Reusing it avoids migration overhead and keeps the schema stable. The `type: 'link'` discriminator ensures backwards compatibility with other widget types.

### D9: Filename validation with strict regex, server-side only

**Decision**: The `POST /api/files/create` endpoint validates filenames with the regex `^[a-zA-Z0-9_\-. ]+$` (no special chars, no path separators, no dots for traversal), a max length of 255 chars, and rejects `..`, `/`, `\`, and null bytes. No file extension validation happens on the client; the server validates the extension after stripping it from the filename. Client-side validation is minimal (non-empty check only).

**Alternatives considered:**

- Permissive client-side validation (allow any `filename.ext` the user types). Rejected — too trusting; the server must revalidate anyway.
- Complex regex on the client to match the server. Rejected — redundant and prone to drift; let the server be the source of truth.

**Rationale**: Server-side validation is the security boundary. The client-side empty-check improves UX (disable Create button when empty) but doesn't replace server validation. The regex is strict (only common characters) and conservative, reducing the attack surface for path traversal and injection.

### D10: No auth gating; file creation available to any authenticated user

**Decision**: The `POST /api/files/create` endpoint requires the user to be authenticated (CSRF token + session) but does not check additional permissions (e.g., "can create files" role). Any authenticated user can create files in their own home directory (`/`). The admin-configured extension allow-list is the only permission boundary.

**Alternatives considered:**

- Add a `can-create-files` permission or role check. Rejected — overengineered for MVP; the admin controls the extension allow-list, which is the effective gate. If needed later, add permission checks in a follow-up change.
- Restrict to admin-only. Rejected — defeats the purpose of a user-facing dashboard widget.

**Rationale**: The Files app already allows any authenticated user to create files. This endpoint is a convenience wrapper with additional safety (filename validation, extension allow-list). Adding a new permission would require schema changes and administrative setup; the allow-list provides sufficient control for this release.

## Risks / Trade-offs

- **Risk**: Overwriting existing files without prompting could lead to data loss if the user fat-fingers the filename. → **Mitigation**: The form should warn "This will overwrite [filename]" before submitting, educating the user. The server silently overwrites (per D4), but the UI prevents accidental submits.

- **Risk**: The `Map` singleton for internal actions is untyped in JavaScript; a buggy action registration could break other actions. → **Mitigation**: Actions are registered at bootstrap by trusted first-party code; we do not accept user-defined actions. The registry is checked at runtime and missing actions log warnings (no crashes).

- **Risk**: The strict filename regex (`^[a-zA-Z0-9_\-. ]+$`) may be too restrictive for some locales (e.g., filenames with accented characters). → **Mitigation**: File systems (NTFS, ext4) support UTF-8; the regex is conservative for MVP. A follow-up change can relax the regex to `[^\x00\/\\]` (disallow only null, slash, backslash) if needed. Document the current limitation in the changelog.

- **Risk**: Admin settings are stored in the database; if an admin misconfigures the allow-list (e.g., adds `exe`), security is compromised. → **Mitigation**: The settings form should include a warning and a pre-filled default list. Code review and audits catch misconfiguration.

- **Trade-off**: The registry uses a simple `Map` and no persistence; actions registered during the session are lost on page reload. → **Mitigation**: Accepted for MVP. Actions are registered at app bootstrap from known locations (not user input). If session-persistent registration is needed later, store the registry in `localStorage` or as a Pinia store.

## Migration Plan

1. **Backend: FileService + Controller (Tasks 1–3)**
   - Add `FileService::createFile()` with strict validation.
   - Add `FileController::createFile()` and register the route.
   - AdminSettings exposes the extension allow-list config field.
   - PHPUnit tests validate filename validation, extension checks, overwrite semantics, no exception leakage.

2. **Frontend: Composable + Renderer (Tasks 4–8)**
   - Add `useInternalActions.js` composable and export the singleton registry.
   - Build `LinkButtonWidget.vue` renderer with three click branches, admin-mode suppression, and icon resolution.
   - Mount inline `CreateFileModal.vue` as a child component.
   - Implement `LinkButtonForm.vue` with six fields and dynamic placeholder.
   - Register `link` in `widgetRegistry.js`.
   - Vitest tests for three click branches, admin-mode suppression, in-flight disabling, registry warn-on-miss, form validation.

3. **Integration (Tasks 9–11)**
   - PHPUnit + Vitest pass.
   - Playwright end-to-end test: `createFile` flow (modal → POST → Files tab opens).
   - Playwright test: `external` link opens in `_blank`.

4. **Quality & Translations (Task 12)**
   - `composer check:strict` and ESLint pass.
   - OpenAPI spec updated for `POST /api/files/create`.
   - `en.json` + `nl.json` translations for all UI strings (13 keys).

5. **Rollback**: Pure frontend + new backend endpoint. Remove the route, drop the FileService methods, remove frontend components. No data migration; existing dashboard placements are unaffected.

## Open Questions

- **Q1: Should the file-creation modal support custom directory selection, or always create in `/` (user home)?**
  - Current decision: Always `/` (user home). Simplifies the UX and matches the "quick action" intent. Revisit if users request nested directory creation.

- **Q2: Should the internal action registry warn or error on duplicate registration (e.g., two actions with the same ID)?**
  - Current decision: Log a warning and overwrite the previous registration. Simplifies debugging and allows hot-reload/re-registration in dev. Revisit if production use cases require strict unique IDs.

- **Q3: Should `createFile` actions support a custom default filename, or always use `document_<timestamp>`?**
  - Current decision: Fixed `document_<timestamp>` default. The user can edit it in the modal. If use cases arise (e.g., "New Report" should default to `Report_<timestamp>`), extend the widget content shape in a follow-up change.

- **Q4: Should the admin allow-list be case-sensitive (e.g., `.Docx` rejected but `.docx` allowed)?**
  - Current decision: Case-insensitive comparison. Most file systems are case-insensitive on Windows and case-preserving on Unix; case-insensitive comparison is safer. The server stores `.docx` and `*.docx`, `*.DOCX`, `*.Docx` all match.

- **Q5: Should deleted actions (unregistered IDs) trigger an error toast, or silently no-op with a console warning?**
  - Current decision: Silent no-op + `console.warn()`. Prevents noise if an action is deregistered between page load and click. Revisit if metrics show users are confused by silent no-ops.
