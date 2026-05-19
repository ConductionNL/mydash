<?php

/**
 * OrphanedSharesCategory
 *
 * Cleanup category for rows in `mydash_dashboard_shares` whose
 * `dashboard_id` no longer points at any row in `mydash_dashboards`.
 * REQ-CLN-001.
 *
 * Tier-A (`safeToPurgeAutomatically=true`): a share row whose
 * dashboard no longer exists is unreachable from every code path —
 * the share can never grant access to anything. Deleting it has no
 * user-visible effect. The proposal labelled the broader category
 * "expired_share_tokens"; mydash currently has no expiry/revocation
 * column so the only orphan kind is the dangling-foreign-key kind.
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

use OCA\MyDash\Db\DashboardShareMapper;

/**
 * Sweeps share rows whose dashboard no longer exists.
 */
class OrphanedSharesCategory implements CleanupCategoryInterface
{
    /**
     * Stable category identifier (REQ-CLN-008 default auto-purge list).
     *
     * Named `expired_share_tokens` to match the spec vocabulary even
     * though the current schema has no expiry/revocation columns —
     * the dangling-FK orphan is the only orphan kind shares can
     * currently produce.
     *
     * @var string
     */
    public const NAME = 'expired_share_tokens';

    /**
     * Constructor.
     *
     * @param DashboardShareMapper $shareMapper The share mapper.
     */
    public function __construct(
        private readonly DashboardShareMapper $shareMapper,
    ) {
    }//end __construct()

    /**
     * Stable identifier.
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
        return 'Expired or orphaned share tokens';
    }//end getDisplayName()

    /**
     * Tier-A — always safe to auto-purge. The share is unreachable
     * because its dashboard is gone.
     *
     * @return bool True.
     */
    public function getSafeToPurgeAutomatically(): bool
    {
        return true;
    }//end getSafeToPurgeAutomatically()

    /**
     * Always available — `mydash_dashboard_shares` ships in the core
     * schema.
     *
     * @return bool True.
     */
    public function isAvailable(): bool
    {
        return true;
    }//end isAvailable()

    /**
     * Count orphaned share rows.
     *
     * @return int The orphan count.
     */
    public function scan(): int
    {
        return $this->shareMapper->countOrphaned();
    }//end scan()

    /**
     * Delete orphaned share rows.
     *
     * Dry-run safety is provided by the orchestrator wrapping this
     * call in a transaction and rolling back; this implementation
     * runs the same delete in either mode.
     *
     * @param bool $dryRun True for dry-run.
     *
     * @return int The number of rows deleted.
     */
    public function purge(bool $dryRun=false): int
    {
        return $this->shareMapper->deleteOrphaned();
    }//end purge()
}//end class
