# Role-based dashboard content

## What it does

MyDash can restrict which dashboard widgets a user can add to their dashboard
based on their Nextcloud group(s). When a user belongs to multiple groups,
the effective allowed-widget set is resolved deterministically via the
admin-configured `group_order` priority list, with a deny-wins rule for
cross-group conflicts.

For new users (no admin template applies), MyDash can also seed a starter
dashboard layout from per-group `RoleLayoutDefault` rows so the first-paint
experience is tailored to their role.

When **no** RoleFeaturePermission rows exist for any of a user's groups,
MyDash behaves exactly as before — the full widget catalogue is shown
(REQ-RFP-009 backwards-compat).

## Key concepts

### RoleFeaturePermission

One row per Nextcloud group. Fields:

| Field | Type | Notes |
|---|---|---|
| `groupId` | string | Nextcloud group ID; unique. The reserved id `default` is the catch-all fallback. |
| `name` | string | Display name shown in the admin UI. |
| `description` | string | Optional notes. |
| `allowedWidgets` | string[] | Widget IDs this group may add to their dashboard. Empty = none allowed. |
| `deniedWidgets` | string[] | Widget IDs explicitly blocked, even if a different group allows them (deny-wins). |
| `priorityWeights` | object | `{widgetId: integer}` — higher value = higher seeding priority. |

### RoleLayoutDefault

One row per `(groupId, widgetId)` combination. Captures the seed grid
position and size for one widget when MyDash creates a fresh dashboard for a
user in that group and no admin template matches.

| Field | Type | Notes |
|---|---|---|
| `groupId` | string | The group this default applies to. |
| `widgetId` | string | The widget to seed at this slot. |
| `gridX/gridY/gridWidth/gridHeight` | int | Slot geometry (0-based; min size 1). |
| `sortOrder` | int | Render order within the layout (lower = earlier). |
| `isCompulsory` | bool | When true, the user can't remove the widget from the seeded layout. |

## Multi-group resolution

Users often belong to multiple groups. MyDash walks the configured
`group_order` (set in admin settings) and:

1. Picks the first group in `group_order` that the user belongs to AND has a
   RoleFeaturePermission row → that group's `allowedWidgets` becomes the
   base set.
2. Subsequent groups in `group_order` that the user is also in widen the
   base set (union of their `allowedWidgets`).
3. ANY group's `deniedWidgets` is removed from the final set (deny-wins).
4. If no `group_order` group matched, MyDash falls back to the row whose
   `groupId` is exactly `default`.
5. If no `default` row exists either, returns null = no restriction (the
   full catalogue is shown).

Worked example. `group_order = ['managers', 'employees']`. Alice is in
`['employees', 'managers']`.

| Group | Allowed | Denied |
|---|---|---|
| `managers` | `['analytics', 'activity', 'recommendations']` | `[]` |
| `employees` | `['activity', 'recommendations', 'notes']` | `['analytics']` |

Walk: managers matches first → base = `['analytics', 'activity',
'recommendations']`. employees also matches → union = `['analytics',
'activity', 'recommendations', 'notes']`. employees has `denied=['analytics']`
→ final = `['activity', 'recommendations', 'notes']`.

## Admin UI

Settings → MyDash settings → **Role-based widget permissions**. Lets admins:

- See all configured RoleFeaturePermission rows with allowed/denied widget
  chips.
- Add a new permission for a Nextcloud group.
- Edit allowed/denied widget lists (comma-separated free text).
- Delete a permission.

The RoleLayoutDefault rows are managed only via the API for now; an admin
UI for them lands in a follow-up.

## Admin API

Admin-only — the controller calls a `requireAdmin()` guard on every method.

- `GET    /api/role-feature-permissions` — list all permissions
- `POST   /api/role-feature-permissions` — upsert by `groupId`
- `DELETE /api/role-feature-permissions/{id}` — delete by id
- `GET    /api/role-layout-defaults` — list all defaults
- `POST   /api/role-layout-defaults` — upsert by `(groupId, widgetId)`
- `DELETE /api/role-layout-defaults/{id}` — delete by id

`POST` accepts JSON: `{name, description?, groupId, allowedWidgets,
deniedWidgets?, priorityWeights?}` for permissions and `{name, groupId,
widgetId, gridX, gridY, gridWidth, gridHeight, sortOrder, isCompulsory?,
description?}` for defaults.

## Initial-state contract

The workspace page now ships `allowedWidgets` in its initial state
(REQ-RFP-010). Possible values:

- `null` — no role-feature-permissions configured for the caller; the
  frontend must treat this as "no restriction" and show the full
  catalogue (legacy behaviour).
- `string[]` — the effective allowed widget set for the caller; the
  frontend should not show or render any widget whose id is missing.

## Migration

Migration `Version001007Date20260501120000` creates two tables:

- `mydash_role_feature_perms` — one row per group, unique on `group_id`
- `mydash_role_layout_defaults` — one row per `(group_id, widget_id)`,
  unique on the combination

Both tables are empty on first migration; nothing is seeded. Existing users
are unaffected until an admin adds their first row (REQ-RFP-009 scenario:
"empty config → full catalogue").
