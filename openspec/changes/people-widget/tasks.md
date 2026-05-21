# Tasks: people-widget

## Backend Implementation

### People Service (lib/Service/PeopleService.php)

- [ ] Create `PeopleService` class with constructor dependency injection:
  - `IUserManager` — list and fetch users
  - `IGroupManager` — look up group membership
  - `IAccountManager` — read profile fields (email, phone, role, organisation, pronouns, headline, biography, address, website, social links, birthdate)
  - `IUserStatusManager` — fetch user status (optional)
  - `IConfig` — app config (cache settings)
  - `LoggerInterface` — error logging

- [ ] Implement `fetchUsers()` method:
  - Accept parameters: `filters` array, `limit` (1..100), `offset`, `sortBy`, `excludeDisabled`, `showBirthdays`
  - Return array with keys: `users`, `total`, `hasMore`
  - Parse and validate filters (group, birthday)
  - Apply group filter using `IGroupManager::get($groupId)->getUsers()` with union (OR) strategy and deduplication
  - Apply birthday window filter using `within_next_days` operator
  - Apply disabled-user exclusion via `IUser::isEnabled()`
  - Normalize birthdates from locale-specific formats to ISO 8601
  - Sort by `displayName` (default), `group`, or `recent-activity` (stub for now)
  - Paginate using offset + limit
  - Build user response objects with all non-empty profile fields

- [ ] Implement `buildUserObject()` method:
  - Input: `IUser` instance, `showFields` config, `showBirthdays` flag
  - Output: associative array with uid, displayName, email, phone, role, organisation, pronouns, headline, biography, address, website, twitter, bluesky, fediverse, birthdate, groups, status
  - Fetch avatar URL via `IURLGenerator::linkToRoute('core.avatar.getAvatar', ['userId' => $uid, 'size' => 128])`
  - Omit fields with empty/null values
  - Omit birthdate if `showBirthdays: false`
  - Omit status if not available

- [ ] Implement `normalizeBirthdate()` helper:
  - Accept raw birthdate string in formats: DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY, ISO 8601
  - Return ISO 8601 string or null if parsing fails

- [ ] Implement `filterByBirthdayWindow()` method:
  - Accept users array, window days (0..30)
  - Include users whose birthday (month-day) falls within next N days from today
  - Add Feb-29 guard (TODO for follow-up): substitute Feb-28 in non-leap years

- [ ] Implement caching layer (APCu):
  - Cache key: hash of filters + sortBy + showBirthdays (NOT per-viewer in v1)
  - TTL: 1 hour
  - Cache only non-group-filter queries (group filters always fresh)
  - Add cache invalidation method for admin-triggered refreshes (future)

## People Controller (lib/Controller/PeopleController.php)

- [ ] Create `PeopleController` class extending `OCP\AppFramework\Controller`:
  - Constructor: inject `IRequest`, `PeopleService`, `ILogger`
  - Endpoint: `GET /api/people`

- [ ] Implement `getPeople()` public method:
  - Route attribute: `@Route("/people", method="GET")`
  - Accept query parameters:
    - `filters` (URL-encoded JSON array, default `[]`)
    - `limit` (integer, default 50, max 100)
    - `offset` (integer, default 0)
    - `sortBy` (string, default 'displayName')
    - `excludeDisabled` (boolean, default true)
    - `showBirthdays` (boolean, default true)

  - Validate `limit` (1..100); return 400 if exceeded
  - Validate `offset` is non-negative integer; return 400 if invalid
  - Parse JSON filters; return 400 if malformed
  - Call `PeopleService::fetchUsers()` with validated parameters
  - Return HTTP 200 with JSON response: `{users: [...], total: N, hasMore: bool}`
  - Catch exceptions and return HTTP 500 with error message on failure

## Routes (appinfo/routes.php)

- [ ] Register route in `appinfo/routes.php`:
  - URL pattern: `api/people`
  - Method: `GET`
  - Controller: `PeopleController`
  - Action: `getPeople`
  - No authentication bypass required (authenticated users only, per Dashboard convention)

## Dashboard Registration (appinfo/dashboard.php)

- [ ] Create or update `appinfo/dashboard.php`:
  - Call `IManager::registerWidget()` with:
    - `id`: `mydash_people`
    - `title`: Translated string via `t()` function
    - `icon_url`: Path to SVG icon (e.g., `img/people.svg`)
    - `options`: Optional widget configuration template (if supported)

## Frontend: Vue Components

### Main Renderer (src/components/Widgets/Renderers/PeopleWidget.vue)

