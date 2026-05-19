---
status: implemented
---

# Orphaned Data Cleanup Specification

## Purpose

Provide administrators with a comprehensive, safe, and auditable mechanism to scan for and remove orphaned MyDash data: expired locks and tokens, widget assets from deleted dashboards, metadata-value rows with missing field definitions, placements with no dashboard, tokens for deleted users, role assignments for deleted users/groups, and translations for deleted dashboards. The capability MUST support dry-run (safe preview), per-category selectivity (scan vs. auto-purge), background automation (daily safe-categories job), and audit trails (activity events). A registry pattern enables adding new cleanup categories without editing central code.

## Data Model

Orphaned data exists in multiple tables and file locations. This spec defines what constitutes orphaned and the cleanup strategy for each:

- **expired_locks**: rows in `oc_mydash_dashboard_locks` where `updated_at` is older than `now - LOCK_TIMEOUT` (15 minutes).
- **expired_share_tokens**: rows in `oc_mydash_dashboard_shares` whose `dashboard_id` no longer points at any row in `oc_mydash_dashboards`. The MyDash share schema has no expiry/revocation columns at the time of writing; the dangling-FK orphan is the only orphan kind shares can currently produce. Future schema additions (revoked_at, expires_at) MAY extend this category without breaking the contract.
- **orphaned_widget_assets**: files in `MyDash/Imports/*` and `MyDash/icons/*` (Nextcloud file storage) not referenced by any current dashboard or widget config. Optional; may be skipped on installs where the file backend is not provisioned.
- **orphaned_metadata_values**: rows in `oc_mydash_metadata_values` where `fieldId` does NOT exist in `oc_mydash_metadata_fields`. Optional; available only on installs with the dashboard-metadata-fields feature.
- **orphaned_widget_placements**: rows in `oc_mydash_widget_placements` whose `dashboard_id` does NOT exist in `oc_mydash_dashboards`.
- **orphaned_conditional_rules**: rows in `oc_mydash_conditional_rules` whose `widget_placement_id` does NOT exist in `oc_mydash_widget_placements`.
- **orphaned_feed_tokens**: rows in `oc_mydash_feed_tokens` where `userId` no longer exists in `oc_users`. Optional; available only on installs with the dashboard-rss-feeds feature.
- **orphaned_role_assignments**: rows in `oc_mydash_role_assignments` where `userId` (or `groupId`) no longer exists in `oc_users` (or `oc_groups`). Optional; available only on installs with the admin-roles feature.
- **dangling_dashboard_translations**: rows in `oc_mydash_dash_translations` where `dashboardUuid` no longer exists in `oc_mydash_dashboards`. Optional; available only on installs with the dashboard-language-content feature.

Categories that map to optional features MUST report `isAvailable() === false` on installs where the feature is not present, so the orchestrator can skip them cleanly (REQ-CLN-001 "Scan handles missing tables gracefully") without erroring on a missing-table SQL fault. The shipped four categories (expired_locks, expired_share_tokens, orphaned_widget_placements, orphaned_conditional_rules) are always available because their tables are part of the core schema.

Cleanup is grouped into three safety tiers:

1. **Tier-A (Auto-safe)**: expired_locks, expired_share_tokens — always safe to auto-purge (no user-visible impact)
2. **Tier-B (Manual-safe)**: orphaned_widget_assets, orphaned_metadata_values, orphaned_widget_placements, orphaned_conditional_rules, orphaned_feed_tokens, dangling_dashboard_translations — safe to purge manually but not auto (require data validation first)
3. **Tier-C (Inspect-first)**: orphaned_role_assignments — purge only after inspection (role-based permissions at stake)

Default auto-purge list: Tier-A (expired_locks, expired_share_tokens). Returned by `CategoryRegistryService::getAutoSafeCategoryNames()` and used as the default for both the daily background job and the admin settings UI.

## Requirements

### Requirement: REQ-CLN-001 Scan CLI Command

Administrators MUST be able to run a CLI command that reports all orphaned items by category WITHOUT deleting.

#### Scenario: Scan finds orphaned items

- GIVEN a MyDash installation with: 3 expired locks, 2 orphaned share tokens, 1 orphaned widget placement
- WHEN an administrator runs `php occ mydash:cleanup:scan`
- THEN the system MUST:
  - Query every registered category whose `isAvailable()` returns `true`
  - Display a table with one row per category, showing category name and count
  - Return exit code 1 (nonzero) to signal that orphans exist
