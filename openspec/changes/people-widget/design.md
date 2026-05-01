# Design — People widget

## Context

The people-widget spec (REQ-PPL-001 through REQ-PPL-012) was written before examining the
existing implementation in the reference source app. This document resolves the open design
questions by grounding each decision in the actual source code.

Source roots examined:
- `intravox-source/lib/Controller/PeopleController.php`
- `intravox-source/lib/Service/UserService.php`
- `intravox-source/src/components/PeopleWidget.vue`
- `intravox-source/src/components/PeopleWidgetEditor.vue`
- `intravox-source/src/components/people/PersonItem.vue`
- `intravox-source/src/components/people/PeopleLayoutCard.vue`
- `intravox-source/src/components/people/PeopleLayoutGrid.vue`
- `intravox-source/src/components/people/PeopleLayoutList.vue`
- `intravox-source/appinfo/routes.php`

## Goals / Non-Goals

**Goals**: Resolve field set, privacy model, birthday source, group filter mechanics, layout
modes, avatar sizes, and pagination strategy so the spec can be tightened to match
implementable reality.

**Non-Goals**: This document does not change the spec. Spec deltas are listed at the end.

---

## Decisions

### D1: Field projection

**Decision**: The concrete field set returned by the people endpoint is larger than the spec
describes. The spec names `jobTitle` and `department` as two distinct fields, but the real
implementation maps `PROPERTY_ROLE` → `role` (job title) and `PROPERTY_ORGANISATION` →
`organisation` (used as department fallback). There is no `jobTitle` key or `department` key
in the actual response. The full set of standard keys is:

| Response key   | Source constant                         |
|----------------|-----------------------------------------|
| `uid`          | `IUser::getUID()`                       |
| `displayName`  | `IUser::getDisplayName()`               |
| `email`        | `IUser::getEMailAddress()`              |
| `avatarUrl`    | URL-generator route (absolute)          |
| `groups`       | `IGroupManager::getUserGroups()`        |
| `status`       | `IUserStatusManager` (optional)         |
| `displayname`  | `PROPERTY_DISPLAYNAME`                  |
| `email`        | `PROPERTY_EMAIL`                        |
| `phone`        | `PROPERTY_PHONE`                        |
| `address`      | `PROPERTY_ADDRESS`                      |
| `website`      | `PROPERTY_WEBSITE`                      |
| `twitter`      | `PROPERTY_TWITTER`                      |
| `bluesky`      | `PROPERTY_BLUESKY`                      |
| `fediverse`    | `PROPERTY_FEDIVERSE`                    |
| `organisation` | `PROPERTY_ORGANISATION`                 |
| `role`         | `PROPERTY_ROLE`                         |
| `headline`     | `PROPERTY_HEADLINE`                     |
| `biography`    | `PROPERTY_BIOGRAPHY`                    |
| `pronouns`     | `PROPERTY_PRONOUNS`                     |
| `birthdate`    | `PROPERTY_BIRTHDATE`                    |
| *(dynamic)*    | LDAP/OIDC extra properties via `getProperties()` |
| *(dynamic)*    | App-level custom fields from user preferences |

The `PROPERTY_BIRTHDATE` constant is confirmed present in `IAccountManager` and is explicitly
handled in `STANDARD_PROPERTIES`.

The spec's `jobTitle` field does not exist. `PROPERTY_ROLE` is the NC standard for job title.
`department` in the spec maps to `organisation` in the source (the `PersonItem` component
renders `user.organisation || user.department` — it tries `organisation` first).

**Source evidence**:
- `intravox-source/lib/Service/UserService.php:35-50` — `STANDARD_PROPERTIES` constant
- `intravox-source/lib/Service/UserService.php:400-484` — `buildUserProfile()` method
- `intravox-source/src/components/people/PersonItem.vue:33` — `user.organisation || user.department`

---

### D2: Privacy enforcement

**Decision**: The source does **not** apply NC's `IAccountManager` field-level visibility
(scope: private/contacts/local/public). It calls `$account->getProperty($property)` and reads
`$prop->getValue()` unconditionally, without checking `$prop->getScope()` against the
requesting user. All standard properties are included in every response regardless of the
user's privacy settings. The only filter is a truthy check (`$value ?: null`).

This is a privacy bypass relative to what the spec requires. The spec mandates that hidden
fields must be omitted entirely (REQ-PPL-004). The implementation does not do this.

There is a 1-hour distributed APCu cache on the non-group-filter path
(`intravox-source/lib/Service/UserService.php:256-263`) keyed only on filter + sort, not on
requesting user. Results from this cache will leak fields regardless of privacy settings.

The spec requirement REQ-PPL-004 is therefore aspirational for v1 and needs a deliberate
implementation decision: either adopt NC scope-based visibility or document it as out-of-scope
with a warning.

