<?php

/**
 * MetadataServiceTest
 *
 * Unit tests for the dashboard-metadata-fields capability service
 * facade (REQ-MDFL-001..007). Mocks the two mappers + the validation
 * helper so the test exercises orchestration logic only — no DB I/O.
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

use OCA\MyDash\Db\MetadataField;
use OCA\MyDash\Db\MetadataFieldMapper;
use OCA\MyDash\Db\MetadataValue;
use OCA\MyDash\Db\MetadataValueMapper;
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Exception\MetadataFieldHasValuesException;
use OCA\MyDash\Service\MetadataService;
use OCA\MyDash\Service\MetadataValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetadataServiceTest extends TestCase
{
    /** @var MetadataFieldMapper&MockObject */
    private MetadataFieldMapper $fieldMapper;
    /** @var MetadataValueMapper&MockObject */
    private MetadataValueMapper $valueMapper;
    private MetadataValidationService $validationService;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    private MetadataService $service;

    protected function setUp(): void
    {
        $this->fieldMapper        = $this->createMock(MetadataFieldMapper::class);
        $this->valueMapper        = $this->createMock(MetadataValueMapper::class);
        $this->validationService  = new MetadataValidationService();
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->service            = new MetadataService(
            fieldMapper: $this->fieldMapper,
            valueMapper: $this->valueMapper,
            validationService: $this->validationService,
            logger: $this->logger,
        );
    }

    private function makeField(
        int $id,
        string $key,
        string $type = MetadataField::TYPE_TEXT,
        ?array $options = null,
        int $required = 0,
        string $label = 'Test'
    ): MetadataField {
        $field = new MetadataField();
        $field->setId($id);
        $field->setFieldKey($key);
        $field->setLabel($label);
        $field->setType($type);
        $field->setRequired($required);
        if ($options !== null) {
            $field->setOptionsArray($options);
        }
        return $field;
    }

    private function makeValue(int $fieldId, string $value, string $uuid = 'abc'): MetadataValue
    {
        $row = new MetadataValue();
        $row->setDashboardUuid($uuid);
        $row->setFieldId($fieldId);
        $row->setValue($value);
        return $row;
    }

    public function testCreateFieldDefinitionRejectsBadKey(): void
    {
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage('lowercase alphanumeric');
        $this->service->createFieldDefinition(
            key: 'Bad Key',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
        );
    }

    public function testCreateFieldDefinitionRejectsEmptyLabel(): void
    {
        $this->fieldMapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage('Field label must be 1..255');
        $this->service->createFieldDefinition(
            key: 'department',
            label: '',
            type: MetadataField::TYPE_TEXT,
        );
    }

    public function testCreateFieldDefinitionRejectsUnknownType(): void
    {
        $this->fieldMapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Unsupported field type 'fancy'");
        $this->service->createFieldDefinition(
            key: 'department',
            label: 'Department',
            type: 'fancy',
        );
    }

    public function testCreateFieldDefinitionRejectsSelectWithoutOptions(): void
    {
        $this->fieldMapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage('Select type requires non-empty options array');
        $this->service->createFieldDefinition(
            key: 'status',
            label: 'Status',
            type: MetadataField::TYPE_SELECT,
        );
    }

    public function testCreateFieldDefinitionRejectsTextWithOptions(): void
    {
        $this->fieldMapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Type 'text' does not support options");
        $this->service->createFieldDefinition(
            key: 'department',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
            options: ['foo'],
        );
    }

    public function testCreateFieldDefinitionRejectsDuplicateKey(): void
    {
        $existing = $this->makeField(5, 'department');
        $this->fieldMapper->method('findByKey')->willReturn($existing);

        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field key 'department' already exists");
        $this->service->createFieldDefinition(
            key: 'department',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
        );
    }

    public function testCreateFieldDefinitionPersistsValid(): void
    {
        $this->fieldMapper->method('findByKey')->willThrowException(new DoesNotExistException(''));
        $this->fieldMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (MetadataField $f): MetadataField {
                $f->setId(7);
                return $f;
            });

        $field = $this->service->createFieldDefinition(
            key: 'department',
            label: 'Department',
            type: MetadataField::TYPE_TEXT,
            required: 1,
        );

        $this->assertSame('department', $field->getFieldKey());
        $this->assertSame('Department', $field->getLabel());
        $this->assertSame(MetadataField::TYPE_TEXT, $field->getType());
        $this->assertSame(1, $field->getRequired());
        $this->assertNotNull($field->getCreatedAt());
    }

    public function testUpdateFieldDefinitionForbidsKeyRename(): void
    {
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage('Field key cannot be renamed');
        $this->service->updateFieldDefinition(
            id: 5,
            patch: ['key' => 'division'],
        );
    }

    public function testUpdateFieldDefinitionAllowsLabelAndSortOrder(): void
    {
        $field = $this->makeField(5, 'department');
        $this->fieldMapper->method('findById')->willReturn($field);
        $this->fieldMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static fn (MetadataField $f): MetadataField => $f);

        $updated = $this->service->updateFieldDefinition(
            id: 5,
            patch: ['label' => 'Department (Required)', 'sortOrder' => 100],
        );

        $this->assertSame('Department (Required)', $updated->getLabel());
        $this->assertSame(100, $updated->getSortOrder());
        $this->assertNotNull($updated->getUpdatedAt());
    }

    public function testDeleteFieldDefinitionWithoutCascadeRejectsWhenValuesExist(): void
    {
        $field = $this->makeField(5, 'department');
        $this->fieldMapper->method('findById')->willReturn($field);
        $this->fieldMapper->method('countValuesForField')->willReturn(3);

        $this->expectException(MetadataFieldHasValuesException::class);
        try {
            $this->service->deleteFieldDefinition(id: 5, cascade: false);
        } catch (MetadataFieldHasValuesException $e) {
            $this->assertSame(3, $e->getValueCount());
            throw $e;
        }
    }

    public function testDeleteFieldDefinitionCascadeWipesValues(): void
    {
        $field = $this->makeField(5, 'department');
        $this->fieldMapper->method('findById')->willReturn($field);
        $this->fieldMapper->method('countValuesForField')->willReturn(3);
        $this->fieldMapper->expects($this->once())
            ->method('deleteWithCascade')
            ->with($this->equalTo(5))
            ->willReturn(true);

        $this->assertTrue($this->service->deleteFieldDefinition(id: 5, cascade: true));
    }

    public function testGetMetadataForDashboardReturnsKeyValueMap(): void
    {
        $rows = [
            $this->makeValue(1, 'marketing'),
            $this->makeValue(2, '8'),
        ];
        $fields = [
            1 => $this->makeField(1, 'department'),
            2 => $this->makeField(2, 'priority'),
        ];

        $this->valueMapper->method('findByDashboard')->willReturn($rows);
        $this->fieldMapper->method('findByIds')->willReturn($fields);

        $result = $this->service->getMetadataForDashboard('abc');
        $this->assertSame([
            'department' => 'marketing',
            'priority'   => '8',
        ], $result);
    }

    public function testGetMetadataForDashboardSkipsOrphans(): void
    {
        $rows = [
            $this->makeValue(1, 'marketing'),
            $this->makeValue(99, 'stale'),
        ];
        $fields = [
            1 => $this->makeField(1, 'department'),
        ];

        $this->valueMapper->method('findByDashboard')->willReturn($rows);
        $this->fieldMapper->method('findByIds')->willReturn($fields);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->getMetadataForDashboard('abc');
        $this->assertSame(['department' => 'marketing'], $result);
    }

    public function testGetMetadataForDashboardEmpty(): void
    {
        $this->valueMapper->method('findByDashboard')->willReturn([]);
        $this->assertSame([], $this->service->getMetadataForDashboard('abc'));
    }

    public function testSetMetadataRejectsUnknownKey(): void
    {
        $this->fieldMapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Unknown metadata field 'mystery'");
        $this->service->setMetadataForDashboard('abc', ['mystery' => 'x']);
    }

    public function testSetMetadataUpsertsValidValues(): void
    {
        $field = $this->makeField(1, 'department');
        $this->fieldMapper->method('findByKey')->willReturn($field);
        $this->valueMapper->expects($this->once())
            ->method('upsert')
            ->with('abc', 1, 'marketing')
            ->willReturn($this->makeValue(1, 'marketing'));

        // After upsert the read path resolves the same row.
        $this->valueMapper->method('findByDashboard')
            ->willReturn([$this->makeValue(1, 'marketing')]);
        $this->fieldMapper->method('findByIds')->willReturn([1 => $field]);

        $result = $this->service->setMetadataForDashboard(
            'abc',
            ['department' => 'marketing']
        );
        $this->assertSame(['department' => 'marketing'], $result);
    }

    public function testSetMetadataValidatesNumber(): void
    {
        $field = $this->makeField(1, 'priority', MetadataField::TYPE_NUMBER, null, 0, 'Priority');
        $this->fieldMapper->method('findByKey')->willReturn($field);

        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field 'Priority' must be a valid number");
        $this->service->setMetadataForDashboard(
            'abc',
            ['priority' => 'banana']
        );
    }

    public function testFilterDashboardsTextExactMatch(): void
    {
        $field = $this->makeField(1, 'department');
        $this->fieldMapper->method('findByKey')
            ->willReturnCallback(static function (string $key) use ($field): MetadataField {
                if ($key === 'department') {
                    return $field;
                }
                throw new DoesNotExistException('');
            });

        $this->valueMapper->method('findByField')
            ->willReturn([
                $this->makeValue(1, 'marketing', 'uuid-a'),
                $this->makeValue(1, 'sales', 'uuid-b'),
            ]);

        $dashboards = [
            ['uuid' => 'uuid-a', 'name' => 'A'],
            ['uuid' => 'uuid-b', 'name' => 'B'],
            ['uuid' => 'uuid-c', 'name' => 'C'],
        ];

        $result = $this->service->filterDashboards(
            $dashboards,
            ['department' => 'marketing']
        );

        $this->assertCount(1, $result);
        $this->assertSame('uuid-a', $result[0]['uuid']);
    }

    public function testFilterDashboardsNumberRange(): void
    {
        $field = $this->makeField(1, 'priority', MetadataField::TYPE_NUMBER);
        $this->fieldMapper->method('findByKey')->willReturn($field);
        $this->valueMapper->method('findByField')
            ->willReturn([
                $this->makeValue(1, '1', 'd1'),
                $this->makeValue(1, '5', 'd2'),
                $this->makeValue(1, '7', 'd3'),
                $this->makeValue(1, '9', 'd4'),
            ]);

        $dashboards = [
            ['uuid' => 'd1'],
            ['uuid' => 'd2'],
            ['uuid' => 'd3'],
            ['uuid' => 'd4'],
        ];

        $result = $this->service->filterDashboards(
            $dashboards,
            ['priority' => ['min' => 5, 'max' => 7]]
        );

        $uuids = array_map(static fn ($d) => $d['uuid'], $result);
        $this->assertSame(['d2', 'd3'], $uuids);
    }

    public function testFilterDashboardsAndsMultipleFilters(): void
    {
        $deptField     = $this->makeField(1, 'department');
        $priorityField = $this->makeField(2, 'priority', MetadataField::TYPE_NUMBER);
        $this->fieldMapper->method('findByKey')
            ->willReturnCallback(static function (string $key) use ($deptField, $priorityField) {
                return $key === 'department' ? $deptField : $priorityField;
            });

        $this->valueMapper->method('findByField')
            ->willReturnCallback(function (int $fieldId): array {
                if ($fieldId === 1) {
                    return [
                        $this->makeValue(1, 'marketing', 'd1'),
                        $this->makeValue(1, 'marketing', 'd2'),
                    ];
                }
                return [
                    $this->makeValue(2, '5', 'd1'),
                    $this->makeValue(2, '2', 'd2'),
                ];
            });

        $dashboards = [['uuid' => 'd1'], ['uuid' => 'd2']];

        $result = $this->service->filterDashboards(
            $dashboards,
            ['department' => 'marketing', 'priority' => ['min' => 5]]
        );

        $this->assertCount(1, $result);
        $this->assertSame('d1', $result[0]['uuid']);
    }

    public function testFilterDashboardsIgnoresUnknownFilterKey(): void
    {
        $this->fieldMapper->method('findByKey')
            ->willThrowException(new DoesNotExistException(''));
        $dashboards = [['uuid' => 'd1']];
        $result = $this->service->filterDashboards(
            $dashboards,
            ['mystery' => 'x']
        );
        // Unknown filter is silently dropped → all dashboards returned.
        $this->assertSame($dashboards, $result);
    }
}
