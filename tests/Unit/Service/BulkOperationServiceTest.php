<?php

/**
 * BulkOperationServiceTest
 *
 * Unit tests for the dashboard-bulk-operations service. Covers
 * REQ-BULK-001..011: per-uuid idempotency, all-or-nothing permission
 * pre-check, request size cap, dry-run isolation, and per-operation
 * envelope shape (delete / move / status / reindex).
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

use InvalidArgumentException;
use OCA\MyDash\Activity\ActivityPublisher;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\DashboardHasChildrenException;
use OCA\MyDash\Service\BulkOperationService;
use OCA\MyDash\Service\DashboardTreeService;
use OCA\MyDash\Service\PermissionDeniedException;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see BulkOperationService}.
 */
class BulkOperationServiceTest extends TestCase
{

    /**
     * @var DashboardMapper&MockObject
     */
    private $dashboardMapper;

    /**
     * @var WidgetPlacementMapper&MockObject
     */
    private $placementMapper;

    /**
     * @var PermissionService&MockObject
     */
    private $permissionService;

    /**
     * @var DashboardTreeService&MockObject
     */
    private $treeService;

    /**
     * @var ActivityPublisher&MockObject
     */
    private $activityPublisher;

    /**
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * @var IAppConfig&MockObject
     */
    private $appConfig;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    private BulkOperationService $service;

