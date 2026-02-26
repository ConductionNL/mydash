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
        $placement->setTileType(tileType: 'custom');
        $placement->setTileTitle(
            tileTitle: $tileData['title'] ?? 'New Tile'
        );
        $placement->setTileIcon(
            tileIcon: $tileData['icon'] ?? 'icon-link'
        );
        $placement->setTileIconType(
            tileIconType: $tileData['iconType'] ?? 'class'
        );
        $placement->setTileBackgroundColor(
            tileBackgroundColor: $tileData['bgColor'] ?? '#0082c9'
        );
        $placement->setTileTextColor(
            tileTextColor: $tileData['txtColor'] ?? '#ffffff'
        );
        $placement->setTileLinkType(
            tileLinkType: $tileData['linkType'] ?? 'app'
        );
        $placement->setTileLinkValue(
            tileLinkValue: $tileData['linkVal'] ?? ''
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
            $placement->setTileTitle(tileTitle: $data['tileTitle']);
        }

        if (isset($data['tileIcon']) === true) {
            $placement->setTileIcon(tileIcon: $data['tileIcon']);
        }

        if (isset($data['tileIconType']) === true) {
            $placement->setTileIconType(
                tileIconType: $data['tileIconType']
            );
        }

        if (isset($data['tileBackgroundColor']) === true) {
            $placement->setTileBackgroundColor(
                tileBackgroundColor: $data['tileBackgroundColor']
            );
        }

        if (isset($data['tileTextColor']) === true) {
            $placement->setTileTextColor(
                tileTextColor: $data['tileTextColor']
            );
        }

        if (isset($data['tileLinkType']) === true) {
            $placement->setTileLinkType(
                tileLinkType: $data['tileLinkType']
            );
        }

        if (isset($data['tileLinkValue']) === true) {
            $placement->setTileLinkValue(
                tileLinkValue: $data['tileLinkValue']
            );
        }
    }//end applyTileUpdates()
}//end class
