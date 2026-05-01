# Tasks — dashboard-cascade-events

## 1. Event class

- [ ] 1.1 Create `lib/Event/DashboardDeletedEvent.php` extending `\OCP\EventDispatcher\Event` with constructor arguments `(string $dashboardUuid, string $ownerUserId, string $type, \DateTimeImmutable $deletedAt)`
- [ ] 1.2 Add read-only getters: `getDashboardUuid()`, `getOwnerUserId()`, `getType()`, `getDeletedAt()`
- [ ] 1.3 Add SPDX docblock header (SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock per convention)

## 2. Migration — cascade failures table

- [ ] 2.1 Create `lib/Migration/VersionXXXXDate2026AddCascadeFailuresTable.php` adding `oc_mydash_cascade_failures` with columns: `id` (BIGINT auto-increment PK), `listener_class` (VARCHAR 255), `dashboard_uuid` (VARCHAR 36), `error_message` (TEXT), `failed_at` (DATETIME)
- [ ] 2.2 Add index `idx_mydash_cascade_fail_uuid` on `(dashboard_uuid)` for efficient orphan-cleanup lookups
- [ ] 2.3 Confirm migration is reversible (drop table in rollback path)
- [ ] 2.4 Run migration locally against sqlite, mysql, and postgres; verify schema applies cleanly

## 3. DashboardDeletedEvent listeners

- [ ] 3.1 Create `lib/Listener/WidgetPlacementsListener.php` implementing `\OCP\EventDispatcher\IEventListener`
  - [ ] `handle()`: DELETE from `oc_mydash_widget_placements` WHERE `dashboardUuid = $event->getDashboardUuid()`; wrap in try/catch — on failure log WARN and record to `oc_mydash_cascade_failures`

- [ ] 3.2 Create `lib/Listener/CommentsListener.php`
  - [ ] `handle()`: call `ICommentsManager::deleteCommentsAtObject('mydash_dashboard', $event->getDashboardUuid())`; wrap in try/catch

- [ ] 3.3 Create `lib/Listener/ReactionsListener.php`
  - [ ] `handle()`: DELETE from `oc_mydash_dashboard_reactions` WHERE `dashboardUuid = ?`; wrap in try/catch

- [ ] 3.4 Create `lib/Listener/LocksListener.php`
  - [ ] `handle()`: DELETE from `oc_mydash_dashboard_locks` WHERE `dashboardUuid = ?`; wrap in try/catch

- [ ] 3.5 Create `lib/Listener/VersionsListener.php`
  - [ ] `handle()`: DELETE from `oc_mydash_dashboard_versions` WHERE `dashboardUuid = ?`; also delete any JSON version file in GroupFolder mode (check via IConfig whether GroupFolder backend is active); wrap in try/catch

- [ ] 3.6 Create `lib/Listener/PublicSharesListener.php`
  - [ ] `handle()`: UPDATE `oc_mydash_public_shares` SET `revokedAt = NOW()` WHERE `dashboardUuid = ?` AND `revokedAt IS NULL`; wrap in try/catch

- [ ] 3.7 Create `lib/Listener/MetadataValuesListener.php`
  - [ ] `handle()`: DELETE from `oc_mydash_metadata_values` WHERE `dashboardUuid = ?`; wrap in try/catch

- [ ] 3.8 Create `lib/Listener/TranslationsListener.php`
  - [ ] `handle()`: DELETE from `oc_mydash_dashboard_translations` WHERE `dashboardUuid = ?`; wrap in try/catch

- [ ] 3.9 Create `lib/Listener/ViewAnalyticsListener.php`
  - [ ] `handle()`: DELETE from `oc_mydash_dashboard_views` WHERE `dashboardUuid = ?`; wrap in try/catch

- [ ] 3.10 Create `lib/Listener/TreeListener.php`
  - [ ] `handle()`: query `oc_mydash_dashboards` for children WHERE `parentUuid = $event->getDashboardUuid()`; for each child, dispatch a new `DashboardDeletedEvent` via `IEventDispatcher`; wrap in try/catch

## 4. Lifecycle listeners

- [ ] 4.1 Create `lib/Listener/UserDeletedListener.php` subscribing to `\OCP\User\Events\UserDeletedEvent`
  - [ ] `handle()`: query all personal dashboards for `$event->getUser()->getUID()`; for each, call `DashboardService::delete()` which triggers further cascade; DELETE from `oc_mydash_role_assignments` WHERE `userId = ?`; UPDATE `oc_mydash_feed_tokens` SET `revokedAt = NOW()` WHERE `userId = ?`; delete analytics opt-out preference via IConfig; wrap full method in try/catch, log errors at WARN

