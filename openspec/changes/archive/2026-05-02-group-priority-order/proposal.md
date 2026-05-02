# Group priority order — admin setting

## Why

Multi-group users currently have no deterministic way to know which group's dashboard set they will see — group iteration order is implementation-defined. Admins also have no way to designate which Nextcloud groups are "in scope" for MyDash at all (today every group is implicitly active). This change gives admins explicit control over both the active set and the priority order, and is the foundation that the `group-routing` change consumes for primary-group resolution (REQ-TMPL-012).

## What Changes

- Add a new global admin setting `group_order` to `oc_mydash_admin_settings`, persisted as a JSON string list of Nextcloud group IDs in the order the admin chose.
- Add `GET /api/admin/groups` returning `{active, inactive, allKnown}` so the admin UI can render both columns in one round-trip and surface stale (deleted) group IDs.
- Add `POST /api/admin/groups` accepting `{groups: [id…]}` that **replaces wholesale** (no partial merge — UI sends the full ordered list after every drag).
- Both endpoints are admin-only via `IGroupManager::isAdmin` (`GET` is also admin-gated because the inactive list reveals every group on the system).
- Add a two-list drag-and-drop UI to `src/views/AdminApp.vue` with active-vs-inactive columns, filter inputs, auto-save on every drag, and a "(removed)" affix for stale IDs.

## Capabilities

- **New Capabilities**: none
- **Modified Capabilities**:
  - `admin-settings` — adds REQ-ASET-012 (group_order setting), REQ-ASET-013 (`GET /api/admin/groups`), REQ-ASET-014 (admin guard on both endpoints). Pure ADDED — no existing requirement is modified or removed.

## Impact

- **Code**:
  - `lib/Service/AdminSettingsService.php` — new `getGroupOrder(): array`, `setGroupOrder(array $groupIds): void`; new constant `AdminSettings::KEY_GROUP_ORDER`.
  - `lib/Controller/AdminSettingsController.php` — new `listGroups()` (GET) and `updateGroupOrder()` (POST) actions.
  - `appinfo/routes.php` — register `GET /api/admin/groups` and `POST /api/admin/groups`.
  - `src/views/AdminApp.vue` — new two-list drag-and-drop component (using existing `vuedraggable`).
- **Data**: one new row in `oc_mydash_admin_settings` (key `group_order`, JSON value, default `[]`). No schema migration needed — the table already accepts arbitrary keys.
- **APIs**: two new endpoints; existing `/api/admin/settings` is untouched.
- **Dependencies**: no new server deps; frontend uses already-bundled `vuedraggable`.
- **Downstream**: the `group-routing` change reads `group_order` via `AdminSettingsService::getGroupOrder()` to resolve primary group per REQ-TMPL-012.

## Approach Notes

- Use the existing `AdminSetting` ORM entity / mapper; the value is stored via `json_encode()` and retrieved via `json_decode()`. Corrupt JSON in the DB MUST resolve to `[]` without throwing (defensive read).
- Validation: every ID in the payload MUST be a string and SHOULD exist in Nextcloud, but unknown IDs are tolerated — they are dropped silently from the runtime resolver (REQ-TMPL-012 stale-group scenario) but kept in the persisted setting so admins can restore them when groups come back.
- Auto-save on every drag matches admin's expectation that drag-drop equals commit. Implies more API chatter but only during admin sessions; tasks.md tracks a 300ms debounce as the recommended throttle.
