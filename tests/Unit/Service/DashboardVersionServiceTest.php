<?php

/**
 * DashboardVersionService Test
 *
 * Unit tests for the dashboard versioning service backing the
 * `dashboard-versioning` capability (REQ-VERS-001..009). Covers:
 *   - automatic snapshot capture (REQ-VERS-001)
 *   - debounce window enforcement (REQ-VERS-001)
 *   - explicit snapshot creation bypasses debounce (REQ-VERS-002)
 *   - permission guard returns canonical sentinel (REQ-VERS-003..005)
 *   - retention pruning is invoked on every successful insert
 *     (REQ-VERS-006)
 *   - restore captures a pre-restore snapshot (REQ-VERS-005)
 *   - restore-to-current is idempotent (REQ-VERS-005)
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

use Exception;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardVersion;
use OCA\MyDash\Db\DashboardVersionMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\DashboardVersionService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the dashboard version service.
 */
class DashboardVersionServiceTest extends TestCase
{

    /**
     * Version mapper mock.
     *
     * @var DashboardVersionMapper&MockObject
     */
    private $versionMapper;

    /**
     * Dashboard mapper mock.
     *
     * @var DashboardMapper&MockObject
     */
    private $dashboardMapper;

    /**
     * Placement mapper mock.
     *
     * @var WidgetPlacementMapper&MockObject
     */
    private $placementMapper;

    /**
     * Group manager mock.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * Cache factory mock.
     *
     * @var ICacheFactory&MockObject
     */
    private $cacheFactory;

    /**
     * Cache mock.
     *
     * @var ICache&MockObject
     */
    private $cache;

    /**
     * Service under test.
     *
     * @var DashboardVersionService
     */
    private DashboardVersionService $service;

    /**
     * Set up fresh mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->versionMapper   = $this->createMock(DashboardVersionMapper::class);
        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->cacheFactory    = $this->createMock(ICacheFactory::class);
        $this->cache           = $this->createMock(ICache::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->cacheFactory->method('createDistributed')
            ->willReturn($this->cache);

        $this->service = new DashboardVersionService(
            versionMapper: $this->versionMapper,
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            groupManager: $this->groupManager,
            cacheFactory: $this->cacheFactory,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Build a minimal owner-stamped dashboard fixture.
     *
     * @param string $userId The owning user.
     * @param string $uuid   The UUID to assign.
     *
     * @return Dashboard The fixture entity.
     */
    private function makeDashboard(
        string $userId='alice',
        string $uuid='d-uuid-1'
    ): Dashboard {
        $dashboard = new Dashboard();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUuid($uuid);
        $dashboard->setUserId($userId);
        $dashboard->setName('Test');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        return $dashboard;
    }//end makeDashboard()

    /**
     * REQ-VERS-001: automatic snapshot inserts a row when no debounce
     * key is set, and increments versionNumber from max+1.
     *
     * @return void
     */
    public function testAutomaticSnapshotInsertsRow(): void
    {
        $dashboard = $this->makeDashboard();

        $this->cache->method('hasKey')->willReturn(false);

        $this->versionMapper->method('findMaxVersionNumber')
            ->with(dashboardUuid: 'd-uuid-1')
            ->willReturn(2);

        $this->versionMapper->expects($this->once())
            ->method('insert')
            ->willReturnArgument(0);

        $this->versionMapper->expects($this->once())
            ->method('pruneOldVersions')
            ->with(
                dashboardUuid: 'd-uuid-1',
                keepCount: DashboardVersionService::RETENTION_LIMIT
            );

        $this->placementMapper->method('findByDashboardId')->willReturn([]);

        $result = $this->service->captureSnapshot(
            dashboard: $dashboard,
            snapshotJson: null,
            createdBy: 'alice',
            note: null,
            explicit: false
        );

        $this->assertNotNull($result);
        $this->assertSame(3, $result->getVersionNumber());
        $this->assertSame('alice', $result->getCreatedBy());
        $this->assertNull($result->getNote());
    }//end testAutomaticSnapshotInsertsRow()

