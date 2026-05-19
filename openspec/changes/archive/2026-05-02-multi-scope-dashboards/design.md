# Design — Multi-scope dashboards

## Context

MyDash currently has two dashboard scopes:

1. **`user`** — personal, user-owned, freely editable. CRUD via `/api/dashboard[s]`.
2. **`admin_template`** — admin-authored snapshot. On a user's first access, `TemplateService::createDashboardFromTemplate()` clones the template into a new `user`-type dashboard (with `basedOnTemplate` set). Subsequent admin edits do NOT propagate.

Customer-facing requests have surfaced a third pattern: a **live, shared** dashboard that an admin can author once and have render in real-time for every member of a group, with admin edits visible immediately. The template scope cannot satisfy this because (a) it's copy-on-first-access and (b) once copied, the user owns the divergent record.

This change adds the `group_shared` scope to fill the gap, plus a `'default'` synthetic group sentinel and a single `/api/dashboards/visible` endpoint that the frontend can call to get the deduplicated, source-tagged union of everything the user should see.

The existing `admin_template` capability is intentionally untouched — it keeps its narrow "snapshot" meaning, and the two scopes coexist.

## Goals / Non-Goals

**Goals:**

- Add `group_shared` as a first-class third dashboard type without breaking any existing `user` or `admin_template` behaviour.
- Render group-shared dashboards live (no per-user copy) so admin edits propagate on next page load.
- Provide a single resolution endpoint (`/api/dashboards/visible`) that unions personal + group + default-group dashboards so the frontend has one source of truth.
- Reserve `'default'` as a synthetic group meaning "visible to all" so admins don't have to maintain an "all-users" Nextcloud group.
- Tag every returned dashboard with a `source` field so the frontend knows which mutation endpoint to call.
- Keep delete semantics safe: prevent the admin from accidentally removing the last group-shared dashboard in a non-default group.

**Non-Goals:**

- A UI for managing group-shared dashboards in the admin panel — that ships in a follow-up `admin-group-management` change. This change provides only the backend + store wiring.
- Per-user overrides on top of a group-shared dashboard — out of scope. Users wanting to customise must use the existing `fork-current-as-personal` action to clone into a personal dashboard.
- Migrating existing `admin_template` records to `group_shared` — they continue to coexist; admins choose per-feature.
- Multi-group sharing of one dashboard (one dashboard → one `groupId`). A dashboard intended for two groups must be created twice. Rationale: keeps the schema simple and `findVisibleToUser` cheap; the dedup-by-uuid path in REQ-DASH-013 handles the rare edge where a user is in both groups.
- Real-time push notifications when an admin edits — propagation is on next page load, not via WebSocket.

## Decisions

### D1: Single column `groupId` instead of reusing `targetGroups` JSON

**Decision**: Add a dedicated nullable `groupId VARCHAR(64)` column on `oc_mydash_dashboards`.

**Alternatives considered:**

- Reuse the existing `targetGroups` JSON column from `admin_template`. Rejected because (a) `targetGroups` is a list (admin templates can target many groups for distribution) while `group_shared` is strictly one group per dashboard, and (b) overloading the column makes `findByGroup` queries impossible to index efficiently — we'd need a JSON contains query rather than an `=` lookup.

**Rationale**: A scalar `groupId` column lets us add a `(type, groupId)` composite index and keeps the WHERE clause to `WHERE type = 'group_shared' AND groupId = ?`. The cost is one extra nullable column on a table that already has 14 columns — negligible.

### D2: `'default'` is a string sentinel, not a separate column

**Decision**: Use the literal string `'default'` as a reserved `groupId` value meaning "visible to all".

**Alternatives considered:**

- Add a separate `isDefaultGroup BOOLEAN` column. Rejected because it doubles the matrix (`groupId × isDefaultGroup`) and creates ambiguous states (a row with both set).
- Use `groupId = NULL` to mean "default". Rejected because `NULL` already has a clear meaning in this column ("not a group-shared dashboard, so this field is irrelevant"). Conflating the two would force every query to check both `type = 'group_shared'` AND `groupId IS NULL` rather than a clean equality.

**Rationale**: `'default'` is short, self-documenting in the database, indexable like any other group ID, and impossible to collide with a real Nextcloud group ID because Nextcloud rejects creating a group with id `default` (reserved by IGroupManager). We document the reservation in the spec so frontend clients know not to allow it as a real group selection.

### D3: `findVisibleToUser` does the union in PHP, not in SQL