- [ ] 4.2 Create `lib/Listener/GroupDeletedListener.php` subscribing to `\OCP\Group\Events\GroupDeletedEvent`
  - [ ] `handle()`: query all group-shared dashboards for `$event->getGroup()->getGID()`; for each, call `DashboardService::delete()`; DELETE from `oc_mydash_role_assignments` WHERE `groupId = ?`; remove the group from `groupVisibility` arrays in `mydash.org_navigation_tree` (read JSON, filter, rewrite via IConfig); remove the group from `mydash.group_order` JSON array; wrap in try/catch

## 5. DashboardService integration

- [ ] 5.1 In `DashboardService::delete()`, after soft-deleting the dashboard row (before returning), inject and call `IEventDispatcher::dispatchTyped(new DashboardDeletedEvent(...))`
- [ ] 5.2 Collect `cascadeStats` from listeners: each listener SHOULD record its deletion count via a shared stats accumulator (e.g., carried on the event object or a service-scoped collector); `DashboardService::delete()` returns `{deletedAt, cascadeStats: {widgetPlacementsDeleted: N, commentsDeleted: N, reactionsDeleted: N, locksDeleted: N, versionsDeleted: N, sharesRevoked: N, metadataValuesDeleted: N, translationsDeleted: N, viewsDeleted: N}}`
- [ ] 5.3 Confirm that validation that rejects non-cascade delete when children exist still runs BEFORE the event dispatch (validation → soft-delete → dispatch)

## 6. Listener registration

- [ ] 6.1 In `lib/AppInfo/Application.php`, register all ten `DashboardDeletedEvent` listeners using `IEventDispatcher::addListener(DashboardDeletedEvent::class, ListenerClass::class)`
- [ ] 6.2 Register `UserDeletedListener` for `\OCP\User\Events\UserDeletedEvent::class`
- [ ] 6.3 Register `GroupDeletedListener` for `\OCP\Group\Events\GroupDeletedEvent::class`

## 7. Failure recording helper

- [ ] 7.1 Create `lib/Service/CascadeFailureRecorder.php` with method `record(string $listenerClass, string $dashboardUuid, \Throwable $error): void` — inserts into `oc_mydash_cascade_failures`; used by every listener's catch block
- [ ] 7.2 Confirm that `CascadeFailureRecorder::record()` itself is wrapped in a try/catch so a DB failure in the recorder does not cause further exceptions

## 8. PHPUnit tests

- [ ] 8.1 `DashboardDeletedEventTest` — verify all getters return correct constructor values
- [ ] 8.2 `WidgetPlacementsListenerTest` — success path deletes correct rows; failure path logs WARN and calls `CascadeFailureRecorder::record()` without throwing
- [ ] 8.3 `PublicSharesListenerTest` — verify soft-revoke (not hard delete); idempotency (re-run on already-revoked rows is a no-op)
- [ ] 8.4 `TreeListenerTest` — cascade: child dashboards each receive their own `DashboardDeletedEvent`; no-op when no children
- [ ] 8.5 `UserDeletedListenerTest` — deletes personal dashboards, role assignments, feed tokens; leaves other users' data untouched
- [ ] 8.6 `GroupDeletedListenerTest` — removes group from `groupVisibility` JSON; removes from `group_order`; cascades dashboard deletes
- [ ] 8.7 `DashboardServiceDeleteTest` — event is dispatched after soft-delete; response includes `cascadeStats`; cascade is NOT triggered on a validation-rejected delete

## 9. End-to-end Playwright tests

- [ ] 9.1 Delete a dashboard that has widget placements and verify placements table is empty afterwards
- [ ] 9.2 Delete a dashboard that has child dashboards (cascade mode) and verify all children are also soft-deleted and each fired its own cascade
- [ ] 9.3 Simulate a listener failure (mock one listener to throw); verify other listeners still execute and failure row appears in `oc_mydash_cascade_failures`
- [ ] 9.4 Delete a Nextcloud user and verify their personal dashboards and role assignments are removed
- [ ] 9.5 Confirm cascade stats in the delete API response match actual row counts deleted

## 10. Quality gates

- [ ] 10.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 10.2 SPDX headers in every new PHP file (inside docblock per convention)
- [ ] 10.3 `i18n` keys for cascade failure log messages in both `nl` and `en`
- [ ] 10.4 Run all `hydra-gates` locally before opening PR
- [ ] 10.5 Confirm `oc_mydash_cascade_failures` migration is reversible and tested on sqlite + mysql + postgres