    protected function setUp(): void
    {
        $this->dashboardMapper   = $this->createMock(DashboardMapper::class);
        $this->placementMapper   = $this->createMock(WidgetPlacementMapper::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->treeService       = $this->createMock(DashboardTreeService::class);
        $this->activityPublisher = $this->createMock(ActivityPublisher::class);
        $this->groupManager      = $this->createMock(IGroupManager::class);
        $this->appConfig         = $this->createMock(IAppConfig::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->appConfig->method('getValueInt')->willReturn(500);
        $this->permissionService->method('resolveAccessLevel')
            ->willReturn(Dashboard::PERMISSION_FULL);

        $this->service = new BulkOperationService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            permissionService: $this->permissionService,
            treeService: $this->treeService,
            activityPublisher: $this->activityPublisher,
            groupManager: $this->groupManager,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Build a minimal Dashboard entity.
     */
    private function makeDashboard(
        string $uuid,
        ?string $parentUuid=null,
        string $publicationStatus=Dashboard::STATUS_PUBLISHED,
        ?int $id=1
    ): Dashboard {
        $dash = new Dashboard();
        $dash->setUuid($uuid);
        if ($parentUuid !== null) {
            $dash->setParentUuid($parentUuid);
        }

        $dash->setPublicationStatus($publicationStatus);
        if ($id !== null) {
            $dash->setId($id);
        }

        return $dash;
    }//end makeDashboard()

    public function testBulkDeleteHardDeletesEachDashboard(): void
    {
        $dash1 = $this->makeDashboard(uuid: 'uuid-1', id: 11);
        $dash2 = $this->makeDashboard(uuid: 'uuid-2', id: 12);

        $this->dashboardMapper->method('findByUuid')->willReturnMap(
                [
                    ['uuid-1', $dash1],
                    ['uuid-2', $dash2],
                ]
                );
        $this->dashboardMapper->method('countChildrenByParent')->willReturn(0);

        $this->dashboardMapper->expects(self::exactly(2))->method('delete');
        $this->placementMapper->expects(self::exactly(2))->method('deleteByDashboardId');

        $result = $this->service->bulkDelete(
            dashboardUuids: ['uuid-1', 'uuid-2'],
            userId: 'admin'
        );

        self::assertSame(2, $result['deletedCount']);
        self::assertSame(0, $result['skippedCount']);
        self::assertSame([], $result['errors']);
        self::assertFalse($result['dryRun']);
    }//end testBulkDeleteHardDeletesEachDashboard()

    public function testBulkDeleteSkipsAlreadyDeleted(): void
    {
        $dash1 = $this->makeDashboard(uuid: 'uuid-1', id: 11);

        $this->dashboardMapper->method('findByUuid')->willReturnCallback(
            function (string $uuid) use ($dash1) {
                if ($uuid === 'uuid-1') {
                    return $dash1;
                }

                throw new DoesNotExistException(msg: 'gone');
            }
        );
        $this->dashboardMapper->method('countChildrenByParent')->willReturn(0);

        $result = $this->service->bulkDelete(
            dashboardUuids: ['uuid-1', 'uuid-2'],
            userId: 'admin'
        );

        self::assertSame(1, $result['deletedCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertSame('uuid-2', $result['errors'][0]['uuid']);
        self::assertSame(BulkOperationService::REASON_ALREADY_DELETED, $result['errors'][0]['reason']);
    }//end testBulkDeleteSkipsAlreadyDeleted()

    public function testBulkDeleteParentWithChildrenAndCascadeFalseSkipsWithError(): void
    {
        $dash = $this->makeDashboard(uuid: 'parent', id: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->dashboardMapper->method('countChildrenByParent')->willReturn(5);

        $this->dashboardMapper->expects(self::never())->method('delete');

        $result = $this->service->bulkDelete(
            dashboardUuids: ['parent'],
            userId: 'admin',
            dryRun: false,
            cascade: false
        );

        self::assertSame(0, $result['deletedCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertCount(1, $result['errors']);
        self::assertSame(DashboardHasChildrenException::ERROR_CODE, $result['errors'][0]['reason']);
        self::assertSame(5, $result['errors'][0]['childCount']);
    }//end testBulkDeleteParentWithChildrenAndCascadeFalseSkipsWithError()

    public function testBulkDeleteCascadeTrueDelegatesToTreeService(): void
    {
        $dash = $this->makeDashboard(uuid: 'parent', id: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->dashboardMapper->method('countChildrenByParent')->willReturn(5);
        $this->treeService->method('deleteSubtree')->willReturn(6);

        $this->dashboardMapper->expects(self::never())->method('delete');

        $result = $this->service->bulkDelete(
            dashboardUuids: ['parent'],
            userId: 'admin',
            dryRun: false,
            cascade: true
        );

        self::assertSame(6, $result['deletedCount']);
        self::assertSame(0, $result['skippedCount']);
    }//end testBulkDeleteCascadeTrueDelegatesToTreeService()

    public function testBulkDeleteRejectsRequestExceedingCap(): void
    {
        $uuids = array_fill(start_index: 0, count: 501, value: 'uuid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum is 500');
        $this->service->bulkDelete(dashboardUuids: $uuids, userId: 'admin');
    }//end testBulkDeleteRejectsRequestExceedingCap()

    public function testBulkDeletePermissionDeniedIsAllOrNothing(): void
    {
        $dash = $this->makeDashboard(uuid: 'uuid-3', id: 3);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);

        // Override the default permission resolution.
        $perm = $this->createMock(PermissionService::class);
        $perm->method('resolveAccessLevel')->willReturn(null);

        $service = new BulkOperationService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            permissionService: $perm,
            treeService: $this->treeService,
            activityPublisher: $this->activityPublisher,
            groupManager: $this->groupManager,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

        $this->dashboardMapper->expects(self::never())->method('delete');

        $caught = false;
        try {
            $service->bulkDelete(
                dashboardUuids: ['uuid-3'],
                userId: 'admin'
            );
        } catch (PermissionDeniedException $e) {
            $caught = true;
            self::assertSame(['uuid-3'], $e->getDeniedUuids());
        }

        self::assertTrue($caught, 'expected PermissionDeniedException');
    }//end testBulkDeletePermissionDeniedIsAllOrNothing()

    public function testBulkDeleteNonAdminRejected(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        $service = new BulkOperationService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            permissionService: $this->permissionService,
            treeService: $this->treeService,
            activityPublisher: $this->activityPublisher,
            groupManager: $groupManager,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

        $this->expectException(PermissionDeniedException::class);
        $service->bulkDelete(dashboardUuids: ['uuid-1'], userId: 'bob');
    }//end testBulkDeleteNonAdminRejected()

    public function testBulkDeleteDryRunDoesNotMutate(): void
    {
        $dash = $this->makeDashboard(uuid: 'uuid-1', id: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->dashboardMapper->method('countChildrenByParent')->willReturn(0);

        $this->dashboardMapper->expects(self::never())->method('delete');

        $result = $this->service->bulkDelete(
            dashboardUuids: ['uuid-1'],
            userId: 'admin',
            dryRun: true
        );

        self::assertSame(1, $result['wouldDeleteCount']);
        self::assertSame(0, $result['wouldSkipCount']);
        self::assertTrue($result['dryRun']);
    }//end testBulkDeleteDryRunDoesNotMutate()

    public function testBulkMoveValidBatch(): void
    {
        $dash   = $this->makeDashboard(uuid: 'child', parentUuid: 'old');
        $parent = $this->makeDashboard(uuid: 'new-parent', id: 99);

        $this->dashboardMapper->method('findByUuid')->willReturnCallback(
            function (string $uuid) use ($dash, $parent) {
                return ($uuid === 'new-parent') ? $parent : $dash;
            }
        );

        $this->dashboardMapper->expects(self::once())->method('update');

        $result = $this->service->bulkMove(
            dashboardUuids: ['child'],
            parentUuid: 'new-parent',
            userId: 'admin'
        );

        self::assertSame(1, $result['movedCount']);
        self::assertSame(0, $result['skippedCount']);
    }//end testBulkMoveValidBatch()

    public function testBulkMoveDetectsCycle(): void
    {
        $dash   = $this->makeDashboard(uuid: 'A', parentUuid: null);
        $parent = $this->makeDashboard(uuid: 'C');

        $this->dashboardMapper->method('findByUuid')->willReturnCallback(
            function (string $uuid) use ($dash, $parent) {
                return ($uuid === 'C') ? $parent : $dash;
            }
        );

        $this->treeService->method('validateParent')->willThrowException(
            new InvalidArgumentException(message: DashboardTreeService::ERR_CYCLE_DETECTED)
        );

        $this->dashboardMapper->expects(self::never())->method('update');

        $result = $this->service->bulkMove(
            dashboardUuids: ['A'],
            parentUuid: 'C',
            userId: 'admin'
        );

        self::assertSame(0, $result['movedCount']);
        self::assertSame(BulkOperationService::REASON_CYCLE_DETECTED, $result['errors'][0]['reason']);
    }//end testBulkMoveDetectsCycle()

    public function testBulkMoveNoOpWhenParentMatches(): void
    {
        $dash   = $this->makeDashboard(uuid: 'child', parentUuid: 'target');
        $parent = $this->makeDashboard(uuid: 'target');

        $this->dashboardMapper->method('findByUuid')->willReturnCallback(
            function (string $uuid) use ($dash, $parent) {
                return ($uuid === 'target') ? $parent : $dash;
            }
        );

        $this->dashboardMapper->expects(self::never())->method('update');

        $result = $this->service->bulkMove(
            dashboardUuids: ['child'],
            parentUuid: 'target',
            userId: 'admin'
        );

        self::assertSame(0, $result['movedCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertSame(BulkOperationService::REASON_PARENT_ALREADY_MATCH, $result['errors'][0]['reason']);
    }//end testBulkMoveNoOpWhenParentMatches()

    public function testBulkMoveDryRun(): void
    {
        $dash   = $this->makeDashboard(uuid: 'child', parentUuid: null);
        $parent = $this->makeDashboard(uuid: 'p');

        $this->dashboardMapper->method('findByUuid')->willReturnCallback(
            function (string $uuid) use ($dash, $parent) {
                return ($uuid === 'p') ? $parent : $dash;
            }
        );

        $this->dashboardMapper->expects(self::never())->method('update');

        $result = $this->service->bulkMove(
            dashboardUuids: ['child'],
            parentUuid: 'p',
            userId: 'admin',
            dryRun: true
        );

        self::assertSame(1, $result['wouldMoveCount']);
        self::assertTrue($result['dryRun']);
    }//end testBulkMoveDryRun()

    public function testBulkMoveRejectsMissingParent(): void
    {
        $this->dashboardMapper->method('findByUuid')->willThrowException(
            new DoesNotExistException(msg: 'missing')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(DashboardTreeService::ERR_PARENT_NOT_FOUND);

        $this->service->bulkMove(
            dashboardUuids: ['child'],
            parentUuid: 'missing-parent',
            userId: 'admin'
        );
    }//end testBulkMoveRejectsMissingParent()

    public function testBulkStatusValidPublishesDrafts(): void
    {
        $dash = $this->makeDashboard(uuid: 'd1', publicationStatus: Dashboard::STATUS_DRAFT);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);

        $this->dashboardMapper->expects(self::once())->method('update');

        $result = $this->service->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: Dashboard::STATUS_PUBLISHED,
            publishAt: null,
            userId: 'admin'
        );

        self::assertSame(1, $result['updatedCount']);
        self::assertSame(0, $result['skippedCount']);
    }//end testBulkStatusValidPublishesDrafts()

    public function testBulkStatusIdempotentSkip(): void
    {
        $dash = $this->makeDashboard(uuid: 'd1', publicationStatus: Dashboard::STATUS_PUBLISHED);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);

        $this->dashboardMapper->expects(self::never())->method('update');

        $result = $this->service->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: Dashboard::STATUS_PUBLISHED,
            publishAt: null,
            userId: 'admin'
        );

        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['skippedCount']);
        self::assertSame(BulkOperationService::REASON_STATUS_ALREADY_MATCH, $result['errors'][0]['reason']);
    }//end testBulkStatusIdempotentSkip()

    public function testBulkStatusInvalidStatusRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publicationStatus must be one of');

        $this->service->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: 'bogus',
            publishAt: null,
            userId: 'admin'
        );
    }//end testBulkStatusInvalidStatusRejected()

    public function testBulkStatusScheduledRequiresPublishAt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publishAt is required');

        $this->service->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: Dashboard::STATUS_SCHEDULED,
            publishAt: null,
            userId: 'admin'
        );
    }//end testBulkStatusScheduledRequiresPublishAt()

    public function testBulkStatusScheduledRejectsPastPublishAt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->bulkStatus(
            dashboardUuids: ['d1'],
            publicationStatus: Dashboard::STATUS_SCHEDULED,
            publishAt: '2000-01-01T00:00:00Z',
            userId: 'admin'
        );
    }//end testBulkStatusScheduledRejectsPastPublishAt()

    public function testBulkReindexValidBatch(): void
    {
        $dash1 = $this->makeDashboard(uuid: 'd1');
        $dash2 = $this->makeDashboard(uuid: 'd2');

        $this->dashboardMapper->method('findByUuid')->willReturnMap(
                [
                    ['d1', $dash1],
                    ['d2', $dash2],
                ]
                );

        $this->dashboardMapper->expects(self::exactly(2))->method('update');

        $result = $this->service->bulkReindex(
            dashboardUuids: ['d1', 'd2'],
            userId: 'admin'
        );

        self::assertSame(2, $result['reindexedCount']);
        self::assertSame([], $result['errors']);
    }//end testBulkReindexValidBatch()

    public function testBulkReindexDryRun(): void
    {
        $dash1 = $this->makeDashboard(uuid: 'd1');
        $this->dashboardMapper->method('findByUuid')->willReturn($dash1);

        $this->dashboardMapper->expects(self::never())->method('update');

        $result = $this->service->bulkReindex(
            dashboardUuids: ['d1'],
            userId: 'admin',
            dryRun: true
        );

        self::assertSame(1, $result['wouldReindexCount']);
        self::assertTrue($result['dryRun']);
    }//end testBulkReindexDryRun()

    public function testEffectiveCapFallsBackOnInvalidConfig(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn(0);

        $service = new BulkOperationService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            permissionService: $this->permissionService,
            treeService: $this->treeService,
            activityPublisher: $this->activityPublisher,
            groupManager: $this->groupManager,
            appConfig: $appConfig,
            logger: $this->logger,
        );

        self::assertSame(BulkOperationService::DEFAULT_MAX_PER_REQUEST, $service->getEffectiveCap());
    }//end testEffectiveCapFallsBackOnInvalidConfig()

    public function testEmitsAuditEventOncePerOperation(): void
    {
        $dash = $this->makeDashboard(uuid: 'uuid-1', id: 1);
        $this->dashboardMapper->method('findByUuid')->willReturn($dash);
        $this->dashboardMapper->method('countChildrenByParent')->willReturn(0);

        $this->activityPublisher->expects(self::once())->method('publish');

        $this->service->bulkDelete(
            dashboardUuids: ['uuid-1'],
            userId: 'admin'
        );
    }//end testEmitsAuditEventOncePerOperation()
}//end class
