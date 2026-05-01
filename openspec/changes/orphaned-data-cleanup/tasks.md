# Tasks — orphaned-data-cleanup

## 1. Core domain model

- [ ] 1.1 Create `lib/Db/CleanupResult.php` DTO with fields: `byCategory: array<string, int>`, `totalRows: int`, `durationMs: int`, `dryRun: bool`, `scannedAt: \DateTime` (for API and CLI reporting)
- [ ] 1.2 Add getters/setters for each field with type hints

## 2. Category registry infrastructure

- [ ] 2.1 Create `lib/Service/Cleanup/CleanupCategoryInterface.php` interface with methods:
  - [ ] `getName(): string` — unique category identifier (e.g., "expired_locks")
  - [ ] `getDisplayName(): string` — human-readable label (e.g., "Expired Dashboard Locks")
  - [ ] `getSafeToPurgeAutomatically(): bool` — whether this category can be auto-purged
  - [ ] `scan(): int` — count orphaned items; return count
  - [ ] `purge(bool $dryRun = false): int` — delete orphaned items (or simulate if dryRun); return count deleted
- [ ] 2.2 Create `lib/Service/Cleanup/CategoryRegistryService.php` with:
  - [ ] Constructor dependency injection of all category instances
  - [ ] `getCategories(): array<CleanupCategoryInterface>` — return all registered categories
  - [ ] `getCategoryByName(string $name): ?CleanupCategoryInterface` — lookup by name
  - [ ] `getAutoSafeCategories(): array<string>` — return names of categories safe to auto-purge

## 3. Category implementations (per-category scan/purge logic)

- [ ] 3.1 Create `lib/Service/Cleanup/ExpiredLocksCategory.php` implementing `CleanupCategoryInterface`
  - [ ] `scan()`: Query `oc_mydash_dashboard_locks` WHERE `expiresAt < NOW()`; return count
  - [ ] `purge()`: DELETE from same; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return true

- [ ] 3.2 Create `lib/Service/Cleanup/ExpiredShareTokensCategory.php`
  - [ ] `scan()`: Query `oc_mydash_public_shares` WHERE `expiresAt < NOW() OR revokedAt < (NOW() - INTERVAL 30 DAY)`; return count
  - [ ] `purge()`: DELETE from same; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return true

- [ ] 3.3 Create `lib/Service/Cleanup/OrphanedWidgetAssetsCategory.php`
  - [ ] `scan()`: Enumerate files in `MyDash/Imports/*` and `MyDash/icons/*` via `IAppData`; check if referenced in any dashboard/widget config JSON; count unreferenced; return count
  - [ ] `purge()`: Delete unreferenced files; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return false (requires file system access validation)

- [ ] 3.4 Create `lib/Service/Cleanup/OrphanedMetadataValuesCategory.php`
  - [ ] `scan()`: Query `oc_mydash_metadata_values` LEFT JOIN `oc_mydash_metadata_fields` where field is NULL; return count
  - [ ] `purge()`: DELETE orphaned values; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return false

- [ ] 3.5 Create `lib/Service/Cleanup/OrphanedWidgetPlacementsCategory.php`
  - [ ] `scan()`: Query `oc_mydash_widget_placements` LEFT JOIN `oc_mydash_dashboards` where dashboard is NULL; return count
  - [ ] `purge()`: DELETE orphaned placements; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return false

- [ ] 3.6 Create `lib/Service/Cleanup/OrphanedFeedTokensCategory.php` (conditional on dashboard-rss-feeds feature)
  - [ ] `scan()`: Query `oc_mydash_feed_tokens` LEFT JOIN `oc_users` where user is NULL; return count
  - [ ] `purge()`: DELETE orphaned tokens; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return true

- [ ] 3.7 Create `lib/Service/Cleanup/OrphanedRoleAssignmentsCategory.php` (conditional on admin-roles feature)
  - [ ] `scan()`: Query `oc_mydash_role_assignments` LEFT JOIN `oc_users` and `oc_groups` where both are NULL; return count
  - [ ] `purge()`: DELETE orphaned assignments; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return false (Tier-C: inspect first)

- [ ] 3.8 Create `lib/Service/Cleanup/DanglingDashboardTranslationsCategory.php` (conditional on dashboard-language-content feature)
  - [ ] `scan()`: Query `oc_mydash_dashboard_translations` LEFT JOIN `oc_mydash_dashboards` where dashboard is NULL; return count
  - [ ] `purge()`: DELETE dangling translations; return count deleted
  - [ ] `getSafeToPurgeAutomatically()`: return false

## 4. Orchestration service

- [ ] 4.1 Create `lib/Service/OrphanedDataCleanupService.php` with dependencies: `CategoryRegistryService`, `ICache`, `ILogger`, `IActivity\IManager`
- [ ] 4.2 Add `scan(array $categoryNames = []): CleanupResult` — if empty array, scan all categories; else scan only named categories; return result DTO
- [ ] 4.3 Add `purge(array $categoryNames = [], bool $dryRun = false): CleanupResult` — if empty, purge all; else named only; if dryRun, wrap in transaction and ROLLBACK; emit activity event on success (non-dry-run only); invalidate cache; return result DTO
- [ ] 4.4 Add `getCachedScanResult(): ?CleanupResult` — read from ICache with key 'mydash_cleanup_scan'; return null if expired
- [ ] 4.5 Add `setCachedScanResult(CleanupResult $result): void` — write to cache with 5-minute TTL
- [ ] 4.6 Add `invalidateCache(): void` — delete cache entry

## 5. CLI commands

