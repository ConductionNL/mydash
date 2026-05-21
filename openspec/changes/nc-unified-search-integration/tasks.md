# Tasks — nc-unified-search-integration

## Tasks

- [ ] Task 1: Create `lib/Search/MyDashSearchProvider.php` implementing `OCP\Search\IProvider` with methods `getId()` returning `'mydash'`, `getName()` returning `IL10N::t('Dashboards')`, `getOrder()` returning `50` (or `5` when on a MyDash route), and `search(IUser, ISearchQuery): SearchResult` as the main entry point; add inline comments linking REQ-SRCH-001 through REQ-SRCH-012

- [ ] Task 2: Implement `matchesDashboard(Dashboard, string): bool` helper using `mb_strtolower()` + `str_contains()` for case-insensitive substring matching on both `getName()` and `getDescription()` (REQ-SRCH-002)

- [ ] Task 3: Implement `findMatchingTextPlacements(Dashboard, string): array` helper that iterates `WidgetPlacementMapper::findByDashboardId()`, filters on `styleConfig['type'] === 'text'`, decodes JSON config, strips HTML via `strip_tags()` + `html_entity_decode()`, and returns matching placements with placement ID and parent dashboard (REQ-SRCH-003)

- [ ] Task 4: Implement `findMatchingMetadata(Dashboard, string): array` helper that calls `MetadataService::getMetadataForDashboard()` and matches case-insensitively on field **values only** (not field names/keys); wrap the entire call in a try/catch that silently returns `[]` on any exception for graceful degradation (REQ-SRCH-004)

- [ ] Task 5: Implement `search(IUser, ISearchQuery): SearchResult` to:
  - Return early with empty `SearchResult::complete($this->getName(), [])` if the query term is empty or whitespace-only (REQ-SRCH-009)
  - Call `DashboardService::getVisibleToUser($user)` exactly once to fetch the permission-filtered candidate set; wrap in try/catch and return empty result on failure (REQ-SRCH-005)
  - Run `matchesDashboard()` on each candidate; collect matches up to 10 results (cap per Task 7)
  - For each matching dashboard, run `findMatchingTextPlacements()` and `findMatchingMetadata()`; collect results up to 10 per type
  - Call result builders (Tasks 6) for each match to emit `SearchResultEntry` objects
  - Return `SearchResult::complete($this->getName(), $allResults)` with all entries concatenated: dashboards first, then widgets, then metadata (REQ-SRCH-005, REQ-SRCH-007, REQ-SRCH-010)

- [ ] Task 6a: Implement `buildDashboardEntry(Dashboard): SearchResultEntry` returning a `SearchResultEntry` with:
  - `title`: dashboard name
  - `subline`: dashboard description if non-empty, else localized `'MyDash dashboard'` fallback
  - `thumbnailUrl`: app icon URL via `IURLGenerator::getAbsoluteURL(imagePath('mydash', 'app.svg'))`
  - `resourceUrl`: dashboard deep-link via `IURLGenerator::linkToRouteAbsolute('mydash.page.index') . '#dashboard/' . $dashboard->getUuid()`
  - (REQ-SRCH-006)

- [ ] Task 6b: Implement `buildWidgetEntry(WidgetPlacement, Dashboard): SearchResultEntry` returning a `SearchResultEntry` with:
  - `title`: parent dashboard name
  - `subline`: localized string `'Widget content on %s'` with dashboard name interpolated via `IL10N::t('Widget content on %s', $dashboard->getName())`
  - `thumbnailUrl`: app icon URL (same as Task 6a)
  - `resourceUrl`: dashboard deep-link plus widget hint: `IURLGenerator::linkToRouteAbsolute('mydash.page.index') . '#dashboard/' . $dashboard->getUuid() . ';widget=' . $placement->getId()`
  - (REQ-SRCH-006)

- [ ] Task 6c: Implement `buildMetadataEntry(Dashboard, string $fieldKey, string $fieldValue): SearchResultEntry` returning a `SearchResultEntry` with:
  - `title`: dashboard name
  - `subline`: localized string `'Metadata: %1$s = %2$s'` with field key and value interpolated via `IL10N::t('Metadata: %1$s = %2$s', $fieldKey, $fieldValue)`
  - `thumbnailUrl`: app icon URL (same as Task 6a)
  - `resourceUrl`: dashboard deep-link (no widget hint) via `IURLGenerator::linkToRouteAbsolute('mydash.page.index') . '#dashboard/' . $dashboard->getUuid()`
  - (REQ-SRCH-006)

- [ ] Task 7: Add constants to `MyDashSearchProvider` defining `PER_BUCKET_LIMIT = 10` for dashboard, widget, and metadata result caps; enforce the cap in Task 5 by breaking the loop once each bucket reaches 10 results (REQ-SRCH-007, REQ-SRCH-010)

- [ ] Task 8: Register the search provider in `lib/AppInfo/Application.php` by calling `$context->registerSearchProvider(MyDashSearchProvider::class)` in the app bootstrap (e.g., in the constructor or an `IBootstrap::boot()` method); ensure the class is type-hinted correctly via dependency injection (REQ-SRCH-001)

- [ ] Task 9: Declare search capability in `appinfo/info.xml` by adding `<types><search/></types>` inside the `<types>` root element (REQ-SRCH-001)

- [ ] Task 10: Add translation strings to `l10n/en.json` and `l10n/nl.json`:
  - `"Dashboards"` — provider name
  - `"MyDash dashboard"` — fallback subline for dashboards with empty description
  - `"Widget content on %s"` — subline for widget matches with dashboard name interpolation
  - `"Metadata: %1$s = %2$s"` — subline for metadata matches with field key and value interpolation
  - (REQ-SRCH-008)

