---
status: draft
---

# Activity Feed Integration Specification

## Purpose

Surface MyDash events in Nextcloud's standard Activity feed so every action on a dashboard — creation, editing, publication, sharing, commenting, locking, and role changes — is visible to the relevant users in their NC notifications and activity stream. This capability defines the NC Activity extension class, all event-type constants, audience-targeting rules, debounce logic, subject/message templates, icon conventions, the cross-capability emission contract, and the unit-test contract. Actual `publishActivity()` call-sites are delegated to the sibling capability that owns each action.

## Data Model

No new database tables. NC Activity persists events in the existing `oc_activity` table (managed by NC core). Each MyDash event row carries:

- **app**: `'mydash'`
- **type**: one of the 13 `Extension::EVENT_*` constants
- **user**: the NC user ID of the recipient
- **affecteduser**: the NC user ID of the actor
- **object_type**: `'mydash_dashboard'`
- **object_id**: the dashboard UUID (string)
- **subject**: translated template key
- **subjectparams**: JSON-encoded parameter array consumed by NC Activity's translation layer
- **message**: optional detail line (comment body excerpt for `dashboard_commented`)
- **link**: full NC URL to the dashboard
- **timestamp**: Unix epoch of the event

## ADDED Requirements

### Requirement: REQ-ACT-001 Extension Registration

The system MUST register a class `\OCA\MyDash\Activity\Extension` that implements `OCP\Activity\IProvider` and is declared in `appinfo/info.xml` under the `<activity>` section with application id `mydash`. The extension MUST be resolvable from the NC DI container at runtime.

#### Scenario: Extension resolves from DI container

- GIVEN MyDash is installed and enabled
- WHEN NC Activity attempts to resolve the registered provider via the DI container
- THEN it MUST receive an instance of `\OCA\MyDash\Activity\Extension` without error

#### Scenario: Extension is listed in NC Activity admin settings

- GIVEN MyDash is installed
- WHEN an administrator opens NC Settings → Activity
- THEN the MyDash extension MUST appear as a registered application with its 13 event types visible for per-type opt-out configuration

#### Scenario: Unknown event type does not throw

- GIVEN `Extension::parse()` receives an `IEvent` whose `type` is not one of the 13 known `EVENT_*` constants
- WHEN `parse()` is called
- THEN it MUST return the unmodified `IEvent` without throwing any exception

#### Scenario: `getIcon()` returns non-empty URL for all known types

- GIVEN any of the 13 known event type strings is passed to `Extension::getIcon()`
- WHEN the method executes
- THEN it MUST return a non-empty absolute URL string pointing to an SVG resource served by MyDash

#### Scenario: `getIcon()` falls back for unknown types

- GIVEN an unknown event type string is passed to `Extension::getIcon()`
- WHEN the method executes
- THEN it MUST return the URL to the generic MyDash activity icon (`img/activity/mydash.svg`)

### Requirement: REQ-ACT-002 Event-Type Catalogue

The system MUST define exactly 13 event-type constants on `\OCA\MyDash\Activity\Extension`, grouped in a static `ALL_EVENTS` array, covering the full set of trackable MyDash actions. No event type may be emitted by `ActivityPublisher` unless it is declared in `ALL_EVENTS`.

#### Scenario: All 13 constants present and non-empty

- GIVEN `Extension::ALL_EVENTS` is inspected at runtime
- THEN it MUST contain exactly these 13 string values (in any order): `dashboard_created`, `dashboard_updated`, `dashboard_deleted`, `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled`, `dashboard_shared`, `dashboard_public_share_created`, `dashboard_commented`, `dashboard_reacted`, `dashboard_restored`, `dashboard_lock_overridden`, `dashboard_role_changed`
- AND no value MUST be empty or duplicated

#### Scenario: Unknown type rejected by `ActivityPublisher`