**Decision**: `DashboardMapper::findVisibleToUser(string $userId)` issues three separate queries (personal, group-matching, default-group) and unions/dedupes in PHP using a UUID-keyed associative array.

**Alternatives considered:**

- A single SQL UNION query with `WHERE (userId = ? AND type='user') OR (type='group_shared' AND groupId IN (?,?,...,'default'))`. Rejected because (a) the IN-list size is unbounded (a user can be in many groups), causing query plan instability, and (b) the SQL UNION makes it harder to tag each row with the correct `source` value.

**Rationale**: Three indexed queries are fast (each hits `(userId, type)` or `(type, groupId)`), the PHP-side union is O(n) over a small result set (rarely >50 dashboards total per user), and we can attach the `source` field cleanly per result set before merging. Dedup-by-UUID is trivial in associative-array form.

### D4: Source-tagging happens server-side, not client-side

**Decision**: The `/api/dashboards/visible` endpoint adds `source: 'user' | 'group' | 'default'` to each returned dashboard.

**Alternatives considered:**

- Let the frontend infer `source` from `type` + `groupId`. Rejected because it pushes business logic into multiple frontend stores (Pinia, the page-level mounter, and the dashboard-card component) and risks divergence.

**Rationale**: Server-side tagging keeps the frontend dumb — it simply checks `dashboard.source` to decide which endpoint to PUT updates to. Same logic, one place.

### D5: Last-in-group delete guard only applies to `group_shared`, not personal

**Decision**: `DELETE /api/dashboards/group/{groupId}/{uuid}` returns HTTP 400 if removing the row would leave the group with zero `group_shared` dashboards. Personal-dashboard deletion (REQ-DASH-005) is unchanged.

**Alternatives considered:**

- No guard at all. Rejected because an admin who accidentally deletes the last group dashboard would silently strip every member of that group of their default landing page (since the visible-to-user union would suddenly return only personal + default-group dashboards, none of which may be the previously-active one).
- A guard on every delete (including personal). Rejected because users explicitly expect to be able to delete all their own personal dashboards — REQ-DASH-005 #5 ("Delete the last remaining dashboard") is an existing scenario.