- [ ] 5.1 Create `lib/Command/CleanupScanCommand.php` extending `Command`
  - [ ] Constructor: inject `OrphanedDataCleanupService`
  - [ ] `configure()`: set name, description
  - [ ] `execute()`: call `service.scan()`, render table output with columns: Category | Count
  - [ ] Display total row with sum of all counts
  - [ ] Return exit code 0 if total == 0; exit code 1 if total > 0

- [ ] 5.2 Create `lib/Command/CleanupPurgeCommand.php` extending `Command`
  - [ ] Constructor: inject `OrphanedDataCleanupService`, `IInput`, `IOutput`
  - [ ] Add options: `--category=<name>` (optional), `--dry-run`, `--yes`
  - [ ] `execute()`: 
    - [ ] If no `--yes` flag, prompt: "Delete orphaned data in categories: [list]? (yes/no)"
    - [ ] If user says no, display "Purge cancelled" and exit code 0
    - [ ] Call `service.purge(categoryNames, dryRun)` with options
    - [ ] Render result: "Purged N items across M categories in X ms" (or "DRY-RUN: Would purge..." if dryRun)
    - [ ] Return exit code 0 on success, 1 on error

## 6. API controller

- [ ] 6.1 Create `lib/Controller/AdminCleanupController.php` with dependencies: `OrphanedDataCleanupService`, `IRequest`
- [ ] 6.2 Add `scan()` endpoint (admin-only middleware):
  - [ ] GET /api/admin/cleanup/scan
  - [ ] Call `service.scan()` (auto-uses cache if valid)
  - [ ] Return JSON CleanupResult (or array with totalOrphans, cached, cachedAt, scannedAt fields)
  - [ ] Return HTTP 200 on success, 403 if not admin
- [ ] 6.3 Add `purge()` endpoint (admin-only):
  - [ ] POST /api/admin/cleanup/purge
  - [ ] Parse JSON body: `{categories?: string[], dryRun?: boolean}`
  - [ ] Call `service.purge(categories, dryRun)`
  - [ ] Return JSON with `purgedByCategory`, `totalRows`, `durationMs`, `dryRun` fields
  - [ ] Return HTTP 200 on success, 403 if not admin, 400 if body invalid

## 7. Routes registration

- [ ] 7.1 Update `appinfo/routes.php` to register:
  - [ ] GET /api/admin/cleanup/scan → `AdminCleanupController->scan()`
  - [ ] POST /api/admin/cleanup/purge → `AdminCleanupController->purge()`
  - [ ] Both routes admin-only (use `OCP\Middleware\OCSMiddleware` or equivalent authorization middleware)

## 8. Background job

- [ ] 8.1 Create `lib/Jobs/OrphanedDataCleanupJob.php` extends `TimedJob`
  - [ ] Constructor: inject `OrphanedDataCleanupService`, `IConfig`, `ILogger`
  - [ ] `run()`: 
    - [ ] Read config `mydash.cleanup_auto_purge_categories` (JSON array, default `["expired_locks", "expired_share_tokens"]`)
    - [ ] If empty, log "No categories enabled for auto-purge" and return
    - [ ] Call `service.purge(categories, dryRun=false)`
    - [ ] Log result to ILogger
    - [ ] Activity event emitted by service (see REQ-CLN-009)
  - [ ] Set frequency: daily at 02:00 AM (configurable via admin settings)

- [ ] 8.2 Update `appinfo/backgroundjobs.php` to register the job

## 9. Admin settings

- [ ] 9.1 Create or update `lib/Settings/AdminSettings.php` (or similar) to expose cleanup settings:
  - [ ] Last cleanup run timestamp (read-only)
  - [ ] Auto-purge categories (checkboxes, one per category)
  - [ ] Auto-purge time of day (time picker, default 02:00)
  - [ ] Button: "Run Cleanup Now" (triggers purge with current auto-purge list)

- [ ] 9.2 Update the admin settings admin/cleanup.php template to render the form

## 10. Activity event emission

- [ ] 10.1 In `OrphanedDataCleanupService.purge()`, after successful purge (not dry-run):
  - [ ] Create activity event via `IActivityManager`:
    - [ ] Type: `mydash_cleanup_purge`
    - [ ] Subject: "Orphaned data cleanup"
    - [ ] Message: "Purged N items from categories: [list]"
    - [ ] Metadata: JSON with categories, totalRows, byCategory, durationMs, source (CLI/API/Job)
    - [ ] UserId: current user (or "system" for background job)

## 11. Migration (if needed)

- [ ] 11.1 If cleanup needs to store metadata (e.g., last-run timestamp), create migration file `lib/Migration/VersionXXXXDate2026...php` (typically not needed if using IConfig)

## 12. Testing

- [ ] 12.1 Unit tests for each category class: scan and purge logic under various data states
- [ ] 12.2 Unit tests for `CategoryRegistryService`: registration, lookup, auto-safe filtering
- [ ] 12.3 Unit tests for `OrphanedDataCleanupService`: scan, purge, cache, activity emission
- [ ] 12.4 Integration tests for CLI commands: prompt/confirmation, --yes flag, --category filter, --dry-run
- [ ] 12.5 Integration tests for API endpoints: JSON parsing, per-category purge, dryRun mode, auth checks
- [ ] 12.6 Unit tests for background job: config reading, category filtering, logging
- [ ] 12.7 Verify PHPCS/PHPMD/PHPStan pass; run `composer check:strict`

## 13. Documentation

- [ ] 13.1 Add admin documentation: cleanup overview, CLI commands, API usage, auto-purge configuration
- [ ] 13.2 Add developer documentation: extending cleanup with new categories, registry pattern, category interface

