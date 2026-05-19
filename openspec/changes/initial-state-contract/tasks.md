# Tasks — initial-state-contract

## Tasks

- [ ] Task 1: Create `lib/Service/InitialStateBuilder.php` with a `Page` enum (`WORKSPACE`, `ADMIN`), constructor accepting `IInitialState` + `Page`, and typed setter methods per page key (`setWidgets`, `setLayout`, `setPrimaryGroup`, `setPrimaryGroupName`, `setIsAdmin`, `setActiveDashboardId`, `setDashboardSource`, `setGroupDashboards`, `setUserDashboards`, `setAllowUserDashboards`, `setAllGroups`, `setConfiguredGroups`)
- [ ] Task 2: Implement `apply(): void` that validates required keys per page and throws `MissingInitialStateException` (new class under `lib/Exception/`) naming the missing key
- [ ] Task 3: Add `INITIAL_STATE_SCHEMA_VERSION = 1` constant; push it under key `_schemaVersion` in `apply()`; document the contract in the class docblock with a link to REQ-INIT-002
- [ ] Task 4: Refactor `lib/Controller/WorkspaceController::index` to construct `InitialStateBuilder(Page::WORKSPACE)`, call all setters, then `apply()`
- [ ] Task 5: Refactor `lib/Settings/Admin/AdminSettings::getForm` to construct `InitialStateBuilder(Page::ADMIN)`, call all setters, then `apply()`
- [ ] Task 6: Add a CI lint task (shell or PHPUnit) that greps `provideInitialState` outside `lib/Service/InitialStateBuilder.php` and fails the build if found
- [ ] Task 7: Create `src/utils/loadInitialState.js` exporting `loadInitialState(page)` with per-page key/default tables mirroring REQ-INIT-002; include an `INITIAL_STATE_SCHEMA_VERSION = 1` constant that must equal the PHP value and emit a console warning when received `_schemaVersion` mismatches
- [ ] Task 8: Refactor `src/main.js` (workspace entry) to call `loadInitialState('workspace')` and `app.provide(key, value)` for every key
- [ ] Task 9: Refactor `src/admin.js` (admin entry) to call `loadInitialState('admin')` and `app.provide(key, value)` for every key
- [ ] Task 10: Add a CI lint task (shell or Vitest) that greps `loadState\(['"]mydash['"]` outside `src/utils/loadInitialState.js` and fails the build if found
- [ ] Task 11: PHPUnit — builder rejects missing required keys for each page; builder writes all keys with correct values via a stub `IInitialState`; `_schemaVersion` key is always pushed
- [ ] Task 12: Vitest — reader fills defaults for missing keys (mock `loadState`); reader logs a warning on schema-version mismatch; provide/inject pipe-through works for every workspace key; mutating a component clone of an injected value does not affect siblings (REQ-INIT-005)
- [ ] Task 13: Wire the CI lint pair (PHP grep + JS grep) into the workflow
- [ ] Task 14: Quality — `composer check:strict` passes; ESLint clean; class docblock on `InitialStateBuilder` links REQ-INIT-002 and lists all keys for each page; changelog note describing the new contract and how to add a key (spec update + version bump + reader/builder update in the same commit)

## Verification

`openspec validate` exits clean. Both lint guards fail loudly on stray `provideInitialState` / `loadState('mydash')` calls outside the canonical files.

## Tests (company-wide ADR-009)

PHPUnit per Task 11; Vitest per Task 12. No new endpoint surface.

## Documentation (company-wide ADR-010)

Changelog entry per Task 14 plus the inline docblock contract.

## i18n (company-wide ADR-005)

No user-facing strings added — initial-state plumbing is contract-only.
