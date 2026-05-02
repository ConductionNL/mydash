# Tasks — initial-state-contract

## 1. Backend — InitialStateBuilder service

- [x] 1.1 Create `lib/Service/InitialStateBuilder.php` with a `Page` enum (`WORKSPACE`, `ADMIN`) and a constructor accepting `IInitialState` + `Page` (enum lives at `lib/Service/InitialState/Page.php`)
- [x] 1.2 Add typed setter methods per page key (`setWidgets`, `setLayout`, `setPrimaryGroup`, `setPrimaryGroupName`, `setIsAdmin`, `setActiveDashboardId`, `setDashboardSource`, `setGroupDashboards`, `setUserDashboards`, `setAllowUserDashboards`, `setAllGroups`, `setConfiguredGroups`)
- [x] 1.3 Implement `apply(): void` that validates required keys per page and throws `MissingInitialStateException` (new class under `lib/Exception/`) naming the missing key
- [x] 1.4 Add `INITIAL_STATE_SCHEMA_VERSION = 1` constant; push it under key `_schemaVersion` in `apply()`
- [x] 1.5 Document the contract in the class docblock with link to REQ-INIT-002

## 2. Backend — Controller refactor

- [x] 2.1 Refactor `lib/Controller/PageController::index` to construct `InitialStateBuilder(Page::WORKSPACE)`, call all setters, then `apply()` — note: codebase has `PageController.php`, not `WorkspaceController.php`
- [x] 2.2 Refactor `lib/Settings/MyDashAdmin::getForm` to construct `InitialStateBuilder(Page::ADMIN)`, call all setters, then `apply()` — note: codebase has `Settings/MyDashAdmin.php`, not `Settings/Admin/AdminSettings.php`
- [x] 2.3 Add a CI lint task (`composer lint:initial-state` → `scripts/lint-initial-state.php`) that greps `->provideInitialState(` outside `lib/Service/InitialStateBuilder.php` and fails the build if found; wired into `composer check:strict`

## 3. Frontend — JS reader

- [x] 3.1 Create `src/utils/loadInitialState.js` exporting `loadInitialState(page)`; declare per-page key/default tables that mirror REQ-INIT-002
- [x] 3.2 Add `INITIAL_STATE_SCHEMA_VERSION = 1` constant in the reader (must equal the PHP value); compare against received `_schemaVersion` and emit a console warning on mismatch
- [x] 3.3 Refactor `src/main.js` (workspace entry) to call `loadInitialState('workspace')` and `provide(key, value)` for every key — Vue 2.7 root `provide` option used (no `app.provide()` in Vue 2)
- [x] 3.4 Refactor `src/admin.js` (admin entry) to call `loadInitialState('admin')` and `provide(key, value)` for every key — Vue 2.7 root `provide` option used
- [x] 3.5 Add a CI lint task (`npm run lint:initial-state` → `scripts/lint-initial-state.js`) that greps `loadState\(['"]mydash['"]` outside `src/utils/loadInitialState.js` and fails the build if found

## 4. Tests

- [x] 4.1 PHPUnit: builder rejects missing required keys for each page (one test per page)
- [x] 4.2 PHPUnit: builder writes all keys with correct values via a stub `IInitialState`
- [x] 4.3 PHPUnit: schema version key `_schemaVersion` is always pushed (workspace + admin)
- [x] 4.4 Vitest: reader fills defaults for missing keys (mock `loadState`)
- [x] 4.5 Vitest: reader logs warning on schema version mismatch
- [x] 4.6 Vitest: provide/inject pipe-through works for every workspace key
- [x] 4.7 Vitest: mutating a component clone of an injected value does not affect siblings (REQ-INIT-005)
- [x] 4.8 CI lint pair (PHP grep + JS grep) — wired into `composer check:strict` (PHP) and runnable as `npm run lint:initial-state` (JS); add a step to `.github/workflows/code-quality.yml` if/when MyDash adopts a non-shared workflow (current setup uses the shared ConductionNL workflow which already runs `composer check:strict`)

## 5. Quality

- [x] 5.1 `composer check:strict` passes (PHPCS clean, PHPMD/Psalm/PHPStan errors all pre-existing in DashboardService/Notifier — none introduced by this change)
- [x] 5.2 ESLint clean (9 pre-existing JSDoc warnings in `services/widgetBridge.js` only)
- [x] 5.3 Class docblock on `InitialStateBuilder` links to REQ-INIT-002 and lists all keys for each page
- [x] 5.4 Changelog note added in same commit describing the contract and the add-a-key procedure (spec update + version bump + reader/builder update in same commit)
