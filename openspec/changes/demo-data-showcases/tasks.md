# Tasks — Demo Data Showcases

## Setup & Validation

- [ ] Task 1: Create `showcases/` directory structure under the app root with subdirectories for each showcase ID (`de-bron/`, `de-linden/`, `gemeente-duin/`, `horizon-labs/`, `van-der-berg/`)
- [ ] Task 2: Create `export.json` manifest files for each of the 5 showcases with required fields: `exportVersion`, `schemaVersion`, `requiresMinVersion` (set to current app version), `language: 'nl'`, `pages[]` with `_exportPath`, `uniqueId`, `title`, `content`, `navigation` object, `footer` object, `comments` array (can be empty for v1)
- [ ] Task 3: Create `nl/` subdirectories within each showcase and add `home.json` with page content (layout, rows, widgets) per the data model spec; include `navigation.json` with megamenu structure; add `footer.json` with footer content
- [ ] Task 4: Create `nl/_media/` directories and add 5–10 representative JPEG images per showcase (team photo for de-bron, campus for de-linden, city hall for gemeente-duin, startup office for horizon-labs, law office for van-der-berg); optimize images to ~10–20 KB each
- [ ] Task 5: ZIP each showcase directory: `php -r '$z = new ZipArchive(); $z->open("showcases/{id}/{id}.zip", ZipArchive::CREATE); /* recursively add export.json, nl/ tree */; $z->close();'` or equivalent; verify ZIP structure with `unzip -t`

## Backend Service Layer

- [ ] Task 6: Build `lib/Service/DemoShowcaseService.php` with:
  - `listShowcases(): array` — scans `showcases/` directory, validates each `export.json`, returns array of showcase metadata (id, name, description, language, isInstalled boolean, installedDashboardUuid if installed)
  - `getShowcase(string $id): array` — extracts and returns `export.json` content for a specific showcase
  - `validateShowcaseVersion(string $requiresMinVersion): bool` — checks if current app version >= requiresMinVersion; returns false with logged warning if not
  - `installShowcase(string $id, string $lang = 'nl'): array` — orchestrates install: extracts ZIP, resolves locale, validates widgets, creates dashboard, extracts media, returns `{installedDashboardUuid, skippedWidgets[]}`
  - `uninstallShowcase(string $id): void` — soft-deletes the installed dashboard and cascades to widget placements
  - `findInstalledShowcase(string $id): ?array` — queries dashboards with `metadata.showcaseId == $id` and `type = 'group_shared'` and `groupId = 'default'`; returns first match or null
  - Helper: `extractShowcaseZip(string $id, string $tempDir): array` — extracts ZIP to temp dir, validates structure, returns manifest data
  - Helper: `validateWidgetTypes(array $widgets): {valid: [], skipped: []}` — checks each widget type against the registered registry; returns separate arrays
  - Helper: `extractMediaAssets(string $showcaseId, string $sourceDir, string $destDir): void` — copies all `_media/` files to persistent location
  - Helper: `rewriteWidgetMediaPaths(array $widgets, string $showcaseId): array` — rewrites `image.src` and `file.path` fields to point to extracted media location
  - All methods log actions via `ILogger` (showcase ID, operation, status, admin user ID)

- [ ] Task 7: Build `lib/Mapper/DashboardMapper.php` extensions (or update existing if present):
  - Add `findByShowcaseId(string $showcaseId): array` — queries `oc_mydash_dashboards` where `metadata` JSON path `$.showcaseId == ?` and `type = 'group_shared'` and `groupId = 'default'`
  - Ensure metadata JSON field is read/write in the Entity

## REST Controller Layer

