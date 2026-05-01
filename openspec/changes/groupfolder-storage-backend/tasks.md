# Tasks — groupfolder-storage-backend

## 1. Core storage interface

- [ ] 1.1 Create `lib/Service/DashboardContentStorage/DashboardContentStorageInterface.php` with methods: `read(string $dashboardUuid): array`, `write(string $dashboardUuid, array $content): void`, `delete(string $dashboardUuid): void`, `exists(string $dashboardUuid): bool`
- [ ] 1.2 Each method throws `DashboardContentStorageException` on I/O failure with a descriptive message
- [ ] 1.3 The `read()` method returns the dashboard content object (widgets array, layout metadata, etc.) as a parsed PHP array; if file does not exist, throw `DashboardNotFoundException` (extend `DashboardContentStorageException`)
- [ ] 1.4 The `write()` method accepts the content array and persists it; it MUST be idempotent (overwriting an existing file is safe)
- [ ] 1.5 All methods include PHPDoc with type hints and exception docs

## 2. Database storage implementation

- [ ] 2.1 Create `lib/Service/DashboardContentStorage/DbContentStorage.php` implementing `DashboardContentStorageInterface`
- [ ] 2.2 Inject `DashboardMapper` and `DashboardFactory` in the constructor
- [ ] 2.3 `read()` method: fetch the dashboard entity via mapper, extract the content field (deserialised JSON), return as array; throw `DashboardNotFoundException` if mapper throws DoesNotExistException
- [ ] 2.4 `write()` method: fetch the existing dashboard, update its content field, call `mapper->update()`
- [ ] 2.5 `delete()` method: fetch dashboard, set content to `null` or empty array, call `mapper->update()`
- [ ] 2.6 `exists()` method: attempt to fetch via mapper, return boolean (no exception thrown if not found)
- [ ] 2.7 Add PHPUnit tests for DbContentStorage: read existing, read non-existent, write, delete, exists checks

## 3. GroupFolder storage implementation

- [ ] 3.1 Create `lib/Service/DashboardContentStorage/GroupFolderContentStorage.php` implementing `DashboardContentStorageInterface`
- [ ] 3.2 Inject `IRootFolder`, `IGroupManager`, `IAppManager` (to check if `groupfolders` is installed), `ILogger` in the constructor
- [ ] 3.3 Constructor MUST validate that the `groupfolders` app is installed; if not, throw `GroupFoldersNotInstalledException` (extend `DashboardContentStorageException`)
- [ ] 3.4 Add private method `ensureMyDashGroupFolder(): int` — creates or fetches the "MyDash" GroupFolder, returns its folder ID; if creation fails, log warning and throw exception
- [ ] 3.5 Add private method `resolvePath(string $dashboardUuid, ?string $locale = null): string` returning `MyDash/<locale-or-empty>/<uuid>.json` (e.g., `MyDash/nl/abc123.json` or `MyDash/abc123.json` if no locale)
- [ ] 3.6 `read()` method: call `ensureMyDashGroupFolder()`, navigate via `IRootFolder` to the resolved file path, read and `json_decode()` the content, return array; throw `DashboardNotFoundException` if file not found
- [ ] 3.7 `write()` method: call `ensureMyDashGroupFolder()`, create parent directories if needed, write `json_encode($content)` to the resolved path, return (no exception on overwrite)
- [ ] 3.8 `delete()` method: call `ensureMyDashGroupFolder()`, delete the file at the resolved path; do not throw if file does not exist (idempotent)
- [ ] 3.9 `exists()` method: attempt to open file at resolved path, return boolean (no exception)
- [ ] 3.10 All I/O errors (permissions, disk full, etc.) MUST be caught and wrapped in `DashboardContentStorageException` with HTTP 503 status hint
- [ ] 3.11 Add PHPUnit tests for GroupFolderContentStorage: read existing, read non-existent, write, delete, exists; test with and without locale; test groupfolder creation

## 4. Storage factory and dependency injection

- [ ] 4.1 Create `lib/Service/DashboardContentStorage/DashboardContentStorageFactory.php` with method `getStorage(): DashboardContentStorageInterface`
- [ ] 4.2 Inject `IConfig` (for admin settings) and both implementations in the constructor
- [ ] 4.3 `getStorage()` method reads the `mydash.content_storage` admin setting; returns `DbContentStorage` if `db` (or unset), `GroupFolderContentStorage` if `groupfolder`
- [ ] 4.4 Add PHPUnit test for factory: verify correct implementation returned based on setting value

## 5. Domain model updates

- [ ] 5.1 Update `lib/Db/Dashboard.php` entity — keep the `content` field (column) for backward compatibility, but document that it may be unused if GroupFolder backend is active
- [ ] 5.2 Add optional GUID parameter `locale` to the entity for multi-language support; default to empty string
- [ ] 5.3 Update `jsonSerialize()` to always include the `content` field in the response (the storage layer reads it; the API client sees it regardless of backend)
- [ ] 5.4 No database schema changes needed for the entity itself

## 6. Service layer integration

