<?php

/**
 * TileService
 *
 * Service for managing tiles.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DateTime;
use OCA\MyDash\Db\Tile;
use OCA\MyDash\Db\TileMapper;

class TileService
{
    /**
     * Constructor
     *
     * @param TileMapper $tileMapper The tile mapper.
     */
    public function __construct(
        private readonly TileMapper $tileMapper,
    ) {
    }//end __construct()

    /**
     * Get all tiles for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Tile[] Array of tiles.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-29
     */
    public function getUserTiles(string $userId): array
    {
        return $this->tileMapper->findByUserId(userId: $userId);
    }//end getUserTiles()

    /**
     * Create a new tile.
     *
     * @param string $userId          The user ID.
     * @param string $title           The tile title.
     * @param string $icon            The icon (class, URL, or emoji).
     * @param string $iconType        The icon type (class, url, or emoji).
     * @param string $backgroundColor The background color (hex).
     * @param string $textColor       The text color (hex).
     * @param string $linkType        The link type (app or url).
     * @param string $linkValue       The link value (app ID or URL).
     *
     * @return Tile The created tile.
     *
     * @deprecated 1.0 Use the unified add-widget flow with `type: tile`
     *                 (REQ-WDG-022 / REQ-TILE-PLACEMENT) instead. New tile
     *                 placements MUST be created via
     *                 `POST /api/dashboards/{uuid}/widgets` with the
     *                 widget content stored inline on the placement; the
     *                 `oc_mydash_tiles` reusable-entity table is being
     *                 phased out. This method is preserved for legacy
     *                 callers and migration tooling only and MUST NOT be
     *                 invoked by new code paths.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-28
     */
    public function createTile(
        string $userId,
        string $title,
        string $icon,
        string $iconType='class',
        string $backgroundColor='#0082c9',
        string $textColor='#ffffff',
        string $linkType='url',
        string $linkValue='#'
    ): Tile {
        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');

        $tile = new Tile();
        $tile->setUserId($userId);
        $tile->setTitle($title);
        $tile->setIcon($icon);
        $tile->setIconType($iconType);
        $tile->setBackgroundColor($backgroundColor);
        $tile->setTextColor($textColor);
        $tile->setLinkType($linkType);
        $tile->setLinkValue($linkValue);
        $tile->setCreatedAt($now);
        $tile->setUpdatedAt($now);

        return $this->tileMapper->insert(entity: $tile);
    }//end createTile()

    /**
     * Update a tile.
     *
     * @param int    $id     The tile ID.
     * @param string $userId The user ID.
     * @param array  $data   The data to update.
     *
     * @return Tile The updated tile.
     * @throws \OCP\AppFramework\Db\DoesNotExistException If tile not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple found.
     *
     * @deprecated 1.0 Use the unified add-widget flow with `type: tile`
     *                 (REQ-WDG-022 / REQ-TILE-PLACEMENT) instead. Tile
     *                 placement edits MUST go through the standard
     *                 widget-placement update endpoint; the reusable
     *                 tile-entity table is read-only going forward.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-30
     */
    public function updateTile(int $id, string $userId, array $data): Tile
    {
        $tile = $this->tileMapper->findByIdAndUser(
            id: $id,
            userId: $userId
        );

        if (isset($data['title']) === true) {
            $tile->setTitle($data['title']);
        }

        if (isset($data['icon']) === true) {
            $tile->setIcon($data['icon']);
        }

        if (isset($data['iconType']) === true) {
            $tile->setIconType($data['iconType']);
        }

        if (isset($data['backgroundColor']) === true) {
            $tile->setBackgroundColor(
                $data['backgroundColor']
            );
        }

        if (isset($data['textColor']) === true) {
            $tile->setTextColor($data['textColor']);
        }

        if (isset($data['linkType']) === true) {
            $tile->setLinkType($data['linkType']);
        }

        if (isset($data['linkValue']) === true) {
            $tile->setLinkValue($data['linkValue']);
        }

        $tile->setUpdatedAt(
            (new DateTime())->format(format: 'Y-m-d H:i:s')
        );

        return $this->tileMapper->update(entity: $tile);
    }//end updateTile()

    /**
     * Delete a tile.
     *
     * @param int    $id     The tile ID.
     * @param string $userId The user ID.
     *
     * @return void
     * @throws \OCP\AppFramework\Db\DoesNotExistException If tile not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple found.
     *
     * @deprecated 1.0 Use the unified add-widget flow with `type: tile`
     *                 (REQ-WDG-022 / REQ-TILE-PLACEMENT) instead. The
     *                 controller's destroy endpoint now returns HTTP 410
     *                 Gone and this service method is preserved for
     *                 migration tooling that needs to clear out legacy
     *                 rows server-side.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-31
     */
    public function deleteTile(int $id, string $userId): void
    {
        $tile = $this->tileMapper->findByIdAndUser(
            id: $id,
            userId: $userId
        );
        $this->tileMapper->delete(entity: $tile);
    }//end deleteTile()
}//end class
