# Tasks — active-dashboard-resolution

## 1. Backend resolver

- [ ] 1.1 Add user pref key constant `DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY = 'active_dashboard_uuid'`
- [ ] 1.2 Add `DashboardService::resolveActiveDashboard(string $userId, ?string $primaryGroupId): ?array` returning `['dashboard' => Dashboard, 'source' => 'user'|'group'|'default']` or `null`
- [ ] 1.3 Implement the 7-step precedence chain exactly as REQ-DASH-018 lists (saved pref → group default → default-group default → first-in-group → first-in-default-group → first personal → null)
- [ ] 1.4 Implement stale-preference auto-clear: when the saved UUID is not in `findVisibleToUser` results, call `IConfig::deleteUserValue` (write-on-read) and emit a `LoggerInterface::warning` line
- [ ] 1.5 Resolver MUST be otherwise pure (no other side effects on read)

## 2. Backend write endpoint

- [ ] 2.1 Add `DashboardService::setActivePreference(string $userId, string $uuid): void` that writes to `IConfig::setUserValue` (or deletes when uuid is empty string)
- [ ] 2.2 Add `DashboardController::setActiveDashboard()` mapped to `POST /api/dashboards/active` with `#[NoAdminRequired]` — accepts `{uuid: string}`, returns HTTP 200 `{status: 'success'}`
- [ ] 2.3 Register the route in `appinfo/routes.php`
- [ ] 2.4 No existence check on write (per REQ-DASH-019 scenario "no existence check on write")

## 3. Workspace integration

- [ ] 3.1 Update `WorkspaceController` to call `resolveActiveDashboard($currentUserId, $primaryGroupId)` on first render
- [ ] 3.2 Push `activeDashboardId` (or `''` when null) and `dashboardSource` into initial-state JSON via `IInitialState`
- [ ] 3.3 When resolver returns null, ensure the page renders the empty-state UI (per REQ-DASH-018 scenario "empty state")

## 4. Frontend

- [ ] 4.1 Mirror the 7-step precedence in `useDashboardsStore.resolveActive()` for client-side `switchDashboard()` flows after store mutations
- [ ] 4.2 Add `switchDashboard(uuid)` action that updates store state and POSTs to `/api/dashboards/active` (fire-and-forget; surface failure as a toast but do not block UI)
- [ ] 4.3 Empty-state component shown when `resolveActive()` returns null — includes a "Create your first dashboard" affordance

## 5. PHPUnit tests

- [ ] 5.1 Table-driven test covering all 7 steps with permutations (saved pref / no pref; group default present / absent; default-group default present / absent; first-in-group present / absent; first personal present / absent; nothing-at-all)
- [ ] 5.2 Stale preference cleared exactly once per request (not on every visibility check)
- [ ] 5.3 Cross-group preference invalidated correctly — alice pref points to a dashboard whose group she no longer belongs to
- [ ] 5.4 `setActivePreference` accepts non-existent UUIDs without erroring (REQ-DASH-019 scenario "no existence check on write")
- [ ] 5.5 Empty-string uuid clears the preference (REQ-DASH-019 scenario "empty uuid clears the preference")

## 6. Playwright tests

- [ ] 6.1 Empty state shows on a fresh user with no dashboards (any type, any group)
- [ ] 6.2 Switching dashboard fires `POST /api/dashboards/active` with the new UUID and the next page load picks up the saved choice
- [ ] 6.3 Stale preference (dashboard deleted between sessions) silently falls through to step 2 of the chain — no error toast

## 7. Quality gates

- [ ] 7.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 7.2 ESLint + Stylelint clean on touched Vue/JS files
- [ ] 7.3 Update generated OpenAPI spec / Postman collection so external API consumers see the new endpoint
- [ ] 7.4 `i18n` keys for new error messages and the empty-state copy in both `nl` and `en` per the i18n requirement
- [ ] 7.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 7.6 Run all 10 `hydra-gates` locally before opening PR
- [ ] 7.7 Stale prefs are cleaned per request, not via cron — document the rationale in `design.md` if added (see proposal Notes)
