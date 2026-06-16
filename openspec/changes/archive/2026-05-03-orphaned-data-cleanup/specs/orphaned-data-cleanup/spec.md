---
status: draft
---

# Orphaned Data Cleanup Specification

## Purpose

Provide administrators with a comprehensive, safe, and auditable mechanism to scan for and remove orphaned MyDash data: expired locks and tokens, widget assets from deleted dashboards, metadata-value rows with missing field definitions, placements with no dashboard, tokens for deleted users, role assignments for deleted users/groups, and translations for deleted dashboards. The capability MUST support dry-run (safe preview), per-category selectivity (scan vs. auto-purge), background automation (daily safe-categories job), and audit trails (activity events). A registry pattern enables adding new cleanup categories without editing central code.

## Data Model

Orphaned data exists in multiple tables and file locations. This spec defines what constitutes orphaned and the cleanup strategy for each:

- **expired_locks**: rows in `oc_mydash_dashboard_locks` where `expiresAt < now()`
- **expired_share_tokens**: rows in `oc_mydash_public_shares` where `expiresAt < now()` OR `revokedAt < (now() - 30 days)`
- **orphaned_widget_assets**: files in `MyDash/Imports/*` and `MyDash/icons/*` (Nextcloud file storage) not referenced by any current dashboard or widget config
- **orphaned_metadata_values**: rows in `oc_mydash_metadata_values` where `fieldId` does NOT exist in `oc_mydash_metadata_fields`
- **orphaned_widget_placements**: rows in `oc_mydash_widget_placements` where `dashboardId` does NOT exist in `oc_mydash_dashboards`
- **orphaned_feed_tokens**: rows in `oc_mydash_feed_tokens` where `userId` no longer exists in `oc_users`
- **orphaned_role_assignments**: rows in `oc_mydash_role_assignments` where `userId` (or `groupId`) no longer exists in `oc_users` (or `oc_groups`)
- **dangling_dashboard_translations**: rows in `oc_mydash_dashboard_translations` where `dashboardUuid` no longer exists in `oc_mydash_dashboards`

Cleanup is grouped into three safety tiers:

1. **Tier-A (Auto-safe)**: expired_locks, expired_share_tokens — always safe to auto-purge (no user-visible impact)
2. **Tier-B (Manual-safe)**: orphaned_widget_assets, orphaned_metadata_values, orphaned_widget_placements, orphaned_feed_tokens, dangling_dashboard_translations — safe to purge manually but not auto (require data validation first)
3. **Tier-C (Inspect-first)**: orphaned_role_assignments — purge only after inspection (role-based permissions at stake)

Default auto-purge list: Tier-A (expired_locks, expired_share_tokens).

## ADDED Requirements

### Requirement: REQ-CLN-001 Scan CLI Command

Administrators MUST be able to run a CLI command that reports all orphaned items by category WITHOUT deleting.

#### Scenario: Scan finds orphaned items
- GIVEN a MyDash installation with: 3 expired locks, 2 expired share tokens, 5 orphaned widget assets, 1 orphaned metadata value
- WHEN an administrator runs `php occ mydash:cleanup:scan`
- THEN the system MUST:
  - Query all eight categories (expired_locks, expired_share_tokens, orphaned_widget_assets, orphaned_metadata_values, orphaned_widget_placements, orphaned_feed_tokens, orphaned_role_assignments, dangling_dashboard_translations)
  - Display a table with one row per category, showing category name and count
  - Return exit code 1 (nonzero) to signal that orphans exist
- AND the output MUST include:
  - `expired_locks: 3`
  - `expired_share_tokens: 2`
  - `orphaned_widget_assets: 5`
  - `orphaned_metadata_values: 1`
  - `orphaned_widget_placements: 0`
  - etc.
- AND no data MUST be deleted

#### Scenario: Scan finds no orphans
- GIVEN a clean MyDash installation with no orphaned data
- WHEN an administrator runs `php occ mydash:cleanup:scan`
- THEN the system MUST display a table with all categories showing count 0
- AND return exit code 0 (success, no orphans)

#### Scenario: Scan handles missing tables gracefully
- GIVEN a MyDash installation where a feature (e.g., dashboard-rss-feeds) is not yet enabled
- WHEN `php occ mydash:cleanup:scan` is run
- THEN the system MUST skip categories tied to that feature (e.g., orphaned_feed_tokens)
- AND display remaining categories; return 0 if no other orphans exist

### Requirement: REQ-CLN-002 Purge CLI Command

Administrators MUST be able to run a CLI command to DELETE orphaned items, with confirmation and per-category selectivity.

#### Scenario: Purge all categories with confirmation
- GIVEN a MyDash installation with 3 expired locks and 2 expired share tokens
- WHEN an administrator runs `php occ mydash:cleanup:purge` (no --yes flag)
- THEN the system MUST:
  - Prompt interactively: "Delete orphaned data in categories: [expired_locks, expired_share_tokens, ...]? (yes/no)"
  - Await user input