- AND the output MUST include rows for every shipped category with their counts and a TOTAL row

#### Scenario: Scan finds no orphans

- GIVEN a clean MyDash installation with no orphaned data
- WHEN an administrator runs `php occ mydash:cleanup:scan`
- THEN the system MUST display a table with all categories showing count 0
- AND return exit code 0 (success, no orphans)

#### Scenario: Scan handles missing tables gracefully

- GIVEN a MyDash installation where a feature (e.g., dashboard-rss-feeds) is not yet enabled
- WHEN `php occ mydash:cleanup:scan` is run
- THEN the system MUST skip categories whose `CleanupCategoryInterface::isAvailable()` returns `false`
- AND list the skipped categories under a "Skipped categories" comment line
- AND return 0 if no other orphans exist

### Requirement: REQ-CLN-002 Purge CLI Command

Administrators MUST be able to run a CLI command to DELETE orphaned items, with confirmation and per-category selectivity.

#### Scenario: Purge all categories with confirmation

- GIVEN a MyDash installation with orphaned data across multiple categories
- WHEN an administrator runs `php occ mydash:cleanup:purge` (no --yes flag)
- THEN the system MUST prompt interactively listing every effective category
- AND if the user confirms (`y`), delete all orphaned items and display a summary `Purged N items across M categories in Xms.`
- AND if the user declines, display `Purge cancelled.` and exit 0 without deleting

#### Scenario: Purge with --yes flag (non-interactive)

- GIVEN orphaned data exists
- WHEN an administrator runs `php occ mydash:cleanup:purge --yes`
- THEN the system MUST delete all orphaned items immediately (no prompt) and exit 0

#### Scenario: Purge specific category

- GIVEN orphaned data across multiple categories
- WHEN an administrator runs `php occ mydash:cleanup:purge --category=expired_locks --yes`
- THEN the system MUST delete only orphans in `expired_locks`
- AND display `Purged N items from category 'expired_locks' in Xms.`

#### Scenario: Purge non-existent category

- GIVEN a valid cleanup setup
- WHEN an administrator runs `php occ mydash:cleanup:purge --category=invalid_category --yes`
- THEN the system MUST display an error naming the invalid category, list valid categories, and return exit code 1

### Requirement: REQ-CLN-003 Dry-Run Safety Mode

The purge CLI command MUST support a `--dry-run` flag that reports what WOULD be deleted without actually deleting. The scan command is always non-destructive.

#### Scenario: Purge with dry-run

- GIVEN orphaned data exists
- WHEN an administrator runs `php occ mydash:cleanup:purge --dry-run --yes`
- THEN the system MUST execute the deletion queries inside a transaction wrapper that is rolled back before commit
- AND display `DRY-RUN: Would purge N items across M categories in Xms.`
- AND leave all data untouched

#### Scenario: Dry-run with category filter

- GIVEN orphans in multiple categories
- WHEN an administrator runs `php occ mydash:cleanup:purge --category=expired_locks --dry-run --yes`
- THEN the system MUST report `DRY-RUN: Would purge N items from category 'expired_locks' in Xms.`
- AND delete nothing

### Requirement: REQ-CLN-004 Scan API Endpoint

Administrators MUST be able to call an HTTP API endpoint to retrieve scan results as JSON, with caching.

#### Scenario: GET /api/admin/cleanup/scan returns current orphan counts

- GIVEN a MyDash installation with orphans
- WHEN an administrator (admin role) sends `GET /api/admin/cleanup/scan`
- THEN the system MUST return HTTP 200 with a JSON body containing `byCategory`, `totalRows`, `durationMs`, `dryRun`, `scannedAt`, `skipped`, `cached`, and `cachedAt` fields
- AND, when no cache entry is present, perform a fresh scan and return `cached: false`

#### Scenario: Subsequent scan API call returns cached results

- GIVEN the previous scan completed within `CACHE_TTL_SECONDS` (300s)
- WHEN an administrator sends `GET /api/admin/cleanup/scan` again
- THEN the system MUST return the same counts with `cached: true` and `cachedAt` set to the original `scannedAt`

#### Scenario: Cache is invalidated after purge

- GIVEN a cached scan
- WHEN an administrator calls `POST /api/admin/cleanup/purge` with `dryRun=false`
- THEN the next `GET /api/admin/cleanup/scan` MUST perform a fresh scan and return `cached: false`

#### Scenario: Non-admin user is denied access

- GIVEN an unauthenticated or non-admin user
- WHEN they send `GET /api/admin/cleanup/scan`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CLN-005 Purge API Endpoint

