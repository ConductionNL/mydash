# Tasks — dashboard-cascade-events

## 1. Event class

- [x] 1.1 Create `lib/Event/DashboardDeletedEvent.php` extending `\OCP\EventDispatcher\Event` with constructor arguments `(string $dashboardUuid, string $ownerUserId, string $type, \DateTimeImmutable $deletedAt)`
- [x] 1.2 Add read-only getters: `getDashboardUuid()`, `getOwnerUserId()`, `getType()`, `getDeletedAt()`
- [x] 1.3 Add SPDX docblock header (SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock per convention)

## 2. Migration — cascade failures table

- [~] 2.1 ~~Create `lib/Migration/VersionXXXXDate2026AddCascadeFailuresTable.php`~~ — DROPPED per spec REQ-CSC-007 ("No failure-recording table migration is required"). The design.md D5 analysis concluded the failures table was over-engineered; the spec was updated to mandate log-and-continue with the orphan-cleanup job identifying stragglers by querying dependent tables directly.
- [~] 2.2 ~~Add index `idx_mydash_cascade_fail_uuid`~~ — DROPPED (table not created).
- [~] 2.3 ~~Confirm migration is reversible~~ — N/A (no migration).
- [~] 2.4 ~~Run migration locally~~ — N/A (no migration).

## 3. DashboardDeletedEvent listeners

- [x] 3.1 Create `lib/Listener/WidgetPlacementsListener.php` implementing `\OCP\EventDispatcher\IEventListener` (stub with try/catch + WARN log; live cleanup deferred to placements follow-up)
- [x] 3.2 Create `lib/Listener/CommentsListener.php` (stub; live `ICommentsManager::deleteCommentsAtObject()` wiring deferred to dashboard-comments follow-up)
- [x] 3.3 Create `lib/Listener/ReactionsListener.php` (stub; deferred to dashboard-reactions)
- [x] 3.4 Create `lib/Listener/LocksListener.php` (stub; deferred to dashboard-locking)
- [x] 3.5 Create `lib/Listener/VersionsListener.php` (stub; deferred to dashboard-versioning)
- [x] 3.6 Create `lib/Listener/PublicSharesListener.php` (stub; deferred to dashboard-public-share)
- [x] 3.7 Create `lib/Listener/MetadataValuesListener.php` (stub; deferred to dashboard-metadata-fields)
- [x] 3.8 Create `lib/Listener/TranslationsListener.php` (stub; deferred to dashboard-language-content)
- [x] 3.9 Create `lib/Listener/ViewAnalyticsListener.php` (stub; deferred to dashboard-view-analytics)
- [x] 3.10 Create `lib/Listener/TreeListener.php` (stub; deferred to dashboard-tree)

## 4. Lifecycle listeners

- [x] 4.1 `lib/Listener/UserDeletedListener.php` already exists and satisfies REQ-CSC-004 owned-dashboard enumeration via the REQ-SHARE-012/013 admin-retention cascade. Downstream extensions (role assignments, feed token revocation, analytics opt-out preference) deferred to the relevant subsystems.
- [x] 4.2 Create `lib/Listener/GroupDeletedListener.php` subscribing to `\OCP\Group\Events\GroupDeletedEvent` (stub with try/catch + WARN log; group-shared dashboard enumeration, role assignment cleanup, and IConfig JSON mutation of `org_navigation_tree` / `group_order` deferred to dashboard-sharing / navigation-editor-org follow-ups)

## 5. DashboardService integration

- [ ] 5.1 In `DashboardService::deleteDashboard()` and `deleteGroupShared()`, after soft-deleting the dashboard row inject and call `IEventDispatcher::dispatchTyped(new DashboardDeletedEvent(...))` — DEFERRED. Event class + registry are in place; switching the in-flight delete paths to dispatch the event belongs in the `dashboard-tree` follow-up that owns the cascade flag and child enumeration (REQ-CSC-010).
- [ ] 5.2 Collect `cascadeStats` from listeners — DEFERRED to per-listener implementations; aggregation contract owned by the downstream proposals.
- [x] 5.3 Confirmed the existing validation guard (`ERR_LAST_IN_GROUP`) runs before the soft-delete call, so a dispatch added after the delete will inherit the validation-first ordering.

## 6. Listener registration

- [x] 6.1 In `lib/AppInfo/Application.php`, register all ten `DashboardDeletedEvent` listeners using `IRegistrationContext::registerEventListener()` (Nextcloud's preferred wrapper around `IEventDispatcher::addServiceListener`)
- [x] 6.2 `UserDeletedListener` is already registered for `\OCP\User\Events\UserDeletedEvent::class` from the REQ-SHARE-012/013 work
- [x] 6.3 Register `GroupDeletedListener` for `\OCP\Group\Events\GroupDeletedEvent::class`

## 7. Failure recording helper

- [~] 7.1 ~~Create `lib/Service/CascadeFailureRecorder.php`~~ — DROPPED per spec REQ-CSC-007 (log-and-continue replaces the failures table entirely). Each listener's catch block calls `LoggerInterface::warning()` directly with listener class, dashboard UUID, and exception message.
- [~] 7.2 ~~Confirm recorder is wrapped in try/catch~~ — N/A (no recorder).

## 8. PHPUnit tests

- [x] 8.1 `DashboardDeletedEventTest` — verifies all four getters return constructor values, group_shared type carries actor user ID, and the class extends `\OCP\EventDispatcher\Event`
- [x] 8.2 `WidgetPlacementsListenerTest` — stub accepts `DashboardDeletedEvent` without throwing and short-circuits on foreign event types
- [ ] 8.3 `PublicSharesListenerTest` — DEFERRED with the live implementation
- [ ] 8.4 `TreeListenerTest` — DEFERRED with the live implementation (dashboard-tree)
- [ ] 8.5 `UserDeletedListenerTest` — already exists for the REQ-SHARE-012/013 path; cascade-stats coverage deferred to downstream
- [ ] 8.6 `GroupDeletedListenerTest` — DEFERRED with the live implementation
- [ ] 8.7 `DashboardServiceDeleteTest` — DEFERRED with task 5.1 (event dispatch in service)

## 9. End-to-end Playwright tests

- [ ] 9.1 DEFERRED — placements lookup requires the live `WidgetPlacementsListener` implementation
- [ ] 9.2 DEFERRED — child cascade requires the live `TreeListener` implementation
- [ ] 9.3 DEFERRED — listener-failure simulation only meaningful once listeners do real work
- [ ] 9.4 DEFERRED — owned-dashboard cascade is exercised by existing REQ-SHARE-012/013 E2E coverage
- [ ] 9.5 DEFERRED — `cascadeStats` is built once task 5.2 lands

## 10. Quality gates

- [x] 10.1 `composer check:strict` — see verify report (PHPCS, PHPMD, Psalm, PHPStan all clean for the new files)
- [x] 10.2 SPDX headers in every new PHP file (inside docblock per convention)
- [x] 10.3 No user-facing strings introduced; `i18n` keys not required for log-only listener stubs
- [ ] 10.4 `hydra-gates` — runs in CI on PR
- [~] 10.5 ~~Confirm `oc_mydash_cascade_failures` migration~~ — N/A per task 2 (table dropped).
