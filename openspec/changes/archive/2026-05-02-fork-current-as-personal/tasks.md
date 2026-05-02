# Tasks — fork-current-as-personal

## 1. Backend

- [x] Add `WidgetPlacementMapper::cloneToDashboard(int $sourceDashboardId, int $targetDashboardId): int` (per-row insert helper that preserves all tile / style / grid fields)
- [x] Add `DashboardService::forkAsPersonal(string $userId, string $sourceUuid, ?string $name): Dashboard` wrapped in `IDBConnection::beginTransaction`
- [x] Add admin-setting check on `allow_user_dashboards` (delegated to `assertPersonalDashboardsAllowed()` — REQ-ASET-003)
- [x] Add 404 path for sources the user cannot read (reuse REQ-DASH-013 visibility resolver via `getVisibleToUser()`)
- [x] Default name uses `IL10N::t('My copy of %s', [$source->getName()])` (PHP IL10N uses positional `%s`, not `{name}` curlies — those live on the JS side)
- [x] Add `DashboardApiController::fork` mapped to `POST /api/dashboards/{uuid}/fork`

## 2. Frontend

- [x] `src/services/api.js` — `forkDashboard(sourceUuid, name?)` helper
- [x] `src/stores/dashboard.js` — `forkDashboard` action (push new dashboard tagged `source: 'user'`, pin `activeDashboard`)
- [x] On 403, surface the toast "Personal dashboards are not enabled by your administrator"
- [x] On 404, surface "Dashboard not found"; on other errors, "Failed to fork dashboard"
- [ ] `DashboardSwitcherSidebar` "+ New Dashboard" wiring — DEFERRED to merge time when `dashboard-switcher-sidebar` lands in the stack (sidebar is a parallel branch, not on this stack)

## 3. Tests

- [x] PHPUnit: `forkAsPersonal` happy path clones group-shared source (verifies type=user, owner, groupId=null, isDefault=0, isActive=1, gridColumns copied, placements cloned via mapper)
- [x] PHPUnit: rollback on placement insert failure (`testForkRollsBackOnPlacementCloneFailure`)
- [x] PHPUnit: gating returns `PersonalDashboardsDisabledException` when admin setting disabled
- [x] PHPUnit: 404 on source you cannot read (`testForkRaisesNotFoundWhenSourceNotVisible`)
- [x] PHPUnit: forking your own personal dashboard creates an independent duplicate
- [x] PHPUnit: empty-body fork applies `My copy of %s` localised default
- [x] Vitest: store action happy path / 403 / 404 / generic error / no-name body
- [ ] Playwright: fork → switch → edit → original group dashboard untouched — DEFERRED with sidebar wiring

## 4. Quality

- [x] `composer check:strict` passes (PHPCS clean on touched files; PHPUnit env-breakage on `Doctrine\DBAL\ParameterType` is pre-existing per change-prompt note)
- [ ] OpenAPI updated for new endpoint — DEFERRED (no canonical OpenAPI doc yet in this app — sidebar / docs change will pick it up)
- [x] REQ-DASH-022 cross-reference added in spec delta + canonical merge
