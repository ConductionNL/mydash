<?php

/**
 * OrphanedWidgetPlacementsCategory
 *
 * Cleanup category for rows in `mydash_widget_placements` whose
 * `dashboard_id` no longer points at any row in `mydash_dashboards`.
 * REQ-CLN-001.
 *
 * Tier-B (`safeToPurgeAutomatically=false`): widget placements carry
 * configuration the user may want to recover (custom titles, icons,
 * style overrides). The proposal flags this category as manual-only
 * so admins can inspect the placements before destroying them.
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

use OCA\MyDash\Db\WidgetPlacementMapper;

/**
 * Sweeps widget placement rows whose dashboard no longer exists.
 */
class OrphanedWidgetPlacementsCategory implements CleanupCategoryInterface
{
    /**
     * Stable category identifier.
     *
     * @var string
     */
    public const NAME = 'orphaned_widget_placements';

    /**
     * Constructor.
     *
     * @param WidgetPlacementMapper $placementMapper The placement mapper.
     */
    public function __construct(
        private readonly WidgetPlacementMapper $placementMapper,
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
     * Human-readable label.
     *
     * @return string The label.
     */
    public function getDisplayName(): string
    {
        return 'Widget placements without a parent dashboard';
    }//end getDisplayName()

    /**
     * Tier-B — placements may carry recoverable user configuration.
     *
     * @return bool False.
     */
    public function getSafeToPurgeAutomatically(): bool
    {
        return false;
    }//end getSafeToPurgeAutomatically()

    /**
     * Always available — `mydash_widget_placements` ships in the core
     * schema.
     *
     * @return bool True.
     */
    public function isAvailable(): bool
    {
        return true;
    }//end isAvailable()

    /**
     * Count orphan placement rows.
     *
     * @return int The orphan count.
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function scan(): int
    {
        return $this->placementMapper->countOrphaned();
    }//end scan()

    /**
     * Delete orphan placement rows.
     *
     * @param bool $dryRun True for dry-run.
     *
     * @return int The number of rows deleted.
     *
     * @spec openspec/specs/orphaned-data-cleanup/spec.md
     */
    public function purge(bool $dryRun=false): int
    {
        return $this->placementMapper->deleteOrphaned();
    }//end purge()
}//end class
