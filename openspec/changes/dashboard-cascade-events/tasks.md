# Tasks — dashboard-cascade-events

## Tasks

- [ ] Task 1: Create `lib/Event/DashboardDeletedEvent.php` extending `\OCP\EventDispatcher\Event` with constructor parameters `(string $dashboardUuid, string $ownerUserId, string $type, \DateTimeImmutable $deletedAt)` and read-only getter methods; add a docblock linking REQ-CSC-001

- [ ] Task 2: Modify `lib/Service/DashboardService.php::delete(uuid, cascade=false)` to validate children before any changes: if children exist and `cascade=false`, throw `\Exception` with message "Dashboard has child dashboards; use cascade=true to delete them"; return early before soft-delete

- [ ] Task 3: In `DashboardService::delete()`, after validation passes, soft-delete the dashboard row: `UPDATE oc_mydash_dashboards SET deletedAt = NOW() WHERE uuid = ?`; inject `IEventDispatcher` and dispatch `DashboardDeletedEvent` immediately after soft-delete, before returning the response

- [ ] Task 4: Create `lib/Listener/WidgetPlacementsListener.php` implementing `IEventListener` and `IEventListener::handle(Event $event)`: cast to `DashboardDeletedEvent`, wrap in try/catch, run `DELETE FROM oc_mydash_widget_placements WHERE dashboardUuid = ?`, log on exception at WARN level with listener class + UUID + message, return the count of affected rows, or 0 if exception occurred

- [ ] Task 5: Create `lib/Listener/CommentsListener.php` implementing `IEventListener`: inject `ICommentsManager`, cast event to `DashboardDeletedEvent`, call `$commentsManager->deleteCommentsAtObject('mydash_dashboard', $event->getDashboardUuid())`, wrap in try/catch with WARN-level logging, return the count of deleted comments (or 0 on exception)

- [ ] Task 6: Create `lib/Listener/ReactionsListener.php` implementing `IEventListener`: cast event to `DashboardDeletedEvent`, wrap in try/catch, run `DELETE FROM oc_mydash_reactions WHERE dashboardUuid = ?`, log exceptions at WARN level, return affected row count or 0

- [ ] Task 7: Create `lib/Listener/LocksListener.php` implementing `IEventListener`: cast event, run `DELETE FROM oc_mydash_locks WHERE dashboardUuid = ?`, wrap in try/catch with WARN logging, return affected row count or 0

- [ ] Task 8: Create `lib/Listener/MetadataValuesListener.php` implementing `IEventListener`: cast event, run `DELETE FROM oc_mydash_metadata_values WHERE dashboardUuid = ?`, wrap in try/catch with WARN logging, return affected row count or 0

- [ ] Task 9: Create `lib/Listener/TranslationsListener.php` implementing `IEventListener`: cast event, run `DELETE FROM oc_mydash_translations WHERE dashboardUuid = ?`, wrap in try/catch with WARN logging, return affected row count or 0

- [ ] Task 10: Create `lib/Listener/ViewAnalyticsListener.php` implementing `IEventListener`: cast event, run `DELETE FROM oc_mydash_view_analytics WHERE dashboardUuid = ?`, wrap in try/catch with WARN logging, return affected row count or 0

- [ ] Task 11: Create `lib/Listener/PublicSharesListener.php` implementing `IEventListener`: cast event, run `UPDATE oc_mydash_public_shares SET revokedAt = NOW() WHERE dashboardUuid = ? AND revokedAt IS NULL`, wrap in try/catch with WARN logging, return the count of updated rows or 0

- [ ] Task 12: Create `lib/Listener/VersionsListener.php` implementing `IEventListener`: cast event, delete DB rows `DELETE FROM oc_mydash_dashboard_versions WHERE dashboardUuid = ?`, if GroupFolder storage is enabled, delete the JSON file from `<groupfolder>/MyDash/versions/{uuid}.json` (treat file-not-found as no-op), wrap entire logic in try/catch with WARN logging, return count of DB rows deleted or 0

