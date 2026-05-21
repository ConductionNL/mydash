---
capability: dashboard-crud
delta: true
status: draft
---

# Dashboard CRUD — Delta from change `dashboard-cascade-events`

## MODIFIED Requirements

### Requirement: REQ-CRUD-DELETE Dashboard Deletion (modified to include cascade validation and stats)

When a dashboard is deleted, it MUST be soft-deleted (marked with `deletedAt` timestamp), a `DashboardDeletedEvent` MUST be dispatched for dependent-data cleanup, and cascade statistics MUST be returned.

#### Scenario: Cascade validation rejects non-cascade delete with children

- **GIVEN** dashboard `P1` has at least one child dashboard in `oc_mydash_dashboards` (where `parentId = P1.uuid`)
- **WHEN** `DashboardService::delete(uuid)` is called without `cascade=true` parameter
- **THEN** the service MUST return HTTP 400 with error code `'CHILDREN_EXIST'` and message indicating children are present
- **AND** the dashboard row MUST NOT be soft-deleted
- **AND** no event MUST be dispatched
- **AND** no dependent data MUST be modified

#### Scenario: Cascade delete with children proceeds

- **GIVEN** dashboard `P1` has children and a caller requests `cascade=true`
- **WHEN** `DashboardService::delete(uuid, cascade=true)` runs
- **THEN** the dashboard row MUST be soft-deleted (insert `deletedAt` timestamp)
- **AND** `DashboardDeletedEvent` MUST be dispatched
- **AND** `TreeListener` will dispatch events for all children

#### Scenario: Delete response includes cascadeStats

- **GIVEN** a dashboard is successfully deleted (soft-deleted row + events dispatched)
- **WHEN** the delete operation completes
- **THEN** the HTTP response MUST include a `cascadeStats` object with the following keys (all numeric):
  - `widgetPlacementsDeleted` (count from `WidgetPlacementsListener`)
  - `commentsDeleted` (count from `CommentsListener`)
  - `reactionsDeleted` (count from `ReactionsListener`)
  - `locksDeleted` (count from `LocksListener`)
  - `versionsDeleted` (count from `VersionsListener`)
  - `sharesRevoked` (count from `PublicSharesListener`)
  - `metadataValuesDeleted` (count from `MetadataValuesListener`)
  - `translationsDeleted` (count from `TranslationsListener`)
  - `viewsDeleted` (count from `ViewAnalyticsListener`)
- **AND** each listener MUST return its count so the service can aggregate and include it in the response
- **AND** counts of `0` are acceptable when a listener finds no dependent data for that dashboard

#### Scenario: Delete response format remains backward-compatible

- **GIVEN** the delete response is built with `cascadeStats`
- **WHEN** a legacy client processes the response
- **THEN** the response MUST include the original delete fields (e.g., `deletedAt`, `uuid`) plus the new `cascadeStats` object
- **AND** clients that do not understand `cascadeStats` MUST not break (it is an additive field)

#### Scenario: Tree deletion aggregates child cascadeStats

- **GIVEN** `DashboardDeletedEvent` is dispatched for parent dashboard `P1`
- **AND** `TreeListener` dispatches events for children `C1`, `C2`, etc.
- **AND** each child event triggers its own listener stack with cascade counts
- **WHEN** the parent delete completes
- **THEN** the final `cascadeStats` in the response MUST include aggregated totals from parent + all children
- **EXAMPLE:** if parent has 3 placements, C1 has 2, C2 has 1, then `widgetPlacementsDeleted = 6`

#### Scenario: Partial listener failures do not alter cascadeStats from successful listeners

- **GIVEN** 8 out of 10 listeners execute successfully and 2 listeners throw
- **WHEN** the delete completes
- **THEN** `cascadeStats` MUST reflect the counts from the 8 successful listeners
- **AND** the 2 failed listeners' counts MUST be reported as `0` (or that key may be omitted, but choice MUST be consistent)
- **AND** failures are logged separately; the response does not report failure details in cascadeStats

#### Scenario: Soft-delete timestamp is included in response

- **GIVEN** a dashboard is deleted
- **WHEN** the response is built
- **THEN** it MUST include `deletedAt` as an ISO 8601 timestamp (e.g., `"2026-05-01T10:00:00Z"`)
- **AND** this timestamp MUST match the value stored in the database row's `deletedAt` column
