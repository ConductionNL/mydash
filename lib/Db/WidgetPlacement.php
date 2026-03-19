<?php

declare(strict_types=1);

/**
 * WidgetPlacement Entity
 *
 * Represents a widget placement on a dashboard.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Widget placement entity for dashboard grid positioning.
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 *
 * @method int getDashboardId()
 * @method void setDashboardId(int $dashboardId)
 * @method string getWidgetId()
 * @method void setWidgetId(string $widgetId)
 * @method int getGridX()
 * @method void setGridX(int $gridX)
 * @method int getGridY()
 * @method void setGridY(int $gridY)
 * @method int getGridWidth()
 * @method void setGridWidth(int $gridWidth)
 * @method int getGridHeight()
 * @method void setGridHeight(int $gridHeight)
 * @method int getIsCompulsory()
 * @method void setIsCompulsory(int $isCompulsory)
 * @method int getIsVisible()
 * @method void setIsVisible(int $isVisible)
 * @method string|null getStyleConfig()
 * @method void setStyleConfig(?string $styleConfig)
 * @method string|null getCustomTitle()
 * @method void setCustomTitle(?string $customTitle)
 * @method int getShowTitle()
 * @method void setShowTitle(int $showTitle)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string|null getTileType()
 * @method void setTileType(?string $tileType)
 * @method string|null getTileTitle()
 * @method void setTileTitle(?string $tileTitle)
 * @method string|null getTileIcon()
 * @method void setTileIcon(?string $tileIcon)
 * @method string|null getTileIconType()
 * @method void setTileIconType(?string $tileIconType)
 * @method string|null getTileBackgroundColor()
 * @method void setTileBackgroundColor(?string $tileBackgroundColor)
 * @method string|null getTileTextColor()
 * @method void setTileTextColor(?string $tileTextColor)
 * @method string|null getTileLinkType()
 * @method void setTileLinkType(?string $tileLinkType)
 * @method string|null getTileLinkValue()
 * @method void setTileLinkValue(?string $tileLinkValue)
 * @method string|null getCustomIcon()
 * @method void setCustomIcon(?string $customIcon)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class WidgetPlacement extends Entity implements JsonSerializable
{

    /**
     * The dashboard ID.
     *
     * @var integer
     */
    protected int $dashboardId = 0;

    /**
     * The widget ID.
     *
     * @var string
     */
    protected string $widgetId = '';

    /**
     * The grid X position.
     *
     * @var integer
     */
    protected int $gridX = 0;

    /**
     * The grid Y position.
     *
     * @var integer
     */
    protected int $gridY = 0;

    /**
     * The grid width.
     *
     * @var integer
     */
    protected int $gridWidth = 4;

    /**
     * The grid height.
     *
     * @var integer
     */
    protected int $gridHeight = 4;

    /**
     * Whether the widget is compulsory (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $isCompulsory = 0;

    /**
     * Whether the widget is visible (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $isVisible = 1;

    /**
     * The style configuration JSON.
     *
     * @var string|null
     */
    protected ?string $styleConfig = null;

    /**
     * The custom title.
     *
     * @var string|null
     */
    protected ?string $customTitle = null;

    /**
     * The custom icon.
     *
     * @var string|null
     */
    protected ?string $customIcon = null;

    /**
     * Whether to show the title (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $showTitle = 1;

    /**
     * The sort order.
     *
     * @var integer
     */
    protected int $sortOrder = 0;

    /**
     * The tile type.
     *
     * @var string|null
     */
    protected ?string $tileType = null;

    /**
     * The tile title.
     *
     * @var string|null
     */
    protected ?string $tileTitle = null;

    /**
     * The tile icon.
     *
     * @var string|null
     */
    protected ?string $tileIcon = null;

    /**
     * The tile icon type.
     *
     * @var string|null
     */
    protected ?string $tileIconType = null;

    /**
     * The tile background color.
     *
     * @var string|null
     */
    protected ?string $tileBackgroundColor = null;

    /**
     * The tile text color.
     *
     * @var string|null
     */
    protected ?string $tileTextColor = null;

    /**
     * The tile link type.
     *
     * @var string|null
     */
    protected ?string $tileLinkType = null;

    /**
     * The tile link value.
     *
     * @var string|null
     */
    protected ?string $tileLinkValue = null;

    /**
     * The creation timestamp as string.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * The update timestamp as string.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * Constructor
     *
     * Registers column types for proper ORM handling.
     * Note: Boolean columns are SMALLINT in DB (0/1).
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'dashboardId', type: 'integer');
        $this->addType(fieldName: 'gridX', type: 'integer');
        $this->addType(fieldName: 'gridY', type: 'integer');
        $this->addType(fieldName: 'gridWidth', type: 'integer');
        $this->addType(fieldName: 'gridHeight', type: 'integer');
        $this->addType(fieldName: 'isCompulsory', type: 'integer');
        // SMALLINT in DB (0/1).
        $this->addType(fieldName: 'isVisible', type: 'integer');
        // SMALLINT in DB (0/1).
        $this->addType(fieldName: 'showTitle', type: 'integer');
        // SMALLINT in DB (0/1).
        $this->addType(fieldName: 'sortOrder', type: 'integer');
    }//end __construct()

    /**
     * Get style config as array.
     *
     * @return array The decoded style configuration.
     */
    public function getStyleConfigArray(): array
    {
        if (empty($this->styleConfig) === true) {
            return [];
        }

        $decoded = json_decode(json: $this->styleConfig, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getStyleConfigArray()

    /**
     * Set style config from array.
     *
     * @param array $config The style configuration array.
     *
     * @return void
     */
    public function setStyleConfigArray(array $config): void
    {
        $this->setStyleConfig(styleConfig: json_encode(value: $config));
    }//end setStyleConfigArray()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized widget placement.
     */
    public function jsonSerialize(): array
    {
        $data = [
            'id'           => $this->getId(),
            'dashboardId'  => $this->dashboardId,
            'widgetId'     => $this->widgetId,
            'gridX'        => $this->gridX,
            'gridY'        => $this->gridY,
            'gridWidth'    => $this->gridWidth,
            'gridHeight'   => $this->gridHeight,
            'isCompulsory' => $this->isCompulsory,
            'isVisible'    => $this->isVisible,
            'styleConfig'  => $this->getStyleConfigArray(),
            'customTitle'  => $this->customTitle,
            'customIcon'   => $this->customIcon,
            'showTitle'    => $this->showTitle,
            'sortOrder'    => $this->sortOrder,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
        ];

        // Include tile configuration if this is a tile.
        if ($this->tileType !== null) {
            $data['tileType']            = $this->tileType;
            $data['tileTitle']           = $this->tileTitle;
            $data['tileIcon']            = $this->tileIcon;
            $data['tileIconType']        = $this->tileIconType;
            $data['tileBackgroundColor'] = $this->tileBackgroundColor;
            $data['tileTextColor']       = $this->tileTextColor;
            $data['tileLinkType']        = $this->tileLinkType;
            $data['tileLinkValue']       = $this->tileLinkValue;
        }

        return $data;
    }//end jsonSerialize()
}//end class
