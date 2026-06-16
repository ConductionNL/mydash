# Tasks — nc-unified-search-integration

## 1. Service layer — Search provider

- [ ] 1.1 Create `lib/Service/SearchProvider/MyDashSearchProvider.php` implementing `OCP\Search\IProvider`
- [ ] 1.2 Inject dependencies: `DashboardMapper`, `DashboardPermissionService`, `IL10N` (from `@Inject` annotations or constructor)
- [ ] 1.3 Implement `getId()` returning `'mydash'`
- [ ] 1.4 Implement `getName()` returning `$this->l10n->t('Dashboards')` (localized via IL10N)
- [ ] 1.5 Implement `getOrder()` returning `50` (mid-range priority so admin-search providers come first)
- [ ] 1.6 Implement `search(IUser $user, ISearchQuery $query): SearchResult` method stub
- [ ] 1.7 Add inline comment on the class linking to REQ-SRCH-001, REQ-SRCH-002, REQ-SRCH-003 from the spec

## 2. Search method — Dashboard name/description match

- [ ] 2.1 In `search()`, fetch all dashboards the user can VIEW via `DashboardMapper::findByUserId($user->getUID())` filtered through `DashboardPermissionService::canView()`
- [ ] 2.2 Implement case-insensitive substring match on dashboard name: `stripos(dashboard.name, query) !== false`
- [ ] 2.3 Implement case-insensitive substring match on dashboard description: `stripos(dashboard.description, query) !== false`
- [ ] 2.4 Build SearchResultEntry for each matching dashboard with: `title` = dashboard name, `subline` = `sprintf($this->l10n->t('Created by %s • %d widgets'), creatorName, widgetCount)`, `resourceUrl` = `/apps/mydash/dashboard/{uuid}`, `thumbnailUrl` = built-in MyDash icon URL
- [ ] 2.5 Cap results to 10 entries (override with `$query->getLimit()` if larger)
- [ ] 2.6 Return `SearchResult::complete($this->getName(), $entries)`

## 3. Search method — Widget content match

- [ ] 3.1 For each dashboard the user can VIEW, fetch all text-display-widget placements via `WidgetPlacementMapper::findByDashboardId()`
- [ ] 3.2 For each placement of type `text_display` (or similar), check if the rendered `content` (HTML/markdown) contains a case-insensitive substring match
- [ ] 3.3 Build SearchResultEntry for each matching placement with: `title` = dashboard name, `subline` = `sprintf($this->l10n->t('Widget content on %s'), dashboardName)`, `resourceUrl` = `/apps/mydash/dashboard/{dashboardUuid}#widget-{placementId}`, `thumbnailUrl` = widget-type icon (or fallback to MyDash icon)
- [ ] 3.4 Cap widget-content results to 10 entries
- [ ] 3.5 Return combined `SearchResult` with both dashboard + widget entries

## 4. Search method — Metadata field match (optional, degrade silently)

- [ ] 4.1 If the `dashboard-metadata-fields` capability is available (check via `$this->container->has('mydash.metadata.fields')`), fetch metadata field definitions
- [ ] 4.2 For each dashboard the user can VIEW, fetch metadata field values via the metadata service
- [ ] 4.3 Match metadata field values case-insensitively against the query string
- [ ] 4.4 Build SearchResultEntry for each matching field value with: `title` = dashboard name, `subline` = `sprintf($this->l10n->t('Metadata: %s = %s'), fieldName, fieldValue)`, `resourceUrl` = `/apps/mydash/dashboard/{dashboardUuid}`, `thumbnailUrl` = metadata icon or fallback
- [ ] 4.5 If `dashboard-metadata-fields` is not available, skip this step silently (no error returned)
- [ ] 4.6 Cap metadata-value results to 10 entries

## 5. Permission filtering