- [ ] Task 13: Create `lib/Listener/TreeListener.php` implementing `IEventListener`: cast event to `DashboardDeletedEvent`, query `SELECT uuid FROM oc_mydash_dashboards WHERE parentId = ? AND deletedAt IS NULL` to find children, for each child, inject `IEventDispatcher` and dispatch a new `DashboardDeletedEvent` with the child's UUID, wrap in try/catch with WARN logging, return successfully (this listener does not delete rows itself; it dispatches child events)

- [ ] Task 14: Modify `DashboardService::delete()` to collect cascade stats: after event dispatch completes, gather return values from all listeners (each listener returns an affected-row count), build a `cascadeStats` array with keys: `widgetPlacementsDeleted`, `commentsDeleted`, `reactionsDeleted`, `locksDeleted`, `versionsDeleted`, `sharesRevoked`, `metadataValuesDeleted`, `translationsDeleted`, `viewsDeleted`, include these stats in the delete response along with `deletedAt`

- [ ] Task 15: Create `lib/Listener/UserDeletedListener.php` implementing `IEventListener`: responds to `\OCP\User\Events\UserDeletedEvent`, inject `DashboardService` and `IDBConnection`, query `SELECT uuid FROM oc_mydash_dashboards WHERE ownerUserId = ? AND type = 'user'`, for each owned dashboard, call `DashboardService::delete(uuid)` to cascade cleanup, then delete role assignments `DELETE FROM oc_mydash_role_assignments WHERE userId = ?`, soft-revoke feed tokens `UPDATE oc_mydash_feed_tokens SET revokedAt = NOW() WHERE userId = ? AND revokedAt IS NULL`, delete IConfig analytics pref `$this->config->deleteUserValue(userId, 'mydash', 'analyticsOptOut')`, wrap entire logic in try/catch with WARN logging

- [ ] Task 16: Create `lib/Listener/GroupDeletedListener.php` implementing `IEventListener`: responds to `\OCP\Group\Events\GroupDeletedEvent`, inject `DashboardService`, `IDBConnection`, `IConfig`, query `SELECT uuid FROM oc_mydash_dashboards WHERE ownerUserId = ? AND type = 'group_shared'` (where ownerUserId is the group identifier), for each, call `DashboardService::delete(uuid)`, then read `mydash.org_navigation_tree` from IConfig as JSON, remove the group ID from all `groupVisibility` arrays, write back to IConfig, read `mydash.group_order` as JSON array, remove the group ID, write back, wrap in try/catch with WARN logging

- [ ] Task 17: Modify `Application.php::register()` to register all 12 listeners with `IEventDispatcher`: add lines for `DashboardDeletedEvent` (ten listeners: WidgetPlacements, Comments, Reactions, Locks, Versions, PublicShares, MetadataValues, Translations, ViewAnalytics, Tree), `UserDeletedEvent` (UserDeleteListener), `GroupDeletedEvent` (GroupDeletedListener); each registration is a single `$dispatcher->addListener(EventClass::class, [ContainerOrClass::class, 'method'], priority)` call

- [ ] Task 18: Vitest — `DashboardDeletedEvent` constructor and getters return correct values; event extends `\OCP\EventDispatcher\Event`

- [ ] Task 19: Vitest — `WidgetPlacementsListener`, `CommentsListener`, and other listeners catch `\Throwable`, log at WARN level, and return gracefully without propagating exceptions; verify log entry includes listener class name, UUID, and exception message

- [ ] Task 20: Vitest — each listener is idempotent: running it twice on already-cleaned data affects 0 rows the second time and does not throw

- [ ] Task 21: Vitest — `TreeListener` queries children, dispatches event for each child (mock `IEventDispatcher`), and returns gracefully; verify it dispatches correct number of events

- [ ] Task 22: Vitest — `UserDeletedListener` queries owned dashboards, calls `DashboardService::delete()` for each, and handles missing dashboards gracefully (e.g., already deleted)

