---
capability: cascade-operations
delta: false
status: draft
---

# Cascade Operations — new capability from change `dashboard-cascade-events`

## NEW Requirements

### Requirement: REQ-CSC-003 Dependent-Data Listener Group

A set of listeners MUST clean up every dependent table when `DashboardDeletedEvent` fires. Listeners for disjoint data MUST execute independently and MUST NOT interfere with each other.

#### Scenario: Widget placements are deleted on dashboard delete

- **GIVEN** dashboard `D1` has 5 widget placements in `oc_mydash_widget_placements`
- **WHEN** `DashboardDeletedEvent` fires for `D1`
- **THEN** `WidgetPlacementsListener` MUST delete all 5 rows
- **AND** no placements from other dashboards MUST be touched

#### Scenario: Comments are removed via ICommentsManager

- **GIVEN** dashboard `D1` has comments tracked by NC core
- **WHEN** `CommentsListener` handles the event
- **THEN** it MUST call `ICommentsManager::deleteCommentsAtObject('mydash_dashboard', $D1.uuid)`
- **AND** the NC comments table MUST contain no comments for `mydash_dashboard` / `D1.uuid` after the call

#### Scenario: Reactions are deleted from reactions table

- **GIVEN** dashboard `D1` has 3 reaction rows in `oc_mydash_reactions`
- **WHEN** `ReactionsListener` handles the event
- **THEN** all 3 reaction rows MUST be deleted
- **AND** no reactions for other dashboards MUST be affected

#### Scenario: Locks are deleted from locks table

- **GIVEN** dashboard `D1` has 2 active lock rows in `oc_mydash_locks`
- **WHEN** `LocksListener` handles the event
- **THEN** both lock rows MUST be deleted
- **AND** locks for other dashboards MUST not be touched

#### Scenario: Metadata values are deleted

- **GIVEN** dashboard `D1` has 4 metadata value rows in `oc_mydash_metadata_values`
- **WHEN** `MetadataValuesListener` handles the event
- **THEN** all 4 rows MUST be deleted
- **AND** no metadata for other dashboards MUST be affected

#### Scenario: Translations are deleted

- **GIVEN** dashboard `D1` has translation rows for 3 languages in `oc_mydash_translations`
- **WHEN** `TranslationsListener` handles the event
- **THEN** all translation rows for `D1` MUST be deleted
- **AND** translations for other dashboards MUST remain

#### Scenario: View analytics are deleted

- **GIVEN** dashboard `D1` has 10 view analytics rows in `oc_mydash_view_analytics`
- **WHEN** `ViewAnalyticsListener` handles the event
- **THEN** all 10 rows MUST be deleted
- **AND** analytics for other dashboards MUST not be affected

#### Scenario: Public shares are soft-revoked, not hard-deleted

- **GIVEN** dashboard `D1` has 2 active rows in `oc_mydash_public_shares` with `revokedAt IS NULL`
- **WHEN** `PublicSharesListener` handles the event
- **THEN** both rows MUST have `revokedAt` set to the current timestamp
- **AND** neither row MUST be hard-deleted (audit trail is preserved)

#### Scenario: Versions database rows are deleted

- **GIVEN** dashboard `D1` has rows in `oc_mydash_dashboard_versions`
- **WHEN** `VersionsListener` handles the event
- **THEN** all version rows for `D1` MUST be deleted from the database

#### Scenario: Versions file is deleted in GroupFolder mode

- **GIVEN** the instance uses GroupFolder storage backend
- **AND** dashboard `D1` has a JSON versions file at `<groupfolder>/MyDash/versions/D1.uuid.json`
- **WHEN** `VersionsListener` handles the event
- **THEN** the DB rows in `oc_mydash_dashboard_versions` MUST be deleted
- **AND** the JSON file MUST be removed from GroupFolder storage
- **NOTE:** if the file does not exist (already removed or never created), the listener MUST treat this as a no-op

#### Scenario: Tree listener recursively cascades to child dashboards

- **GIVEN** dashboard `P1` (parent) has children `C1` and `C2` in `oc_mydash_dashboards`
- **WHEN** `DashboardDeletedEvent` fires for `P1` (cascade-delete mode)
- **THEN** `TreeListener` MUST dispatch a new `DashboardDeletedEvent` for `C1` and another for `C2`
- **AND** each child event MUST trigger the full listener stack for that child (placements, comments, etc.)

