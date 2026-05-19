<?php

/**
 * MetadataValidationServiceTest
 *
 * Per-type validation coverage for the dashboard-metadata-fields
 * capability (REQ-MDFL-006). Pure-PHP service — no DB or HTTP I/O.
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
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Service\MetadataValidationService;
use PHPUnit\Framework\TestCase;

class MetadataValidationServiceTest extends TestCase
{
    private MetadataValidationService $service;

    protected function setUp(): void
    {
        $this->service = new MetadataValidationService();
    }

    private function makeField(
        string $type,
        ?array $options = null,
        int $required = 0,
        string $label = 'Test'
    ): MetadataField {
        $field = new MetadataField();
        $field->setType($type);
        $field->setLabel($label);
        $field->setRequired($required);
        if ($options !== null) {
            $field->setOptionsArray($options);
        }
        return $field;
    }

    public function testTextAcceptsArbitraryString(): void
    {
        $field  = $this->makeField(MetadataField::TYPE_TEXT);
        $result = $this->service->validateValue('marketing', $field);
        $this->assertSame('marketing', $result);
    }

    public function testTextEmptyOptionalReturnsEmptyString(): void
    {
        $field  = $this->makeField(MetadataField::TYPE_TEXT);
        $result = $this->service->validateValue('', $field);
        $this->assertSame('', $result);
    }

    public function testRequiredFieldEmptyValueRejected(): void
    {
        $field = $this->makeField(MetadataField::TYPE_TEXT, null, 1, 'Department');
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field 'Department' is required");
        $this->service->validateValue('', $field);
    }

    public function testNumberAcceptsDecimal(): void
    {
        $field  = $this->makeField(MetadataField::TYPE_NUMBER);
        $result = $this->service->validateValue('42.75', $field);
        $this->assertSame('42.75', $result);
    }

    public function testNumberRejectsNonNumeric(): void
    {
        $field = $this->makeField(MetadataField::TYPE_NUMBER, null, 0, 'Priority');
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field 'Priority' must be a valid number");
        $this->service->validateValue('not-a-number', $field);
    }

    public function testDateAcceptsIso(): void
    {
        $field  = $this->makeField(MetadataField::TYPE_DATE);
        $result = $this->service->validateValue('2026-05-01', $field);
        $this->assertSame('2026-05-01', $result);
    }

    public function testDateRejectsMalformed(): void
    {
        $field = $this->makeField(MetadataField::TYPE_DATE, null, 0, 'Go Live');
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field 'Go Live' must be a valid date (YYYY-MM-DD)");
        $this->service->validateValue('invalid-date', $field);
    }

    public function testDateRejectsImpossibleDate(): void
    {
        $field = $this->makeField(MetadataField::TYPE_DATE, null, 0, 'Go Live');
        $this->expectException(InvalidMetadataFieldException::class);
        $this->service->validateValue('2026-02-30', $field);
    }

    public function testSelectAcceptsValidOption(): void
    {
        $field  = $this->makeField(
            MetadataField::TYPE_SELECT,
            ['open', 'closed', 'pending']
        );
        $result = $this->service->validateValue('open', $field);
        $this->assertSame('open', $result);
    }

    public function testSelectRejectsUnknownOption(): void
    {
        $field = $this->makeField(
            MetadataField::TYPE_SELECT,
            ['open', 'closed'],
            0,
            'Status'
        );
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field 'Status' value 'rejected' not in allowed options");
        $this->service->validateValue('rejected', $field);
    }

    public function testMultiSelectAcceptsValidArray(): void
    {
        $field  = $this->makeField(
            MetadataField::TYPE_MULTI_SELECT,
            ['feature', 'bug', 'docs']
        );
        $result = $this->service->validateValue(['feature', 'docs'], $field);
        $this->assertSame('["feature","docs"]', $result);
    }

    public function testMultiSelectAcceptsJsonString(): void
    {
        $field  = $this->makeField(
            MetadataField::TYPE_MULTI_SELECT,
            ['feature', 'bug']
        );
        $result = $this->service->validateValue('["feature"]', $field);
        $this->assertSame('["feature"]', $result);
    }

    public function testMultiSelectRejectsUnknownEntry(): void
    {
        $field = $this->makeField(
            MetadataField::TYPE_MULTI_SELECT,
            ['feature'],
            0,
            'Tags'
        );
        $this->expectException(InvalidMetadataFieldException::class);
        $this->service->validateValue(['feature', 'rogue'], $field);
    }

    public function testBooleanCanonicalises(): void
    {
        $field = $this->makeField(MetadataField::TYPE_BOOLEAN);
        $this->assertSame('1', $this->service->validateValue(true, $field));
        $this->assertSame('1', $this->service->validateValue('1', $field));
        $this->assertSame('0', $this->service->validateValue(false, $field));
        $this->assertSame('0', $this->service->validateValue('0', $field));
    }

    public function testBooleanRejectsArbitrary(): void
    {
        $field = $this->makeField(MetadataField::TYPE_BOOLEAN, null, 0, 'Active');
        $this->expectException(InvalidMetadataFieldException::class);
        $this->expectExceptionMessage("Field 'Active' must be boolean");
        $this->service->validateValue('yes', $field);
    }

    public function testNullValueOnOptionalReturnsEmpty(): void
    {
        $field  = $this->makeField(MetadataField::TYPE_TEXT);
        $result = $this->service->validateValue(null, $field);
        $this->assertSame('', $result);
    }
}