Administrators MUST be able to POST to an API endpoint to trigger purge with per-category selectivity and dry-run support.

#### Scenario: Purge categories via API

- GIVEN orphaned data exists
- WHEN an administrator sends `POST /api/admin/cleanup/purge` with body `{"categories": ["expired_locks", "expired_share_tokens"], "dryRun": false}`
- THEN the system MUST delete the orphans in those categories and return HTTP 200 with `purgedByCategory`, `totalRows`, `durationMs`, `dryRun`, and `skipped` fields

#### Scenario: Purge with dryRun=true

- GIVEN orphaned data exists
- WHEN an administrator sends `POST /api/admin/cleanup/purge` with `dryRun: true`
- THEN the system MUST return the same envelope shape with `dryRun: true` and leave all data untouched

#### Scenario: Empty categories array purges all categories

- GIVEN a body `{"categories": [], "dryRun": false}`
- WHEN the administrator sends `POST /api/admin/cleanup/purge`
- THEN the system MUST treat the empty list as "all registered categories" and return per-category counts for the full set

#### Scenario: Unknown category yields HTTP 400

- GIVEN a body referencing an unregistered category name
- WHEN the administrator sends `POST /api/admin/cleanup/purge`
- THEN the system MUST return HTTP 400 with an `error` message and a `validCategories` list

#### Scenario: Non-admin user is denied access

- GIVEN a non-admin user
- WHEN they send `POST /api/admin/cleanup/purge`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CLN-006 Per-Category Breakdown

The scan and purge results MUST include a per-category breakdown so administrators understand exactly what is being removed.

#### Scenario: Scan output shows detailed breakdown

- GIVEN orphaned data in multiple categories
- WHEN an administrator calls `php occ mydash:cleanup:scan`
- THEN the output table MUST display one row per category with name and count, in registration order
- AND a TOTAL row MUST follow with the sum of all categories

#### Scenario: Purge API returns per-category counts

- GIVEN a `POST /api/admin/cleanup/purge` request
- WHEN the purge completes
- THEN the response MUST include a `purgedByCategory` object mapping category name to count, plus `totalRows`

### Requirement: REQ-CLN-007 Background Job for Auto-Purge

A scheduled daily job MUST run and automatically purge a safe-to-auto-purge subset of categories.

#### Scenario: Daily cleanup job runs and purges Tier-A categories

- GIVEN the `mydash` app config `cleanup_auto_purge_categories` is empty (factory default)
- AND the `OrphanedDataCleanupJob` is registered with a 24-hour interval
- WHEN the scheduled time arrives
- THEN the job MUST resolve the auto-purge list via `CategoryRegistryService::getAutoSafeCategoryNames()` (Tier-A)
- AND call `OrphanedDataCleanupService::purge` with that list and `source='job'`
- AND the orchestrator MUST emit one activity event when the total purge count is non-zero

#### Scenario: Admin overrides auto-purge category list

- GIVEN the admin sets `cleanup_auto_purge_categories` to a JSON-encoded list (e.g. `["expired_locks"]`)
- WHEN the daily job runs
- THEN the job MUST purge only the categories in the configured list
- AND skip unknown / non-string entries silently

#### Scenario: Auto-purge job is skipped if no categories enabled

- GIVEN `cleanup_auto_purge_categories` is set to an empty JSON array (`"[]"`)
- WHEN the daily job is scheduled to run
- THEN the job MUST resolve the list to `[]`, log `mydash.cleanup.job_skipped reason=no_categories_enabled`, and return without invoking the orchestrator

### Requirement: REQ-CLN-008 Safe-to-Auto-Purge List

The implementation MUST define which categories are safe enough to auto-purge without admin inspection. The shipped Tier-A list MUST contain exactly the two zero-impact categories: `expired_locks` and `expired_share_tokens`. Each category exposes its tier through `CleanupCategoryInterface::getSafeToPurgeAutomatically()`.

#### Scenario: Default auto-purge list is Tier-A only

- GIVEN a fresh MyDash installation with no `cleanup_auto_purge_categories` config value
- WHEN the daily job resolves its auto-purge list
- THEN the system MUST default to `["expired_locks", "expired_share_tokens"]` as returned by `CategoryRegistryService::getAutoSafeCategoryNames()`

#### Scenario: Admin opts into more aggressive auto-purge

- GIVEN the admin sets `cleanup_auto_purge_categories` to include a Tier-B category
- WHEN the daily job runs
- THEN it MUST purge that category in addition to the configured Tier-A entries