- [ ] Create component with:
  - Props: `content` (placement config JSON), `placement` (placement object)
  - State: `users` array, `loading` boolean, `error` string, `currentPage`, `cache` object, `cacheExpiry`
  - Methods:
    - `fetchUsers()` — call API with current config, store in cache with 60s TTL
    - `getFromCache()` — check cache validity, return cached results if fresh
    - `clearCache()` — explicitly clear cache and refetch
    - `refreshData()` — force-refresh button handler
    - `parseFilters()` — convert config filters to API query string
    - `handlePageChange()` — pagination handler

  - Lifecycle:
    - `onMounted`: fetch initial data via cache/API
    - `onActivated`: check cache and refetch if expired (optional, for tab focus)

  - Template structure:
    - Header: title + refresh button + search input
    - Loading state: spinner
    - Error state: error message + retry button
    - Empty state: "No matching users" message
    - Layout selector: conditional render of PeopleCardLayout / PeopleGridLayout / PeopleListLayout
    - Pagination: CnPagination component or custom offset-based nav

- [ ] Implement client-side search on current page:
  - Filter displayed users by displayName or email match (case-insensitive)
  - Do not refetch API; search only on already-loaded page
  - Show "No users match your search" if search yields no results

- [ ] API error handling:
  - 500 error: show "Failed to load users" + retry button
  - 400 error: show form validation error (invalid limit, offset, etc.)
  - Network timeout: show "Connection failed" + retry button

### Card Layout (src/components/Widgets/Renderers/PeopleCardLayout.vue)

- [ ] Create sub-component with:
  - Props: `users` array, `columns` (2/3/4), `showFields` object
  - Render CSS grid with `grid-template-columns: repeat(${columns}, 1fr)`
  - Per-card template:
    - Avatar: 80 px image, rounded
    - Display name: bold text, clickable link to `/u/{uid}`
    - Conditional fields per `showFields`: role, organisation, email, phone, pronouns, headline, birthdate
    - Hover effect: subtle shadow/scale (optional)

- [ ] Avatar click target: link to `/u/{uid}`
- [ ] Card click target: link to `/u/{uid}` (or set cursor: pointer on entire card)

### Grid Layout (src/components/Widgets/Renderers/PeopleGridLayout.vue)

- [ ] Create sub-component with:
  - Props: `users` array, `columns` (2/3/4), `showFields` object
  - Render compact grid (~80×120 px cells)
  - Per-cell template:
    - Avatar: 64 px, centered
    - Display name: 1–2 lines, text-overflow ellipsis, clickable link to `/u/{uid}`
  - No other fields shown (compact mode)

### List Layout (src/components/Widgets/Renderers/PeopleListLayout.vue)

- [ ] Create sub-component with:
  - Props: `users` array, `showFields` object
  - Render single-line rows (~40 px height)
  - Per-row template:
    - Avatar: 44 px, left side
    - Display name: clickable link to `/u/{uid}`
    - Secondary field (email or organisation, per `showFields`)
    - Hover: row background highlight
  - Scrollable container if exceeds widget height

### Configuration Form (src/components/Widgets/Forms/PeopleForm.vue)

- [ ] Create sub-component integrated into AddWidgetModal with:
  - Props: `placement` (initial config or empty object)
  - State: form data (layout, sortBy, filters, birthdayWindowDays, excludeDisabled, showBirthdays, columns, showFields)

  - Form sections:
    1. **Layout selector**: radio buttons for card/grid/list
    2. **Sort selector**: dropdown (displayName, group, recent-activity)
    3. **Excluded users**: checkbox "Exclude disabled users"
    4. **Birthday settings**:
       - Checkbox "Show birthdays"
       - Number input "Upcoming birthdays within N days" (0–30, default 7)
       - Validation: error if outside 0..30 range
    5. **Filters**: 
       - "Add filter" button → dropdown (group, birthday, etc.)
       - Per-filter row: field selector + operator + values
       - Remove button per filter
       - Filter combinator: AND/OR radio buttons
    6. **Show fields**: checkboxes for each field (displayName, email, phone, role, organisation, pronouns, headline, biography, address, website, twitter, bluesky, fediverse, birthdate, avatar)
    7. **Columns**: number input (2–4, context-aware default: 3 for card, 4 for grid)
    8. **Save/Cancel buttons**

  - Validation:
    - `birthdayWindowDays` must be 0–30; show error and prevent save if outside range
    - `sortBy` must be one of allowed values; reject unknown values
    - `columns` must be 2–4; reject out-of-range

  - Submission:
    - Emit or callback with validated form data
    - Call placement save API with `widgetContent: {...formData}`

  - Localization: all labels, placeholders, validation messages in English (en) and Dutch (nl)

## Widget Registry (src/constants/widgetRegistry.js)

- [ ] Register widget type in `widgetRegistry`:
  ```javascript
  {
    component: PeopleWidget,
    label: t('People'),
    defaults: {
      layout: 'grid',
      selectionMode: 'filter',
      selectedUsers: [],
      filters: [],
      filterOperator: 'AND',
      excludeDisabled: true,
      showBirthdays: true,
      birthdayWindowDays: 7,
      sortBy: 'displayName',
      columns: 4,
      showFields: {
        displayName: true,
        email: true,
        phone: true,
        role: true,
        organisation: true,
        avatar: true,
        birthdate: true
      }
    },
    requires: {} // No external app dependencies
  }
  ```