- GIVEN `ActivityPublisher::publish()` is called with an event type string not present in `Extension::ALL_EVENTS`
- WHEN `publish()` executes
- THEN it MUST log a WARNING and return without calling `IManager::publishActivity()`
- AND no row MUST be written to `oc_activity`

#### Scenario: Constants are importable from sibling-capability files

- GIVEN a sibling capability (e.g. `dashboard-comments`) needs to reference the comment event type
- WHEN it uses `Extension::EVENT_COMMENTED`
- THEN PHP MUST resolve the constant without an autoload error
- NOTE: Constants MUST be public; no dynamic-only resolution pattern is acceptable

#### Scenario: `ALL_EVENTS` is iterable for registration loop

- GIVEN NC Activity settings-screen registration code iterates `Extension::ALL_EVENTS`
- WHEN it calls `IManager::registerExtension()` inside the loop
- THEN all 13 types MUST be registered with NC in a single loop without manual repetition

### Requirement: REQ-ACT-003 `publishActivity` Contract

The system MUST provide `\OCA\MyDash\Activity\ActivityPublisher` as the sole entry point for emitting MyDash activity events. It MUST delegate to `OCP\Activity\IManager::publishActivity()` and MUST NOT write to `oc_activity` directly. All fields required by NC Activity (`app`, `type`, `author`, `affecteduser`, `object_type`, `object_id`, `subject`, `message`, `link`, `timestamp`) MUST be set before the call.

#### Scenario: Correct fields populated on IEvent

- GIVEN `ActivityPublisher::publish('dashboard_updated', 'alice', 'uuid-1', 'Marketing', 'https://nc.example/apps/mydash#uuid-1')` is called
- WHEN `IManager::publishActivity()` is invoked internally
- THEN the `IEvent` passed to it MUST have: `app = 'mydash'`, `type = 'dashboard_updated'`, `object_type = 'mydash_dashboard'`, `object_id = 'uuid-1'`, `link = 'https://nc.example/apps/mydash#uuid-1'`

#### Scenario: ActivityPublisher never uses static IManager access

- GIVEN the `ActivityPublisher` source is inspected
- THEN it MUST NOT contain any `\OC::$server->getActivityManager()` or `Server::get(IManager::class)` calls
- AND `IManager` MUST be injected via constructor

#### Scenario: Activity failure does not roll back primary action

- GIVEN `IManager::publishActivity()` throws a `\RuntimeException`
- WHEN `ActivityPublisher::publish()` handles the exception
- THEN it MUST log the error and return without re-throwing
- AND the caller (sibling-capability code) MUST continue normally

#### Scenario: `publish()` called after domain action is persisted

- GIVEN a dashboard is updated and the mapper has called `flush()`
- WHEN the service method calls `ActivityPublisher::publish()` after the mapper call
- THEN the activity row MUST reflect the post-update state
- NOTE: Calls before `flush()` or inside a transaction that rolls back violate this contract

### Requirement: REQ-ACT-004 Audience Targeting — Personal Dashboards

For events on `user`-type dashboards, the system MUST emit exactly one activity row per event occurrence, addressed to the dashboard owner. The owner receives their own events so they appear in their personal activity stream.

#### Scenario: Self-action on personal dashboard — single row for owner

- GIVEN user "alice" updates her personal dashboard "Work"
- WHEN `ActivityPublisher::publish('dashboard_updated', 'alice', ...)` is called
- THEN exactly one row MUST be written to `oc_activity` with `user = 'alice'` and `affecteduser = 'alice'`

#### Scenario: Admin action on personal-origin dashboard — single row to owner

- GIVEN admin "root" deletes user "bob"'s personal dashboard on his behalf
- WHEN `ActivityPublisher::publish('dashboard_deleted', 'root', uuid, ...)` is called targeting bob's dashboard
- THEN exactly one row MUST be written with `user = 'bob'` and `affecteduser = 'root'`

#### Scenario: No fan-out for personal dashboards

