# Tasks — orphaned-data-cleanup

## 1. Core interfaces and registry

- [ ] 1.1 Create `lib/Cleanup/CleanupCategoryInterface.php` with methods:
  - `getName(): string` — machine name (e.g., 'expired_locks')
  - `getDisplayName(): string` — human-readable label
  - `getSafeToPurgeAutomatically(): bool` — Tier-A (true) or Tier-B/C (false)
  - `isAvailable(): bool` — false if required tables/feature are missing
  - `scan(): int` — count of orphaned rows without deleting
  - `purge(bool $dryRun = false): int` — delete and return count (or return count without deleting if dryRun=true)

- [ ] 1.2 Create `lib/Service/CategoryRegistryService.php` that:
  - Collects all cleanup category implementations via constructor injection (list each category as a parameter)
  - Exposes `getAllCategories(): CleanupCategoryInterface[]` in registration order
  - Exposes `getCategoryByName(string $name): CleanupCategoryInterface` with exception if not found
  - Exposes `getAutoSafeCategoryNames(): string[]` returning names where `getSafeToPurgeAutomatically() === true`
  - Exposes `getAvailableCategories(): CleanupCategoryInterface[]` filtering to `isAvailable() === true`

- [ ] 1.3 Create `lib/Service/OrphanedDataCleanupService.php` (orchestrator) that:
  - Exposes `scan(?array $categoryNames = null): array` — returns `['byCategory' => [...], 'totalRows' => N, 'durationMs' => X]`
  - Exposes `purge(array $categoryNames, bool $dryRun = false, string $source = 'cli'): array` — same structure as scan
  - When `$categoryNames` is empty or null, uses all available categories
  - Validates category names, throwing `\OCA\MyDash\Exception\UnknownCategoryException` on unknown name
  - Invokes each category's `scan()` or `purge()` method
  - Measures total duration
  - On non-dry-run purge with totalRows > 0, calls `ActivityEventPublisher` (see task 5)

## 2. Cleanup category implementations

### 2.1 ExpiredLocksCleanupCategory (Tier-A)

- [ ] 2.1.1 Create `lib/Cleanup/Categories/ExpiredLocksCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.1.2 In `scan()`: count rows in `oc_mydash_dashboard_locks` where `updated_at < now() - 900 seconds` (15 minutes)
- [ ] 2.1.3 In `purge()`: delete those rows via `DashboardLockMapper::deleteExpiredLocks()` (see task 3)
- [ ] 2.1.4 Set `getSafeToPurgeAutomatically()` to return `true`

### 2.2 ExpiredShareTokensCleanupCategory (Tier-A)

- [ ] 2.2.1 Create `lib/Cleanup/Categories/ExpiredShareTokensCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.2.2 In `scan()`: count rows in `oc_mydash_dashboard_shares` where `dashboard_id` does not exist in `oc_mydash_dashboards`
- [ ] 2.2.3 In `purge()`: delete those rows via `DashboardShareMapper::deleteOrphanedTokens()` (see task 3)
- [ ] 2.2.4 Set `getSafeToPurgeAutomatically()` to return `true`

### 2.3 OrphanedWidgetPlacementsCleanupCategory (Tier-B, core)

- [ ] 2.3.1 Create `lib/Cleanup/Categories/OrphanedWidgetPlacementsCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.3.2 In `scan()`: count rows in `oc_mydash_widget_placements` where `dashboard_id` does not exist in `oc_mydash_dashboards`
- [ ] 2.3.3 In `purge()`: delete those rows via `WidgetPlacementMapper::deleteOrphanedPlacements()` (see task 3)
- [ ] 2.3.4 Set `getSafeToPurgeAutomatically()` to return `false`

### 2.4 OrphanedConditionalRulesCleanupCategory (Tier-B, core)

- [ ] 2.4.1 Create `lib/Cleanup/Categories/OrphanedConditionalRulesCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.4.2 In `scan()`: count rows in `oc_mydash_conditional_rules` where `widget_placement_id` does not exist in `oc_mydash_widget_placements`
- [ ] 2.4.3 In `purge()`: delete those rows via `ConditionalRuleMapper::deleteOrphanedRules()` (see task 3)
- [ ] 2.4.4 Set `getSafeToPurgeAutomatically()` to return `false`

