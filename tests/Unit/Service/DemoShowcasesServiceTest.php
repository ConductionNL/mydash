<?php

/**
 * DemoShowcasesServiceTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\DemoShowcasesService} covering
 * the `demo-data-showcases` capability — REQ-DEMO-001 (bundled
 * archives), REQ-DEMO-003 (install path), REQ-DEMO-004 (idempotency),
 * REQ-DEMO-005 (widget skip-on-missing), REQ-DEMO-006 (uninstall).
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

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\ShowcaseNotFoundException;
use OCA\MyDash\Service\DemoShowcasesService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Dashboard\IManager;
use OCP\Dashboard\IWidget;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors constructor.
 */
class DemoShowcasesServiceTest extends TestCase
{
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var IDBConnection&MockObject */
    private $db;

    /** @var IAppConfig&MockObject */
    private $appConfig;

    /** @var IManager&MockObject */
    private $dashboardManager;

    private DemoShowcasesService $service;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardMapper  = $this->createMock(originalClassName: DashboardMapper::class);
        $this->placementMapper  = $this->createMock(originalClassName: WidgetPlacementMapper::class);
        $this->db               = $this->createMock(originalClassName: IDBConnection::class);
        $this->appConfig        = $this->createMock(originalClassName: IAppConfig::class);
        $this->dashboardManager = $this->createMock(originalClassName: IManager::class);