**Source evidence**:
- `intravox-source/lib/Service/UserService.php:414-435` — reads value with no scope check
- `intravox-source/lib/Service/UserService.php:256-263` — shared cache ignores requesting user

---

### D3: Birthday source and computation

**Decision**: `birthdate` is sourced directly from `IAccountManager::PROPERTY_BIRTHDATE` —
confirmed by presence in `STANDARD_PROPERTIES` and explicit ISO normalization logic. The raw
value may be stored in locale-specific formats (DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY) and is
normalized to ISO 8601 server-side before being returned.

The source does **not** compute `daysToBirthday` on the backend. The `buildUserProfile()`
method returns the ISO date string as `birthdate`. Birthday filtering (upcoming birthday
detection) is implemented via two filter operators on the frontend editor side:
`is_today` and `within_next_days`. The `within_next_days` operator is computed server-side
only inside `matchesSingleFilter()` — but only as a filter predicate, not as a returned field.

The `daysToBirthday` response field named in the spec does not exist in the source. The source
returns the raw `birthdate` string; the Vue component formats it for display
(`PersonItem.vue:304-315`), showing month + day (e.g., "June 10") using `toLocaleDateString`.

Leap-year handling in the `within_next_days` operator constructs `YYYY-MM-DD` directly from
the birthday's month-day without special Feb-29 logic; this will throw on non-leap years.

**Source evidence**:
- `intravox-source/lib/Service/UserService.php:49` — `PROPERTY_BIRTHDATE` in standard set
- `intravox-source/lib/Service/UserService.php:425-430` — ISO normalization
- `intravox-source/lib/Service/UserService.php:750-755` — `within_next_days` logic (no Feb-29 guard)
- `intravox-source/src/components/people/PersonItem.vue:304-315` — `formatBirthdate()`

---

### D4: Group filter mechanics

**Decision**: Group filtering is not a top-level query parameter. It is expressed as a filter
object in the `filters` JSON array, where `fieldName === 'group'`. The backend detects this
special case and uses `IGroupManager::get($groupId)->getUsers()` for efficiency (avoiding a
full user scan). Multiple group values use a union (OR) strategy: deduplicated via a `$seen`
map. Other filters are then applied on top of the group-scoped set.

There is no separate `groupFilter` query param as the spec describes. The frontend sends
`filters=[{"fieldName":"group","operator":"in","values":["management","product"]}]`.

The `IGroupManager::get()` approach (not `displayNamesInGroup()`) is what is used. An unknown
group returns an empty result (the `$group === null` check silently yields zero users).

**Source evidence**:
- `intravox-source/lib/Service/UserService.php:208-246` — group filter detection + union loop
- `intravox-source/lib/Controller/PeopleController.php:210-228` — `filters` JSON param
- `intravox-source/appinfo/routes.php:155` — `GET /api/people` (no separate groupFilter route)

---

### D5: Layout modes

**Decision**: All three layout modes — `card`, `list`, `grid` — are **confirmed implemented**
in shipping code. They are three separate Vue components backed by a shared `PersonItem`
component that switches behavior based on a `layout` prop.

Key differences from the spec's described dimensions:

| Layout | Avatar size (actual) | Notes |
|--------|---------------------|-------|
| `card` | 80 px               | Spec said 64 px; actual is 80 px |
| `list` | 44 px               | Spec said 32 px; actual is 44 px |
| `grid` | 64 px               | Spec said 48 px; actual is 64 px |

The card and grid layouts support a configurable column count (2/3/4) via `widget.columns`.
The spec does not mention `columns` as a separate config key. Default is 3 for card, 4 for
grid.

The spec describes birthday badges ("🎂 in N days") overlaid on cards and grid cells. These
are **not implemented** in the source. There is no `daysToBirthday` badge rendering in any
layout component. Birthdate is rendered as a formatted date string in the contact section only
(card layout only for biography; birthdate appears in contact block across all layouts when
`showFields.birthdate` is true).

The `click → /u/{userId}` navigation (REQ-PPL-010) is **not implemented** in PersonItem or
any layout component. The `PersonItem` component renders no click handlers on the card/name.

**Source evidence**:
- `intravox-source/src/components/people/PersonItem.vue:212-222` — avatarSize computed
- `intravox-source/src/components/people/PeopleLayoutCard.vue` — card layout, columns from `widget.columns`
- `intravox-source/src/components/people/PeopleLayoutGrid.vue` — grid default 4 columns
- `intravox-source/src/components/people/PersonItem.vue:1-145` — no birthday badge, no click handler

---

### D6: Avatar and pagination

**Avatar decision**: Avatar URLs are generated via `IURLGenerator::linkToRouteAbsolute()` for
the route `core.avatar.getAvatar` with hardcoded size 128, not the configurable 64 px the
spec describes. There is no app config key `mydash.people_widget_avatar_size` in the source.
The `NcAvatar` component in the frontend then receives the avatar size prop per-layout (80, 44,
or 64 px) for display, but the actual avatar route always fetches 128 px resolution.

