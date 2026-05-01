# Dashboard Cascade Events

## Why

When a dashboard is deleted in MyDash, dependent data rows in other tables (widget placements, reactions, locks, versions, comments, shares, metadata values, translations, view analytics) become orphaned. Likewise, when a Nextcloud user or group is deleted, their personal dashboards and all downstream records are left behind. Today there is no automated mechanism to remove this dependent data at deletion time — it accumulates silently and can only be addressed by the separate `orphaned-data-cleanup` job after the fact.

This change introduces an event-driven cascade system: `DashboardService::delete()` dispatches a `DashboardDeletedEvent` after soft-deleting the dashboard row, and each subsystem that owns dependent data registers a dedicated listener to clean up its own table. User and group lifecycle events from Nextcloud are handled by `UserDeletedListener` and `GroupDeletedListener` respectively, which drive cascade deletion of all associated dashboards (which in turn fire further `DashboardDeletedEvent` dispatches).

The design ensures each listener is independently isolated — a failure in one MUST NOT block the others — and every operation is idempotent, making the orphan-cleanup job safe to retry any residual failures.

## What Changes

- Add `lib/Event/DashboardDeletedEvent.php` carrying `{dashboardUuid, ownerUserId, type, deletedAt}`.
- Fire `DashboardDeletedEvent` from `DashboardService::delete()` after soft-delete, before returning the response.
- Add ten listeners under `lib/Listener/` for `DashboardDeletedEvent`:
  - `WidgetPlacementsListener` — deletes `oc_mydash_widget_placements` rows.
  - `CommentsListener` — calls `ICommentsManager::deleteCommentsAtObject('mydash_dashboard', $uuid)`.
  - `ReactionsListener` — deletes `oc_mydash_dashboard_reactions` rows.
  - `LocksListener` — deletes `oc_mydash_dashboard_locks` rows.
  - `VersionsListener` — deletes `oc_mydash_dashboard_versions` rows (DB mode) and the JSON version file (GroupFolder mode).
  - `PublicSharesListener` — soft-revokes rows in `oc_mydash_public_shares`.
  - `MetadataValuesListener` — deletes `oc_mydash_metadata_values` rows for the dashboard.
  - `TranslationsListener` — deletes `oc_mydash_dashboard_translations` rows for the dashboard.
  - `ViewAnalyticsListener` — deletes `oc_mydash_dashboard_views` rows for the dashboard.
  - `TreeListener` — on cascade-delete, recursively dispatches `DashboardDeletedEvent` for each child dashboard.
- Add `UserDeletedListener` subscribing to NC's `\OCP\User\Events\UserDeletedEvent`.
- Add `GroupDeletedListener` subscribing to NC's `\OCP\Group\Events\GroupDeletedEvent`.
- Add a new `oc_mydash_cascade_failures` table (one migration) for recording listener failures to allow orphan-cleanup retry.
- Register all listeners in `Application` via `IEventDispatcher::addListener`.
- Extend the `DashboardService::delete()` response to include `{deletedAt, cascadeStats: {...}}`.

## Capabilities

### New Capabilities

- `dashboard-cascade-events`: provides REQ-CSC-001 through REQ-CSC-010 covering event definition, listener registry, widget/asset listener group, user/group lifecycle listeners, failure isolation, failure recording, idempotency, cascade stats response, tree recursion, and listener registration.

### Modified Capabilities

- `dashboards`: `DashboardService::delete()` now dispatches `DashboardDeletedEvent` and returns `cascadeStats` in the response. Existing REQ-DASH-005 delete semantics are preserved.

## Impact

**Affected code:**

- `lib/Event/DashboardDeletedEvent.php` — new event class
- `lib/Listener/WidgetPlacementsListener.php` — new
- `lib/Listener/CommentsListener.php` — new
- `lib/Listener/ReactionsListener.php` — new
- `lib/Listener/LocksListener.php` — new
- `lib/Listener/VersionsListener.php` — new
- `lib/Listener/PublicSharesListener.php` — new
- `lib/Listener/MetadataValuesListener.php` — new
- `lib/Listener/TranslationsListener.php` — new
- `lib/Listener/ViewAnalyticsListener.php` — new
- `lib/Listener/TreeListener.php` — new
- `lib/Listener/UserDeletedListener.php` — new
- `lib/Listener/GroupDeletedListener.php` — new
- `lib/Service/DashboardService.php` — dispatch event post-delete; extend response with `cascadeStats`
- `lib/Migration/VersionXXXXDate2026AddCascadeFailuresTable.php` — adds `oc_mydash_cascade_failures`
- `lib/AppInfo/Application.php` — register all 13 listeners via `IEventDispatcher`

**Affected APIs:**

- `DELETE /api/dashboards/{uuid}` response body gains `cascadeStats` (additive; backward-compatible for clients that ignore unknown fields)
- No new routes

**Dependencies:**

- `OCP\EventDispatcher\IEventDispatcher` — already present in NC core
- `OCP\Comments\ICommentsManager` — for NC comments integration
- `OCP\User\Events\UserDeletedEvent` and `OCP\Group\Events\GroupDeletedEvent` — NC lifecycle events
- `OCP\ILogger` — for WARN-level failure logging
- No new composer or npm dependencies

**Migration:**

- Adds `oc_mydash_cascade_failures` table (columns: `id`, `listener_class`, `dashboard_uuid`, `error_message`, `failed_at`).
- Existing rows are unaffected; migration is reversible (drop table on rollback).
