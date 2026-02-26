<?php

/**
 * PlacementUpdater
 *
 * Service for applying grid and display updates to widget placements.
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
 * Service for applying grid and display updates to widget placements.
 */
class PlacementUpdater
{
    /**
     * Apply grid position and size updates to a placement.
     *
     * @param WidgetPlacement $placement The placement entity.
     * @param array           $data      The update data.
     *
     * @return void
     */
    public function applyGridUpdates(
        WidgetPlacement $placement,
        array $data
    ): void {
        if (isset($data['gridX']) === true) {
            $placement->setGridX(gridX: $data['gridX']);
        }

        if (isset($data['gridY']) === true) {
            $placement->setGridY(gridY: $data['gridY']);
        }

        if (isset($data['gridWidth']) === true) {
            $placement->setGridWidth(gridWidth: $data['gridWidth']);
        }

        if (isset($data['gridHeight']) === true) {
            $placement->setGridHeight(
                gridHeight: $data['gridHeight']
            );
        }
    }//end applyGridUpdates()

    /**
     * Apply display and style updates to a placement.
     *
     * @param WidgetPlacement $placement The placement entity.
     * @param array           $data      The update data.
     *
     * @return void
     */
    public function applyDisplayUpdates(
        WidgetPlacement $placement,
        array $data
    ): void {
        if (isset($data['isVisible']) === true) {
            $placement->setIsVisible(isVisible: $data['isVisible']);
        }

        if (isset($data['showTitle']) === true) {
            $placement->setShowTitle(showTitle: $data['showTitle']);
        }

        if (isset($data['customTitle']) === true) {
            $placement->setCustomTitle(
                customTitle: $data['customTitle']
            );
        }

        if (isset($data['customIcon']) === true) {
            $placement->setCustomIcon(
                customIcon: $data['customIcon']
            );
        }

        if (isset($data['styleConfig']) === true) {
            $placement->setStyleConfigArray(
                config: $data['styleConfig']
            );
        }
    }//end applyDisplayUpdates()
}//end class
