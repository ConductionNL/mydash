# Orphaned Data Cleanup

## Why

MyDash accumulates orphaned data over time: widget assets from deleted dashboards, expired locks and share tokens, metadata-value rows whose field definitions no longer exist, role assignments for deleted users, and other referential integrity issues. Today, there is no standard way to scan for, report on, or safely remove such data, leading to accumulated technical debt and storage waste. A comprehensive scan + purge capability—with safety via dry-run mode, category selectivity, and audit logging—enables administrators to maintain database hygiene without manual SQL or risk of data loss.

## What Changes

- Add `php occ mydash:cleanup:scan` CLI command — report orphaned items by category (expired_locks, expired_share_tokens, orphaned_widget_assets, orphaned_metadata_values, orphaned_widget_placements, orphaned_feed_tokens, orphaned_role_assignments, dangling_dashboard_translations) as a table; return nonzero exit code if any orphans found (useful for CI).
- Add `php occ mydash:cleanup:purge [--category=<name>] [--dry-run] [--yes]` CLI command — delete orphaned items; `--category` limits to one category (default all); `--dry-run` reports what WOULD be deleted; `--yes` skips confirmation prompt (interactive otherwise).
- Add `GET /api/admin/cleanup/scan` endpoint (admin-only) — JSON of the scan output; cached with 5-minute TTL.
- Add `POST /api/admin/cleanup/purge` endpoint (admin-only), body `{categories: [...], dryRun?: boolean}` — triggers purge, returns `{purgedByCategory: {...}, totalRows: N, durationMs: M}`.
- Add daily background job `OrphanedDataCleanupJob` running at configured time, auto-purging a safe-to-auto-purge subset (default `['expired_locks','expired_share_tokens']`).
- Add admin settings in Settings > Administration > MyDash: view last cleanup run, configure auto-purge categories and time of day.
- Emit one NC activity event `mydash_cleanup_purge` per purge run with metadata: categories, totalRows, byUserId, durationMs.
- Per-category extensibility via registry pattern — adding new categories requires one class addition, no central edits.

## Capabilities

### New Capabilities

- `orphaned-data-cleanup`: provides REQ-CLN-001 through REQ-CLN-011 (scan CLI, purge CLI, scan API, purge API, per-category details, background job, safe auto-purge list, dry-run safety, audit logging, cache invalidation, registry extensibility).

### Modified Capabilities

- None. All other MyDash capabilities remain unchanged.

## Impact

**Affected code:**

- `lib/Db/CleanupResult.php` — DTO for scan/purge results
- `lib/Service/OrphanedDataCleanupService.php` — orchestrates scan and purge across all categories
- `lib/Service/Cleanup/CategoryRegistryService.php` — registry holding all cleanup categories
- `lib/Service/Cleanup/CleanupCategoryInterface.php` — interface for per-category scan/purge implementations
- `lib/Service/Cleanup/ExpiredLocksCategory.php` — scan and purge expired locks
- `lib/Service/Cleanup/ExpiredShareTokensCategory.php` — scan and purge expired share tokens
- `lib/Service/Cleanup/OrphanedWidgetAssetsCategory.php` — scan and purge orphaned widget asset files
- `lib/Service/Cleanup/OrphanedMetadataValuesCategory.php` — scan and purge dangling metadata values
- `lib/Service/Cleanup/OrphanedWidgetPlacementsCategory.php` — scan and purge placements with no dashboard
- `lib/Service/Cleanup/OrphanedFeedTokensCategory.php` — scan and purge tokens for deleted users
- `lib/Service/Cleanup/OrphanedRoleAssignmentsCategory.php` — scan and purge role assignments for deleted users/groups
- `lib/Service/Cleanup/DanglingDashboardTranslationsCategory.php` — scan and purge orphaned dashboard translations
- `lib/Jobs/OrphanedDataCleanupJob.php` — scheduled background task
- `lib/Controller/AdminCleanupController.php` — API endpoints
- `lib/Command/CleanupScanCommand.php` — CLI scan command
- `lib/Command/CleanupPurgeCommand.php` — CLI purge command
- `appinfo/routes.php` — register cleanup API routes (admin-only)
- `appinfo/backgroundjobs.php` — register cleanup job
- `lib/Migration/VersionXXXXDate2026...php` — (if needed) any schema changes for cleanup metadata
- `lib/Service/SettingsService.php` — (update) expose cleanup settings (auto-purge categories, last run timestamp)
- `lib/Settings/AdminSettings.php` — (update) MyDash admin settings in Nextcloud Settings > Administration

**Affected APIs:**

- 2 new admin API routes (GET /api/admin/cleanup/scan, POST /api/admin/cleanup/purge)
- No changes to existing dashboard, widget, or permission APIs

**Dependencies:**

- `OCP\ICache` — for caching scan results
- `OCP\IConfig` — for reading/writing cleanup settings
- `OCP\IUserManager` — for resolving user existence
- `OCP\IGroupManager` — for resolving group existence
- `OCP\Activity\IManager` — for emitting audit events
- `OCP\ILogger` — for logging purge operations
- No new composer dependencies

**Migration:**

- No schema changes required (all data already exists in standard tables).
- On first run, creates admin settings with defaults.

**Security:**

- All endpoints and commands are admin-only; no unprivileged access.
- Dry-run mode prevents accidental data loss.
- Audit logging captures all purge operations for compliance.
- Per-category selectivity allows conservative purging (safe categories first, risky categories manual-only).

