<?php

/**
 * OrphanedConditionalRulesCategory
 *
 * Cleanup category for rows in `mydash_conditional_rules` whose
 * `widget_placement_id` no longer points at any row in
 * `mydash_widget_placements`. REQ-CLN-001.
 *
 * Tier-B (`safeToPurgeAutomatically=false`): conditional rules
 * encode admin-authored visibility logic the user may want to
 * recover by attaching the rule to a new placement, so the proposal
 * keeps this category off the auto-purge defaults.
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

use OCA\MyDash\Db\ConditionalRuleMapper;

/**
 * Sweeps conditional-rule rows whose placement no longer exists.
 */
class OrphanedConditionalRulesCategory implements CleanupCategoryInterface
{
    /**
     * Stable category identifier.
     *
     * @var string
     */
    public const NAME = 'orphaned_conditional_rules';

    /**
     * Constructor.
     *
     * @param ConditionalRuleMapper $ruleMapper The rule mapper.
     */
    public function __construct(
        private readonly ConditionalRuleMapper $ruleMapper,
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
        return 'Conditional rules without a parent placement';
    }//end getDisplayName()

    /**
     * Tier-B — admin-authored visibility logic is potentially worth
     * recovering, so this category is opt-in only.
     *
     * @return bool False.
     */
    public function getSafeToPurgeAutomatically(): bool
    {
        return false;
    }//end getSafeToPurgeAutomatically()

    /**
     * Always available — `mydash_conditional_rules` ships in the core
     * schema.
     *
     * @return bool True.
     */
    public function isAvailable(): bool
    {
        return true;
    }//end isAvailable()

    /**
     * Count orphan conditional-rule rows.
     *
     * @return int The orphan count.
     */
    /** @spec openspec/specs/orphaned-data-cleanup/spec.md */
    public function scan(): int
    {
        return $this->ruleMapper->countOrphaned();
    }//end scan()

    /**
     * Delete orphan conditional-rule rows.
     *
     * @param bool $dryRun True for dry-run.
     *
     * @return int The number of rows deleted.
     */
    /** @spec openspec/specs/orphaned-data-cleanup/spec.md */
    public function purge(bool $dryRun=false): int
    {
        return $this->ruleMapper->deleteOrphaned();
    }//end purge()
}//end class