**Pagination decision**: The source uses **offset-based pagination**, not cursor-based. The
`getPeople` endpoint accepts `limit` (max 100) and `offset` integer params. The response shape
is `{users: [...], total: N, hasMore: boolean}` — not `{users: [...], nextCursor: string}` as
the spec specifies.

The frontend `PeopleWidget.vue` uses `users.length` as the offset for "load more" requests.
This is efficient enough for the expected use case but not cursor-stable under concurrent edits.

The spec's cursor-based pagination (REQ-PPL-003) is aspirational; the source implements
offset-based with `hasMore` boolean.

**Source evidence**:
- `intravox-source/lib/Service/UserService.php:405-408` — hardcoded size 128 in avatar URL
- `intravox-source/lib/Controller/PeopleController.php:177-185` — `limit` + `offset` params
- `intravox-source/lib/Controller/PeopleController.php:234-238` — `{users, total, hasMore}` response
- `intravox-source/src/components/PeopleWidget.vue:249-253` — `fetchPeople(this.users.length)`

---

## Spec changes implied

The following changes are needed to align the spec with implementable reality. Do NOT edit
the spec yet — these are deltas to apply in a follow-up task.

- **REQ-PPL-003** (response shape): Replace `nextCursor` with `hasMore: boolean` and `total: number`. Remove cursor-based pagination text. Pagination is offset-based (`limit` + `offset` integers). Max `limit` is 100.
- **REQ-PPL-003** (route): The endpoint is `GET /api/people?userIds=...&filters=...&limit=50&offset=0`, not `/api/widgets/people/{placementId}/users`. There is no `placementId` path param; filter config is passed per-request.
- **REQ-PPL-004** (privacy): Add a warning that NC scope-based visibility is NOT currently enforced; all `IAccountManager` fields are returned unconditionally. Either implement scope checking or downgrade the MUST to SHOULD for v1 and add a follow-up item.
- **REQ-PPL-005** (birthday): Rename `daysToBirthday: integer` to `birthdate: string (ISO 8601 or null)`. The backend returns a date string, not a days-countdown. Compute `daysToBirthday` display client-side if needed. Add note about missing Feb-29 guard in `within_next_days` filter operator.
- **REQ-PPL-007** (avatar): Change default size from 64 px to 128 px (route fetch) / layout-dependent display (80 card / 64 grid / 44 list). Remove `mydash.people_widget_avatar_size` config key — it does not exist.
- **REQ-PPL-008** (layout sizes): Correct avatar sizes to 80/64/44 (card/grid/list). Add `columns` as a per-layout config key (2/3/4 options). Remove birthday-badge overlay requirement — not implemented, move to follow-up.
- **REQ-PPL-010** (click-through): Mark as NOT implemented in the reference source; must be added fresh in MyDash.
- **Field names**: Replace `jobTitle` with `role` (maps to `PROPERTY_ROLE`) and `department` with `organisation` (maps to `PROPERTY_ORGANISATION`). The `PersonItem` falls back `user.organisation || user.department`.
- **Config shape** (REQ-PPL-002): The actual config uses `selectionMode: 'manual'|'filter'`, `selectedUsers: string[]`, `filters: FilterObject[]`, `filterOperator: 'AND'|'OR'`, `columns: 2|3|4`, `showFields: {...}` — not `cardFields: string[]`. Replace `cardFields` with `showFields` map.
- **Shared backend cache**: The non-group-filter path uses a 1-hour shared APCu cache that ignores the requesting user. This is a privacy risk when scope-based visibility is eventually enforced. Add to open follow-ups.

---

## Open follow-ups

- **Privacy**: Implement `IAccountManager` scope-based visibility (`$prop->getScope()` check against viewer) before the shared filter cache is safe to keep.
- **Feb-29 birthday**: The `within_next_days` filter operator constructs dates with `new \DateTime($currentYear . '-' . $date->format('m-d'))` — this throws on Feb 29 in non-leap years. Add a guard.
- **Click-through to `/u/{userId}`**: Not present in the reference source; must be added to `PersonItem.vue`.
- **Birthday badge overlay**: "🎂 in N days" badge rendering is not present; to be added if desired (spec's REQ-PPL-008 scenario).
- **Avatar size config**: `mydash.people_widget_avatar_size` app config key is not implemented; decide whether to add it or fix the spec.
- **Status integration**: `IUserStatusManager` is already wired and populates `status` in the profile. The spec does not mention status — worth deciding whether to expose it in v1.
- **Public share access**: The source exposes people data via `GET /api/share/{token}/people` — the spec does not address this route. Decide scope.
