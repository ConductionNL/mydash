# Design — Group routing: pick primary group for a user

## Context

MyDash displays dashboards filtered by user group membership. When a user belongs to multiple Nextcloud groups, the system must deterministically select one "primary" group to guide role-based widget visibility and landing-page routing. Today, no formal algorithm exists — different code paths make different choices (ad-hoc, unpredictable), leading to inconsistent UX where the same user might see different widgets depending on which controller invoked the group lookup.

The admin-configured `group_order` setting (introduced by a parallel change `group-priority-order`) lists the groups in priority sequence. This design formalises how the system converts `group_order` + user's Nextcloud groups into a single deterministic primary group ID.

## Goals / Non-Goals

**Goals:**

- Make primary group selection deterministic: same `group_order` + same user memberships → same primary group.
- Centralise the algorithm in a single service so it is testable, auditable, and impossible to bypass.
- Tolerate stale group IDs in the admin's `group_order` list without throwing errors (cleanup is the admin's responsibility).
- Provide a pure function (`resolvePrimaryGroup`) with no side effects, no database writes, no caching — only reads.
- Expose the resolved primary group to the frontend so role-based widgets can filter correctly.

**Non-Goals:**

- Modifying user group memberships (Nextcloud owns that). This change only reads `IGroupManager` data.
- Creating a new data model in OpenRegister. The admin's `group_order` setting lives in `oc_mydash_admin_settings`, not OpenRegister.
- Implementing the admin UI for editing `group_order`. That's a separate change (`group-priority-order`).
- Implementing role-to-group mappings or group-to-widget-visibility rules. Those are delegated to the existing role-based filtering code (REQ-DASH-003, REQ-DASH-004).

## Decisions

### D1: Pure resolver function, no caching in the service

**Decision**: Expose `AdminTemplateService::resolvePrimaryGroup(string $userId): string` as a pure function. It takes no constructor-injected state and produces the same output for the same input every invocation.

**Alternatives considered:**

- Cache the result per-request using `RequestContext` or a property. Rejected — the result depends on `group_order` which admins may change mid-request; caching would hide those changes. Better to cache at the composable level (frontend) where the scope is clear (one user session).
- Lazy-init the resolver on first call. Rejected — adds statefulness to the service; the pure-function pattern is clearer.

**Rationale**: Purity makes the function trivial to test (no mocks), trivial to reason about (no hidden state), and safe to call from any code path. The service is stateless; only the frontend composable caches the result.

### D2: Return a literal sentinel `'default'` when no group matches, not null

**Decision**: When no group in the user's memberships matches any entry in `group_order`, return the literal string `'default'` (not `null`, not an empty string, not `false`).

**Alternatives considered:**

- Return `null` and let callers decide how to handle "no match". Rejected — the dashboard system already has a `'default'` sentinel (`Dashboard::DEFAULT_GROUP_ID`); using `'default'` makes callers uniform.
- Return the first group from the user's actual memberships as a fallback. Rejected — that order is Nextcloud's internal, not the admin's configured order, so it doesn't represent admin intent.

**Rationale**: A consistent, named sentinel is more debuggable than null and aligns with the existing `multi-scope-dashboards` change which also uses `'default'` to represent "the fallback workspace".

### D3: Tolerate stale group IDs in group_order; never throw

**Decision**: If an entry in `group_order` no longer exists in Nextcloud (verified via `IGroupManager::get($id) === null`), skip it without logging or throwing. Continue walking the ordered list.

**Alternatives considered:**

- Throw an exception so the admin is alerted. Rejected — during a group deletion, the exception fires on every user login until the admin edits `group_order`. Too noisy, too fragile. Better to silently skip and require the admin to clean up via the settings UI.
- Log a warning. Rejected — similar to throwing; generates logs without actionable intent.
- Remove the stale entry automatically. Rejected — the service has no write authority; that's the settings UI's job.

