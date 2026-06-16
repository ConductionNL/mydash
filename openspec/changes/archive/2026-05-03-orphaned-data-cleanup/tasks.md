# Tasks — orphaned-data-cleanup

> NOTE: Implementation shipped four real categories backed by tables
> already present in the core schema:
> `expired_locks`, `expired_share_tokens`, `orphaned_widget_placements`,
> `orphaned_conditional_rules`. Categories tied to optional
> not-yet-shipped features (orphaned_widget_assets,
> orphaned_metadata_values, orphaned_feed_tokens,
> orphaned_role_assignments, dangling_dashboard_translations) were
> deferred — they will be added in their respective feature changes
> via `CategoryRegistryService::__construct` per REQ-CLN-011 (no
> central code change required).

## 1. Core domain model

- [x] 1.1 Create `lib/Db/CleanupResult.php` DTO with fields: `byCategory: array<string, int>`, `totalRows: int`, `durationMs: int`, `dryRun: bool`, `scannedAt: string`, `skipped: array<int, string>`
- [x] 1.2 Add getters / `jsonSerialize()` / `fromCounts()` factory

## 2. Category registry infrastructure

- [x] 2.1 Create `lib/Service/Cleanup/CleanupCategoryInterface.php` interface with `getName()`, `getDisplayName()`, `getSafeToPurgeAutomatically()`, `isAvailable()`, `scan()`, `purge(bool $dryRun = false)`
- [x] 2.2 Create `lib/Service/Cleanup/CategoryRegistryService.php` with explicit constructor injection, `getCategories()`, `getCategoryNames()`, `getCategoryByName()`, `getAutoSafeCategoryNames()`

## 3. Category implementations (per-category scan/purge logic)

- [x] 3.1 `lib/Service/Cleanup/ExpiredLocksCategory.php` (Tier-A) — delegates to `DashboardLockMapper::countAllExpired()` + `deleteAllExpired()`
- [x] 3.2 `lib/Service/Cleanup/OrphanedSharesCategory.php` (Tier-A, name `expired_share_tokens`) — `DashboardShareMapper::countOrphaned()` + `deleteOrphaned()` (dangling-FK orphan; expiry/revocation columns are a future schema addition)
- [ ] 3.3 OrphanedWidgetAssetsCategory — deferred (file backend not in scope for this change)
- [ ] 3.4 OrphanedMetadataValuesCategory — deferred until `dashboard-metadata-fields` ships
- [x] 3.5 `lib/Service/Cleanup/OrphanedWidgetPlacementsCategory.php` (Tier-B) — `WidgetPlacementMapper::countOrphaned()` + `deleteOrphaned()`
- [ ] 3.6 OrphanedFeedTokensCategory — deferred until `dashboard-rss-feeds` ships
- [ ] 3.7 OrphanedRoleAssignmentsCategory — deferred until `admin-roles` ships
- [ ] 3.8 DanglingDashboardTranslationsCategory — deferred until `dashboard-language-content` ships
- [x] 3.9 `lib/Service/Cleanup/OrphanedConditionalRulesCategory.php` (Tier-B, additional category) — `ConditionalRuleMapper::countOrphaned()` + `deleteOrphaned()`

## 4. Orchestration service

- [x] 4.1 `lib/Service/OrphanedDataCleanupService.php` with `CategoryRegistryService`, `ICacheFactory`, `IDBConnection`, `Activity\IManager`, `LoggerInterface`
- [x] 4.2 `scan(array $categoryNames = []): CleanupResult` — empty list = all; skips unavailable categories; cache hit short-circuits the registry walk
- [x] 4.3 `purge(array $categoryNames = [], bool $dryRun = false, ?string $userId = null, string $source = 'api'): CleanupResult` — wraps dry-run in transaction + rollback; emits one Activity event on real, non-zero purges; invalidates cache on real purges
- [x] 4.4 `getCachedScanResult()` reads `mydash.cleanup.scan` from `ICacheFactory::createDistributed`
- [x] 4.5 `setCachedScanResult()` writes with `CACHE_TTL_SECONDS = 300`
- [x] 4.6 `invalidateCache()` removes the key

