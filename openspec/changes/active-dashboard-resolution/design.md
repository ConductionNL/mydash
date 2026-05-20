# Design — active-dashboard-resolution

## Overview

`multi-scope-dashboards` (REQ-DASH-011..014) introduced `group_shared` dashboards and the `GET /api/dashboards/visible` resolution endpoint. `default-dashboard-flag` introduced `isDefault` on dashboard records. `group-routing` (REQ-TMPL-012) introduced `AdminTemplateService::resolvePrimaryGroup()` as the single source of truth for which Nextcloud group the current user routes into.

Each of these changes delivers a piece of the multi-scope model, but none of them defines *which dashboard* to render when the user first loads the workspace. Without a canonical resolver, `WorkspaceController`, the Pinia store, and any future entry-point that needs to "pick the active dashboard" would each invent their own ordering — producing flicker, A/B confusion, and divergence between the backend initial-state push and the client-side store resolution after a `switchDashboard()` call.

This change closes that gap: a single `DashboardService::resolveActiveDashboard()` method defines the canonical 7-step precedence chain, a `POST /api/dashboards/active` endpoint lets the frontend persist the user's explicit choice, and the frontend store mirrors the same chain for synchronous post-mutation picks.

## Goals

- Define one canonical, deterministic precedence for picking the active dashboard across all call sites (backend initial-state, frontend store post-mutation).
- Persist the user's explicit dashboard choice via `oc_preferences` so it survives page reloads.
- Silently self-heal stale preferences (dashboard deleted, user removed from group) without user-visible errors.
- Expose a `source` field on the resolved result so the frontend knows which PUT endpoint to route save operations to.
- Render an empty-state affordance when the resolver returns `null` (no dashboards exist for the user at all).

## Non-Goals

- A UI for managing group-shared dashboards — that ships in `admin-group-management`.
- Real-time active-dashboard push when an admin edits a group-shared dashboard — propagation is on next page load.
- Validating that the UUID written via `POST /api/dashboards/active` actually exists — the resolver's stale-preference path handles invalid UUIDs on next render (deliberate, see D3 below).
- Changing how `GET /api/dashboards/visible` works — this change is a consumer of that endpoint, not a modifier of it.
- Per-user `isActive` flags on dashboard rows — see D1.

## Architecture

### PHP side

```
WorkspaceController::index
    │
    ├─ AdminTemplateService::resolvePrimaryGroup($userId)   ← group-routing change
    │       returns: string (group id or 'default')
    │
    └─ DashboardService::resolveActiveDashboard($userId, $primaryGroupId)
            │
            ├─ DashboardService::findVisibleToUser($userId)    ← REQ-DASH-013
            │       returns: Dashboard[] tagged with source
            │
            ├─ IConfig::getUserValue('mydash', 'active_dashboard_uuid', '')
            │
            └─ walk 7-step chain → {dashboard: Dashboard, source: 'user'|'group'|'default'} | null
                    │
                    └─ [side-effect on stale pref only]
                       IConfig::deleteUserValue('mydash', 'active_dashboard_uuid')
                       + LoggerInterface::warning(...)

DashboardService::setActivePreference($userId, $uuid)
    └─ IConfig::setUserValue / deleteUserValue (empty string → delete)

DashboardController::setActiveDashboard()          POST /api/dashboards/active
    └─ DashboardService::setActivePreference($userId, $uuid)
    └─ JSONResponse {status: 'success'} HTTP 200
```

`WorkspaceController` pushes `activeDashboardId` (string; empty string for null) and `dashboardSource` (`'user'|'group'|'default'|''`) into `IInitialState` for the workspace boot payload.

### Frontend side

`useDashboardsStore` mirrors the precedence chain in a synchronous `resolveActive()` getter that operates on the already-fetched `visibleDashboards` array. When `switchDashboard(uuid)` is called (e.g. the user clicks a dashboard tab):

1. `resolveActive()` finds the record in the store.
2. Store state is updated synchronously (no flicker).
3. `POST /api/dashboards/active` fires fire-and-forget — failure surfaces as a toast but does not block the UI or roll back the local state.

## Decisions

### D1: `oc_preferences` key rather than a per-row `isActive` boolean

**Decision:** Store the user's active-dashboard choice as a single `oc_preferences` record via `IConfig::setUserValue('mydash', 'active_dashboard_uuid', $uuid)`.

**Alternatives considered:**

