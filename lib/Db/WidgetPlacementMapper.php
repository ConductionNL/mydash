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
 * @extends QBMapper<WidgetPlacement>
 */
class WidgetPlacementMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'mydash_widget_placements', WidgetPlacement::class);
	}

	/**
	 * Find placement by ID
	 *
	 * @throws DoesNotExistException
	 */
	public function find(int $id): WidgetPlacement {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find all placements for a dashboard
	 *
	 * @return WidgetPlacement[]
	 */
	public function findByDashboardId(int $dashboardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('dashboard_id', $qb->createNamedParameter($dashboardId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('grid_y', 'ASC')
			->addOrderBy('grid_x', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find placement by dashboard and widget ID
	 *
	 * @return WidgetPlacement[]
	 */
	public function findByDashboardAndWidget(int $dashboardId, string $widgetId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('dashboard_id', $qb->createNamedParameter($dashboardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('widget_id', $qb->createNamedParameter($widgetId)));

		return $this->findEntities($qb);
	}

	/**
	 * Delete all placements for a dashboard
	 */
	public function deleteByDashboardId(int $dashboardId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('dashboard_id', $qb->createNamedParameter($dashboardId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Update grid positions for multiple placements
	 *
	 * @param array $updates Array of ['id' => int, 'gridX' => int, 'gridY' => int, 'gridWidth' => int, 'gridHeight' => int]
	 */
	public function updatePositions(array $updates): void {
		foreach ($updates as $update) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('grid_x', $qb->createNamedParameter($update['gridX'] ?? 0, IQueryBuilder::PARAM_INT))
				->set('grid_y', $qb->createNamedParameter($update['gridY'] ?? 0, IQueryBuilder::PARAM_INT))
				->set('grid_width', $qb->createNamedParameter($update['gridWidth'] ?? 4, IQueryBuilder::PARAM_INT))
				->set('grid_height', $qb->createNamedParameter($update['gridHeight'] ?? 4, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($update['id'], IQueryBuilder::PARAM_INT)));

			$qb->executeStatement();
		}
	}

	/**
	 * Get max sort order for a dashboard
	 */
	public function getMaxSortOrder(int $dashboardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))
			->from($this->getTableName())
			->where($qb->expr()->eq('dashboard_id', $qb->createNamedParameter($dashboardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return (int)($max ?? 0);
	}
}
