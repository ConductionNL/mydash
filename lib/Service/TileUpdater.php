<?php

/**
 * TileUpdater
 *
 * Service for applying tile-specific updates to widget placements.
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

use OCA\MyDash\Db\WidgetPlacement;

/**
 * Service for applying tile-specific updates to widget placements.
 */
class TileUpdater
{
    /**
     * Apply tile configuration to a new placement entity.
     *
     * @param WidgetPlacement $placement The placement entity.
     * @param array           $tileData  The tile configuration data.
     *
     * @return void
     */
    public function applyTileConfig(
        WidgetPlacement $placement,
        array $tileData
    ): void {
        $placement->setTileType('custom');
        $placement->setTileTitle(
            $tileData['title'] ?? 'New Tile'
        );
        $placement->setTileIcon(
            $tileData['icon'] ?? 'icon-link'
        );
        $placement->setTileIconType(
            $tileData['iconType'] ?? 'class'
        );
        $placement->setTileBackgroundColor(
            $tileData['bgColor'] ?? '#0082c9'
        );
        $placement->setTileTextColor(
            $tileData['txtColor'] ?? '#ffffff'
        );
        $placement->setTileLinkType(
            $tileData['linkType'] ?? 'app'
        );
        $placement->setTileLinkValue(
            $tileData['linkVal'] ?? ''
        );
    }//end applyTileConfig()

    /**
     * Apply tile-specific field updates to a placement.
     *
     * @param WidgetPlacement $placement The placement entity.
     * @param array           $data      The update data.
     *
     * @return void
     */
    public function applyTileUpdates(
        WidgetPlacement $placement,
        array $data
    ): void {
        if (isset($data['tileTitle']) === true) {
            $placement->setTileTitle($data['tileTitle']);
        }

        if (isset($data['tileIcon']) === true) {
            $placement->setTileIcon($data['tileIcon']);
        }

        if (isset($data['tileIconType']) === true) {
            $placement->setTileIconType(
                $data['tileIconType']
            );
        }

        if (isset($data['tileBackgroundColor']) === true) {
            $placement->setTileBackgroundColor(
                $data['tileBackgroundColor']
            );
        }

        if (isset($data['tileTextColor']) === true) {
            $placement->setTileTextColor(
                $data['tileTextColor']
            );
        }

        if (isset($data['tileLinkType']) === true) {
            $placement->setTileLinkType(
                $data['tileLinkType']
            );
        }

        if (isset($data['tileLinkValue']) === true) {
            $placement->setTileLinkValue(
                $data['tileLinkValue']
            );
        }
    }//end applyTileUpdates()
}//end class
