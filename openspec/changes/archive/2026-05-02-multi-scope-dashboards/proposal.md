# Multi-scope dashboards

## Why

Today MyDash only supports two dashboard scopes: `user` (personal, user-owned) and `admin_template` (admin-authored snapshot copied per-user on first access). Neither supports the common organisational need to share a single live dashboard with a group of users where edits propagate immediately. Admins currently have to choose between (a) authoring a template that diverges per-user the moment one user edits their copy or (b) asking every user to recreate the same dashboard manually. This change introduces a third scope, `group_shared`, to fill that gap, plus a `'default'` synthetic group sentinel and a single `/api/dashboards/visible` endpoint that unions the three sources so the frontend has one place to ask "what dashboards should this user see?".

## What Changes

- Add `Dashboard::TYPE_GROUP_SHARED = 'group_shared'` constant alongside the existing `TYPE_USER` and `TYPE_ADMIN_TEMPLATE`.
- Add a nullable `groupId VARCHAR(64)` column on `oc_mydash_dashboards`, populated only for `group_shared` records.
- Reserve the literal `groupId = 'default'` as a synthetic "visible to every user" sentinel — it is not a real Nextcloud group.
- Add CRUD endpoints scoped to a group: `GET|POST /api/dashboards/group/{groupId}`, `GET|PUT|DELETE /api/dashboards/group/{groupId}/{uuid}` — admin-only for mutations.
- Add `GET /api/dashboards/visible` that returns the deduplicated union of personal + group-matching + default-group dashboards, each annotated with a `source` field (`'user' | 'group' | 'default'`) so the frontend knows which endpoint to PUT updates to.
- Group-shared dashboards are read-only for non-admin members — editing them requires forking to a personal dashboard via the existing `fork-current-as-personal` action.
- Last-in-group delete guard: an admin cannot delete the last remaining group-shared dashboard in a group via the new endpoint (returns HTTP 400). Personal-dashboard delete behaviour from REQ-DASH-005 is unchanged.

## Capabilities

### New Capabilities

(none — the feature folds into the existing `dashboards` capability)

### Modified Capabilities

- `dashboards`: adds REQ-DASH-011 (group_shared type), REQ-DASH-012 (default-group sentinel), REQ-DASH-013 (visible-to-user resolution endpoint), REQ-DASH-014 (group-scoped CRUD endpoints). Existing REQ-DASH-001..010 are untouched.

The `admin-templates` capability is intentionally not modified — its narrow meaning ("snapshot copied per-user on first access") is preserved. The new `group_shared` type is a separate, parallel scope.

## Impact

**Affected code:**

- `lib/Db/Dashboard.php` — extend `type` enum, add nullable `groupId` field with getter/setter
- `lib/Db/DashboardMapper.php` — add `findByGroup(string $groupId)` and `findVisibleToUser(string $userId)`
- `lib/Service/DashboardService.php` — group-scoped CRUD with admin guard + `IGroupManager` integration; visible-to-user resolution rules
- `lib/Controller/DashboardController.php` — five new endpoints + the `/visible` resolution endpoint
- `appinfo/routes.php` — register the six new routes (one is `/visible`, five are `/group/{groupId}[...]`)
- `lib/Migration/VersionXXXXDate2026...php` — schema migration adding `groupId` column + composite index on `(type, groupId)`
- `src/stores/dashboards.js` — add `groupSharedDashboards` and `defaultGroupDashboards` getters; track `source` per dashboard so PUT routes correctly
- `src/views/AdminApp.vue` — admin-only UI to manage group-shared dashboards (deferred to a follow-up `admin-group-management` change; this change only ships the backend + store wiring)

**Affected APIs:**

- 6 new routes (no existing routes changed)
- Existing `GET /api/dashboards` continues to return only personal dashboards — group-shared dashboards do NOT bleed into it (ensures backward compatibility for clients that don't yet know about group scopes)

**Dependencies:**

- `OCP\IGroupManager` — already injected elsewhere, used to resolve user → groups and to check admin status
- No new composer or npm dependencies

**Migration:**

- Zero-impact: the migration only adds a nullable column and an index. Existing rows get `groupId = NULL` and continue to be classified as `user` or `admin_template` as before.
- No data backfill required.
