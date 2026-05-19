---
capability: dashboard-versioning
status: draft
---

# Dashboard Versioning Specification

## Purpose

Enable version history and one-click restoration for MyDash dashboards. The feature delegates storage strategy to the underlying content backend: dashboards stored in Nextcloud Files (via the `groupfolder` backend) use NC's native file versioning; dashboards in the database backend use a dedicated `oc_mydash_dashboard_versions` table. All APIs are backend-agnostic.

## Data Model

### Database Backend (Default)

Each dashboard version snapshot is stored in `oc_mydash_dashboard_versions` with:
- **id**: Auto-increment integer primary key
- **dashboardUuid**: UUID of the parent dashboard
- **versionNumber**: INT (auto-incremented per-dashboard, monotonic, starting at 1)
- **snapshotJson**: MEDIUMTEXT containing the full widget tree and metadata at that point
- **createdBy**: VARCHAR(64) Nextcloud user ID of the version creator
- **createdAt**: TIMESTAMP of snapshot creation
- **note**: TEXT NULL for optional version label/annotation
- Composite unique index on `(dashboardUuid, versionNumber)` for fast per-dashboard lookups
- Retention: automatically prune oldest versions beyond the 50-most-recent per dashboard

### GroupFolder Backend

Versions are stored via `\OCP\IVersionManager` on the dashboard's JSON file in Nextcloud Files. MyDash does NOT manage retention — Nextcloud's own versioning policies apply. If versioning is unavailable, the API gracefully returns `{versions: [], modeSupported: false}` with HTTP 200.

## ADDED Requirements

### Requirement: REQ-VERS-001 Snapshot Creation on Content Update

The system MUST automatically capture a snapshot whenever a dashboard's content is successfully saved via PUT. Snapshots MUST be debounced — at most one snapshot per 60-second window per dashboard.

#### Scenario: Automatic snapshot on PUT

- GIVEN a dashboard "My Dashboard" with current content
- WHEN a user sends PUT /api/dashboard/{uuid}/content with new widget positions
- THEN the system MUST create a version snapshot in the appropriate backend (file-version for groupfolder, DB row for db mode) after the content is persisted
- AND the snapshot MUST include the full widget tree and metadata as it was before the update

#### Scenario: Debouncing rapid edits

- GIVEN a dashboard and a user rapidly sends 5 PUT requests within 30 seconds
- WHEN the user saves each change
- THEN the system MUST create at most 1 snapshot for the entire burst (the first one at t=0, others ignored or batched until 60s passes)
- AND subsequent updates after the 60-second window MUST create a new snapshot

#### Scenario: Failed PUT does not create snapshot

- GIVEN a PUT request that fails validation or encounters a database error
- WHEN the request returns HTTP 400 or HTTP 500
- THEN no version snapshot MUST be recorded
- AND the dashboard content MUST remain unchanged

#### Scenario: Snapshot captures full state

- GIVEN a dashboard with 4 widgets at specific grid positions
- WHEN a PUT updates widget positions and adds metadata
- THEN the snapshot MUST include all 4 widgets and the new metadata exactly as persisted
- AND the snapshot MUST be complete and restorable to the exact prior state

### Requirement: REQ-VERS-002 Explicit Snapshot Creation

The system MUST expose POST /api/dashboards/{uuid}/versions to allow users to explicitly create labelled snapshots with an optional note, independent of automatic debouncing.

#### Scenario: Create a named snapshot

- GIVEN a dashboard "My Dashboard" and a logged-in user "alice"
- WHEN alice sends POST /api/dashboards/{uuid}/versions with body `{"note": "Before major redesign"}`
- THEN the system MUST create a new version snapshot with the provided note
- AND the snapshot MUST be immediately queryable via GET /api/dashboards/{uuid}/versions
- AND the response MUST return HTTP 201 with the new version object including versionNumber

#### Scenario: Explicit snapshot bypasses debounce

- GIVEN a dashboard with an automatic snapshot captured at t=10s
- WHEN the user sends POST /api/dashboards/{uuid}/versions with a note at t=30s (within the 60s debounce window)
- THEN the system MUST create the explicit snapshot immediately (debounce does NOT apply)
- AND the response MUST return HTTP 201

#### Scenario: Empty note is valid

