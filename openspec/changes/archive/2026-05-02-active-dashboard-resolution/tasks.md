# Tasks — active-dashboard-resolution

## 1. Backend resolver

- [x] 1.1 Add user pref key constant `DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY = 'active_dashboard_uuid'`
- [x] 1.2 Add `DashboardService::resolveActiveDashboard(string $userId, ?string $primaryGroupId): ?array` returning `['dashboard' => Dashboard, 'source' => 'user'|'group'|'default']` or `null`
- [x] 1.3 Implement the 7-step precedence chain exactly as REQ-DASH-018 lists (saved pref → group default → default-group default → first-in-group → first-in-default-group → first personal → null)
- [x] 1.4 Implement stale-preference auto-clear: when the saved UUID is not in `findVisibleToUser` results, call `IConfig::deleteUserValue` (write-on-read) and emit a `LoggerInterface::warning` line
- [x] 1.5 Resolver MUST be otherwise pure (no other side effects on read)

## 2. Backend write endpoint

- [x] 2.1 Add `DashboardService::setActivePreference(string $userId, string $uuid): void` that writes to `IConfig::setUserValue` (or deletes when uuid is empty string)
- [x] 2.2 Add `DashboardApiController::setActiveDashboard()` mapped to `POST /api/dashboards/active` with `#[NoAdminRequired]` — accepts `{uuid: string}`, returns HTTP 200 `{status: 'success'}`
- [x] 2.3 Register the route in `appinfo/routes.php` (route URL changed from `/api/dashboard/active` to `/api/dashboards/active` so it does not collide with the singular `/api/dashboard/{id}` wildcard)
- [x] 2.4 No existence check on write (per REQ-DASH-019 scenario "no existence check on write")

## 3. Workspace integration

- [x] 3.1 `PageController::index` calls `resolveActiveDashboard($currentUserId, $primaryGroupId)` on first render (controller is `PageController`, not `WorkspaceController`)
- [x] 3.2 Push `activeDashboardId` (or `''` when null) and `dashboardSource` into initial-state JSON via `InitialStateBuilder` setters (formal initial-state contract keys)
- [x] 3.3 When resolver returns null, the page renders the empty-state UI in `src/views/Views.vue` ("No dashboard yet" + "Create dashboard" affordance)

## 4. Frontend

- [x] 4.1 Mirror the 7-step precedence in `useDashboardStore.resolveActive` (Pinia getter) for client-side `switchDashboard()` flows after store mutations
- [x] 4.2 `switchDashboard(uuid)` action POSTs the new uuid to `/api/dashboards/active` via `persistActivePreference()` (fire-and-forget; failure logged but does not block UI)
- [x] 4.3 Empty-state component shown when `resolveActive` returns null — the existing `NcEmptyContent` block in `Views.vue` provides a "Create dashboard" affordance

## 5. PHPUnit tests

- [x] 5.1 Table-driven test covering all 7 steps with permutations (saved pref / no pref; group default present / absent; default-group default present / absent; first-in-group present / absent; first personal present / absent; nothing-at-all) — see `tests/Unit/Service/DashboardServiceActiveResolutionTest.php`
- [x] 5.2 Stale preference cleared exactly once per request (not on every visibility check)
- [x] 5.3 Cross-group preference invalidated correctly — alice pref points to a dashboard whose group she no longer belongs to
- [x] 5.4 `setActivePreference` accepts non-existent UUIDs without erroring (REQ-DASH-019 scenario "no existence check on write")
- [x] 5.5 Empty-string uuid clears the preference (REQ-DASH-019 scenario "empty uuid clears the preference")

## 6. Playwright tests

- [ ] 6.1 Empty state shows on a fresh user with no dashboards (any type, any group) — deferred (Playwright suite is pre-existing scaffolding only; covered by Vitest empty-state assertion + manual smoke)
- [ ] 6.2 Switching dashboard fires `POST /api/dashboards/active` with the new UUID and the next page load picks up the saved choice — deferred (covered by Vitest `switchDashboard wires the active-pref POST` test + backend `testStep1HonoursSavedPreference`)
- [ ] 6.3 Stale preference (dashboard deleted between sessions) silently falls through to step 2 of the chain — no error toast — deferred (covered by `testStalePreferenceIsClearedAndChainContinues`)

## 7. Quality gates

- [x] 7.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [x] 7.2 ESLint + Stylelint clean on touched Vue/JS files
- [ ] 7.3 Update generated OpenAPI spec / Postman collection so external API consumers see the new endpoint — deferred (no generator wired; route lives in `appinfo/routes.php` source-of-truth)
- [x] 7.4 `i18n` keys for new error messages and the empty-state copy in both `nl` and `en` per the i18n requirement (no new strings introduced; existing empty-state copy already translated in both locales)
- [x] 7.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 7.6 Run all 10 `hydra-gates` locally before opening PR — deferred (PR creation excluded by workflow scope)
- [x] 7.7 Stale prefs are cleaned per request, not via cron — documented in proposal Notes (no `design.md` needed)
