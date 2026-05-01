# Tasks — activity-feed-integration

## 1. Extension registration

- [ ] 1.1 Create `lib/Activity/Extension.php` implementing `OCP\Activity\IProvider`; declare class constant `APP_ID = 'mydash'`
- [ ] 1.2 Implement `parse(string $language, IEvent $event, ?IEvent $previousEvent): IEvent` — match on each of the 13 `EVENT_*` constants; delegate to per-type template builder; return unmodified event for unknown types
- [ ] 1.3 Implement `getIcon(string $eventType): string` — return full URL to the per-type SVG from `img/activity/`; fall back to the generic MyDash icon for unknown types
- [ ] 1.4 Register `\OCA\MyDash\Activity\Extension` in `appinfo/info.xml` under `<activity>`
- [ ] 1.5 Register `ActivityPublisher` and `DebounceHelper` in `lib/AppInfo/Application.php` DI container

## 2. Event-type constants

- [ ] 2.1 Declare all 13 class constants on `Extension.php`: `EVENT_CREATED`, `EVENT_UPDATED`, `EVENT_DELETED`, `EVENT_PUBLISHED`, `EVENT_UNPUBLISHED`, `EVENT_SCHEDULED`, `EVENT_SHARED`, `EVENT_PUBLIC_SHARE_CREATED`, `EVENT_COMMENTED`, `EVENT_REACTED`, `EVENT_RESTORED`, `EVENT_LOCK_OVERRIDDEN`, `EVENT_ROLE_CHANGED` — values are the string names listed in the spec
- [ ] 2.2 Group constants into a static `ALL_EVENTS` array for use in the unit test assertion loop and NC settings-screen registration
- [ ] 2.3 Register each event type with NC Activity so it appears in the per-type opt-out UI in NC settings; set sensible defaults (`notification: true`, `email: false`) for each type

## 3. `publishActivity` contract

- [ ] 3.1 Create `lib/Activity/ActivityPublisher.php` with a single public `publish(string $type, string $actorUserId, string $dashboardUuid, string $dashboardName, string $dashboardLink, array $extraParams = []): void` method
- [ ] 3.2 Inside `publish()`: build `IEvent` via `IManager::generateEvent()`; set `app`, `type`, `author`, `object` (`object_type = 'mydash_dashboard'`, `object_id = $dashboardUuid`), `subject`, `message`, `link`, and `timestamp`; call `IManager::publishActivity($event)` — never bypass `IManager`
- [ ] 3.3 Inject `IManager` into `ActivityPublisher` via constructor DI (no static calls, no service locator)
- [ ] 3.4 `publish()` MUST NOT throw on unknown event types — log a warning and return early

## 4. Audience targeting — personal dashboards

- [ ] 4.1 For `dashboard_created`, `dashboard_updated`, `dashboard_deleted`, `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled`, `dashboard_restored`: emit exactly one activity row to the dashboard owner (`userId` of the dashboard)
- [ ] 4.2 When the actor is the owner (self-action), use the first-person subject template (e.g. `"You created dashboard %s"`); when the actor differs, use the third-person template (e.g. `"%s created dashboard %s"`)
- [ ] 4.3 Unit-test both template branches: self-action resolves first-person; third-party resolves third-person

## 5. Audience targeting — user-to-user shares