- GIVEN "alice"'s personal dashboard is not shared with anyone
- WHEN she creates a new widget on it (triggering `dashboard_updated`)
- THEN `oc_activity` MUST contain exactly one new row for this event
- AND no rows MUST be written for any other user

#### Scenario: First-person vs third-person subject selection

- GIVEN "alice" updates her own personal dashboard
- THEN the activity row MUST use the first-person subject template for `dashboard_updated`
- GIVEN "root" updates "alice"'s personal dashboard
- THEN the activity row addressed to "alice" MUST use the third-person template with actor param `'root'`

### Requirement: REQ-ACT-005 Audience Targeting — Shared Dashboards

For `dashboard_shared` events (user-to-user shares via the `dashboard-sharing` capability), the system MUST emit one activity row to each named share recipient plus one row to the acting user.

#### Scenario: Share with two users produces three rows

- GIVEN "alice" shares dashboard "Marketing" with "bob" and "carol"
- WHEN `ActivityPublisher::publishToRecipients('dashboard_shared', 'alice', uuid, 'Marketing', link, ['bob', 'carol'])` is called
- THEN `oc_activity` MUST contain three new rows: one for "alice", one for "bob", one for "carol"

#### Scenario: Actor row uses first-person subject; recipient rows use second/third-person

- GIVEN the same share event above
- THEN "alice"'s row MUST use `"You shared dashboard Marketing with bob, carol"` (first-person)
- AND "bob"'s row MUST use `"alice shared dashboard Marketing with you"` (second-person variant)

#### Scenario: Public share creation — owner and actor only

- GIVEN admin "root" creates a public share link for "alice"'s dashboard "Marketing"
- WHEN `ActivityPublisher::publish('dashboard_public_share_created', 'root', uuid, 'Marketing', link)` is called
- THEN exactly two rows MUST be written: one for "root", one for "alice" (dashboard owner)
- AND no additional rows MUST be written (no named recipients exist)

#### Scenario: Sharing with a user who already has the dashboard does not skip their row

- GIVEN "carol" already sees the dashboard via a group share
- WHEN "alice" also creates a direct user-to-user share with "carol"
- THEN a `dashboard_shared` activity row MUST still be emitted to "carol"
- NOTE: The activity event is informational — deduplication of shares is handled by the `dashboard-sharing` capability, not Activity

### Requirement: REQ-ACT-006 Audience Targeting — Group-Shared Dashboards

For events on `group_shared`-type dashboards, the system MUST emit one activity row per current member of the target group, resolved at emit-time via `IGroupManager`. The actor receives their own row and MUST NOT receive a duplicate.

#### Scenario: Update on group-shared dashboard fans out to all members

- GIVEN group "marketing" has members ["alice", "bob", "carol"] and admin "root" updates the group-shared dashboard "Campaigns"
- WHEN `ActivityPublisher::publish('dashboard_updated', 'root', uuid, 'Campaigns', link)` is called with the dashboard entity
- THEN `oc_activity` MUST contain exactly 4 new rows: one for each of "root", "alice", "bob", "carol"

#### Scenario: Actor who is also a group member does not receive a duplicate row

- GIVEN admin "root" is also a member of group "marketing" (which has 3 members total including root)
- WHEN "root" updates the group-shared "Campaigns" dashboard
- THEN `oc_activity` MUST contain exactly 3 rows (not 4): one per unique user

#### Scenario: `IGroupManager` is not invoked for personal-dashboard events

- GIVEN a `user`-type dashboard event is being published
- WHEN `ActivityPublisher::publish()` runs
- THEN `IGroupManager` MUST NOT be called
- NOTE: Personal-dashboard audience resolution is always "owner only" — group lookup is guarded by `dashboard->getType() === 'group_shared'`

#### Scenario: Group with zero members produces zero activity rows

- GIVEN group "empty-team" has no members and a group-shared dashboard exists for it
- WHEN an update event is published for that dashboard
- THEN `oc_activity` MUST contain zero new rows for this event
- AND no exception MUST be thrown

