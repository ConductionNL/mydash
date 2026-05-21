---
capability: event-dispatch
delta: false
status: draft
---

# Event Dispatch — new capability from change `dashboard-cascade-events`

## NEW Requirements

### Requirement: REQ-CSC-001 DashboardDeletedEvent Definition

The system MUST define a `DashboardDeletedEvent` class at `lib/Event/DashboardDeletedEvent.php` that carries all context listeners need to perform targeted cleanup.

#### Scenario: Event carries required payload

- **GIVEN** `DashboardService::delete()` soft-deletes a dashboard with UUID `abc-123`, owner `alice`, type `user`, at `2026-05-01T10:00:00Z`
- **WHEN** the event is constructed
- **THEN** `getDashboardUuid()` MUST return `'abc-123'`
- **AND** `getOwnerUserId()` MUST return `'alice'`
- **AND** `getType()` MUST return `'user'`
- **AND** `getDeletedAt()` MUST return a `\DateTimeImmutable` equal to `2026-05-01T10:00:00Z`

#### Scenario: Event is dispatched after soft-delete and before response

- **GIVEN** a dashboard `D1` is being deleted
- **WHEN** `DashboardService::delete()` runs
- **THEN** the dashboard row MUST be soft-deleted in `oc_mydash_dashboards` first
- **AND** `DashboardDeletedEvent` MUST be dispatched second, before the HTTP response is returned
- **NOTE:** dispatch is synchronous within the same PHP request — `IEventDispatcher::dispatchTyped()` blocks until all listeners return; the event fires before the HTTP response is sent.
- **NOTE:** listeners observe a row that is already soft-deleted — they MUST NOT attempt to re-delete the main row

#### Scenario: Event is NOT dispatched when validation rejects the delete

- **GIVEN** dashboard `D1` has child dashboards and the caller has NOT requested cascade mode
- **WHEN** `DashboardService::delete()` runs validation
- **THEN** the validation MUST reject the request (HTTP 400 or equivalent)
- **AND** `DashboardDeletedEvent` MUST NOT be dispatched
- **AND** no dependent data MUST be touched

#### Scenario: Event carries correct type for group-shared dashboard

- **GIVEN** a group-shared dashboard `G1` (type `group_shared`) is deleted by an admin
- **WHEN** `DashboardDeletedEvent` is dispatched
- **THEN** `getType()` MUST return `'group_shared'`
- **AND** `getOwnerUserId()` MUST return the admin's user ID (the actor who performed the deletion)

#### Scenario: Event class extends Nextcloud IEventDispatcher contract

- **GIVEN** `DashboardDeletedEvent` is instantiated
- **WHEN** it is passed to `IEventDispatcher::dispatchTyped()`
- **THEN** no type error MUST occur — the class MUST extend `\OCP\EventDispatcher\Event`

### Requirement: REQ-CSC-002 Listener Registry and Registration

Every listener MUST be registered in `Application` via `IEventDispatcher::addListener`. Adding a new listener MUST require only adding one registration line — no edits to existing listener classes.

#### Scenario: All DashboardDeletedEvent listeners are registered

- **GIVEN** MyDash is bootstrapped
- **WHEN** `Application::register()` runs
- **THEN** `IEventDispatcher` MUST have listeners registered for `DashboardDeletedEvent::class` covering: `WidgetPlacementsListener`, `CommentsListener`, `ReactionsListener`, `LocksListener`, `VersionsListener`, `PublicSharesListener`, `MetadataValuesListener`, `TranslationsListener`, `ViewAnalyticsListener`, `TreeListener`

#### Scenario: Lifecycle listeners are registered for NC events

- **GIVEN** MyDash is bootstrapped
- **WHEN** a `\OCP\User\Events\UserDeletedEvent` is dispatched by Nextcloud core
- **THEN** MyDash's `UserDeletedListener` MUST be invoked
- **AND** when a `\OCP\Group\Events\GroupDeletedEvent` is dispatched
- **THEN** MyDash's `GroupDeletedListener` MUST be invoked

#### Scenario: New listener can be added without editing existing code

- **GIVEN** a developer creates `lib/Listener/FavoritesListener.php` implementing `IEventListener`
- **WHEN** they add one line to `Application::register()` registering it for `DashboardDeletedEvent::class`
- **THEN** it MUST be invoked on every subsequent dashboard deletion — no changes to other listener files, `DashboardService`, or the event class are required

### Requirement: REQ-CSC-006 Failure Isolation

A failure in one listener MUST NOT prevent other listeners from executing. Every listener MUST catch all `\Throwable` and continue.

#### Scenario: One listener throws — others still execute

- **GIVEN** `DashboardDeletedEvent` fires for `D1`
- **AND** `ReactionsListener` throws a `\RuntimeException` during its execution
- **WHEN** the event is dispatched
- **THEN** `WidgetPlacementsListener`, `CommentsListener`, `LocksListener`, and all other registered listeners MUST still execute
- **AND** the exception from `ReactionsListener` MUST NOT propagate beyond its own catch block
- **AND** `DashboardService::delete()` MUST complete and return a response

#### Scenario: Listener failure is logged at WARN level

- **GIVEN** a listener throws during handling of `DashboardDeletedEvent`
- **WHEN** the catch block executes
- **THEN** it MUST call `ILogger::warning(...)` (or equivalent) with the listener class name, dashboard UUID, and exception message
- **AND** MUST NOT call `ILogger::error()` (a cascade failure is recoverable via orphan-cleanup — it is not a fatal error)

#### Scenario: Multiple listener failures do not prevent response

- **GIVEN** 3 out of 10 listeners throw exceptions
- **WHEN** the dispatch completes
- **THEN** `DashboardService::delete()` MUST still return the HTTP response with `cascadeStats`
- **AND** `cascadeStats` MUST accurately reflect counts from the 7 listeners that succeeded
- **AND** the 3 failures MUST each be logged at WARN level with full context (listener class, dashboard UUID, exception message)

### Requirement: REQ-CSC-007 Failure Handling — Log-and-Continue

When a listener fails, the failure MUST be logged at WARN level with full context. No separate failure-recording table is required.

#### Scenario: Listener failure is fully logged before continuing

- **GIVEN** `MetadataValuesListener` throws while handling a `DashboardDeletedEvent` for UUID `abc-123`
- **WHEN** the catch block runs
- **THEN** `ILogger::warning()` MUST be called with at minimum: the listener class name, the dashboard UUID `abc-123`, and the exception message
- **AND** execution MUST continue — no exception MUST propagate out of the listener's catch block

#### Scenario: No failure-recording table migration is required

- **GIVEN** the implementation of this change
- **WHEN** the migration list is reviewed
- **THEN** there MUST be no migration adding an `oc_mydash_cascade_failures` table
- **AND** the orphan-cleanup job MUST identify stragglers by querying dependent tables directly (e.g., `oc_mydash_widget_placements` joined against soft-deleted dashboards)
