<?php

/**
 * DashboardService Publication-State Test
 *
 * Covers the publication-state workflow added by the
 * `dashboard-draft-published` change (REQ-DASH-031..037):
 *   - publish() flips status, stamps publishedAt on first publish, is
 *     idempotent on subsequent calls.
 *   - unpublish() returns the dashboard to draft while preserving
 *     publishedAt.
 *   - schedule() validates that publishAt parses and is strictly in
 *     the future.
 *   - Owner-or-admin guard: non-owner non-admin callers receive the
 *     canonical 403 sentinel.
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
use InvalidArgumentException;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\DashboardTreeService;
use OCA\MyDash\Service\TemplateService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the publish / unpublish / schedule workflow.
 */
class DashboardServicePublicationTest extends TestCase
{

    /**
     * Dashboard mapper mock.
     *
     * @var DashboardMapper&MockObject
     */
    private $dashboardMapper;

    /**
     * Group manager mock — used by the owner-or-admin guard.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * Service under test.
     *
     * @var DashboardService
     */
    private DashboardService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dashboardMapper      = $this->createMock(DashboardMapper::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);
        $placementMapper            = $this->createMock(WidgetPlacementMapper::class);
        $settingMapper              = $this->createMock(AdminSettingMapper::class);
        $templateService            = $this->createMock(TemplateService::class);
        $dashboardFactory           = $this->createMock(DashboardFactory::class);
        $dashResolver               = $this->createMock(DashboardResolver::class);
        $treeService                = $this->createMock(DashboardTreeService::class);
        $adminTemplateService       = $this->createMock(AdminTemplateService::class);
        $db                         = $this->createMock(IDBConnection::class);
        $config                     = $this->createMock(IConfig::class);
        $l10nFactory                = $this->createMock(IFactory::class);
        $logger                     = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $placementMapper,
            settingMapper: $settingMapper,
            templateService: $templateService,
            dashboardFactory: $dashboardFactory,
            dashResolver: $dashResolver,
            treeService: $treeService,
            groupManager: $this->groupManager,
            adminTemplateService: $adminTemplateService,
            db: $db,
            config: $config,
            l10nFactory: $l10nFactory,
            logger: $logger,
            footerService: $this->createMock(\OCA\MyDash\Service\FooterService::class),
        );
    }//end setUp()

    /**
     * Build a fresh dashboard fixture in draft state owned by `$userId`.
     *
     * @param string $userId The owning user.
     * @param string $uuid   The UUID to assign.
     *
     * @return Dashboard The fixture entity.
     */
    private function makeDraftDashboard(
        string $userId,
        string $uuid='d-uuid-1'
    ): Dashboard {
        $dashboard = new Dashboard();
        $dashboard->setUuid($uuid);
        $dashboard->setUserId($userId);
        $dashboard->setName('Test');
        $dashboard->setPublicationStatus(Dashboard::STATUS_DRAFT);

        return $dashboard;
    }//end makeDraftDashboard()

    /**
     * REQ-DASH-032: publish() MUST flip status to published and stamp
     * publishedAt the first time.
     *
     * @return void
     */
    public function testPublishFlipsStatusAndStampsPublishedAt(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');

        $this->dashboardMapper->expects($this->once())
            ->method('findByUuid')
            ->with(uuid: 'd-uuid-1')
            ->willReturn($dashboard);

        $this->dashboardMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->publish(uuid: 'd-uuid-1', userId: 'alice');

        $this->assertSame(
            Dashboard::STATUS_PUBLISHED,
            $result->getPublicationStatus()
        );
        $this->assertNotNull($result->getPublishedAt());
        $this->assertNull($result->getPublishAt());
    }//end testPublishFlipsStatusAndStampsPublishedAt()

    /**
     * REQ-DASH-032: republishing an already-published dashboard MUST
     * leave publishedAt unchanged (idempotent).
     *
     * @return void
     */
    public function testPublishIsIdempotent(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');
        $dashboard->setPublicationStatus(Dashboard::STATUS_PUBLISHED);
        $dashboard->setPublishedAt('2026-03-20 14:30:00');

        $this->dashboardMapper->expects($this->once())
            ->method('findByUuid')
            ->willReturn($dashboard);
        $this->dashboardMapper->expects($this->never())
            ->method('update');

        $result = $this->service->publish(uuid: 'd-uuid-1', userId: 'alice');

        $this->assertSame('2026-03-20 14:30:00', $result->getPublishedAt());
    }//end testPublishIsIdempotent()

    /**
     * REQ-DASH-032: non-owner non-admin caller MUST be rejected with the
     * canonical sentinel error.
     *
     * @return void
     */
    public function testPublishForbiddenForNonOwnerNonAdmin(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');

        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            DashboardService::ERR_FORBIDDEN_NOT_OWNER_OR_ADMIN
        );

        $this->service->publish(uuid: 'd-uuid-1', userId: 'bob');
    }//end testPublishForbiddenForNonOwnerNonAdmin()

    /**
     * REQ-DASH-032: a Nextcloud admin MUST be allowed to publish a
     * dashboard owned by another user.
     *
     * @return void
     */
    public function testPublishAllowedForAdmin(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');

        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->dashboardMapper->method('update')->willReturnArgument(0);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $result = $this->service->publish(
            uuid: 'd-uuid-1',
            userId: 'root'
        );

        $this->assertSame(
            Dashboard::STATUS_PUBLISHED,
            $result->getPublicationStatus()
        );
    }//end testPublishAllowedForAdmin()

    /**
     * REQ-DASH-033: unpublish MUST preserve publishedAt while flipping
     * status back to draft.
     *
     * @return void
     */
    public function testUnpublishPreservesPublishedAt(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');
        $dashboard->setPublicationStatus(Dashboard::STATUS_PUBLISHED);
        $dashboard->setPublishedAt('2026-03-20 14:30:00');

        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->dashboardMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->unpublish(
            uuid: 'd-uuid-1',
            userId: 'alice'
        );

        $this->assertSame(
            Dashboard::STATUS_DRAFT,
            $result->getPublicationStatus()
        );
        $this->assertSame('2026-03-20 14:30:00', $result->getPublishedAt());
        $this->assertNull($result->getPublishAt());
    }//end testUnpublishPreservesPublishedAt()

    /**
     * REQ-DASH-033: unpublish on an already-draft dashboard is a no-op
     * round-trip; no DB write fires.
     *
     * @return void
     */
    public function testUnpublishIsIdempotent(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');

        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->dashboardMapper->expects($this->never())->method('update');

        $result = $this->service->unpublish(
            uuid: 'd-uuid-1',
            userId: 'alice'
        );

        $this->assertSame(
            Dashboard::STATUS_DRAFT,
            $result->getPublicationStatus()
        );
    }//end testUnpublishIsIdempotent()

    /**
     * REQ-DASH-034: schedule MUST set status, normalise publishAt to
     * the storage format, and reject past dates.
     *
     * @return void
     */
    public function testScheduleAcceptsFutureDate(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');

        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);
        $this->dashboardMapper->method('update')->willReturnArgument(0);

        $future = (new \DateTime('+1 day'))->format('c');
        $result = $this->service->schedule(
            uuid: 'd-uuid-1',
            publishAt: $future,
            userId: 'alice'
        );

        $this->assertSame(
            Dashboard::STATUS_SCHEDULED,
            $result->getPublicationStatus()
        );
        $this->assertNotNull($result->getPublishAt());
        // Format normalised to Y-m-d H:i:s.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result->getPublishAt()
        );
    }//end testScheduleAcceptsFutureDate()

    /**
     * REQ-DASH-034: scheduling with a past date MUST raise the canonical
     * InvalidArgumentException with the i18n-translatable message.
     *
     * @return void
     */
    public function testScheduleRejectsPastDate(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            DashboardService::ERR_SCHEDULE_PAST_DATE
        );

        $past = (new \DateTime('-1 day'))->format('c');
        $this->service->schedule(
            uuid: 'd-uuid-1',
            publishAt: $past,
            userId: 'alice'
        );
    }//end testScheduleRejectsPastDate()

    /**
     * REQ-DASH-034: scheduling with an empty / unparseable publishAt
     * MUST raise the same canonical exception as a past date.
     *
     * @return void
     */
    public function testScheduleRejectsEmptyPublishAt(): void
    {
        $dashboard = $this->makeDraftDashboard(userId: 'alice');
        $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            DashboardService::ERR_SCHEDULE_PAST_DATE
        );

        $this->service->schedule(
            uuid: 'd-uuid-1',
            publishAt: '',
            userId: 'alice'
        );
    }//end testScheduleRejectsEmptyPublishAt()
}//end class