### Requirement: REQ-ACT-007 Debounce — Reaction Events

The system MUST apply a 15-minute debounce per `(actorUserId, dashboardUuid)` pair to `dashboard_reacted` events. At most one reaction activity row per actor per dashboard per 15-minute window MUST be written.

#### Scenario: First reaction within window is published

- GIVEN user "bob" reacts to dashboard "Marketing" (uuid `abc`)
- AND no prior reaction from "bob" to `abc` exists in the current APCu window
- WHEN `ActivityPublisher::publish('dashboard_reacted', 'bob', 'abc', ...)` is called
- THEN `allowReaction('bob', 'abc')` MUST return true
- AND one row MUST be written to `oc_activity`

#### Scenario: Second reaction within 15 minutes is suppressed

- GIVEN "bob" already reacted to dashboard `abc` 5 minutes ago (APCu key is live)
- WHEN `ActivityPublisher::publish('dashboard_reacted', 'bob', 'abc', ...)` is called again
- THEN `allowReaction('bob', 'abc')` MUST return false
- AND `IManager::publishActivity()` MUST NOT be called
- AND zero new rows MUST be written to `oc_activity`

#### Scenario: Reaction from a different user is not suppressed by another user's debounce

- GIVEN "bob" reacted to dashboard `abc` 5 minutes ago
- WHEN "carol" reacts to the same dashboard `abc`
- THEN `allowReaction('carol', 'abc')` MUST return true (separate APCu key)
- AND one row MUST be written for "carol"

#### Scenario: Debounce TTL expires and next reaction is allowed

- GIVEN "bob" reacted to dashboard `abc` and the APCu key `mydash_act_react_bob_abc` has expired (TTL 900 s elapsed)
- WHEN "bob" reacts again
- THEN `allowReaction('bob', 'abc')` MUST return true
- AND one row MUST be written

### Requirement: REQ-ACT-008 Debounce — Global (Default-Group) Fan-Out

For events on dashboards with `groupId = 'default'`, the system MUST apply a 15-minute debounce per `(dashboardUuid, eventType)` before performing the full user-enumeration fan-out. When the debounce window is active, the entire fan-out MUST be skipped silently (no rows written, no exception).

#### Scenario: First global event triggers full fan-out

- GIVEN a default-group dashboard `D-default` is updated
- AND no prior debounce key exists for `(D-default.uuid, 'dashboard_updated')`
- WHEN `ActivityPublisher::publish('dashboard_updated', 'root', D-default.uuid, ...)` is called
- THEN `allowGlobalFanout(D-default.uuid, 'dashboard_updated')` MUST return true
- AND one row per NC user MUST be written to `oc_activity`

#### Scenario: Subsequent global event within 15 minutes is fully suppressed

- GIVEN the debounce key for `(D-default.uuid, 'dashboard_updated')` is active
- WHEN another update to the same dashboard triggers `publish('dashboard_updated', ...)` again
- THEN `allowGlobalFanout()` MUST return false
- AND `IUserManager::callForAllUsers()` MUST NOT be called
- AND zero rows MUST be written

#### Scenario: Different event type on same dashboard is not suppressed

- GIVEN debounce for `(D-default.uuid, 'dashboard_updated')` is active
- WHEN `publish('dashboard_published', ...)` is called for the same dashboard
- THEN `allowGlobalFanout(D-default.uuid, 'dashboard_published')` MUST return true (separate key)
- AND the publication fan-out MUST proceed

#### Scenario: DEBUG log entry emitted on suppression

- GIVEN the global debounce is active for an event
- WHEN the fan-out is suppressed
- THEN `ActivityPublisher` MUST emit a DEBUG-level log entry stating that the global fan-out was debounced, including the dashboard UUID and event type
- AND no WARNING or ERROR MUST be logged (suppression is expected behaviour)

### Requirement: REQ-ACT-009 User Opt-Out via NC Settings

