<?php

declare(strict_types=1);

/**
 * TileApiController
 *
 * @category Controller
 * @package  OCA\MyDash\Controller
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\TileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class TileApiController extends Controller {

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
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all tiles for the current user
	 *
	 * @return JSONResponse The list of tiles.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$tiles = $this->tileService->getUserTiles($this->userId);

		return new JSONResponse(array_map(fn($tile) => $tile->jsonSerialize(), $tiles));
	}

	/**
	 * Create a new tile
	 *
	 * @param string $title           The tile title.
	 * @param string $icon            The icon (class, URL, or emoji).
	 * @param string $iconType        The icon type (class, url, or emoji).
	 * @param string $backgroundColor The background color (hex).
	 * @param string $textColor       The text color (hex).
	 * @param string $linkType        The link type (app or url).
	 * @param string $linkValue       The link value (app ID or URL).
	 *
	 * @return JSONResponse The created tile.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(
		?string $title = null,
		?string $icon = null,
		?string $iconType = null,
		?string $backgroundColor = null,
		?string $textColor = null,
		?string $linkType = null,
		?string $linkValue = null
	): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		// Use defaults for any null values.
		$title = $title ?? 'New Tile';
		$icon = $icon ?? 'icon-link';
		$iconType = $iconType ?? 'class';
		$backgroundColor = $backgroundColor ?? '#0082c9';
		$textColor = $textColor ?? '#ffffff';
		$linkType = $linkType ?? 'url';
		$linkValue = $linkValue ?? '#';

		try {
			$tile = $this->tileService->createTile(
				$this->userId,
				$title,
				$icon,
				$iconType,
				$backgroundColor,
				$textColor,
				$linkType,
				$linkValue
			);

			return new JSONResponse($tile->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update a tile
	 *
	 * @param int         $id               The tile ID.
	 * @param string|null $title            The tile title.
	 * @param string|null $icon             The icon (class, URL, or emoji).
	 * @param string|null $iconType         The icon type (class, url, or emoji).
	 * @param string|null $backgroundColor  The background color (hex).
	 * @param string|null $textColor        The text color (hex).
	 * @param string|null $linkType         The link type (app or url).
	 * @param string|null $linkValue        The link value (app ID or URL).
	 *
	 * @return JSONResponse The updated tile.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(
		$id,
		$title = null,
		?string $icon = null,
		?string $iconType = null,
		?string $backgroundColor = null,
		?string $textColor = null,
		?string $linkType = null,
		?string $linkValue = null
	): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		// Handle JSON body - Nextcloud sends JSON POST/PUT data as array.
		if (is_array($id)) {
			$data = $id;
			$id = $data['id'] ?? null;
		} else {
			// Build data array from parameters.
			$data = [];
			if ($title !== null) {
				$data['title'] = $title;
			}
			if ($icon !== null) {
				$data['icon'] = $icon;
			}
			if ($iconType !== null) {
				$data['iconType'] = $iconType;
			}
			if ($backgroundColor !== null) {
				$data['backgroundColor'] = $backgroundColor;
			}
			if ($textColor !== null) {
				$data['textColor'] = $textColor;
			}
			if ($linkType !== null) {
				$data['linkType'] = $linkType;
			}
			if ($linkValue !== null) {
				$data['linkValue'] = $linkValue;
			}
		}

		if ($id === null) {
			return new JSONResponse(['error' => 'Missing tile ID'], Http::STATUS_BAD_REQUEST);
		}

		try {
			// Build data array from JSON body if it was an array.
			if (is_array($title)) {
				$updateData = [];
				if (isset($data['title'])) {
					$updateData['title'] = $data['title'];
				}
				if (isset($data['icon'])) {
					$updateData['icon'] = $data['icon'];
				}
				if (isset($data['iconType'])) {
					$updateData['iconType'] = $data['iconType'];
				}
				if (isset($data['backgroundColor'])) {
					$updateData['backgroundColor'] = $data['backgroundColor'];
				}
				if (isset($data['textColor'])) {
					$updateData['textColor'] = $data['textColor'];
				}
				if (isset($data['linkType'])) {
					$updateData['linkType'] = $data['linkType'];
				}
				if (isset($data['linkValue'])) {
					$updateData['linkValue'] = $data['linkValue'];
				}
			} else {
				$updateData = $data;
			}

			$tile = $this->tileService->updateTile((int)$id, $this->userId, $updateData);

			return new JSONResponse($tile->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a tile
	 *
	 * @param int $id The tile ID.
	 *
	 * @return JSONResponse Success response.
	 */
	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->tileService->deleteTile($id, $this->userId);

			return new JSONResponse(['status' => 'ok']);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