    /**
     * REQ-VERS-001: debounce key present → automatic snapshot is
     * suppressed. The mapper MUST NOT be touched.
     *
     * @return void
     */
    public function testAutomaticSnapshotIsDebounced(): void
    {
        $dashboard = $this->makeDashboard();

        $this->cache->method('hasKey')->willReturn(true);
        $this->versionMapper->expects($this->never())->method('insert');
        $this->versionMapper->expects($this->never())->method('findMaxVersionNumber');

        $result = $this->service->captureSnapshot(
            dashboard: $dashboard,
            snapshotJson: '{}',
            createdBy: 'alice',
            note: null,
            explicit: false
        );

        $this->assertNull($result);
    }//end testAutomaticSnapshotIsDebounced()

    /**
     * REQ-VERS-002: explicit snapshot bypasses the debounce window.
     *
     * @return void
     */
    public function testExplicitSnapshotBypassesDebounce(): void
    {
        $dashboard = $this->makeDashboard();

        $this->cache->method('hasKey')->willReturn(true);
        $this->versionMapper->method('findMaxVersionNumber')->willReturn(0);
        $this->versionMapper->expects($this->once())
            ->method('insert')
            ->willReturnArgument(0);
        $this->versionMapper->expects($this->once())->method('pruneOldVersions');

        $result = $this->service->captureSnapshot(
            dashboard: $dashboard,
            snapshotJson: '{"foo":1}',
            createdBy: 'alice',
            note: 'manual',
            explicit: true
        );

        $this->assertNotNull($result);
        $this->assertSame(1, $result->getVersionNumber());
        $this->assertSame('manual', $result->getNote());
        $this->assertSame('{"foo":1}', $result->getSnapshotJson());
    }//end testExplicitSnapshotBypassesDebounce()

    /**
     * REQ-VERS-003: list endpoint serialises each version row and
     * returns `modeSupported: true` for DB-backed dashboards.
     *
     * @return void
     */
    public function testListVersionsReturnsRows(): void
    {
        $dashboard = $this->makeDashboard();

        $row = new DashboardVersion();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $row->setDashboardUuid('d-uuid-1');
        $row->setVersionNumber(2);
        $row->setCreatedBy('alice');
        $row->setCreatedAt('2026-05-02 10:00:00');
        $row->setSnapshotJson('{"x":1}');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->versionMapper->method('findLatestByDashboard')
            ->willReturn([$row]);

        $envelope = $this->service->listVersions(
            dashboard: $dashboard,
            requestingUser: 'alice'
        );

        $this->assertTrue($envelope['modeSupported']);
        $this->assertCount(1, $envelope['versions']);
        $this->assertSame(2, $envelope['versions'][0]['versionNumber']);
        $this->assertArrayNotHasKey(
            key: 'snapshotJson',
            array: $envelope['versions'][0]
        );
    }//end testListVersionsReturnsRows()

    /**
     * REQ-VERS-003 / -004 / -005: non-owner non-admin caller MUST be
     * rejected with the canonical sentinel.
     *
     * @return void
     */
    public function testPermissionGuardRejectsNonOwnerNonAdmin(): void
    {
        $dashboard = $this->makeDashboard(userId: 'alice');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            DashboardVersionService::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN
        );

