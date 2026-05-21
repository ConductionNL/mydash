# Dashboard Cascade Events Specification

When a MyDash dashboard is deleted, all dependent data (widget placements, comments, reactions, locks, versions, public shares, metadata values, translations, view analytics, child dashboards) MUST be automatically removed via an event-listener architecture. When a Nextcloud user or group is deleted, their associated dashboards and downstream records MUST likewise be cleaned up. This change defines the `DashboardDeletedEvent` event, the listener registry with ten cascade listeners, failure isolation with log-and-continue semantics, idempotency guarantees, cascade stats reporting, and tree cascade validation.

## Affected code units

- `lib/Event/DashboardDeletedEvent.php` — new event class carrying dashboard UUID, owner, type, and deletion timestamp
- `lib/Listener/` — ten new listener classes: `WidgetPlacementsListener`, `CommentsListener`, `ReactionsListener`, `LocksListener`, `VersionsListener`, `PublicSharesListener`, `MetadataValuesListener`, `TranslationsListener`, `ViewAnalyticsListener`, `TreeListener`
- `lib/Listener/UserDeletedListener.php` — enumerate and cascade-delete personal dashboards, revoke feed tokens, clear role assignments
- `lib/Listener/GroupDeletedListener.php` — cascade-delete group-shared dashboards, remove group from org navigation tree and group order settings
- `lib/Service/DashboardService.php` — modify `delete()` to soft-delete, dispatch event, return cascade stats; add cascade validation guard
- `Application.php` — register all 12 listeners with `IEventDispatcher`

## Why a delta

Dashboard deletion is a critical data integrity operation. Today, deletion is incomplete — some dependent tables are cleaned up inline (or not at all), others are orphaned, and child dashboards are silently wiped without firing their own delete events. This leaves orphaned rows in analytics, locks, reactions tables and masks the hierarchy's full cleanup scope. The event-listener pattern (proven in Nextcloud core) makes cascade behaviour explicit, testable, and isolated from the core delete logic. Each listener is independent — a failure in one (e.g., file I/O during version cleanup) MUST NOT prevent the others from running, and MUST be recoverable via the orphan-cleanup job (separately tracked). Cascade validation guards against accidental subtree deletion of dashboards with children.

## Approach

- **Event definition** — `DashboardDeletedEvent` carries UUID, owner, type, and deletion timestamp. Listeners inject dependencies (managers, services, logger) to perform targeted cleanup.
- **Listener architecture** — each listener implements `IEventListener`, handles one dependent data class, catches all `\Throwable`, logs failures at WARN level (not ERROR — failures are recoverable), and returns gracefully. Listeners are registered once in `Application::register()` via simple `addListener()` calls.
- **User/Group lifecycle** — `UserDeletedListener` and `GroupDeletedListener` respond to Nextcloud core events. User deletion calls `DashboardService::delete()` for each owned personal dashboard (cascading dependent-data cleanup via full listener stack). Group deletion does the same for group-shared dashboards and removes group identifiers from IConfig JSON settings.
- **Tree cascade** — `TreeListener` dispatches a new `DashboardDeletedEvent` for each child dashboard, ensuring the full listener stack runs for every node. Non-cascade deletes with children are rejected before any event dispatch.
- **Cascade stats** — `DashboardService::delete()` returns a response including `cascadeStats` with counts (widgetPlacementsDeleted, commentsDeleted, reactionsDeleted, etc.). Tree deletions aggregate counts from all child events.
- **Idempotency** — every listener uses idempotent queries (DELETE WHERE, UPDATE WHERE ... IS NULL) that safely run twice without error.

## Capabilities

**New Capabilities:**

- `event-dispatch` (new) — defines `DashboardDeletedEvent` and listener pattern
- `dashboard-lifecycle` (new) — user/group deletion handlers with cascade
- `cascade-operations` (new) — dependent-data cleanup semantics, stats reporting, validation guard

**Modified Capabilities:**

- `dashboard-crud` (modifies to add cascade validation and stats response)

## Notes

- `TreeListener` is a MyDash improvement — the reference implementation (Nextcloud core's wiki page delete) uses a recursive filesystem operation that does NOT fire the delete event for each child, leaving child-level DB rows orphaned. MyDash's explicit event dispatch per child is more correct and enables proper cascade cleanup at every level.
- `CommentsListener` follows the reference pattern directly (calling `ICommentsManager::deleteCommentsAtObject()`); the other nine listeners are MyDash-specific because MyDash's schema is richer than the reference's minimal page-only design.
- Failure recording via a dedicated `oc_mydash_cascade_failures` table has been DROPPED in favour of log-and-continue. The orphan-cleanup job identifies stragglers by querying dependent tables directly, making an explicit failures table redundant.
- WARN-level logging on listener failure is deliberate (not ERROR). Cascade failures are transient and recoverable; they do not represent fatal errors.
