# Specifications: people-widget

## REQ-PPL-001: Widget registration via dashboard API

The system MUST register a widget with id `mydash_people` via `OCP\Dashboard\IManager::registerWidget()` in `appinfo/dashboard.php`. The widget MUST have:
- **id**: `mydash_people`
- **title**: Translated string (e.g., "People" / "Personen")
- **icon_url**: Path to SVG icon representing user directory
- Widget MUST be discoverable via Nextcloud's Dashboard widget discovery mechanism

### Scenario: Widget is registered on app load
- **GIVEN** MyDash is installed and enabled
- **WHEN** Nextcloud boots and calls `IManager::getWidgets()`
- **THEN** widget `mydash_people` MUST appear in the list
- **AND** the widget's title and icon MUST be correctly set

### Scenario: Widget can be added to a dashboard
- **GIVEN** a user wants to add a widget
- **WHEN** they open the widget picker
- **THEN** "People" (or localized variant) MUST be available
- **AND** clicking "Add" MUST create a placement with `widgetId: "mydash_people"`

### Scenario: Widget icon is valid SVG
- **GIVEN** the widget's icon_url
- **WHEN** the dashboard renders
- **THEN** the SVG MUST load without errors
- **AND** the icon MUST follow Conduction brand guidelines

## REQ-PPL-002: Per-placement configuration

Each widget placement MUST support configuration stored in the placement's `widgetContent` JSON field. Configuration keys and defaults:
- `layout: 'card'|'grid'|'list'` (default `grid`)
- `selectionMode: 'manual'|'filter'` (default `filter`)
- `selectedUsers: string[]` (default `[]`)
- `filters: FilterObject[]` (default `[]`)
- `filterOperator: 'AND'|'OR'` (default `'AND'`)
- `excludeDisabled: boolean` (default `true`)
- `showBirthdays: boolean` (default `true`)
- `birthdayWindowDays: number` (default `7`, valid range 0..30)
- `sortBy: 'displayName'|'group'|'recent-activity'` (default `displayName`)
- `columns: 2|3|4` (default `3`)
- `showFields: object` (map of field name to boolean; default all `true`)

The frontend MUST validate `birthdayWindowDays` is between 0 and 30 (inclusive) before saving.

### Scenario: Config saved to placement
- **GIVEN** user configures widget layout to "card" with a group filter for "management"
- **WHEN** they save
- **THEN** the placement's `widgetContent` JSON MUST contain `{"layout": "card", "filters": [{"fieldName": "group", "operator": "in", "values": ["management"]}], ...}`

### Scenario: Invalid birthdayWindowDays rejected
- **GIVEN** user sets `birthdayWindowDays: 50`
- **WHEN** they save
- **THEN** the frontend MUST show validation error "Must be between 0 and 30"
- **AND** the save MUST NOT proceed

### Scenario: Unknown sortBy value rejected
- **GIVEN** user sets `sortBy: "unknown-field"`
- **WHEN** they save
- **THEN** the frontend MUST reject with error message
- **AND** fall back to `displayName`

### Scenario: showFields subset validated
- **GIVEN** user configures `showFields: {"displayName": true, "email": true}` (omitting others)
- **WHEN** they save and the widget renders
- **THEN** only displayName and email MUST be shown on cards
- **AND** other fields MUST NOT appear

## REQ-PPL-003: Paginated users API endpoint

The system MUST expose `GET /api/people?filters=...&limit=50&offset=0` returning a paginated list of users matching the request parameters. Pagination is offset-based. Maximum `limit` is 100.

Response shape:
```json
{
  "users": [...],
  "total": 150,
  "hasMore": true
}
```

### Scenario: Fetch first page of users
- **GIVEN** 150 visible users
- **WHEN** client sends `GET /api/people?limit=50&offset=0`
- **THEN** the system MUST return HTTP 200 with the first 50 users
- **AND** `hasMore` MUST be `true`
- **AND** `total` MUST be `150`
- **AND** each user object MUST include all non-empty fields

### Scenario: Fetch second page via offset
- **GIVEN** a first page of 50 users was fetched
- **WHEN** client sends `GET /api/people?limit=50&offset=50`
- **THEN** the system MUST return the next 50 users
- **AND** results MUST NOT overlap with the first page

### Scenario: Last page returns hasMore false
- **GIVEN** the last page of results
- **WHEN** client fetches that page
- **THEN** `hasMore` MUST be `false`
- **AND** `total` MUST reflect the full result count

