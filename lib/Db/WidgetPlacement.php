<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * WidgetPlacement Entity
 *
 * @category Database
 * @package  OCA\MyDash\Db
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
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
class WidgetPlacement extends Entity implements JsonSerializable {
	protected int $dashboardId = 0;
	protected string $widgetId = '';
	protected int $gridX = 0;
	protected int $gridY = 0;
	protected int $gridWidth = 4;
	protected int $gridHeight = 4;
	protected int $isCompulsory = 0; // SMALLINT in DB (0/1).
	protected int $isVisible = 1; // SMALLINT in DB (0/1).
	protected ?string $styleConfig = null;
	protected ?string $customTitle = null;
	protected ?string $customIcon = null;
	protected int $showTitle = 1; // SMALLINT in DB (0/1).
	protected int $sortOrder = 0;
	// Tile configuration fields.
	protected ?string $tileType = null;
	protected ?string $tileTitle = null;
	protected ?string $tileIcon = null;
	protected ?string $tileIconType = null;
	protected ?string $tileBackgroundColor = null;
	protected ?string $tileTextColor = null;
	protected ?string $tileLinkType = null;
	protected ?string $tileLinkValue = null;
	protected ?string $createdAt = null; // Stored as string to avoid DateTime conversion issues.
	protected ?string $updatedAt = null; // Stored as string to avoid DateTime conversion issues.

	/**
	 * Constructor
	 *
	 * Registers column types for proper ORM handling.
	 * Note: Boolean columns are SMALLINT in DB (0/1).
	 */
	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('dashboardId', 'integer');
		$this->addType('gridX', 'integer');
		$this->addType('gridY', 'integer');
		$this->addType('gridWidth', 'integer');
		$this->addType('gridHeight', 'integer');
		$this->addType('isCompulsory', 'integer'); // SMALLINT in DB (0/1).
		$this->addType('isVisible', 'integer'); // SMALLINT in DB (0/1).
		$this->addType('showTitle', 'integer'); // SMALLINT in DB (0/1).
		$this->addType('sortOrder', 'integer');
	}

	/**
	 * Get style config as array
	 */
	public function getStyleConfigArray(): array {
		if (empty($this->styleConfig)) {
			return [];
		}
		$decoded = json_decode($this->styleConfig, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Set style config from array
	 */
	public function setStyleConfigArray(array $config): void {
		$this->setStyleConfig(json_encode($config));
	}

	/**
	 * Serialize to JSON
	 *
	 * @return array The serialized widget placement.
	 */
	public function jsonSerialize(): array {
		$data = [
			'id' => $this->getId(),
			'dashboardId' => $this->dashboardId,
			'widgetId' => $this->widgetId,
			'gridX' => $this->gridX,
			'gridY' => $this->gridY,
			'gridWidth' => $this->gridWidth,
			'gridHeight' => $this->gridHeight,
			'isCompulsory' => $this->isCompulsory,
			'isVisible' => $this->isVisible,
			'styleConfig' => $this->getStyleConfigArray(),
			'customTitle' => $this->customTitle,
			'customIcon' => $this->customIcon,
			'showTitle' => $this->showTitle,
			'sortOrder' => $this->sortOrder,
			'createdAt' => $this->createdAt, // Already a string.
			'updatedAt' => $this->updatedAt, // Already a string.
		];

		// Include tile configuration if this is a tile.
		if ($this->tileType !== null) {
			$data['tileType'] = $this->tileType;
			$data['tileTitle'] = $this->tileTitle;
			$data['tileIcon'] = $this->tileIcon;
			$data['tileIconType'] = $this->tileIconType;
			$data['tileBackgroundColor'] = $this->tileBackgroundColor;
			$data['tileTextColor'] = $this->tileTextColor;
			$data['tileLinkType'] = $this->tileLinkType;
			$data['tileLinkValue'] = $this->tileLinkValue;
		}

		return $data;
	}
}
