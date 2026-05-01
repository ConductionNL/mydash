# People Widget

A new dashboard widget that displays a directory of Nextcloud users with profile cards, filters, and birthday tracking. The widget registers via `OCP\Dashboard\IManager`, supports per-placement configuration (layout, group filters, profile field visibility), and provides a paginated API endpoint for user lookup. Results respect user profile visibility settings, and birthdays are computed at request time.

## Why

MyDash dashboards need a discoverable people directory as a core widget capability — users want to browse colleagues, see contact info and department, and get nudges for upcoming birthdays. Unlike static directory features, the people widget must be configurable per-placement (some dashboards show only management group, others show all visible users), respect privacy settings (hidden fields are omitted entirely, not nulled), and support efficient pagination for orgs with hundreds of users.

## What Changes

- **NEW** `POST /appinfo/dashboard.php` registers widget id `mydash_people` via `OCP\Dashboard\IManager::registerWidget()`.
- **NEW** `GET /api/people?filters=...&limit=50&offset=0` endpoint returns paginated user list `{users: [{uid, displayName, role, organisation, email, phone, avatarUrl, groups, birthdate|omitted, status?}], total, hasMore}`. Pagination is offset-based (max limit 100).
- **NEW** per-placement config in `widgetContent JSON`:
  - `layout: 'card'|'grid'|'list'` (default `grid`)
  - `selectionMode: 'manual'|'filter'` (default `filter`)
  - `selectedUsers: string[]` (default `[]`)
  - `filters: FilterObject[]` — group filtering expressed as `{fieldName: "group", operator: "in", values: [...]}` (default `[]` = all visible users)
  - `filterOperator: 'AND'|'OR'` (default `'AND'`)
  - `excludeDisabled: boolean` (default `true`)
  - `showBirthdays: boolean` (default `true`)
  - `birthdayWindowDays: number` (default 7, range 0..30)
  - `sortBy: 'displayName'|'group'|'recent-activity'` (default `displayName`)
  - `columns: 2|3|4` (default 3 for card, 4 for grid)
  - `showFields: object` — map of field name to boolean (default all `true`)
- **NEW** Vue 3 SFC `PeopleWidget.vue` with three layout modes (card ~200×280px / avatar 80 px, grid ~80×120px / avatar 64 px, list single-line / avatar 44 px) and optional in-widget search (client-side substring filter).
- **NEW** birthday field: read from `OCP\Accounts\IAccountManager`'s `PROPERTY_BIRTHDATE`, normalized to ISO 8601 string. Returned as `birthdate: "YYYY-MM-DD"` or omitted. Days-to-birthday display computed client-side. Birthday window filtering via `within_next_days` filter operator (server-side predicate only).
- **NEW** avatar URL via NC's standard `core.avatar.getAvatar` route at 128 px resolution; display size is layout-dependent (80/64/44 px). No configurable size app config key.
- **NEW** profile visibility: v1 returns all non-empty `IAccountManager` fields unconditionally. Scope-based visibility enforcement is a planned follow-up.
- **NEW** group-filter: expressed as a filter object in `filters` array; uses `IGroupManager::get($groupId)->getUsers()` with deduplication via `$seen` map.
- **NEW** 60-second client-side cache with force-refresh button in widget header.
- **NEW** click on profile card → opens `/u/{uid}` standard NC profile page in same tab (must be added fresh — not in reference source).
- **NEW** empty state: "No matching users."

## Capabilities

### New Capabilities
- `people-widget`: Dashboard widget displaying a user directory with customizable layout, group filters, profile field visibility, birthday tracking, and pagination.

### Modified Capabilities
- (none — widget integrates with core `widgets` capability via standard `OCP\Dashboard\IManager` registration.)

## Impact

- New files: `appinfo/dashboard.php`, `lib/Controller/PeopleWidgetController.php`, `lib/Service/PeopleWidgetService.php`, `src/components/PeopleWidget.vue`, plus typed exception classes.
- Routes: one new `GET /api/widgets/people/{placementId}/users` entry in `appinfo/routes.php`.
- Database: NO new tables — widget placements stored in core `oc_mydash_widget_placements` table with `widgetContent` JSON field.
- Frontend: `PeopleWidget.vue` component with layout variants (card/grid/list), search input, pagination, and 60-second cache.
- Dependencies: uses built-in `OCP\IUserManager`, `OCP\Accounts\IAccountManager`, `OCP\IGroupManager`, no new external libraries.

## Affected code units

- `appinfo/dashboard.php` — widget registration via `IManager::registerWidget()`
- `lib/Controller/PeopleWidgetController.php` — `GET /api/widgets/people/{placementId}/users`
- `lib/Service/PeopleWidgetService.php` — user lookup, pagination, visibility filtering, birthday computation
- `src/components/PeopleWidget.vue` — three layout modes (card/grid/list), search, cache, click-through
- `appinfo/routes.php` — add GET route for people widget users API

## Why a new capability

The people widget is a self-contained feature: registration, endpoint, service layer, and Vue component. It has no dependency on other widgets and can be disabled/enabled independently. Folding it into the core `widgets` capability would obscure the contract; keeping it standalone lets us add features (per-user filter, export, presence status) independently.

## Approach

- Widget registration via `appinfo/dashboard.php` (standard NC approach).
- Config stored in widget placement's `widgetContent` JSON field (no separate config table).
- Endpoint returns paginated list (offset-based, max 100 per page; `{users, total, hasMore}`).
- Profile visibility: v1 returns all non-empty `IAccountManager` fields unconditionally; scope-based visibility is a planned follow-up.
- Birthday field returned as ISO 8601 string; days-to-birthday computed client-side. Server-side `within_next_days` filter predicate used for birthday window queries.
- Avatar URLs point to NC's standard `core.avatar.getAvatar` route at 128 px; display size is layout-dependent.
- Client-side search filters current page only (not all users — efficient for large orgs).
- Click → `/u/{userId}` opens user's NC profile in same tab.

## Notes

- Per-user ACL (e.g., only show HR to HR staff) is OUT of scope for v1 — group filtering is the primary privacy lever.
- Presence status integration is OUT of scope for v1 — tracked as a follow-up.
- History/activity-based sorting ("who did I chat with recently") requires `OCP\Activity` integration — OUT of scope for v1; `sortBy: 'recent-activity'` is reserved but not yet implemented.
- Export (CSV of people + birthdays) is OUT of scope for v1.
- Intravox, intra, voxcloud terminology is NOT used — widget is purely for Nextcloud user directory.
