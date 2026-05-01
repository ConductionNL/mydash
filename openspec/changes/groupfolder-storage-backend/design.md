# Design — GroupFolder storage backend

## Context

MyDash is introducing a two-backend storage architecture (DB and GroupFolder) built directly on the pattern proven by the reference implementation, which uses a GroupFolder as its sole persistence layer. The reference implementation provides the ground truth for how a Nextcloud app should behave when its GroupFolder is unreachable, missing, or not yet set up.

The critical open question is what happens when an administrator switches `mydash.content_storage` from one backend to the other without first running the one-time migration command. Half the dashboard records then live in the old backend while the new backend is active. Two strategies are possible: (a) hard-fail every read that misses in the new backend, or (b) transparently fall back to the old backend for reads.

The reference implementation sheds light on this because it has exactly one backend and must deal with the same class of failure: GroupFolder unreachable, app uninstalled, or setup never completed. Its choices reveal the operational tradeoff between fail-closed safety and read continuity.

A key structural fact from the reference app is that its `intravox_page_index` table stores only metadata — `unique_id`, `title`, `path`, `language`, `status`, `modified_at`, `parent_id`, `file_id` — and carries **no cached body/content**. The DB is never a content fallback; it is only a lookup index. Page bodies always require a live filesystem read. This is the architectural anchor for the decision below.

## Goals / Non-Goals

**Goals:**

- Pick exactly one behaviour for REQ-GFSB-009 and encode it in the spec with concrete scenarios.
- Ensure the chosen behaviour is consistent with the reference app's fail-closed philosophy.
- Document the specific conditions that trigger HTTP 503 vs. HTTP 404.

**Non-Goals:**

- Choosing a fallback strategy for **reads within a single backend** (e.g., locale fallback inside GroupFolderContentStorage — that is REQ-GFSB-004 and already partially resolved).
- Designing the migration command itself (REQ-GFSB-008 is fully specced).
- Changing any other requirement.

## Decisions

### D1: Backend-switch behaviour — hard-fail vs. dual-read

**Decision**: **Hard-fail (option a).** When `mydash.content_storage` is switched to a new backend without running the migration, read operations for dashboards that exist only in the old backend MUST return HTTP 503 with error key `dashboard_content_storage_unavailable`. The API MUST NOT attempt to read from the inactive backend.

**Alternatives considered:**

- **Dual-read (option b)**: try the active backend, fall back to the inactive one if not found. Rejected because (1) it requires both backends to remain live and queryable at all times, making backend decommissioning impossible without data loss risk; (2) it makes the `exists()` semantics ambiguous — callers cannot know which backend holds the authoritative copy; (3) the reference app explicitly does not implement any cross-backend fallback even for its much simpler single-backend case.

**Rationale**: The reference implementation's error handling is consistently fail-closed. When `getSharedFolder()` fails — because the GroupFolder is missing or the `groupfolders` app is uninstalled — the resulting exception propagates as HTTP 500 (internal server error) rather than silently degrading. The list endpoint is the one exception: it deliberately returns an empty array instead of an error when the folder is not found (`intravox-source/lib/Controller/ApiController.php:209-214`), but this is scoped to the *listing* operation only, where an empty result is semantically valid. Single-page reads throw the exception straight through to a 404/500. The reference app never attempts a cross-store lookup because it has nowhere else to look — but the design choice is clear: known-bad state surfaces immediately rather than being papered over.

For MyDash the consequence is equally clear: an operator who switches backends without migrating has made an incomplete operational change. Surfacing HTTP 503 immediately is the correct signal — it is unambiguous, monitorable, and tells the operator exactly what to do (run the migration command). Silent dual-read would hide the incomplete migration indefinitely and could cause split-brain writes (new dashboards going to the new backend, old ones silently read from the old one, no indication that migration is needed).

The `groupfolder_setup_required` distinction (GroupFolder app not installed vs. GroupFolder folder not yet created) also informs this: the reference app (`intravox-source/lib/Service/SetupService.php:79-89`) returns a typed error key (`groupfolders_app_not_enabled`) immediately — it does not substitute a different storage mechanism. MyDash should mirror this granularity.

**Source evidence**:

- `intravox-source/lib/Controller/ApiController.php:209-214` — listing endpoint catches `"IntraVox folder not found"` and returns `[]` rather than an error, but this is the list path only; all other endpoints propagate the exception as 404 or 500.
- `intravox-source/lib/Service/PageService.php:441-445` — `getIntraVoxFolder()` throws a plain `\Exception("IntraVox folder not found…")` with no fallback; caller in `getPage()` maps it to HTTP 404 (`ApiController:258-263`).
- `intravox-source/lib/Service/SetupService.php:79-89` — `setupSharedFolder()` returns `['success' => false, 'error' => 'groupfolders_app_not_enabled']` immediately when the app is absent; no alternative path attempted.
- `intravox-source/lib/Service/SetupService.php:562-569` — `isSetupComplete()` is a boolean predicate (`try { getSharedFolder(); return true; } catch { return false; }`); callers treat `false` as a blocking condition (see `DemoDataService:570-572` which runs setup first, not a different content source).
- `intravox-source/lib/Migration/Version001300Date20260420000000.php:25-76` — `intravox_page_index` schema: only `unique_id`, `title`, `path`, `language`, `status`, `modified_at`, `parent_id`, `file_id`. **No `body` or `content` column.** The DB is purely a metadata index; there is nothing to fall back to for content even if dual-read were desired.
- `intravox-source/lib/Service/PermissionService.php:129-131` — **surprising exception**: when no GroupFolder is found, `calculatePermissions()` returns `PERMISSION_ALL` as a fallback ("Fallback if groupfolders not setup"). This is a deliberate open-door for pre-setup state, but it applies only to permission checks, not to content reads. Content reads have no equivalent fallback.

