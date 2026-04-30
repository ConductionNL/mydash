# Active-dashboard resolution chain

## Why

Define the deterministic precedence MyDash uses to pick *which* dashboard to render for a user when they open the workspace, given the multi-scope model (`user` / `group_shared` / `default`-group). The existing REQ-DASH-009 ("Dashboard Resolution Chain") covers a one-scope personal-only model. The multi-scope model (introduced by `multi-scope-dashboards` and `default-dashboard-flag`) needs a longer chain that prefers the user's saved preference but falls back through group → default → first. Without a canonical resolver each frontend code path could diverge and pick a different "active" dashboard, producing flicker and confusing users.

## What Changes

- Introduce a per-user pref key `active_dashboard_uuid` that holds the UUID of the dashboard the user last opened (or explicitly pinned).
- Add `DashboardService::resolveActiveDashboard(string $userId, ?string $primaryGroupId): ?array` that walks a 7-step precedence chain and returns `{dashboard, source}` (or `null` for the empty-state case).
- The 7-step chain is: (1) saved pref UUID if visible to user, (2) `isDefault=1` group-shared in primary group, (3) `isDefault=1` default-group dashboard, (4) first group-shared in primary group, (5) first group-shared in default group, (6) first personal dashboard, (7) `null`.
- If the saved pref points to a dashboard the user can no longer see (deleted / removed from group), silently clear the pref on read (write-on-read with WARNING log) and continue down the chain. No error surfaced to the user.
- The resolver returns `source: 'user' | 'group' | 'default'` so the frontend knows which API endpoint to PUT to on save.
- Add `POST /api/dashboards/active` write endpoint accepting `{uuid: string}` so the frontend can persist the user's choice on switch. Empty string clears the pref. No existence check on write — the resolver's stale-preference path handles invalid UUIDs.
- `WorkspaceController` calls the resolver and pushes `activeDashboardId` + `dashboardSource` into initial state on first render.
- Frontend `useDashboardsStore.resolveActive()` mirrors the precedence so client-side `switchDashboard()` picks consistently after store mutations.

## Capabilities

### New Capabilities

(none — the feature folds into the existing `dashboards` capability)

### Modified Capabilities

- `dashboards`: adds REQ-DASH-018 (active-dashboard resolution chain — multi-scope) and REQ-DASH-019 (persist active-dashboard preference). Existing REQ-DASH-001..017 are untouched. REQ-DASH-009 (the one-scope chain) remains in force for callers that ask for a personal-only resolution; the new REQ-DASH-018 is the workspace-level resolver.

## Impact

**Affected code:**

- `lib/Service/DashboardService.php` — add `resolveActiveDashboard(string $userId, ?string $primaryGroupId): ?array`, `setActivePreference(string $userId, string $uuid): void`, and the `ACTIVE_DASHBOARD_UUID_PREF_KEY` constant
- `lib/Controller/DashboardController.php` — add `setActiveDashboard()` mapped to `POST /api/dashboards/active`
- `lib/Controller/WorkspaceController.php` — call the resolver and push `activeDashboardId` + `dashboardSource` into initial-state JSON on first render
- `appinfo/routes.php` — register `POST /api/dashboards/active`
- `src/stores/dashboards.js` — frontend mirror of the precedence; `resolveActive()` getter; `switchDashboard(uuid)` action POSTs to the new endpoint

**Affected APIs:**

- 1 new route (`POST /api/dashboards/active`)
- No existing routes changed — `GET /api/dashboards/visible` (REQ-DASH-013) is unchanged and is the source of truth for "what dashboards can the user see"

**Dependencies:**

- `OCP\IConfig::setUserValue` / `getUserValue` — already injected elsewhere, used to persist the per-user preference
- No new composer or npm dependencies

**Migration:**

- Zero schema impact: the preference lives in `oc_preferences` via `IConfig`. No new table or column.
- No data backfill required: missing preference is treated as "no saved choice" and the chain falls through to step 2.

## Notes

- Resolver MUST be pure (no side effects on read) except the silent pref-cleanup when the saved id is invalid. That cleanup is observable in REQ-DASH-018 scenario "stale preference is silently cleared".
- We deliberately chose a single `oc_preferences` key over the alternative of repurposing the per-user `isActive` boolean flag because group-shared dashboards have no per-user row to flip — the pref key works uniformly across all three scopes.
- Stale prefs are cleaned per-request, not via cron — the load on `IConfig::deleteUserValue` is bounded by login frequency. If this becomes a hotspot we can revisit with a background job in a follow-up change.
