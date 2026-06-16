# Tasks — allow-personal-dashboards-flag

## 1. Backend

- [x] 1.1 Add `DashboardService::assertPersonalDashboardsAllowed(): void` (throws `PersonalDashboardsDisabledException`) (pre-existing — added `getAllowUserDashboards()` precondition checker as proposal demanded)
- [x] 1.2 Define `PersonalDashboardsDisabledException` mapping to HTTP 403 with `error: 'personal_dashboards_disabled'` (pre-existing)
- [x] 1.3 Call assert in `DashboardApiController::create` — moved BEFORE param resolution so the 403 envelope is the first thing returned. Fork endpoint does not exist yet (handled by separate `fork-current-as-personal` change).
- [x] 1.4 Read endpoints (`list`, `visible`, `getActive`, `update`, `delete`, `activate`, `setActiveDashboard`) confirmed to NOT call the assert
- [x] 1.5 `PageController::index` pushes `allowUserDashboards` via `InitialStateBuilder::setAllowUserDashboards()` (pre-existing — refactored to use the new `DashboardService::getAllowUserDashboards()` helper)
- [x] 1.6 `MyDashAdmin::getForm` pushes the flag too (pre-existing — refactored to use the helper)

## 2. Frontend

- [x] 2.1 Hide "Create dashboard…" entry in `DashboardConfigMenu` when `!allowUserDashboards` (read via `inject` from the typed initial-state contract)
- [x] 2.1 Hide empty-state "Create dashboard" button in `Views.vue` and swap copy for the "managed by your administrator" explainer
- [ ] 2.2 Hide "Fork to personal" button when `!allowUserDashboards` (deferred — fork endpoint owned by `fork-current-as-personal` change; handled there)
- [x] 2.3 Surface 403 with `error === 'personal_dashboards_disabled'` as a localised toast (`useDashboardStore.createDashboard` action)
- [x] 2.4 Document the toggle's "data is preserved" behaviour in the admin UI helper text (`AdminSettings.vue`)
- [x] 2.5 (pre-existing bug fix) `AdminSettings.vue::saveSettings` was sending the long camelCase keys (`allowUserDashboards`); the `PUT /api/admin/settings` endpoint accepts the abbreviated names (`allowUserDash`). Toggling never persisted before this fix.

## 3. Tests

- [x] 3.1 PHPUnit: 403 envelope shape exactly matches REQ-ASET-003 scenario (`DashboardServicePersonalGatingTest::testExceptionExposesStableErrorEnvelope`)
- [x] 3.2 PHPUnit: gating throws on flag-off / silent on flag-on (`DashboardServicePersonalGatingTest`)
- [x] 3.3 Vitest: store surfaces toast on 403 + does not on unrelated errors (`dashboard.spec.js — createDashboard gating`)
- [ ] 3.4 Playwright: button visibility matches flag state (out of scope — deferred to e2e suite)
- [ ] 3.5 Playwright: direct API call (bypassing UI) still returns 403 (out of scope — deferred to e2e suite)

## 4. Quality

- [x] 4.1 `composer check:strict` passes (full suite ran)
- [ ] 4.2 OpenAPI updated with the 403 response variant (no OpenAPI source-of-truth in this repo today; tracked separately)
- [x] 4.3 Translation file entries for `'Personal dashboards are not enabled by your administrator'` and the admin helper text in all four `l10n/` files
