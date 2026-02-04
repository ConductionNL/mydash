<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Service;

use DateTime;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Db\DashboardMapper;
use OCP\Dashboard\IManager;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\IUserSession;

/**
 * WidgetService
 *
 * Service for managing dashboard widgets
 *
 * @category Service
 * @package  OCA\MyDash\Service
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
 */
class WidgetService {

	/**
	 * Constructor
	 *
	 * @param IManager               $dashboardManager Dashboard manager interface.
	 * @param WidgetPlacementMapper  $placementMapper  Widget placement mapper.
	 * @param DashboardMapper        $dashboardMapper  Dashboard mapper.
	 * @param IUserSession           $userSession      User session interface.
	 */
	public function __construct(
		private readonly IManager $dashboardManager,
		private readonly WidgetPlacementMapper $placementMapper,
		private readonly DashboardMapper $dashboardMapper,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * Get all available widgets from Nextcloud
	 *
	 * @return array
	 */
	public function getAvailableWidgets(): array {
		$widgets = $this->dashboardManager->getWidgets();
		$result = [];

		foreach ($widgets as $widget) {
			$result[] = $this->formatWidget($widget);
		}

		// Sort by order
		usort($result, fn($a, $b) => $a['order'] - $b['order']);

		return $result;
	}

	/**
	 * Get widget items for multiple widgets
	 *
	 * @param string $userId
	 * @param array $widgetIds
	 * @param int $limit
	 * @return array
	 */
	public function getWidgetItems(string $userId, array $widgetIds, int $limit = 7): array {
		$widgets = $this->dashboardManager->getWidgets();
		$result = [];

		foreach ($widgetIds as $widgetId) {
			if (!isset($widgets[$widgetId])) {
				continue;
			}

			$widget = $widgets[$widgetId];

			// Try V2 API first
			if ($widget instanceof IAPIWidgetV2) {
				$items = $widget->getItemsV2($userId, null, $limit);
				$result[$widgetId] = [
					'items' => array_map(fn($item) => $item->jsonSerialize(), $items->getItems()),
					'emptyContentMessage' => $items->getEmptyContentMessage(),
					'halfEmptyContentMessage' => $items->getHalfEmptyContentMessage(),
				];
			} elseif ($widget instanceof IAPIWidget) {
				// Fallback to V1 API
				$items = $widget->getItems($userId, null, $limit);
				$result[$widgetId] = [
					'items' => array_map(fn($item) => $item->jsonSerialize(), $items),
					'emptyContentMessage' => '',
					'halfEmptyContentMessage' => '',
				];
			} else {
				// Widget doesn't support API
				$result[$widgetId] = [
					'items' => [],
					'emptyContentMessage' => '',
					'halfEmptyContentMessage' => '',
				];
			}
		}

		return $result;
	}

	/**
	 * Add a widget to a dashboard
	 *
	 * @param int $dashboardId Dashboard ID.
	 * @param string $widgetId Widget ID.
	 * @param int $gridX Grid X position.
	 * @param int $gridY Grid Y position.
	 * @param int $gridWidth Grid width.
	 * @param int $gridHeight Grid height.
	 *
	 * @return WidgetPlacement The created widget placement.
	 */
	public function addWidget(
		int $dashboardId,
		string $widgetId,
		int $gridX = 0,
		int $gridY = 0,
		int $gridWidth = 4,
		int $gridHeight = 4
	): WidgetPlacement {
		$placement = new WidgetPlacement();
		$now = (new DateTime())->format('Y-m-d H:i:s');
		$placement->setDashboardId($dashboardId);
		$placement->setWidgetId($widgetId);
		$placement->setGridX($gridX);
		$placement->setGridY($gridY);
		$placement->setGridWidth($gridWidth);
		$placement->setGridHeight($gridHeight);
		$placement->setIsCompulsory(0); // Use 0 for false in SMALLINT column.
		$placement->setIsVisible(1); // Use 1 for true in SMALLINT column.
		$placement->setShowTitle(1); // Use 1 for true in SMALLINT column.
		$placement->setCreatedAt($now);
		$placement->setUpdatedAt($now);

		return $this->placementMapper->insert($placement);
	}

	/**
	 * Add a tile to a dashboard
	 *
	 * @param int $dashboardId Dashboard ID.
	 * @param string $title Tile title.
	 * @param string $icon Tile icon.
	 * @param string $iconType Icon type.
	 * @param string $backgroundColor Background color.
	 * @param string $textColor Text color.
	 * @param string $linkType Link type.
	 * @param string $linkValue Link value.
	 * @param int $gridX Grid X position.
	 * @param int $gridY Grid Y position.
	 * @param int $gridWidth Grid width.
	 * @param int $gridHeight Grid height.
	 *
	 * @return WidgetPlacement The created tile placement.
	 */
	public function addTile(
		int $dashboardId,
		string $title,
		string $icon,
		string $iconType,
		string $backgroundColor = '#0082c9',
		string $textColor = '#ffffff',
		string $linkType = 'app',
		string $linkValue = '',
		int $gridX = 0,
		int $gridY = 0,
		int $gridWidth = 2,
		int $gridHeight = 2
	): WidgetPlacement {
		$placement = new WidgetPlacement();
		$now = (new DateTime())->format('Y-m-d H:i:s');
		$placement->setDashboardId($dashboardId);
		$placement->setWidgetId('tile-' . uniqid()); // Generate unique ID for the tile.
		$placement->setGridX($gridX);
		$placement->setGridY($gridY);
		$placement->setGridWidth($gridWidth);
		$placement->setGridHeight($gridHeight);
		$placement->setIsCompulsory(0);
		$placement->setIsVisible(1);
		$placement->setShowTitle(1);
		// Set tile configuration.
		$placement->setTileType('custom');
		$placement->setTileTitle($title);
		$placement->setTileIcon($icon);
		$placement->setTileIconType($iconType);
		$placement->setTileBackgroundColor($backgroundColor);
		$placement->setTileTextColor($textColor);
		$placement->setTileLinkType($linkType);
		$placement->setTileLinkValue($linkValue);
		$placement->setCreatedAt($now);
		$placement->setUpdatedAt($now);

		return $this->placementMapper->insert($placement);
	}

	/**
	 * Update a widget placement
	 *
	 * @param int   $placementId The placement ID.
	 * @param array $data        The data to update.
	 *
	 * @return WidgetPlacement The updated widget placement.
	 */
	public function updatePlacement(int $placementId, array $data): WidgetPlacement {
		$placement = $this->placementMapper->find($placementId);

		if (isset($data['gridX'])) {
			$placement->setGridX($data['gridX']);
		}
		if (isset($data['gridY'])) {
			$placement->setGridY($data['gridY']);
		}
		if (isset($data['gridWidth'])) {
			$placement->setGridWidth($data['gridWidth']);
		}
		if (isset($data['gridHeight'])) {
			$placement->setGridHeight($data['gridHeight']);
		}
		if (isset($data['isVisible'])) {
			$placement->setIsVisible($data['isVisible']);
		}
		if (isset($data['showTitle'])) {
			$placement->setShowTitle($data['showTitle']);
		}
		if (isset($data['customTitle'])) {
			$placement->setCustomTitle($data['customTitle']);
		}
		if (isset($data['customIcon'])) {
			$placement->setCustomIcon($data['customIcon']);
		}
		if (isset($data['styleConfig'])) {
			$placement->setStyleConfigArray($data['styleConfig']);
		}
		// Tile configuration updates.
		if (isset($data['tileTitle'])) {
			$placement->setTileTitle($data['tileTitle']);
		}
		if (isset($data['tileIcon'])) {
			$placement->setTileIcon($data['tileIcon']);
		}
		if (isset($data['tileIconType'])) {
			$placement->setTileIconType($data['tileIconType']);
		}
		if (isset($data['tileBackgroundColor'])) {
			$placement->setTileBackgroundColor($data['tileBackgroundColor']);
		}
		if (isset($data['tileTextColor'])) {
			$placement->setTileTextColor($data['tileTextColor']);
		}
		if (isset($data['tileLinkType'])) {
			$placement->setTileLinkType($data['tileLinkType']);
		}
		if (isset($data['tileLinkValue'])) {
			$placement->setTileLinkValue($data['tileLinkValue']);
		}

		$placement->setUpdatedAt((new DateTime())->format('Y-m-d H:i:s'));

		return $this->placementMapper->update($placement);
	}

	/**
	 * Remove a widget placement
	 */
	public function removePlacement(int $placementId): void {
		$placement = $this->placementMapper->find($placementId);
		$this->placementMapper->delete($placement);
	}

	/**
	 * Get placement by ID
	 */
	public function getPlacement(int $placementId): WidgetPlacement {
		return $this->placementMapper->find($placementId);
	}

	/**
	 * Get all placements for a dashboard
	 *
	 * @return WidgetPlacement[]
	 */
	public function getDashboardPlacements(int $dashboardId): array {
		return $this->placementMapper->findByDashboardId($dashboardId);
	}

	/**
	 * Format a widget for API response
	 */
	private function formatWidget(IWidget $widget): array {
		$data = [
			'id' => $widget->getId(),
			'title' => $widget->getTitle(),
			'order' => $widget->getOrder(),
			'iconClass' => $widget->getIconClass(),
			'iconUrl' => null,
			'widgetUrl' => $widget->getUrl(),
			'itemIconsRound' => false,
			'itemApiVersions' => [],
			'reloadInterval' => 0,
			'buttons' => [],
		];

		// Check for icon URL
		if ($widget instanceof IIconWidget) {
			$data['iconUrl'] = $widget->getIconUrl();
		}

		// Check API versions
		if ($widget instanceof IAPIWidget) {
			$data['itemApiVersions'][] = 1;
		}
		if ($widget instanceof IAPIWidgetV2) {
			$data['itemApiVersions'][] = 2;
		}

		// Check for buttons.
		if ($widget instanceof IButtonWidget) {
			$user = $this->userSession->getUser();
			$userId = $user ? $user->getUID() : '';
			$buttons = $widget->getWidgetButtons($userId);
			$data['buttons'] = array_map(function($btn) {
				// Manual serialization since WidgetButton doesn't have jsonSerialize.
				return [
					'type' => $btn->getType(),
					'text' => $btn->getText(),
					'link' => $btn->getLink(),
				];
			}, $buttons);
		}

		// Check for options
		if ($widget instanceof IOptionWidget) {
			$options = $widget->getWidgetOptions();
			$data['itemIconsRound'] = $options->withRoundItemIcons();
		}

		// Check for reload interval
		if ($widget instanceof IReloadableWidget) {
			$data['reloadInterval'] = $widget->getReloadInterval();
		}

		return $data;
	}
}