- [ ] 6.1 Update `lib/Service/DashboardService.php` — inject `DashboardContentStorageFactory` in the constructor
- [ ] 6.2 Refactor `getDashboard($uuid)` to: call `dashboardMapper->findByUuid()` to get the entity, then call `getStorage()->read($uuid)` to fetch content, merge into response object
- [ ] 6.3 Refactor `createDashboard()` to: create entity via factory, call `getStorage()->write()` with the initial widget tree, then persist the entity
- [ ] 6.4 Refactor `updateDashboard($uuid, $patch)` to: fetch entity, call `getStorage()->write()` with the merged content, update entity metadata (name, description) via mapper
- [ ] 6.5 Refactor `deleteDashboard($uuid)` to: call `getStorage()->delete($uuid)` first, then delete the entity via mapper
- [ ] 6.6 Catch `DashboardContentStorageException` in all methods and re-throw as `\Exception` with a user-friendly message; include the underlying error in logs
- [ ] 6.7 Add PHPUnit tests for DashboardService integration: verify it calls the correct storage backend based on factory output

## 7. Migration command

- [ ] 7.1 Create `lib/Command/MigrateStorageToGroupFolder.php` extending `Command`
- [ ] 7.2 Register in `appinfo/info.xml` or bootstrap (depending on app structure) as a console command
- [ ] 7.3 Command MUST:
  - [ ] 7.3.1 Query all dashboards from DB via `DashboardMapper->findAll()`
  - [ ] 7.3.2 For each dashboard: read its content from DB, write to GroupFolder via `GroupFolderContentStorage`, delete from DB (optional; see 7.4 below)
  - [ ] 7.3.3 Skip dashboards already in GroupFolder (check via `exists()` on GroupFolder storage)
  - [ ] 7.3.4 Log progress and any errors
  - [ ] 7.3.5 Return exit code 0 on success, non-zero on error
- [ ] 7.4 Decide on retention: EITHER delete DB content after migration (recommended for cleanup) OR leave it in place for safety. Document the choice.
- [ ] 7.5 Make the command idempotent — re-running it MUST not cause errors or data loss
- [ ] 7.6 Add output options (verbose, quiet) for automation-friendly logging
- [ ] 7.7 PHPUnit test: mock both storages, verify migration copies all records and skips duplicates

## 8. Error handling and failover

- [ ] 8.1 Create exception hierarchy: `DashboardContentStorageException` (base), `DashboardNotFoundException`, `GroupFoldersNotInstalledException` extending it
- [ ] 8.2 In the controller (DashboardController), catch `DashboardContentStorageException` and return HTTP 503 with a JSON error body: `{"error": "dashboard_content_storage_unavailable", "message": "The dashboard content store is unreachable. Please contact your administrator."}`
- [ ] 8.3 Log all storage exceptions at WARN level with full context (dashboard UUID, storage type, underlying error)
- [ ] 8.4 NEVER silently fall back from GroupFolder to DB — fail closed with an explicit error
- [ ] 8.5 PHPUnit test: mock storage to throw exception, verify controller returns 503

## 9. Admin settings integration

- [ ] 9.1 Update `lib/Db/AdminSetting.php` (or equivalent admin settings entity) to include `mydash.content_storage` as a recognized setting key
- [ ] 9.2 Add to `admin-settings` spec: `mydash.content_storage` with type `string`, enum values `["db", "groupfolder"]`, default `"db"`
- [ ] 9.3 Update the admin settings controller to include this setting in GET/POST responses
- [ ] 9.4 Add validation: POST to the admin settings endpoint with invalid `mydash.content_storage` value returns HTTP 400
- [ ] 9.5 PHPUnit test: retrieve, update, and validate the setting

## 10. GroupFolder ACL and auto-creation

- [ ] 10.1 When the GroupFolder is first created, set ACL rules:
  - [ ] 10.1.1 Administrators: full access (read, write, delete)
  - [ ] 10.1.2 All other users: no default access (ACL is restrictive by default)
  - [ ] 10.1.3 Dashboard access is mediated by the API layer (the storage layer does not enforce per-dashboard ACL — that is the responsibility of the dashboard permission layer)
- [ ] 10.2 Document the GroupFolder creation process in a README or inline comment so operators understand the structure
- [ ] 10.3 PHPUnit test: verify GroupFolder creation includes correct ACL rules

## 11. CLI helper command (optional)

- [ ] 11.1 Create `lib/Command/ToggleStorageSetting.php` to allow admins to change the setting via CLI
- [ ] 11.2 Command signature: `mydash:storage:toggle-backend {db|groupfolder}`
- [ ] 11.3 Validate the argument, update the setting, confirm to the user
- [ ] 11.4 Output a warning if switching away from `groupfolder`: "Note: dashboards already in the GroupFolder will not be automatically copied back to the DB"

## 12. Quality gates and testing

- [ ] 12.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 12.2 All new PHP files include SPDX headers inside the docblock (per SPDX-in-docblock convention)
- [ ] 12.3 PHPUnit test coverage: aim for 85%+ on the storage layer (interface, implementations, factory)
- [ ] 12.4 E2E test (Playwright): create a dashboard via API, verify it appears in GroupFolder when backend is `groupfolder`; switch backend to `db` and verify fallback read still works
- [ ] 12.5 Integration test: run migration command, verify all dashboards are copied and the API still reads them correctly
- [ ] 12.6 i18n: all error messages and CLI output in both `nl` and `en` (error keys: `dashboard_content_storage_unavailable`, `groupfolder_not_installed`, etc.)
- [ ] 12.7 Update OpenAPI spec / Postman collection if it documents error responses

## 13. Documentation

- [ ] 13.1 Add a "Storage Backend" section to the MyDash admin documentation explaining the two backends, when to use each, and the migration process
- [ ] 13.2 Include example GroupFolder structure and ACL rules
- [ ] 13.3 Document the CLI commands and their options
- [ ] 13.4 Add a changelog entry noting the new capability, the default behaviour (unchanged), and the opt-in migration path
