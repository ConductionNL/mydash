<?php

/**
 * AdminSettingsService Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Service\AdminSettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class AdminSettingsServiceTest extends TestCase
{
    private AdminSettingsService $service;
    private AdminSettingMapper $settingMapper;

    protected function setUp(): void
    {
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->service = new AdminSettingsService(
            settingMapper: $this->settingMapper,
        );
    }

    public function testGetSettingsReturnsDefaults(): void
    {
        $this->settingMapper->method('getAllAsArray')->willReturn([]);

        $settings = $this->service->getSettings();

        $this->assertSame(Dashboard::PERMISSION_ADD_ONLY, $settings['defaultPermissionLevel']);
        $this->assertTrue($settings['allowUserDashboards']);
        $this->assertTrue($settings['allowMultipleDashboards']);
        $this->assertSame(12, $settings['defaultGridColumns']);
    }

    public function testGetSettingsReturnsStoredValues(): void
    {
        $this->settingMapper->method('getAllAsArray')->willReturn([
            AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL => 'view_only',
            AdminSetting::KEY_ALLOW_USER_DASHBOARDS => false,
            AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS => false,
            AdminSetting::KEY_DEFAULT_GRID_COLUMNS => 6,
        ]);

        $settings = $this->service->getSettings();

        $this->assertSame('view_only', $settings['defaultPermissionLevel']);
        $this->assertFalse($settings['allowUserDashboards']);
        $this->assertFalse($settings['allowMultipleDashboards']);
        $this->assertSame(6, $settings['defaultGridColumns']);
    }

    public function testGetSettingsPartialOverride(): void
    {
        $this->settingMapper->method('getAllAsArray')->willReturn([
            AdminSetting::KEY_ALLOW_USER_DASHBOARDS => false,
        ]);

        $settings = $this->service->getSettings();

        $this->assertSame(Dashboard::PERMISSION_ADD_ONLY, $settings['defaultPermissionLevel']);
        $this->assertFalse($settings['allowUserDashboards']);
        $this->assertTrue($settings['allowMultipleDashboards']);
        $this->assertSame(12, $settings['defaultGridColumns']);
    }

    public function testUpdateSettingsCallsMapperForEachProvided(): void
    {
        $this->settingMapper->expects($this->exactly(2))
            ->method('setSetting');

        $this->service->updateSettings(
            defaultPermLevel: 'full',
            allowUserDash: false,
        );
    }

    public function testUpdateSettingsSkipsNullValues(): void
    {
        $this->settingMapper->expects($this->once())
            ->method('setSetting');

        $this->service->updateSettings(
            defaultGridCols: 8,
        );
    }

    public function testUpdateSettingsWithNoValues(): void
    {
        $this->settingMapper->expects($this->never())
            ->method('setSetting');

        $this->service->updateSettings();
    }

    public function testGetSettingsReturnsCamelCaseKeys(): void
    {
        $this->settingMapper->method('getAllAsArray')->willReturn([]);

        $settings = $this->service->getSettings();

        $this->assertArrayHasKey('defaultPermissionLevel', $settings);
        $this->assertArrayHasKey('allowUserDashboards', $settings);
        $this->assertArrayHasKey('allowMultipleDashboards', $settings);
        $this->assertArrayHasKey('defaultGridColumns', $settings);
        $this->assertCount(4, $settings);
    }

    public function testGetGroupOrderReturnsEmptyWhenRowAbsent(): void
    {
        $this->settingMapper
            ->method('findByKey')
            ->with(AdminSetting::KEY_GROUP_ORDER)
            ->willThrowException(new DoesNotExistException('not found'));

        $this->assertSame([], $this->service->getGroupOrder());
    }

    public function testGetGroupOrderReturnsEmptyOnNullValue(): void
    {
        $entity = new AdminSetting();
        $entity->setSettingKey(AdminSetting::KEY_GROUP_ORDER);
        $entity->setSettingValue(null);

        $this->settingMapper
            ->method('findByKey')
            ->willReturn($entity);

        $this->assertSame([], $this->service->getGroupOrder());
    }

    public function testGetGroupOrderReturnsEmptyOnCorruptJsonWithoutThrowing(): void
    {
        $entity = new AdminSetting();
        $entity->setSettingKey(AdminSetting::KEY_GROUP_ORDER);
        $entity->setSettingValue('{not-json');

        $this->settingMapper
            ->method('findByKey')
            ->willReturn($entity);

        $this->assertSame([], $this->service->getGroupOrder());
    }

    public function testGetGroupOrderReturnsEmptyOnNonArrayJson(): void
    {
        $entity = new AdminSetting();
        $entity->setSettingKey(AdminSetting::KEY_GROUP_ORDER);
        $entity->setSettingValue('"a-string"');

        $this->settingMapper
            ->method('findByKey')
            ->willReturn($entity);

        $this->assertSame([], $this->service->getGroupOrder());
    }

    public function testGetGroupOrderPreservesOrderAndFiltersInvalidElements(): void
    {
        $entity = new AdminSetting();
        $entity->setSettingKey(AdminSetting::KEY_GROUP_ORDER);
        // Mix in a non-string and an empty string — both must be filtered out;
        // the surviving entries must keep their declared order.
        $entity->setSettingValue('["engineering",42,"","marketing",null,"engineering"]');

        $this->settingMapper
            ->method('findByKey')
            ->willReturn($entity);

        $this->assertSame(
            ['engineering', 'marketing'],
            $this->service->getGroupOrder()
        );
    }

    public function testSetGroupOrderDeduplicatesPreservingFirstOccurrence(): void
    {
        $captured = null;

        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(
                AdminSetting::KEY_GROUP_ORDER,
                $this->callback(static function ($value) use (&$captured): bool {
                    $captured = $value;
                    return true;
                })
            );

        $this->service->setGroupOrder(['a', 'b', 'a', 'c', 'b']);

        $this->assertSame(['a', 'b', 'c'], $captured);
    }

    public function testSetGroupOrderRejectsNonStringElements(): void
    {
        $this->settingMapper->expects($this->never())->method('setSetting');

        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line — intentionally invalid for the test */
        $this->service->setGroupOrder(['engineering', 42, 'marketing']);
    }

    public function testSetGroupOrderRejectsEmptyStringElements(): void
    {
        $this->settingMapper->expects($this->never())->method('setSetting');

        $this->expectException(InvalidArgumentException::class);

        $this->service->setGroupOrder(['engineering', '', 'marketing']);
    }

    public function testSetGroupOrderAcceptsEmptyArray(): void
    {
        $captured = null;

        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(
                AdminSetting::KEY_GROUP_ORDER,
                $this->callback(static function ($value) use (&$captured): bool {
                    $captured = $value;
                    return true;
                })
            );

        $this->service->setGroupOrder([]);

        $this->assertSame([], $captured);
    }
}
