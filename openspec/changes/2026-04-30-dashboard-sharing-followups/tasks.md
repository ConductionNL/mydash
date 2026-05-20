# Tasks — dashboard-sharing-followups

## 0. Deduplication check

- [ ] 0.1 Search `openspec/specs/` for any existing notification, bulk-share, or cascade specs that overlap with REQ-SHARE-008..013
  - Expected result: no overlap; the baseline `dashboard-sharing` spec (REQ-SHARE-001..007) is the only prior art, and this change extends it with additive requirements only
- [ ] 0.2 Search `lib/Service/` for any existing `NotificationService`, `BulkShareService`, or `CascadeService` that could be extended rather than creating new methods
  - Expected result: `DashboardShareService` is the correct extension point; no parallel notification dispatch exists in the app
- [ ] 0.3 Confirm that OpenRegister's `NotificationService` is not applicable here — MyDash uses custom tables, not OR objects, so OR's notification path does not apply
- [ ] 0.4 Document findings in the PR description (even if "no overlap found")

## 1. Notifier service

- [ ] 1.1 Create `lib/Notification/Notifier.php` implementing `OCP\Notification\INotifier`
  - `getID()` returns `'mydash'`
  - `getName()` returns `'MyDash'` (translatable)
  - `prepare(INotification, $languageCode)` handles two subjects: `dashboard_shared`, `dashboard_ownership_transferred`
  - For unknown subjects throw `\OCP\Notification\UnknownNotificationException`
  - Use `IURLGenerator::linkToRouteAbsolute('mydash.page.index')` plus a `?dashboard={uuid}` query for the deep link
- [ ] 1.2 Use `IFactory::get('mydash')` for translations of the rendered strings:
  - `dashboard_shared`: rich subject "{sharerDisplayName} shared **{dashboardName}** with you" + parsed message "{permissionLevel} access"
  - `dashboard_ownership_transferred`: rich subject "**{dashboardName}** is now yours" + parsed message "Ownership transferred after the previous owner was removed"
- [ ] 1.3 Register the notifier in `lib/AppInfo/Application.php::register()` via `$context->registerNotifierService(Notifier::class)`
- [ ] 1.4 Add English and Dutch translation entries to `l10n/en.json` and `l10n/nl.json` for the two notification subjects and permission level labels ("Full access", "Add-only access", "View-only access")
- [ ] 1.5 Add unit test covering both subjects, English + Dutch locale

## 2. Notification publishing

- [ ] 2.1 Inject `OCP\Notification\IManager` into `DashboardShareService`
- [ ] 2.2 Extract a private `_persistShare(int $dashboardId, string $shareType, string $shareWith, string $level): array{share: DashboardShare, isNew: bool, isUpgrade: bool}` from the current `addShare`
- [ ] 2.3 Add a private `_notifyShared(DashboardShare $share, string $sharerUserId, string $dashboardName)` that:
  - For `share_type='user'`: creates one `INotification` for that single recipient
  - For `share_type='group'`: resolves group members at publish time via `IGroupManager` and creates one notification per current member, excluding the sharer
  - Sets `setSubject('dashboard_shared', [sharerUserId, dashboardName, permissionLevel])` and `setObject('dashboard', (string) $dashboardId)`
- [ ] 2.4 The current `addShare()` becomes: `_persistShare(...)` THEN `_notifyShared(...)` only when `isNew === true || isUpgrade === true` (not on downgrades, not on no-op writes)
- [ ] 2.5 The `removeShare()` path does NOT publish anything (revocations are silent)
- [ ] 2.6 Add unit test: mock `IManager`, assert exactly one notification per fan-out target, assert no notification on level downgrade

## 3. Bulk replace endpoint

- [ ] 3.1 Add `DashboardShareService::replaceShares(int $dashboardId, array $shares, string $userId): array` that:
  - Asserts caller is owner (throws 403 if not)
  - Validates each entry's `shareType`, `shareWith`, `permissionLevel`
  - Loads existing shares once
  - In a single transaction: deletes shares not in payload, upserts the rest
  - Returns the new full list
  - Calls `_notifyShared` only for entries where `isNew === true || isUpgrade === true`
- [ ] 3.2 Add `DashboardShareApiController::replace(int $id, ?array $shares)` action with `#[NoAdminRequired]` attribute and per-object owner check (ADR-005 Rule 3)
- [ ] 3.3 Register route `PUT /api/dashboard/{id}/shares` in `appinfo/routes.php`
- [ ] 3.4 Add `replaceShares(dashboardId, shares)` to `src/services/api.js`
- [ ] 3.5 Update `DashboardConfigModal.vue`: replace the per-row immediate `addShare`/`removeShare` calls with a `pendingShares` data array. Save button posts via `replaceShares` and reloads. Cancel reverts to the server snapshot. Wrap the `await` call in `try/catch` with user-facing error feedback (ADR-004)
- [ ] 3.6 Add backend integration test covering: add+remove+upgrade in one PUT; idempotent re-PUT with same payload publishes 0 notifications; non-owner receives 403

