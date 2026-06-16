# Spec: mydash-file-access-widget

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, widget-add-edit-modal, files-widget (peer pattern), permissions, conditional-visibility; cross-app runtime sources: Nextcloud Files OCS API + WebDAV, OR object-interactions (for dossier-attached documents)

## Purpose

Surface dossier documents (and arbitrary Nextcloud Files objects)
on a mydash dashboard via a single widget
(`mydash_file_access`). The widget is a **quick-access surface**
distinct from the existing `files-widget` (which embeds a folder
browser): this widget renders a curated short-list of files the
viewer needs from the dashboard — typically the documents attached
to a dossier object via OR's `object-interactions` integration
(ADR-019 / ADR-022).

mydash MUST NOT store file bytes. All file data flows live from
Nextcloud Files (OCS / WebDAV) or from OR's
`object-interactions` files endpoint — the viewer's existing
Nextcloud ACL enforces visibility.

Sourced from Specter draft `dashboard-file-access` (1 feature: open
dossier document from dashboard).

## ADDED Requirements

### REQ-FAW-001: The system SHALL register a `mydash_file_access` widget type alongside the existing `files-widget`

The widget MUST appear in `src/constants/widgetRegistry.js` as a
distinct type (`file-access`) from the existing `files` type — the
two coexist (browser vs quick-access). The registry entry MUST carry
the standard fields and a soft `requires.nextcloud:
['files']` declaration. `files` is a Nextcloud-core app so this is
always satisfied in practice; the declaration is for completeness.

#### Scenario: Widget registered without colliding with `files-widget`

- **GIVEN** the registry completeness test (REQ-WDG-023)
- **WHEN** it runs
- **THEN** both `files` and `file-access` MUST appear in
  EXPECTED_TYPES
- **AND** their registry entries MUST point at distinct
  `renderer` + `form` components

#### Scenario: Picker disambiguates the two types

- **GIVEN** the Add Widget modal type picker
- **WHEN** rendered
- **THEN** both types MUST list with distinct `displayName`
  values (e.g. `'Files browser'` vs `'Quick file access'`)

### REQ-FAW-002: The widget content shape SHALL describe the source binding mode

The placement persists `{type: 'file-access', content: {...}}` with:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `sourceMode` | enum | Yes | `'fileIds'` | `'fileIds' \| 'orObject' \| 'recent'` — three binding modes |
| `fileIds` | integer[] | When `sourceMode === 'fileIds'` | `[]` | Explicit list of Nextcloud file IDs |
| `orObjectRef` | object | When `sourceMode === 'orObject'` | `null` | `{register, schema, objectId}` — surfaces files attached to that OR object via `object-interactions` |
| `recentLimit` | integer | When `sourceMode === 'recent'` | `5` | Most-recently-modified files the viewer can access |
| `displayMode` | enum | No | `'list'` | `'list' \| 'grid'` — same conventions as `files-widget` |
| `showThumbnails` | boolean | No | `true` | Thumbnail rendering (delegates to Nextcloud preview endpoint) |

#### Scenario: Minimal `orObject` placement validates

- **GIVEN** the content shape contract
- **WHEN** `{type: 'file-access', content: {sourceMode: 'orObject', orObjectRef: {register: 'procest', schema: 'Case', objectId: 'case-1'}}}` is saved
- **THEN** validation MUST pass

#### Scenario: Cross-mode field is rejected

- **GIVEN** `sourceMode === 'recent'` AND `fileIds = [42]`
- **WHEN** `validate()` runs
- **THEN** validation MUST return an error noting that `fileIds`
  is only relevant when `sourceMode === 'fileIds'`

### REQ-FAW-003: The widget SHALL consume files via Nextcloud Files OCS / WebDAV — never via a mydash-local file table

The renderer MUST issue requests to:

- `GET /ocs/v2.php/apps/files/api/v1/files` — file metadata by id
- `PROPFIND` on `/remote.php/dav/files/{user}/{path}` — file
  listings for `recent` and `orObject` modes
- `/index.php/core/preview?fileId=...` — thumbnails

mydash MUST NOT define a local file metadata table, MUST NOT cache
file bytes, and MUST NOT issue any write call to Files (no upload,
no delete) — this widget is read-only quick-access. (Upload /
delete remains the responsibility of the existing `files-widget`
where the placement-level toggles already exist per REQ-FLS-001.)

#### Scenario: Metadata fetch routes through OCS