- [ ] Task 8: Build `lib/Controller/DemoShowcaseController.php` with:
  - `GET /api/admin/demo-showcases` → `listShowcases()` endpoint
    - Requires admin role (Nextcloud `IGroupManager::isAdmin()`)
    - Returns HTTP 403 for non-admin
    - Returns HTTP 200 with JSON array: `[{id, name, description, thumbnailUrl, language, isInstalled, installedDashboardUuid?}]`
  - `POST /api/admin/demo-showcases/{id}/install` → `installShowcase($id)` endpoint
    - Requires admin role
    - Accepts optional `?lang=` query parameter (ignored in v1, resolved to `nl`)
    - Returns HTTP 201 on success: `{installedDashboardUuid, skippedWidgets[]}`
    - Returns HTTP 404 if showcase not found
    - Returns HTTP 422 if version check fails (with message "Requires app version X or later")
    - Returns HTTP 400 if validation fails
    - Returns HTTP 403 for non-admin
  - `DELETE /api/admin/demo-showcases/{id}` → `uninstallShowcase($id)` endpoint
    - Requires admin role
    - Returns HTTP 204 (idempotent — both installed and not-installed return 204)
    - Returns HTTP 403 for non-admin

## CLI Commands

- [ ] Task 9: Build `lib/Command/DemoShowcaseInstallCommand.php` extending `OCP\Console\Command`
  - Command name: `mydash:demo-showcases:install <showcase-id> [--lang=nl] [--force]`
  - Arguments: `showcase-id` (required)
  - Options: `--lang=nl` (optional, ignored in v1), `--force` (bypass idempotency, reinstall even if exists)
  - Output: "Installed dashboard {uuid}" on success, list of skipped widgets if any
  - Output: error message and exit code 1 if showcase not found or validation fails
  - Validates admin role via `IGroupManager::isAdmin()` or skips if run from CLI (web/cron context)

- [ ] Task 10: Build `lib/Command/DemoShowcaseListCommand.php` extending `OCP\Console\Command`
  - Command name: `mydash:demo-showcases:list`
  - Output: table with columns `ID`, `Name`, `Language`, `Status` (Installed / Not installed)
  - Uses `DemoShowcaseService::listShowcases()` to populate rows
  - No arguments or options

- [ ] Task 11: Register both CLI commands in `appinfo/info.xml`:
  ```xml
  <commands>
    <command>OCA\MyDash\Command\DemoShowcaseInstallCommand</command>
    <command>OCA\MyDash\Command\DemoShowcaseListCommand</command>
  </commands>
  ```

## Routes & DI

- [ ] Task 12: Register 3 new REST routes in `appinfo/routes.php`:
  ```php
  ['name' => 'demo_showcase#list', 'url' => '/api/admin/demo-showcases', 'verb' => 'GET'],
  ['name' => 'demo_showcase#install', 'url' => '/api/admin/demo-showcases/{id}/install', 'verb' => 'POST'],
  ['name' => 'demo_showcase#uninstall', 'url' => '/api/admin/demo-showcases/{id}', 'verb' => 'DELETE'],
  ```
  All three routes are admin-only (enforced in controller via `IGroupManager::isAdmin()`)

- [ ] Task 13: Ensure `DemoShowcaseService` is registered in `lib/AppInfo/Application.php` with dependency injection (constructor inject `ILogger`, `DashboardService`, `DashboardMapper`, `IAppConfig`, filesystem context)

## Error Handling & Validation

- [ ] Task 14: Implement ZIP validation:
  - Check that ZIP file exists and is readable
  - Check that `export.json` is present and valid JSON
  - Check that required fields (`exportVersion`, `schemaVersion`, `language`, `pages`, `navigation`, `footer`) are present
  - Log and reject if validation fails; return error response with HTTP 400 or 422 as appropriate

- [ ] Task 15: Implement version checking:
  - Parse `export.json.requiresMinVersion` (e.g., "0.8.11")
  - Compare against current app version via `IAppConfig::getAppVersion()`
  - Return HTTP 422 with message "Showcase requires app version X or later; current version is Y" if mismatch
  - Log the version mismatch

- [ ] Task 16: Implement graceful widget-type skipping:
  - Iterate over all widgets in all pages
  - For each widget, check if `type` is registered in the widget registry
  - If unknown, add to `skippedWidgets[]` array and continue (do not throw)
  - Log: "Skipped widget type '{type}' in showcase '{id}' (widget not registered in app)"
  - Return `skippedWidgets[]` in the install response
  - Installation succeeds (HTTP 201) even if some widgets are skipped

