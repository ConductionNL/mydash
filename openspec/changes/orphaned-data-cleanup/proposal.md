# Orphaned Data Cleanup Mechanism

## Why

MyDash installations accumulate orphaned data over time: expired dashboard locks, share tokens for deleted dashboards, widget placements for missing dashboards, role assignments for deleted users, and metadata rows with dangling foreign keys. Administrators have no safe, auditable way to discover and remove this debris without direct database access. A comprehensive cleanup mechanism MUST support dry-run (safe preview), per-category selectivity (scan vs. auto-purge), background automation (daily safe-categories job), and audit trails (activity events). The cleanup system MUST be extensible — adding new orphan categories as features grow MUST require only creating one new class implementing a registry interface, with no modifications to the orchestration service, CLI commands, controller, or background job.

## What Changes

- Introduce a `CleanupCategoryInterface` — every cleanup category implements `getName()`, `getDisplayName()`, `getSafeToPurgeAutomatically()`, `isAvailable()`, `scan()`, and `purge(bool $dryRun = false)`.
- Introduce `CategoryRegistryService` that collects all category implementations and exposes `getAutoSafeCategoryNames()` (returns Tier-A categories: expired_locks, expired_share_tokens).
- Introduce `OrphanedDataCleanupService` — the orchestrator for scan/purge operations, supporting per-category filtering and dry-run mode.
- Create nine concrete cleanup category implementations, covering:
  - **Tier-A (Auto-safe, always shipped)**: `ExpiredLocksCleanupCategory`, `ExpiredShareTokensCleanupCategory`
  - **Tier-B (Manual-safe, core shipped)**: `OrphanedWidgetPlacementsCleanupCategory`, `OrphanedConditionalRulesCleanupCategory`
  - **Tier-B (Manual-safe, optional features)**: `OrphanedWidgetAssetsCleanupCategory`, `OrphanedMetadataValuesCleanupCategory`, `OrphanedFeedTokensCleanupCategory`, `DanglingDashboardTranslationsCleanupCategory`
  - **Tier-C (Inspect-first, optional feature)**: `OrphanedRoleAssignmentsCleanupCategory`
- Expose two CLI commands:
  - `php occ mydash:cleanup:scan` — reports orphan counts by category without deleting
  - `php occ mydash:cleanup:purge [--category=<name>] [--dry-run] [--yes]` — deletes with confirmation and safety options
- Expose two REST API endpoints (admin-only):
  - `GET /api/admin/cleanup/scan` — returns scan results as JSON with caching (5-minute TTL)
  - `POST /api/admin/cleanup/purge` — triggers purge with per-category selectivity and dry-run support
- Register `OrphanedDataCleanupJob` as a daily scheduled job that auto-purges Tier-A (default) or admin-configured categories
- Emit exactly one activity event per successful (non-dry-run) purge, regardless of source (CLI, API, background job)
- Cache scan results in distributed cache (300s TTL) — cache is invalidated on any real (non-dry-run) purge

## Capabilities

### New Capabilities

- `orphaned-data-cleanup`: introduces REQ-CLN-001..011, enabling administrators to scan, purge, and automate cleanup of nine orphan categories via CLI, REST API, and scheduled jobs

## Impact

**Affected code:**

- `lib/Db/` — data mappers for each cleanup category (queries for orphan detection)
- `lib/Service/OrphanedDataCleanupService.php` (new) — orchestrator
- `lib/Service/CategoryRegistryService.php` (new) — cleanup category registry
- `lib/Cleanup/CleanupCategoryInterface.php` (new) — interface all categories implement
- `lib/Cleanup/` — 9 concrete cleanup category classes
- `lib/Controller/AdminCleanupController.php` (new) — HTTP endpoints
- `lib/Command/CleanupScanCommand.php` (new) — CLI scan command
- `lib/Command/CleanupPurgeCommand.php` (new) — CLI purge command
- `lib/Job/OrphanedDataCleanupJob.php` (new) — scheduled cleanup job
- `appinfo/routes.php` — register 2 new API routes
- `appinfo/info.xml` — register background job

**Affected APIs:**

- 2 new routes (no existing routes changed, all new)
- All endpoints admin-only; comprehensive scope validation

**Dependencies:**

- Symfony Console (for CLI commands) — already a dependency
- Nextcloud Activity API (for audit logging) — already available
- No new composer or npm dependencies

**Migration:**

- Zero-breaking-change: purely additive feature. Existing dashboards, templates, and data are unaffected.
- First-run scan may take 1-2 seconds on large installs (acceptable for admin CLI/API, cached for subsequent calls)
- Optional features (dashboard-metadata-fields, dashboard-rss-feeds, admin-roles, dashboard-language-content) report categories as unavailable until enabled

**Safe to auto-purge (Tier-A):**
- expired_locks: 15-minute expiry has no user-visible impact; auto-purge is zero-risk
- expired_share_tokens: dangling FKs have no user-visible impact; auto-purge is zero-risk

**Requires manual inspection (Tier-B):**
- orphaned_widget_placements, orphaned_conditional_rules, orphaned_widget_assets, orphaned_metadata_values, orphaned_feed_tokens, dangling_dashboard_translations

**High-risk, admin-review (Tier-C):**
- orphaned_role_assignments: role-based permissions at stake; must be reviewed before purge
