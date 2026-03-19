<?php

/**
 * TileMapper
 *
 * Database mapper for tile entities.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
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

namespace OCA\MyDash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TileMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_tiles',
            entityClass: Tile::class
        );
    }//end __construct()

    /**
     * Find all tiles for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Tile[] Array of tiles.
     */
    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByUserId()

    /**
     * Find a tile by ID for a specific user.
     *
     * @param int    $id     The tile ID.
     * @param string $userId The user ID.
     *
     * @return Tile The tile.
     * @throws \OCP\AppFramework\Db\DoesNotExistException If tile not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple found.
     */
    public function findByIdAndUser(int $id, string $userId): Tile
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $id,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByIdAndUser()

    /**
     * Delete all tiles for a user.
     *
     * @param string $userId The user ID.
     *
     * @return void
     */
    public function deleteByUserId(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            )
            ->executeStatement();
    }//end deleteByUserId()
}//end class
