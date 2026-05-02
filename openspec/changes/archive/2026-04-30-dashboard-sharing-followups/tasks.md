# Tasks — dashboard-sharing-followups

## 1. Notifier service

- [ ] 1.1 Create `lib/Notification/Notifier.php` implementing `OCP\Notification\INotifier`
  - `getID()` returns `'mydash'`
  - `getName()` returns `'MyDash'` (translatable)
  - `prepare(INotification, $languageCode)` handles two subjects: `dashboard_shared`, `dashboard_ownership_transferred`
  - For unknown subjects throw `\OCP\Notification\UnknownNotificationException`
  - Use `IURLGenerator::linkToRouteAbsolute('mydash.page.index')` plus a `?dashboard={uuid}` query for the deep link
- [ ] 1.2 Use `IFactory::get('mydash')` for translations of the rendered strings:
  - `dashboard_shared`: rich subject "Alice shared **Marketing Overview** with you" + parsed message "{permissionLevel} access"
  - `dashboard_ownership_transferred`: rich subject "**Marketing Overview** is now yours" + parsed message "Ownership transferred after the previous owner was removed"
- [ ] 1.3 Register the notifier in `lib/AppInfo/Application.php::register()` via `$context->registerNotifierService(Notifier::class)`
- [ ] 1.4 Add unit test covering both subjects, English + at least one other locale (Dutch since project ships nl/en)

## 2. Notification publishing

- [ ] 2.1 Inject `OCP\Notification\IManager` into `DashboardShareService`
- [ ] 2.2 Extract a private `_persistShare(int $dashboardId, string $shareType, string $shareWith, string $level): array{share: DashboardShare, isNew: bool, isUpgrade: bool}` from the current `addShare`
- [ ] 2.3 Add a private `_notifyShared(DashboardShare $share, string $sharerUserId, string $dashboardName)` that:
  - For `share_type='user'`: creates one `INotification` for that single recipient
  - For `share_type='group'`: resolves group members at publish time via `IGroupManager::getDisplayNames()` (or members iterator) and creates one notification per current member, excluding the sharer
  - Sets `setSubject('dashboard_shared', [sharerUserId, dashboardName, permissionLevel])` and `setObject('dashboard', (string) $dashboardId)`
- [ ] 2.4 The current `addShare()` becomes: `_persistShare(...)` THEN `_notifyShared(...)` only when `isNew === true || isUpgrade === true` (not on downgrades, not on no-op writes)
- [ ] 2.5 The `removeShare()` path does NOT publish anything (revocations are silent — user can re-discover absence by reopening the menu)
- [ ] 2.6 Add unit test: mock `IManager`, assert exactly one notification per fan-out target, assert no notification on level downgrade

## 3. Bulk replace endpoint

- [ ] 3.1 Add `DashboardShareService::replaceShares(int $dashboardId, array $shares, string $userId): array` that:
  - Asserts caller is owner
  - Validates each entry's `shareType`, `shareWith`, `permissionLevel`
  - Loads existing shares once
  - In a single transaction: deletes shares not in payload, upserts the rest
  - Returns the new full list
  - Calls `_notifyShared` only for entries where `isNew === true || isUpgrade === true`
- [ ] 3.2 Add `DashboardShareApiController::replace(int $id, ?array $shares)` action — same `#[NoAdminRequired]` attribute, same userId guard pattern as the existing `create`
- [ ] 3.3 Register route `PUT /api/dashboard/{id}/shares` in `appinfo/routes.php`
- [ ] 3.4 Add `api.replaceShares(dashboardId, shares)` to `src/services/api.js`
- [ ] 3.5 Update `DashboardConfigModal.vue`: replace the per-row immediate `addShare`/`removeShare` calls with a `pendingShares` data array. Save button posts via `replaceShares` and reloads. Cancel reverts to the server snapshot.
- [ ] 3.6 Add backend integration test covering: add+remove+upgrade in one PUT; idempotent re-PUT with same payload publishes 0 notifications

## 4. Revoke-all-for-recipient

