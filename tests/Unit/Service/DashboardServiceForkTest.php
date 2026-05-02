<?php

/**
 * DashboardServiceForkTest
 *
 * Unit tests for the fork-current-as-personal change. Covers
 * REQ-DASH-020 (fork any visible dashboard), REQ-DASH-021 (transactional
 * rollback on placement-clone failure), REQ-DASH-022 (resource URLs are
 * shared, not duplicated) and the REQ-ASET-003 gating layered on top of
 * the fork endpoint.
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
use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\DashboardFactory;
use OCA\MyDash\Service\DashboardResolver;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\TemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see DashboardService::forkAsPersonal()}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors the service constructor.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One scenario per spec bullet.
 */
class DashboardServiceForkTest extends TestCase
{

    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

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

    /** @var IL10N&MockObject */
    private $l10n;

    private DashboardService $service;

    /**
     * Set up fresh mocks per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->settingMapper   = $this->createMock(AdminSettingMapper::class);
        /** @var TemplateService&MockObject $templateService */
        $templateService       = $this->createMock(TemplateService::class);
        /** @var DashboardResolver&MockObject $dashResolver */
        $dashResolver          = $this->createMock(DashboardResolver::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);
        $this->adminTemplateService = $this->createMock(AdminTemplateService::class);
        $this->db              = $this->createMock(IDBConnection::class);
        $this->config          = $this->createMock(IConfig::class);
        $this->l10nFactory     = $this->createMock(IFactory::class);
        $this->l10n            = $this->createMock(IL10N::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger                = $this->createMock(LoggerInterface::class);

        // Default: l10n factory returns the IL10N mock and `t()`
        // echoes the source name into the placeholder so the test can
        // assert the canonical fallback shape.
        $this->l10nFactory
            ->method('get')
            ->willReturn($this->l10n);
        $this->l10n
            ->method('t')
            ->willReturnCallback(function (string $text, array $params = []): string {
                if ($text === 'My copy of %s' && isset($params[0]) === true) {
                    return 'My copy of '.$params[0];
                }
                return $text;
            });

        $this->service = new DashboardService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingMapper: $this->settingMapper,
            templateService: $templateService,
            dashboardFactory: new DashboardFactory(),
            dashResolver: $dashResolver,
            groupManager: $this->groupManager,
            adminTemplateService: $this->adminTemplateService,
            db: $this->db,
            config: $this->config,
            l10nFactory: $this->l10nFactory,
            logger: $logger,
        );
    }//end setUp()

    /**
     * REQ-ASET-003 (extended): fork MUST throw before any DB write
     * when the admin flag is off.
     *
     * @return void
     */
    public function testForkThrowsWhenPersonalDashboardsDisabled(): void
    {
        // settingMapper returns false -> assertPersonalDashboardsAllowed
        // throws.
        $this->settingMapper->method('getValue')->willReturn(false);

        // No DB writes MUST happen — assert the strict expectation.
        $this->db->expects($this->never())->method('beginTransaction');
        $this->dashboardMapper->expects($this->never())->method('insert');
        $this->placementMapper->expects($this->never())->method('cloneToDashboard');

        $this->expectException(PersonalDashboardsDisabledException::class);

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid',
            name: null
        );
    }//end testForkThrowsWhenPersonalDashboardsDisabled()

    /**
     * REQ-DASH-020: source UUID not visible to the user MUST surface
     * as a 404 (DoesNotExistException at the service boundary) without
     * leaking existence.
     *
     * @return void
     */
    public function testForkRaisesNotFoundWhenSourceNotVisible(): void
    {
        $this->settingMapper->method('getValue')->willReturn(true);

        $this->stubVisibleToUser(userId: 'alice', visible: []);

        $this->db->expects($this->never())->method('beginTransaction');
        $this->dashboardMapper->expects($this->never())->method('insert');

        $this->expectException(DoesNotExistException::class);

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'unknown-uuid',
            name: null
        );
    }//end testForkRaisesNotFoundWhenSourceNotVisible()

    /**
     * REQ-DASH-020: happy path forks a visible group-shared dashboard
     * — new row is type=user, owner=alice, isDefault=0, groupId=null,
     * isActive=1, gridColumns copied; placements cloned via mapper.
     *
     * @return void
     */
    public function testForkHappyPathClonesGroupSharedSource(): void
    {
        $this->settingMapper->method('getValue')->willReturn(true);

        $source = $this->makeDashboard(
            uuid: 'src-uuid',
            name: 'Marketing Overview',
            type: Dashboard::TYPE_GROUP_SHARED,
            userId: null,
            groupId: 'marketing',
            id: 42,
            gridColumns: 16
        );

        $this->stubVisibleToUser(
            userId: 'alice',
            visible: [
                ['dashboard' => $source, 'source' => Dashboard::SOURCE_GROUP],
            ]
        );

        // Single transaction (REQ-DASH-021).
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $captured = null;
        $this->dashboardMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Dashboard $d) use (&$captured): Dashboard {
                $d->setId(7);
                $captured = $d;
                return $d;
            });
        $this->dashboardMapper
            ->expects($this->once())
            ->method('deactivateAllForUser')
            ->with('alice');

        // REQ-DASH-020: cloneToDashboard called with source id and the
        // newly persisted fork id.
        $this->placementMapper
            ->expects($this->once())
            ->method('cloneToDashboard')
            ->with(42, 7)
            ->willReturn(4);

        // REQ-DASH-018/019: active-pref pinned to the new uuid.
        $this->config
            ->expects($this->once())
            ->method('setUserValue')
            ->with(
                'alice',
                'mydash',
                DashboardService::ACTIVE_DASHBOARD_UUID_PREF_KEY,
                $this->isType('string')
            );

        $fork = $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid',
            name: 'My Marketing'
        );

        $this->assertNotNull($captured);
        $this->assertSame(Dashboard::TYPE_USER, $captured->getType());
        $this->assertSame('alice', $captured->getUserId());
        $this->assertNull($captured->getGroupId());
        $this->assertSame(0, $captured->getIsDefault());
        $this->assertSame(1, $captured->getIsActive());
        $this->assertSame(16, $captured->getGridColumns());
        $this->assertSame('My Marketing', $captured->getName());
        $this->assertSame(7, $fork->getId());
    }//end testForkHappyPathClonesGroupSharedSource()

    /**
     * REQ-DASH-020 scenario: empty body uses
     * `t('My copy of %s', [source.name])` as the default name.
     *
     * @return void
     */
    public function testForkDefaultsToLocalisedNameWhenBodyOmitsName(): void
    {
        $this->settingMapper->method('getValue')->willReturn(true);

        $source = $this->makeDashboard(
            uuid: 'src-uuid',
            name: 'Marketing Overview',
            type: Dashboard::TYPE_GROUP_SHARED,
            userId: null,
            groupId: 'marketing',
            id: 42
        );

        $this->stubVisibleToUser(
            userId: 'alice',
            visible: [
                ['dashboard' => $source, 'source' => Dashboard::SOURCE_GROUP],
            ]
        );

        $captured = null;
        $this->dashboardMapper
            ->method('insert')
            ->willReturnCallback(function (Dashboard $d) use (&$captured): Dashboard {
                $d->setId(7);
                $captured = $d;
                return $d;
            });

        $this->placementMapper->method('cloneToDashboard')->willReturn(0);

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid',
            name: null
        );

        $this->assertNotNull($captured);
        $this->assertSame('My copy of Marketing Overview', $captured->getName());
    }//end testForkDefaultsToLocalisedNameWhenBodyOmitsName()

    /**
     * REQ-DASH-021: any throwable from the placement clone rolls back
     * the entire transaction and re-throws.
     *
     * @return void
     */
    public function testForkRollsBackOnPlacementCloneFailure(): void
    {
        $this->settingMapper->method('getValue')->willReturn(true);

        $source = $this->makeDashboard(
            uuid: 'src-uuid',
            name: 'Marketing Overview',
            type: Dashboard::TYPE_GROUP_SHARED,
            userId: null,
            groupId: 'marketing',
            id: 42
        );

        $this->stubVisibleToUser(
            userId: 'alice',
            visible: [
                ['dashboard' => $source, 'source' => Dashboard::SOURCE_GROUP],
            ]
        );

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $this->dashboardMapper
            ->method('insert')
            ->willReturnCallback(function (Dashboard $d): Dashboard {
                $d->setId(7);
                return $d;
            });

        // Placement clone fails — transaction MUST be rolled back.
        $this->placementMapper
            ->method('cloneToDashboard')
            ->willThrowException(new Exception('DB error during clone'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DB error during clone');

        $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'src-uuid',
            name: 'My Marketing'
        );
    }//end testForkRollsBackOnPlacementCloneFailure()

    /**
     * REQ-DASH-020 scenario: forking your own personal dashboard
     * works and produces an independent duplicate (different uuid,
     * type still `user`, owner unchanged).
     *
     * @return void
     */
    public function testForkPersonalDashboardCreatesIndependentDuplicate(): void
    {
        $this->settingMapper->method('getValue')->willReturn(true);

        $source = $this->makeDashboard(
            uuid: 'personal-src-uuid',
            name: 'Personal',
            type: Dashboard::TYPE_USER,
            userId: 'alice',
            groupId: null,
            id: 11
        );

        $this->stubVisibleToUser(
            userId: 'alice',
            visible: [
                ['dashboard' => $source, 'source' => Dashboard::SOURCE_USER],
            ]
        );

        $captured = null;
        $this->dashboardMapper
            ->method('insert')
            ->willReturnCallback(function (Dashboard $d) use (&$captured): Dashboard {
                $d->setId(99);
                $captured = $d;
                return $d;
            });
        $this->placementMapper->method('cloneToDashboard')->willReturn(2);

        $fork = $this->service->forkAsPersonal(
            userId: 'alice',
            sourceUuid: 'personal-src-uuid',
            name: null
        );

        $this->assertNotNull($captured);
        $this->assertSame(Dashboard::TYPE_USER, $captured->getType());
        $this->assertSame('alice', $captured->getUserId());
        $this->assertNotSame($source->getUuid(), $captured->getUuid());
        $this->assertSame(99, $fork->getId());
    }//end testForkPersonalDashboardCreatesIndependentDuplicate()

    /**
     * Helper: stub the AdminTemplateService routing layer so
     * `getVisibleToUser()` returns the supplied visible list.
     *
     * The visible-to-user resolver in the SUT calls
     * `adminTemplateService->getUserGroupIdsFor($userId)` (REQ-TMPL-013
     * single-source-of-truth wrapper for `IGroupManager`) then delegates
     * to the dashboard mapper's `findVisibleToUser`. Stubbing the mapper
     * end is enough — the routing helper only needs to return an array.
     *
     * @param string                                                  $userId  The user id.
     * @param array<int, array{dashboard: Dashboard, source: string}> $visible The visible-to-user list.
     *
     * @return void
     */
    private function stubVisibleToUser(string $userId, array $visible): void
    {
        $this->adminTemplateService
            ->method('getUserGroupIdsFor')
            ->with(userId: $userId)
            ->willReturn([]);
        $this->dashboardMapper
            ->method('findVisibleToUser')
            ->willReturn($visible);
    }//end stubVisibleToUser()

    /**
     * Helper: build a Dashboard entity with the fields the tests care
     * about pre-populated.
     *
     * @param string      $uuid        The dashboard UUID.
     * @param string      $name        The dashboard name.
     * @param string      $type        The dashboard type.
     * @param string|null $userId      The owner user id (null for group_shared).
     * @param string|null $groupId     The group id (null for user-type).
     * @param int         $id          The primary key.
     * @param int         $gridColumns The grid column count.
     *
     * @return Dashboard The populated entity.
     */
    private function makeDashboard(
        string $uuid,
        string $name,
        string $type,
        ?string $userId,
        ?string $groupId,
        int $id,
        int $gridColumns=12
    ): Dashboard {
        $dashboard = new Dashboard();
        $dashboard->setId($id);
        $dashboard->setUuid($uuid);
        $dashboard->setName($name);
        $dashboard->setType($type);
        $dashboard->setUserId($userId);
        $dashboard->setGroupId($groupId);
        $dashboard->setGridColumns($gridColumns);
        $dashboard->setIsDefault(0);
        $dashboard->setIsActive(0);
        return $dashboard;
    }//end makeDashboard()
}//end class
