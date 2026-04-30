# Tasks — fork-current-as-personal

## 1. Backend

- [ ] Add `WidgetPlacementMapper::cloneToDashboard(int $sourceDashboardId, int $targetDashboardId): void` (bulk INSERT … SELECT with new IDs)
- [ ] Add `DashboardService::forkAsPersonal(string $userId, string $sourceUuid, ?string $name): Dashboard` wrapped in `IDBConnection::beginTransaction`
- [ ] Add admin-setting check on `allow_user_dashboards`
- [ ] Add 404 path for sources the user cannot read (reuse REQ-DASH-013 visibility resolver)
- [ ] Default name uses `IL10N::t('My copy of {name}', ['name' => $source->getName()])`
- [ ] Add `DashboardController::fork` mapped to `POST /api/dashboards/{uuid}/fork`

## 2. Frontend

- [ ] Wire "+ New Dashboard" button in `DashboardSwitcherSidebar` to `forkAsPersonal(activeDashboardUuid, t('My Dashboard'))`
- [ ] On 403, surface the toast "Personal dashboards are not enabled by your administrator"
- [ ] Optimistic add to `userDashboards` store; rollback on error

## 3. Tests

- [ ] PHPUnit: deep-copy preserves all placement fields including tile-* and styleConfig
- [ ] PHPUnit: rollback on placement insert failure
- [ ] PHPUnit: gating returns 403 when admin setting disabled
- [ ] PHPUnit: 404 on source you cannot read
- [ ] PHPUnit: forking your own personal dashboard works (independent duplicate)
- [ ] Playwright: fork → switch → edit → original group dashboard untouched

## 4. Quality

- [ ] `composer check:strict` passes
- [ ] OpenAPI updated for new endpoint
- [ ] Document in `dashboards/spec.md` REQ-DASH-005 NOTE that personal dashboards from forks share resource URLs (REQ-DASH-022 cross-reference)