- GIVEN a user creates an explicit snapshot
- WHEN the request body contains `{"note": ""}` or omits note entirely
- THEN the snapshot MUST be created with `note = NULL`
- AND the API MUST return HTTP 201

#### Scenario: Only owners and admins can create

- GIVEN dashboard "My Dashboard" owned by "alice"
- WHEN user "bob" (non-owner, non-admin) sends POST /api/dashboards/{uuid}/versions
- THEN the system MUST return HTTP 403
- AND no snapshot MUST be created

### Requirement: REQ-VERS-003 List Versions

The system MUST expose GET /api/dashboards/{uuid}/versions returning an ordered list of available versions, newest first, with metadata but NOT full snapshot content.

#### Scenario: List versions for a dashboard with history

- GIVEN a dashboard with 5 version snapshots
- WHEN a user sends GET /api/dashboards/{uuid}/versions
- THEN the response MUST return HTTP 200 with an array of 5 version objects
- AND each object MUST include: versionNumber, createdBy, createdAt, note (may be null), sizeBytes
- AND the array MUST be ordered newest-first (highest versionNumber first)

#### Scenario: List versions backend-agnostic

- GIVEN a dashboard stored in groupfolder backend
- WHEN a user calls GET /api/dashboards/{uuid}/versions
- THEN the system MUST resolve to `IVersionManager` file-versions and map each file-version to a compatible response object
- AND the response format MUST be identical to database backend (versionNumber synthesized from file-version timestamp, createdBy from NC user ID if available, etc.)

#### Scenario: List versions for a dashboard with no snapshots

- GIVEN a dashboard that exists but has no version snapshots
- WHEN a user sends GET /api/dashboards/{uuid}/versions
- THEN the response MUST return HTTP 200 with an empty array `[]`
- AND NOT HTTP 404 (the dashboard exists, it just has no history yet)

#### Scenario: Soft-failure for unavailable versioning

- GIVEN a dashboard on groupfolder backend and NC versioning is disabled or unavailable
- WHEN a user sends GET /api/dashboards/{uuid}/versions
- THEN the response MUST return HTTP 200 with body `{versions: [], modeSupported: false}`
- AND MUST NOT return HTTP 500 or raise an error
- AND the UI SHOULD show "version history disabled" to the user

#### Scenario: Only owners and admins can list

- GIVEN dashboard "My Dashboard" owned by "alice"
- WHEN user "bob" (non-owner, non-admin) sends GET /api/dashboards/{uuid}/versions
- THEN the system MUST return HTTP 403
- AND no version list MUST be disclosed

### Requirement: REQ-VERS-004 Fetch Version Snapshot

The system MUST expose GET /api/dashboards/{uuid}/versions/{versionNumber} returning the full snapshot content at that version.

#### Scenario: Fetch a specific version snapshot

- GIVEN a dashboard with version 3 containing a snapshot
- WHEN a user sends GET /api/dashboards/{uuid}/versions/3
- THEN the response MUST return HTTP 200 with the full snapshotJson body (widget tree, metadata, etc.) exactly as stored
- AND the response MUST be identical to the current dashboard content if version 3 is the latest

#### Scenario: Fetch non-existent version

- GIVEN a dashboard with 5 versions (versionNumber 1..5)
- WHEN a user sends GET /api/dashboards/{uuid}/versions/99
- THEN the system MUST return HTTP 404
- AND the response MUST indicate "version not found"

#### Scenario: Only owners and admins can fetch

- GIVEN dashboard owned by "alice"
- WHEN user "bob" sends GET /api/dashboards/{uuid}/versions/3
- THEN the system MUST return HTTP 403

### Requirement: REQ-VERS-005 Restore Version

The system MUST expose POST /api/dashboards/{uuid}/versions/{versionNumber}/restore to replace the current dashboard content with a historical snapshot. The restore operation is itself reversible — a pre-restore snapshot is automatically created.

#### Scenario: Restore a previous version

- GIVEN a dashboard with 5 versions, current content at version 5
- WHEN a user sends POST /api/dashboards/{uuid}/versions/3/restore
- THEN the system MUST:
  1. Capture the current state (version 5 content) as a NEW version snapshot BEFORE overwriting
  2. Replace the dashboard content with version 3's snapshotJson
  3. Return HTTP 200 with the newly restored state
- AND subsequent GET /api/dashboards/{uuid}/versions MUST list the pre-restore snapshot as the newest version (versionNumber 6)

