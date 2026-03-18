<?php

/**
 * RequestDataExtractor
 *
 * Helper for extracting typed data from controller requests.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
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

namespace OCA\MyDash\Controller;

use OCP\IL10N;
use OCP\IRequest;

/**
 * Helper for extracting typed data from controller requests.
 */
class RequestDataExtractor
{
    /**
     * Extract tile data from the request parameters.
     *
     * @param IRequest   $request The request.
     * @param IL10N|null $l10n    The localization service.
     *
     * @return array The tile configuration data.
     */
    public static function extractTileData(
        IRequest $request,
        ?IL10N $l10n=null
    ): array {
        $defaultTitle = $l10n !== null ? $l10n->t('New Tile') : 'New Tile';

        return [
            'title'    => $request->getParam(
                key: 'title',
                default: $defaultTitle
            ),
            'icon'     => $request->getParam(
                key: 'icon',
                default: 'icon-link'
            ),
            'iconType' => $request->getParam(
                key: 'iconType',
                default: 'class'
            ),
            'bgColor'  => $request->getParam(
                key: 'bgColor',
                default: '#0082c9'
            ),
            'txtColor' => $request->getParam(
                key: 'textColor',
                default: '#ffffff'
            ),
            'linkType' => $request->getParam(
                key: 'linkType',
                default: 'app'
            ),
            'linkVal'  => $request->getParam(
                key: 'linkValue',
                default: ''
            ),
            'gridX'    => (int) $request->getParam(
                key: 'gridX',
                default: 0
            ),
            'gridY'    => (int) $request->getParam(
                key: 'gridY',
                default: 0
            ),
        ];
    }//end extractTileData()

    /**
     * Extract placement update data from the request.
     *
     * @param IRequest $request The request.
     *
     * @return array The non-null placement field values.
     */
    public static function extractPlacementData(IRequest $request): array
    {
        $fields = [
            'gridX',
            'gridY',
            'gridWidth',
            'gridHeight',
            'isVisible',
            'showTitle',
            'customTitle',
            'customIcon',
            'styleConfig',
            'tileTitle',
            'tileIcon',
            'tileIconType',
            'tileBackgroundColor',
            'tileTextColor',
            'tileLinkType',
            'tileLinkValue',
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $request->getParam(key: $field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        return $data;
    }//end extractPlacementData()
}//end class
