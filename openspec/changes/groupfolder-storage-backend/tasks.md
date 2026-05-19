# Tasks — groupfolder-storage-backend

## Tasks

- [ ] Task 1: Define `lib/Service/DashboardContentStorage/DashboardContentStorageInterface.php` with `read`, `write`, `delete`, `exists` methods and supporting exception hierarchy (`DashboardContentStorageException`, `DashboardNotFoundException`, `GroupFoldersNotInstalledException`)
- [ ] Task 2: Implement `DbContentStorage` against the existing `DashboardMapper` (reads/writes the entity `content` field, idempotent `write`, soft `delete`)
- [ ] Task 3: Implement `GroupFolderContentStorage` against `IRootFolder`/`IGroupManager`/`IAppManager` with `ensureMyDashGroupFolder()` bootstrap, `resolvePath()` (`MyDash/<locale>/<uuid>.json`), and 503-wrapping for all I/O failures
- [ ] Task 4: Implement `DashboardContentStorageFactory::getStorage()` reading the `mydash.content_storage` admin setting (`db` default, `groupfolder` opt-in)
- [ ] Task 5: Wire `DashboardContentStorageFactory` into `DashboardService` so `get/create/update/delete` route through the active backend; catch storage exceptions and rethrow with user-friendly messages
- [ ] Task 6: Update `lib/Db/Dashboard.php` to add the optional `locale` property and document the now-optional `content` column; keep `jsonSerialize()` returning `content`
- [ ] Task 7: Register `mydash.content_storage` as an admin setting (enum `db|groupfolder`, default `db`) with GET/POST validation returning 400 on invalid values
- [ ] Task 8: Set restrictive ACL on the auto-created `MyDash` GroupFolder (admins full, all others denied; per-dashboard ACL stays in the API layer) and document the layout in code comments
- [ ] Task 9: Ship `lib/Command/MigrateStorageToGroupFolder` (idempotent DB→GroupFolder copy, skips entries already in GroupFolder, verbose/quiet options) registered via bootstrap
- [ ] Task 10: Ship `lib/Command/ToggleStorageSetting` (`mydash:storage:toggle-backend {db|groupfolder}`) with a warning when switching back to `db` about not auto-copying GroupFolder data
- [ ] Task 11: Controller layer catches `DashboardContentStorageException` and returns HTTP 503 with `{"error":"dashboard_content_storage_unavailable", ...}`; never silently falls back to DB
- [ ] Task 12: PHPUnit coverage across the new storage layer (interface, both implementations, factory, service integration, migration command, controller failure-path)
- [ ] Task 13: Playwright + integration coverage — create dashboard via API on `groupfolder` backend (file appears in GroupFolder), then run migration command and confirm reads still resolve via the API
- [ ] Task 14: Quality gates — `composer check:strict`, SPDX headers in-docblock on new PHP files, `nl`+`en` i18n for new error/CLI strings, OpenAPI/Postman regen if error responses are documented

## Verification

`openspec validate` exits clean. Storage layer hits ≥85% line coverage via PHPUnit; Playwright migration scenario passes on the local dev container.

## Tests (company-wide ADR-009)

PHPUnit per Task 12; Playwright per Task 13. No new REST endpoints (storage is internal); existing dashboard CRUD endpoints get failure-path coverage via Task 11.

## Documentation (company-wide ADR-010)

Add the "Storage Backend" admin guide section (db vs groupfolder, ACL, migration workflow, CLI commands) and a changelog entry noting the opt-in nature.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for the new admin-facing strings: `dashboard_content_storage_unavailable`, `groupfolder_not_installed`, CLI output for the migration + toggle commands.