- A per-user `isActive TINYINT` column on `oc_mydash_dashboards`, toggled exclusively per user. Rejected because `group_shared` and `admin_template` dashboards are shared rows — there is no per-user row to flip, so the flag would only be settable for personal dashboards. Implementing it would require a separate join table (user × dashboard → active boolean), which is a schema migration and mapper change for a feature that `IConfig` already handles in a single line.
- Store the active UUID in `oc_mydash_admin_settings` per user. Rejected because `admin_settings` is an app-global config store, not a per-user store. Per-user prefs belong in `oc_preferences`.

**Rationale:** `IConfig::setUserValue/getUserValue` is already injected in `DashboardService` for other purposes. Using it requires zero schema changes, zero migration, and is idempotent by design.

### D2: Stale-preference cleanup per-request, not via cron

**Decision:** When `resolveActiveDashboard()` reads an `active_dashboard_uuid` that is not present in `findVisibleToUser()` results, it immediately calls `IConfig::deleteUserValue('mydash', 'active_dashboard_uuid')` and emits a `LoggerInterface::warning` before continuing down the chain.

**Alternatives considered:**

- A background job (`IJob`) that periodically scans all users' preferences and deletes stale ones. Rejected because it requires iterating `oc_preferences` for every user, which is expensive on large instances, and the stale state is harmless until the user's next page load anyway.
- Leave the stale value, retry the preference on every request. Rejected because the pref would never self-heal without manual intervention.

**Rationale:** Stale prefs are cleared at the moment they are observed to be stale — the workspace page render. The `IConfig::deleteUserValue` call cost is O(1) and bounded by the user's login frequency, not by the number of dashboards in the system. If this becomes a hotspot (measurable via the WARNING log line) a cron approach can be layered in as a follow-up.

### D3: No existence check on `POST /api/dashboards/active` write

**Decision:** `DashboardController::setActiveDashboard()` accepts any `{uuid: string}` and writes it to `oc_preferences` without checking whether a dashboard with that UUID exists.

**Alternatives considered:**

- Validate that the UUID exists and is visible to the user before persisting. Rejected because it requires an extra `findObject` call in the write path, and the resolver on next render already performs the visibility check and clears stale values. Validating on write would double the cost for no user-visible benefit.
- Validate existence only (not visibility). Rejected for the same reason, plus it could let a user pin a dashboard they can no longer see, which the resolver would then immediately clear — making the validation pointless.

**Rationale:** The write endpoint is intentionally cheap. The resolver is the authority on whether a preference is valid; it enforces visibility at read time (REQ-DASH-018 scenario "stale preference is silently cleared").

### D4: Resolver purity constraint

**Decision:** `resolveActiveDashboard()` MUST have no side effects except the stale-preference cleanup in D2. In particular, it MUST NOT persist a new preference, mutate dashboard records, or emit events.

**Rationale:** A pure resolver (modulo the documented stale-pref cleanup) is straightforward to unit-test with table-driven test fixtures — the PHPUnit suite can exercise all 7 precedence rows without mocking complex side effects. The write path is cleanly separated into `setActivePreference()`, which is only called from `DashboardController::setActiveDashboard()`.

### D5: `source` field on the resolved result

**Decision:** `resolveActiveDashboard()` returns `['dashboard' => Dashboard, 'source' => 'user'|'group'|'default']` or `null`, with `source` derived from how the dashboard was found (step 1 → `'user'`, steps 2–4 → `'group'`, steps 3/5 → `'default'`, step 6 → `'user'`).

**Rationale:** The frontend needs to know which PUT endpoint to send save operations to. Without `source`, a component that wants to "save this widget layout" doesn't know whether to call `PUT /api/dashboards/{uuid}` (personal) or `PUT /api/dashboards/group/{groupId}/{uuid}` (group-shared, admin-only). Tagging at resolution time keeps the frontend logic dead simple — it reads `activeDashboardSource` from the store and routes accordingly. This mirrors the same tagging already done in `GET /api/dashboards/visible` (REQ-DASH-013 D4).

## Data Model Changes

**No schema changes.** This change introduces:

- One `oc_preferences` key: `app = 'mydash'`, `configkey = 'active_dashboard_uuid'`, `configvalue = <uuid string>`. Managed via `IConfig::setUserValue` / `getUserValue` / `deleteUserValue`. Not a new table or column.
- One PHP constant: `DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY = 'active_dashboard_uuid'` to keep the key name in one place.

