<?php

/**
 * Unit tests for AdminSettingsService.
 *
 * @category Test
 * @package  OCA\MyDash\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Tests\Unit\Service;

use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Service\AdminSettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdminSettingsService.
 */
class AdminSettingsServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var AdminSettingsService
     */
    private AdminSettingsService $service;

    /**
     * Mock AdminSettingMapper.
     *
     * @var AdminSettingMapper&MockObject
     */
    private AdminSettingMapper&MockObject $settingMapper;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->service       = new AdminSettingsService(
            settingMapper: $this->settingMapper,
        );

    }//end setUp()

    /**
     * Test that getSettings() returns an array with all expected keys.
     *
     * @return void
     */
    public function testGetSettingsReturnsAllExpectedKeys(): void
    {
        $this->settingMapper->expects($this->once())
            ->method('getAllAsArray')
            ->willReturn([]);

        $result = $this->service->getSettings();

        self::assertArrayHasKey('defaultPermissionLevel', $result);
        self::assertArrayHasKey('allowUserDashboards', $result);
        self::assertArrayHasKey('allowMultipleDashboards', $result);
        self::assertArrayHasKey('defaultGridColumns', $result);

    }//end testGetSettingsReturnsAllExpectedKeys()

    /**
     * Test that getSettings() uses default values when no settings are stored.
     *
     * @return void
     */
    public function testGetSettingsUsesDefaultsWhenEmpty(): void
    {
        $this->settingMapper->method('getAllAsArray')->willReturn([]);

        $result = $this->service->getSettings();

        self::assertTrue($result['allowUserDashboards']);
        self::assertTrue($result['allowMultipleDashboards']);
        self::assertSame(12, $result['defaultGridColumns']);

    }//end testGetSettingsUsesDefaultsWhenEmpty()

    /**
     * Test that getSettings() uses stored values when available.
     *
     * @return void
     */
    public function testGetSettingsUsesStoredValues(): void
    {
        $stored = [
            AdminSetting::KEY_DEFAULT_GRID_COLUMNS       => 8,
            AdminSetting::KEY_ALLOW_USER_DASHBOARDS      => false,
            AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS  => false,
            AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL   => 'view_only',
        ];

        $this->settingMapper->method('getAllAsArray')->willReturn($stored);

        $result = $this->service->getSettings();

        self::assertSame(8, $result['defaultGridColumns']);
        self::assertFalse($result['allowUserDashboards']);
        self::assertFalse($result['allowMultipleDashboards']);
        self::assertSame('view_only', $result['defaultPermissionLevel']);

    }//end testGetSettingsUsesStoredValues()

}//end class
