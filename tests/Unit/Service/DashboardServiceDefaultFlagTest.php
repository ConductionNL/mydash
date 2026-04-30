<?php

/**
 * DashboardService Default-Flag Test
 *
 * Covers REQ-DASH-015 (transactional single-default flip),
 * REQ-DASH-016 (creation always sets isDefault=0), and
 * REQ-DASH-017 (PUT MUST NOT mutate isDefault).
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
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the default-dashboard-flag change.
 */
class DashboardServiceDefaultFlagTest extends TestCase
{
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;
    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;
    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;
    /** @var TemplateService&MockObject */
    private $templateService;
    /** @var DashboardFactory&MockObject */
    private $dashboardFactory;
    /** @var DashboardResolver&MockObject */
    private $dashResolver;
    /** @var IGroupManager&MockObject */
    private $groupManager;
    /** @var IUserManager&MockObject */
    private $userManager;
    /** @var IDBConnection&MockObject */
    private $db;

    private DashboardService $service;

    protected function setUp(): void
    {
        $this->dashboardMapper  = $this->createMock(DashboardMapper::class);
        $this->placementMapper  = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper    = $this->createMock(AdminSettingMapper::class);
        $this->templateService  = $this->createMock(TemplateService::class);
        $this->dashboardFactory = $this->createMock(DashboardFactory::class);
        $this->dashResolver     = $this->createMock(DashboardResolver::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->userManager      = $this->createMock(IUserManager::class);
        $this->db               = $this->createMock(IDBConnection::class);

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $this->templateService,
            dashboardFactory: $this->dashboardFactory,
            dashResolver: $this->dashResolver,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            db: $this->db,
        );
    }//end setUp()

    /**
     * REQ-DASH-015: setGroupDefault flips others off and target on, in a
     * single transaction.
     *
     * @return void
     */
    public function testSetGroupDefaultFlipsOthersOff(): void
    {
        $this->groupManager->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        // Order matters: SET-target first (so we can detect 0-row 404),
        // then clear-others.
        $this->dashboardMapper->expects($this->once())
            ->method('setGroupDefaultUuid')
            ->with('marketing', 'uuid-c')
            ->willReturn(1);
        $this->dashboardMapper->expects($this->once())
            ->method('clearGroupDefaults')
            ->with('marketing', 'uuid-c')
            ->willReturn(1);

        $this->service->setGroupDefault(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-c'
        );
    }//end testSetGroupDefaultFlipsOthersOff()

    /**
     * REQ-DASH-015 scenario: target uuid does not belong to the group.
     * Service must throw DoesNotExistException AND roll the transaction
     * back so the previous default is preserved.
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsCrossGroupUuid(): void
    {
        $this->groupManager->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        // setGroupDefaultUuid finds no row → returns 0.
        $this->dashboardMapper->expects($this->once())
            ->method('setGroupDefaultUuid')
            ->with('sales', 'uuid-from-marketing')
            ->willReturn(0);
        // clearGroupDefaults must NOT be called when the target is
        // missing — otherwise we'd nuke the existing default in the
        // wrong group.
        $this->dashboardMapper->expects($this->never())
            ->method('clearGroupDefaults');

        $this->expectException(DoesNotExistException::class);

        $this->service->setGroupDefault(
            actorUserId: 'admin',
            groupId: 'sales',
            uuid: 'uuid-from-marketing'
        );
    }//end testSetGroupDefaultRejectsCrossGroupUuid()

    /**
     * REQ-DASH-015: simulate failure between SET and CLEAR — rollback
     * MUST fire so the previous default survives.
     *
     * @return void
     */
    public function testSetGroupDefaultIsTransactional(): void
    {
        $this->groupManager->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $this->dashboardMapper->expects($this->once())
            ->method('setGroupDefaultUuid')
            ->willReturn(1);
        $this->dashboardMapper->expects($this->once())
            ->method('clearGroupDefaults')
            ->willThrowException(new RuntimeException('DB down'));

        $this->expectException(RuntimeException::class);

        $this->service->setGroupDefault(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-b'
        );
    }//end testSetGroupDefaultIsTransactional()