## Media Handling

- [ ] Task 17: Create or configure a media storage location for extracted showcase assets (e.g., `data/showcases/` under app data root or a public assets subdirectory)
  - Ensure directory is writable and created on first install
  - Use a predictable path: `media/showcases/{showcase-id}/`

- [ ] Task 18: Implement media extraction and path rewriting:
  - Extract all files from `nl/_media/` in the ZIP to the media storage location
  - Iterate over all widgets in the installed dashboard
  - For `image` widgets: rewrite `src` field from `"zorgteam.jpg"` to full URL or filesystem path
  - For `file` widgets: rewrite `path` field similarly (if media is referenced)
  - Store the rewritten widget content in the dashboard record

## Dashboard Creation

- [ ] Task 19: Implement showcase-to-dashboard creation:
  - Extract page content from showcase JSON (from `export.json` or per-locale JSON)
  - Create a dashboard record with:
    - `type = 'group_shared'`
    - `groupId = 'default'`
    - `title` from showcase page title
    - `metadata.showcaseId = {showcase-id}` (required for idempotency)
    - `metadata.sourceLanguage = 'nl'` (v1 is NL-only)
  - Iterate over widgets and create widget placements via `DashboardService` or Widget mapper
  - Return the created dashboard UUID

## Testing

- [ ] Task 20: Unit tests for `DemoShowcaseService`:
  - Test `listShowcases()` returns correct count and fields (5 showcases)
  - Test `validateShowcaseVersion()` correctly compares versions (pass if current >= required, fail if older)
  - Test `installShowcase()` creates dashboard with correct metadata
  - Test idempotency: second install of same showcase returns same UUID, no duplicate created
  - Test `findInstalledShowcase()` queries correctly by showcase ID
  - Test `validateWidgetTypes()` correctly identifies unknown types and returns skipped array
  - Test media extraction creates files in correct location

- [ ] Task 21: Integration tests for REST endpoints:
  - Test `GET /api/admin/demo-showcases` returns 5 items with isInstalled flags
  - Test `GET /api/admin/demo-showcases` returns HTTP 403 for non-admin
  - Test `POST /api/admin/demo-showcases/de-bron/install` creates dashboard, returns HTTP 201
  - Test second install of same showcase returns HTTP 201 with same UUID (idempotency)
  - Test `POST /api/admin/demo-showcases/unknown/install` returns HTTP 404
  - Test version mismatch returns HTTP 422
  - Test `DELETE /api/admin/demo-showcases/de-bron` soft-deletes dashboard, returns HTTP 204
  - Test second delete of same showcase returns HTTP 204 (idempotency)
  - Test non-admin calls return HTTP 403

- [ ] Task 22: Integration tests for CLI commands:
  - Test `php occ mydash:demo-showcases:install de-bron` outputs "Installed dashboard {uuid}"
  - Test `php occ mydash:demo-showcases:install de-bron --force` reinstalls and outputs new UUID
  - Test `php occ mydash:demo-showcases:install de-bron` (no --force, already installed) outputs existing UUID
  - Test `php occ mydash:demo-showcases:list` outputs table with 5 rows, correct status
  - Test invalid showcase ID outputs error and exits with code 1

- [ ] Task 23: End-to-end (Playwright):
  - Login as admin
  - Call `GET /api/admin/demo-showcases` (e.g., via curl)
  - Verify 5 showcases listed, all with `isInstalled: false`
  - Call `POST /api/admin/demo-showcases/de-bron/install`
  - Verify HTTP 201, dashboard UUID returned
  - Call `GET /api/admin/demo-showcases` again
  - Verify de-bron now has `isInstalled: true` with matching UUID
  - As a non-admin user, verify the installed dashboard is visible in `GET /api/dashboards/visible`
  - Verify installed dashboard content renders correctly (widgets present, media loads, no broken links)

## Documentation & i18n

