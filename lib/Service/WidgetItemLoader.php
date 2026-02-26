<?php

/**
 * WidgetItemLoader
 *
 * Service for loading widget items from Nextcloud widget APIs.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IWidget;

/**
 * Service for loading widget items from Nextcloud widget APIs.
 */
class WidgetItemLoader
{
    /**
     * Load items for the specified widget IDs.
     *
     * @param array  $widgets   Map of widget ID to IWidget instances.
     * @param string $userId    The user ID.
     * @param array  $widgetIds The widget IDs to load.
     * @param int    $limit     Maximum items per widget.
     *
     * @return array The widget items keyed by widget ID.
     */
    public function loadItems(
        array $widgets,
        string $userId,
        array $widgetIds,
        int $limit=7
    ): array {
        $result = [];

        foreach ($widgetIds as $widgetId) {
            if (isset($widgets[$widgetId]) === false) {
                continue;
            }

            $result[$widgetId] = $this->loadSingleWidget(
                widget: $widgets[$widgetId],
                userId: $userId,
                limit: $limit
            );
        }

        return $result;
    }//end loadItems()

    /**
     * Load items for a single widget.
     *
     * @param IWidget $widget The widget instance.
     * @param string  $userId The user ID.
     * @param int     $limit  Maximum items.
     *
     * @return array The widget items data.
     */
    private function loadSingleWidget(
        IWidget $widget,
        string $userId,
        int $limit
    ): array {
        if ($widget instanceof IAPIWidgetV2) {
            return $this->loadV2Items(
                widget: $widget,
                userId: $userId,
                limit: $limit
            );
        }

        if ($widget instanceof IAPIWidget) {
            return $this->loadV1Items(
                widget: $widget,
                userId: $userId,
                limit: $limit
            );
        }

        return [
            'items'                   => [],
            'emptyContentMessage'     => '',
            'halfEmptyContentMessage' => '',
        ];
    }//end loadSingleWidget()

    /**
     * Load items using the V2 API.
     *
     * @param IAPIWidgetV2 $widget The V2 widget.
     * @param string       $userId The user ID.
     * @param int          $limit  Maximum items.
     *
     * @return array The serialized items data.
     */
    private function loadV2Items(
        IAPIWidgetV2 $widget,
        string $userId,
        int $limit
    ): array {
        $items           = $widget->getItemsV2(
            userId: $userId,
            since: null,
            limit: $limit
        );
        $serializedItems = [];
        foreach ($items->getItems() as $item) {
            $serializedItems[] = $item->jsonSerialize();
        }

        return [
            'items'                   => $serializedItems,
            'emptyContentMessage'     => $items->getEmptyContentMessage(),
            'halfEmptyContentMessage' => $items->getHalfEmptyContentMessage(),
        ];
    }//end loadV2Items()

    /**
     * Load items using the V1 API.
     *
     * @param IAPIWidget $widget The V1 widget.
     * @param string     $userId The user ID.
     * @param int        $limit  Maximum items.
     *
     * @return array The serialized items data.
     */
    private function loadV1Items(
        IAPIWidget $widget,
        string $userId,
        int $limit
    ): array {
        $items           = $widget->getItems(
            userId: $userId,
            since: null,
            limit: $limit
        );
        $serializedItems = [];
        foreach ($items as $item) {
            $serializedItems[] = $item->jsonSerialize();
        }

        return [
            'items'                   => $serializedItems,
            'emptyContentMessage'     => '',
            'halfEmptyContentMessage' => '',
        ];
    }//end loadV1Items()
}//end class
