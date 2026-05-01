---
status: draft
---

# Dashboard Cascade Events Specification

## Purpose

When a MyDash dashboard is deleted, all dependent data (widget placements, comments, reactions, locks, versions, public shares, metadata values, translations, view analytics, child-tree dashboards) MUST be automatically removed. When a Nextcloud user or group is deleted, their associated dashboards and downstream records MUST likewise be cleaned up. This capability defines the event, the listener registry, failure isolation, idempotency, and cascade stats reporting.

## ADDED Requirements

### Requirement: REQ-CSC-001 DashboardDeletedEvent Definition

The system MUST define a `DashboardDeletedEvent` class at `lib/Event/DashboardDeletedEvent.php` that carries all context listeners need to perform targeted cleanup.

#### Scenario: Event carries required payload

- GIVEN `DashboardService::delete()` soft-deletes a dashboard with UUID `abc-123`, owner `alice`, type `user`, at `2026-05-01T10:00:00Z`
- WHEN the event is constructed
- THEN `getDashboardUuid()` MUST return `'abc-123'`
- AND `getOwnerUserId()` MUST return `'alice'`
- AND `getType()` MUST return `'user'`
- AND `getDeletedAt()` MUST return a `\DateTimeImmutable` equal to `2026-05-01T10:00:00Z`

#### Scenario: Event is dispatched after soft-delete and before response

- GIVEN a dashboard `D1` is being deleted
- WHEN `DashboardService::delete()` runs
- THEN the dashboard row MUST be soft-deleted in `oc_mydash_dashboards` first
- AND `DashboardDeletedEvent` MUST be dispatched second, before the HTTP response is returned
- NOTE: listeners observe a row that is already soft-deleted — they MUST NOT attempt to re-delete the main row

#### Scenario: Event is NOT dispatched when validation rejects the delete

- GIVEN dashboard `D1` has child dashboards and the caller has NOT requested cascade mode
- WHEN `DashboardService::delete()` runs validation
- THEN the validation MUST reject the request (HTTP 400 or equivalent)
- AND `DashboardDeletedEvent` MUST NOT be dispatched
- AND no dependent data MUST be touched

#### Scenario: Event carries correct type for group-shared dashboard

- GIVEN a group-shared dashboard `G1` (type `group_shared`) is deleted by an admin
- WHEN `DashboardDeletedEvent` is dispatched
- THEN `getType()` MUST return `'group_shared'`
- AND `getOwnerUserId()` MUST return the admin's user ID (the actor who performed the deletion)

#### Scenario: Event class extends Nextcloud IEventDispatcher contract

- GIVEN `DashboardDeletedEvent` is instantiated
- WHEN it is passed to `IEventDispatcher::dispatchTyped()`
- THEN no type error MUST occur — the class MUST extend `\OCP\EventDispatcher\Event`

### Requirement: REQ-CSC-002 Listener Registry and Registration

Every listener MUST be registered in `Application` via `IEventDispatcher::addListener`. Adding a new listener MUST require only adding one registration line — no edits to existing listener classes.

#### Scenario: All DashboardDeletedEvent listeners are registered

- GIVEN MyDash is bootstrapped
- WHEN `Application::register()` runs
- THEN `IEventDispatcher` MUST have listeners registered for `DashboardDeletedEvent::class` covering: `WidgetPlacementsListener`, `CommentsListener`, `ReactionsListener`, `LocksListener`, `VersionsListener`, `PublicSharesListener`, `MetadataValuesListener`, `TranslationsListener`, `ViewAnalyticsListener`, `TreeListener`

#### Scenario: Lifecycle listeners are registered for NC events

- GIVEN MyDash is bootstrapped
- WHEN a `\OCP\User\Events\UserDeletedEvent` is dispatched by Nextcloud core
- THEN MyDash's `UserDeletedListener` MUST be invoked
- AND when a `\OCP\Group\Events\GroupDeletedEvent` is dispatched
- THEN MyDash's `GroupDeletedListener` MUST be invoked

#### Scenario: New listener can be added without editing existing code

- GIVEN a developer creates `lib/Listener/FavoritesListener.php` implementing `IEventListener`
- WHEN they add one line to `Application::register()` registering it for `DashboardDeletedEvent::class`
- THEN it MUST be invoked on every subsequent dashboard deletion — no changes to other listener files, `DashboardService`, or the event class are required

