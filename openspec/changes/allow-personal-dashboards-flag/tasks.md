# Tasks — allow-personal-dashboards-flag

## 1. Backend

- [ ] 1.1 Add `DashboardService::assertPersonalDashboardsAllowed(): void` (throws `PersonalDashboardsDisabledException`)
- [ ] 1.2 Define `PersonalDashboardsDisabledException` mapping to HTTP 403 with `error: 'personal_dashboards_disabled'`
- [ ] 1.3 Call assert in `DashboardController::create` (when type=user) and `::fork`
- [ ] 1.4 Ensure read/update/delete endpoints do NOT call the assert
- [ ] 1.5 Update `WorkspaceController::index` to push `allowUserDashboards` initial state
- [ ] 1.6 Update admin endpoints to surface flag in their initial state too

## 2. Frontend

- [ ] 2.1 Hide "+ New Dashboard" sidebar button when `!allowUserDashboards`
- [ ] 2.2 Hide "Fork to personal" button when `!allowUserDashboards`
- [ ] 2.3 Surface 403 with `error === 'personal_dashboards_disabled'` as a localised toast
- [ ] 2.4 Document the toggle's "data is preserved" behaviour in the admin UI helper text

## 3. Tests

- [ ] 3.1 PHPUnit: 403 envelope shape exactly matches REQ-ASET-003 scenario
- [ ] 3.2 PHPUnit: existing personal dashboards remain readable/editable when flag off
- [ ] 3.3 PHPUnit: toggling does not mutate data (assert row counts before/after)
- [ ] 3.4 Playwright: button visibility matches flag state
- [ ] 3.5 Playwright: direct API call (bypassing UI) still returns 403

## 4. Quality

- [ ] 4.1 `composer check:strict` passes
- [ ] 4.2 OpenAPI updated with the 403 response variant
- [ ] 4.3 Translation file entries for `'Personal dashboards are not enabled by your administrator'`