- AND if the user enters "yes":
  - Delete all orphaned items across all categories
  - Display summary: "Purged 5 items across 2 categories in 123ms"
  - Return exit code 0
- AND if the user enters "no":
  - Cancel without deleting
  - Display "Purge cancelled"
  - Return exit code 0 (user choice, not an error)

#### Scenario: Purge with --yes flag (non-interactive)
- GIVEN the same installation
- WHEN an administrator runs `php occ mydash:cleanup:purge --yes`
- THEN the system MUST:
  - Delete all orphaned items immediately (no prompt)
  - Display summary and return exit code 0

#### Scenario: Purge specific category
- GIVEN 3 expired locks and 2 expired share tokens
- WHEN an administrator runs `php occ mydash:cleanup:purge --category=expired_locks --yes`
- THEN the system MUST:
  - Delete ONLY the 3 expired locks
  - Display summary: "Purged 3 items from category 'expired_locks' in 45ms"
  - Leave the 2 expired share tokens untouched

#### Scenario: Purge non-existent category
- GIVEN a valid cleanup setup
- WHEN an administrator runs `php occ mydash:cleanup:purge --category=invalid_category --yes`
- THEN the system MUST:
  - Display error: "Unknown cleanup category: invalid_category"
  - List valid categories
  - Return exit code 1

### Requirement: REQ-CLN-003 Dry-Run Safety Mode

Both CLI commands MUST support a `--dry-run` flag that reports what WOULD be deleted without actually deleting.

#### Scenario: Scan with dry-run (no-op)
- GIVEN orphaned data exists
- WHEN an administrator runs `php occ mydash:cleanup:scan --dry-run`
- THEN the system MUST:
  - Behave identically to scan without --dry-run (scan is always non-destructive)
  - Display "(dry-run)" label in output if applicable
  - Return appropriate exit code

#### Scenario: Purge with dry-run
- GIVEN 3 expired locks and 2 expired share tokens
- WHEN an administrator runs `php occ mydash:cleanup:purge --dry-run --yes`
- THEN the system MUST:
  - Execute the same deletion queries but wrap in a transaction and ROLLBACK before commit
  - Display: "DRY-RUN: Would purge 5 items across 2 categories in 78ms"
  - Leave all data untouched
  - Return exit code 0

#### Scenario: Dry-run with category filter
- GIVEN 3 expired locks and 2 expired share tokens
- WHEN an administrator runs `php occ mydash:cleanup:purge --category=expired_locks --dry-run --yes`
- THEN the system MUST:
  - Report "DRY-RUN: Would purge 3 items from category 'expired_locks' in 42ms"
  - Delete nothing

### Requirement: REQ-CLN-004 Scan API Endpoint

Administrators MUST be able to call an HTTP API endpoint to retrieve scan results as JSON, with caching.

#### Scenario: GET /api/admin/cleanup/scan returns current orphan counts
- GIVEN a MyDash installation with 3 expired locks
- WHEN an administrator (user with admin role) sends `GET /api/admin/cleanup/scan`
- THEN the system MUST:
  - Return HTTP 200 with JSON body:
    ```json
    {
      "expired_locks": 3,
      "expired_share_tokens": 0,
      "orphaned_widget_assets": 0,
      "orphaned_metadata_values": 0,
      "orphaned_widget_placements": 0,
      "orphaned_feed_tokens": 0,
      "orphaned_role_assignments": 0,
      "dangling_dashboard_translations": 0,
      "totalOrphans": 3,
      "cached": false,
      "cachedAt": null,
      "scannedAt": "2026-05-01T12:34:56Z"
    }
    ```
  - Perform a fresh scan (not cached)

#### Scenario: Subsequent scan API call returns cached results
- GIVEN the scan from the previous scenario completed 30 seconds ago
- WHEN an administrator sends `GET /api/admin/cleanup/scan` again
- THEN the system MUST:
  - Return HTTP 200 with identical counts
  - Include `"cached": true, "cachedAt": "2026-05-01T12:34:56Z"` (original scan time)
  - Use cached result without re-querying database (cached 5 minutes by default)

#### Scenario: Cache is invalidated after purge
- GIVEN a cached scan with 3 expired locks
- WHEN an administrator calls `POST /api/admin/cleanup/purge` and deletes those locks
- THEN the next `GET /api/admin/cleanup/scan` MUST:
  - Perform a fresh scan (cache cleared)
  - Return 0 expired locks
  - Include `"cached": false`

#### Scenario: Non-admin user is denied access
- GIVEN an unauthenticated or non-admin user
- WHEN they send `GET /api/admin/cleanup/scan`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CLN-005 Purge API Endpoint

