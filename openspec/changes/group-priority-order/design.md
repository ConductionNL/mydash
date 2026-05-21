# Design — Group priority order

## Architecture

### Backend

```
AdminSettingsController    (admin-only, thin: routing + validation + response)
        │
        ▼
AdminSettingsService       (all business logic, stateless)
        │
        ├── IGroupManager       (reads group list from Nextcloud)
        ├── IAppConfig          (persists group_order setting)
        └── Logger              (logs admin actions)
```

**Service responsibilities:**
- `getGroupOrder(): array` — reads the persisted ordered list of group IDs, returns `[]` if missing/corrupt JSON.
- `setGroupOrder(array $groupIds): void` — validates input (all strings), persists via `IAppConfig`, throws `\InvalidArgumentException` on invalid input.
- `listGroups(): array` — returns `{ active: [...], inactive: [...], allKnown: [...] }` by combining `getGroupOrder()` with results from `IGroupManager::search()` or `IGroupManager::display()`.
- `isGroupActive(string $groupId): bool` — convenience helper used by downstream features.

**Controller pattern (ADR-003):**
- `AdminSettingsController` handles both new admin endpoints.
- Methods: `listGroups()`, `updateGroupOrder()`.
- Both methods annotated `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- No direct mapper calls; all data access through `AdminSettingsService`.

### Frontend

```
AdminApp.vue
  └── GroupOrderSection.vue    (new component — drag-drop group ordering UI)
        ├── Active groups list (draggable, left side)
        ├── Inactive groups list (draggable, right side)
        ├── Search/filter inputs
        ├── Stale group indicator
        └── Auto-save on drag (300ms debounce)
```

The component calls `AdminSettingsService` (via the existing settings store) on mount to fetch groups, then listens for drag events and auto-saves the new order.

---

## Data Model

### Group Order Storage

**Storage:** `IAppConfig` with key `mydash.group_order` or a dedicated row in `oc_mydash_admin_settings` table.

**Format:** JSON-encoded array of Nextcloud group IDs in priority order.

**Example:**
```json
[
  "board-members",
  "staff",
  "public"
]
```

**Default:** `[]` (empty array — no groups explicitly configured, all groups treated as equal).

**Constraints:**
- Each ID is a non-empty string.
- No duplicate IDs allowed.
- IDs may be stale (group no longer exists in Nextcloud) — they are preserved in the setting so admins can restore them if the group is recreated.

---

## Reuse Analysis

This change leverages existing platform abstractions and avoids rebuilding anything the platform provides.

| Concern | Platform service / component used | Custom code needed? |
|---|---|---|
| App configuration storage | `IAppConfig` (Nextcloud built-in) | Only the key/value management |
| Group lookup | `IGroupManager::search()` or `display()` | No — read-only call |
| Admin UI framework | `CnSettingsSection` + `CnVersionInfoCard` (from `@conduction/nextcloud-vue`) | Only the two-list drag-drop component |
| Drag-and-drop | `vuedraggable` (already bundled) | Only the Vue template integration |
| Authorization | `#[AuthorizedAdminSetting]` (Nextcloud framework) | None — declarative attribute |
| Validation | Standard PHP type checking | Only the validation logic in the service |

**Deduplication check — no overlap found:**
- No existing MyDash feature manages Nextcloud group priorities or group-in-scope designation.
- No OpenRegister functionality provides this (OR manages object-level RBAC, not Nextcloud group iteration).
- The `@conduction/nextcloud-vue` library provides drag-drop components but not group-specific logic.

---

## API Specification

### GET /api/admin/groups

**Auth:** `#[AuthorizedAdminSetting(Application::APP_ID)]`

**Response (200):**
```json
{
  "active": [
    "board-members",
    "staff"
  ],
  "inactive": [
    "public",
    "contractors",
    "deleted-group-2025-01"
  ],
  "allKnown": [
    "board-members",
    "staff",
    "public",
    "contractors",
    "deleted-group-2025-01"
  ]
}
```

**Semantics:**
- `active` — groups in the current `group_order`, in priority order (first = primary).
- `inactive` — groups known to Nextcloud (from `IGroupManager`) but NOT in `group_order`, plus stale IDs in `group_order` that no longer exist (visual indicator "(deleted)" added by frontend).
- `allKnown` — union of active + inactive (for admin reference).

### POST /api/admin/groups

**Auth:** `#[AuthorizedAdminSetting(Application::APP_ID)]`

**Request body (application/json):**
```json
{
  "groups": [
    "board-members",
    "staff",
    "public"
  ]
}
```

**Validation:**
- `groups` must be an array of strings.
- Duplicates are rejected (return 400).
- Unknown group IDs are tolerated (not an error).
- Stale IDs (groups that once existed but are now deleted) may be in the list; they are preserved.

**Response (200):**
```json
{
  "active": [
    "board-members",
    "staff",
    "public"
  ],
  "inactive": [],
  "allKnown": [
    "board-members",
    "staff",
    "public"
  ]
}
```

**Error responses:**
- `400` — Invalid JSON, invalid type, or duplicates in `groups` array. Response: `{ "message": "Invalid group list" }`.
- `403` — Caller is not admin. Response: `{ "message": "Not authorized" }`.

---

## Frontend Interaction Flow

1. **On mount:** `GroupOrderSection.vue` calls `GET /api/admin/groups` to populate the two lists.
2. **Search:** filter active/inactive lists by group name (client-side, no API call).
3. **Drag-and-drop:**
   - Drag from active → inactive: remove from `group_order`.
   - Drag from inactive → active: add to `group_order` at the drop position.
   - Drag within active: reorder the priority.
4. **Auto-save (300ms debounce):** after every drag, POST the new `groups` array to `/api/admin/groups`.
5. **Stale indicators:** if a group ID in `active` does not appear in `allKnown`, display "(deleted)" next to its name.

---

## Seed Data

No seed data required — this change only defines admin settings, not data objects. The default `group_order` is an empty array.

---

## Multi-group Priority Resolution (for downstream features)

When a user belongs to multiple Nextcloud groups:

1. Fetch `group_order` via `AdminSettingsService::getGroupOrder()`.
2. Walk the list left-to-right; the **first** group the user belongs to is the "primary group".
3. When multiple groups apply (e.g., role-based permissions, dashboard templates), resolve by primary-group first, then union/intersect additional groups per the specific feature's rules.

**Example:**
- User belongs to: `["staff", "board-members"]`
- `group_order` is: `["board-members", "staff", "public"]`
- User's primary group: `"board-members"` (first match in `group_order`)
- Downstream features use `"board-members"` for role-based features, then may add `"staff"` permissions per their merge rules.
