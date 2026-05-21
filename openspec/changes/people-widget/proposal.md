---
kind: config
depends_on: []
chain: []
---

# People Widget

## Why

MyDash currently lacks a first-class discoverable directory widget that surfaces user profiles across the organization. Administrators and team members rely on external people-search, manual directory lookups, or embedded contacts sheets — all friction. The widget integrates with Nextcloud's native user infrastructure (`IAccountManager`, `IGroupManager`, `IUserManager`) and the Dashboard Widget API to provide an on-dashboard people directory with customizable layout (card/grid/list), profile field visibility control (email, phone, role, organisation, biography, social links, birthdate), group filtering, and birthday tracking.

The widget is purely read-only (queries existing user data, no profile editing) and stateless (users fetched per-request). It respects Nextcloud's standard access control and (in v1) exposes all user profiles visible to the viewer; scope-based field-level privacy enforcement is a documented follow-up.

## What Changes

Add a new widget type `people` registered with Nextcloud Dashboard via `IManager::registerWidget()` with id `mydash_people`, rendered via a set of Vue components supporting three layout modes. The widget includes:

- **Backend:** `GET /api/people` endpoint returning paginated user list with profile fields from `IAccountManager` (displayName, email, phone, role, organisation, pronouns, headline, biography, address, website, social links, birthdate, groups). Filters: group membership (union strategy), birthday window (within-next-days), disabled-user exclusion. Sorting: displayName, group, recent-activity (activity a follow-up).
- **Frontend:** Three layout renderers (card, list, grid), per-placement configuration JSON shape (layout, selectionMode, filters, sortBy, columns, showFields, birthday options), client-side search on current page, 60-second in-memory cache with force-refresh button.
- **Data:** User profiles are read-only queries; no storage in mydash. Widget config persisted in placement's `widgetContent` JSON.

## Capabilities

### New Capabilities

- **people-widget**: adds REQ-PPL-001 (widget registration), REQ-PPL-002 (per-placement config), REQ-PPL-003 (paginated API), REQ-PPL-004 (profile visibility), REQ-PPL-005 (birthdate normalization), REQ-PPL-006 (group filtering), REQ-PPL-007 (avatar URLs), REQ-PPL-008 (three layouts), REQ-PPL-009 (empty state), REQ-PPL-010 (click-through to profile), REQ-PPL-011 (search), REQ-PPL-012 (client-side caching).

### Modified Capabilities

(none — this is a self-contained widget; existing widgets and dashboard capabilities are untouched.)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/PeopleWidget.vue` — new renderer (props: `content`, `placement`)
- `src/components/Widgets/Renderers/PeopleCardLayout.vue`, `PeopleGridLayout.vue`, `PeopleListLayout.vue` — layout-specific sub-components
- `src/components/Widgets/Forms/PeopleForm.vue` — configuration sub-form for `AddWidgetModal`
- `lib/Service/PeopleService.php` — backend user listing logic, filtering, pagination
- `lib/Controller/PeopleController.php` — HTTP endpoint for `GET /api/people`
- `appinfo/routes.php` — register `GET /api/people` route
- `src/constants/widgetRegistry.js` — register `type: 'people'` with defaults
- `src/manifest.json` — widget entry with id `mydash_people`
- Translation entries: `People`, `No matching users`, `Failed to load users`, `Search by name or email`, `Card Layout`, `Grid Layout`, `List Layout`, `Show Birthdays`, `Birthday Window`, `days`, `Exclude Disabled`, `Filter by Group`, etc. (both `nl` and `en`).

**Affected APIs:**

- New route: `GET /api/people?filters=...&limit=50&offset=0` — owned by `people-widget` capability

**Dependencies:**

- No new composer or npm dependencies. Avatar URLs use Nextcloud's standard `core.avatar.getAvatar` route. User data read via existing `OCP\IUserManager`, `OCP\IGroupManager`, `OCP\Accounts\IAccountManager`.

**Migration:**

- No database migration. Widget placements are persisted in existing table; no schema changes.

**Out of scope:**

- Scope-based field-level visibility enforcement (planned follow-up `people-widget-privacy`).
- Per-viewer cache for scope-aware visibility (planned follow-up after privacy gates are wired).
- Sort by 'recent-activity' (requires `OCP\Activity\IManager` integration, follow-up `people-widget-activity`).
- Public-share access to people data (future `people-widget-public-share`).
- User status / presence indicator (future `people-widget-presence` via `OCP\UserStatus`).
- Birthday badge overlays and "days until" display client-side (future `people-widget-birthday-badges`).
- Feb-29 birthday edge case in `within_next_days` filter (documented in REQ-PPL-005 as a guard to add).