Administrators MUST be able to POST to an API endpoint to trigger purge with per-category selectivity and dry-run support.

#### Scenario: Purge all categories via API
- GIVEN 3 expired locks and 2 expired share tokens
- WHEN an administrator sends `POST /api/admin/cleanup/purge` with body `{"categories": ["expired_locks", "expired_share_tokens"], "dryRun": false}`
- THEN the system MUST:
  - Delete all 5 items
  - Return HTTP 200 with JSON:
    ```json
    {
      "purgedByCategory": {
        "expired_locks": 3,
        "expired_share_tokens": 2
      },
      "totalRows": 5,
      "durationMs": 123,
      "dryRun": false
    }
    ```

#### Scenario: Purge with dryRun=true
- GIVEN the same data
- WHEN an administrator sends `POST /api/admin/cleanup/purge` with body `{"categories": [...], "dryRun": true}`
- THEN the system MUST:
  - Return the same response structure BUT with `"dryRun": true`
  - Leave all data untouched

#### Scenario: Empty categories array purges all categories
- GIVEN a request body `{"categories": [], "dryRun": false}`
- WHEN the administrator sends `POST /api/admin/cleanup/purge`
- THEN the system MUST:
  - Treat empty array as "all categories"
  - Purge everything
  - Return totals for each category

#### Scenario: Non-admin user is denied access
- GIVEN a non-admin user
- WHEN they send `POST /api/admin/cleanup/purge`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CLN-006 Per-Category Breakdown

The scan and purge results MUST include a per-category breakdown so administrators understand exactly what is being removed.

#### Scenario: Scan output shows detailed breakdown
- GIVEN orphaned data in multiple categories
- WHEN an administrator calls `php occ mydash:cleanup:scan`
- THEN the output table MUST display:
  - Column 1: Category name (e.g., "expired_locks", "orphaned_widget_assets")
  - Column 2: Count (e.g., 3, 0, 5)
  - Alphabetical or logical order
- AND the total row MUST show the sum of all categories

#### Scenario: Purge API returns per-category counts
- GIVEN a POST /api/admin/cleanup/purge request
- WHEN the purge completes
- THEN the response MUST include a `purgedByCategory` object mapping category name to count:
  ```json
  {
    "purgedByCategory": {
      "expired_locks": 3,
      "expired_share_tokens": 2,
      "orphaned_widget_assets": 0,
      ...
    },
    "totalRows": 5
  }
  ```

### Requirement: REQ-CLN-007 Background Job for Auto-Purge

A scheduled daily job MUST run and automatically purge a safe-to-auto-purge subset of categories.

#### Scenario: Daily cleanup job runs and purges Tier-A categories
- GIVEN MyDash is configured with auto-purge categories = ["expired_locks", "expired_share_tokens"]
- AND a background job is scheduled to run daily at 02:00 AM
- WHEN the scheduled time arrives
- THEN the system MUST:
  - Instantiate `OrphanedDataCleanupJob`
  - Call purge with only the configured categories
  - Emit one activity event with metadata (see REQ-CLN-009)
  - Log the result in the MyDash activity log

#### Scenario: Admin changes auto-purge category list
- GIVEN the admin navigates to Settings > Administration > MyDash > Cleanup
- WHEN they uncheck "Auto-purge expired locks" and click Save
- THEN the admin setting `mydash.cleanup_auto_purge_categories` MUST be updated
- AND the next background job run MUST respect the new list

#### Scenario: Auto-purge job is skipped if no categories enabled
- GIVEN admin has unchecked all auto-purge categories
- WHEN the daily job is scheduled to run
- THEN the system MUST:
  - Check the config
  - Find an empty auto-purge list
  - Skip the purge (log: "No categories enabled for auto-purge")
  - Return successfully (exit code 0)

### Requirement: REQ-CLN-008 Safe-to-Auto-Purge List

The implementation MUST define which categories are safe enough to auto-purge without admin inspection.

#### Scenario: Default auto-purge list is Tier-A only
- GIVEN a fresh MyDash installation
- WHEN the admin views Settings > Administration > MyDash > Cleanup
- THEN the "Auto-purge categories" section MUST show:
  - [x] expired_locks (checked by default)
  - [x] expired_share_tokens (checked by default)
  - [ ] orphaned_widget_assets (unchecked)
  - [ ] orphaned_metadata_values (unchecked)
  - [ ] orphaned_widget_placements (unchecked)
  - [ ] orphaned_feed_tokens (unchecked)
  - [ ] orphaned_role_assignments (unchecked)
  - [ ] dangling_dashboard_translations (unchecked)

#### Scenario: Admin opts into more aggressive auto-purge
- GIVEN the admin has enabled auto-purge for "orphaned_widget_assets" as well
- WHEN the daily job runs
- THEN it MUST purge both Tier-A and the additional Tier-B categories