### D2: Pre-setup hard-fail vs. auto-create on first GroupFolder write

**Decision**: The GroupFolder backend MUST attempt auto-creation (`ensureMyDashGroupFolder()`) on the first write when no "MyDash" GroupFolder exists. It MUST NOT auto-create on reads. A read against a non-existent GroupFolder returns HTTP 503.

**Rationale**: The reference app auto-creates its GroupFolder during the explicit `setupSharedFolder()` step (triggered via the repair step on install, `intravox-source/lib/Migration/SetupDemoData.php:34-48`). It does not auto-create on demand during page reads; `getSharedFolder()` throws if the folder does not exist. For writes the reference app delegates creation to the setup flow, not to the write path. MyDash will differ slightly because its GroupFolder backend can be activated by an admin mid-lifecycle rather than only at install. Putting `ensureMyDashGroupFolder()` in the write path (REQ-GFSB-003 already specifies this) matches the spirit of the reference implementation while accommodating the mid-lifecycle activation case.

**Source evidence**:

- `intravox-source/lib/Migration/SetupDemoData.php:34-48` — setup runs before any content operations; if it fails, the repair step returns early with a warning, not by falling back to a different store.
- `intravox-source/lib/Service/SetupService.php:269-297` — `getSharedFolder()` throws `\Exception("Groupfolder '…' not accessible")` with no auto-create; auto-create is only in `setupSharedFolder()` → `createOrGetGroupfolderByName()`.

### D3: Migration retention — delete DB copy after migration or keep it

**Decision**: Keep DB records after migration (option B from REQ-GFSB-008's optional decision point). The migration command MUST NOT delete the source DB content. Deletion can be triggered by a separate `--prune-source` flag.

**Rationale**: The reference app never destroys content on migration — its `migrateResourcesFolders()` and `migrateTemplatesFolders()` helpers only create new structures; they never delete old ones. Given that rolling back from GroupFolder to DB is as simple as flipping `mydash.content_storage` back, keeping DB records gives operators a zero-downtime rollback path. Destroying them would make rollback require restoring from backup.

**Source evidence**:

- `intravox-source/lib/Service/SetupService.php:625-703` — both migration helpers (`migrateResourcesFolders`, `migrateTemplatesFolders`) are purely additive; no removal of existing data after creating new structures.

## Spec changes implied

Changes to apply to `specs/groupfolder-storage-backend/spec.md`:

1. **REQ-GFSB-009 Scenario 1 (database-backed dashboard readable during GroupFolder transition)**: Replace the "NOTE" text and the "decide during implementation" hedge with a firm ruling: the system MUST return HTTP 503 with `dashboard_content_storage_unavailable` when the active backend is GroupFolder and the requested dashboard does not exist in the GroupFolder (even if it exists in the DB). Remove the dual-read option from the scenario entirely.

2. **REQ-GFSB-009 — add new scenario**: "Pre-switch migration required" — GIVEN `mydash.content_storage` is about to be switched AND dashboards exist in the current backend, the admin-facing error message in HTTP 503 responses MUST include a reference to the migration command (`mydash:storage:migrate-to-groupfolder`). This ensures the 503 is actionable, not just a raw failure.

3. **REQ-GFSB-008 Scenario "Retention policy after migration"**: Change from open decision to resolved: option B (keep DB records). Add: the command MUST accept a `--prune-source` flag that, when passed, deletes the DB content for successfully migrated dashboards. The default (no flag) MUST NOT delete.

4. **REQ-GFSB-003 — add scenario**: "GroupFolder auto-creation does not occur on read" — GIVEN the "MyDash" GroupFolder does not exist AND `mydash.content_storage = 'groupfolder'`, WHEN a read operation is attempted, THEN the system MUST return HTTP 503 without attempting to create the GroupFolder.

5. **REQ-GFSB-005 Scenario "GroupFolders app becomes unavailable mid-operation"**: Remove the phrase "NOT silently fall back to the database backend" — this already aligns with the decision but the current wording implies fallback is a live option being rejected; after this design is applied, the wording should state the positive: "MUST throw `DashboardContentStorageException` propagated as HTTP 503."

## Open follow-ups

- **PermissionService `PERMISSION_ALL` fallback**: The reference app grants full permissions when no GroupFolder is found (`intravox-source/lib/Service/PermissionService.php:129-131`). This is an open-door for pre-setup state. MyDash's `PermissionService` equivalent should explicitly NOT grant full permissions when the GroupFolder backend is configured but the GroupFolder is missing — that state is HTTP 503 territory, not open-door territory. This is a design detail that needs a spec scenario under REQ-GFSB-005 or a new REQ-GFSB-011.

- **Listing endpoint semantics during transition**: The reference app returns `[]` (empty array) for the page list when the folder is not found. Should MyDash's `GET /api/dashboards` (or `/visible`) return `[]` or HTTP 503 when the active backend is GroupFolder but the folder is unreachable? The current spec says fail-closed (503) for all operations. The reference app makes a list-vs-detail distinction. A separate micro-decision is needed here before implementation starts.