**Rationale**: Resilience over purity. A human admin will eventually notice stale entries (or not — they're harmless) and edit the list. Throwing on every login makes that maintenance work urgent and annoying.

### D4: Read-only access via AdminSettingsService; no caching in the service

**Decision**: `AdminSettingsService::getGroupOrder(): array` reads the JSON string from `oc_mydash_admin_settings` and returns it fresh on every call (no in-service caching).

**Alternatives considered:**

- Cache in memory during the request lifetime. Rejected — if the admin changes `group_order` in one request, a second request in the same process should see the new value. Better to trust the database as the source of truth and let the frontend composable cache at the session level.
- Pre-load all admin settings on app boot. Rejected — premature optimisation; typical deployments have one or two admin-setting rows, so the lookup is negligible.

**Rationale**: Databases are fast; function-level purity is more important than micro-optimisation. The frontend composable caches the resolved group for the session.

### D5: Expose primary group + display name to frontend via initial state

**Decision**: The workspace controller calls `resolvePrimaryGroup`, fetches the group object via `IGroupManager::get($id)`, and returns both the ID and the human-readable display name to the frontend as `{ primaryGroup, primaryGroupDisplayName }` in the initial state.

**Alternatives considered:**

- Only return the ID, let the frontend look up the display name. Rejected — the frontend doesn't have direct access to `IGroupManager`; it would need a new API endpoint just for this lookup. Better to include it in the existing initial-state response.
- Store the display name in the database. Rejected — group display names are mutable in Nextcloud; storing a copy is redundant. Fetch it fresh on every page load.

**Rationale**: One API call (the existing initial-state fetch) carries all the data the frontend needs. The display name is cached by the frontend composable for the session.

### D6: No new API endpoints; lever the existing initial-state mechanism

**Decision**: Reuse the existing workspace initial-state response (`GET /api/runtime-shell` or equivalent) to include `{ primaryGroup, primaryGroupDisplayName }`. No new `/api/user-groups` or similar endpoints.

**Alternatives considered:**

- Add a dedicated `/api/user-groups` endpoint. Rejected — adds a new call site, requires its own tests, owns its own auth/error paths. The initial-state response is already authoritative.
- Embed the resolver in the frontend and have the frontend call Nextcloud's `/ocs/v2.php/apps/admin/api/v1/groups` endpoint. Rejected — that endpoint is admin-only; regular users can't call it. The backend must resolve.

**Rationale**: Simplicity. One API call carries all initial state, including the primary group. No new surface area.

## Risks / Trade-offs

- **Risk:** If the admin deletes a group from Nextcloud without removing it from `group_order`, users in that deleted group will silently see the next-priority group's dashboards. → **Mitigation:** The admin UI for editing `group_order` should warn about deleted groups or auto-remove them. For now, the admin is responsible.
- **Risk:** `group_order` is a flat list with no nested scopes or hierarchies. → **Mitigation:** Flat is simple; hierarchies can be added in a future change if governance structures demand it.
- **Risk:** The primary group is resolved once at page load; if the admin changes `group_order` mid-session, the user doesn't see the change until they reload. → **Mitigation:** Acceptable; group reordering is rare and non-urgent. Not a live-update scenario.
- **Trade-off:** No automatic cleanup of stale group IDs. → **Accepted:** Stale entries are harmless; administrative cleanup is the right place for this concern.

## Seed Data

No seed data required. The `group_order` setting is empty by default (returns `'default'` for all users).

## Reuse Analysis

- **OpenRegister ObjectService**: This change does NOT use OpenRegister. The primary group list is admin configuration (stored in `oc_mydash_admin_settings`), not domain data. ADR-001 reserves OpenRegister for domain data; admin settings live in `IAppConfig`.
- **IGroupManager**: This change consumes the existing Nextcloud service to read group memberships and group metadata (display names).
- **Nextcloud's initial-state mechanism**: Existing pattern; no new abstraction needed.

## Migration Plan

1. **Service implementation** — `AdminTemplateService::resolvePrimaryGroup()` + helper + tests land first. Pure reads, no writes; zero schema impact.
2. **Integrate into `WorkspaceController::index`** — wire the resolver into the workspace rendering path.
3. **Refactor REQ-DASH-013 + REQ-DASH-018** — update existing dashboard-resolution code to consume the resolver instead of inlining the lookup.
4. **Frontend wiring** — surface the resolved group in initial state; update `useUserGroups()` composable to expose it.
5. **Quality gates** — all tests pass; no pre-existing debt introduced.
6. **Rollback:** Pure backend change with no schema impact. Reverting the PR restores the previous (ad-hoc) behaviour with no data loss.

## Open Questions

- **Q:** What happens if a user's only group is deleted mid-session? → **A:** They see `'default'` dashboards on next page load. No automatic re-login or session invalidation.
- **Q:** Can a user explicitly choose a different primary group via the dashboard switcher? → **A:** Yes, that's delegated to the existing `dashboard-switcher-sidebar` change. The switcher's choice is session-local (not persisted to the admin's `group_order`).
- **Q:** What if Nextcloud's `IGroupManager` is unavailable or throws? → **A:** That's a service-level failure (500 error), not specific to this change. Handled by the app's error handler.