- [ ] Task 23: Vitest — `GroupDeletedListener` queries group dashboards, cascades delete, removes group from IConfig JSON arrays (`org_navigation_tree`, `group_order`), and leaves other entries untouched; verify JSON mutation does not corrupt invalid/missing keys

- [ ] Task 24: Vitest — `DashboardService::delete()` rejects non-cascade delete when children exist (returns HTTP 400, no soft-delete, no event dispatch); cascade delete succeeds and dispatches event

- [ ] Task 25: Vitest — multiple listener failures (throw exceptions) do NOT prevent other listeners from executing; `DashboardService::delete()` returns response with cascadeStats from successful listeners, and all failures are logged separately

- [ ] Task 26: Vitest — `cascadeStats` response includes all 9 keys with counts from successful listeners; failed listeners report 0 (or omitted, but consistent); tree deletion aggregates child counts correctly

- [ ] Task 27: Vitest — tree deletion with 1 parent and 2 children dispatches 3 events total (1 parent + 2 children), aggregates stats from all 3, and includes total in response

- [ ] Task 28: Playwright — create a dashboard with 5 widgets, 2 comments (via NC comments system), add 1 reaction, add 1 lock, create 1 public share; delete it without cascade (no children), verify 2xx response, verify cascadeStats shows all counts > 0, verify dashboard soft-deleted in DB, verify no widgets/comments/etc. in dependent tables

- [ ] Task 29: Playwright — create parent dashboard P with children C1, C2, both with widgets/comments; try `DELETE /api/dashboards/P` without cascade → verify 400 error, verify nothing deleted; retry with `?cascade=true` → verify all soft-deleted, all dependent data removed, stats aggregated

- [ ] Task 30: Playwright — delete a user with owned dashboards (personal) → verify `UserDeletedListener` fires, all user's dashboards cascade-deleted, feed tokens revoked (not hard-deleted), role assignments removed, IConfig analytics pref removed

- [ ] Task 31: Playwright — delete a group with group-shared dashboards → verify `GroupDeletedListener` fires, all group dashboards cascade-deleted, group removed from `org_navigation_tree` JSON (other groups untouched), group removed from `group_order` array

- [ ] Task 32: Quality — ESLint + PHPStan clean on all new listener files, `DashboardService`, and `Application`; `composer check:strict` passes; run `openspec validate` and confirm all specs pass

- [ ] Task 33: Documentation — add inline docblock to `DashboardDeletedEvent` and each listener linking to REQ-CSC-XXX; update changelog with high-level summary of cascade event architecture; add a reference doc explaining listener failure recovery and orphan-cleanup integration (in docs/ or inline)

- [ ] Task 34: i18n — no new user-facing strings expected (cascade stats keys are internal JSON); if error messages are added (e.g., "has children"), add to both `translations/nl.php` and `translations/en.php`

## Verification

`openspec validate` exits clean. All 12 listeners are registered, all cascade scenarios work end-to-end, failures are isolated and logged, partial failures do not block the response, and cascadeStats are accurate across simple and tree deletions.

## Tests (company-wide ADR-009)

Vitest per Tasks 18–27 (event, all listeners, idempotency, user/group lifecycle, partial failure, cascadeStats); Playwright per Tasks 28–31 (dashboard delete, cascade validation, tree cascade, user/group lifecycle); no new backend API surface beyond the modified `DashboardService::delete()` response shape.

## Documentation (company-wide ADR-010)

Inline docblocks per Task 33; changelog entry summarizing the cascade event architecture (10 dependent-data listeners, user/group lifecycle cleanup, tree cascade, cascade stats response). Optional: separate reference doc explaining listener failure recovery (how orphan-cleanup identifies and retries failed cascades).

## i18n (company-wide ADR-007)

No new user-facing strings expected (cascadeStats keys are internal JSON response keys); if error messages are added (e.g., validation reject message for children), parity in `nl`+`en` per Task 34.