### Requirement: REQ-CSC-003 Dependent-Data Listener Group

A set of listeners MUST clean up every dependent table when `DashboardDeletedEvent` fires. Listeners for disjoint data MUST execute independently and MUST NOT interfere with each other.

#### Scenario: Widget placements are deleted on dashboard delete

- GIVEN dashboard `D1` has 5 widget placements in `oc_mydash_widget_placements`
- WHEN `DashboardDeletedEvent` fires for `D1`
- THEN `WidgetPlacementsListener` MUST delete all 5 rows
- AND no placements from other dashboards MUST be touched

#### Scenario: Comments are removed via ICommentsManager

- GIVEN dashboard `D1` has comments tracked by NC core
- WHEN `CommentsListener` handles the event
- THEN it MUST call `ICommentsManager::deleteCommentsAtObject('mydash_dashboard', $D1.uuid)`
- AND the NC comments table MUST contain no comments for `mydash_dashboard` / `D1.uuid` after the call

#### Scenario: Public shares are soft-revoked, not hard-deleted

- GIVEN dashboard `D1` has 2 active rows in `oc_mydash_public_shares` with `revokedAt IS NULL`
- WHEN `PublicSharesListener` handles the event
- THEN both rows MUST have `revokedAt` set to the current timestamp
- AND neither row MUST be hard-deleted (audit trail is preserved)

#### Scenario: Versions file is deleted in GroupFolder mode

- GIVEN the instance uses GroupFolder storage backend
- AND dashboard `D1` has a JSON versions file at `<groupfolder>/MyDash/versions/D1.uuid.json`
- WHEN `VersionsListener` handles the event
- THEN the DB rows in `oc_mydash_dashboard_versions` MUST be deleted
- AND the JSON file MUST be removed from GroupFolder storage
- NOTE: if the file does not exist (already removed or never created), the listener MUST treat this as a no-op

#### Scenario: Tree listener recursively cascades to child dashboards

- GIVEN dashboard `P1` (parent) has children `C1` and `C2` in `oc_mydash_dashboards`
- WHEN `DashboardDeletedEvent` fires for `P1` (cascade-delete mode)
- THEN `TreeListener` MUST dispatch a new `DashboardDeletedEvent` for `C1` and another for `C2`
- AND each child event MUST trigger the full listener stack for that child (placements, comments, etc.)

### Requirement: REQ-CSC-004 User Lifecycle Cleanup

When Nextcloud deletes a user, MyDash MUST clean up all data owned by that user.

#### Scenario: Personal dashboards are deleted on user deletion

- GIVEN user `alice` owns 3 personal dashboards in `oc_mydash_dashboards`
- WHEN Nextcloud dispatches `\OCP\User\Events\UserDeletedEvent` for `alice`
- THEN `UserDeletedListener` MUST call `DashboardService::delete()` for each of alice's 3 dashboards
- AND each deletion MUST dispatch `DashboardDeletedEvent`, cascading cleanup of their dependent data
- AND no other user's dashboards MUST be affected

#### Scenario: Role assignments are removed on user deletion

- GIVEN `alice` has 2 rows in `oc_mydash_role_assignments`
- WHEN `UserDeletedListener` handles the event
- THEN both rows MUST be deleted from `oc_mydash_role_assignments`
- AND role assignments for other users MUST remain untouched

#### Scenario: Feed token is soft-revoked on user deletion

- GIVEN `alice` has an active RSS feed token in `oc_mydash_feed_tokens`
- WHEN `UserDeletedListener` handles the event
- THEN the token row MUST have `revokedAt` set to the current timestamp
- AND it MUST NOT be hard-deleted (the token URL may still be live; revocation allows a 410 response)

#### Scenario: Analytics opt-out preference is removed on user deletion

- GIVEN `alice` has a stored analytics opt-out preference via IConfig
- WHEN `UserDeletedListener` handles the event
- THEN the preference MUST be deleted from IConfig for her user ID
- NOTE: absence of the key is equivalent to "opted in" — this ensures the pref does not linger for a future user who receives the same user ID

### Requirement: REQ-CSC-005 Group Lifecycle Cleanup

When Nextcloud deletes a group, MyDash MUST clean up all group-scoped data.

#### Scenario: Group-shared dashboards are deleted on group deletion