### Requirement: REQ-CSC-004 User Lifecycle Cleanup

When Nextcloud deletes a user, MyDash MUST clean up all data owned by that user.

#### Scenario: Personal dashboards are deleted on user deletion

- **GIVEN** user `alice` owns 3 personal dashboards in `oc_mydash_dashboards`
- **WHEN** Nextcloud dispatches `\OCP\User\Events\UserDeletedEvent` for `alice`
- **THEN** `UserDeletedListener` MUST call `DashboardService::delete()` for each of alice's 3 dashboards
- **AND** each deletion MUST dispatch `DashboardDeletedEvent`, cascading cleanup of their dependent data
- **AND** no other user's dashboards MUST be affected

#### Scenario: Role assignments are removed on user deletion

- **GIVEN** `alice` has 2 rows in `oc_mydash_role_assignments`
- **WHEN** `UserDeletedListener` handles the event
- **THEN** both rows MUST be deleted from `oc_mydash_role_assignments`
- **AND** role assignments for other users MUST remain untouched

#### Scenario: Feed token is soft-revoked on user deletion

- **GIVEN** `alice` has an active RSS feed token in `oc_mydash_feed_tokens`
- **WHEN** `UserDeletedListener` handles the event
- **THEN** the token row MUST have `revokedAt` set to the current timestamp
- **AND** it MUST NOT be hard-deleted (the token URL may still be live; revocation allows a 410 response)

#### Scenario: Analytics opt-out preference is removed on user deletion

- **GIVEN** `alice` has a stored analytics opt-out preference via IConfig
- **WHEN** `UserDeletedListener` handles the event
- **THEN** the preference MUST be deleted from IConfig for her user ID
- **NOTE:** absence of the key is equivalent to "opted in" — this ensures the pref does not linger for a future user who receives the same user ID

### Requirement: REQ-CSC-005 Group Lifecycle Cleanup

When Nextcloud deletes a group, MyDash MUST clean up all group-scoped data.

#### Scenario: Group-shared dashboards are deleted on group deletion

- **GIVEN** group `marketing` owns 2 group-shared dashboards in `oc_mydash_dashboards`
- **WHEN** Nextcloud dispatches `\OCP\Group\Events\GroupDeletedEvent` for `marketing`
- **THEN** `GroupDeletedListener` MUST call `DashboardService::delete()` for each of those 2 dashboards
- **AND** each deletion MUST cascade cleanup of dependent data via `DashboardDeletedEvent`

#### Scenario: Group is removed from org navigation tree on group deletion

- **GIVEN** the `mydash.org_navigation_tree` IConfig value is a JSON object containing `groupVisibility` arrays that reference group `marketing`
- **WHEN** `GroupDeletedListener` handles the event
- **THEN** it MUST read the JSON, remove `'marketing'` from all `groupVisibility` arrays, and write the updated JSON back to IConfig
- **AND** no other group identifiers in the JSON MUST be altered

#### Scenario: Group is removed from group_order setting on group deletion

- **GIVEN** `mydash.group_order` is a JSON array `["engineering", "marketing", "support"]`
- **WHEN** `GroupDeletedListener` handles the event for `marketing`
- **THEN** the setting MUST be updated to `["engineering", "support"]`
- **AND** the update MUST be persisted to IConfig

### Requirement: REQ-CSC-008 Idempotency

Every listener MUST be idempotent: running it a second time against already-cleaned data MUST be a no-op with no errors.

#### Scenario: Re-running WidgetPlacementsListener on empty table is safe

- **GIVEN** `DashboardDeletedEvent` fires for `D1` and placements are already deleted
- **WHEN** `WidgetPlacementsListener` runs again (e.g., via orphan-cleanup retry)
- **THEN** the DELETE query MUST affect 0 rows and MUST NOT throw an exception
- **AND** the listener MUST return successfully

#### Scenario: Re-running PublicSharesListener on already-revoked shares is safe

