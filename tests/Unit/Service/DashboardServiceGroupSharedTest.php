<?php

/**
 * DashboardServiceGroupSharedTest
 *
 * Unit tests for the multi-scope-dashboards additions to
 * {@see \OCA\MyDash\Service\DashboardService} — group-shared CRUD,
 * the visible-to-user resolution, and the last-in-group delete guard.
 * Covers REQ-DASH-011, REQ-DASH-013, REQ-DASH-014.
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
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the group-shared CRUD additions to DashboardService.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DashboardServiceGroupSharedTest extends TestCase
{

    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    /** @var TemplateService&MockObject */
    private $templateService;

    /** @var DashboardResolver&MockObject */
    private $dashResolver;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var AdminTemplateService&MockObject */
    private $adminTemplateService;

    /** @var IDBConnection&MockObject */
    private $db;

    /** @var IConfig&MockObject */
    private $config;

    /** @var IFactory&MockObject */
    private $l10nFactory;

    /** @var IUserManager&MockObject */
    private $userManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private DashboardService $service;

    /**
     * Set up fresh mocks per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardMapper      = $this->createMock(DashboardMapper::class);
        $this->placementMapper      = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper        = $this->createMock(AdminSettingMapper::class);
        $this->templateService      = $this->createMock(TemplateService::class);
        $this->dashResolver         = $this->createMock(DashboardResolver::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);
        $this->adminTemplateService = $this->createMock(AdminTemplateService::class);
        $this->userManager          = $this->createMock(IUserManager::class);
        $this->db                   = $this->createMock(IDBConnection::class);
        $this->config               = $this->createMock(IConfig::class);
        $this->l10nFactory          = $this->createMock(IFactory::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $this->templateService,
            dashboardFactory: new DashboardFactory(),
            dashResolver: $this->dashResolver,
            groupManager: $this->groupManager,
            adminTemplateService: $this->adminTemplateService,
            db: $this->db,
            config: $this->config,
            l10nFactory: $this->l10nFactory,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * REQ-DASH-014: createGroupShared rejects non-admin actors.
     *
     * @return void
     */
    public function testCreateGroupSharedRejectsNonAdmin(): void
    {
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(DashboardService::ERR_FORBIDDEN_NOT_ADMIN);

        $this->service->createGroupShared(
            actorUserId: 'alice',
            groupId: 'marketing',
            name: 'Marketing Overview'
        );
    }//end testCreateGroupSharedRejectsNonAdmin()

    /**
     * REQ-DASH-014: createGroupShared persists with type=group_shared,
     * groupId=path, userId=null, permission=view_only, isDefault=0.
     *
     * @return void
     */
    public function testCreateGroupSharedPersistsCorrectShape(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $captured = null;
        $this->dashboardMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Dashboard $d) use (&$captured) {
                $captured = $d;
                return $d;
            });

        $this->service->createGroupShared(
            actorUserId: 'admin',
            groupId: 'marketing',
            name: 'Marketing Overview'
        );

        $this->assertNotNull($captured);
        $this->assertSame(Dashboard::TYPE_GROUP_SHARED, $captured->getType());
        $this->assertSame('marketing', $captured->getGroupId());
        $this->assertNull($captured->getUserId());
        $this->assertSame(Dashboard::PERMISSION_VIEW_ONLY, $captured->getPermissionLevel());
        $this->assertSame(0, $captured->getIsDefault());
        $this->assertSame(0, $captured->getIsActive());
    }//end testCreateGroupSharedPersistsCorrectShape()

    /**
     * REQ-DASH-014: deleteGroupShared rejects when removing the row would
     * leave the (non-default) group with zero group-shared dashboards.
     *
     * @return void
     */
    public function testDeleteGroupSharedRejectsLastInGroup(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-1');
        $dashboard->setType(Dashboard::TYPE_GROUP_SHARED);
        $dashboard->setGroupId('marketing');
        $dashboard->setId(42);

        $this->dashboardMapper
            ->method('findByUuid')
            ->with('uuid-1')
            ->willReturn($dashboard);
        $this->dashboardMapper
            ->method('countByGroup')
            ->with('marketing')
            ->willReturn(1);

        $this->placementMapper
            ->expects($this->never())
            ->method('deleteByDashboardId');
        $this->dashboardMapper
            ->expects($this->never())
            ->method('delete');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(DashboardService::ERR_LAST_IN_GROUP);

        $this->service->deleteGroupShared(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-1'
        );
    }//end testDeleteGroupSharedRejectsLastInGroup()

    /**
     * REQ-DASH-014: the `default` group is exempt from the
     * last-in-group guard — the admin can intentionally clear it.
     *
     * @return void
     */
    public function testDeleteGroupSharedAllowsLastInDefaultGroup(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-default');
        $dashboard->setType(Dashboard::TYPE_GROUP_SHARED);
        $dashboard->setGroupId(Dashboard::DEFAULT_GROUP_ID);
        $dashboard->setId(99);

        $this->dashboardMapper
            ->method('findByUuid')
            ->with('uuid-default')
            ->willReturn($dashboard);
        // The countByGroup branch must NOT be hit for the default group.
        $this->dashboardMapper
            ->expects($this->never())
            ->method('countByGroup');

        $this->placementMapper
            ->expects($this->once())
            ->method('deleteByDashboardId')
            ->with(99);
        $this->dashboardMapper
            ->expects($this->once())
            ->method('delete')
            ->with($dashboard);

        $this->service->deleteGroupShared(
            actorUserId: 'admin',
            groupId: Dashboard::DEFAULT_GROUP_ID,
            uuid: 'uuid-default'
        );
    }//end testDeleteGroupSharedAllowsLastInDefaultGroup()

    /**
     * REQ-DASH-014: groupId mismatch between path and record returns
     * 404 (DoesNotExistException at the service boundary).
     *
     * @return void
     */
    public function testFindGroupDashboardRejectsGroupMismatch(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-1');
        $dashboard->setType(Dashboard::TYPE_GROUP_SHARED);
        $dashboard->setGroupId('marketing');

        $this->dashboardMapper
            ->method('findByUuid')
            ->with('uuid-1')
            ->willReturn($dashboard);

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage(DashboardService::ERR_GROUP_MISMATCH);

        $this->service->findGroupDashboard(
            groupId: 'engineering',
            uuid: 'uuid-1'
        );
    }//end testFindGroupDashboardRejectsGroupMismatch()

    /**
     * REQ-DASH-014: a personal `user`-type row is rejected when looked
     * up via the group-scoped findGroupDashboard path.
     *
     * @return void
     */
    public function testFindGroupDashboardRejectsNonGroupSharedType(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-personal');
        $dashboard->setType(Dashboard::TYPE_USER);
        $dashboard->setUserId('alice');

        $this->dashboardMapper
            ->method('findByUuid')
            ->with('uuid-personal')
            ->willReturn($dashboard);

        $this->expectException(DoesNotExistException::class);

        $this->service->findGroupDashboard(
            groupId: 'marketing',
            uuid: 'uuid-personal'
        );
    }//end testFindGroupDashboardRejectsNonGroupSharedType()

    /**
     * REQ-DASH-013 + REQ-TMPL-013: getVisibleToUser returns an empty list
     * when the routing resolver reports no group memberships (the
     * resolver itself returns `[]` for unknown users — defensive
     * behaviour propagated through the mapper call).
     *
     * @return void
     */
    public function testGetVisibleToUserHandlesUnknownUser(): void
    {
        $this->adminTemplateService
            ->method('getUserGroupIdsFor')
            ->with('ghost')
            ->willReturn([]);

        $this->dashboardMapper
            ->expects($this->once())
            ->method('findVisibleToUser')
            ->with('ghost', [])
            ->willReturn([]);

        $result = $this->service->getVisibleToUser(userId: 'ghost');
        $this->assertSame([], $result);
    }//end testGetVisibleToUserHandlesUnknownUser()

    /**
     * REQ-DASH-013 + REQ-TMPL-013: getVisibleToUser delegates to the
     * mapper with the user's group ids resolved via the routing resolver
     * (single source of truth for `IGroupManager::getUserGroupIds`).
     *
     * @return void
     */
    public function testGetVisibleToUserDelegatesToMapper(): void
    {
        $this->adminTemplateService
            ->method('getUserGroupIdsFor')
            ->with('alice')
            ->willReturn(['marketing', 'engineering']);

        $expected = [
            ['dashboard' => new Dashboard(), 'source' => 'user'],
        ];
        $this->dashboardMapper
            ->expects($this->once())
            ->method('findVisibleToUser')
            ->with('alice', ['marketing', 'engineering'])
            ->willReturn($expected);

        $result = $this->service->getVisibleToUser(userId: 'alice');
        $this->assertSame($expected, $result);
    }//end testGetVisibleToUserDelegatesToMapper()

    /**
     * REQ-DASH-015: setGroupDefault rejects non-admin actors with the
     * canonical "forbidden" exception. The mapper MUST NOT be touched
     * and no transaction MUST be opened.
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsNonAdmin(): void
    {
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

        $this->db->expects($this->never())->method('beginTransaction');
        $this->dashboardMapper
            ->expects($this->never())
            ->method('setGroupDefaultUuid');
        $this->dashboardMapper
            ->expects($this->never())
            ->method('clearGroupDefaults');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(DashboardService::ERR_FORBIDDEN_NOT_ADMIN);

        $this->service->setGroupDefault(
            actorUserId: 'alice',
            groupId: 'marketing',
            uuid: 'uuid-1'
        );
    }//end testSetGroupDefaultRejectsNonAdmin()

    /**
     * REQ-DASH-015 happy path. The service MUST:
     *  - open a single transaction,
     *  - set the target row to default,
     *  - clear every other row in the same group (with `exceptUuid` pinning
     *    the target so we never write the "set 1" row to 0 in the same
     *    statement),
     *  - commit.
     *
     * @return void
     */
    public function testSetGroupDefaultFlipsOthersOff(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->dashboardMapper
            ->expects($this->once())
            ->method('setGroupDefaultUuid')
            ->with('marketing', 'uuid-c')
            ->willReturn(1);
        $this->dashboardMapper
            ->expects($this->once())
            ->method('clearGroupDefaults')
            ->with('marketing', 'uuid-c')
            ->willReturn(2);

        $this->service->setGroupDefault(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-c'
        );
    }//end testSetGroupDefaultFlipsOthersOff()

    /**
     * REQ-DASH-015: the target uuid must belong to the path's group; if
     * `setGroupDefaultUuid` returns 0 the service MUST roll back, throw
     * `DoesNotExistException`, and crucially MUST NOT call
     * `clearGroupDefaults` (which would wipe the existing default in the
     * other group).
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsCrossGroupUuid(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->dashboardMapper
            ->expects($this->once())
            ->method('setGroupDefaultUuid')
            ->with('sales', 'uuid-marketing')
            ->willReturn(0);
        $this->dashboardMapper
            ->expects($this->never())
            ->method('clearGroupDefaults');

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage(DashboardService::ERR_DEFAULT_TARGET_NOT_IN_GROUP);

        $this->service->setGroupDefault(
            actorUserId: 'admin',
            groupId: 'sales',
            uuid: 'uuid-marketing'
        );
    }//end testSetGroupDefaultRejectsCrossGroupUuid()

    /**
     * REQ-DASH-015: a failure between the two UPDATE calls MUST roll the
     * transaction back so the previous default is preserved. We simulate
     * this by making `clearGroupDefaults` throw — the service MUST
     * rollback and re-throw.
     *
     * @return void
     */
    public function testSetGroupDefaultIsTransactional(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->dashboardMapper
            ->expects($this->once())
            ->method('setGroupDefaultUuid')
            ->with('marketing', 'uuid-b')
            ->willReturn(1);
        $this->dashboardMapper
            ->expects($this->once())
            ->method('clearGroupDefaults')
            ->with('marketing', 'uuid-b')
            ->willThrowException(new \RuntimeException('DB exploded mid-flip'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB exploded mid-flip');

        $this->service->setGroupDefault(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-b'
        );
    }//end testSetGroupDefaultIsTransactional()

    /**
     * REQ-DASH-014: updateGroupShared strips the `isDefault` field from
     * the patch payload defensively (the admin promotes via the
     * dedicated /default endpoint instead).
     *
     * @return void
     */
    public function testUpdateGroupSharedStripsIsDefaultFromPatch(): void
    {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-1');
        $dashboard->setType(Dashboard::TYPE_GROUP_SHARED);
        $dashboard->setGroupId('marketing');
        $dashboard->setIsDefault(0);
        $dashboard->setName('Old Name');

        $this->dashboardMapper
            ->method('findByUuid')
            ->with('uuid-1')
            ->willReturn($dashboard);
        $this->dashboardMapper
            ->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->updateGroupShared(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-1',
            patch: ['name' => 'New Name', 'isDefault' => 1]
        );

        $this->assertSame('New Name', $result->getName());
        // isDefault MUST remain 0 — the patch field is stripped.
        $this->assertSame(0, $result->getIsDefault());
    }//end testUpdateGroupSharedStripsIsDefaultFromPatch()

}//end class
