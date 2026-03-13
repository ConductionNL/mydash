<?php

/**
 * WidgetPlacementMapper
 *
 * Database mapper for widget placements.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * WidgetPlacementMapper
 *
 * Mapper for widget placement entities.
 *
 * @extends QBMapper<WidgetPlacement>
 */
class WidgetPlacementMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_widget_placements',
            entityClass: WidgetPlacement::class
        );
    }//end __construct()

    /**
     * Find placement by ID.
     *
     * @param int $id The placement ID.
     *
     * @return WidgetPlacement The found placement.
     *
     * @throws DoesNotExistException If not found.
     */
    public function find(int $id): WidgetPlacement
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
            );

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find all placements for a dashboard.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return WidgetPlacement[] The list of placements.
     */
    public function findByDashboardId(int $dashboardId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_id',
                    y: $qb->createNamedParameter(
                        value: $dashboardId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->orderBy(sort: 'sort_order', order: 'ASC')
            ->addOrderBy(sort: 'grid_y', order: 'ASC')
            ->addOrderBy(sort: 'grid_x', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByDashboardId()

    /**
     * Find placement by dashboard and widget ID.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $widgetId    The widget ID.
     *
     * @return WidgetPlacement[] The matching placements.
     */
    public function findByDashboardAndWidget(
        int $dashboardId,
        string $widgetId
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_id',
                    y: $qb->createNamedParameter(
                        value: $dashboardId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'widget_id',
                    y: $qb->createNamedParameter(value: $widgetId)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByDashboardAndWidget()

    /**
     * Delete all placements for a dashboard.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return void
     */
    public function deleteByDashboardId(int $dashboardId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_id',
                    y: $qb->createNamedParameter(
                        value: $dashboardId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        $qb->executeStatement();
    }//end deleteByDashboardId()

    /**
     * Update grid positions for multiple placements.
     *
     * @param array $updates Array of position updates.
     *
     * @return void
     */
    public function updatePositions(array $updates): void
    {
        foreach ($updates as $update) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(update: $this->getTableName())
                ->set(
                    key: 'grid_x',
                    value: $qb->createNamedParameter(
                        value: $update['gridX'] ?? 0,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
                ->set(
                    key: 'grid_y',
                    value: $qb->createNamedParameter(
                        value: $update['gridY'] ?? 0,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
                ->set(
                    key: 'grid_width',
                    value: $qb->createNamedParameter(
                        value: $update['gridWidth'] ?? 4,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
                ->set(
                    key: 'grid_height',
                    value: $qb->createNamedParameter(
                        value: $update['gridHeight'] ?? 4,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
                ->set(
                    key: 'updated_at',
                    value: $qb->createNamedParameter(
                        value: new DateTime(),
                        type: IQueryBuilder::PARAM_DATETIME_MUTABLE
                    )
                )
                ->where(
                    $qb->expr()->eq(
                        x: 'id',
                        y: $qb->createNamedParameter(
                            value: $update['id'],
                            type: IQueryBuilder::PARAM_INT
                        )
                    )
                );

            $qb->executeStatement();
        }//end foreach
    }//end updatePositions()

    /**
     * Get max sort order for a dashboard.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return int The maximum sort order.
     */
    public function getMaxSortOrder(int $dashboardId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max(field: 'sort_order'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_id',
                    y: $qb->createNamedParameter(
                        value: $dashboardId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        $result = $qb->executeQuery();
        $max    = $result->fetchOne();
        $result->closeCursor();

        return (int) ($max ?? 0);
    }//end getMaxSortOrder()
}//end class