- GIVEN group `marketing` owns 2 group-shared dashboards in `oc_mydash_dashboards`
- WHEN Nextcloud dispatches `\OCP\Group\Events\GroupDeletedEvent` for `marketing`
- THEN `GroupDeletedListener` MUST call `DashboardService::delete()` for each of those 2 dashboards
- AND each deletion MUST cascade cleanup of dependent data via `DashboardDeletedEvent`

#### Scenario: Group is removed from org navigation tree on group deletion

- GIVEN the `mydash.org_navigation_tree` IConfig value is a JSON object containing `groupVisibility` arrays that reference group `marketing`
- WHEN `GroupDeletedListener` handles the event
- THEN it MUST read the JSON, remove `'marketing'` from all `groupVisibility` arrays, and write the updated JSON back to IConfig
- AND no other group identifiers in the JSON MUST be altered

#### Scenario: Group is removed from group_order setting on group deletion

- GIVEN `mydash.group_order` is a JSON array `["engineering", "marketing", "support"]`
- WHEN `GroupDeletedListener` handles the event for `marketing`
- THEN the setting MUST be updated to `["engineering", "support"]`
- AND the update MUST be persisted to IConfig

### Requirement: REQ-CSC-006 Failure Isolation

A failure in one listener MUST NOT prevent other listeners from executing. Every listener MUST catch all `\Throwable` and continue.

#### Scenario: One listener throws — others still execute

- GIVEN `DashboardDeletedEvent` fires for `D1`
- AND `ReactionsListener` throws a `\RuntimeException` during its execution
- WHEN the event is dispatched
- THEN `WidgetPlacementsListener`, `CommentsListener`, `LocksListener`, and all other registered listeners MUST still execute
- AND the exception from `ReactionsListener` MUST NOT propagate beyond its own catch block
- AND `DashboardService::delete()` MUST complete and return a response

#### Scenario: Listener failure is logged at WARN level

- GIVEN a listener throws during handling of `DashboardDeletedEvent`
- WHEN the catch block executes
- THEN it MUST call `ILogger::warning(...)` (or equivalent) with the listener class name, dashboard UUID, and exception message
- AND MUST NOT call `ILogger::error()` (a cascade failure is recoverable via orphan-cleanup — it is not a fatal error)

#### Scenario: Multiple listener failures do not prevent response

- GIVEN 3 out of 10 listeners throw exceptions
- WHEN the dispatch completes
- THEN `DashboardService::delete()` MUST still return the HTTP response with `cascadeStats`
- AND `cascadeStats` MUST accurately reflect counts from the 7 listeners that succeeded
- AND the 3 failures MUST be recorded in `oc_mydash_cascade_failures`

### Requirement: REQ-CSC-007 Failure Recording

When a listener fails, the failure MUST be recorded in `oc_mydash_cascade_failures` for later retry by the orphan-cleanup job.

#### Scenario: Failed listener is recorded with full context

- GIVEN `MetadataValuesListener` throws while handling a `DashboardDeletedEvent` for UUID `abc-123`
- WHEN the catch block runs
- THEN a row MUST be inserted into `oc_mydash_cascade_failures` with:
  - `listener_class = 'OCA\MyDash\Listener\MetadataValuesListener'`
  - `dashboard_uuid = 'abc-123'`
  - `error_message` = the exception message (truncated to fit column if needed)
  - `failed_at` = current timestamp

#### Scenario: Failure recorder itself fails gracefully

- GIVEN `CascadeFailureRecorder::record()` cannot write to the DB (e.g., table lock)
- WHEN the recorder's internal try/catch fires
- THEN no exception MUST propagate to the calling listener — the failure is logged via `ILogger` but silently swallowed
- NOTE: this prevents a meta-failure from disrupting other listeners

#### Scenario: Orphan-cleanup job can query failure table

- GIVEN rows exist in `oc_mydash_cascade_failures` from previous failed listeners
- WHEN the orphan-cleanup job runs
- THEN it MUST be able to query `oc_mydash_cascade_failures` by `dashboard_uuid` to identify which listeners still need to be re-run
- NOTE: retry logic is defined in the `orphaned-data-cleanup` change; this spec only defines the table and recording contract

### Requirement: REQ-CSC-008 Idempotency

Every listener MUST be idempotent: running it a second time against already-cleaned data MUST be a no-op with no errors.

#### Scenario: Re-running WidgetPlacementsListener on empty table is safe