### Scenario: limit exceeds maximum returns 400
- **GIVEN** client sends `GET /api/people?limit=200`
- **WHEN** request is processed
- **THEN** the system MUST return HTTP 400
- **AND** response MUST contain error message indicating maximum is 100

### Scenario: Invalid offset or limit type handled
- **GIVEN** a malformed offset or limit (e.g. negative or non-integer)
- **WHEN** client sends the request
- **THEN** the system MUST return HTTP 400 with a clear error message

## REQ-PPL-004: Profile visibility enforcement

The system MUST NOT return empty or null field values — all returned fields MUST have a non-empty value. The system SHOULD only return profile fields the requesting viewer is allowed to see, per Nextcloud's `IAccountManager` field-level visibility settings. Hidden fields SHOULD be omitted entirely from the response.

**NOTE (v1 limitation)**: The current implementation reads `IAccountManager` properties unconditionally. All non-empty profile fields are returned to any authenticated viewer regardless of the user's privacy settings. Enforcing NC scope-based visibility is a planned follow-up.

**NOTE (caching risk)**: The shared APCu cache is keyed only on filter + sort parameters, not on the requesting user. If scope-based visibility is enforced in a future revision, this cache MUST be made per-viewer or disabled.

### Scenario: Viewer cannot see hidden email (future)
- **GIVEN** user "alice" has set her email visibility to "private"
- **AND** user "bob" is viewing the people widget
- **WHEN** bob fetches the user list
- **THEN** alice's entry SHOULD NOT have an `email` field
- **NOTE**: This scenario describes the target state; scope-based filtering is not enforced in v1

### Scenario: Viewer can see visible email (future)
- **GIVEN** user "alice" has set her email visibility to "public"
- **WHEN** bob fetches the user list
- **THEN** alice's entry MUST include `email: "alice@example.com"`

### Scenario: Admin viewing their own profile sees all fields
- **GIVEN** admin "charlie" requests the people list
- **WHEN** charlie is in the results
- **THEN** charlie MUST see all their own fields regardless of privacy settings

### Scenario: Missing birthdate returns omitted field
- **GIVEN** user "dave" has not set a birthdate
- **WHEN** bob fetches the people list with `showBirthdays: true`
- **THEN** dave's entry MUST NOT include a `birthdate` field (omitted, not nulled)

## REQ-PPL-005: Birthday field

The system MUST return `birthdate` as an ISO 8601 date string (e.g. `"1990-06-10"`) when the user has a birthdate set in `IAccountManager::PROPERTY_BIRTHDATE`. The raw value may be stored in locale-specific formats (DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY) and MUST be normalized to ISO 8601 server-side before being returned. If the birthdate is not set the field MUST be omitted from the response.

Birthday window filtering is expressed as a filter object `{fieldName: "birthday", operator: "within_next_days", values: [7]}`. This filter is computed server-side but the `birthdate` string is what is returned in the response.

Days-to-birthday display logic MUST be computed client-side from the returned `birthdate` string.

**NOTE (Feb-29 guard)**: The `within_next_days` filter operator constructs dates without a Feb-29 guard. This will throw an exception on non-leap years for users with a Feb-29 birthday. A guard must be added in a follow-up.

### Scenario: Upcoming birthday returned as ISO string
- **GIVEN** today is 2026-05-15
- **AND** user "alice" has birthdate stored as "10-06-1990"
- **WHEN** system returns alice's profile
- **THEN** `birthdate` MUST be `"1990-06-10"` (ISO 8601 normalized)

### Scenario: Birthday filter within_next_days
- **GIVEN** placement config requests birthday filter `within_next_days: 7`
- **AND** today is 2026-05-15
- **WHEN** system fetches user list
- **THEN** only users whose birthday falls between 2026-05-15 and 2026-05-22 MUST be included
- **AND** each included user's `birthdate` MUST be the ISO date string

### Scenario: Birthday today is included
- **GIVEN** today is 2026-06-10
- **AND** user "charlie" has birthdate 1990-06-10
- **WHEN** system applies `within_next_days: 7` filter
- **THEN** charlie MUST be included in results
- **AND** `birthdate` MUST be `"1990-06-10"`

### Scenario: Missing birthdate omits field
- **GIVEN** user "dave" has not set a birthdate
- **WHEN** any request is processed
- **THEN** dave's entry MUST NOT include a `birthdate` field

### Scenario: showBirthdays false omits birthdate
- **GIVEN** placement config has `showBirthdays: false`
- **WHEN** system computes results
- **THEN** all entries MUST NOT include a `birthdate` field

