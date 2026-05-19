<?php

/**
 * OrphanedDataCleanupService Test
 *
 * Covers the orchestrator's scan + purge contracts: registry
 * traversal in registration order, "skipped when isAvailable=false",
 * dry-run transaction rollback, cache write/read, cache
 * invalidation on real purges, and the no-event-on-dry-run rule.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCA\MyDash\Service\Cleanup\CleanupCategoryInterface;
use OCA\MyDash\Service\OrphanedDataCleanupService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OrphanedDataCleanupService.
 */
class OrphanedDataCleanupServiceTest extends TestCase
{
    /**
     * Registry mock.
     *
     * @var CategoryRegistryService&MockObject
     */
    private $registry;

    /**
     * Cache factory mock.
     *
     * @var ICacheFactory&MockObject
     */
    private $cacheFactory;

    /**
     * Cache mock returned by the factory.
     *
     * @var ICache&MockObject
     */
    private $cache;

    /**
     * DB connection mock (transaction tracking).
     *
     * @var IDBConnection&MockObject
     */
    private $db;

    /**
     * Activity manager mock.
     *
     * @var IActivityManager&MockObject
     */
    private $activity;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * Service under test.
     *
     * @var OrphanedDataCleanupService
     */
    private OrphanedDataCleanupService $service;

    /**
     * Build all mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->registry     = $this->createMock(originalClassName: CategoryRegistryService::class);
        $this->cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
        $this->cache        = $this->createMock(originalClassName: ICache::class);
        $this->db           = $this->createMock(originalClassName: IDBConnection::class);
        $this->activity     = $this->createMock(originalClassName: IActivityManager::class);
        $this->logger       = $this->createMock(originalClassName: LoggerInterface::class);

        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->service = new OrphanedDataCleanupService(
            registry: $this->registry,
            cacheFactory: $this->cacheFactory,
            db: $this->db,
            activityManager: $this->activity,
            logger: $this->logger,
        );
    }

    /**
     * Build a category mock returning the supplied count from `scan`
     * and `purge`. `isAvailable()` is `true` by default.
     *
     * @param string $name      Category identifier.
     * @param int    $count     Count to return from scan/purge.
     * @param bool   $available Whether the category is available.
     *
     * @return CleanupCategoryInterface&MockObject The category.
     */
    private function makeCategory(
        string $name,
        int $count,
        bool $available=true
    ): CleanupCategoryInterface {
        $category = $this->createMock(originalClassName: CleanupCategoryInterface::class);
        $category->method('getName')->willReturn($name);
        $category->method('isAvailable')->willReturn($available);
        $category->method('scan')->willReturn($count);
        $category->method('purge')->willReturn($count);

        return $category;
    }

    /**
     * Scan with no filter MUST traverse every registered category in
     * registration order and aggregate counts.
     *
     * @return void
     */
    public function testScanAggregatesCountsAcrossRegistry(): void
    {
        $a = $this->makeCategory(name: 'a', count: 3);
        $b = $this->makeCategory(name: 'b', count: 0);

        $this->registry->method('getCategoryNames')->willReturn(['a', 'b']);
        $this->registry->method('getCategoryByName')->willReturnMap(
            [
                ['a', $a],
                ['b', $b],
            ]
        );

        // Cache empty so the orchestrator runs a fresh scan.
        $this->cache->method('get')->willReturn(null);

        $result = $this->service->scan();

        $this->assertSame(expected: 3, actual: $result->getTotalRows());
        $this->assertSame(
            expected: ['a' => 3, 'b' => 0],
            actual: $result->getByCategory()
        );
    }

    /**
     * Categories whose `isAvailable()` is `false` MUST end up under
     * `skipped` and contribute no count.
     *
     * @return void
     */
    public function testScanSkipsUnavailableCategories(): void
    {
        $a = $this->makeCategory(name: 'a', count: 3);
        $b = $this->makeCategory(name: 'b', count: 99, available: false);

        $this->registry->method('getCategoryNames')->willReturn(['a', 'b']);
        $this->registry->method('getCategoryByName')->willReturnMap(
            [
                ['a', $a],
                ['b', $b],
            ]
        );
        $this->cache->method('get')->willReturn(null);

        $result = $this->service->scan();

        $this->assertSame(expected: ['a' => 3], actual: $result->getByCategory());
        $this->assertSame(expected: ['b'], actual: $result->getSkipped());
        $this->assertSame(expected: 3, actual: $result->getTotalRows());
    }