- GIVEN `DashboardDeletedEvent` fires for `D1` and placements are already deleted
- WHEN `WidgetPlacementsListener` runs again (e.g., via orphan-cleanup retry)
- THEN the DELETE query MUST affect 0 rows and MUST NOT throw an exception
- AND the listener MUST return successfully

#### Scenario: Re-running PublicSharesListener on already-revoked shares is safe

- GIVEN all share rows for `D1` already have `revokedAt` set
- WHEN `PublicSharesListener` runs again
- THEN the UPDATE MUST match 0 rows (WHERE `revokedAt IS NULL` filters them all out) and MUST NOT throw

#### Scenario: Re-running CommentsListener when no comments exist is safe

- GIVEN `ICommentsManager::deleteCommentsAtObject()` is called for `D1` when no comments exist
- THEN it MUST return without error (NC core already handles the empty-case gracefully)
- AND `CommentsListener` MUST NOT add extra null-checks that could mask genuine errors

### Requirement: REQ-CSC-009 Cascade Stats Response

`DashboardService::delete()` MUST return cascade statistics alongside the standard delete response.

#### Scenario: Response includes cascadeStats on successful delete

- GIVEN dashboard `D1` has 3 widget placements, 2 reactions, 1 lock
- WHEN `DELETE /api/dashboards/D1.uuid` completes
- THEN the response body MUST include:
  ```json
  {
    "deletedAt": "2026-05-01T10:00:00Z",
    "cascadeStats": {
      "widgetPlacementsDeleted": 3,
      "commentsDeleted": 0,
      "reactionsDeleted": 2,
      "locksDeleted": 1,
      "versionsDeleted": 0,
      "sharesRevoked": 0,
      "metadataValuesDeleted": 0,
      "translationsDeleted": 0,
      "viewsDeleted": 0
    }
  }
  ```
- AND the response MUST be additive (clients that ignore unknown fields remain unaffected)

#### Scenario: cascadeStats reflects partial success when listeners fail

- GIVEN 2 listeners succeed (deleting 5 rows total) and 1 listener fails
- WHEN the response is built
- THEN `cascadeStats` MUST report counts from the 2 successful listeners
- AND the failed listener's key MUST appear with value `0` (or be omitted — implementation choice, but MUST be consistent)
- NOTE: the caller is not expected to act on partial-failure counts; the `oc_mydash_cascade_failures` table is the canonical failure record

#### Scenario: cascadeStats is present even when no dependent data exists

- GIVEN dashboard `D1` has no placements, comments, or any other dependent rows
- WHEN `DELETE /api/dashboards/D1.uuid` completes
- THEN `cascadeStats` MUST still be present in the response with all counters set to `0`

### Requirement: REQ-CSC-010 Tree Cascade Validation Guard

Cascade deletion of a parent dashboard MUST only proceed when explicitly requested; non-cascade deletes with children MUST be rejected before any event is dispatched.

#### Scenario: Non-cascade delete with children is rejected before event dispatch

- GIVEN dashboard `P1` has child `C1`
- WHEN a caller sends `DELETE /api/dashboards/P1.uuid` without a cascade flag
- THEN the system MUST return HTTP 400 with an error indicating children exist
- AND `DashboardDeletedEvent` MUST NOT be dispatched for `P1` or `C1`
- AND no dependent data MUST be altered

#### Scenario: Cascade delete processes parent then recursively processes children

- GIVEN dashboard `P1` has children `C1` and `C2`, each with 2 widget placements
- WHEN `DELETE /api/dashboards/P1.uuid?cascade=true` is called
- THEN `DashboardService::delete()` MUST soft-delete `P1` and dispatch `DashboardDeletedEvent` for it
- AND `TreeListener` MUST dispatch `DashboardDeletedEvent` for `C1` and `C2`
- AND all 4 widget placements (2 per child) MUST be removed by `WidgetPlacementsListener` responding to each child event
- AND `cascadeStats` in the response MUST reflect the total across all three dashboards

#### Scenario: Tree listener is a no-op for leaf dashboards

- GIVEN dashboard `L1` has no children
- WHEN `DashboardDeletedEvent` fires for `L1`
- THEN `TreeListener` MUST query the children table, find zero rows, and return without dispatching any further events
- AND this MUST be treated as a successful no-op (no failure is recorded)