## 4. Revoke-all-for-recipient

- [ ] 4.1 Add `DashboardShareService::revokeAllForRecipient(string $shareType, string $shareWith, string $callerId): int` that:
  - Joins `oc_mydash_dashboard_shares` to `oc_mydash_dashboards` on `dashboardId = id` filtered to `dashboards.user_id = callerId`
  - Deletes all matching rows in one statement, returns the affected row count
- [ ] 4.2 Add `DashboardShareApiController::revokeForRecipient(string $shareType, string $shareWith)` with `#[NoAdminRequired]` attribute
- [ ] 4.3 Register route `DELETE /api/sharees/{shareType}/{shareWith}` in `appinfo/routes.php`
- [ ] 4.4 Add `revokeAllForRecipient(shareType, shareWith)` to `src/services/api.js`
- [ ] 4.5 No UI surface in this change — exposed for admin tools / scripted cleanup. Frontend wiring deferred.

## 5. UserDeletedEvent listener with admin retention

- [ ] 5.1 Create `lib/Listener/UserDeletedListener.php` implementing `IEventListener<UserDeletedEvent>`
- [ ] 5.2 Register in `lib/AppInfo/Application.php::register()` via `$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class)`
- [ ] 5.3 In `handle()`:
  - Resolve `$userId = $event->getUser()->getUID()`
  - **Step A**: delete every share where `share_type='user' AND share_with=$userId` (recipient cleanup)
  - **Step B**: for every dashboard owned by `$userId`:
    - Compute admin pool: load every share with `permission_level='full'`; for `user`-type entries the userId is added directly; for `group`-type entries, expand via `IGroupManager::get($groupId)->searchUsers('', 1000)` capped at 1000 per group
    - Filter the admin pool to existing users (skip already-deleted accounts via `IUserManager::get`)
    - **If pool is empty**: delete the dashboard, its placements, and its shares (existing `deleteDashboard` logic via `DashboardService`)
    - **If pool is non-empty**: pick the new owner using the selection rule (task 5.4), transfer ownership, delete only the new owner's matching share row, publish a `dashboard_ownership_transferred` notification to them
  - All Step B work runs in a single DB transaction per dashboard
- [ ] 5.4 New-owner selection rule (codified):
  1. Among `user`-type shares with `permission_level='full'`, take the one with the smallest `created_at`
  2. If none, expand the alphabetically-first `group`-type share's member list and pick the alphabetically-first uid still active
  3. If both fail (pool became empty between resolve and pick), fall through to delete
- [ ] 5.5 Add `DashboardShareService::transferOwnership(int $dashboardId, string $newUserId): void`:
  - Updates the dashboard's `user_id` and stamps `updated_at`
  - Deletes the share row that previously gave `newUserId` access
  - All other shares are kept as-is
- [ ] 5.6 Increment Prometheus counter `mydash_dashboards_orphaned_at_owner_deletion_total` on the delete path and `mydash_dashboard_ownership_transferred_total` on the transfer path
- [ ] 5.7 Add unit tests: pool with one user share; pool with only group share; pool empty; group share where every member is also deleted; recipient (non-owner) deletion removes only their shares

## 6. Optional one-shot data hygiene migration

- [ ] 6.1 Create `lib/Migration/Version001006Date20260430130000.php` (`SimpleMigrationStep`)
- [ ] 6.2 In `postSchemaChange`, gated by admin setting `mydash.cleanup_orphan_shares = true`:
  - Find share rows where `share_type='user'` and the `share_with` uid no longer resolves via `IUserManager::get()`
  - Find share rows where `share_type='group'` and the `share_with` group no longer resolves via `IGroupManager::get()`
  - Delete those rows; emit a count to the migration output
- [ ] 6.3 Default the setting to `false` so no surprise deletions on federated environments

## 7. Documentation + telemetry

- [ ] 7.1 Update `docs/sharing.md` (create if missing) with the share lifecycle diagram, including the new ownership-transfer path
- [ ] 7.2 Confirm `mydash_dashboards_orphaned_at_owner_deletion_total` and `mydash_dashboard_ownership_transferred_total` Prometheus counters are exported via `GET /api/metrics` (ADR-006)
- [ ] 7.3 Update `openspec/specs/dashboard-sharing/spec.md` with the new REQ-SHARE-008..013 once the change is archived (post-merge step done by `/opsx-archive`)
