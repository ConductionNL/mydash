# Tasks — people-widget

## 1. Backend

- [ ] Create `lib/Service/PeopleWidgetService.php` with methods:
  - `getUsersForPlacement(string $placementId, string $cursor = null, int $limit = 50): array` returning `{users: [{userId, displayName, jobTitle, department, email, phone, avatarUrl, groups, daysToBirthday|null}], nextCursor|null}`
  - `getVisibleFields(string $userId, string $viewerId): array` — returns subset of profile fields viewer is allowed to see
  - `computeDaysToBirthday(string $birthdate): ?int` — returns days until next birthday (modulo year) or null if invalid
  - `filterByGroups(string[] $userIds, string[] $groupNames): string[]` — intersection with NC groups
- [ ] Create `lib/Controller/PeopleWidgetController.php::getUsers` mapped to `GET /api/widgets/people/{placementId}/users?cursor=&limit=50`
- [ ] Load placement config from `widgetContent` JSON: `layout`, `groupFilter`, `excludeDisabled`, `showBirthdays`, `birthdayWindowDays`, `sortBy`, `cardFields`
- [ ] Resolve placement from `{placementId}` via `PlacementService` or similar
- [ ] Use `OCP\IUserManager::getUsers()` to fetch NC users
- [ ] Apply `groupFilter` via `IGroupManager::displayNamesInGroup()` intersection
- [ ] Filter disabled users if `excludeDisabled: true`
- [ ] Resolve profile data via `OCP\Accounts\IAccountManager` for `jobTitle`, `department`, `email`, `phone`, `birthdate`
- [ ] Enforce visibility: only return fields the viewer is allowed to see (via `IAccountManager`'s per-field visibility flags)
- [ ] Compute `daysToBirthday` if `showBirthdays: true` and birthdate is visible
- [ ] Sort by `displayName` (default), `group`, or stub `recent-activity` (returns error "not yet implemented")
- [ ] Paginate: return up to `limit` users with `nextCursor` for next page
- [ ] Avatar URL: NC's standard `/avatar/<userId>?size=64` (size configurable via `mydash.people_widget_avatar_size` app config, default 64)
- [ ] Define typed exceptions: `PlacementNotFoundException`, `InvalidGroupFilterException`

## 2. Frontend

- [ ] Create `src/components/PeopleWidget.vue` Vue 3 SFC
  - Props: `widgetContent` (JSON config), `placementId`
  - Fetch: `GET /api/widgets/people/{placementId}/users?cursor=&limit=50`
  - Render three layouts:
    - `card`: full profile cards (avatar + all configured fields, ~200×280 px each), avatar ~64 px, fields stacked vertically
    - `grid`: avatar + display name only (~80×120 px each), avatar ~48 px
    - `list`: single-line rows with avatar + name + optional secondary field (email or department), avatar ~32 px
  - Search input: client-side substring filter on `displayName` + `email` (case-insensitive), filters current page only
  - Birthday badges: "🎂 in N days" on card/grid/list if `daysToBirthday` <= `birthdayWindowDays`
  - Pagination: "Load more" button or infinite scroll, maintains scroll position
  - Cache: 60-second in-memory cache per placement, clear on re-focus or manual refresh
  - Force-refresh button in widget header (clear cache + refetch)
  - Empty state: "No matching users." (for search or filters)
  - Click on profile → opens `/u/{userId}` in same tab
  - Error handling: "Failed to load users" message with retry button

- [ ] Widget registration: add entry to `appinfo/dashboard.php` registering widget id `mydash_people`

## 3. Tests

- [ ] PHPUnit: `PeopleWidgetService::getUsersForPlacement()`:
  - Returns paginated list (limit 50 default, cursor-based next page)
  - Applies `groupFilter` intersection (empty array = all visible, non-empty = intersection with NC groups)
  - Filters disabled users if `excludeDisabled: true`
  - Computes `daysToBirthday` only if visible + `showBirthdays: true`
  - Returns only fields the viewer is allowed to see (hidden fields omitted)
  - Sorts by `displayName` (default), `group`, or rejects `recent-activity` with error message
  - Returns `null` `nextCursor` on last page

- [ ] PHPUnit: `computeDaysToBirthday()`:
  - Returns int (0..365) for upcoming birthday this year
  - Returns int for past-year birthday (wraps to next year)
  - Returns null for invalid/missing birthdate
  - Handles leap years correctly (Feb 29)

- [ ] PHPUnit: `getVisibleFields()`:
  - Only returns fields viewer is allowed to see
  - Omits hidden fields entirely (not null)
  - Respects `IAccountManager` visibility flags

- [ ] PHPUnit: `filterByGroups()`:
  - Returns intersection of users in any listed group
  - Returns empty array if no users match
  - Returns all users if groupFilter is empty

- [ ] PHPUnit: `PeopleWidgetController::getUsers`:
  - Returns HTTP 200 with paginated list
  - Returns HTTP 404 if placement not found
  - Returns HTTP 400 if `groupFilter` contains unknown group name

- [ ] Playwright: render PeopleWidget in all three layout modes (card/grid/list)
  - Card layout displays avatar + all configured fields, ~200×280 px cards
  - Grid layout displays avatar + name only, ~80×120 px cards
  - List layout displays rows with avatar + name + optional secondary field
  - Birthday badge "🎂 in N days" shown if within `birthdayWindowDays`

- [ ] Playwright: search input filters users by displayName/email (case-insensitive, current page only)
- [ ] Playwright: pagination works (load more / infinite scroll)
- [ ] Playwright: click on profile card opens `/u/{userId}` in same tab
- [ ] Playwright: force-refresh button clears cache + refetches
- [ ] Playwright: empty state shown when no results

## 4. Quality

- [ ] `composer check:strict` passes
- [ ] OpenAPI updated for `GET /api/widgets/people/{placementId}/users` endpoint
- [ ] Translation entries for all user-facing strings (layout names, field labels, error messages, empty state)
- [ ] Widget icon (SVG) in `img/people-widget.svg` (solid user group symbol, Conduction brand colors)
- [ ] No raw exception messages returned to client
- [ ] Avatar URLs handle missing/invalid users gracefully (fallback to placeholder)

## 5. Follow-ups (separate changes)

- [ ] `people-widget-presence` — integrate `OCP\UserStatus` for online/away/do-not-disturb badges
- [ ] `people-widget-activity` — implement `sortBy: 'recent-activity'` via `OCP\Activity\IManager`
- [ ] `people-widget-export` — CSV export of people + birthdays
- [ ] `people-widget-acl` — per-user visibility control (e.g., HR-only view)