#### Scenario: Scan results indicate which categories are auto-purged
- GIVEN a scan result with the auto-purge config visible
- WHEN the admin reviews the scan output (CLI or API)
- THEN each category SHOULD display a hint or note: "(auto-purged daily)" if enabled in the auto-purge list

### Requirement: REQ-CLN-009 Audit Logging

Every purge operation (CLI, API, or background job) MUST emit exactly one activity event with structured metadata.

#### Scenario: Purge CLI command emits activity event
- GIVEN an administrator runs `php occ mydash:cleanup:purge --category=expired_locks --yes` and deletes 3 locks
- WHEN the purge completes
- THEN the system MUST emit one NC activity event with:
  - Event type: `mydash_cleanup_purge`
  - Subject: "Orphaned data cleanup"
  - Message: "Purged 3 items from category 'expired_locks'"
  - Metadata:
    ```
    {
      "categories": ["expired_locks"],
      "totalRows": 3,
      "byCategory": {"expired_locks": 3},
      "durationMs": 45,
      "userId": "admin_user_id"
    }
    ```
- AND the event MUST appear in the Nextcloud Activity log visible to admins

#### Scenario: API purge emits activity event
- GIVEN an administrator calls `POST /api/admin/cleanup/purge` with multiple categories
- WHEN the purge deletes 5 items
- THEN one activity event MUST be emitted with:
  - Categories: the list sent in the request
  - TotalRows: 5
  - ByCategory: per-category counts
  - Endpoint: "api" (distinguish from CLI)
  - userId: the authenticated admin's ID

#### Scenario: Dry-run purge does NOT emit activity event
- GIVEN a purge with `dryRun=true` that would delete 5 items
- WHEN the purge completes without touching data
- THEN NO activity event MUST be emitted
- AND the operation MUST log locally (if enabled) but not to the activity stream

#### Scenario: Background job purge emits activity event
- GIVEN the daily background job runs and auto-purges 10 items
- WHEN the purge completes
- THEN one activity event MUST be emitted with:
  - Subject: "Orphaned data cleanup (background job)"
  - UserId: "system" or similar
  - Categories: the auto-purge list configured

### Requirement: REQ-CLN-010 Cache Invalidation

Scan results MUST be cached with a 5-minute TTL; cache MUST be invalidated on any successful (non-dry-run) purge.

#### Scenario: Scan results are cached for 5 minutes
- GIVEN a fresh scan at 12:00 PM returning 3 expired locks
- WHEN another scan is requested at 12:01 PM (within TTL)
- THEN the system MUST return cached results (same timestamp, `cached: true`)

#### Scenario: Cache expires after 5 minutes
- GIVEN a cached result from 12:00 PM
- WHEN a new scan is requested at 12:06 PM (after 5 minutes)
- THEN the system MUST discard the cache and perform a fresh scan
- AND return `cached: false`

#### Scenario: Purge invalidates cache
- GIVEN a cached scan result
- WHEN an administrator calls `POST /api/admin/cleanup/purge` and successfully deletes items
- THEN the cache MUST be cleared immediately
- AND the next scan MUST perform a fresh query

#### Scenario: Dry-run purge does NOT invalidate cache
- GIVEN a cached scan result
- WHEN an administrator calls `POST /api/admin/cleanup/purge` with `dryRun=true`
- THEN the cache MUST remain untouched
- AND the next scan MUST return the same cached result

### Requirement: REQ-CLN-011 Registry Pattern Extensibility

Adding a new cleanup category in the future MUST require only creating one new class and registering it; no modifications to central orchestration code.

#### Scenario: New category can be added without editing core classes
- GIVEN that a new feature (e.g., "widget-favorites") introduces a new orphan type
- WHEN a developer creates `lib/Service/Cleanup/OrphanedFavoritesCategory.php` implementing `CleanupCategoryInterface`
- AND registers it in `CategoryRegistryService`
- THEN the system MUST:
  - Automatically include the new category in all scans (CLI, API)
  - Automatically include it in all purges (unless category-filtered)
  - Display it in the admin settings UI and auto-purge list
  - NO changes to `OrphanedDataCleanupService`, command classes, or controller classes

#### Scenario: Category interface contract
- GIVEN the `CleanupCategoryInterface` with methods: `getName(): string`, `getDisplayName(): string`, `getSafeToPurgeAutomatically(): bool`, `scan(): int`, `purge(bool $dryRun = false): int`
- WHEN a new category class implements this interface
- THEN the registry MUST automatically discover and invoke it without modification to calling code

#### Scenario: Backwards-compatible category additions
- GIVEN an existing installation running cleanup regularly
- WHEN a new category is added via a plugin or app update
- THEN the existing CLI commands, API endpoints, and background job MUST work seamlessly with the new category
- AND the admin UI MUST reflect the new category in the next page refresh