- **GIVEN** all share rows for `D1` already have `revokedAt` set
- **WHEN** `PublicSharesListener` runs again
- **THEN** the UPDATE MUST match 0 rows (WHERE `revokedAt IS NULL` filters them all out) and MUST NOT throw

#### Scenario: Re-running CommentsListener when no comments exist is safe

- **GIVEN** `ICommentsManager::deleteCommentsAtObject()` is called for `D1` when no comments exist
- **THEN** it MUST return without error (NC core already handles the empty-case gracefully)
- **AND** `CommentsListener` MUST NOT add extra null-checks that could mask genuine errors

#### Scenario: Re-running TreeListener when no children exist is safe

- **GIVEN** dashboard `L1` has no children and `DashboardDeletedEvent` fires for it
- **WHEN** `TreeListener` runs (even on retry)
- **THEN** it MUST query the children table, find zero rows, and return without error or dispatching any further events

### Requirement: REQ-CSC-009 Cascade Stats Response

`DashboardService::delete()` MUST return cascade statistics alongside the standard delete response.

#### Scenario: Response includes cascadeStats on successful delete

- **GIVEN** dashboard `D1` has 3 widget placements, 2 reactions, 1 lock
- **WHEN** `DELETE /api/dashboards/D1.uuid` completes
- **THEN** the response body MUST include:
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
- **AND** the response MUST be additive (clients that ignore unknown fields remain unaffected)

#### Scenario: cascadeStats reflects partial success when listeners fail

- **GIVEN** 2 listeners succeed (deleting 5 rows total) and 1 listener fails
- **WHEN** the response is built
- **THEN** `cascadeStats` MUST report counts from the 2 successful listeners
- **AND** the failed listener's key MUST appear with value `0` (or be omitted — implementation choice, but MUST be consistent)

#### Scenario: cascadeStats is present even when no dependent data exists

- **GIVEN** dashboard `D1` has no placements, comments, or any other dependent rows
- **WHEN** `DELETE /api/dashboards/D1.uuid` completes
- **THEN** `cascadeStats` MUST still be present in the response with all counters set to `0`

#### Scenario: cascadeStats aggregate child deletions in tree cascade

- **GIVEN** dashboard `P1` has children `C1` and `C2`
- **AND** `P1` has 3 widget placements; `C1` has 2 placements; `C2` has 1 placement
- **WHEN** `DELETE /api/dashboards/P1.uuid?cascade=true` completes
- **THEN** `cascadeStats.widgetPlacementsDeleted` MUST equal `6` (total across P1 + C1 + C2)

### Requirement: REQ-CSC-010 Tree Cascade Validation Guard

Cascade deletion of a parent dashboard MUST only proceed when explicitly requested; non-cascade deletes with children MUST be rejected before any event is dispatched.

#### Scenario: Non-cascade delete with children is rejected before event dispatch

- **GIVEN** dashboard `P1` has child `C1`
- **WHEN** a caller sends `DELETE /api/dashboards/P1.uuid` without a cascade flag
- **THEN** the system MUST return HTTP 400 with an error indicating children exist
- **AND** `DashboardDeletedEvent` MUST NOT be dispatched for `P1` or `C1`
- **AND** no dependent data MUST be altered

#### Scenario: Cascade delete processes parent then recursively processes children

- **GIVEN** dashboard `P1` has children `C1` and `C2`, each with 2 widget placements
- **WHEN** `DELETE /api/dashboards/P1.uuid?cascade=true` is called
- **THEN** `DashboardService::delete()` MUST soft-delete `P1` and dispatch `DashboardDeletedEvent` for it
- **AND** `TreeListener` MUST dispatch `DashboardDeletedEvent` for `C1` and `C2`
- **AND** all 4 widget placements (2 per child) MUST be removed by `WidgetPlacementsListener` responding to each child event
- **AND** `cascadeStats` in the response MUST reflect the total across all three dashboards

#### Scenario: Tree listener is a no-op for leaf dashboards

- **GIVEN** dashboard `L1` has no children
- **WHEN** `DashboardDeletedEvent` fires for `L1`
- **THEN** `TreeListener` MUST query the children table, find zero rows, and return without dispatching any further events
- **AND** this MUST be treated as a successful no-op (no failure is recorded)