### Requirement: REQ-CLN-009 Audit Logging

Every successful real (non-dry-run) purge that affects at least one row MUST emit exactly one Nextcloud Activity event with structured metadata. Dry-run purges MUST NOT emit an Activity event. Both real and dry-run purges write a structured PSR-3 log line for cluster operators.

#### Scenario: Purge CLI command emits activity event

- GIVEN an administrator runs `php occ mydash:cleanup:purge --category=expired_locks --yes` and deletes 3 locks
- WHEN the purge completes
- THEN the system MUST publish exactly one activity event with:
  - app: `mydash`
  - type: `mydash_cleanup_purge`
  - subject parameters: `totalRows`, `byCategory`, `durationMs`, `source: 'cli'`
- AND the event MUST appear in the Nextcloud Activity log visible to admins

#### Scenario: API purge emits activity event

- GIVEN an administrator calls `POST /api/admin/cleanup/purge` and deletes 5 items
- WHEN the purge succeeds
- THEN one activity event MUST be emitted with `source: 'api'` and the authenticated admin's UID under `affectedUser`/`author`

#### Scenario: Dry-run purge does NOT emit activity event

- GIVEN a purge with `dryRun=true` that would delete 5 items
- WHEN the purge completes without touching data
- THEN NO activity event MUST be emitted

#### Scenario: Background job purge emits activity event

- GIVEN the daily background job runs and auto-purges N > 0 items
- WHEN the purge completes
- THEN exactly one activity event MUST be emitted with `source: 'job'`

### Requirement: REQ-CLN-010 Cache Invalidation

Scan results MUST be cached in the distributed cache (`ICacheFactory::createDistributed`) under the key `mydash.cleanup.scan` for `CACHE_TTL_SECONDS` (300s); the cache MUST be invalidated on any successful (non-dry-run) purge. Partial scans (with a non-empty category-name filter) bypass the cache entirely so they cannot pollute the full-set entry.

#### Scenario: Scan results are cached for 5 minutes

- GIVEN a fresh full scan stored in the cache at T
- WHEN another scan is requested at T+30s (within TTL)
- THEN the system MUST return cached results with `cached: true` and `cachedAt` equal to the original `scannedAt`

#### Scenario: Cache expires after the TTL

- GIVEN a cached result from T
- WHEN a new scan is requested at T+CACHE_TTL_SECONDS+1
- THEN the system MUST discard the cache and perform a fresh scan returning `cached: false`

#### Scenario: Real purge invalidates cache

- GIVEN a cached scan result
- WHEN an administrator calls `POST /api/admin/cleanup/purge` with `dryRun=false` (success)
- THEN the cache MUST be cleared immediately (`ICache::remove(key: 'mydash.cleanup.scan')`)

#### Scenario: Dry-run purge does NOT invalidate cache

- GIVEN a cached scan result
- WHEN an administrator calls `POST /api/admin/cleanup/purge` with `dryRun=true`
- THEN the cache MUST remain untouched

### Requirement: REQ-CLN-011 Registry Pattern Extensibility

Adding a new cleanup category MUST require only creating one new class implementing `CleanupCategoryInterface` and adding it to `CategoryRegistryService::__construct`; no modifications to the orchestration service, controller, CLI commands, or background job classes.

#### Scenario: New category can be added without editing core classes

- GIVEN a new feature introduces a new orphan type
- WHEN a developer creates a class implementing `CleanupCategoryInterface` and binds it into `CategoryRegistryService`
- THEN the system MUST automatically include the new category in scans, purges, the daily auto-purge default list (if Tier-A), CLI listings, and API responses
- AND no changes to `OrphanedDataCleanupService`, `AdminCleanupController`, `CleanupScanCommand`, `CleanupPurgeCommand`, or `OrphanedDataCleanupJob` are required

#### Scenario: Category interface contract

- GIVEN the `CleanupCategoryInterface` with methods `getName()`, `getDisplayName()`, `getSafeToPurgeAutomatically()`, `isAvailable()`, `scan()`, and `purge(bool $dryRun = false)`
- WHEN a new class implements this interface
- THEN the registry MUST discover it via constructor injection and the orchestrator MUST invoke it without modification to calling code

#### Scenario: Backwards-compatible category additions

- GIVEN an existing installation running cleanup regularly
- WHEN a new category is added via a code update
- THEN existing CLI commands, API endpoints, and the background job MUST work unchanged with the new category, picking it up automatically on the next request
