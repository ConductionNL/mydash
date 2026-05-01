# Tasks — demo-data-showcases

## 1. Showcase Data

- [ ] Create `appdata/demo/marketing-en.json` with schema:
  ```json
  {
    "id": "marketing",
    "name": "Marketing Overview",
    "description": "Dashboard for marketing campaigns, leads, and ROI tracking",
    "language": "en",
    "showcaseTag": "marketing",
    "gridColumns": 12,
    "metadataFields": [
      {"name": "campaignId", "label": "Campaign ID", "type": "text"},
      {"name": "roi", "label": "ROI %", "type": "number"}
    ],
    "widgets": [
      {"type": "welcome", "position": {"x": 0, "y": 0, "w": 12, "h": 2}, "config": {"title": "Welcome to Marketing Dashboard"}},
      {"type": "calendar", "position": {"x": 0, "y": 2, "w": 6, "h": 4}, "config": {"showWeekends": true}},
      {"type": "recent-activity", "position": {"x": 6, "y": 2, "w": 6, "h": 4}, "config": {"limit": 20}}
    ]
  }
  ```
- [ ] Create `appdata/demo/marketing-nl.json` (Dutch localization of above)
- [ ] Create `appdata/demo/engineering-en.json` with engineering-focused widgets (architecture docs, sprint tracking, code deployments)
- [ ] Create `appdata/demo/engineering-nl.json` (Dutch localization)
- [ ] Create `appdata/demo/all-staff-en.json` with company-wide widgets (news, birthdays, org chart)
- [ ] Create `appdata/demo/all-staff-nl.json` (Dutch localization)
- [ ] Create `appdata/demo/project-en.json` with project management widgets (tasks, timeline, team members)
- [ ] Create `appdata/demo/project-nl.json` (Dutch localization)
- [ ] Create `appdata/demo/community-en.json` with community engagement widgets (discussions, events, member directory)
- [ ] Create `appdata/demo/community-nl.json` (Dutch localization)
- [ ] Create `appdata/demo/marketing-thumbnail.png` (300×200 px PNG preview image)
- [ ] Create `appdata/demo/engineering-thumbnail.png`, `all-staff-thumbnail.png`, `project-thumbnail.png`, `community-thumbnail.png`

## 2. Backend

- [ ] Create `lib/Service/DemoShowcasesService.php` with methods:
  - `getAvailableShowcases(): array` — reads `appdata/demo/*-en.json` files, returns `[{id, name, description, language}]`
  - `loadShowcase(string $id, string $lang = 'en'): array` — loads showcase JSON from disk, falls back to default language if localized version missing
  - `getShowcaseWithStatus(string $id, string $lang = 'en'): array` — augments showcase with `isInstalled: bool` and `installedDashboardUuid|null`
  - `installShowcase(string $id, string $lang = 'en'): array` — creates group_shared dashboard with `groupId = 'default'`, stores showcase ID in metadata, validates widget types, skips unknown widgets
  - `uninstallShowcase(string $id): void` — finds installed dashboard by showcase ID, soft-deletes it (cascade delete widget placements); idempotent
  - `findInstalledShowcaseDashboard(string $showcaseId): ?Dashboard` — query for existing installation
  - `validateWidgetTypes(array $widgets): array` — cross-reference each widget type against registered widget registry; return `['valid' => [...], 'skipped' => [...]]`
  - `getThumbnailUrl(string $id): string` — returns URL to PNG thumbnail
- [ ] Create `lib/Controller/AdminDemoShowcasesController.php` with routes:
  - `GET /api/admin/demo-showcases` → calls `getAvailableShowcases()`, returns `[{id, name, description, thumbnailUrl, language, isInstalled}]`
  - `POST /api/admin/demo-showcases/{id}/install?lang=en|nl` → calls `installShowcase($id, $lang)`, returns `{installedDashboardUuid, skippedWidgets: []|[...]}` HTTP 201
  - `DELETE /api/admin/demo-showcases/{id}` → calls `uninstallShowcase($id)`, returns HTTP 204 (idempotent)
- [ ] Require admin role for all three endpoints (check via `IAppManager` or `IUserSession`)
- [ ] Define typed exceptions: `ShowcaseNotFoundException`, `InvalidWidgetTypeException`, `InvalidLanguageException`
- [ ] Inject `DashboardService`, `WidgetService`, `WidgetRegistry`, `ILogger` into controller

## 3. Showcase Installation Logic

- [ ] Store showcase metadata in installed dashboard:
  - Dashboard `title` = showcase name + language suffix (e.g., "Marketing Overview")
  - Dashboard metadata/tags: add `"showcaseId": "marketing"` and `"sourceLanguage": "en"` to preserve source
- [ ] Idempotency: check if dashboard with matching `showcaseId` in metadata already exists for this user/group; if yes, return existing UUID
- [ ] Widget skip mechanism: iterate showcase's `widgets` array, validate each `type` against registry, skip if not found, record in response `skippedWidgets`
- [ ] Create widget placements for valid widgets with positions and config from JSON
- [ ] Return response: `{installedDashboardUuid: "...", skippedWidgets: ["unknown-type1", "unknown-type2"]}`
- [ ] Log each installation and any skipped widgets to `ILogger`

