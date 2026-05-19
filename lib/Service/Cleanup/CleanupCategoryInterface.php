<?php

/**
 * CleanupCategoryInterface
 *
 * Contract for an individual orphaned-data cleanup category. The
 * registry collects every implementation and runs them in sequence;
 * adding a new category requires only one new class implementing this
 * interface plus a constructor binding in
 * {@see \OCA\MyDash\Service\Cleanup\CategoryRegistryService}. No
 * central code change required (REQ-CLN-011).
 *
 * Each category is responsible for:
 *  - reporting its stable identifier (used in CLI/API filters and
 *    auto-purge config),
 *  - reporting a human-readable label for the admin UI,
 *  - declaring whether it is safe to auto-purge (Tier-A vs Tier-B/C),
 *  - declaring whether the underlying feature/table is currently
 *    available (so categories tied to optional features skip cleanly
 *    on installs that don't have them yet — REQ-CLN-001 "Scan handles
 *    missing tables gracefully"),
 *  - counting orphaned rows (`scan`),
 *  - deleting orphaned rows or simulating it under a transaction
 *    rollback (`purge` with `dryRun=true`) — REQ-CLN-003.
 *
 * Implementations MUST be side-effect-free during `scan()` — the scan
 * path is the input to admin previews and to the `--dry-run` flag.
 *
 * @category  Service
 * @package   OCA\MyDash\Service\Cleanup
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service\Cleanup;

/**
 * One detector + purger for a single orphan category.
 */
interface CleanupCategoryInterface
{
    /**
     * Stable category identifier (snake_case).
     *
     * Used in CLI `--category=<name>` filters, API request bodies,
     * cached scan keys and the auto-purge config list. MUST stay
     * unchanged across releases or admin configurations referencing
     * this category will silently fall through.
     *
     * @return string The identifier (e.g. "expired_locks").
     */
    public function getName(): string;

    /**
     * Human-readable label for the admin UI.
     *
     * @return string The display label.
     */
    public function getDisplayName(): string;

    /**
     * Whether this category is in the Tier-A "safe to auto-purge"
     * default set (REQ-CLN-007, REQ-CLN-008).
     *
     * Tier-A categories are pre-checked in the daily background job's
     * default config; Tier-B/C return `false` and require an explicit
     * admin opt-in.
     *
     * @return bool True for Tier-A categories.
     */
    public function getSafeToPurgeAutomatically(): bool;

    /**
     * Whether the underlying feature / table is currently available.
     *
     * Categories tied to optional features (RSS feeds, role
     * assignments, language variants, ...) MUST return `false` when
     * the relevant feature has not been provisioned, so the registry
     * can mark them as `skipped` instead of erroring with a missing
     * table SQL fault. REQ-CLN-001 "Scan handles missing tables
     * gracefully".
     *
     * Always-on categories tied to existing core tables MUST return
     * `true` unconditionally.
     *
     * @return bool True when the category can be scanned/purged.
     */
    public function isAvailable(): bool;

    /**
     * Count orphaned rows for this category.
     *
     * MUST NOT delete or modify any data; the scan path is the input
     * to dry-run previews. SHOULD return 0 (rather than throwing) when
     * there are no orphans.
     *
     * @return int The orphaned row count (>= 0).
     */
    public function scan(): int;

    /**
     * Delete orphaned rows for this category.
     *
     * When `$dryRun` is `true`, the implementation MUST NOT change any
     * persisted state — the orchestrator wraps the call in a
     * transaction and rolls back, so a category that performs
     * non-DB side effects (file deletion, external calls) MUST gate
     * those side effects on `$dryRun === false`.
     *
     * Returns the number of rows that were deleted (or that would
     * have been deleted, in dry-run).
     *
     * @param bool $dryRun True for simulation, false for real delete.
     *
     * @return int The number of rows affected (>= 0).
     */
    public function purge(bool $dryRun=false): int;
}//end interface
