<?php

/**
 * TileApiController
 *
 * Controller for tile API endpoints.
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

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\TileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for tile API endpoints.
 */
class TileApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest    $request     The request.
     * @param TileService $tileService The tile service.
     * @param string|null $userId      The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly TileService $tileService,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all tiles for the current user.
     *
     * @return JSONResponse The list of tiles.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $tiles = $this->tileService->getUserTiles(userId: $this->userId);

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $tiles)
        );
    }//end index()

    /**
     * Create a new tile.
     *
     * @param string|null $title           The tile title.
     * @param string|null $icon            The icon.
     * @param string|null $iconType        The icon type.
     * @param string|null $backgroundColor The background color.
     * @param string|null $textColor       The text color.
     * @param string|null $linkType        The link type.
     * @param string|null $linkValue       The link value.
     *
     * @return JSONResponse The created tile.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(
        ?string $title=null,
        ?string $icon=null,
        ?string $iconType=null,
        ?string $backgroundColor=null,
        ?string $textColor=null,
        ?string $linkType=null,
        ?string $linkValue=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $tile = $this->tileService->createTile(
                userId: $this->userId,
                title: $title ?? 'New Tile',
                icon: $icon ?? 'icon-link',
                iconType: $iconType ?? 'class',
                backgroundColor: $backgroundColor ?? '#0082c9',
                textColor: $textColor ?? '#ffffff',
                linkType: $linkType ?? 'url',
                linkValue: $linkValue ?? '#'
            );

            return ResponseHelper::success(
                data: $tile->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end create()

    /**
     * Update a tile.
     *
     * @param int|array   $id              The tile ID or JSON data.
     * @param string|null $title           The tile title.
     * @param string|null $icon            The icon.
     * @param string|null $iconType        The icon type.
     * @param string|null $backgroundColor The background color.
     * @param string|null $textColor       The text color.
     * @param string|null $linkType        The link type.
     * @param string|null $linkValue       The link value.
     *
     * @return JSONResponse The updated tile.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(
        $id,
        $title=null,
        ?string $icon=null,
        ?string $iconType=null,
        ?string $backgroundColor=null,
        ?string $textColor=null,
        ?string $linkType=null,
        ?string $linkValue=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $resolvedData = $this->resolveUpdateData(
            id: $id,
            title: $title,
            icon: $icon,
            iconType: $iconType,
            bgColor: $backgroundColor,
            textColor: $textColor,
            linkType: $linkType,
            linkValue: $linkValue
        );

        if ($resolvedData['id'] === null) {
            return ResponseHelper::success(
                data: ['error' => 'Missing tile ID'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $tile = $this->tileService->updateTile(
                id: (int) $resolvedData['id'],
                userId: $this->userId,
                data: $resolvedData['data']
            );

            return ResponseHelper::success(
                data: $tile->jsonSerialize()
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end update()

    /**
     * Resolve update data from either JSON body or parameters.
     *
     * @param mixed       $id        The tile ID or JSON body.
     * @param mixed       $title     The title.
     * @param string|null $icon      The icon.
     * @param string|null $iconType  The icon type.
     * @param string|null $bgColor   The background color.
     * @param string|null $textColor The text color.
     * @param string|null $linkType  The link type.
     * @param string|null $linkValue The link value.
     *
     * @return array The resolved ID and data.
     */
    private function resolveUpdateData(
        $id,
        $title,
        ?string $icon,
        ?string $iconType,
        ?string $bgColor,
        ?string $textColor,
        ?string $linkType,
        ?string $linkValue
    ): array {
        if (is_array($id) === true) {
            return [
                'id'   => $id['id'] ?? null,
                'data' => $id,
            ];
        }

        $fields = [
            'title'           => $title,
            'icon'            => $icon,
            'iconType'        => $iconType,
            'backgroundColor' => $bgColor,
            'textColor'       => $textColor,
            'linkType'        => $linkType,
            'linkValue'       => $linkValue,
        ];

        return [
            'id'   => $id,
            'data' => array_filter(
                array: $fields,
                callback: function ($value) {
                    return $value !== null;
                }
            ),
        ];
    }//end resolveUpdateData()

    /**
     * Delete a tile.
     *
     * @param int $id The tile ID.
     *
     * @return JSONResponse Success response.
     */
    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $this->tileService->deleteTile(
                id: $id,
                userId: $this->userId
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end destroy()
}//end class