- [ ] 4.1 Add `DashboardShareService::revokeAllForRecipient(string $shareType, string $shareWith, string $callerId): int` that:
  - Joins `oc_mydash_dashboard_shares` to `oc_mydash_dashboards` on `dashboardId = id` filtered to `dashboards.user_id = callerId`
  - Deletes all matching rows in one statement, returns the affected row count
- [ ] 4.2 Add `DashboardShareApiController::revokeForRecipient(string $shareType, string $shareWith)`
- [ ] 4.3 Register route `DELETE /api/sharees/{shareType}/{shareWith}` in `appinfo/routes.php`
- [ ] 4.4 Add `api.revokeAllForRecipient(shareType, shareWith)` to `src/services/api.js`
- [ ] 4.5 No UI surface in this change — exposed for admin tools / scripted cleanup. Frontend wiring deferred.

## 5. UserDeletedEvent listener with admin retention

- [ ] 5.1 Create `lib/Listener/UserDeletedListener.php` implementing `IEventListener<UserDeletedEvent>`
- [ ] 5.2 Register in `lib/AppInfo/Application.php::register()` via `$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class)`
- [ ] 5.3 In `handle()`:
  - Resolve `$userId = $event->getUser()->getUID()`
  - **Step A**: delete every share where `share_type='user' AND share_with=$userId` (recipient cleanup)
  - **Step B**: for every dashboard owned by `$userId`:
    - Compute admin pool: load every share with `permission_level='full'`; for `user`-type entries the userId is added directly; for `group`-type entries, expand via `IGroupManager::get($groupId)->searchUsers('', 1000)` capped at 1000 per group
    - Filter the admin pool to existing users (skip already-deleted accounts)
    - **If pool is empty**: delete the dashboard, its placements, and its shares (existing `deleteDashboard` logic via `DashboardService`)
    - **If pool is non-empty**: pick the new owner using the rule below, transfer ownership, delete only the new owner's matching share row, publish a `dashboard_ownership_transferred` notification to them
  - All Step B work runs in a single DB transaction per dashboard
- [ ] 5.4 New-owner selection rule (codified):
  1. Among `user`-type shares with `permission_level='full'`, take the one with the smallest `created_at`
  2. If none, expand the alphabetically-first `group`-type share's member list and pick the alphabetically-first uid still active
  3. If both fail (pool became empty between resolve and pick), fall through to delete
- [ ] 5.5 Add `DashboardShareService::transferOwnership(int $dashboardId, string $newUserId): void`:
  - Updates the dashboard's `user_id` and stamps `updated_at`
  - Deletes the share row that previously gave `newUserId` access
  - All other shares are kept as-is
- [ ] 5.6 Add unit tests: pool with one user share; pool with only group share; pool empty; group share where every member is also deleted (cycle); concurrent owner-deletion edge case

## 6. Optional one-shot data hygiene migration

- [ ] 6.1 Create `lib/Migration/Version001006Date20260430130000.php` (`SimpleMigrationStep`)
- [ ] 6.2 In `postSchemaChange`, gated by an admin setting `mydash.cleanup_orphan_shares = true`:
  - Find share rows where `share_type='user'` and the `share_with` uid no longer resolves via `IUserManager::get()`
  - Find share rows where `share_type='group'` and the `share_with` group no longer resolves via `IGroupManager::get()`
  - Delete those rows; emit a count to the migration output
- [ ] 6.3 Default the setting to `false` so no surprise deletions on federated environments

## 7. Documentation + telemetry

- [ ] 7.1 Update `docs/sharing.md` (create if missing) with the share lifecycle diagram, including the new ownership-transfer path
- [ ] 7.2 Add a `mydash_dashboards_orphaned_at_owner_deletion_total` Prometheus counter incremented inside `UserDeletedListener` for each dashboard where the admin pool was empty (we deleted)
- [ ] 7.3 Add a `mydash_dashboard_ownership_transferred_total` counter for the success branch
- [ ] 7.4 Update `openspec/specs/dashboard-sharing/spec.md` with the new REQ-SHARE-008..013 once the change is archived (this is the post-merge merge step done by `/opsx-archive`)
