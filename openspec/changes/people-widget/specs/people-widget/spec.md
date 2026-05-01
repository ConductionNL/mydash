---
status: draft
---

# People Widget Specification

## Purpose

The `people-widget` capability registers a dashboard widget that displays a discoverable directory of Nextcloud users with customizable layout (card/grid/list), profile field visibility control, group filtering, and birthday tracking. The widget integrates with Nextcloud's Dashboard Widget API via `OCP\Dashboard\IManager`, stores configuration in the widget placement's JSON config, and provides a paginated API endpoint for user lookup. Results respect each user's profile visibility settings, and birthdays are computed at request time to ensure accuracy.

## Data Model

User listings are stateless and fetched per-request via `GET /api/widgets/people/{placementId}/users`. Results include:

- **userId**: Unique NC user ID
- **displayName**: User's configured display name
- **jobTitle**: From `OCP\Accounts\IAccountManager` (if visible)
- **department**: From `OCP\Accounts\IAccountManager` (if visible)
- **email**: From `OCP\Accounts\IAccountManager` (if visible)
- **phone**: From `OCP\Accounts\IAccountManager` (if visible)
- **avatarUrl**: NC's standard avatar endpoint at configured size (default 64×64)
- **groups**: Array of NC group names the user belongs to
- **daysToBirthday**: Integer (0..365) or null. Null if birthdate not set, not visible to viewer, or `showBirthdays: false`.

Widget configuration is stored in the placement's `widgetContent` JSON:

```json
{
  "layout": "grid",
  "groupFilter": [],
  "excludeDisabled": true,
  "showBirthdays": true,
  "birthdayWindowDays": 7,
  "sortBy": "displayName",
  "cardFields": ["displayName", "jobTitle", "department", "email", "phone", "avatar"]
}
```

## ADDED Requirements

### Requirement: Widget registration via dashboard API (REQ-PPL-001)

The system MUST register a widget with id `mydash_people` via `OCP\Dashboard\IManager::registerWidget()` in `appinfo/dashboard.php`. The widget MUST have:
- **id**: `mydash_people`
- **title**: Translated string (e.g., "People" / "Personen")
- **icon_url**: Path to SVG icon representing user directory
- Widget MUST be discoverable via Nextcloud's Dashboard widget discovery mechanism

#### Scenario: Widget is registered on app load
- **GIVEN** MyDash is installed and enabled
- **WHEN** Nextcloud boots and calls `IManager::getWidgets()`
- **THEN** widget `mydash_people` MUST appear in the list
- **AND** the widget's title and icon MUST be correctly set

#### Scenario: Widget can be added to a dashboard
- **GIVEN** a user wants to add a widget
- **WHEN** they open the widget picker
- **THEN** "People" (or localized variant) MUST be available
- **AND** clicking "Add" MUST create a placement with `widgetId: "mydash_people"`

#### Scenario: Widget icon is valid SVG
- **GIVEN** the widget's icon_url
- **WHEN** the dashboard renders
- **THEN** the SVG MUST load without errors
- **AND** the icon MUST follow Conduction brand guidelines (solid shapes, hex colors, no gradients)

### Requirement: Per-placement configuration (REQ-PPL-002)

Each widget placement MUST support configuration stored in the placement's `widgetContent` JSON field. Configuration keys and defaults:
- `layout: 'card'|'grid'|'list'` (default `grid`)
- `groupFilter: string[]` (default `[]` — all visible users)
- `excludeDisabled: boolean` (default `true`)
- `showBirthdays: boolean` (default `true`)
- `birthdayWindowDays: number` (default `7`, valid range 0..30)
- `sortBy: 'displayName'|'group'|'recent-activity'` (default `displayName`)
- `cardFields: string[]` (default all 6: `displayName`, `jobTitle`, `department`, `email`, `phone`, `avatar`)

The frontend MUST validate `birthdayWindowDays` is between 0 and 30 (inclusive) before saving.

