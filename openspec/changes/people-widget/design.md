# Design: people-widget

## Context

MyDash ships with 17 built-in widgets (image, text, link-button, calendar, files, news, video, quicklinks, etc.) but lacks a first-class people directory. Administrators and team members currently depend on Nextcloud's admin user list UI (requires admin panel access) or manual email/contact lookups. A dashboard-integrated people widget exposes discoverable user profiles inline without leaving the dashboard surface.

The existing dashboard infrastructure already provides:
- Widget registration via `OCP\Dashboard\IManager` (used by every other widget)
- Placement-level JSON config storage (existing pattern; content shape is widget-specific)
- GridStack layout rendering with per-widget add/edit modals
- Translation / i18n infrastructure (`transifex`, English + Dutch)
- Avatar service via Nextcloud's `core.avatar.getAvatar` route

What's **missing** is a backend API endpoint that lists users with their profile fields from `IAccountManager`, with pagination, filtering (groups, birthdate window, disabled-user exclusion), and sorting.

## Reuse Analysis

The people widget reuses existing capabilities and no new infrastructure:

- `widgets` (existing capability) — registration, placement, config storage
- `widget-add-edit-modal` (existing pattern) — configuration form sub-component
- `widgetRegistry.js` (existing convention) — declare widget type, defaults, renderer component
- `CnPagination` + `CnDataTable` (from `@conduction/nextcloud-vue`) — table layout, pagination UI (future refinement)
- `@conduction/nextcloud-vue` translation function — for widget strings

**No new MyDash infrastructure, services, or database tables.** The widget is a thin HTTP client over Nextcloud's user APIs.

## API Response Shape

Endpoint: `GET /api/people?filters=<json>&limit=50&offset=0&sortBy=displayName&showBirthdays=true`

Request query parameters:
- `filters`: URL-encoded JSON array of filter objects. Example: `[{"fieldName":"group","operator":"in","values":["management"]}]`
- `limit`: integer, 1..100, default 50
- `offset`: integer, default 0
- `sortBy`: 'displayName' | 'group' | 'recent-activity', default 'displayName'
- `excludeDisabled`: boolean, default true
- `showBirthdays`: boolean, default true (if false, birthdate field is omitted from response)

Response (HTTP 200):
```json
{
  "users": [
    {
      "uid": "alice",
      "displayName": "Alice Smith",
      "email": "alice@example.com",
      "phone": "+31 6 12345678",
      "avatarUrl": "https://example.com/avatar.php?userid=alice&size=128",
      "groups": ["management", "product"],
      "role": "Product Manager",
      "organisation": "Product Division",
      "pronouns": "she/her",
      "headline": "Passionate about user experience",
      "biography": "10+ years in product management...",
      "address": "Amsterdam, NL",
      "website": "https://alice.example.com",
      "twitter": "@alice",
      "birthdate": "1990-06-10",
      "status": "online"
    }
  ],
  "total": 150,
  "hasMore": true
}
```

Notes on field inclusion:
- Fields are omitted (not null) when empty
- Avatar URL is always present (Nextcloud generates placeholder)
- `groups` array always present (may be empty for users in no groups)
- `birthdate`: ISO 8601 format, omitted if not set or if `showBirthdays: false`
- `status`: optional, omitted if `IUserStatusManager` is unavailable

## Widget Configuration (Placement JSON)

Stored in `oc_mydash_widget_placements.widgetContent`:

```json
{
  "layout": "grid",
  "selectionMode": "filter",
  "selectedUsers": [],
  "filters": [
    {"fieldName": "group", "operator": "in", "values": ["management"]}
  ],
  "filterOperator": "AND",
  "excludeDisabled": true,
  "showBirthdays": true,
  "birthdayWindowDays": 7,
  "sortBy": "displayName",
  "columns": 3,
  "showFields": {
    "displayName": true,
    "role": true,
    "organisation": true,
    "email": true,
    "phone": true,
    "avatar": true,
    "birthdate": true
  }
}
```

Defaults:
- `layout: 'grid'`
- `selectionMode: 'filter'`
- `selectedUsers: []`
- `filters: []` (no filtering by default)
- `filterOperator: 'AND'`
- `excludeDisabled: true`
- `showBirthdays: true`
- `birthdayWindowDays: 7`
- `sortBy: 'displayName'`
- `columns: 3` (for card: 3, for grid: 4)
- `showFields`: all `true`

## Layout Specifications

### Card Layout

- ~200×280 px per card
- Avatar: 80 px, centered top
- Fields stacked vertically (displayName, role, organisation, email, phone, optional fields per `showFields`)
- Hover: subtle shadow/scale effect (optional)
- Column count: configurable 2/3/4 (default 3)

### Grid Layout

