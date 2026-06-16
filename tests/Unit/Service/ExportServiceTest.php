<?php

/**
 * ExportServiceTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\ExportService} covering the
 * `dashboard-export-import` capability — REQ-EXIM-001 (manifest + ZIP
 * shape), REQ-EXIM-002 (single-dashboard export), REQ-EXIM-003 (site
 * export with manifest counts).
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
use OCA\MyDash\Service\ExportService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors constructor.
 */
class ExportServiceTest extends TestCase
{

    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    private ExportService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboardMapper = $this->createMock(originalClassName: DashboardMapper::class);
        $this->placementMapper = $this->createMock(originalClassName: WidgetPlacementMapper::class);
        $this->groupManager    = $this->createMock(originalClassName: IGroupManager::class);

        $this->service = new ExportService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            groupManager: $this->groupManager,
            logger: new NullLogger(),
        );
    }

    /**
     * REQ-EXIM-001: manifest carries the schema version and metadata.
     *
     * @return void
     */
    public function testManifestStructure(): void
    {
        $manifest = $this->service->buildManifest(
            scope: 'site',
            dashboardCount: 4,
            currentUserId: 'admin'
        );

        $this->assertSame(expected: 1, actual: $manifest['schemaVersion']);
        $this->assertSame(expected: 'site', actual: $manifest['scope']);
        $this->assertSame(expected: 4, actual: $manifest['dashboardCount']);
        $this->assertSame(expected: 'admin', actual: $manifest['exportedBy']);
        $this->assertMatchesRegularExpression(
            pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            string: (string) $manifest['exportedAt']
        );
        $this->assertContains(needle: 'icons', haystack: $manifest['includedAssets']);
        $this->assertContains(needle: 'widgetUploads', haystack: $manifest['includedAssets']);
        $this->assertContains(needle: 'metadataFields', haystack: $manifest['includedAssets']);
    }

    /**
     * REQ-EXIM-002: single dashboard serializes with widgets attached.
     *
     * @return void
     */
    public function testSerializeDashboardEmbedsWidgets(): void
    {
        $dashboard = $this->makeDashboard(
            id: 17,
            uuid: 'aaa-111-222-333-zzz1'
        );

        $placement = new WidgetPlacement();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setWidgetId('hello-widget');
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setGridX(1);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setGridY(2);

        $this->placementMapper
            ->expects($this->once())
            ->method('findByDashboardId')
            ->with(dashboardId: 17)
            ->willReturn([$placement]);

        $payload = $this->service->serializeDashboard(dashboard: $dashboard);

        $this->assertSame(expected: 'aaa-111-222-333-zzz1', actual: $payload['uuid']);
        $this->assertCount(expectedCount: 1, haystack: $payload['widgets']);
        $this->assertSame(expected: 'hello-widget', actual: $payload['widgets'][0]['widgetId']);
        $this->assertArrayNotHasKey(key: 'id', array: $payload);
        $this->assertArrayNotHasKey(key: 'id', array: $payload['widgets'][0]);
        $this->assertArrayNotHasKey(key: 'dashboardId', array: $payload['widgets'][0]);
    }

    /**
     * REQ-EXIM-002: exporting a single dashboard produces a valid ZIP
     * containing manifest + dashboard JSON.
     *
     * @return void
     */
    public function testExportDashboardProducesValidArchive(): void
    {
        $uuid      = 'bbb-222-333-444-yyy2';
        $dashboard = $this->makeDashboard(id: 5, uuid: $uuid);

        $this->dashboardMapper
            ->expects($this->once())
            ->method('findByUuid')
            ->with(uuid: $uuid)
            ->willReturn($dashboard);

        $this->placementMapper
            ->expects($this->any())
            ->method('findByDashboardId')
            ->willReturn([]);

        $response = $this->service->exportDashboard(
            dashboardUuid: $uuid,
            currentUserId: 'admin'
        );

        // The StreamResponse holds the path to a temp file we created
        // — read it back and validate the archive contents.
        $reflection = new \ReflectionObject(object: $response);
        $prop       = $reflection->getProperty(name: 'filePath');
        $prop->setAccessible(accessible: true);
        $tempPath   = (string) $prop->getValue(object: $response);

        $this->assertFileExists(filename: $tempPath);

        $zip = new ZipArchive();
        $this->assertTrue(condition: $zip->open(filename: $tempPath) === true);

        $manifestRaw = $zip->getFromName(name: 'manifest.json');
        $this->assertNotFalse(condition: $manifestRaw);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(json: $manifestRaw, associative: true);
        $this->assertSame(expected: 1, actual: $manifest['schemaVersion']);
        $this->assertSame(expected: 'dashboard', actual: $manifest['scope']);
        $this->assertSame(expected: 1, actual: $manifest['dashboardCount']);

        $dashboardRaw = $zip->getFromName(name: 'dashboards/' . $uuid . '.json');
        $this->assertNotFalse(condition: $dashboardRaw);

        $zip->close();
        @unlink(filename: $tempPath);
    }

    /**
     * REQ-EXIM-003: site export aggregates admin templates and personal
     * dashboards and stamps the manifest with the count.
     *
     * @return void
     */
    public function testExportSiteCollectsAllDashboards(): void
    {
        $template = $this->makeDashboard(id: 1, uuid: 'tpl-uuid-1');
        $root     = $this->makeDashboard(id: 2, uuid: 'usr-uuid-1');

        $this->dashboardMapper
            ->method('findAdminTemplates')
            ->willReturn([$template]);
        $this->dashboardMapper
            ->method('findByParent')
            ->willReturn([$root]);
        $this->dashboardMapper
            ->method('findDescendants')
            ->willReturn([]);
        $this->groupManager
            ->method('search')
            ->willReturn([]);

        $this->placementMapper
            ->method('findByDashboardId')
            ->willReturn([]);

        $response = $this->service->exportSite(currentUserId: 'admin');

        $reflection = new \ReflectionObject(object: $response);
        $prop       = $reflection->getProperty(name: 'filePath');
        $prop->setAccessible(accessible: true);
        $tempPath   = (string) $prop->getValue(object: $response);

        $zip = new ZipArchive();
        $zip->open(filename: $tempPath);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(
            json: (string) $zip->getFromName(name: 'manifest.json'),
            associative: true
        );
        $this->assertSame(expected: 'site', actual: $manifest['scope']);
        $this->assertSame(expected: 2, actual: $manifest['dashboardCount']);

        $this->assertNotFalse(condition: $zip->getFromName(name: 'dashboards/tpl-uuid-1.json'));
        $this->assertNotFalse(condition: $zip->getFromName(name: 'dashboards/usr-uuid-1.json'));
        $this->assertNotFalse(condition: $zip->getFromName(name: 'metadata-fields.json'));

        $zip->close();
        @unlink(filename: $tempPath);
    }

    /**
     * Build a Dashboard entity for the test harness.
     *
     * @param int    $id   The DB primary key.
     * @param string $uuid The dashboard UUID.
     *
     * @return Dashboard
     */
    private function makeDashboard(int $id, string $uuid): Dashboard
    {
        $dashboard = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setId($id);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUuid($uuid);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setName('Test ' . $uuid);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setUserId('alice');
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setType(Dashboard::TYPE_USER);
        return $dashboard;
    }
}