- [ ] Task 11: Implement `getOrder()` method to return `50` by default, but return `5` when the current route is a MyDash route (e.g., matches `'/apps/mydash/'` or the route name starts with `'mydash.'`); use `IURLGenerator::getCurrentUrl()` or route information from the current request context (REQ-SRCH-012)

- [ ] Task 12: Vitest — `test/Unit/Search/MyDashSearchProviderTest.php`:
  - Provider ID is `'mydash'`, name is translated `'Dashboards'`, order is `50` (default) and `5` (in-app)
  - Empty term returns empty `SearchResult`
  - Whitespace-only term returns empty `SearchResult`
  - Dashboard name match via case-insensitive substring (e.g., "market" matches "Marketing Campaign 2026")
  - Dashboard description match (e.g., "quarterly" matches description "Company quarterly performance")
  - Substring matching (e.g., "pipe" matches "Pipeline")
  - Multiple dashboards all returned for partial match (e.g., "market" returns all three marketing dashboards)
  - Widget content match via HTML stripping (e.g., "revenue" matches `<h1>Revenue</h1>`)
  - Widget content case-insensitive match
  - Multiple widgets on same dashboard return separate results with correct placement IDs
  - Non-text widgets skipped silently (no error)
  - Metadata value match when capability enabled
  - Metadata key-vs-value distinction (key not searchable, value is)
  - Metadata case-insensitive match
  - Metadata gracefully degraded when capability unavailable (no error, just no metadata results)
  - Permission filtering — user sees only visible dashboards
  - Permission denial — user does NOT see private dashboard
  - Group-shared dashboard visible to group members only
  - Permission service failure returns empty `SearchResult` (fail-safe)
  - Dashboard results capped at 10
  - Widget results capped at 10
  - Metadata results capped at 10
  - SearchResultEntry has correct title, subline, thumbnailUrl, resourceUrl for each result type
  - (REQ-SRCH-001 through REQ-SRCH-012)

- [ ] Task 13: Playwright — `tests/Browser/SearchIntegration.e2e.js`:
  - User opens unified search (Ctrl+K), types "marketing", dashboard "Marketing Campaign 2026" appears with name and description
  - User clicks search result, navigates to dashboard view
  - Widget content match returns result with correct dashboard name and "Widget content on..." subline
  - Widget deep-link URL includes `widget=<placementId>` hint
  - Searching for non-matching term returns "No results"
  - Metadata field value match returns result with "Metadata: year = 2026" subline (when capability enabled)
  - Permission boundary respected — user does NOT see private dashboard in results
  - Group-shared dashboard visible to group members, hidden to non-members
  - (REQ-SRCH-011, REQ-SRCH-002 through REQ-SRCH-005)

- [ ] Task 14: Seed data generation task — create fixture dashboards and widgets in the app seeder or test database:
  - "Marketing Campaign 2026" dashboard with description "Company quarterly performance overview for marketing initiatives"
  - "Q1 Metrics" dashboard with description "Company quarterly performance overview"
  - "Sales Pipeline Analysis" dashboard with description "Current sales opportunities and conversion metrics"
  - "Team Analytics" dashboard (group-shared, group='sales') with description "Performance metrics for the sales group"
  - "Analytics" dashboard with three text widgets: "Budget Proposal for Q2", "Meeting notes from Monday with January timeline", "Sales targets for 2026"
  - (REQ-SRCH-002, REQ-SRCH-003)

- [ ] Task 15: Quality checks:
  - ESLint clean on `lib/Search/MyDashSearchProvider.php` (Psalm/PHPStan static analysis)
  - No new PHPCS/PHPMD/Psalm/PHPStan regressions (run `composer check:strict`)
  - i18n review — all user-facing strings in Tasks 10 registered in translation files
  - Code review: dependency injection, null-safety, permission boundary isolation, graceful exception handling in metadata service
  - (REQ-SRCH-008)

## Verification

`openspec validate` exits clean. Search provider is registered, all result types are discoverable via unified search, permission boundaries are enforced, optional metadata gracefully degrades, and translation strings are present in `nl` and `en` files.

## Tests (company-wide ADR-009)

Vitest per Task 12; Playwright per Task 13. No new frontend surface (search provider is backend-only; Nextcloud's search UI handles display).

## Documentation (company-wide ADR-010)

Inline class and method comments per Task 1; changelog entry covering the new search provider and multi-bucket result matching.

## i18n (company-wide ADR-025)

Translation strings in Tasks 10 and 11 (subline, metadata, provider name); `l10n/en.json` and `l10n/nl.json` both updated.

## Deduplication Check

**Existing services reused (no duplication):**
- `DashboardService::getVisibleToUser()` — canonical permission boundary; no custom permission checks needed.
- `WidgetPlacementMapper::findByDashboardId()` — existing widget data access.
- `MetadataService::getMetadataForDashboard()` — existing metadata retrieval (optional).
- `OCP\Search\IProvider` — standard Nextcloud interface; no custom search framework.
- `IL10N` factory — standard Nextcloud localization; no custom i18n.

**Unique to this change:**
- Search provider implementation (not provided by OpenRegister or existing app services).
- Multi-bucket matching logic (dashboard, widget, metadata distinct result types).
- HTML stripping for widget content (standard PHP `strip_tags()`, no custom implementation).

**Conclusion:** No overlap with existing capabilities. Deduplication analysis: PASSED.