## 4. Frontend

- [ ] Create `src/components/AdminDemoData.vue` Vue 3 SFC:
  - Fetch `GET /api/admin/demo-showcases` on mount
  - Display grid of showcase cards, each with:
    - Thumbnail image
    - Title
    - Description
    - Language badge (EN/NL)
    - "Install" button (if `!isInstalled`) or "Uninstall" button (if `isInstalled`)
  - Click "Install" → `POST /api/admin/demo-showcases/{id}/install?lang={currentUserLang}` → show success toast + refresh list
  - Show warning if any widgets were skipped: "Installed but skipped widgets: [...]"
  - Click "Uninstall" → `DELETE /api/admin/demo-showcases/{id}` → confirm dialog → remove from list
  - Handle 404, 403, server errors with user-friendly messages
  - Disable buttons during loading
- [ ] Add "Demo data" tab to admin settings page (alongside existing tabs like "Settings", "Templates")
- [ ] Pagination or lazy load if > 10 showcases (future-proof)

## 5. CLI Commands

- [ ] Create `lib/Command/DemoShowcasesInstallCommand.php` extending `OCP\Console\Command`:
  - `php occ mydash:demo-showcases:install <id>` — calls `DemoShowcasesService::installShowcase(id)`, outputs "Installed dashboard {uuid}" or error
  - Accept optional `--lang=en|nl` flag (default user's locale or 'en')
  - Output installed dashboard UUID on success
- [ ] Create `lib/Command/DemoShowcasesListCommand.php`:
  - `php occ mydash:demo-showcases:list` — calls `getAvailableShowcases()`, outputs table: `ID | Name | Status (Installed/Not installed) | Language`
  - Support `--json` flag for machine parsing

## 6. Tests

- [ ] PHPUnit: `DemoShowcasesService`:
  - `getAvailableShowcases()` returns all showcase IDs from disk
  - `loadShowcase()` reads JSON correctly, falls back to default language
  - `getShowcaseWithStatus()` includes `isInstalled` flag
  - `installShowcase()` creates group_shared dashboard with `groupId = 'default'`
  - `installShowcase()` skips unknown widget types, returns `skippedWidgets` array
  - `installShowcase()` is idempotent: reinstalling returns same UUID
  - `uninstallShowcase()` soft-deletes dashboard and cascades to widgets; idempotent (no error on second call)
  - `findInstalledShowcaseDashboard()` returns null if not installed
  - `validateWidgetTypes()` correctly identifies valid vs. invalid widget types

- [ ] PHPUnit: `AdminDemoShowcasesController`:
  - `GET /api/admin/demo-showcases` returns HTTP 200 with list of showcases
  - `POST /api/admin/demo-showcases/{id}/install` returns HTTP 201 with `installedDashboardUuid` and `skippedWidgets`
  - `POST /api/admin/demo-showcases/{invalid-id}/install` returns HTTP 404
  - `DELETE /api/admin/demo-showcases/{id}` returns HTTP 204
  - `DELETE /api/admin/demo-showcases/{id}` called twice is idempotent (204 both times)
  - Non-admin user gets HTTP 403 on POST/DELETE
  - `GET /api/admin/demo-showcases` accessible to admin only (HTTP 403 for non-admin)

- [ ] PHPUnit: Showcase JSON schema validation
  - Valid showcase JSON loads without errors
  - Invalid showcase JSON (missing required fields) logs error and is skipped

- [ ] Playwright: Admin UI
  - "Demo data" tab visible in admin settings
  - Showcase list displays all 5 cards with thumbnails + names
  - Click "Install" button → loading spinner → success toast → button changes to "Uninstall"
  - Skip warning shown if widgets are skipped
  - Click "Uninstall" button → confirm dialog → button changes to "Install"
  - Error message displayed if install fails (e.g., server error)

## 7. Quality

- [ ] `composer check:strict` passes
- [ ] OpenAPI schema updated for three new endpoints
- [ ] Translation entries for all user-facing strings:
  - Showcase names and descriptions in both EN and NL (from JSON files)
  - Admin UI button labels ("Install", "Uninstall", "Demo data")
  - Toast messages ("Successfully installed", "Skipped widgets: ...")
  - Error messages
- [ ] Showcase JSON files validate against JSON schema on app install (repair step or onEnable hook)
- [ ] Thumbnail images (PNG) in `appdata/demo/` are valid and loadable
- [ ] No raw exception messages returned to client
- [ ] Demo showcase installations are NOT visible in user's personal dashboard list — they are group-scoped default-group

## 8. Follow-ups (separate changes)

- [ ] `demo-data-showcase-preview` — live preview of showcase widgets before install
- [ ] `demo-data-showcase-versioning` — support multiple showcase versions (v1, v2) per ID
- [ ] `demo-data-showcase-export` — export installed dashboard back to template JSON
- [ ] `demo-data-showcase-custom-upload` — allow admins to create/upload custom showcases via UI