The `DashboardService::resolveActiveDashboard()` method is a read-only consumer of the tables already owned by `multi-scope-dashboards` (`oc_mydash_dashboards`) and `group-routing` (`oc_mydash_admin_settings` for `group_order`).

## API Surface

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/dashboards/active` | `#[NoAdminRequired]` (any logged-in user) | Persist the user's active-dashboard UUID. Body: `{uuid: string}`. Empty string clears. No existence check on write. |

No existing routes are changed. The `WorkspaceController::index()` that pushes `activeDashboardId` + `dashboardSource` into initial state is a modification to an existing controller method, not a new route.

## Seed Data

This change introduces no new OpenRegister schemas and does not modify existing ones. The per-user preference lives in Nextcloud's `oc_preferences` table, which is not seeded via `lib/Settings/{app}_register.json`. No `_registers.json` entry is required.

The downstream data this resolver operates on (group-shared dashboards, personal dashboards, the default-group dashboard) is seeded by the `multi-scope-dashboards` change.

## Reuse Analysis (ADR-012)

| Capability reused | Source |
|---|---|
| `DashboardService::findVisibleToUser()` | `multi-scope-dashboards` — already returns the deduplicated, source-tagged union that the resolver walks |
| `AdminTemplateService::resolvePrimaryGroup()` | `group-routing` — already computes the user's primary group from the admin-configured `group_order` setting; consumed without modification |
| `IConfig` (Nextcloud core) | Already injected in `DashboardService` for other settings operations |
| `IGroupManager` (Nextcloud core) | Already injected for group membership checks |
| `IInitialState` (Nextcloud core) | Already used by `WorkspaceController` for the boot payload |

No new OpenRegister services, no custom middleware, no custom auth mechanisms. The resolver is a composition of already-available primitives. **No overlap with ObjectService, RegisterService, SchemaService, or ConfigurationService.** The only domain-specific logic is the 7-step precedence chain itself.

## Declarative-vs-imperative decision (ADR-031)

The resolution chain is control-flow logic (conditional lookup, fallback chain) over preference data stored in `oc_preferences`, not OpenRegister object state. There is no lifecycle (`x-openregister-lifecycle`), no aggregation (`x-openregister-aggregations`), and no calculation (`x-openregister-calculations`) applicable here.

The preference key is not an OpenRegister object property — it is a Nextcloud-core per-user config key. The OR schema extensions operate on OpenRegister object fields; a string preference stored in `oc_preferences` is outside their scope.

**Verdict: PHP service implementation is the correct approach here.** No OR declarative extension applies. Exception documented per ADR-031.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Stale-pref cleanup fires on every request if the dashboard was permanently deleted and the user never visits the workspace again | The `IConfig::deleteUserValue` call is idempotent — deleting a non-existent key is a no-op. The WARNING log appears at most once per login session (the key is gone after the first cleanup). |
| `resolveActiveDashboard()` relies on `findVisibleToUser()` which issues three queries — potential N+1 on high-traffic instances | `findVisibleToUser()` is already the source of truth for `GET /api/dashboards/visible`; its performance is owned by `multi-scope-dashboards`. A cache layer (if needed) belongs there, not in the resolver. |
| Frontend `resolveActive()` and backend resolver diverge over time | Both implement the same 7-step chain defined in REQ-DASH-018. The spec is the single authoritative source. The PHPUnit table-driven test (Task 10) and the Playwright test (Task 12) jointly verify both sides against the same scenarios. |
| A user in zero Nextcloud groups with no personal dashboards always hits the empty-state | Correct and expected — step 7 of the chain returns `null`. The empty-state UI (`resolveActive() === null`) includes a "Create your first dashboard" affordance so the user is not stuck. |
| `POST /api/dashboards/active` with a UUID from a different user's private dashboard would be written and silently cleared on next render | This is acceptable. The endpoint requires a logged-in session (`#[NoAdminRequired]`); an attacker can only store a UUID in their own preference row. The resolver clears it on next render because the dashboard is not visible to them. No data leak occurs. |

## Open Questions

- Should the resolver also emit an `ActivityService` entry when it silently clears a stale preference? Current decision: no — this is a housekeeping operation, not a user-initiated action. The WARNING log is sufficient for operators; an activity entry would clutter the user's activity feed.
- Should `switchDashboard()` update the store's `activeDashboardSource` field synchronously (using the already-resolved record's source) or wait for the next `resolveActive()` call? Current decision: update synchronously from the store record — the UUID is already in `visibleDashboards` with its `source` tag, so no re-resolution is needed.