    /**
     * The cache hit path MUST short-circuit the registry traversal
     * and return a hydrated DTO.
     *
     * @return void
     */
    public function testScanReturnsCachedResultWhenAvailable(): void
    {
        // Registry MUST NOT be touched on a cache hit.
        $this->registry->expects($this->never())->method('getCategoryNames');

        $this->cache->method('get')->willReturn(
            [
                'byCategory' => ['x' => 7],
                'totalRows'  => 7,
                'durationMs' => 1,
                'dryRun'     => false,
                'scannedAt'  => '2026-05-03T10:00:00Z',
                'skipped'    => [],
            ]
        );

        $result = $this->service->scan();

        $this->assertSame(expected: 7, actual: $result->getTotalRows());
        $this->assertSame(
            expected: '2026-05-03T10:00:00Z',
            actual: $result->getScannedAt()
        );
    }

    /**
     * A successful real purge MUST invalidate the cache.
     *
     * @return void
     */
    public function testRealPurgeInvalidatesCache(): void
    {
        $a = $this->makeCategory(name: 'a', count: 2);

        $this->registry->method('getCategoryNames')->willReturn(['a']);
        $this->registry->method('getCategoryByName')->willReturn($a);

        $this->cache->expects($this->once())
            ->method('remove')
            ->with(self::equalTo('mydash.cleanup.scan'));

        $this->service->purge();
    }

    /**
     * Dry-run purge MUST wrap the work in a transaction rollback and
     * MUST NOT emit an Activity event or invalidate the cache.
     *
     * @return void
     */
    public function testDryRunRollsBackAndDoesNotEmitEvent(): void
    {
        $a = $this->makeCategory(name: 'a', count: 5);

        $this->registry->method('getCategoryNames')->willReturn(['a']);
        $this->registry->method('getCategoryByName')->willReturn($a);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->cache->expects($this->never())->method('remove');
        $this->activity->expects($this->never())->method('publish');

        $result = $this->service->purge(categoryNames: [], dryRun: true);

        $this->assertTrue(condition: $result->isDryRun());
        $this->assertSame(expected: 5, actual: $result->getTotalRows());
    }

    /**
     * Real purge with a non-zero total MUST publish exactly one
     * activity event tagged with the source.
     *
     * @return void
     */
    public function testRealPurgeEmitsOneActivityEvent(): void
    {
        $a = $this->makeCategory(name: 'a', count: 4);

        $this->registry->method('getCategoryNames')->willReturn(['a']);
        $this->registry->method('getCategoryByName')->willReturn($a);

        $event = $this->createMock(originalClassName: IEvent::class);
        $event->method('setApp')->willReturnSelf();
        $event->method('setType')->willReturnSelf();
        $event->method('setAffectedUser')->willReturnSelf();
        $event->method('setAuthor')->willReturnSelf();
        $event->method('setSubject')->willReturnSelf();
        $event->method('setObject')->willReturnSelf();

        $this->activity->method('generateEvent')->willReturn($event);
        $this->activity->expects($this->once())->method('publish');

        $this->service->purge(
            categoryNames: [],
            dryRun: false,
            userId: 'admin',
            source: 'cli'
        );
    }

    /**
     * A real purge that finds zero rows MUST NOT emit an activity
     * event (avoids audit-log spam from idle daily runs).
     *
     * @return void
     */
    public function testRealPurgeWithZeroRowsDoesNotEmitEvent(): void
    {
        $a = $this->makeCategory(name: 'a', count: 0);

        $this->registry->method('getCategoryNames')->willReturn(['a']);
        $this->registry->method('getCategoryByName')->willReturn($a);

        $this->activity->expects($this->never())->method('publish');

        $this->service->purge();
    }
}
