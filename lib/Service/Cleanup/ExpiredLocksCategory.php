<?php

/**
 * ExpiredLocksCategory
 *
 * Cleanup category for stale rows in `mydash_dashboard_locks`. A row
 * is considered stale when its `updated_at` is older than the lock
 * TTL (15 minutes; see `DashboardLock::LOCK_TIMEOUT_SECONDS`). The
 * locking service already removes stale rows inline at acquire/get
 * time, so this category catches locks that were never re-touched
 * because the holding session crashed and never returned. REQ-CLN-001.
 *
 * Tier-A (`safeToPurgeAutomatically=true`): purging an expired lock
 * has no user-visible impact — by definition the holder is gone.
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

use OCA\MyDash\Db\DashboardLockMapper;

/**
 * Sweeps expired dashboard editing locks.
 */
class ExpiredLocksCategory implements CleanupCategoryInterface
{
    /**
     * Stable category identifier (REQ-CLN-008 default auto-purge list).
     *
     * @var string
     */
    public const NAME = 'expired_locks';

    /**
     * Constructor.
     *
     * @param DashboardLockMapper $lockMapper The lock mapper exposing
     *                                        `countAllExpired()` and
     *                                        `deleteAllExpired()`.
     */
    public function __construct(
        private readonly DashboardLockMapper $lockMapper,
    ) {
    }//end __construct()

    /**
     * Stable identifier (REQ-CLN-008).
     *
     * @return string The identifier.
     */
    public function getName(): string
    {
        return self::NAME;
    }//end getName()

    /**
     * Human-readable label for the admin UI.
     *
     * @return string The label.
     */
    public function getDisplayName(): string
    {
        return 'Expired dashboard locks';
    }//end getDisplayName()

    /**
     * Tier-A — always safe to auto-purge. The holding session is by
     * definition gone (TTL exceeded), so no user is ever affected.
     *
     * @return bool True.
     */
    public function getSafeToPurgeAutomatically(): bool
    {
        return true;
    }//end getSafeToPurgeAutomatically()

    /**
     * Always available — `mydash_dashboard_locks` ships in the core
     * schema (Version001005…).
     *
     * @return bool True.
     */
    public function isAvailable(): bool
    {
        return true;
    }//end isAvailable()

    /**
     * Count stale lock rows across all dashboards.
     *
     * @return int The orphan count.
     */
    /** @spec openspec/specs/orphaned-data-cleanup/spec.md */
    public function scan(): int
    {
        return $this->lockMapper->countAllExpired();
    }//end scan()

    /**
     * Delete stale lock rows (or simulate under transaction rollback).
     *
     * The orchestrator wraps the call in a transaction when
     * `$dryRun=true`, so this implementation can use the same
     * delete-everything path for both modes — the rollback handles the
     * "do nothing" promise.
     *
     * @param bool $dryRun True for dry-run.
     *
     * @return int The number of rows deleted (or that would be).
     */
    /** @spec openspec/specs/orphaned-data-cleanup/spec.md */
    public function purge(bool $dryRun=false): int
    {
        return $this->lockMapper->deleteAllExpired();
    }//end purge()
}//end class