- [ ] Task 24: i18n — add keys to `l10n/en.json` and `l10n/nl.json`:
  - "Demo Showcases" (title)
  - "Install" (button)
  - "Uninstall" (button)
  - "Showcase not found"
  - "Requires app version {version} or later"
  - "Showcase installed successfully"
  - "Showcase uninstalled"
  - "Skipped unknown widget type: {type}"
  - Any user-facing error messages

- [ ] Task 25: Update `CHANGELOG.md` with entry describing the new feature, the 5 bundled showcases, and CLI/REST API endpoints

- [ ] Task 26: Add user-guide documentation or README section explaining:
  - What a showcase is
  - How to install showcases via REST API and CLI
  - Screenshot of installed showcase dashboard
  - Explanation that installed dashboards are fully editable

- [ ] Task 27: Update API documentation (OpenAPI spec if present) with 3 new endpoints: GET /api/admin/demo-showcases, POST /api/admin/demo-showcases/{id}/install, DELETE /api/admin/demo-showcases/{id}

## Quality & Verification

- [ ] Task 28: Quality gates — all new `.php` files pass linting: `php -l lib/Service/DemoShowcaseService.php`, `lib/Controller/DemoShowcaseController.php`, `lib/Command/*`, `lib/Mapper/*` extensions; ESLint/Stylelint on any frontend additions
- [ ] Task 29: Code coverage — all service methods have unit tests; all controller endpoints have integration tests; coverage threshold met (aim for 80%+ on new code)
- [ ] Task 30: Security validation:
  - ZIP extraction: validate file paths do not escape the extraction directory (no `../` traversal)
  - File permissions: extracted media files are readable by the web server, not world-writable
  - Admin-only enforcement: verify all mutation endpoints require admin role; use `hydra-gate-no-admin-idor` and `hydra-gate-semantic-auth` gates
  - No PII in logs: verify logs only include showcase ID, user ID (from `IUserSession`), action, status — never password, token, or user display name

- [ ] Task 31: Database & migration review:
  - No new tables required (existing `oc_mydash_dashboards.metadata` JSON field is used)
  - Verify `metadata` column is indexed or JSON path is optimized if needed
  - Confirm backward compatibility: existing dashboards without `showcaseId` in metadata remain unaffected

- [ ] Task 32: Bundle size audit:
  - Measure total size of `showcases/` directory (5 ZIPs + manifests + media)
  - Verify under 500 KB total
  - Document in release notes if significant

- [ ] Task 33: `openspec validate` exits clean (spec artifact validation)

## Deduplication Check

- [ ] Task 34: Verify no overlap with existing capabilities:
  - `dashboards` capability: uses existing `DashboardService` and mapper; no duplication
  - `widgets` capability: uses existing widget registry and validation; no duplication
  - `dashboard-public-share` capability: distinct concern (public read-only sharing); no shared code
  - Confirm no custom widget types or custom dashboard schema properties
  - Document reuse of existing services in design.md (completed in Task 6 design section)

## Post-Implementation

- [ ] Task 35: Create demo / walkthrough GIF or short video showing:
  - Admin calling `GET /api/admin/demo-showcases` (5 showcases listed)
  - Admin calling `POST /api/admin/demo-showcases/de-bron/install`
  - Dashboard appearing in user's visible dashboards
  - Dashboard rendering with all widgets and media

## Verification

`openspec validate` exits clean. All 5 showcases load without error. Install endpoint creates visible group-shared dashboard. Reinstall returns same UUID (idempotency). Unknown widget types are skipped and logged. CLI commands execute and output as documented. Non-admin requests return HTTP 403. Media assets extract and display correctly.

## Tests (company-wide ADR-003 backend, ADR-008 testing)

- Unit: `DemoShowcaseService` methods (version checking, widget validation, idempotency, media extraction)
- Integration: REST endpoints and CLI commands with real database
- End-to-end: Playwright browser test verifying install, visibility, and rendering

## Documentation (company-wide ADR-010)

- Changelog entry
- User-guide section on showcase installation and management
- API documentation (OpenAPI)

## i18n (company-wide ADR-007)

- `nl_NL` and `en_US` per Task 24
- All showcase names (de-bron, de-linden, gemeente-duin, horizon-labs, van-der-berg) remain in Dutch