## REQ-PPL-006: Group filtering

The system MUST apply group filters expressed as filter objects (where `fieldName === 'group'`) to restrict results to users in any of the specified Nextcloud groups. An empty `filters` array means all visible users. A group filter MUST use a union (OR) strategy: users in ANY of the listed group values are included. Deduplication MUST be applied so users appearing in multiple groups appear only once.

### Scenario: Empty filters returns all visible users
- **GIVEN** placement config has `filters: []`
- **WHEN** system fetches user list
- **THEN** all NC users visible to the viewer MUST be returned (subject to `excludeDisabled`)

### Scenario: Single group filter
- **GIVEN** `filters: [{"fieldName": "group", "operator": "in", "values": ["management"]}]`
- **WHEN** system fetches user list
- **THEN** only users in the "management" group MUST be returned
- **AND** users in other groups MUST be excluded
- **AND** `groups` field MUST show all groups the user belongs to

### Scenario: Multiple group values (union)
- **GIVEN** `filters: [{"fieldName": "group", "operator": "in", "values": ["management", "product"]}]`
- **WHEN** system fetches user list
- **THEN** users in either "management" OR "product" MUST be returned
- **AND** users in both groups MUST appear only once (deduplicated)

### Scenario: Unknown group name handled gracefully
- **GIVEN** `filters: [{"fieldName": "group", "operator": "in", "values": ["nonexistent-group"]}]`
- **WHEN** system fetches user list
- **THEN** no users MUST be returned
- **AND** the system MUST return HTTP 200 with an empty `users` array

### Scenario: Users in group appear with full group list
- **GIVEN** user "alice" is in groups ["management", "product"]
- **AND** `filters: [{"fieldName": "group", "operator": "in", "values": ["management"]}]`
- **WHEN** system returns alice
- **THEN** alice's `groups` array MUST show `["management", "product"]`

## REQ-PPL-007: Avatar URLs

The system MUST provide absolute avatar URLs via Nextcloud's standard avatar route. The avatar route MUST always be fetched at 128 px resolution. Display size is layout-dependent and controlled by the frontend component (80 px for card, 64 px for grid, 44 px for list). Nextcloud returns a generated placeholder avatar for users without an uploaded avatar.

### Scenario: Avatar URL for existing user
- **GIVEN** user "alice" with an uploaded avatar
- **WHEN** system returns alice in the user list
- **THEN** `avatarUrl` MUST be an absolute URL at size 128

### Scenario: Avatar URL for user without avatar
- **GIVEN** user "bob" with no avatar uploaded
- **WHEN** system returns bob
- **THEN** `avatarUrl` MUST be a valid URL pointing to NC's avatar route
- **AND** Nextcloud MUST serve a generated placeholder avatar

### Scenario: Avatar displayed at layout-appropriate size
- **GIVEN** widget is rendering in card layout
- **WHEN** frontend receives `avatarUrl` (128 px source)
- **THEN** the avatar MUST be displayed at 80 px in card layout
- **AND** at 64 px in grid layout
- **AND** at 44 px in list layout

## REQ-PPL-008: Three layout modes

The frontend MUST support three layout modes: `card`, `list`, and `grid`. Each layout MUST respect the `showFields` configuration for which fields to display.

### Scenario: Card layout displays full profile
- **GIVEN** `layout: 'card'`, `showFields: {"displayName": true, "role": true, "organisation": true, "email": true}`
- **WHEN** widget renders 10 users
- **THEN** each card MUST show avatar (80 px) + all 4 configured fields
- **AND** cards MUST be ~200×280 px each
- **AND** cards MUST be arranged according to the `columns` config (default 3 per row)

### Scenario: Grid layout shows avatar + name only
- **GIVEN** `layout: 'grid'`, any `showFields` value
- **WHEN** widget renders 20 users
- **THEN** each cell MUST show avatar (64 px) + display name (2 lines max, truncated)
- **AND** cells MUST be ~80×120 px each
- **AND** cells arranged according to `columns` config (default 4 per row)

### Scenario: List layout shows compact rows
- **GIVEN** `layout: 'list'`, `showFields: {"displayName": true, "email": true}`
- **WHEN** widget renders 50 users
- **THEN** each row MUST be ~40 px height
- **AND** row MUST show avatar (44 px) + name + email
- **AND** rows MUST be scrollable if they exceed widget height

