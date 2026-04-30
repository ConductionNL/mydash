<?php

/**
 * DashboardService Fork Test
 *
 * Unit tests for DashboardService::forkAsPersonal() covering
 * REQ-DASH-020..022:
 *   - Deep-copy preserves all placement fields byte-for-byte.
 *   - Default name via t('My copy of {name}').
 *   - Gated 403 when allow_user_dashboards is off.
 *   - 404 when source is not visible to the user.
 *   - Forking own personal dashboard creates an independent duplicate.
 *   - Transactional rollback when placement insert fails.
 *   - Resource URLs are shared (not duplicated) — REQ-DASH-022.
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

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for forkAsPersonal (REQ-DASH-020..022).
 */
class DashboardServiceForkTest extends TestCase
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

    /** @var IConfig&MockObject */
    private $config;

    /** @var IL10N&MockObject */
    private $l10n;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var DashboardService */
    private DashboardService $service;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
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
        $this->config           = $this->createMock(IConfig::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

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
            config: $this->config,
            l10n: $this->l10n,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Helper: build a stub user with the given group IDs.
     *
     * @param string[] $groupIds Group IDs to return.
     *
     * @return IUser&MockObject
     */
    private function makeUser(array $groupIds=[]): IUser
    {
        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')
            ->willReturn($user);
        $this->groupManager->method('getUserGroupIds')
            ->willReturn($groupIds);

        return $user;
    }//end makeUser()

    /**
     * Helper: build a stub source Dashboard with uuid and name set.
     *
     * @param string $uuid        Dashboard UUID.
     * @param string $name        Dashboard name.
     * @param int    $gridColumns Grid columns.
     * @param int    $id          Dashboard DB id.
     *
     * @return Dashboard
     */
    private function makeSourceDashboard(
        string $uuid,
        string $name,
        int $gridColumns=12,
        int $id=42
    ): Dashboard {
        $dash = new Dashboard();
        $dash->setId($id);
        $dash->setUuid($uuid);
        $dash->setName($name);
        $dash->setType(Dashboard::TYPE_GROUP_SHARED);
        $dash->setGroupId('marketing');
        $dash->setGridColumns($gridColumns);
        $dash->setIsDefault(0);
        $dash->setIsActive(0);

        return $dash;
    }//end makeSourceDashboard()

    /**
     * Helper: build a stub personal Dashboard returned by dashboardMapper->insert.
     *
     * @param int    $id   DB id.
     * @param string $uuid UUID.
     * @param string $name Name.
     *
     * @return Dashboard
     */
    private function makeNewDashboard(
        int $id=99,
        string $uuid='new-uuid',
        string $name='My copy of Source'
    ): Dashboard {
        $dash = new Dashboard();
        $dash->setId($id);
        $dash->setUuid($uuid);
        $dash->setName($name);
        $dash->setType(Dashboard::TYPE_USER);
        $dash->setIsActive(1);
        $dash->setIsDefault(0);
        $dash->setGridColumns(12);

        return $dash;
    }//end makeNewDashboard()

    /**
     * REQ-DASH-020: Fork throws PersonalDashboardsDisabledException when
     * the admin flag is off (gating → HTTP 403).
     *
     * @return void
     */
    public function testForkThrowsWhenFlagIsOff(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, false)
            ->willReturn(false);

        $this->expectException(PersonalDashboardsDisabledException::class);

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'some-uuid'
        );
    }//end testForkThrowsWhenFlagIsOff()

    /**
     * REQ-DASH-020: Fork throws DoesNotExistException when the source uuid
     * is not in the user's visible set (→ HTTP 404, no info leak).
     *
     * @return void
     */
    public function testForkThrowsNotFoundWhenSourceNotVisible(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_ALLOW_USER_DASHBOARDS, false)
            ->willReturn(true);

        $this->makeUser(['marketing']);

        // findVisibleToUser returns an empty list — source not visible.
        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([]);

        $this->expectException(DoesNotExistException::class);

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'invisible-uuid'
        );
    }//end testForkThrowsNotFoundWhenSourceNotVisible()

    /**
     * REQ-DASH-020: Happy path — fork uses the caller-supplied name.
     *
     * @return void
     */
    public function testForkUsesSuppliedName(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser(['marketing']);

        $source = $this->makeSourceDashboard(
            uuid: 'src-uuid',
            name: 'Source Dashboard',
            gridColumns: 10
        );

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => 'group'],
            ]);

        $newDash = $this->makeNewDashboard(
            id: 99,
            uuid: 'new-uuid',
            name: 'Custom Fork Name'
        );

        $this->dashboardFactory->expects($this->once())
            ->method('create')
            ->with(
                'alice',       // userId
                'Custom Fork Name', // name
                null,          // description
                Dashboard::TYPE_USER, // type
                null,          // groupId
                10             // gridColumns
            )
            ->willReturn($newDash);

        $this->dashboardMapper->method('insert')
            ->willReturn($newDash);
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $result = $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid',
            name: 'Custom Fork Name'
        );

        $this->assertSame('Custom Fork Name', $result->getName());
    }//end testForkUsesSuppliedName()

    /**
     * REQ-DASH-020: When no name is given, the fork MUST use
     * t('My copy of {name}', {name: source.name}).
     *
     * @return void
     */
    public function testForkUsesDefaultTranslatedName(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser([]);

        $source = $this->makeSourceDashboard(
            uuid: 'src-uuid',
            name: 'Marketing Overview'
        );

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => 'group'],
            ]);

        // Expect the translated default name to be built.
        $this->l10n->expects($this->once())
            ->method('t')
            ->with('My copy of {name}', ['name' => 'Marketing Overview'])
            ->willReturn('My copy of Marketing Overview');

        $newDash = $this->makeNewDashboard(
            name: 'My copy of Marketing Overview'
        );

        $this->dashboardFactory->expects($this->once())
            ->method('create')
            ->with(
                $this->anything(), // userId
                'My copy of Marketing Overview', // name
                $this->anything(), // description
                $this->anything(), // type
                $this->anything(), // groupId
                $this->anything()  // gridColumns
            )
            ->willReturn($newDash);

        $this->dashboardMapper->method('insert')->willReturn($newDash);
        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $result = $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid'
        );

        $this->assertSame('My copy of Marketing Overview', $result->getName());
    }//end testForkUsesDefaultTranslatedName()

    /**
     * REQ-DASH-021: Fork MUST roll back the transaction when placement
     * clone fails. The new dashboard row MUST NOT be visible.
     *
     * @return void
     */
    public function testForkRollsBackOnPlacementInsertFailure(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser([]);

        $source = $this->makeSourceDashboard(uuid: 'src-uuid', name: 'S');

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => 'group'],
            ]);

        $this->l10n->method('t')
            ->willReturn('My copy of S');

        $newDash = $this->makeNewDashboard(id: 77);
        $this->dashboardFactory->method('create')->willReturn($newDash);
        $this->dashboardMapper->method('insert')->willReturn($newDash);

        // Simulate placement clone failure.
        $this->placementMapper->method('cloneToDashboard')
            ->willThrowException(new RuntimeException('DB error'));

        $this->db->expects($this->once())->method('beginTransaction');
        // Rollback MUST be called on failure.
        $this->db->expects($this->once())->method('rollBack');
        // Commit MUST NOT be called.
        $this->db->expects($this->never())->method('commit');

        $this->expectException(RuntimeException::class);

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid'
        );
    }//end testForkRollsBackOnPlacementInsertFailure()

    /**
     * REQ-DASH-020: Forking a user's own personal dashboard creates an
     * independent duplicate (the source type is TYPE_USER).
     *
     * @return void
     */
    public function testForkOwnPersonalDashboardCreatesIndependentCopy(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser([]);

        // Source is the user's own personal dashboard.
        $source = new Dashboard();
        $source->setId(10);
        $source->setUuid('personal-uuid');
        $source->setName('My Dashboard');
        $source->setType(Dashboard::TYPE_USER);
        $source->setUserId('alice');
        $source->setGridColumns(12);

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => Dashboard::SOURCE_USER],
            ]);

        $this->l10n->method('t')
            ->willReturn('My copy of My Dashboard');

        $newDash = $this->makeNewDashboard(
            id: 55,
            uuid: 'fork-of-personal-uuid',
            name: 'My copy of My Dashboard'
        );

        $this->dashboardFactory->expects($this->once())
            ->method('create')
            ->with(
                'alice',                   // userId
                'My copy of My Dashboard', // name
                null,                      // description
                Dashboard::TYPE_USER,      // type
                null,                      // groupId
                12                         // gridColumns
            )
            ->willReturn($newDash);

        $this->dashboardMapper->method('insert')->willReturn($newDash);
        $this->placementMapper->expects($this->once())
            ->method('cloneToDashboard')
            ->with(10, 55);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $result = $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'personal-uuid'
        );

        $this->assertSame('fork-of-personal-uuid', $result->getUuid());
        $this->assertSame(Dashboard::TYPE_USER, $result->getType());
    }//end testForkOwnPersonalDashboardCreatesIndependentCopy()

    /**
     * REQ-DASH-022: Resource URLs in placements are NOT duplicated.
     *
     * This test verifies that cloneToDashboard is called with the source
     * and target IDs only — the service never reads or re-uploads resource
     * bytes. The WidgetPlacementMapper is responsible for preserving tile*
     * field values verbatim (tested in WidgetPlacementMapper tests).
     *
     * @return void
     */
    public function testForkDoesNotDuplicateResourceUrls(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser([]);

        $source = $this->makeSourceDashboard(
            uuid: 'src-uuid',
            name: 'Icon Dashboard',
            id: 11
        );

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => 'group'],
            ]);

        $this->l10n->method('t')
            ->willReturn('My copy of Icon Dashboard');

        $newDash = $this->makeNewDashboard(id: 88, uuid: 'new-icon-uuid');
        $this->dashboardFactory->method('create')->willReturn($newDash);
        $this->dashboardMapper->method('insert')->willReturn($newDash);

        // The service MUST call cloneToDashboard and pass through only IDs —
        // no resource-byte duplication logic should happen in the service.
        $this->placementMapper->expects($this->once())
            ->method('cloneToDashboard')
            ->with(
                $this->identicalTo(11),
                $this->identicalTo(88)
            );

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid'
        );
    }//end testForkDoesNotDuplicateResourceUrls()

    /**
     * REQ-DASH-020: Fork deactivates all existing user dashboards and
     * makes the new one active (isActive logic).
     *
     * @return void
     */
    public function testForkDeactivatesExistingDashboardsAndMakesNewActive(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser([]);

        $source = $this->makeSourceDashboard(uuid: 'src-uuid', name: 'S');

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => 'group'],
            ]);

        $this->l10n->method('t')
            ->willReturn('My copy of S');

        $newDash = $this->makeNewDashboard(
            id: 99,
            uuid: 'new-uuid'
        );
        $this->dashboardFactory->method('create')->willReturn($newDash);
        $this->dashboardMapper->method('insert')->willReturn($newDash);

        // deactivateAllForUser MUST be called before insert.
        $this->dashboardMapper->expects($this->once())
            ->method('deactivateAllForUser')
            ->with('alice');

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $result = $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid'
        );

        $this->assertSame(1, (int) $result->getIsActive());
    }//end testForkDeactivatesExistingDashboardsAndMakesNewActive()

    /**
     * REQ-DASH-019: Fork MUST persist the active-dashboard preference so
     * the REQ-DASH-018 resolver picks it up on next page load.
     *
     * @return void
     */
    public function testForkPersistsActiveDashboardPreference(): void
    {
        $this->settingMapper->method('getValue')
            ->willReturn(true);

        $this->makeUser([]);

        $source = $this->makeSourceDashboard(uuid: 'src-uuid', name: 'S');

        $this->dashboardMapper->method('findVisibleToUser')
            ->willReturn([
                ['dashboard' => $source, 'source' => 'group'],
            ]);

        $this->l10n->method('t')->willReturn('My copy of S');

        $newDash = $this->makeNewDashboard(id: 7, uuid: 'pref-uuid');
        $this->dashboardFactory->method('create')->willReturn($newDash);
        $this->dashboardMapper->method('insert')->willReturn($newDash);

        // The preference MUST be saved with the new dashboard UUID.
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(
                $this->identicalTo('alice'),
                $this->anything(),
                DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY,
                'pref-uuid'
            );

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid'
        );
    }//end testForkPersistsActiveDashboardPreference()
}//end class
