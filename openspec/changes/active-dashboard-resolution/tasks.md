# Tasks — active-dashboard-resolution

## Tasks

- [ ] Task 1: Define `DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY = 'active_dashboard_uuid'` and `DashboardService::resolveActiveDashboard($userId, ?$primaryGroupId): ?array` returning `['dashboard' => Dashboard, 'source' => 'user'|'group'|'default']` or `null`
- [ ] Task 2: Implement the 7-step precedence chain in the resolver exactly as REQ-DASH-018 lists (saved pref → group default → default-group default → first-in-group → first-in-default-group → first personal → null); resolver MUST be otherwise pure (no other read-side effects)
- [ ] Task 3: Stale-preference auto-clear — when the saved UUID is not in `findVisibleToUser` results, call `IConfig::deleteUserValue` (write-on-read) and emit a `LoggerInterface::warning` line
- [ ] Task 4: Add `DashboardService::setActivePreference($userId, $uuid): void` that writes via `IConfig::setUserValue` (or deletes when uuid is empty); no existence check on write per REQ-DASH-019
- [ ] Task 5: Add `DashboardController::setActiveDashboard()` mapped to `POST /api/dashboards/active` with `#[NoAdminRequired]` — accepts `{uuid: string}`, returns HTTP 200 `{status:'success'}`; route registered in `appinfo/routes.php`
- [ ] Task 6: Wire `WorkspaceController` to call `resolveActiveDashboard($currentUserId, $primaryGroupId)` on first render and push `activeDashboardId` (or `''` when null) + `dashboardSource` into initial-state JSON via `IInitialState`; null resolver result renders the empty-state UI
- [ ] Task 7: Frontend — mirror the 7-step precedence in `useDashboardsStore.resolveActive()` for client-side `switchDashboard()` flows after store mutations
- [ ] Task 8: Add `switchDashboard(uuid)` store action — updates store state and POSTs to `/api/dashboards/active` fire-and-forget (failure surfaces as a toast but does not block the UI)
- [ ] Task 9: Add the empty-state component shown when `resolveActive()` returns null, including a "Create your first dashboard" affordance
- [ ] Task 10: PHPUnit — table-driven test exercising all 7 precedence steps + permutations (saved pref / no pref; group default present/absent; default-group default present/absent; first-in-group present/absent; first personal present/absent; nothing-at-all); cross-group preference invalidated correctly
- [ ] Task 11: PHPUnit — stale preference cleared exactly once per request (not on every visibility check); `setActivePreference` accepts non-existent UUIDs without erroring; empty-string uuid clears the preference (REQ-DASH-019)
- [ ] Task 12: Playwright — empty state shows on a fresh user with no dashboards; `switchDashboard` POSTs the new UUID and the next page load picks it up; stale preference (dashboard deleted between sessions) silently falls through to step 2 — no error toast
- [ ] Task 13: Quality gates — `composer check:strict`, ESLint+Stylelint, OpenAPI/Postman regen for the new endpoint, `nl`+`en` i18n for the empty-state copy + new error strings, SPDX-in-docblock on new PHP, all 10 hydra-gates green; document in `design.md` why stale prefs are cleaned per-request rather than via cron

## Verification

`openspec validate` exits clean. Resolver returns the correct dashboard for all 7 precedence rows in the test matrix; stale prefs self-heal.

## Tests (company-wide ADR-009)

PHPUnit per Tasks 10–11; Playwright per Task 12. Newman/Postman updated for the new endpoint.

## Documentation (company-wide ADR-010)

Changelog entry covering the active-dashboard resolution chain + the empty-state UX.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for the empty-state copy and new error strings.