### Scenario: showFields filtered per layout
- **GIVEN** `showFields: {"displayName": true, "organisation": true}` (omitting email, phone, role)
- **WHEN** card layout renders
- **THEN** only displayName and organisation MUST be shown
- **AND** email, phone, and role MUST NOT appear

## REQ-PPL-009: Empty state and error handling

The widget MUST display a user-friendly message when no results are available.

### Scenario: No users match filter
- **GIVEN** `filters: [{"fieldName": "group", "operator": "in", "values": ["nonexistent-group"]}]`
- **WHEN** widget renders
- **THEN** text "No matching users." MUST be displayed (localized)
- **AND** no broken grid MUST appear

### Scenario: Failed API request
- **GIVEN** the `/api/people` endpoint returns 500 error
- **WHEN** widget renders
- **THEN** error message "Failed to load users" MUST be displayed
- **AND** a "Retry" button MUST be provided
- **AND** widget MUST NOT crash

### Scenario: Empty result after search
- **GIVEN** user searches for "zzz" (no matching names/emails)
- **WHEN** search is applied
- **THEN** message "No users match your search" MUST appear

## REQ-PPL-010: Click-through to user profile

Clicking on a user profile card (or avatar, or name in list view) MUST open Nextcloud's standard user profile page.

### Scenario: Card click opens profile
- **GIVEN** user "alice" is displayed in card layout
- **WHEN** user clicks the card (or avatar or name)
- **THEN** browser MUST navigate to `/u/alice` in the same tab
- **AND** Nextcloud's user profile page MUST load

### Scenario: List item click opens profile
- **GIVEN** user "bob" is displayed in list layout
- **WHEN** user clicks the row (or name)
- **THEN** browser MUST navigate to `/u/bob` in the same tab

### Scenario: Avatar click opens profile
- **GIVEN** any layout
- **WHEN** user clicks the avatar image
- **THEN** browser MUST navigate to `/u/{uid}` in the same tab

### Scenario: Profile page respects access control
- **GIVEN** user "charlie" cannot access user "dave"'s profile
- **WHEN** charlie tries to navigate to `/u/dave`
- **THEN** Nextcloud MUST return appropriate error page

## REQ-PPL-011: In-widget search

The widget MUST provide a search input that filters displayed users by substring match on `displayName` and `email` (case-insensitive). Search operates on the current page only.

### Scenario: Search filters current page
- **GIVEN** widget is showing page 1 of 50 users
- **AND** user types "john" in search box
- **WHEN** search is applied
- **THEN** only users on the current page with "john" in displayName or email MUST be shown

### Scenario: Search is case-insensitive
- **GIVEN** search box contains "ALICE"
- **WHEN** search is applied
- **THEN** user "alice smith" and "Alice Johnson" MUST both match

### Scenario: Search on email
- **GIVEN** user types "example.com" in search box
- **WHEN** search is applied
- **THEN** all users with "example.com" in their email MUST be shown

### Scenario: Clear search restores all users
- **GIVEN** search is active and filtering results
- **WHEN** user clears the search box
- **THEN** all users on current page MUST be shown again

### Scenario: Search input UI affordance
- **GIVEN** widget renders
- **WHEN** user looks at the widget
- **THEN** a search input MUST be visible with placeholder "Search by name or email..."

## REQ-PPL-012: Client-side caching and refresh

The widget MUST cache results in memory for 60 seconds per placement. A force-refresh button MUST clear the cache and immediately re-fetch user list from the backend.

### Scenario: Results cached for 60 seconds
- **GIVEN** user fetches the people list (first load)
- **WHEN** user closes and reopens the widget within 60 seconds
- **THEN** the second open MUST use cached results (no new API call)
- **AND** displayed data MUST match the first fetch

### Scenario: Cache expires after 60 seconds
- **GIVEN** results were fetched at time T
- **WHEN** user opens widget at time T + 61 seconds
- **THEN** a fresh API call MUST be made
- **AND** results MAY differ if users/profiles have changed

### Scenario: Force-refresh clears cache
- **GIVEN** cached results are displayed
- **WHEN** user clicks "Refresh" button in widget header
- **THEN** cache MUST be cleared immediately
- **AND** new API call MUST be made to backend
- **AND** results MUST update to latest state

### Scenario: Tab/browser focus refreshes cache
- **GIVEN** user switches away from browser tab and back
- **WHEN** user returns to the tab
- **THEN** cache MAY be invalidated (optional)
- **AND** widget SHOULD refetch on re-focus (recommended UX)
