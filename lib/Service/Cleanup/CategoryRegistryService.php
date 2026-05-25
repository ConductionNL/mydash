<?php

/**
 * CategoryRegistryService
 *
 * Holds every {@see CleanupCategoryInterface} implementation that
 * ships with MyDash. Acts as the single point of resolution for the
 * orchestration service, the CLI commands, the API controller and
 * the background job — none of those classes hold direct references
 * to individual category implementations. REQ-CLN-011.
 *
 * Adding a new category therefore requires only:
 *  1. A new class implementing `CleanupCategoryInterface`,
 *  2. Adding the constructor parameter here (or wiring it into the
 *     injected list when the framework's tag-injection is added).
 *
 * Until Nextcloud DI tag-injection is wired we keep an explicit
 * constructor list — it's the same number of edits as a tag binding
 * but stays self-contained inside this file.
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
 * Lookup registry over the cleanup categories.
 */
class CategoryRegistryService
{

    /**
     * The registered categories, keyed by their stable name.
     *
     * @var array<string, CleanupCategoryInterface>
     */
    private array $categories;

    /**
     * Constructor.
     *
     * Each category is injected explicitly so the registry stays
     * compatible with installs that don't have Nextcloud DI tag
     * support. New categories MUST be appended to both the parameter
     * list and the keyed-by-name map.
     *
     * @param ExpiredLocksCategory             $expiredLocks       Tier-A.
     * @param OrphanedSharesCategory           $orphanedShares     Tier-A.
     * @param OrphanedWidgetPlacementsCategory $orphanedPlacements Tier-B.
     * @param OrphanedConditionalRulesCategory $orphanedRules      Tier-B.
     */
    public function __construct(
        ExpiredLocksCategory $expiredLocks,
        OrphanedSharesCategory $orphanedShares,
        OrphanedWidgetPlacementsCategory $orphanedPlacements,
        OrphanedConditionalRulesCategory $orphanedRules,
    ) {
        // Order is significant: it determines the row order of the
        // CLI scan table and the JSON object key order in API
        // responses. Group Tier-A first so the most common entries
        // appear at the top of admin previews.
        $this->categories = [
            $expiredLocks->getName()       => $expiredLocks,
            $orphanedShares->getName()     => $orphanedShares,
            $orphanedPlacements->getName() => $orphanedPlacements,
            $orphanedRules->getName()      => $orphanedRules,
        ];
    }//end __construct()

    /**
     * All registered categories, keyed by their stable name.
     *
     * @return array<string, CleanupCategoryInterface> The categories.
     */
    public function getCategories(): array
    {
        return $this->categories;
    }//end getCategories()

    /**
     * The names of all registered categories, in registration order.
     *
     * @return array<int, string> The names.
     */
    public function getCategoryNames(): array
    {
        return array_keys(array: $this->categories);
    }//end getCategoryNames()

    /**
     * Look up a category by its stable name.
     *
     * Returns `null` when the name is unknown so callers (CLI,
     * controller) can surface a deterministic 400 / "Unknown
     * category" message.
     *
     * @param string $name The category name.
     *
     * @return CleanupCategoryInterface|null The category or null.
     */
    public function getCategoryByName(string $name): ?CleanupCategoryInterface
    {
        return ($this->categories[$name] ?? null);
    }//end getCategoryByName()

    /**
     * The names of every category whose
     * `getSafeToPurgeAutomatically()` returns `true` (REQ-CLN-008).
     *
     * Used as the factory default for the daily background job's
     * auto-purge config and as the "checked by default" set in the
     * admin UI.
     *
     * @return array<int, string> The Tier-A category names.
     */
    /** @spec openspec/specs/orphaned-data-cleanup/spec.md */
    public function getAutoSafeCategoryNames(): array
    {
        $names = [];
        foreach ($this->categories as $name => $category) {
            if ($category->getSafeToPurgeAutomatically() === true) {
                $names[] = $name;
            }
        }

        return $names;
    }//end getAutoSafeCategoryNames()
}//end class
