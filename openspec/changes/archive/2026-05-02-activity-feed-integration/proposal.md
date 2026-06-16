# Activity Feed Integration

## Why

Today MyDash performs actions — creating dashboards, publishing them, sharing them, locking them, posting comments, restoring versions — that are invisible to Nextcloud's standard Activity stream. Users and administrators have no timeline of "who did what" across the MyDash workspace, and there is no notification path for events that affect dashboards shared with other users. This change surfaces MyDash events in the standard Nextcloud Activity feed, giving every user a unified notification history alongside Files, Talk, and other NC apps.

## What Changes

- Register a new `OCP\Activity\IProvider` implementation (`lib/Activity/Extension.php`) under the application id `mydash`, covering thirteen discrete event types.
- Define event-type constants (`dashboard_created`, `dashboard_updated`, `dashboard_deleted`, `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled`, `dashboard_shared`, `dashboard_public_share_created`, `dashboard_commented`, `dashboard_reacted`, `dashboard_restored`, `dashboard_lock_overridden`, `dashboard_role_changed`) in the Extension class — one canonical place.
- Each emitted Activity event carries `{user, type, subject, message, object_type: 'mydash_dashboard', object_id: <uuid>, link, timestamp}` and is persisted via `OCP\Activity\IManager::publishActivity()`.
- Audience targeting varies by dashboard scope: personal → owner only; user-to-user share → all named recipients; group-shared → all group members resolved via `IGroupManager`; default-group dashboards → all NC users (global events, debounced).
- Reaction events are debounced per `(user, dashboard)` at most once per 15 minutes. Global (default-group) events share the same debounce pattern to avoid fan-out spam.
- Users control notification delivery (email / in-app) per event type via the standard NC Activity settings UI — no custom preferences UI required.
- Actual emission calls (`IManager::publishActivity(...)`) are NOT in this spec — they live in the per-capability implementation files for each event type (e.g. `dashboard_published` emission lives in the `dashboard-draft-published` capability). This spec owns the types, extension class, and contracts.
- A unit test asserts that publishing each event type results in a row in `oc_activity` with correct type, object_id, subject, message, and link.

## Capabilities

### New Capabilities

- `activity-feed-integration`: new capability owning the extension registration, event-type catalogue, audience-targeting rules, debounce logic, subject/message templates, icons, and unit-test contract.

### Modified Capabilities

None. Emission call-sites are added inside existing capability implementations by their respective specs/tasks (cross-capability emission contract defined in REQ-ACT-011).

## Impact

**Affected code:**

- `lib/Activity/Extension.php` — new: registers all 13 event types with NC's `IProvider` interface; provides `parse()`, `getIcon()`, `getUrl()`, and default subject/message templates
- `lib/Activity/ActivityPublisher.php` — new: thin service wrapper around `IManager::publishActivity()` + audience resolution + debounce; injected wherever emission calls are added
- `lib/Activity/DebounceHelper.php` — new: APCu-backed per-`(user, dashboard)` 15-minute window guard; also used for global (default-group) fan-out debounce
- `appinfo/info.xml` — register `\OCA\MyDash\Activity\Extension` under `<activity>`
- `lib/AppInfo/Application.php` — register `ActivityPublisher` and `DebounceHelper` in the DI container
- `tests/Unit/Activity/ExtensionTest.php` — new PHPUnit test (REQ-ACT-011 contract)

**Affected APIs:**

- No new HTTP endpoints. NC Activity REST API (`/ocs/v2.php/apps/activity/api/v2/activity`) will surface MyDash events automatically once the provider is registered.

**Dependencies:**

- `OCP\Activity\IProvider` — already available in Nextcloud ≥ 20; no new composer packages required
- `OCP\Activity\IManager` — already in NC core
- `OCP\IGroupManager` — already injected elsewhere in MyDash
- APCu — assumed available (used elsewhere for rate-limiting)

**Migration:**

- No database migration. NC Activity uses its own `oc_activity` table managed by NC core.
- No data backfill — historical events before this change are not retroactively recorded.