#### Scenario: Restore is reversible

- GIVEN a user restored from version 3 to version 5's content, creating version 6
- WHEN they later send POST /api/dashboards/{uuid}/versions/6/restore
- THEN the content returns to the original version 5 state
- AND a new snapshot (version 7) is created capturing version 6 before the reversal

#### Scenario: Restore to the current version is a no-op

- GIVEN a dashboard at version 5 (current)
- WHEN a user sends POST /api/dashboards/{uuid}/versions/5/restore
- THEN the system MUST return HTTP 200 (idempotent)
- AND NO new snapshot MUST be created (content unchanged)

#### Scenario: Restore non-existent version

- GIVEN a dashboard with 5 versions
- WHEN a user sends POST /api/dashboards/{uuid}/versions/99/restore
- THEN the system MUST return HTTP 404
- AND no content MUST be modified

#### Scenario: Only owners and admins can restore

- GIVEN dashboard owned by "alice"
- WHEN user "bob" sends POST /api/dashboards/{uuid}/versions/3/restore
- THEN the system MUST return HTTP 403
- AND content MUST NOT be modified

#### Scenario: Restore updates the modified timestamp

- GIVEN a dashboard last modified at 2026-03-01 10:00:00
- WHEN a user restores a version
- THEN the dashboard's updatedAt MUST be set to the current timestamp (2026-03-01 10:15:00 or whenever the restore occurs)
- AND subsequent GET /api/dashboard/{uuid} MUST reflect the new updatedAt

### Requirement: REQ-VERS-006 Version Retention

The system MUST automatically prune old snapshots, keeping only the 50 most recent versions per dashboard in database backend. GroupFolder backend follows Nextcloud's own retention policies.

#### Scenario: Automatic pruning in database backend

- GIVEN a database-backed dashboard with 60 versions (versionNumber 1..60)
- WHEN the system prunes (triggered after a new snapshot creation if total > 50)
- THEN versions 1..10 MUST be deleted
- AND versions 11..60 MUST remain
- AND versionNumber MUST remain monotonic across the range (no renumbering)

#### Scenario: GroupFolder backend defers to Nextcloud

- GIVEN a groupfolder-backed dashboard with file-versions
- WHEN versions are created and NC's own retention policy kicks in
- THEN MyDash MUST NOT independently prune NC file-versions
- AND the system MUST respect NC's retention settings

#### Scenario: Pruning does not affect versionNumber sequence

- GIVEN a database-backed dashboard where versions 1..10 were pruned, versions 11..60 remain
- WHEN a user creates a new snapshot (next version)
- THEN the new snapshot MUST have versionNumber 61 (monotonic), NOT 51
- AND the versionNumber sequence MUST be unbroken from 11 onwards

#### Scenario: Edge case — exactly 50 versions

- GIVEN a dashboard with exactly 50 versions
- WHEN a new snapshot is created
- THEN the system MUST create version 51
- AND prune the oldest (version 1)
- AND the final state MUST have exactly 50 versions (11..60)

### Requirement: REQ-VERS-007 Restore Audit Trail

The system MUST emit a Nextcloud activity event for every restore, enabling audit and history reconstruction.

#### Scenario: Activity event on restore

- GIVEN a user "alice" restores dashboard {uuid} from version 3
- WHEN the restore completes
- THEN the system MUST emit a Nextcloud activity event with type `dashboard_restored`
- AND the event data MUST include: dashboardUuid, restoredFrom (versionNumber), restoredBy (alice), restoredAt (current timestamp)
- AND admins viewing the activity log MUST see this event

#### Scenario: Activity events include full context

- GIVEN a restore event is emitted
- WHEN an admin queries the activity log
- THEN the event MUST be human-readable (e.g., "Alice restored dashboard 'My Dashboard' from version 3")
- AND the event MUST link to the dashboard (via uuid) if the activity UI supports it

#### Scenario: Automatic snapshots do NOT emit activity

- GIVEN a PUT operation creates an automatic snapshot (debounced)
- WHEN the snapshot is created
- THEN NO activity event MUST be emitted (only explicit restores audit)
- NOTE: Automatic snapshots may emit less critical audit logs if desired, but activity events are reserved for restore actions

### Requirement: REQ-VERS-008 Mode-Aware Backend

