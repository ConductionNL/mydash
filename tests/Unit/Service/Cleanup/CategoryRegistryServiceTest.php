<?php

/**
 * CategoryRegistryService Test
 *
 * Verifies the registry's lookup, listing and Tier-A filter
 * semantics. The registry is the cornerstone of REQ-CLN-011 (the
 * "add a new category without touching central code" guarantee), so
 * these tests pin down the contract that implementations rely on.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service\Cleanup
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service\Cleanup;

use OCA\MyDash\Db\ConditionalRuleMapper;
use OCA\MyDash\Db\DashboardLockMapper;
use OCA\MyDash\Db\DashboardShareMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCA\MyDash\Service\Cleanup\ExpiredLocksCategory;
use OCA\MyDash\Service\Cleanup\OrphanedConditionalRulesCategory;
use OCA\MyDash\Service\Cleanup\OrphanedSharesCategory;
use OCA\MyDash\Service\Cleanup\OrphanedWidgetPlacementsCategory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CategoryRegistryService.
 */
class CategoryRegistryServiceTest extends TestCase
{
    /**
     * The registry under test.
     *
     * @var CategoryRegistryService
     */
    private CategoryRegistryService $registry;

    /**
     * Build a registry with mapper-mocks for each category.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->registry = new CategoryRegistryService(
            expiredLocks: new ExpiredLocksCategory(
                lockMapper: $this->createMock(originalClassName: DashboardLockMapper::class)
            ),
            orphanedShares: new OrphanedSharesCategory(
                shareMapper: $this->createMock(originalClassName: DashboardShareMapper::class)
            ),
            orphanedPlacements: new OrphanedWidgetPlacementsCategory(
                placementMapper: $this->createMock(originalClassName: WidgetPlacementMapper::class)
            ),
            orphanedRules: new OrphanedConditionalRulesCategory(
                ruleMapper: $this->createMock(originalClassName: ConditionalRuleMapper::class)
            ),
        );
    }

    /**
     * `getCategoryNames` exposes the four shipped categories in
     * registration order with stable identifiers.
     *
     * @return void
     */
    public function testGetCategoryNamesReturnsRegisteredOrder(): void
    {
        $this->assertSame(
            expected: [
                'expired_locks',
                'expired_share_tokens',
                'orphaned_widget_placements',
                'orphaned_conditional_rules',
            ],
            actual: $this->registry->getCategoryNames()
        );
    }

    /**
     * `getCategoryByName` returns the category for known names and
     * `null` for unknown ones — the unknown path drives the API/CLI
     * 400 responses.
     *
     * @return void
     */
    public function testGetCategoryByNameLookup(): void
    {
        $this->assertInstanceOf(
            expected: ExpiredLocksCategory::class,
            actual: $this->registry->getCategoryByName(name: 'expired_locks')
        );
        $this->assertNull(
            actual: $this->registry->getCategoryByName(name: 'no_such_category')
        );
    }

    /**
     * The Tier-A filter MUST list exactly the two
     * "safeToPurgeAutomatically=true" categories in registration order.
     *
     * @return void
     */
    public function testGetAutoSafeCategoryNamesReturnsTierAOnly(): void
    {
        $this->assertSame(
            expected: ['expired_locks', 'expired_share_tokens'],
            actual: $this->registry->getAutoSafeCategoryNames()
        );
    }

    /**
     * `getCategories` returns the same map as the lookup — keyed by
     * name, with one entry per registered category.
     *
     * @return void
     */
    public function testGetCategoriesReturnsKeyedMap(): void
    {
        $categories = $this->registry->getCategories();

        $this->assertCount(expectedCount: 4, haystack: $categories);
        $this->assertArrayHasKey(key: 'expired_locks', array: $categories);
        $this->assertArrayHasKey(key: 'orphaned_conditional_rules', array: $categories);
    }
}