The system MUST register each of the 13 event types with NC Activity's per-type notification preference system. Users MUST be able to independently disable in-app notifications and email notifications for each type via the standard NC Settings → Activity UI, with no custom MyDash preferences page required.

#### Scenario: Default notification preferences for a new user

- GIVEN a new NC user "frank" with no prior Activity preferences
- WHEN MyDash emits any activity event addressed to "frank"
- THEN in-app notification MUST be enabled (default on) for all 13 types
- AND email notification MUST be disabled (default off) for all 13 types

#### Scenario: User disables `dashboard_commented` in-app notifications

- GIVEN "frank" has navigated to NC Settings → Activity and disabled in-app for `dashboard_commented`
- WHEN another user comments on a dashboard "frank" has access to
- THEN no in-app row MUST be written to `oc_activity` for "frank" for this comment event
- AND "frank" MUST still receive rows for other event types he has not disabled

#### Scenario: User enables email for `dashboard_shared`

- GIVEN "frank" has enabled email delivery for `dashboard_shared`
- WHEN a dashboard is shared with him
- THEN NC's Activity email job MUST include this event in "frank"'s next digest
- NOTE: Email sending is NC's responsibility; MyDash only controls whether the row is written with the correct type

#### Scenario: Opt-out state is per-user and per-type

- GIVEN "frank" disables `dashboard_updated` but "grace" keeps it enabled
- WHEN a group-shared dashboard they both access is updated
- THEN no `dashboard_updated` row MUST be written for "frank"
- AND a `dashboard_updated` row MUST be written for "grace"

### Requirement: REQ-ACT-010 Subject/Message Templates and Translation

The system MUST define a complete set of translated subject and message templates for all 13 event types in both `en` and `nl`. NC Activity's `{placeholder}` substitution MUST be used for actor names, dashboard names, recipients, and roles. Templates MUST be registered as i18n keys.

#### Scenario: First-person subject rendered for self-actions

- GIVEN user "alice" creates a dashboard "Analytics"
- WHEN her activity row is rendered in the NC Activity UI
- THEN the subject MUST read `"You created dashboard Analytics"` (en) or `"Je hebt dashboard Analytics aangemaakt"` (nl)

#### Scenario: Third-person subject rendered for others' actions

- GIVEN user "bob" comments on "alice"'s dashboard "Analytics" and "alice" receives the activity row
- WHEN "alice" views her activity stream
- THEN the subject MUST read `"bob commented on dashboard Analytics"` (en)

#### Scenario: `dashboard_commented` message includes comment excerpt

- GIVEN "bob" posts the comment "Great work on the Q2 charts!" on dashboard "Analytics"
- WHEN the activity row is rendered
- THEN the message MUST contain the first 200 characters of the comment body
- AND the subject MUST be the short template (not the full comment body)

#### Scenario: Missing translation key falls back to English

- GIVEN a user has an NC locale set to a language other than `en` or `nl` that MyDash has not translated
- WHEN their activity row is rendered
- THEN NC Activity MUST fall back to the English template
- NOTE: This is NC's standard fallback behaviour; MyDash only needs to provide `en` and `nl`

#### Scenario: Placeholder substitution does not leave unresolved tokens

- GIVEN a subject template `"{actor} updated dashboard {dashboard}"` is rendered with actor `"root"` and dashboard `"Marketing"`
- WHEN NC Activity applies substitution
- THEN the rendered string MUST be `"root updated dashboard Marketing"` with no literal `{actor}` or `{dashboard}` tokens remaining

### Requirement: REQ-ACT-011 Cross-Capability Emission Contract

Each of the 13 event types has exactly one owning capability responsible for calling `ActivityPublisher::publish()` at the correct point in the action lifecycle. This spec defines the types and publisher service; the sibling capabilities add the call-sites. A capability MUST NOT emit an event type it does not own.

#### Scenario: `dashboard_published` emitted only by `dashboard-draft-published` capability