- **GIVEN** `sourceMode === 'fileIds'` AND `fileIds = [123, 456]`
- **WHEN** the widget mounts
- **THEN** the renderer MUST issue OCS metadata requests for both
  file IDs

#### Scenario: No mydash file table

- **GIVEN** the mydash migration files after this widget ships
- **WHEN** inspected
- **THEN** no migration introducing a file metadata table MUST
  exist

#### Scenario: No write call to Files

- **GIVEN** the file-access widget source files
- **WHEN** scanned for HTTP method `POST` / `PUT` / `DELETE` to
  `/dav/files/` or `/apps/files/api`
- **THEN** zero matches MUST exist

### REQ-FAW-004: When `sourceMode === 'orObject'`, the file list SHALL be sourced via OR's `object-interactions` files integration

The renderer MUST issue a GraphQL query against OR's `/graphql`
resolving the object's attached files via the
`object-interactions` integration (per ADR-019 + ADR-022). The
returned file list carries Nextcloud file IDs that flow into the
same OCS metadata fetch as REQ-FAW-003.

#### Scenario: Dossier files surface (Specter source)

- **GIVEN** an OR object `{register: 'procest', schema: 'Case', objectId: 'case-1'}` with 3 attached documents
- **WHEN** the widget renders
- **THEN** the widget MUST list those 3 documents with their titles
  and last-modified dates

#### Scenario: Click opens the document viewer (Specter source)

- **GIVEN** a clickable document on the dashboard
- **WHEN** the viewer clicks it
- **THEN** the document MUST open in the Nextcloud document viewer
  (per Files convention — `?openfile=...` parameter on the Files
  app)

### REQ-FAW-005: When the viewer lacks access to a referenced file, the widget SHALL render an access-denied row — not surface the file metadata (Specter source)

When a file ID in `fileIds`, an `orObject`-derived file, or a
`recent` result is not readable by the viewer (Nextcloud ACL
denies), the row MUST render with a lock icon + the translated
text `t('mydash', 'No access')`, MUST NOT surface the file's title
or thumbnail, and MUST NOT allow click-through. The widget MUST
NOT throw — other rows MUST keep rendering.

#### Scenario: Access denied row (Specter source)

- **GIVEN** a placement with `fileIds = [999]` AND the viewer
  has no read access to file 999
- **WHEN** the widget renders
- **THEN** the row for file 999 MUST display the lock icon and
  `t('mydash', 'No access')`
- **AND** the title MUST NOT surface
- **AND** clicking the row MUST be a no-op

#### Scenario: Partial denial keeps other rows

- **GIVEN** `fileIds = [123, 999, 456]` where 999 is denied
- **WHEN** the widget renders
- **THEN** rows for 123 and 456 MUST render normally
- **AND** the 999 row MUST render in the denied state

#### Scenario: Deleted file shows missing state

- **GIVEN** a file ID that no longer exists in Nextcloud
- **WHEN** the widget renders
- **THEN** the row MUST display `t('mydash', 'File not found')`
- **AND** the click target MUST be inert
- **AND** the widget MUST NOT auto-prune the placement (consistent
  with `image-widget` REQ-IMP-006)

## Non-Functional Requirements

- **Performance:** OCS metadata fetches SHOULD be batched (single
  call per render where possible). Thumbnails MUST load lazily.
- **Accessibility:** Lock icons MUST have `aria-label` describing
  the denied access state. Recent-files lists MUST be keyboard
  navigable.
- **Localisation:** All labels in Dutch + English.
- **SVG handling:** SVG files MUST follow the same hand-off to
  `svg-sanitisation` as `image-widget` REQ-IMP-008 (the file-access
  widget defers to the existing sanitisation capability for SVG
  preview rendering).

## Reuses (mydash)

- `widgets`, `widget-add-edit-modal`, `widget-collision-placement`
- `files-widget` — peer widget, shares Nextcloud Files plumbing
  conventions but renders a different UX (browser vs quick-access)
- `svg-sanitisation` — for SVG previews
- `permissions`, `conditional-visibility`

## Standards & References

- ADR-022 — OR `object-interactions` for dossier-attached files,
  deep-link registry for document-viewer hand-off.
- ADR-019 — integration registry pattern (object-interactions is
  the canonical file integration).
- Nextcloud OCS Files API + WebDAV — the authoritative file APIs.
- `feedback_mydash-no-or-dependency.md`.
- WCAG 2.1 AA — keyboard navigation + access-denied a11y.