#### Scenario: Config saved to placement
- **GIVEN** user configures widget layout to "card" with `groupFilter: ["management"]`
- **WHEN** they save
- **THEN** the placement's `widgetContent` JSON MUST contain `{"layout": "card", "groupFilter": ["management"], ...}`

#### Scenario: Invalid birthdayWindowDays rejected
- **GIVEN** user sets `birthdayWindowDays: 50`
- **WHEN** they save
- **THEN** the frontend MUST show validation error "Must be between 0 and 30"
- **AND** the save MUST NOT proceed

#### Scenario: Unknown sortBy value rejected
- **GIVEN** user sets `sortBy: "unknown-field"`
- **WHEN** they save
- **THEN** the frontend MUST reject with error message
- **AND** fall back to `displayName`

#### Scenario: cardFields subset validated
- **GIVEN** user configures `cardFields: ["displayName", "email"]` (omitting others)
- **WHEN** they save and the widget renders
- **THEN** only displayName and email MUST be shown on cards
- **AND** other fields MUST NOT appear

### Requirement: Paginated users API endpoint (REQ-PPL-003)

The system MUST expose `GET /api/widgets/people/{placementId}/users?cursor=&limit=50` returning a paginated list of users matching the placement's configuration.

Response shape:
```json
{
  "users": [
    {
      "userId": "alice",
      "displayName": "Alice Smith",
      "jobTitle": "Product Manager",
      "department": "Product",
      "email": "alice@example.com",
      "phone": "+31612345678",
      "avatarUrl": "/avatar/alice?size=64",
      "groups": ["management", "product"],
      "daysToBirthday": 5
    }
  ],
  "nextCursor": "alice_001"
}
```

#### Scenario: Fetch first page of users
- **GIVEN** a placement with 150 visible users
- **WHEN** user sends `GET /api/widgets/people/5/users?limit=50` (no cursor)
- **THEN** the system MUST return HTTP 200 with the first 50 users
- **AND** `nextCursor` MUST be set (non-null) to fetch the next page
- **AND** each user object MUST include all fields (subject to visibility rules)

#### Scenario: Fetch second page via cursor
- **GIVEN** `nextCursor` from the first page
- **WHEN** user sends `GET /api/widgets/people/5/users?cursor=alice_001&limit=50`
- **THEN** the system MUST return the next 50 users
- **AND** pagination MUST not overlap or skip users

#### Scenario: Last page returns null nextCursor
- **GIVEN** the last page of results (fewer than limit)
- **WHEN** user fetches that page
- **THEN** `nextCursor` MUST be `null`
- **AND** the response MUST indicate no more users available

#### Scenario: Placement not found returns 404
- **GIVEN** a non-existent placement ID
- **WHEN** user sends `GET /api/widgets/people/99999/users`
- **THEN** the system MUST return HTTP 404

#### Scenario: Invalid cursor gracefully handled
- **GIVEN** a malformed or expired cursor
- **WHEN** user sends `GET /api/widgets/people/5/users?cursor=invalid`
- **THEN** the system MUST either restart pagination from the beginning OR return HTTP 400 with clear error message

### Requirement: Profile visibility enforcement (REQ-PPL-004)

The system MUST only return profile fields the requesting viewer is allowed to see, per Nextcloud's `IAccountManager` field-level visibility settings. Hidden fields MUST be omitted entirely from the response (not included with null value).

#### Scenario: Viewer cannot see hidden email
- **GIVEN** user "alice" has set her email visibility to "private" (not visible to others)
- **AND** user "bob" is viewing the people widget
- **WHEN** bob fetches the user list
- **THEN** alice's entry MUST NOT have an `email` field
- **AND** the `email` key MUST NOT appear in the response object (not `email: null`)

#### Scenario: Viewer can see visible email
- **GIVEN** user "alice" has set her email visibility to "public" or "organization"
- **WHEN** bob fetches the user list
- **THEN** alice's entry MUST include `email: "alice@example.com"`