- GIVEN a dashboard is transitioned to `published` status
- WHEN the `dashboard-draft-published` service method completes
- THEN `ActivityPublisher::publish('dashboard_published', ...)` MUST be called exactly once
- AND no other capability code path MUST call `publishActivity` for `dashboard_published`

#### Scenario: Emission occurs after domain action is persisted

- GIVEN the dashboard mapper has flushed the status update to the database
- WHEN `ActivityPublisher::publish()` is subsequently called
- THEN the activity row timestamp MUST be >= the database row's `updatedAt`
- AND if the mapper call fails and throws before `publish()`, no activity row MUST be written

#### Scenario: Activity failure does not fail the HTTP request

- GIVEN `IManager::publishActivity()` throws `\Exception` (e.g. DB error on `oc_activity`)
- WHEN `ActivityPublisher` catches the exception
- THEN the HTTP response from the owning capability endpoint MUST still be HTTP 200/201/204
- AND an ERROR-level log entry MUST be written by `ActivityPublisher`

#### Scenario: No direct `oc_activity` writes in sibling-capability code

- GIVEN any PHP file in a sibling capability (e.g. `lib/Service/PublicationService.php`)
- WHEN the file is inspected
- THEN it MUST NOT contain any direct `oc_activity` SQL or `\OC_DB::` calls for MyDash events
- AND all event emission MUST go through `ActivityPublisher::publish()` or `ActivityPublisher::publishToRecipients()`

#### Scenario: Emission contract documented in Extension class docblock

- GIVEN `lib/Activity/Extension.php` is read
- WHEN the class-level docblock is inspected
- THEN it MUST list all 13 event types with the owning capability for each, so future contributors know where to add call-sites

### Requirement: REQ-ACT-011b Unit-Test Contract

The system MUST include a PHPUnit test class `tests/Unit/Activity/ExtensionTest.php` that verifies each of the 13 event types is correctly published, that icons and subjects are resolved, and that the debounce logic suppresses duplicate rows.

#### Scenario: All 13 event types produce a row with correct fields

- GIVEN `ActivityPublisher` is instantiated with a mock `IManager`
- WHEN `publish($type, 'alice', 'uuid-1', 'Dashboard A', 'https://nc.example/...')` is called for each of the 13 event types
- THEN the mock `IManager::publishActivity()` MUST be called exactly once per event type
- AND the `IEvent` passed MUST have `app = 'mydash'`, `type = $type`, `object_type = 'mydash_dashboard'`, `object_id = 'uuid-1'`

#### Scenario: `Extension::getIcon()` returns non-empty URL for all 13 types

- GIVEN `Extension::getIcon($type)` is called for each value in `Extension::ALL_EVENTS`
- THEN each call MUST return a non-empty string
- AND no call MUST throw

#### Scenario: `Extension::parse()` sets `richSubject` for all 13 types

- GIVEN `Extension::parse()` is called with a mock `IEvent` for each of the 13 types, with both self-actor and other-actor variants
- THEN the returned `IEvent` MUST have `richSubject` set to a non-empty string for every combination (26 assertions total)

#### Scenario: Debounce suppresses second `dashboard_reacted` within window

- GIVEN a mock or in-memory APCu stub is used
- AND `publish('dashboard_reacted', 'bob', 'uuid-1', ...)` has already been called once
- WHEN `publish('dashboard_reacted', 'bob', 'uuid-1', ...)` is called a second time within 900 s
- THEN `IManager::publishActivity()` MUST have been called exactly once in total (the second call is suppressed)

#### Scenario: PHPStan level 8 and PHPCS PSR-12 pass on the test file

- GIVEN `tests/Unit/Activity/ExtensionTest.php` is committed
- WHEN `composer check:strict` is executed
- THEN both PHPStan and PHPCS MUST report zero errors or warnings for the new test file
- AND any pre-existing issues in nearby files encountered during the task MUST also be fixed