        $this->service->listVersions(
            dashboard: $dashboard,
            requestingUser: 'bob'
        );
    }//end testPermissionGuardRejectsNonOwnerNonAdmin()

    /**
     * REQ-VERS-005: restore captures a pre-restore snapshot before
     * applying the historical body.
     *
     * @return void
     */
    public function testRestoreCapturesPreRestoreSnapshot(): void
    {
        $dashboard = $this->makeDashboard();

        $target = new DashboardVersion();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $target->setDashboardUuid('d-uuid-1');
        $target->setVersionNumber(2);
        $target->setSnapshotJson('{"old":true}');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->versionMapper->method('findByDashboardAndVersion')
            ->willReturn($target);
        // Latest is 5, target is 2 → not idempotent path.
        $this->versionMapper->method('findMaxVersionNumber')->willReturn(5);

        // Pre-restore snapshot insert MUST fire exactly once.
        $this->versionMapper->expects($this->once())
            ->method('insert')
            ->willReturnArgument(0);
        $this->versionMapper->method('pruneOldVersions');
        $this->placementMapper->method('findByDashboardId')->willReturn([]);

        $this->dashboardMapper->expects($this->once())->method('update');

        $result = $this->service->restoreVersion(
            dashboard: $dashboard,
            versionNumber: 2,
            restoringUser: 'alice'
        );

        $this->assertSame('{"old":true}', $result['snapshot']);
        $this->assertSame(2, $result['version']->getVersionNumber());
    }//end testRestoreCapturesPreRestoreSnapshot()

    /**
     * REQ-VERS-005: restoring to the current version is a no-op — no
     * pre-restore snapshot, no DB update.
     *
     * @return void
     */
    public function testRestoreToCurrentIsNoOp(): void
    {
        $dashboard = $this->makeDashboard();

        $target = new DashboardVersion();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $target->setDashboardUuid('d-uuid-1');
        $target->setVersionNumber(5);
        $target->setSnapshotJson('{"current":true}');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->versionMapper->method('findByDashboardAndVersion')
            ->willReturn($target);
        $this->versionMapper->method('findMaxVersionNumber')->willReturn(5);
        $this->versionMapper->expects($this->never())->method('insert');
        $this->dashboardMapper->expects($this->never())->method('update');

        $result = $this->service->restoreVersion(
            dashboard: $dashboard,
            versionNumber: 5,
            restoringUser: 'alice'
        );

        $this->assertSame('{"current":true}', $result['snapshot']);
    }//end testRestoreToCurrentIsNoOp()

    /**
     * REQ-VERS-005: admin caller is allowed to restore a dashboard
     * owned by another user.
     *
     * @return void
     */
    public function testAdminMayRestoreOtherUsersDashboard(): void
    {
        $dashboard = $this->makeDashboard(userId: 'alice');
        $this->groupManager->method('isAdmin')->willReturn(true);

        $target = new DashboardVersion();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $target->setDashboardUuid('d-uuid-1');
        $target->setVersionNumber(1);
        $target->setSnapshotJson('{}');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->versionMapper->method('findByDashboardAndVersion')
            ->willReturn($target);
        $this->versionMapper->method('findMaxVersionNumber')->willReturn(2);
        $this->versionMapper->method('insert')->willReturnArgument(0);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);

        $result = $this->service->restoreVersion(
            dashboard: $dashboard,
            versionNumber: 1,
            restoringUser: 'admin'
        );

        $this->assertSame(1, $result['version']->getVersionNumber());
    }//end testAdminMayRestoreOtherUsersDashboard()

    /**
     * Cascade cleanup delegates to the mapper.
     *
     * @return void
     */
    public function testDeleteVersionsForDashboardDelegates(): void
    {
        $this->versionMapper->expects($this->once())
            ->method('deleteByDashboardUuid')
            ->with(dashboardUuid: 'd-uuid-1')
            ->willReturn(7);

        $this->assertSame(
            7,
            $this->service->deleteVersionsForDashboard(
                dashboardUuid: 'd-uuid-1'
            )
        );
    }//end testDeleteVersionsForDashboardDelegates()

    /**
     * REQ-VERS-009: groupfolder-backed dashboard returns the soft-fail
     * envelope from the list endpoint. Currently no Dashboard exposes
     * `getContentBackend`, so this test stubs the entity to verify the
     * dispatch hook exists.
     *
     * @return void
     */
    public function testGroupfolderBackedDashboardReturnsSoftFail(): void
    {
        $dashboard = new class extends Dashboard {
            public function getContentBackend(): string
            {
                return 'groupfolder';
            }//end getContentBackend()
        };
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUuid('d-uuid-1');
        $dashboard->setUserId('alice');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->versionMapper->expects($this->never())
            ->method('findLatestByDashboard');

        $envelope = $this->service->listVersions(
            dashboard: $dashboard,
            requestingUser: 'alice'
        );

        $this->assertFalse($envelope['modeSupported']);
        $this->assertSame([], $envelope['versions']);
    }//end testGroupfolderBackedDashboardReturnsSoftFail()
}//end class