#### Scenario: Admin viewing their own profile sees all fields
- **GIVEN** admin "charlie" requests the people list (viewing themselves)
- **WHEN** charlie is in the results
- **THEN** charlie MUST see all their own fields regardless of privacy settings
- **NOTE**: Users may see all their own fields; privacy is for viewing others.

#### Scenario: Missing birthdate returns null or omits field
- **GIVEN** user "dave" has not set a birthdate
- **WHEN** bob fetches the people list with `showBirthdays: true`
- **THEN** dave's entry MUST have `daysToBirthday: null`
- **OR** the `daysToBirthday` field MUST be omitted entirely

### Requirement: Birthday computation (REQ-PPL-005)

The system MUST compute `daysToBirthday` at request time from the user's `birthdate` (stored in `IAccountManager`). The value MUST be the number of days until the next upcoming birthday (modulo year), or `null` if:
- Birthdate not set
- Birthdate not visible to the viewer
- `showBirthdays: false` in widget config

Leap-year dates (Feb 29) MUST be handled correctly (celebrated on Feb 28 in non-leap years, or Feb 29 in leap years).

#### Scenario: Upcoming birthday in current year
- **GIVEN** today is 2026-05-15
- **AND** user "alice" has birthdate 1990-06-10
- **WHEN** system computes `daysToBirthday`
- **THEN** result MUST be 26 (days until June 10, 2026)

#### Scenario: Past birthday this year wraps to next year
- **GIVEN** today is 2026-06-15
- **AND** user "bob" has birthdate 1990-05-10 (birthday was 36 days ago)
- **WHEN** system computes `daysToBirthday`
- **THEN** result MUST be 330 (days until May 10, 2027)

#### Scenario: Birthday today returns 0
- **GIVEN** today is 2026-06-10
- **AND** user "charlie" has birthdate 1990-06-10
- **WHEN** system computes `daysToBirthday`
- **THEN** result MUST be 0

#### Scenario: Leap-year birthday in non-leap-year
- **GIVEN** today is 2025-02-27
- **AND** user "dave" has birthdate 1990-02-29
- **WHEN** system computes `daysToBirthday` in 2025 (non-leap year)
- **THEN** result MUST be 1 (Feb 29 celebrated on Feb 28; tomorrow is Feb 28, 2025)

#### Scenario: Birthdate hidden from viewer returns null
- **GIVEN** user "eve" has marked her birthdate as private
- **AND** user "frank" is the viewer
- **WHEN** frank requests the people list
- **THEN** eve's entry MUST have `daysToBirthday: null`

#### Scenario: showBirthdays disabled returns null
- **GIVEN** placement config has `showBirthdays: false`
- **WHEN** system computes results
- **THEN** all entries MUST have `daysToBirthday: null`
- **AND** birthday information MUST NOT be sent to the frontend

### Requirement: Group filtering (REQ-PPL-006)

The system MUST apply the `groupFilter` configuration to restrict results to users in any of the specified Nextcloud groups. An empty `groupFilter: []` means all visible users. A non-empty filter MUST intersect: only users in ANY of the listed groups are returned.

#### Scenario: Empty group filter returns all visible users
- **GIVEN** placement config has `groupFilter: []`
- **WHEN** system fetches user list
- **THEN** all NC users visible to the viewer MUST be returned (subject to `excludeDisabled`, `showBirthdays`, field visibility)

#### Scenario: Single group filter
- **GIVEN** `groupFilter: ["management"]`
- **WHEN** system fetches user list
- **THEN** only users in the "management" group MUST be returned
- **AND** users in other groups MUST be excluded
- **AND** `groups` field MUST show all groups the user belongs to (not just the matched group)

#### Scenario: Multiple group filter (union)
- **GIVEN** `groupFilter: ["management", "product"]`
- **WHEN** system fetches user list
- **THEN** users in either "management" OR "product" MUST be returned
- **AND** users in both groups appear only once (not duplicated)