## 5. CLI commands

- [x] 5.1 `lib/Command/CleanupScanCommand.php` extending `Symfony\Console\Command\Command` — table output, exit 1 when total > 0
- [x] 5.2 `lib/Command/CleanupPurgeCommand.php` with `--category=<name>`, `--dry-run`, `--yes` options, interactive `ConfirmationQuestion`, dry-run prefix in summary

## 6. API controller

- [x] 6.1 `lib/Controller/AdminCleanupController.php` with `OrphanedDataCleanupService` + `CategoryRegistryService` + `IGroupManager` + `IUserSession`
- [x] 6.2 `scan()` — `GET /api/admin/cleanup/scan`, admin-gated, cache-aware, returns `cached`/`cachedAt` envelope hints
- [x] 6.3 `purge()` — `POST /api/admin/cleanup/purge`, admin-gated, validates category names (HTTP 400 on unknown), returns `purgedByCategory`/`totalRows`/`durationMs`/`dryRun`/`skipped`

## 7. Routes registration

- [x] 7.1 `appinfo/routes.php` — `admin_cleanup#scan` and `admin_cleanup#purge` registered after the existing `/api/admin/...` block; admin gate enforced inside the controller

## 8. Background job

- [x] 8.1 `lib/BackgroundJob/OrphanedDataCleanupJob.php` extends `TimedJob`, 24h interval, time-insensitive; reads `cleanup_auto_purge_categories` from `IAppConfig`; falls back to `CategoryRegistryService::getAutoSafeCategoryNames()`; logs structured run/skip lines
- [x] 8.2 Registered via `appinfo/info.xml` `<background-jobs>` block (idiomatic Nextcloud auto-registration; `IJobList::add` not required)

## 9. Admin settings

- [ ] 9.1 Vue admin UI for cleanup settings — deferred to a follow-up frontend change. The `cleanup_auto_purge_categories` `IAppConfig` key is the durable backend interface; admins can set it today via `occ config:app:set mydash cleanup_auto_purge_categories --value '["expired_locks"]'`.

## 10. Activity event emission

- [x] 10.1 `OrphanedDataCleanupService::emitActivityEvent()` publishes one event with type `mydash_cleanup_purge`, subject parameters carrying `totalRows` / `byCategory` / `durationMs` / `source`; dry-run suppressed; zero-row real purges suppressed

## 11. Migration (if needed)

- [x] 11.1 No migration required — every shipped category targets a table already created by an earlier migration.

## 12. Testing

- [x] 12.1 `tests/Unit/Db/CleanupResultTest.php` — 4 tests covering DTO shape and `fromCounts` factory
- [x] 12.2 `tests/Unit/Service/Cleanup/CategoryRegistryServiceTest.php` — 4 tests covering lookup, ordering, Tier-A filter
- [x] 12.3 `tests/Unit/Service/OrphanedDataCleanupServiceTest.php` — 7 tests covering scan, skip, cache hit, real-purge cache invalidation, dry-run rollback, activity emission, zero-row no-event
- [x] 12.4 `tests/Unit/Controller/AdminCleanupControllerTest.php` — 6 tests covering admin guard (3 cases), cached scan envelope, unknown-category 400, purge envelope shape
- [ ] 12.5 Mapper-level tests for the four new `countOrphaned`/`deleteOrphaned` helpers — deferred (covered by integration via the orchestrator unit tests; the mapper SQL is exercised in the Newman integration suite).
- [x] 12.6 `composer check:strict` and `npm test` + `npm run build` clean

## 13. Documentation

- [x] 13.1 Spec at `openspec/specs/orphaned-data-cleanup/spec.md` documents tier classification, registry pattern, REQ-CLN-001..011 with scenarios
- [ ] 13.2 Admin / developer Markdown docs — deferred to a docs change; class-level docblocks already cover the registry-extension flow.