- [ ] 5.1 For `dashboard_shared`: emit one activity row to the acting user AND one row per recipient listed in the share payload (resolved from the `dashboard-sharing` capability's share record)
- [ ] 5.2 `ActivityPublisher::publishToRecipients(string $type, string $actorUserId, string $dashboardUuid, string $dashboardName, string $dashboardLink, array $recipientUserIds): void` — loops over recipients and calls `IManager::publishActivity()` once per recipient
- [ ] 5.3 `dashboard_public_share_created`: emit only to the dashboard owner and the acting admin (no fan-out — no named recipients exist)

## 6. Audience targeting — group-shared dashboards

- [ ] 6.1 For events on a `group_shared` dashboard: resolve all members of `groupId` via `IGroupManager::get($groupId)->getUsers()` and emit one activity row per member
- [ ] 6.2 Inject `IGroupManager` into `ActivityPublisher`; never call `IGroupManager` for personal-dashboard events (guard on `dashboard->getType()`)
- [ ] 6.3 Emit the actor's own row first, then remaining members; do not emit a duplicate row for the actor if they are also a group member (deduplicate by userId)

## 7. Audience targeting — default-group dashboards (global events)

- [ ] 7.1 For events on a dashboard with `groupId = 'default'`: enumerate all Nextcloud users via `IUserManager::callForAllUsers()` and emit one activity row per user
- [ ] 7.2 Gate global fan-out through `DebounceHelper::allowGlobalFanout(string $dashboardUuid, string $eventType): bool` — returns true at most once per 15 minutes per `(dashboardUuid, eventType)` combination; uses APCu key `mydash_act_global_{dashboardUuid}_{eventType}`
- [ ] 7.3 When `allowGlobalFanout` returns false, skip the entire fan-out (do not emit any rows for that event occurrence); log a DEBUG message indicating debounce suppressed the event

## 8. Debounce — reaction events

- [ ] 8.1 Create `lib/Activity/DebounceHelper.php` with `allowReaction(string $actorUserId, string $dashboardUuid): bool` — returns true at most once per 15 minutes per `(actorUserId, dashboardUuid)` pair; APCu key `mydash_act_react_{actorUserId}_{dashboardUuid}`; TTL 900 seconds
- [ ] 8.2 `ActivityPublisher::publish()` MUST call `DebounceHelper::allowReaction()` before emitting `EVENT_REACTED`; if it returns false, return without publishing
- [ ] 8.3 Add `DebounceHelper::allowGlobalFanout()` (task 7.2) to the same class; TTL also 900 seconds
- [ ] 8.4 Unit-test: first call to `allowReaction()` returns true; second call within 900 s returns false; call after TTL expiry returns true (mock APCu or inject a test-double cache)

## 9. Subject and message templates

- [ ] 9.1 Define `Extension::getSubjectTemplates(): array` returning `[eventType => ['self' => '...', 'other' => '...']]` for all 13 types
- [ ] 9.2 First-person subjects (`self`): `"You created dashboard {dashboard}"`, `"You updated dashboard {dashboard}"`, `"You deleted dashboard {dashboard}"`, `"You published dashboard {dashboard}"`, `"You unpublished dashboard {dashboard}"`, `"You scheduled dashboard {dashboard}"`, `"You shared dashboard {dashboard} with {recipient}"`, `"You created a public link for dashboard {dashboard}"`, `"You commented on dashboard {dashboard}"`, `"You reacted to dashboard {dashboard}"`, `"You restored dashboard {dashboard} to an earlier version"`, `"You overrode the lock on dashboard {dashboard}"`, `"Your role in {dashboard} was changed to {role}"`
- [ ] 9.3 Third-person subjects (`other`): `"{actor} created dashboard {dashboard}"`, `"{actor} updated dashboard {dashboard}"`, `"{actor} deleted dashboard {dashboard}"`, `"{actor} published dashboard {dashboard}"`, `"{actor} unpublished dashboard {dashboard}"`, `"{actor} scheduled dashboard {dashboard}"`, `"{actor} shared dashboard {dashboard} with {recipient}"`, `"{actor} created a public link for dashboard {dashboard}"`, `"{actor} commented on dashboard {dashboard}"`, `"{actor} reacted to dashboard {dashboard}"`, `"{actor} restored dashboard {dashboard} to an earlier version"`, `"{actor} overrode the lock on dashboard {dashboard}"`, `"{actor} changed {target}'s role in {dashboard} to {role}"`
- [ ] 9.4 Register all subject strings as i18n keys in `l10n/en.json` and `l10n/nl.json`; NC's Activity translation system resolves `{placeholder}` at render time
- [ ] 9.5 Message templates (optional detail lines) use the same key pattern `mydash_act_msg_{eventType}` — empty string is acceptable for non-comment events; `dashboard_commented` message MUST include the first 200 characters of the comment body

## 10. Icons

- [ ] 10.1 Create `img/activity/` directory; add one SVG icon per event type (13 files) using Nextcloud's Activity icon conventions (24×24 px, single colour, no embedded raster images)
- [ ] 10.2 `Extension::getIcon(string $eventType)` MUST return an absolute URL via `\OCP\IURLGenerator::imagePath('mydash', "activity/{$eventType}.svg")` — no hardcoded strings
- [ ] 10.3 Provide a generic fallback `img/activity/mydash.svg` returned when `$eventType` is not one of the 13 known constants
- [ ] 10.4 All SVG files MUST pass SVG sanitisation (no `<script>`, no external references) per the `svg-sanitisation` capability requirements

## 11. Cross-capability emission contract

- [ ] 11.1 Document in `lib/Activity/Extension.php` (class-level docblock) the canonical list of which capability is responsible for calling `ActivityPublisher::publish()` for each event type: `dashboard_created` → `dashboards`; `dashboard_updated` → `dashboards`; `dashboard_deleted` → `dashboards`; `dashboard_published`/`dashboard_unpublished`/`dashboard_scheduled` → `dashboard-draft-published`; `dashboard_shared` → `dashboard-sharing-followups`; `dashboard_public_share_created` → `dashboard-public-share`; `dashboard_commented` → `dashboard-comments`; `dashboard_reacted` → `dashboard-reactions`; `dashboard_restored` → `dashboard-versioning`; `dashboard_lock_overridden` → `dashboard-locking`; `dashboard_role_changed` → `admin-roles`
- [ ] 11.2 Each sibling-capability task list MUST include a subtask "Add `ActivityPublisher::publish(Extension::EVENT_*)` call at the action completion point" — this spec creates the service; the sibling spec adds the call
- [ ] 11.3 Publish calls MUST occur after the primary domain action succeeds and is persisted — never inside a database transaction that may roll back
- [ ] 11.4 If `ActivityPublisher::publish()` throws, the exception MUST be caught and logged; the primary action MUST NOT be rolled back due to an Activity failure

## 12. Unit-test contract

- [ ] 12.1 Create `tests/Unit/Activity/ExtensionTest.php`; for each of the 13 event types: call `IManager::publishActivity()` with a fabricated `IEvent` and assert a row exists in `oc_activity` (or use a mock `IManager` to assert `publishActivity()` was called with correct `type`, `object_id`, `subject`, `message`, and `link` values)
- [ ] 12.2 Assert that `Extension::getIcon()` returns a non-empty string for each of the 13 known event types and for an unknown type
- [ ] 12.3 Assert that `Extension::parse()` returns an `IEvent` with `richSubject` set for each of the 13 event types (both self- and other-actor variants)
- [ ] 12.4 Assert that publishing `EVENT_REACTED` a second time within the debounce window results in zero additional `publishActivity()` calls (requires a mock or in-memory APCu stub)
- [ ] 12.5 All tests MUST pass under `composer check:strict` (PHPStan level 8, PHPCS PSR-12, PHPMD); fix any pre-existing issues encountered in nearby files

## 13. Quality gates

- [ ] 13.1 `composer check:strict` clean on all new and touched PHP files
- [ ] 13.2 SPDX headers on every new PHP file inside the main file docblock (never as `// SPDX-...` line comments)
- [ ] 13.3 i18n keys for all 13 subject pairs (self + other) + all message templates present in both `l10n/en.json` and `l10n/nl.json`
- [ ] 13.4 SVG icons pass the sanitisation checker (no scripts, no external refs)
- [ ] 13.5 Run all `hydra-gates` locally before opening PR
