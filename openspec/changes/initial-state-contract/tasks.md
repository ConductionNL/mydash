# Tasks — initial-state-contract

## 1. Backend — InitialStateBuilder service

- [ ] 1.1 Create `lib/Service/InitialStateBuilder.php` with a `Page` enum (`WORKSPACE`, `ADMIN`) and a constructor accepting `IInitialState` + `Page`
- [ ] 1.2 Add typed setter methods per page key (`setWidgets`, `setLayout`, `setPrimaryGroup`, `setPrimaryGroupName`, `setIsAdmin`, `setActiveDashboardId`, `setDashboardSource`, `setGroupDashboards`, `setUserDashboards`, `setAllowUserDashboards`, `setAllGroups`, `setConfiguredGroups`)
- [ ] 1.3 Implement `apply(): void` that validates required keys per page and throws `MissingInitialStateException` (new class under `lib/Exception/`) naming the missing key
- [ ] 1.4 Add `INITIAL_STATE_SCHEMA_VERSION = 1` constant; push it under key `_schemaVersion` in `apply()`
- [ ] 1.5 Document the contract in the class docblock with link to REQ-INIT-002

## 2. Backend — Controller refactor

- [ ] 2.1 Refactor `lib/Controller/WorkspaceController::index` to construct `InitialStateBuilder(Page::WORKSPACE)`, call all setters, then `apply()`
- [ ] 2.2 Refactor `lib/Settings/Admin/AdminSettings::getForm` to construct `InitialStateBuilder(Page::ADMIN)`, call all setters, then `apply()`
- [ ] 2.3 Add a CI lint task (shell or PHPUnit) that greps `provideInitialState` outside `lib/Service/InitialStateBuilder.php` and fails the build if found

## 3. Frontend — JS reader

- [ ] 3.1 Create `src/utils/loadInitialState.js` exporting `loadInitialState(page)`; declare per-page key/default tables that mirror REQ-INIT-002
- [ ] 3.2 Add `INITIAL_STATE_SCHEMA_VERSION = 1` constant in the reader (must equal the PHP value); compare against received `_schemaVersion` and emit a console warning on mismatch
- [ ] 3.3 Refactor `src/main.js` (workspace entry) to call `loadInitialState('workspace')` and `app.provide(key, value)` for every key
- [ ] 3.4 Refactor `src/admin.js` (admin entry) to call `loadInitialState('admin')` and `app.provide(key, value)` for every key
- [ ] 3.5 Add a CI lint task (shell or Vitest) that greps `loadState\(['"]mydash['"]` outside `src/utils/loadInitialState.js` and fails the build if found

## 4. Tests

- [ ] 4.1 PHPUnit: builder rejects missing required keys for each page (one test per page)
- [ ] 4.2 PHPUnit: builder writes all keys with correct values via a stub `IInitialState`
- [ ] 4.3 PHPUnit: schema version key `_schemaVersion` is always pushed
- [ ] 4.4 Vitest: reader fills defaults for missing keys (mock `loadState`)
- [ ] 4.5 Vitest: reader logs warning on schema version mismatch
- [ ] 4.6 Vitest: provide/inject pipe-through works for every workspace key
- [ ] 4.7 Vitest: mutating a component clone of an injected value does not affect siblings (REQ-INIT-005)
- [ ] 4.8 CI lint pair (PHP grep + JS grep) wired into the workflow

## 5. Quality

- [ ] 5.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 5.2 ESLint clean
- [ ] 5.3 Class docblock on `InitialStateBuilder` links to REQ-INIT-002 and lists all keys for each page
- [ ] 5.4 Add a changelog note describing the new contract and how to add a key (spec update + version bump + reader/builder update in same commit)
