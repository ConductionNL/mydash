# Group priority order — admin setting

## Why

Communications officers need to target dashboard content to specific user groups, ensuring each audience sees only relevant information. Dashboard admins need the ability to restrict which widgets can be accessed by different user groups. Currently, there is no mechanism for admins to designate which Nextcloud groups are "in scope" for dashboard access or to control the priority when users belong to multiple groups. This change provides the foundational admin setting that enables group-based targeting and access control.

## What Changes

- Add a new global admin setting `group_order` to store an ordered list of Nextcloud group IDs that are active in MyDash.
- Add `GET /api/admin/groups` endpoint returning three sets: active groups (in `group_order`), inactive groups (known but not in `group_order`), and all known groups.
- Add `POST /api/admin/groups` endpoint accepting an ordered list of group IDs that replaces the current `group_order` setting.
- Both endpoints are admin-only via `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- Add a two-column drag-and-drop UI to `src/views/AdminApp.vue` where admins can move groups between active and inactive lists, with auto-save on every drag.
- Display a "(deleted)" or "(removed)" indicator next to stale group IDs that no longer exist in Nextcloud.

## Capabilities

### New Capabilities

- **group-priority-order**: Allows admins to configure an ordered list of Nextcloud groups that are active in MyDash, establishing the priority when users belong to multiple groups. Covers:
  - Admin-accessible CRUD for the group order setting via two REST endpoints.
  - Interactive drag-and-drop UI for managing active vs. inactive groups.
  - Display of deleted/stale group references so admins can restore them if needed.
  - Foundation for downstream features (group-routing, role-based-content) that consume `group_order` for multi-group resolution.

### Modified Capabilities

- **admin-settings** — extends with two new admin endpoints (`GET /api/admin/groups` and `POST /api/admin/groups`). Existing `/api/admin/settings` and other admin endpoints remain unchanged.

## Impact

**Code affected:**
- `lib/Service/AdminSettingsService.php` — new `getGroupOrder(): array`, `setGroupOrder(array $groupIds): void`, and `listGroups(): array` method returning `{active, inactive, allKnown}`.
- `lib/Controller/AdminSettingsController.php` — new `listGroups()` (GET) and `updateGroupOrder()` (POST) actions, both `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- `appinfo/routes.php` — register `GET /api/admin/groups` and `POST /api/admin/groups`.
- `src/views/AdminApp.vue` — new two-column drag-and-drop component using existing `vuedraggable` library.

**APIs:**
- `GET /api/admin/groups` — returns `{ active: string[], inactive: string[], allKnown: string[] }` (admin-only)
- `POST /api/admin/groups` — accepts `{ groups: string[] }` and returns the new state (admin-only, wholesale replace)

**Data:**
- New field in the mydash app configuration (stored via `IAppConfig` or a dedicated settings table). The value is a JSON-encoded array of group IDs. Default is an empty array `[]`.

**Dependencies:**
- Existing `IGroupManager` for group membership lookups.
- No new server dependencies.
- Frontend uses already-bundled `vuedraggable` (v2 or v3, depending on Vue version).

**Downstream:**
- The `group-routing` and `role-based-content` changes consume `AdminSettingsService::getGroupOrder()` to resolve primary group and priority ordering for multi-group users.

## Approach Notes

- Validation: every ID in the payload MUST be a string. IDs need not exist in Nextcloud (stale references are tolerated and displayed with a visual indicator).
- Auto-save: trigger save on every drag operation with a 300ms debounce to avoid excessive API calls during rapid reordering.
- Defensive reads: corrupt or missing JSON in storage MUST resolve gracefully to `[]` without throwing.
- The ordered list represents priority: the first group in the list is the "primary group" for users who belong to multiple groups.
