# People Widget

A new dashboard widget that displays a directory of Nextcloud users with profile cards, filters, and birthday tracking. The widget registers via `OCP\Dashboard\IManager`, supports per-placement configuration (layout, group filters, profile field visibility), and provides a paginated API endpoint for user lookup. Results respect user profile visibility settings, and birthdays are computed at request time.

## Why

MyDash dashboards need a discoverable people directory as a core widget capability — users want to browse colleagues, see contact info and department, and get nudges for upcoming birthdays. Unlike static directory features, the people widget must be configurable per-placement (some dashboards show only management group, others show all visible users), respect privacy settings (hidden fields are omitted entirely, not nulled), and support efficient pagination for orgs with hundreds of users.

## What Changes

- **NEW** `POST /appinfo/dashboard.php` registers widget id `mydash_people` via `OCP\Dashboard\IManager::registerWidget()`.
- **NEW** `GET /api/widgets/people/{placementId}/users` endpoint returns paginated user list `{users: [{userId, displayName, jobTitle, department, email, phone, avatarUrl, groups, daysToBirthday|null}], nextCursor}`.
- **NEW** per-placement config in `widgetContent JSON`:
  - `layout: 'card'|'grid'|'list'` (default `grid`)
  - `groupFilter: string[]` — empty = all visible users, non-empty = intersection with named NC groups
  - `excludeDisabled: boolean` (default `true`)
  - `showBirthdays: boolean` (default `true`)
  - `birthdayWindowDays: number` (default 7, range 0..30) — "🎂 in N days" badges
  - `sortBy: 'displayName'|'group'|'recent-activity'` (default `displayName`)
  - `cardFields: string[]` — subset of `['displayName', 'jobTitle', 'department', 'email', 'phone', 'avatar']` (default all)
- **NEW** Vue 3 SFC `PeopleWidget.vue` with three layout modes (card ~200×280px, grid ~80×120px, list single-line) and optional in-widget search (client-side substring filter).
- **NEW** birthday computation: read from `OCP\Accounts\IAccountManager`'s `birthdate` property if visible, compute next birthday modulo year, return `null` if not set or not visible.
- **NEW** avatar URL via NC's standard route at 64×64 px (configurable via `mydash.people_widget_avatar_size`).
- **NEW** profile visibility enforcement: only return fields the viewer is allowed to see; hidden fields are omitted entirely.
- **NEW** group-filter intersection: display only users in any of the configured groups (validated via `IGroupManager::displayNamesInGroup()`).
- **NEW** 60-second client-side cache with force-refresh button in widget header.
- **NEW** click on profile card → opens `/u/{userId}` standard NC profile page in same tab.
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
- Endpoint returns paginated list (cursor-based for efficient large-org queries).
- Profile visibility enforced via `IAccountManager`'s per-field visibility flags — hidden fields are omitted, never nulled.
- Birthdays computed server-side at request time (not cached; next upcoming birthday from today).
- Avatar URLs point to NC's standard `avatar.php` endpoint.
- Client-side search filters current page only (not all users — efficient for large orgs).
- Click → `/u/{userId}` opens user's NC profile in same tab.

## Notes

- Per-user ACL (e.g., only show HR to HR staff) is OUT of scope for v1 — group filtering is the primary privacy lever.
- Presence status integration is OUT of scope for v1 — tracked as a follow-up.
- History/activity-based sorting ("who did I chat with recently") requires `OCP\Activity` integration — OUT of scope for v1; `sortBy: 'recent-activity'` is reserved but not yet implemented.
- Export (CSV of people + birthdays) is OUT of scope for v1.
- Intravox, intra, voxcloud terminology is NOT used — widget is purely for Nextcloud user directory.
