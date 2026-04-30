---
capability: admin-settings
delta: true
status: draft
---

# Admin Settings — Delta from change `group-priority-order`

## ADDED Requirements

### Requirement: REQ-ASET-012 Group order setting

The system MUST persist an ordered list of Nextcloud group IDs as the global setting `group_order` (JSON `string[]`, default `[]`). The order MUST be preserved exactly as provided. The setting determines which groups are "active" for MyDash workspace routing (REQ-TMPL-012). Corrupt or unparseable JSON in the database MUST resolve to `[]` at read time without throwing — the resolver and admin UI MUST never see a fatal error from a malformed value.

#### Scenario: Persist ordered list

- GIVEN admin sends `POST /api/admin/groups` with body `{"groups": ["engineering", "all-staff", "marketing"]}`
- THEN the setting `group_order` MUST be persisted as the JSON string `["engineering","all-staff","marketing"]`
- AND a subsequent `GET /api/admin/groups` MUST return the same order in `active`

#### Scenario: Empty list clears active groups

- GIVEN admin sends `POST /api/admin/groups` with body `{"groups": []}`
- THEN the setting MUST be persisted as `[]`
- AND `resolvePrimaryGroup` MUST return `'default'` for every user (per REQ-TMPL-012)

#### Scenario: Replace-wholesale, not merge

- GIVEN current `group_order = ["a", "b", "c"]`
- WHEN admin sends `POST /api/admin/groups` with body `{"groups": ["c", "b"]}`
- THEN the setting MUST become exactly `["c", "b"]`
- AND `"a"` MUST be removed (no implicit merge)

#### Scenario: Corrupt DB JSON falls back to empty array

- GIVEN the row `group_order` exists in `oc_mydash_admin_settings` with `setting_value = '{not-json'`
- WHEN any caller invokes `AdminSettingsService::getGroupOrder()`
- THEN the method MUST return `[]`
- AND MUST NOT throw an exception
- AND `GET /api/admin/groups` MUST return `active: []` in this state

#### Scenario: Default when setting absent

- GIVEN no `group_order` row has ever been written to `oc_mydash_admin_settings`
- WHEN `AdminSettingsService::getGroupOrder()` is called
- THEN it MUST return `[]` (factory default)

### Requirement: REQ-ASET-013 List groups for admin UI

The system MUST expose `GET /api/admin/groups` returning `{active: [id…], inactive: [id…], allKnown: [{id, displayName}…]}`:

- `active`: the persisted `group_order` list (in admin-chosen order, preserved exactly).
- `inactive`: every Nextcloud group ID NOT in `active`, sorted by display name (case-insensitive).
- `allKnown`: full descriptor list (`{id, displayName}`) for every Nextcloud group currently known, so the UI can render display names without a second round-trip.

Stale IDs (present in `active` but no longer in Nextcloud) MUST remain in `active` so the admin can see and remove them, but MUST NOT appear in `allKnown` (no display name available). The UI is expected to render stale IDs with a "(removed)" affix.

#### Scenario: Lists are disjoint and exhaustive

- GIVEN Nextcloud has groups `["a", "b", "c", "d"]` and `group_order = ["b", "d"]`
- WHEN admin sends `GET /api/admin/groups`
- THEN the response MUST be `{active: ["b", "d"], inactive: ["a", "c"], allKnown: [{id:"a",displayName:"..."}, {id:"b",displayName:"..."}, {id:"c",displayName:"..."}, {id:"d",displayName:"..."}]}`
- AND `active ∪ inactive` MUST equal the set of IDs in `allKnown`
- AND `active ∩ inactive` MUST be empty

#### Scenario: Order in `active` matches admin-chosen order

- GIVEN `group_order = ["zebra", "alpha", "marigold"]`
- WHEN admin sends `GET /api/admin/groups`
- THEN `active` MUST be exactly `["zebra", "alpha", "marigold"]` (no alphabetical re-sort)
- AND `inactive` MUST be sorted alphabetically by `displayName`

#### Scenario: Empty group_order — all groups inactive

- GIVEN `group_order = []` and Nextcloud has groups `["a", "b"]`
- WHEN admin sends `GET /api/admin/groups`
- THEN `active` MUST be `[]`
- AND `inactive` MUST contain both `"a"` and `"b"`

#### Scenario: Stale group IDs surface in active list

- GIVEN `group_order = ["deleted-group", "engineering"]` and Nextcloud no longer has `"deleted-group"`
- WHEN admin sends `GET /api/admin/groups`
- THEN the response's `active` MUST still include `"deleted-group"` (so admin can see and remove it)
- AND `allKnown` MUST NOT include it (no display name to show)
- AND `inactive` MUST NOT include it
- NOTE: The UI SHOULD render stale IDs with a "(removed)" affix.

### Requirement: REQ-ASET-014 Admin guard and payload validation

`POST /api/admin/groups` MUST be admin-only (`IGroupManager::isAdmin`). Non-admins MUST receive HTTP 403 with no side effects. `GET /api/admin/groups` MUST also be admin-only because the inactive list reveals every group on the system.

The `POST` payload MUST be validated:
- Top-level `groups` key MUST exist and MUST be a JSON array.
- Every element MUST be a non-empty string.
- Duplicate IDs in the payload MUST be deduplicated (first occurrence wins, preserving order).
- Validation failures MUST return HTTP 400 with no side effects on the persisted setting.

Unknown (not currently in Nextcloud) IDs MUST NOT cause validation failure — they are tolerated and persisted (per REQ-ASET-013 stale-ID handling).

#### Scenario: Non-admin POST rejected

- GIVEN user "alice" who is not an administrator
- WHEN she sends `POST /api/admin/groups` with any body
- THEN the system MUST return HTTP 403
- AND the persisted `group_order` MUST be unchanged

#### Scenario: Non-admin GET rejected

- GIVEN user "alice" who is not an administrator
- WHEN she sends `GET /api/admin/groups`
- THEN the system MUST return HTTP 403

#### Scenario: Missing `groups` key rejected

- GIVEN admin sends `POST /api/admin/groups` with body `{}`
- THEN the system MUST return HTTP 400
- AND the persisted `group_order` MUST be unchanged

#### Scenario: Non-string element rejected

- GIVEN admin sends `POST /api/admin/groups` with body `{"groups": ["engineering", 42, "marketing"]}`
- THEN the system MUST return HTTP 400
- AND the persisted `group_order` MUST be unchanged

#### Scenario: Duplicate IDs deduplicated

- GIVEN admin sends `POST /api/admin/groups` with body `{"groups": ["a", "b", "a", "c"]}`
- THEN the persisted `group_order` MUST be exactly `["a", "b", "c"]` (first occurrence kept, duplicates removed)
- AND the response MUST be HTTP 200

#### Scenario: Unknown IDs accepted

- GIVEN admin sends `POST /api/admin/groups` with body `{"groups": ["does-not-exist", "engineering"]}`
- AND `"does-not-exist"` is not a known Nextcloud group
- THEN the request MUST succeed (HTTP 200)
- AND `group_order` MUST be persisted as `["does-not-exist", "engineering"]`
