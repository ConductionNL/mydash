<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ConditionalRule>
 */
class ConditionalRuleMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mydash_conditional_rules', ConditionalRule::class);
	}

	/**
	 * Find rule by ID
	 *
	 * @throws DoesNotExistException
	 */
	public function find(int $id): ConditionalRule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find all rules for a widget placement
	 *
	 * @return ConditionalRule[]
	 */
	public function findByPlacementId(int $placementId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('widget_placement_id', $qb->createNamedParameter($placementId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete all rules for a widget placement
	 */
	public function deleteByPlacementId(int $placementId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('widget_placement_id', $qb->createNamedParameter($placementId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}
