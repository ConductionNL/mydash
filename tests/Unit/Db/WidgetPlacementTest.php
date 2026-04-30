<?php

/**
 * WidgetPlacement Entity Test
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

use OCA\MyDash\Db\WidgetPlacement;
use PHPUnit\Framework\TestCase;

class WidgetPlacementTest extends TestCase
{
    private WidgetPlacement $placement;

    protected function setUp(): void
    {
        $this->placement = new WidgetPlacement();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->placement->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
        $this->assertSame('integer', $fieldTypes['dashboardId']);
        $this->assertSame('integer', $fieldTypes['gridX']);
        $this->assertSame('integer', $fieldTypes['gridY']);
        $this->assertSame('integer', $fieldTypes['gridWidth']);
        $this->assertSame('integer', $fieldTypes['gridHeight']);
        $this->assertSame('integer', $fieldTypes['isCompulsory']);
        $this->assertSame('integer', $fieldTypes['isVisible']);
        $this->assertSame('integer', $fieldTypes['showTitle']);
        $this->assertSame('integer', $fieldTypes['sortOrder']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame(0, $this->placement->getDashboardId());
        $this->assertSame('', $this->placement->getWidgetId());
        $this->assertSame(0, $this->placement->getGridX());
        $this->assertSame(0, $this->placement->getGridY());
        $this->assertSame(4, $this->placement->getGridWidth());
        $this->assertSame(4, $this->placement->getGridHeight());
        $this->assertSame(0, $this->placement->getIsCompulsory());
        $this->assertSame(1, $this->placement->getIsVisible());
        $this->assertNull($this->placement->getStyleConfig());
        $this->assertNull($this->placement->getCustomTitle());
        $this->assertNull($this->placement->getCustomIcon());
        $this->assertSame(1, $this->placement->getShowTitle());
        $this->assertSame(0, $this->placement->getSortOrder());
        $this->assertNull($this->placement->getTileType());
    }

    public function testSetAndGetGridPosition(): void
    {
        $this->placement->setGridX(4);
        $this->placement->setGridY(2);
        $this->placement->setGridWidth(6);
        $this->placement->setGridHeight(3);

        $this->assertSame(4, $this->placement->getGridX());
        $this->assertSame(2, $this->placement->getGridY());
        $this->assertSame(6, $this->placement->getGridWidth());
        $this->assertSame(3, $this->placement->getGridHeight());
    }

    public function testSetAndGetWidgetId(): void
    {
        $this->placement->setWidgetId('weather_status');
        $this->assertSame('weather_status', $this->placement->getWidgetId());
    }

    public function testSetAndGetDashboardId(): void
    {
        $this->placement->setDashboardId(5);
        $this->assertSame(5, $this->placement->getDashboardId());
    }

    public function testSetAndGetCompulsory(): void
    {
        $this->placement->setIsCompulsory(1);
        $this->assertSame(1, $this->placement->getIsCompulsory());
    }

    public function testSetAndGetVisible(): void
    {
        $this->placement->setIsVisible(0);
        $this->assertSame(0, $this->placement->getIsVisible());
    }

    public function testSetAndGetCustomTitle(): void
    {
        $this->placement->setCustomTitle('My Weather');
        $this->assertSame('My Weather', $this->placement->getCustomTitle());
    }

    public function testSetAndGetCustomIcon(): void
    {
        $this->placement->setCustomIcon('icon-star');
        $this->assertSame('icon-star', $this->placement->getCustomIcon());
    }

    public function testGetStyleConfigArrayEmpty(): void
    {
        $this->assertSame([], $this->placement->getStyleConfigArray());
    }

    public function testGetStyleConfigArrayWithValidJson(): void
    {
        $config = ['borderColor' => '#ff0000', 'borderRadius' => '8px'];
        $this->placement->setStyleConfig(json_encode($config));
        $this->assertSame($config, $this->placement->getStyleConfigArray());
    }

    public function testGetStyleConfigArrayWithInvalidJson(): void
    {
        $this->placement->setStyleConfig('not-valid-json');
        $this->assertSame([], $this->placement->getStyleConfigArray());
    }

    public function testSetStyleConfigArray(): void
    {
        $config = ['background' => '#fff', 'padding' => '10px'];
        $this->placement->setStyleConfigArray($config);
        $this->assertSame($config, $this->placement->getStyleConfigArray());
    }

    public function testSetAndGetTileFields(): void
    {
        $this->placement->setTileType('custom');
        $this->placement->setTileTitle('My Files');
        $this->placement->setTileIcon('icon-folder');
        $this->placement->setTileIconType('class');
        $this->placement->setTileBackgroundColor('#3b82f6');
        $this->placement->setTileTextColor('#ffffff');
        $this->placement->setTileLinkType('app');
        $this->placement->setTileLinkValue('/apps/files');

        $this->assertSame('custom', $this->placement->getTileType());
        $this->assertSame('My Files', $this->placement->getTileTitle());
        $this->assertSame('icon-folder', $this->placement->getTileIcon());
        $this->assertSame('class', $this->placement->getTileIconType());
        $this->assertSame('#3b82f6', $this->placement->getTileBackgroundColor());
        $this->assertSame('#ffffff', $this->placement->getTileTextColor());
        $this->assertSame('app', $this->placement->getTileLinkType());
        $this->assertSame('/apps/files', $this->placement->getTileLinkValue());
    }

    public function testJsonSerializeWidgetPlacement(): void
    {
        $this->placement->setDashboardId(5);
        $this->placement->setWidgetId('weather_status');
        $this->placement->setGridX(0);
        $this->placement->setGridY(0);
        $this->placement->setGridWidth(4);
        $this->placement->setGridHeight(2);
        $this->placement->setIsCompulsory(1);
        $this->placement->setIsVisible(1);
        $this->placement->setCustomTitle('Weather');
        $this->placement->setShowTitle(1);
        $this->placement->setSortOrder(0);
        $this->placement->setCreatedAt('2024-01-15 10:00:00');
        $this->placement->setUpdatedAt('2024-01-16 12:00:00');

        $serialized = $this->placement->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame(5, $serialized['dashboardId']);
        $this->assertSame('weather_status', $serialized['widgetId']);
        $this->assertSame(0, $serialized['gridX']);
        $this->assertSame(0, $serialized['gridY']);
        $this->assertSame(4, $serialized['gridWidth']);
        $this->assertSame(2, $serialized['gridHeight']);
        $this->assertSame(1, $serialized['isCompulsory']);
        $this->assertSame(1, $serialized['isVisible']);
        $this->assertSame('Weather', $serialized['customTitle']);
        $this->assertArrayNotHasKey('tileType', $serialized);
    }

    public function testJsonSerializeTilePlacement(): void
    {
        $this->placement->setTileType('custom');
        $this->placement->setTileTitle('My Files');
        $this->placement->setTileIcon('icon-folder');
        $this->placement->setTileIconType('class');
        $this->placement->setTileBackgroundColor('#3b82f6');
        $this->placement->setTileTextColor('#ffffff');
        $this->placement->setTileLinkType('app');
        $this->placement->setTileLinkValue('/apps/files');

        $serialized = $this->placement->jsonSerialize();

        $this->assertArrayHasKey('tileType', $serialized);
        $this->assertSame('custom', $serialized['tileType']);
        $this->assertSame('My Files', $serialized['tileTitle']);
        $this->assertSame('icon-folder', $serialized['tileIcon']);
        $this->assertSame('class', $serialized['tileIconType']);
        $this->assertSame('#3b82f6', $serialized['tileBackgroundColor']);
        $this->assertSame('#ffffff', $serialized['tileTextColor']);
        $this->assertSame('app', $serialized['tileLinkType']);
        $this->assertSame('/apps/files', $serialized['tileLinkValue']);
    }
}
