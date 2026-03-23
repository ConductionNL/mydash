<?php

/**
 * AdminSetting Entity Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\MyDash\Db\AdminSetting;
use PHPUnit\Framework\TestCase;

class AdminSettingTest extends TestCase
{
    private AdminSetting $setting;

    protected function setUp(): void
    {
        $this->setting = new AdminSetting();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->setting->getFieldTypes();
        $this->assertSame('integer', $fieldTypes['id']);
    }

    public function testConstants(): void
    {
        $this->assertSame('default_permission_level', AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL);
        $this->assertSame('allow_user_dashboards', AdminSetting::KEY_ALLOW_USER_DASHBOARDS);
        $this->assertSame('allow_multiple_dashboards', AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS);
        $this->assertSame('default_grid_columns', AdminSetting::KEY_DEFAULT_GRID_COLUMNS);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame('', $this->setting->getSettingKey());
        $this->assertNull($this->setting->getSettingValue());
        $this->assertNull($this->setting->getUpdatedAt());
    }

    public function testSetAndGetSettingKey(): void
    {
        $this->setting->setSettingKey('allow_user_dashboards');
        $this->assertSame('allow_user_dashboards', $this->setting->getSettingKey());
    }

    public function testSetAndGetSettingValue(): void
    {
        $this->setting->setSettingValue(json_encode(true));
        $this->assertSame('true', $this->setting->getSettingValue());
    }

    public function testGetValueDecoded(): void
    {
        $this->setting->setSettingValue(json_encode(true));
        $this->assertTrue($this->setting->getValueDecoded());

        $this->setting->setSettingValue(json_encode(false));
        $this->assertFalse($this->setting->getValueDecoded());

        $this->setting->setSettingValue(json_encode(12));
        $this->assertSame(12, $this->setting->getValueDecoded());

        $this->setting->setSettingValue(json_encode('add_only'));
        $this->assertSame('add_only', $this->setting->getValueDecoded());
    }

    public function testGetValueDecodedNull(): void
    {
        $this->assertNull($this->setting->getValueDecoded());
    }

    public function testSetValueEncoded(): void
    {
        $this->setting->setValueEncoded(true);
        $this->assertTrue($this->setting->getValueDecoded());

        $this->setting->setValueEncoded(42);
        $this->assertSame(42, $this->setting->getValueDecoded());

        $this->setting->setValueEncoded('full');
        $this->assertSame('full', $this->setting->getValueDecoded());
    }

    public function testJsonSerialize(): void
    {
        $now = new DateTime();
        $this->setting->setSettingKey('allow_user_dashboards');
        $this->setting->setValueEncoded(true);
        $this->setting->setUpdatedAt($now);

        $serialized = $this->setting->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame('allow_user_dashboards', $serialized['key']);
        $this->assertTrue($serialized['value']);
        $this->assertSame($now->format('c'), $serialized['updatedAt']);
        $this->assertArrayHasKey('id', $serialized);
    }

    public function testJsonSerializeNullUpdatedAt(): void
    {
        $serialized = $this->setting->jsonSerialize();
        $this->assertNull($serialized['updatedAt']);
    }
}