The system MUST transparently adapt all version endpoints to the dashboard's content backend (database vs. groupfolder file). The caller MUST NOT need to know which backend is in use.

#### Scenario: Database backend returns versions from table

- GIVEN a database-backed dashboard
- WHEN a user calls GET /api/dashboards/{uuid}/versions
- THEN the system MUST query oc_mydash_dashboard_versions table
- AND return version objects constructed from DB rows
- AND each version MUST have a canonical versionNumber (INT)

#### Scenario: GroupFolder backend returns file-versions

- GIVEN a groupfolder-backed dashboard with a file at /user_files/Group%20Folder/dashboard.json and 3 NC file-versions
- WHEN a user calls GET /api/dashboards/{uuid}/versions
- THEN the system MUST call `IVersionManager::getVersions()` on the file
- AND map each file-version object to a compatible response format
- AND synthesize versionNumber from file-version timestamp if NC doesn't provide one, ensuring monotonic ordering

#### Scenario: GroupFolder backend fetch snapshot

- GIVEN a groupfolder-backed dashboard and a user requests GET /api/dashboards/{uuid}/versions/2
- WHEN the request is made
- THEN the system MUST retrieve the specific file-version from NC (not from the current live file)
- AND return the full file content
- AND return HTTP 200 with the version body

#### Scenario: GroupFolder backend restore

- GIVEN a groupfolder-backed dashboard at version 5
- WHEN a user sends POST /api/dashboards/{uuid}/versions/3/restore
- THEN the system MUST:
  1. Capture current content as a new file-version (NC creates this automatically when the file is written)
  2. Call the NC file-version revert API to restore version 3
  3. Return HTTP 200
- AND the content MUST revert to version 3's state

#### Scenario: Mode detection is automatic

- GIVEN a dashboard object
- WHEN version endpoints are called
- THEN the system MUST check the dashboard's `contentBackend` field or equivalent to determine mode
- AND route the call accordingly WITHOUT requiring a mode parameter in the API request

### Requirement: REQ-VERS-009 Soft-Failure Tolerance

The system MUST degrade gracefully when version storage is unavailable, returning HTTP 200 with an indication that versioning is not available, rather than raising HTTP 5xx errors.

#### Scenario: GroupFolder versioning disabled

- GIVEN a groupfolder-backed dashboard and NC File versioning is disabled
- WHEN a user calls GET /api/dashboards/{uuid}/versions
- THEN the response MUST be HTTP 200 with body `{versions: [], modeSupported: false}`
- AND no error MUST be logged (this is expected behavior)

#### Scenario: IVersionManager unavailable

- GIVEN a groupfolder-backed dashboard
- WHEN `IVersionManager` is not accessible or throws an exception
- THEN the system MUST catch the exception and return HTTP 200 `{versions: [], modeSupported: false}`
- AND a warning MAY be logged for ops visibility

#### Scenario: Database connection error is NOT soft-fail

- GIVEN a database-backed dashboard and the database is unreachable
- WHEN a user calls GET /api/dashboards/{uuid}/versions
- THEN the system MUST return HTTP 500 (database errors are NOT soft-fail conditions)
- AND an error MUST be logged
- NOTE: Soft-fail applies only to Nextcloud versioning unavailability (groupfolder mode), not database unavailability

#### Scenario: Graceful degradation UI hint

- GIVEN the API returns `{versions: [], modeSupported: false}` for a groupfolder dashboard
- WHEN the frontend receives this response
- THEN the UI SHOULD display "Version history is not available for this dashboard"
- AND the restore button SHOULD be disabled or hidden

## Non-Functional Requirements

- **Performance**: GET /api/dashboards/{uuid}/versions MUST return within 500ms for dashboards with up to 50 versions. Restore operations MUST complete within 2 seconds.
- **Consistency**: The 60-second debounce window MUST be enforced consistently even under concurrent requests from different users on the same dashboard.
- **Storage**: Database backend MUST prune to 50 versions per dashboard; each MEDIUMTEXT snapshot can store up to 16MB of JSON.
- **Auditability**: Every restore action MUST be logged via Nextcloud activity with full context (uuid, version, user, timestamp).
- **Accessibility**: Version list UI MUST be keyboard-operable and screen-reader friendly.
- **Localization**: All error messages and activity event descriptions MUST support English and Dutch.