## Manifest (src/manifest.json)

- [ ] Add widget entry to `manifest.json` `widgets` array:
  ```json
  {
    "id": "mydash_people",
    "title": "People",
    "icon": "img/people.svg",
    "component": "PeopleWidget",
    "defaultRoles": [],
    "restricted": false
  }
  ```

## Translations (l10n/en.json, l10n/nl.json)

### English (l10n/en.json)

- [ ] `People` — widget title
- [ ] `Search by name or email...` — search input placeholder
- [ ] `No matching users.` — empty result message
- [ ] `Failed to load users` — error message
- [ ] `Retry` — retry button
- [ ] `Refresh` — force-refresh button
- [ ] `Card Layout`, `Grid Layout`, `List Layout` — layout option labels
- [ ] `Show Birthdays` — checkbox label
- [ ] `Upcoming birthdays within` — label
- [ ] `days` — unit label
- [ ] `Exclude Disabled Users` — checkbox label
- [ ] `Filter by Group` — filter option label
- [ ] `Filter Operator` — AND/OR selector label
- [ ] Display field names: `Display Name`, `Email`, `Phone`, `Role`, `Organisation`, `Pronouns`, `Headline`, `Biography`, `Address`, `Website`, `Twitter`, `Bluesky`, `Fediverse`, `Birthdate`, `Avatar`
- [ ] `Columns` — layout configuration label
- [ ] `Must be between 0 and 30` — validation error for birthdayWindowDays
- [ ] `Unknown sort field` — validation error for sortBy
- [ ] `No users match your search` — search empty-state message

### Dutch (l10n/nl.json)

- [ ] All of the above translated to Dutch (nl)
- [ ] Special attention to number formatting: "dagen" (days)
- [ ] Field names should match Nextcloud NC terminology if possible

## Accessibility & Testing

- [ ] Avatar alt text: `"Avatar of {displayName}"` for each user
- [ ] Keyboard navigation: Tab through user cards/rows, Enter to open profile
- [ ] ARIA labels on:
  - Search input: `aria-label="Search users"`
  - Refresh button: `aria-label="Refresh user list"`
  - Layout selector: `aria-label="Change layout"`
  - Pagination: `aria-label="Page X of Y"`

- [ ] Color contrast: all text must meet WCAG AA (4.5:1 for normal text)
- [ ] Link semantics: profile links use `<a href="/u/{uid}">` not `<button @click>`

## Icon Asset

- [ ] Create or find SVG icon for widget (`img/people.svg`):
  - 24×24 px or scalable
  - Follows Conduction brand (solid shapes, hex colors, no gradients)
  - Represents "people" / "users" (e.g., group silhouettes, people icon)

## Documentation

- [ ] Add widget to MyDash widget gallery / README section listing all widgets
- [ ] Document API endpoint: `/api/people` query parameters, response shape, error codes
- [ ] Document configuration shape in widget schema (if publishing to Nextcloud apps marketplace)

## Integration & QA

- [ ] End-to-end test: widget appears in dashboard widget picker
- [ ] End-to-end test: add widget to dashboard, see users displayed
- [ ] Manual QA: verify card/grid/list layouts render correctly
- [ ] Manual QA: verify group filter restricts users correctly
- [ ] Manual QA: verify birthday window filter includes upcoming birthdays
- [ ] Manual QA: verify client-side search filters on current page only
- [ ] Manual QA: verify 60-second cache; refresh button bypasses cache
- [ ] Manual QA: verify clicking user card opens `/u/{uid}`
- [ ] Manual QA: verify error handling (API 500, network timeout, etc.)
- [ ] Manual QA: verify empty state displays when no users match filter
- [ ] Manual QA: verify pagination offset + limit works correctly
- [ ] Manual QA: verify form validation (birthdayWindowDays 0–30, etc.)
- [ ] Manual QA: test with 10, 100, 1000+ users to verify performance (<1s API response)
- [ ] Manual QA: test on desktop (1920×1080), tablet (768×1024), mobile (375×667) viewport sizes
- [ ] Manual QA: test keyboard navigation (Tab, Enter, Escape)
- [ ] Manual QA: verify all translatable strings appear in en.json and nl.json

## Deduplication Check

- [ ] Verify no overlap with existing MyDash people browsing or user list capabilities (none exist)
- [ ] Verify no custom service class overlap with `@conduction/nextcloud-vue` or `ObjectService` (none applicable — widget is read-only)
- [ ] Verify API endpoint (`GET /api/people`) is unique to people-widget (no duplicate routes)
- [ ] Document decision: PeopleService is a thin data-access layer around Nextcloud's IUserManager, IAccountManager, not a domain entity mapper (confirmed lightweight design)