        $this->service = new DemoShowcasesService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            db: $this->db,
            appConfig: $this->appConfig,
            dashboardManager: $this->dashboardManager,
            logger: new NullLogger(),
        );

        $this->fixtureDir = sys_get_temp_dir().'/mydash-showcase-fixture-'.uniqid();
        mkdir(directory: $this->fixtureDir, permissions: 0o755, recursive: true);
        $this->service->setDataDirForTesting(path: $this->fixtureDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dir: $this->fixtureDir);
        parent::tearDown();
    }

    /**
     * REQ-DEMO-001: missing ZIP yields a `null` descriptor and the
     * showcase is skipped from the available list.
     */
    public function testDescribeShowcaseReturnsNullWhenZipMissing(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $result = $this->service->describeShowcase(showcaseId: 'de-bron');
        $this->assertNull(actual: $result);
    }

    /**
     * REQ-DEMO-001 + REQ-DEMO-002: a present ZIP with valid manifest
     * produces a descriptor with name + thumbnail URL + install state.
     */
    public function testDescribeShowcasePopulatesDescriptor(): void
    {
        $this->writeFixtureZip(
            showcaseId: 'de-bron',
            manifest: [
                'schemaVersion' => 1,
                'showcaseName' => 'De Bron',
                'showcaseDescription' => 'Zorginstelling',
                'showcaseLanguage' => 'nl',
            ],
            dashboardPayload: $this->validDashboardPayload(uuid: 'dashbrd-uuid', widgets: []),
        );

        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->service->describeShowcase(showcaseId: 'de-bron');
        $this->assertIsArray(actual: $result);
        $this->assertSame(expected: 'de-bron', actual: $result['id']);
        $this->assertSame(expected: 'De Bron', actual: $result['name']);
        $this->assertSame(expected: 'nl', actual: $result['language']);
        $this->assertFalse(condition: $result['isInstalled']);
        $this->assertNull(actual: $result['installedDashboardUuid']);
        $this->assertStringContainsString(needle: '/img/showcases/de-bron.png', haystack: $result['thumbnailUrl']);
    }

    /**
     * REQ-DEMO-002: the available list mirrors the `BUNDLED_IDS` order
     * and only includes showcases whose ZIPs are readable.
     */
    public function testGetAvailableShowcasesIteratesBundledIds(): void
    {
        $this->writeFixtureZip(
            showcaseId: 'de-bron',
            manifest: ['schemaVersion' => 1, 'showcaseName' => 'De Bron', 'showcaseLanguage' => 'nl'],
            dashboardPayload: $this->validDashboardPayload(uuid: 'a-uuid', widgets: []),
        );
        $this->writeFixtureZip(
            showcaseId: 'horizon-labs',
            manifest: ['schemaVersion' => 1, 'showcaseName' => 'Horizon Labs', 'showcaseLanguage' => 'nl'],
            dashboardPayload: $this->validDashboardPayload(uuid: 'b-uuid', widgets: []),
        );

        $this->appConfig->method('getValueString')->willReturn('');

        $available = $this->service->getAvailableShowcases();
        $ids = array_map(callback: static fn(array $row) => $row['id'], array: $available);
        $this->assertContains(needle: 'de-bron', haystack: $ids);
        $this->assertContains(needle: 'horizon-labs', haystack: $ids);
        $this->assertNotContains(needle: 'gemeente-duin', haystack: $ids);
    }

    /**
     * REQ-DEMO-003: install creates a `group_shared` dashboard with the
     * `default` group sentinel and stores the install UUID via IConfig.
     */
    public function testInstallCreatesGroupSharedDashboard(): void
    {
        $this->writeFixtureZip(
            showcaseId: 'de-bron',
            manifest: ['schemaVersion' => 1, 'showcaseName' => 'De Bron', 'showcaseLanguage' => 'nl'],
            dashboardPayload: $this->validDashboardPayload(uuid: 'src-uuid', widgets: []),
        );

        $this->dashboardManager->method('getWidgets')->willReturn([]);
        $this->appConfig->method('getValueString')->willReturn('');

        $persisted = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $persisted->setId(123);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $persisted->setUuid('installed-uuid');

        $this->dashboardMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Dashboard $entity) use ($persisted): Dashboard {
                $this->assertSame(expected: Dashboard::TYPE_GROUP_SHARED, actual: $entity->getType());
                $this->assertSame(expected: Dashboard::DEFAULT_GROUP_ID, actual: $entity->getGroupId());
                $this->assertSame(expected: Dashboard::PERMISSION_VIEW_ONLY, actual: $entity->getPermissionLevel());
                return $persisted;
            });

        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with('mydash', 'showcase_installed_de-bron', 'installed-uuid');

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $result = $this->service->installShowcase(showcaseId: 'de-bron', lang: 'nl');

        $this->assertSame(expected: 'installed-uuid', actual: $result['installedDashboardUuid']);
        $this->assertFalse(condition: $result['alreadyInstalled']);
        $this->assertSame(expected: [], actual: $result['skippedWidgets']);
    }

    /**
     * REQ-DEMO-005: unknown widget IDs are dropped and surfaced in the
     * `skippedWidgets` response array; valid widgets are still placed.
     */
    public function testInstallSkipsUnknownWidgetTypes(): void
    {
        $this->writeFixtureZip(
            showcaseId: 'de-bron',
            manifest: ['schemaVersion' => 1, 'showcaseName' => 'De Bron', 'showcaseLanguage' => 'nl'],
            dashboardPayload: $this->validDashboardPayload(
                uuid: 'src-uuid',
                widgets: [
                    ['widgetId' => 'recommendations', 'gridX' => 0, 'gridY' => 0],
                    ['widgetId' => 'future-timeline', 'gridX' => 4, 'gridY' => 0],
                    ['widgetId' => 'mydash-tile', 'tileType' => 'shortcut', 'tileTitle' => 'Files', 'gridX' => 8, 'gridY' => 0],
                ]
            ),
        );

        $widget = $this->createMock(originalClassName: IWidget::class);
        $widget->method('getId')->willReturn('recommendations');
        $this->dashboardManager->method('getWidgets')->willReturn([$widget]);
        $this->appConfig->method('getValueString')->willReturn('');

        $persisted = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $persisted->setId(7);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $persisted->setUuid('installed-uuid');

        $this->dashboardMapper->method('insert')->willReturn($persisted);
        $this->placementMapper->expects($this->exactly(2))->method('insert')->willReturnCallback(
            static fn(WidgetPlacement $p): WidgetPlacement => $p
        );

        $result = $this->service->installShowcase(showcaseId: 'de-bron');

        $this->assertSame(expected: ['future-timeline'], actual: $result['skippedWidgets']);
    }

    /**
     * REQ-DEMO-004: a second install without `--force` returns the
     * already-installed UUID without persisting a new dashboard.
     */
    public function testInstallIsIdempotent(): void
    {
        $this->writeFixtureZip(
            showcaseId: 'de-bron',
            manifest: ['schemaVersion' => 1, 'showcaseName' => 'De Bron', 'showcaseLanguage' => 'nl'],
            dashboardPayload: $this->validDashboardPayload(uuid: 'src', widgets: []),
        );

        $this->appConfig->method('getValueString')->willReturn('previously-installed-uuid');
        $this->dashboardMapper->expects($this->never())->method('insert');

        $result = $this->service->installShowcase(showcaseId: 'de-bron');

        $this->assertSame(expected: 'previously-installed-uuid', actual: $result['installedDashboardUuid']);
        $this->assertTrue(condition: $result['alreadyInstalled']);
    }

    /**
     * REQ-DEMO-003: an unknown showcase ID raises a typed exception.
     */
    public function testUnknownShowcaseRaisesException(): void
    {
        $this->expectException(exception: ShowcaseNotFoundException::class);
        $this->service->installShowcase(showcaseId: 'not-a-real-id');
    }

    /**
     * REQ-DEMO-006: uninstall deletes the dashboard, cascades widgets,
     * and clears the install marker.
     */
    public function testUninstallDeletesDashboardAndClearsMarker(): void
    {
        $existing = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $existing->setId(42);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $existing->setUuid('installed-uuid');

        $this->appConfig->method('getValueString')->willReturn('installed-uuid');
        $this->dashboardMapper
            ->method('findByUuid')
            ->with(uuid: 'installed-uuid')
            ->willReturn($existing);

        $this->placementMapper->expects($this->once())->method('deleteByDashboardId')->with(dashboardId: 42);
        $this->dashboardMapper->expects($this->once())->method('delete')->with(entity: $existing);
        $this->appConfig
            ->expects($this->once())
            ->method('deleteKey')
            ->with('mydash', 'showcase_installed_de-bron');

        $this->service->uninstallShowcase(showcaseId: 'de-bron');
    }

    /**
     * REQ-DEMO-006: uninstall is a silent no-op when nothing is installed.
     */
    public function testUninstallIsIdempotent(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->dashboardMapper->expects($this->never())->method('delete');
        $this->placementMapper->expects($this->never())->method('deleteByDashboardId');
        $this->appConfig->expects($this->never())->method('deleteKey');

        $this->service->uninstallShowcase(showcaseId: 'de-bron');
    }

    /**
     * REQ-DEMO-006: uninstall tolerates a dashboard already deleted
     * out-of-band — clears the marker without raising.
     */
    public function testUninstallTolerantWhenDashboardMissing(): void
    {
        $this->appConfig->method('getValueString')->willReturn('orphan-uuid');
        $this->dashboardMapper
            ->method('findByUuid')
            ->willThrowException(exception: new DoesNotExistException(msg: 'gone'));

        $this->appConfig
            ->expects($this->once())
            ->method('deleteKey')
            ->with('mydash', 'showcase_installed_de-bron');

        $this->service->uninstallShowcase(showcaseId: 'de-bron');
    }

    /**
     * REQ-DEMO-005: tile placements (with `tileType`) are always
     * considered valid regardless of the widget registry.
     */
    public function testPartitionWidgetsTreatsTilesAsValid(): void
    {
        $this->dashboardManager->method('getWidgets')->willReturn([]);

        [$valid, $skipped] = $this->service->partitionWidgets(widgets: [
            ['widgetId' => 'mydash-tile', 'tileType' => 'shortcut'],
            ['widgetId' => 'unknown-id'],
        ]);

        $this->assertCount(expectedCount: 1, haystack: $valid);
        $this->assertSame(expected: ['unknown-id'], actual: $skipped);
    }

    private function validDashboardPayload(string $uuid, array $widgets): array
    {
        return [
            'uuid' => $uuid,
            'name' => 'Showcase',
            'description' => 'Test',
            'widgets' => $widgets,
            'gridColumns' => 12,
        ];
    }

    private function writeFixtureZip(string $showcaseId, array $manifest, array $dashboardPayload): void
    {
        $dir = $this->fixtureDir.'/'.$showcaseId;
        if (is_dir(filename: $dir) === false) {
            mkdir(directory: $dir, permissions: 0o755, recursive: true);
        }

        $zipPath = $dir.'/'.$showcaseId.'.zip';
        $zip = new ZipArchive();
        $zip->open(filename: $zipPath, flags: ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            name: 'manifest.json',
            content: (string) json_encode(value: $manifest)
        );
        $zip->addFromString(
            name: 'dashboards/'.$dashboardPayload['uuid'].'.json',
            content: (string) json_encode(value: $dashboardPayload)
        );
        $zip->close();
    }

    private function rrmdir(string $dir): void
    {
        if (is_dir(filename: $dir) === false) {
            return;
        }

        foreach (scandir(directory: $dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            if (is_dir(filename: $path) === true) {
                $this->rrmdir(dir: $path);
                continue;
            }

            @unlink(filename: $path);
        }

        @rmdir(directory: $dir);
    }
}