#### Scenario: Unknown group name handled gracefully
- **GIVEN** `groupFilter: ["nonexistent-group"]`
- **WHEN** system fetches user list
- **THEN** no users MUST be returned (intersection is empty)
- **OR** the system MUST return HTTP 400 with error message "Unknown group: nonexistent-group"

#### Scenario: Users in group appear in results with group list
- **GIVEN** user "alice" is in groups ["management", "product"]
- **AND** `groupFilter: ["management"]`
- **WHEN** system returns alice
- **THEN** alice's `groups` array MUST show `["management", "product"]` (all her groups)

### Requirement: Avatar URLs (REQ-PPL-007)

The system MUST provide avatar URLs via Nextcloud's standard avatar endpoint. The avatar size MUST be configurable via the app config key `mydash.people_widget_avatar_size` (default 64 pixels). URLs MUST point to `/avatar/{userId}?size={pixelSize}` and MUST always succeed (Nextcloud returns a placeholder for missing avatars).

#### Scenario: Avatar URL for existing user
- **GIVEN** user "alice" with avatar
- **AND** config `mydash.people_widget_avatar_size: 64`
- **WHEN** system returns alice in the user list
- **THEN** `avatarUrl` MUST be `/avatar/alice?size=64`

#### Scenario: Avatar URL for user without avatar
- **GIVEN** user "bob" with no avatar uploaded
- **WHEN** system returns bob
- **THEN** `avatarUrl` MUST still be `/avatar/bob?size=64`
- **AND** Nextcloud MUST serve a default placeholder

#### Scenario: Custom avatar size from config
- **GIVEN** admin sets `mydash.people_widget_avatar_size: 128`
- **WHEN** system fetches user list
- **THEN** all `avatarUrl` values MUST have `?size=128`

#### Scenario: Avatar size bounds validation
- **GIVEN** admin sets `mydash.people_widget_avatar_size: -5` or `50000`
- **WHEN** system fetches user list
- **THEN** invalid sizes MUST be clamped to a reasonable range (e.g., 16..1024)
- **AND** the widget MUST still render without error

### Requirement: Three layout modes (REQ-PPL-008)

The frontend MUST support three layout modes: `card`, `grid`, and `list`. Each layout MUST respect the `cardFields` configuration for which fields to display.

**Card layout** (`layout: 'card'`):
- Displays full profile cards (~200×280 px each)
- Avatar: ~64 px, top/center
- Fields stacked vertically below avatar (displayName, jobTitle, department, email, phone)
- Birthday badge "🎂 in N days" overlaid on avatar (if applicable)
- Hover effect (optional): subtle shadow/scale

**Grid layout** (`layout: 'grid'`):
- Compact grid of small cards (~80×120 px each)
- Avatar: ~48 px centered
- Display name below avatar (1-2 lines, truncated if needed)
- Birthday badge "🎂 in N days" small, bottom-right of avatar (if applicable)
- Optimized for many users on a narrow dashboard

**List layout** (`layout: 'list'`):
- Single-line rows (height ~40 px)
- Avatar: ~32 px, left side
- Name + optional secondary field (email or department, configured via `cardFields`)
- Hover: highlight row background
- Birthday badge: "🎂 in N days" on the right side (if applicable)

#### Scenario: Card layout displays full profile
- **GIVEN** `layout: 'card'`, `cardFields: ["displayName", "jobTitle", "department", "email"]`
- **WHEN** widget renders 10 users
- **THEN** each card MUST show avatar + all 4 configured fields
- **AND** cards MUST be approximately 200×280 px each
- **AND** cards MUST stack vertically or in a shallow grid

#### Scenario: Grid layout shows avatar + name only
- **GIVEN** `layout: 'grid'`, any `cardFields` value
- **WHEN** widget renders 20 users
- **THEN** each cell MUST show avatar (~48 px) + display name (2 lines max, truncated)
- **AND** cells MUST be approximately 80×120 px each
- **AND** cells MUST fit 3-4 per row on a typical dashboard

