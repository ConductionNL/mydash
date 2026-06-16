<?php

/**
 * DashboardTranslation Entity Test
 *
 * Unit tests for the {@see DashboardTranslation} entity. REQ-DASH-038.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\MyDash\Db\DashboardTranslation;
use PHPUnit\Framework\TestCase;

class DashboardTranslationTest extends TestCase
{
    private DashboardTranslation $translation;

    protected function setUp(): void
    {
        $this->translation = new DashboardTranslation();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->translation->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
        $this->assertSame('integer', $fieldTypes['isPrimary']);
    }

    public function testDefaults(): void
    {
        $this->assertNull($this->translation->getDashboardUuid());
        $this->assertNull($this->translation->getLanguageCode());
        $this->assertNull($this->translation->getName());
        $this->assertNull($this->translation->getDescription());
        $this->assertNull($this->translation->getWidgetTreeJson());
        $this->assertSame(0, $this->translation->getIsPrimary());
        $this->assertNull($this->translation->getCreatedAt());
        $this->assertNull($this->translation->getUpdatedAt());
    }

    public function testSettersAndGetters(): void
    {
        $this->translation->setDashboardUuid('uuid-123');
        $this->translation->setLanguageCode('nl');
        $this->translation->setName('Mijn Dashboard');
        $this->translation->setDescription('Beschrijving');
        $this->translation->setWidgetTreeJson('{"widgets":[]}');
        $this->translation->setIsPrimary(1);
        $this->translation->setCreatedAt('2026-05-02 13:00:00');
        $this->translation->setUpdatedAt('2026-05-02 14:00:00');

        $this->assertSame('uuid-123', $this->translation->getDashboardUuid());
        $this->assertSame('nl', $this->translation->getLanguageCode());
        $this->assertSame('Mijn Dashboard', $this->translation->getName());
        $this->assertSame('Beschrijving', $this->translation->getDescription());
        $this->assertSame('{"widgets":[]}', $this->translation->getWidgetTreeJson());
        $this->assertSame(1, $this->translation->getIsPrimary());
        $this->assertSame('2026-05-02 13:00:00', $this->translation->getCreatedAt());
        $this->assertSame('2026-05-02 14:00:00', $this->translation->getUpdatedAt());
    }

    public function testJsonSerialize(): void
    {
        $this->translation->setDashboardUuid('uuid-abc');
        $this->translation->setLanguageCode('de');
        $this->translation->setName('Mein Dashboard');
        $this->translation->setDescription('Beschreibung');
        $this->translation->setWidgetTreeJson('{}');
        $this->translation->setIsPrimary(1);
        $this->translation->setCreatedAt('2026-01-01 00:00:00');
        $this->translation->setUpdatedAt('2026-01-02 00:00:00');

        $serialized = $this->translation->jsonSerialize();

        $this->assertSame('uuid-abc', $serialized['dashboardUuid']);
        $this->assertSame('de', $serialized['languageCode']);
        $this->assertSame('Mein Dashboard', $serialized['name']);
        $this->assertSame('Beschreibung', $serialized['description']);
        $this->assertSame('{}', $serialized['widgetTreeJson']);
        $this->assertSame(1, $serialized['isPrimary']);
        $this->assertSame('2026-01-01 00:00:00', $serialized['createdAt']);
        $this->assertSame('2026-01-02 00:00:00', $serialized['updatedAt']);
        $this->assertArrayHasKey('id', $serialized);
    }

    public function testDefaultLanguageConstant(): void
    {
        $this->assertSame('en', DashboardTranslation::DEFAULT_LANGUAGE);
    }
}