**Rationale**: The asymmetry mirrors the asymmetry of impact: personal dashboards affect one user, group-shared dashboards affect N users. The default group is exempt from the guard because by definition it is opt-in (admins know what they're doing when curating it).

### D6: Group-shared dashboards do NOT appear in `GET /api/dashboards`

**Decision**: The existing `GET /api/dashboards` endpoint continues to return only personal (`type = 'user'`) dashboards owned by the caller. Group-shared dashboards only appear in `GET /api/dashboards/visible` and `GET /api/dashboards/group/{groupId}`.

**Alternatives considered:**

- Make `GET /api/dashboards` return the union. Rejected because it would silently change the semantics of an endpoint that older clients rely on (currently "my personal dashboards"), risking display of admin-owned dashboards in places that assume edit rights.

**Rationale**: Backward compatibility — existing API consumers (including older mobile clients and integrations) keep getting exactly what they got before. The `/visible` endpoint is the new opt-in path for clients that understand the group scope.

### D7: Permission level for group-shared dashboards

**Decision**: Group-shared dashboards have an effective permission level of `view_only` for non-admin members and `full` for admins, regardless of what the underlying record's `permissionLevel` field says. The field still exists on the row (for forward-compat with future per-tile editing) but is overridden at resolution time by `PermissionService`.

**Rationale**: Hard-coding the rule in `PermissionService::getEffectivePermissionLevel()` keeps the auth check in one place. Non-admins literally cannot mutate (the route guard returns 403), and the UI grays out edit affordances based on the resolved level.

## Data Model Changes

```
oc_mydash_dashboards (existing table)
+ groupId VARCHAR(64) NULL                    -- new column
+ INDEX idx_mydash_dash_type_group (type, groupId)   -- new composite index
```

App-level invariant (enforced in `DashboardFactory::create()` and validated in mapper insert):

```
(type = 'group_shared' AND groupId IS NOT NULL)
   OR (type IN ('user', 'admin_template') AND groupId IS NULL)
```

We do not add a CHECK constraint at the DB level because Nextcloud's migration framework discourages portable CHECK constraints (sqlite/mysql/postgres differ). The app-level guard plus PHPUnit fixtures cover it.

## API Surface

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/dashboards/visible` | logged-in user | Return deduplicated union of personal + group + default-group dashboards, each tagged with `source` |
| GET | `/api/dashboards/group/{groupId}` | logged-in user | List group-shared dashboards in the given group (members + admins can list) |
| POST | `/api/dashboards/group/{groupId}` | admin only | Create a new group-shared dashboard in the given group |
| GET | `/api/dashboards/group/{groupId}/{uuid}` | logged-in user | Get one group-shared dashboard with placements |
| PUT | `/api/dashboards/group/{groupId}/{uuid}` | admin only | Update name / layout / icon |
| DELETE | `/api/dashboards/group/{groupId}/{uuid}` | admin only | Delete the dashboard (last-in-group guard applies to non-`default` groups) |

The `groupId` path parameter accepts either a real Nextcloud group ID or the literal `default`.

## Risks / Trade-offs

- **Risk:** Admin creates many group-shared dashboards in `'default'`, cluttering every user's `/visible` response. → **Mitigation:** Frontend renders dashboards as scrollable tabs; we recommend in admin docs that `default` be reserved for one or two flagship dashboards. No hard limit enforced (would be arbitrary).
- **Risk:** Performance degradation on `/visible` for users with many groups. → **Mitigation:** Three indexed queries; tested at 100 groups in PHPUnit fixture; expected p99 under 50 ms. Add a cache layer in a follow-up only if metrics show a hot path.
- **Risk:** A user is removed from a group while their active dashboard is the group-shared one. → **Mitigation:** `DashboardResolver::tryGetActiveDashboard()` falls through to `tryActivateExistingDashboard()` which picks any other visible dashboard; the user sees no error, just a different active dashboard on next load.
- **Risk:** Admin accidentally creates a real Nextcloud group called `default` despite the reservation. → **Mitigation:** Document the reservation in admin-facing docs; Nextcloud's IGroupManager already disallows the literal `default` as a group ID, but if a future Nextcloud version ever permits it the lookup still works deterministically (the `groupId='default'` query returns rows tagged for the synthetic group, and `IGroupManager::getUserGroupIds()` would also include the real group — both contribute, dedup handles overlap).
- **Trade-off:** One dashboard cannot target multiple groups. Admins who need that must duplicate. We chose schema simplicity over flexibility here; revisit if the duplication burden becomes painful.
- **Trade-off:** Edits don't push in real time. Admins must communicate "refresh your page" out-of-band. WebSocket push is a future enhancement, not part of this change.

## Migration Plan

1. **Schema migration** ships with the release: adds nullable `groupId` column + composite index. Zero-downtime, no data backfill.
2. **Backend rolls out** with the new endpoints registered. Old clients keep working (they don't call `/visible` or `/group/...`).
3. **Frontend rolls out** with `useDashboardsStore` extended to call `/visible` instead of `/api/dashboards` for the listing page. The old endpoint remains for compatibility but is no longer the primary list source.
4. **Rollback strategy**: Reverting the frontend takes the user back to seeing only personal dashboards. Reverting the backend leaves the new column in place (harmless; nullable). The migration can be reversed but isn't required for rollback.
5. **No flag** required — the new endpoints are additive and the new dashboard type only appears in records explicitly created via the new endpoints.

## Seed Data

Group-shared dashboards in OpenRegister-backed installations require seed records so admin developers can preview the feature locally:

- **Default-group "Welcome" dashboard**: `groupId='default'`, name "Welcome to MyDash", permissionLevel=`view_only`, two placements (announcements widget + activity widget). Targets every user, including those with no group memberships.
- **Marketing-group "Campaigns" dashboard**: `groupId='marketing'`, name "Active Campaigns", permissionLevel=`view_only`, three placements (kpi-tile + chart-widget + recent-activity). Demonstrates a real-group binding.
- **Engineering-group "Sprint" dashboard**: `groupId='engineering'`, name "Sprint Overview", permissionLevel=`view_only`, four placements (burndown + open-prs + ci-status + recommendations). Demonstrates a denser layout.

All three records have `userId = NULL`, `type = 'group_shared'`, `isActive = 0`, `basedOnTemplate = NULL`.

## Open Questions

- Should the `groupId='default'` dashboards be ordered before or after group-matching ones in `/visible`? Current decision: priority order is `user → group → default`, so default appears last. Frontend can re-order based on UX research after launch.
- Should an admin be able to convert an existing `admin_template` into a `group_shared` dashboard in-place, or only create fresh? Current decision: only create fresh. Conversion deferred until customer demand surfaces.
