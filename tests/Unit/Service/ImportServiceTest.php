<?php

/**
 * ImportServiceTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\ImportService} covering the
 * `dashboard-export-import` capability — REQ-EXIM-005 (UUID collisions),
 * REQ-EXIM-008 (manifest validation), REQ-EXIM-011 (per-dashboard
 * transactional import).
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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\ImportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors constructor.
 */
class ImportServiceTest extends TestCase
{

    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var IDBConnection&MockObject */
    private $db;

    private ImportService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboardMapper = $this->createMock(originalClassName: DashboardMapper::class);
        $this->placementMapper = $this->createMock(originalClassName: WidgetPlacementMapper::class);
        $this->db              = $this->createMock(originalClassName: IDBConnection::class);

        $this->service = new ImportService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            db: $this->db,
            logger: new NullLogger(),
        );
    }

    /**
     * REQ-EXIM-008: missing manifest.json is rejected with HTTP 400.
     *
     * @return void
     */
    public function testInvalidZipMissingManifest(): void
    {
        $zipPath = $this->makeZip(entries: ['dashboards/foo.json' => '{}']);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'manifest.json not found');

        try {
            $this->service->import(
                zipPath: $zipPath,
                preserveUuids: false,
                currentUserId: 'admin'
            );
        } finally {
            @unlink(filename: $zipPath);
        }
    }

    /**
     * REQ-EXIM-008: an unsupported schemaVersion is rejected.
     *
     * @return void
     */
    public function testUnsupportedSchemaVersion(): void
    {
        $manifest = (string) json_encode(value: [
            'schemaVersion' => 2,
            'scope'         => 'site',
        ]);
        $zipPath  = $this->makeZip(entries: ['manifest.json' => $manifest]);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Unsupported manifest schema version');

        try {
            $this->service->import(
                zipPath: $zipPath,
                preserveUuids: false,
                currentUserId: 'admin'
            );
        } finally {
            @unlink(filename: $zipPath);
        }
    }

    /**
     * REQ-EXIM-005: with `preserveUuids=true`, an existing UUID returns
     * a status flag that the controller maps to HTTP 409.
     *
     * @return void
     */
    public function testImportDashboardPreservingUuidsCollision(): void
    {
        $uuid    = 'abc-123-uuid-collision';
        $payload = [
            'uuid'    => $uuid,
            'name'    => 'Collide',
            'widgets' => [],
        ];

        $zipPath = $this->makeZip(entries: [
            'manifest.json' => (string) json_encode(value: [
                'schemaVersion' => 1,
                'scope'         => 'dashboard',
            ]),
            'dashboards/' . $uuid . '.json' => (string) json_encode(value: $payload),
        ]);

        $existing = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $existing->setUuid($uuid);

        $this->dashboardMapper
            ->method('findByUuid')
            ->with(uuid: $uuid)
            ->willReturn($existing);

        $result = $this->service->import(
            zipPath: $zipPath,
            preserveUuids: true,
            currentUserId: 'admin'
        );

        $this->assertSame(
            expected: ImportService::ERR_UUID_COLLISION,
            actual: $result['status']
        );
        $this->assertSame(expected: 0, actual: $result['importedDashboardCount']);
        $this->assertNotEmpty(actual: $result['errors']);
        $this->assertSame(
            expected: ImportService::ERR_UUID_COLLISION,
            actual: $result['errors'][0]['type']
        );
        @unlink(filename: $zipPath);
    }

    /**
     * REQ-EXIM-005: with `preserveUuids=false`, a colliding UUID is
     * remapped to a fresh UUID and the dashboard is imported.
     *
     * @return void
     */
    public function testImportDashboardFreshUuidsRemap(): void
    {
        $uuid = 'xyz-444-555-666-aaaa';

        $payload = [
            'uuid'    => $uuid,
            'name'    => 'Fresh',
            'widgets' => [],
        ];
        $zipPath = $this->makeZip(entries: [
            'manifest.json' => (string) json_encode(value: [
                'schemaVersion' => 1,
                'scope'         => 'dashboard',
            ]),
            'dashboards/' . $uuid . '.json' => (string) json_encode(value: $payload),
        ]);

        $this->dashboardMapper
            ->method('findByUuid')
            ->willThrowException(exception: new DoesNotExistException(msg: 'no'));

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $inserted = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $inserted->setId(99);
        $this->dashboardMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturn($inserted);

        $result = $this->service->import(
            zipPath: $zipPath,
            preserveUuids: false,
            currentUserId: 'admin'
        );

        $this->assertSame(expected: 'ok', actual: $result['status']);
        $this->assertSame(expected: 1, actual: $result['importedDashboardCount']);
        $this->assertSame(expected: 0, actual: $result['skippedDashboardCount']);
        @unlink(filename: $zipPath);
    }

    /**
     * REQ-EXIM-008: a dashboard JSON file missing a required field is
     * skipped and reported as an error rather than aborting the batch.
     *
     * @return void
     */
    public function testInvalidDashboardSkippedNotFatal(): void
    {
        $zipPath = $this->makeZip(entries: [
            'manifest.json' => (string) json_encode(value: [
                'schemaVersion' => 1,
                'scope'         => 'dashboard',
            ]),
            // Missing required `widgets` field.
            'dashboards/bad-uuid.json' => (string) json_encode(value: [
                'uuid' => 'bad-uuid',
                'name' => 'No widgets',
            ]),
        ]);

        $this->dashboardMapper
            ->method('findByUuid')
            ->willThrowException(exception: new DoesNotExistException(msg: 'no'));

        $result = $this->service->import(
            zipPath: $zipPath,
            preserveUuids: false,
            currentUserId: 'admin'
        );

        $this->assertSame(expected: 0, actual: $result['importedDashboardCount']);
        $this->assertSame(expected: 1, actual: $result['skippedDashboardCount']);
        $this->assertNotEmpty(actual: $result['errors']);
        $this->assertSame(
            expected: ImportService::ERR_INVALID_DASHBOARD,
            actual: $result['errors'][0]['type']
        );
        @unlink(filename: $zipPath);
    }

    /**
     * REQ-EXIM-011: a corrupt widget JSON triggers a per-dashboard
     * rollback while sibling dashboards still import successfully.
     *
     * @return void
     */
    public function testPartialFailureRollsBackOneDashboard(): void
    {
        $goodUuid = 'good-uuid-001';
        $badUuid  = 'bad-uuid-002';

        $zipPath = $this->makeZip(entries: [
            'manifest.json' => (string) json_encode(value: [
                'schemaVersion' => 1,
                'scope'         => 'site',
            ]),
            'dashboards/' . $goodUuid . '.json' => (string) json_encode(value: [
                'uuid'    => $goodUuid,
                'name'    => 'Good',
                'widgets' => [],
            ]),
            'dashboards/' . $badUuid . '.json' => (string) json_encode(value: [
                'uuid'    => $badUuid,
                'name'    => 'Bad',
                'widgets' => [],
            ]),
        ]);

        $this->dashboardMapper
            ->method('findByUuid')
            ->willThrowException(exception: new DoesNotExistException(msg: 'no'));

        $this->db->expects($this->exactly(2))->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $okEntity = new Dashboard();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $okEntity->setId(7);

        $this->dashboardMapper
            ->expects($this->exactly(2))
            ->method('insert')
            ->willReturnOnConsecutiveCalls(
                $okEntity,
                $this->throwException(exception: new \RuntimeException(message: 'boom'))
            );

        $result = $this->service->import(
            zipPath: $zipPath,
            preserveUuids: false,
            currentUserId: 'admin'
        );

        $this->assertSame(expected: 1, actual: $result['importedDashboardCount']);
        $this->assertSame(expected: 1, actual: $result['skippedDashboardCount']);
        $this->assertCount(expectedCount: 1, haystack: $result['errors']);

        @unlink(filename: $zipPath);
    }

    /**
     * REQ-EXIM-005: remapUuids assigns fresh v4 UUIDs and rewrites
     * `parentUuid` references to maintain tree integrity.
     *
     * @return void
     */
    public function testRemapUuidsRewritesParentReferences(): void
    {
        $original = [
            ['uuid' => 'parent-aaaa', 'name' => 'Parent', 'widgets' => []],
            [
                'uuid'       => 'child-bbbb',
                'parentUuid' => 'parent-aaaa',
                'name'       => 'Child',
                'widgets'    => [],
            ],
        ];

        $remapped = $this->service->remapUuids(
            dashboards: $original,
            preserveUuids: false
        );

        $this->assertNotSame(expected: 'parent-aaaa', actual: $remapped[0]['uuid']);
        $this->assertNotSame(expected: 'child-bbbb', actual: $remapped[1]['uuid']);
        $this->assertSame(
            expected: $remapped[0]['uuid'],
            actual: $remapped[1]['parentUuid'],
            message: 'parentUuid must follow the remap'
        );
    }

    /**
     * Build a temporary ZIP archive containing the provided entries.
     *
     * @param array<string, string> $entries Map of archive name to bytes.
     *
     * @return string Path to the temp ZIP file.
     */
    private function makeZip(array $entries): string
    {
        $path = (string) tempnam(directory: sys_get_temp_dir(), prefix: 'mydash-import-test-');
        $zip  = new ZipArchive();
        $zip->open(filename: $path, flags: ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString(name: $name, content: $content);
        }
        $zip->close();
        return $path;
    }
}