    /**
     * Non-admin caller: HTTP 403 surface mapped via service exception.
     * No DB writes MUST be attempted.
     *
     * @return void
     */
    public function testSetGroupDefaultRejectsNonAdmin(): void
    {
        $this->groupManager->method('isAdmin')
            ->with('alice')
            ->willReturn(false);

        $this->db->expects($this->never())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->dashboardMapper->expects($this->never())
            ->method('setGroupDefaultUuid');
        $this->dashboardMapper->expects($this->never())
            ->method('clearGroupDefaults');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            DashboardService::ERR_FORBIDDEN_NOT_ADMIN
        );

        $this->service->setGroupDefault(
            actorUserId: 'alice',
            groupId: 'marketing',
            uuid: 'uuid-b'
        );
    }//end testSetGroupDefaultRejectsNonAdmin()

    /**
     * REQ-DASH-016: createGroupShared MUST set isDefault=0 on the new
     * row, regardless of factory state — this is the defense-in-depth
     * against payload smuggling.
     *
     * @return void
     */
    public function testCreateGroupSharedAlwaysStartsNonDefault(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $entity = new Dashboard();
        // Pretend the factory accidentally created the row with
        // isDefault=1 — the service must overwrite it.
        $entity->setIsDefault(1);

        $this->dashboardFactory->method('create')->willReturn($entity);
        $this->dashboardMapper->method('insert')->willReturnArgument(0);

        $result = $this->service->createGroupShared(
            actorUserId: 'admin',
            groupId: 'marketing',
            name: 'Sneaky'
        );

        $this->assertSame(0, $result->getIsDefault());
    }//end testCreateGroupSharedAlwaysStartsNonDefault()

    /**
     * REQ-DASH-017: updateGroupShared MUST drop the isDefault key from
     * the patch before applying it. The PUT endpoint can never flip
     * the flag — only POST .../default can.
     *
     * @return void
     */
    public function testUpdateGroupSharedIgnoresIsDefaultInPatch(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $entity = new Dashboard();
        $entity->setUuid('uuid-a');
        $entity->setType(Dashboard::TYPE_GROUP_SHARED);
        $entity->setGroupId('marketing');
        $entity->setIsDefault(1);
        $entity->setName('Original');

        $this->dashboardMapper->method('findByUuid')->willReturn($entity);
        $this->dashboardMapper->method('update')->willReturnArgument(0);

        $result = $this->service->updateGroupShared(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-a',
            patch: ['name' => 'Renamed', 'isDefault' => 0]
        );

        $this->assertSame('Renamed', $result->getName());
        $this->assertSame(
            1,
            $result->getIsDefault(),
            'PUT must not flip the default off'
        );
    }//end testUpdateGroupSharedIgnoresIsDefaultInPatch()

    /**
     * Mirror of the previous test on the opposite direction — PUT with
     * isDefault=1 on a non-default row leaves the flag at 0.
     *
     * @return void
     */
    public function testUpdateGroupSharedCannotFlipDefaultOn(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $entity = new Dashboard();
        $entity->setUuid('uuid-b');
        $entity->setType(Dashboard::TYPE_GROUP_SHARED);
        $entity->setGroupId('marketing');
        $entity->setIsDefault(0);
        $entity->setName('Original');

        $this->dashboardMapper->method('findByUuid')->willReturn($entity);
        $this->dashboardMapper->method('update')->willReturnArgument(0);

        $result = $this->service->updateGroupShared(
            actorUserId: 'admin',
            groupId: 'marketing',
            uuid: 'uuid-b',
            patch: ['name' => 'Renamed', 'isDefault' => 1]
        );

        $this->assertSame('Renamed', $result->getName());
        $this->assertSame(
            0,
            $result->getIsDefault(),
            'PUT must not flip the default on'
        );
    }//end testUpdateGroupSharedCannotFlipDefaultOn()
}//end class