- [ ] 5.1 Before returning any dashboard or widget entry, call `DashboardPermissionService::canView($dashboard, $user)` to confirm the user has VIEW permission
- [ ] 5.2 Never return entries for dashboards with permission result = false
- [ ] 5.3 Inline comment linking to REQ-SRCH-005 (Permission filter) and the `permissions` capability

## 6. Pagination / cursor support

- [ ] 6.1 Implement cursor-based pagination by storing the last-seen dashboard/widget ID in the cursor
- [ ] 6.2 Accept `$query->getCursor()` and use it to resume the search from the previous position
- [ ] 6.3 Pass a new cursor to `SearchResult::complete()` so the frontend's "Load more" button can continue the search
- [ ] 6.4 Confirm Nextcloud's search UI calls `search()` multiple times with advancing cursors until cursor is null

## 7. Performance & indexing

- [ ] 7.1 Confirm that `oc_mydash_dashboards.name` and `oc_mydash_dashboards.description` have database indexes (or add them in a schema migration)
- [ ] 7.2 Use `LIKE '%{query}%'` with indexed columns; queries MUST complete in <500ms for 10K dashboards (test locally with load data)
- [ ] 7.3 If performance is suboptimal, consider a follow-up full-text index (out of scope for this change)

## 8. Localization

- [ ] 8.1 Ensure all translatable strings use `$this->l10n->t(...)` or `$this->l10n->n(...)`:
  - Provider name: "Dashboards"
  - Subline format: "Created by %s • %d widgets"
  - Subline format: "Widget content on %s"
  - Subline format: "Metadata: %s = %s" (if metadata fields capability is enabled)
- [ ] 8.2 Add corresponding translation strings to `translationFiles` in `appinfo/info.xml`
- [ ] 8.3 Run `composer run extracttranslations` or equivalent to populate `l10n/en.json`, `l10n/nl.json`

## 9. App registration

- [ ] 9.1 Update `appinfo/info.xml` to declare the search provider in a `<types>` section:
  ```xml
  <types>
    <type>search</type>
  </types>
  ```
- [ ] 9.2 Register `MyDashSearchProvider` in the app's container bootstrap (typically in `lib/AppInfo/Application.php` using `$container->registerService()` or similar)

## 10. Testing — Behat / E2E

- [ ] 10.1 Create `tests/Integration/Search/SearchProviderTest.php` PHPUnit test
- [ ] 10.2 Test: dashboard named "Marketing" appears in `GET /ocs/v2.php/search/providers/mydash/search?term=mark` with the correct deep-link
- [ ] 10.3 Test: widget with content "budget proposal" on a dashboard appears when searching for "budget"
- [ ] 10.4 Test: metadata field value "Q1 2026" appears in search results for "2026" (if metadata capability is enabled)
- [ ] 10.5 Test: search does NOT return dashboards the user cannot VIEW
- [ ] 10.6 Test: empty query result returns `SearchResult::complete()` with empty array (no 404)
- [ ] 10.7 Test: cursor pagination returns the next batch of results correctly
- [ ] 10.8 Create Behat feature test covering: user navigates via Ctrl+K, types "dashboard name", presses Enter, lands on the correct dashboard view

## 11. Frontend — Deep-link support

- [ ] 11.1 In `src/views/DashboardDetail.vue`, add support for the `#widget-{placementId}` URL hash fragment
- [ ] 11.2 On component mount, extract the widget ID from the hash and programmatically scroll/highlight that widget placement
- [ ] 11.3 Fallback: if hash is not present, render the dashboard normally (no regression for non-search-based navigation)
- [ ] 11.4 Inline comment linking to REQ-SRCH-004 (Widget deep-link)

## 12. Quality & code review

- [ ] 12.1 Run `composer check:strict` (PHPCS, PHPMD, PHPStan) to ensure code quality
- [ ] 12.2 Fix any pre-existing quality issues in the search provider class
- [ ] 12.3 Verify no unused imports or dead code
- [ ] 12.4 Add inline docblock comments to the search method explaining the permission boundary and cursor semantics