### 2.5 OrphanedWidgetAssetsCleanupCategory (Tier-B, optional)

- [ ] 2.5.1 Create `lib/Cleanup/Categories/OrphanedWidgetAssetsCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.5.2 Implement `isAvailable()` checking if file backend is provisioned (check config or try folder access)
- [ ] 2.5.3 In `scan()`: enumerate files in `MyDash/Imports/*` and `MyDash/icons/*`, count those not referenced by any dashboard widget config
- [ ] 2.5.4 In `purge()`: delete unreferenced files via filesystem (Nextcloud Files API)
- [ ] 2.5.5 Set `getSafeToPurgeAutomatically()` to return `false`

### 2.6 OrphanedMetadataValuesCleanupCategory (Tier-B, optional)

- [ ] 2.6.1 Create `lib/Cleanup/Categories/OrphanedMetadataValuesCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.6.2 Implement `isAvailable()` checking if feature `dashboard-metadata-fields` is present (check for `oc_mydash_metadata_fields` table existence)
- [ ] 2.6.3 In `scan()`: count rows in `oc_mydash_metadata_values` where `fieldId` does not exist in `oc_mydash_metadata_fields`
- [ ] 2.6.4 In `purge()`: delete those rows via `MetadataValueMapper::deleteOrphanedValues()` (see task 3)
- [ ] 2.6.5 Set `getSafeToPurgeAutomatically()` to return `false`

### 2.7 OrphanedFeedTokensCleanupCategory (Tier-B, optional)

- [ ] 2.7.1 Create `lib/Cleanup/Categories/OrphanedFeedTokensCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.7.2 Implement `isAvailable()` checking if feature `dashboard-rss-feeds` is present (check for `oc_mydash_feed_tokens` table existence)
- [ ] 2.7.3 In `scan()`: count rows in `oc_mydash_feed_tokens` where `userId` does not exist in `oc_users`
- [ ] 2.7.4 In `purge()`: delete those rows via `FeedTokenMapper::deleteOrphanedTokens()` (see task 3)
- [ ] 2.7.5 Set `getSafeToPurgeAutomatically()` to return `false`

### 2.8 DanglingDashboardTranslationsCleanupCategory (Tier-B, optional)

- [ ] 2.8.1 Create `lib/Cleanup/Categories/DanglingDashboardTranslationsCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.8.2 Implement `isAvailable()` checking if feature `dashboard-language-content` is present (check for `oc_mydash_dash_translations` table existence)
- [ ] 2.8.3 In `scan()`: count rows in `oc_mydash_dash_translations` where `dashboardUuid` does not exist in `oc_mydash_dashboards`
- [ ] 2.8.4 In `purge()`: delete those rows via `DashboardTranslationMapper::deleteOrphanedTranslations()` (see task 3)
- [ ] 2.8.5 Set `getSafeToPurgeAutomatically()` to return `false`

### 2.9 OrphanedRoleAssignmentsCleanupCategory (Tier-C, optional)

- [ ] 2.9.1 Create `lib/Cleanup/Categories/OrphanedRoleAssignmentsCleanupCategory.php` implementing `CleanupCategoryInterface`
- [ ] 2.9.2 Implement `isAvailable()` checking if feature `admin-roles` is present (check for `oc_mydash_role_assignments` table existence)
- [ ] 2.9.3 In `scan()`: count rows in `oc_mydash_role_assignments` where `userId` or `groupId` does not exist in `oc_users` or `oc_groups`
- [ ] 2.9.4 In `purge()`: delete those rows via `RoleAssignmentMapper::deleteOrphanedAssignments()` (see task 3)
- [ ] 2.9.5 Set `getSafeToPurgeAutomatically()` to return `false`

## 3. Mapper layer cleanup methods

- [ ] 3.1 In `lib/Db/DashboardLockMapper.php` add `deleteExpiredLocks(): int` — delete locks older than 15 minutes, return count
- [ ] 3.2 In `lib/Db/DashboardShareMapper.php` add `deleteOrphanedTokens(): int` — delete shares with missing dashboards, return count
- [ ] 3.3 In `lib/Db/WidgetPlacementMapper.php` add `deleteOrphanedPlacements(): int` — delete placements with missing dashboards, return count
- [ ] 3.4 In `lib/Db/ConditionalRuleMapper.php` add `deleteOrphanedRules(): int` — delete rules with missing placements, return count
- [ ] 3.5 In `lib/Db/MetadataValueMapper.php` add `deleteOrphanedValues(): int` — optional, conditional on feature, delete values with missing fields, return count
- [ ] 3.6 In `lib/Db/FeedTokenMapper.php` add `deleteOrphanedTokens(): int` — optional, conditional on feature, delete tokens for missing users, return count
- [ ] 3.7 In `lib/Db/DashboardTranslationMapper.php` add `deleteOrphanedTranslations(): int` — optional, conditional on feature, delete translations with missing dashboards, return count
- [ ] 3.8 In `lib/Db/RoleAssignmentMapper.php` add `deleteOrphanedAssignments(): int` — optional, conditional on feature, delete assignments for missing users/groups, return count

## 4. CLI commands

- [ ] 4.1 Create `lib/Command/CleanupScanCommand.php` extending `OC\Core\Command\Base` that:
  - Route: `php occ mydash:cleanup:scan`
  - Calls `OrphanedDataCleanupService::scan()`
  - Displays results in a table format: `[Category Name] [Count]`, one row per category, TOTAL at end
  - Returns exit code 0 if total is 0, exit code 1 if total > 0
  - Lists skipped categories with comment if any
  - Uses PSR-3 logger to log scan result (duration, total count)

- [ ] 4.2 Create `lib/Command/CleanupPurgeCommand.php` extending `OC\Core\Command\Base` that:
  - Route: `php occ mydash:cleanup:purge [--category=<name>] [--dry-run] [--yes]`
  - If `--yes` not provided, prompt user to confirm; show list of categories to be purged; accept `y` or `n`
  - If user declines, output "Purge cancelled." and exit 0
  - If user confirms (or `--yes` provided), call `OrphanedDataCleanupService::purge()`
  - Output: `DRY-RUN: Would purge...` if `--dry-run`, else `Purged...`
  - Table format same as scan: per-category breakdown + TOTAL
  - On unknown category, output error message, list valid categories, return exit code 1
  - Uses PSR-3 logger to log purge result

## 5. REST API endpoints and controller

- [ ] 5.1 Create `lib/Controller/AdminCleanupController.php` extending `OCP\AppFramework\Controller`

- [ ] 5.2 Add `AdminCleanupController::scan()` method:
  - Route: `GET /api/admin/cleanup/scan`
  - Attribute: `#[Admin]` (admin-only)
  - Calls `ScanCacheService::getOrFresh()` (see task 6)
  - Returns HTTP 200 with JSON:
    ```json
    {
      "byCategory": {
        "expired_locks": 2,
        "expired_share_tokens": 1,
        ...
      },
      "totalRows": 3,
      "durationMs": 145,
      "scannedAt": "2026-05-21T10:30:45Z",
      "cached": false,
      "cachedAt": null,
      "skipped": []
    }
    ```

- [ ] 5.3 Add `AdminCleanupController::purge()` method:
  - Route: `POST /api/admin/cleanup/purge`
  - Attribute: `#[Admin]` (admin-only)
  - Request body: `{"categories": ["expired_locks", ...], "dryRun": false}`
  - Calls `OrphanedDataCleanupService::purge()`
  - Returns HTTP 200 with JSON:
    ```json
    {
      "purgedByCategory": {
        "expired_locks": 2,
        ...
      },
      "totalRows": 3,
      "durationMs": 200,
      "dryRun": false,
      "skipped": []
    }
    ```
  - On unknown category, return HTTP 400 with:
    ```json
    {
      "error": "Unknown category 'invalid_category'",
      "validCategories": ["expired_locks", ...]
    }
    ```

- [ ] 5.4 Register routes in `appinfo/routes.php`:
  - `GET /api/admin/cleanup/scan`
  - `POST /api/admin/cleanup/purge`

## 6. Caching service

- [ ] 6.1 Create `lib/Service/ScanCacheService.php` that:
  - Exposes `getOrFresh(): array` — returns cached scan result if valid (within 300s), else calls `OrphanedDataCleanupService::scan()` and caches
  - Exposes `invalidate(): void` — removes cache entry
  - Uses `ICacheFactory::createDistributed('mydash.cleanup.scan')` as cache backend
  - Cache key is literal `'scan'` (no category filter in key; partial scans bypass cache entirely)
  - Includes `cached`, `cachedAt` metadata in response

## 7. Activity event publisher

- [ ] 7.1 Create `lib/Service/ActivityEventPublisher.php` that:
  - Exposes `publishPurgeEvent(array $result, string $source, ?string $userId = null): void`
  - Only publishes if `$result['totalRows'] > 0`
  - Creates event with:
    - app: `'mydash'`
    - type: `'mydash_cleanup_purge'`
    - subject: `'Purged {totalRows} orphaned items'` or similar (uses translation key)
    - parameters: `['totalRows' => N, 'byCategory' => [...], 'durationMs' => X, 'source' => 'cli'|'api'|'job']`
  - For API source, sets `$userId` as affected user / author
  - Injects `IActivityManager` from Nextcloud

## 8. Background job

- [ ] 8.1 Create `lib/Job/OrphanedDataCleanupJob.php` extending `\OCP\BackgroundJob\TimedJob` that:
  - Implements `run($argument = '')` method
  - Resolves auto-purge category list: default to `CategoryRegistryService::getAutoSafeCategoryNames()`, or from config `cleanup_auto_purge_categories`
  - If list is empty, log `mydash.cleanup.job_skipped reason=no_categories_enabled` and return
  - Calls `OrphanedDataCleanupService::purge()` with list and `source='job'`
  - On purge, `ActivityEventPublisher` emits event automatically (via orchestrator)
  - Set interval to 24 hours (86400 seconds)

- [ ] 8.2 Register job in `appinfo/info.xml` under `<background-jobs>` section

## 9. Configuration and defaults

- [ ] 9.1 Ensure `cleanup_auto_purge_categories` config key is documented in admin docs
  - Default: not set (uses Tier-A)
  - Value: JSON-encoded array of category names, e.g. `'["expired_locks", "orphaned_widget_placements"]'`
  - Admin can set via `occ config:app:set mydash cleanup_auto_purge_categories --value='["expired_locks"]'`

- [ ] 9.2 Register `CategoryRegistryService` as a service in DI container (`appinfo/services.xml` or similar) with all 9 cleanup categories injected

## 10. Testing

- [ ] 10.1 Unit test: `tests/Unit/Cleanup/ExpiredLocksCleanupCategoryTest.php` — scan returns correct count, purge deletes and returns count
- [ ] 10.2 Unit test: `tests/Unit/Cleanup/ExpiredShareTokensCleanupCategoryTest.php` — same pattern
- [ ] 10.3 Unit test: `tests/Unit/Service/OrphanedDataCleanupServiceTest.php`:
  - Test scan with no categories → all available
  - Test purge with empty categories array → all available
  - Test purge with unknown category → exception or validation
  - Test dry-run → no rows deleted, count same as real purge
  - Test cache key generation and invalidation
- [ ] 10.4 Integration test: `tests/Integration/Command/CleanupScanCommandTest.php` — execute via CLI, verify table output, exit codes
- [ ] 10.5 Integration test: `tests/Integration/Command/CleanupPurgeCommandTest.php` — execute via CLI with --yes, --dry-run, --category
- [ ] 10.6 Integration test: `tests/Integration/Controller/AdminCleanupControllerTest.php` — GET/POST endpoints, admin auth, caching
- [ ] 10.7 Integration test: `tests/Integration/Job/OrphanedDataCleanupJobTest.php` — background job runs, emits event, respects config

## 11. Documentation and localization

- [ ] 11.1 Add strings to `translationfiles` (e.g., `resources/translations/en.json`) for:
  - "Orphaned Data Cleanup"
  - "Scan for orphaned items"
  - "Purge orphaned items"
  - "Dry-run mode: no data was deleted"
  - per-category display names
  - Activity event description

- [ ] 11.2 Add admin documentation section in `docs/admin/cleanup.md` explaining:
  - How to run scan and purge manually
  - What each category means and which are auto-purged
  - How to configure auto-purge categories
  - Dry-run workflow
  - Activity log integration

- [ ] 11.3 Update `README.md` or feature matrix to mention cleanup capability