#### Scenario: List layout shows compact rows
- **GIVEN** `layout: 'list'`, `cardFields: ["displayName", "email"]`
- **WHEN** widget renders 50 users
- **THEN** each row MUST be a single line (~40 px height)
- **AND** row MUST show avatar (~32 px) + name + email
- **AND** rows MUST be scrollable if they exceed widget height

#### Scenario: Birthday badge displays when applicable
- **GIVEN** user "alice" has `daysToBirthday: 5`, `birthdayWindowDays: 7`
- **WHEN** widget renders alice in any layout
- **THEN** a "🎂 in 5 days" badge MUST appear (exact emoji/text configurable)
- **AND** badge position MUST adapt to layout (card: top-right, grid: bottom-right, list: right side)

#### Scenario: cardFields filtered per layout
- **GIVEN** `cardFields: ["displayName", "department"]` (omitting email, phone, jobTitle)
- **WHEN** card layout renders
- **THEN** only displayName and department MUST be shown
- **AND** email, phone, jobTitle MUST NOT appear

### Requirement: Empty state and error handling (REQ-PPL-009)

The widget MUST display a user-friendly message when no results are available.

#### Scenario: No users match filter
- **GIVEN** `groupFilter: ["nonexistent-group"]` (no users in group)
- **WHEN** widget renders
- **THEN** text "No matching users." MUST be displayed (localized to user's language)
- **AND** no broken/empty grid MUST appear

#### Scenario: Failed API request
- **GIVEN** the `/api/widgets/people/{placementId}/users` endpoint returns 500 error
- **WHEN** widget renders
- **THEN** error message "Failed to load users" MUST be displayed
- **AND** a "Retry" button MUST be provided
- **AND** widget MUST NOT crash or show raw error traces

#### Scenario: Placement not found
- **GIVEN** placement has been deleted
- **WHEN** widget tries to fetch users via invalid placement ID
- **THEN** widget MUST show "Widget configuration missing or invalid"
- **AND** user MUST be able to remove/reconfigure the widget

#### Scenario: Empty result after search
- **GIVEN** user searches for "zzz" (no matching names/emails)
- **WHEN** search is applied
- **THEN** message "No users match your search" MUST appear

### Requirement: Click-through to user profile (REQ-PPL-010)

Clicking on a user profile card (or avatar, or name in list view) MUST open Nextcloud's standard user profile page.

#### Scenario: Card click opens profile
- **GIVEN** user "alice" is displayed in card layout
- **WHEN** user clicks the card (or avatar or name)
- **THEN** browser MUST navigate to `/u/alice` in the SAME tab (not new tab)
- **AND** Nextcloud's user profile page MUST load

#### Scenario: List item click opens profile
- **GIVEN** user "bob" is displayed in list layout
- **WHEN** user clicks the row (or name)
- **THEN** browser MUST navigate to `/u/bob` in the same tab

#### Scenario: Avatar click opens profile
- **GIVEN** any layout
- **WHEN** user clicks the avatar image
- **THEN** browser MUST navigate to `/u/{userId}` in the same tab

#### Scenario: Profile page respects access control
- **GIVEN** user "charlie" does not have access to see user "dave"'s profile
- **WHEN** charlie tries to navigate to `/u/dave` (via widget or manually)
- **THEN** Nextcloud MUST return appropriate error page (access denied)
- **NOTE**: The widget's visibility filtering prevents dave appearing in the list if charlie cannot see them; this scenario is a safety check for direct URL access.

### Requirement: In-widget search (REQ-PPL-011)

The widget MUST provide a search input that filters displayed users by substring match on `displayName` and `email` (case-insensitive). Search operates on the current page only; pagination is not affected by search results.

#### Scenario: Search filters current page
- **GIVEN** widget is showing page 1 of 50 users
- **AND** user types "john" in search box
- **WHEN** search is applied
- **THEN** only users on the current page with "john" in displayName or email MUST be shown
- **AND** pagination to page 2 MUST NOT be affected by search

#### Scenario: Search is case-insensitive
- **GIVEN** search box contains "ALICE"
- **WHEN** search is applied
- **THEN** user "alice smith" and "Alice Johnson" MUST both match

#### Scenario: Search on email
- **GIVEN** user types "example.com" in search box
- **WHEN** search is applied
- **THEN** all users with "example.com" in their email MUST be shown

#### Scenario: Clear search restores all users
- **GIVEN** search is active and filtering results
- **WHEN** user clears the search box
- **THEN** all users on current page MUST be shown again

#### Scenario: Search input UI affordance
- **GIVEN** widget renders
- **WHEN** user looks at the widget
- **THEN** a search input MUST be visible (e.g., in the widget header or body, with placeholder "Search by name or email...")

### Requirement: Client-side caching and refresh (REQ-PPL-012)

The widget MUST cache results in memory for 60 seconds per placement. A force-refresh button MUST clear the cache and immediately re-fetch user list from the backend.

#### Scenario: Results cached for 60 seconds
- **GIVEN** user fetches the people list (first load)
- **WHEN** user closes and reopens the widget within 60 seconds
- **THEN** the second open MUST use cached results (no new API call)
- **AND** displayed data MUST match the first fetch

#### Scenario: Cache expires after 60 seconds
- **GIVEN** results were fetched at time T
- **WHEN** user opens widget at time T + 61 seconds
- **THEN** a fresh API call MUST be made
- **AND** results MAY differ if users/profiles have changed

#### Scenario: Force-refresh clears cache
- **GIVEN** cached results are displayed
- **WHEN** user clicks "Refresh" button in widget header
- **THEN** cache MUST be cleared immediately
- **AND** new API call MUST be made to backend
- **AND** results MUST update to latest state

#### Scenario: Tab/browser focus refreshes cache
- **GIVEN** user switches away from browser tab and back
- **WHEN** user returns to the tab
- **THEN** cache MAY be invalidated (optional)
- **AND** widget SHOULD refetch on re-focus (recommended UX)

## Non-Functional Requirements

- **Performance**: `GET /api/widgets/people/{placementId}/users` MUST return within 1 second for orgs with <1000 users. Pagination with limit=50 MUST be efficient (cursor-based, not offset-based).
- **Compatibility**: Widget MUST work with all Nextcloud versions supported by MyDash (currently 25+).
- **Data integrity**: User list MUST reflect real-time group membership (no stale cache on backend).
- **Accessibility**: Widget MUST support keyboard navigation (Tab through users, Enter to open profile). All fields MUST have accessible labels. Avatar images MUST have alt text.
- **Localization**: All user-facing strings MUST support English and Dutch (nl/en). Birthday computation MUST work with any user's locale.
- **Privacy**: Hidden profile fields MUST NEVER be returned to the client (not even nulled). Birthday information MUST respect visibility settings.

## Current Implementation Status

**Not yet implemented**: This is a new capability for v1 of the people widget.

**Planned follow-ups** (separate changes):
- `people-widget-presence`: Show online/away/do-not-disturb status via `OCP\UserStatus`
- `people-widget-activity`: Implement `sortBy: 'recent-activity'` via `OCP\Activity\IManager`
- `people-widget-export`: CSV export of visible users + birthdays
- `people-widget-acl`: Per-user visibility rules (e.g., HR department only)

### Standards & References

- Nextcloud Dashboard Widget API: `OCP\Dashboard\IManager::registerWidget()`, `IWidget` interface
- Nextcloud User Management: `OCP\IUserManager::getUsers()`, `OCP\IGroupManager::displayNamesInGroup()`
- Nextcloud Profile: `OCP\Accounts\IAccountManager` (field-level visibility, birthdate storage)
- Nextcloud User Status: `OCP\UserStatus\IManager` (for presence, future feature)
- Avatar serving: `/avatar/{userId}?size=N` standard NC endpoint
- WCAG 2.1 AA: Alt text for avatars, keyboard navigation, color contrast for badges
- WAI-ARIA: List roles, button semantics for profile clicks and search