- ~80×120 px per cell
- Avatar: 64 px, centered
- Display name: 1–2 lines, truncated
- Column count: configurable 2/3/4 (default 4)
- Optimized for many users, narrow dashboard

### List Layout

- Single rows, ~40 px height
- Avatar: 44 px, left side
- Name + optional secondary field (email or organisation)
- Scrollable if exceeds widget height
- Hover: row highlight background

## Seed Data

Three example users for dev/test (Nextcloud UI shows empty dashboard without seed users):

```json
{
  "users": [
    {
      "uid": "alice_smith",
      "displayName": "Alice Smith",
      "email": "alice.smith@example.com",
      "phone": "+31 6 12345678",
      "groups": ["management", "product"],
      "role": "Product Manager",
      "organisation": "Product Division",
      "pronouns": "she/her",
      "headline": "User experience advocate",
      "biography": "Passionate about intuitive product design. 10+ years in product management.",
      "address": "Amsterdam, Netherlands",
      "website": "https://alice-smith.example.com",
      "twitter": "@alice_smith",
      "birthdate": "1990-06-10"
    },
    {
      "uid": "bob_johnson",
      "displayName": "Bob Johnson",
      "email": "bob.johnson@example.com",
      "phone": "+31 6 87654321",
      "groups": ["engineering", "backend"],
      "role": "Senior Backend Engineer",
      "organisation": "Engineering Division",
      "pronouns": "he/him",
      "headline": "Building scalable systems",
      "biography": "Specializes in distributed systems and API design.",
      "address": "Utrecht, Netherlands",
      "website": "https://bob-johnson.dev",
      "bluesky": "@bob@bsky.social",
      "birthdate": "1985-03-22"
    },
    {
      "uid": "carol_lee",
      "displayName": "Carol Lee",
      "email": "carol.lee@example.com",
      "phone": "+31 6 55555555",
      "groups": ["management", "marketing"],
      "role": "Marketing Manager",
      "organisation": "Marketing Division",
      "pronouns": "she/her",
      "headline": "Digital transformation enthusiast",
      "biography": "Focus on digital strategy and customer engagement.",
      "address": "Rotterdam, Netherlands",
      "fediverse": "carol@mastodon.social",
      "birthdate": "1992-12-05"
    }
  ]
}
```

These seed users are created during widget testing (QA fixtures, not persisted in app install). They populate the Nextcloud test instance with realistic Dutch profiles.

## Filtering Logic

### Group Filter

- Filter object: `{"fieldName": "group", "operator": "in", "values": ["management", "product"]}`
- Strategy: Union (OR) — users in ANY listed group are included
- Deduplication: Users appearing in multiple groups appear once
- Unknown group: Returns zero users for that value, no error
- Multiple group filters: Combined via `filterOperator` (AND/OR)

### Birthday Window Filter

- Filter object: `{"fieldName": "birthday", "operator": "within_next_days", "values": [7]}`
- Computed: Birthdays falling within next N days from today, inclusive
- Edge case: Feb-29 birthdays in non-leap years throw exception (guard needed in follow-up)
- Display: Days-to-birthday computed client-side from ISO datestring, not sent by server

### Disabled User Exclusion

- `excludeDisabled: true` (default) — skip `IUser::isEnabled() === false`
- Scope: Applied to all queries

## Authorization & Privacy

In v1 (this change):
- All users visible to the viewer are returned (determined by Nextcloud's normal access control)
- All profile fields readable by the viewer are included (no scope-based field filtering)
- The shared APCu cache key includes filter + sort parameters but NOT the requesting user

In future (follow-up `people-widget-privacy`):
- Enforce field-level visibility via `$property->getScope()` (SCOPE_FEDERATED, SCOPE_PUBLISHED, SCOPE_PRIVATE)
- Scope-aware cache key (per-viewer or disabled)
- Admin users always see all fields of all users

## Performance Targets

- `GET /api/people` MUST return within 1 second for orgs with <1000 users
- Pagination max 100 users per request (offset-based)
- Backend APCu cache (1 hour) for non-group-filter paths (group filters always fresh for membership accuracy)
- Frontend in-memory cache (60 seconds) per placement

## Deduplication Check

No overlap with existing MyDash or Nextcloud services:
- MyDash has no people-browsing API or UI widget (new capability)
- Nextcloud's `OCP\IUserManager`, `OCP\IGroupManager`, `OCP\Accounts\IAccountManager` are read-only access layers, not replaced or reimplemented

## Accessibility & Localization

- Keyboard navigation: Tab through users, Enter to open profile
- Avatar alt text: `"Avatar of <displayName>"`
- All labels, buttons, and messages translated to English (en) and Dutch (nl)
- Birthday date formatting: Locale-aware (frontend handles formatting based on user's language setting)
