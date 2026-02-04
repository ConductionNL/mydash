<?php

declare(strict_types=1);

/**
 * TileMapper
 *
 * @category Database
 * @package  OCA\MyDash\Db
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TileMapper extends QBMapper {

	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mydash_tiles', Tile::class);
	}

	/**
	 * Find all tiles for a user
	 *
	 * @param string $userId The user ID.
	 *
	 * @return Tile[] Array of tiles.
	 */
	public function findByUserId(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find a tile by ID for a specific user
	 *
	 * @param int    $id     The tile ID.
	 * @param string $userId The user ID.
	 *
	 * @return Tile The tile.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If tile not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple found.
	 */
	public function findByIdAndUser(int $id, string $userId): Tile {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * Delete all tiles for a user
	 *
	 * @param string $userId The user ID.
	 *
	 * @return void
	 */
	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}
}
