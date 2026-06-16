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
     * Find all placements that reference a given widget id across the
     * entire instance — used by {@see \OCA\MyDash\Service\FeedRefreshService::discoverFeedUrls()}
     * to pull every news-widget placement before each refresh tick
     * (REQ-FRJ-003).
     *
     * @param string $widgetId The widget id (e.g. `'mydash_news'`).
     *
     * @return WidgetPlacement[] The matching placements.
     */
    public function findByWidgetId(string $widgetId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'widget_id',
                    y: $qb->createNamedParameter(value: $widgetId)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByWidgetId()

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
     * Delete all placements for a dashboard identified by UUID.
     *
     * Used by the cascade-event listener path where only the UUID is
     * available (the int ID is not carried by DashboardDeletedEvent).
     * Executes a sub-select to translate UUID → id so no additional
     * mapper is required in the listener. C4 fix (REQ-CSC-003).
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByDashboardUuid(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->in(
                    x: 'dashboard_id',
                    y: $qb->createFunction(
                        call: '(SELECT id FROM `*PREFIX*mydash_dashboards` WHERE `uuid` = '
                            .$qb->createNamedParameter(value: $dashboardUuid).')'
                    )
                )
            );

        return $qb->executeStatement();
    }//end deleteByDashboardUuid()

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
     * Clone all placements from one dashboard to another.
     *
     * Reads the source rows via {@see self::findByDashboardId()} and
     * inserts a new row per source under `$targetDashboardId`. All
     * widget-, tile-, style- and grid-fields are byte-for-byte copied
     * (REQ-DASH-020) — including resource URL fields like `tileIcon`
     * which intentionally point at the same shared resource record
     * (REQ-DASH-022). The new rows receive fresh `id`, `dashboardId`
     * pointing at the target, and `createdAt` / `updatedAt` set to now.
     *
     * Used by {@see \OCA\MyDash\Service\DashboardService::forkAsPersonal()}
     * inside a single transaction — any DB exception thrown here MUST
     * be left to bubble so the caller can roll back.
     *
     * @param int $sourceDashboardId The source dashboard ID.
     * @param int $targetDashboardId The destination dashboard ID.
     *
     * @return int The number of placements cloned.
     */
    public function cloneToDashboard(
        int $sourceDashboardId,
        int $targetDashboardId
    ): int {
        $rows  = $this->findByDashboardId(dashboardId: $sourceDashboardId);
        $now   = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $count = 0;

        foreach ($rows as $row) {
            $clone = new WidgetPlacement();
            // Force the new dashboard id — the rest of the fields below
            // are byte-for-byte copies of the source row.
            $clone->setDashboardId($targetDashboardId);
            $clone->setWidgetId($row->getWidgetId());
            $clone->setGridX($row->getGridX());
            $clone->setGridY($row->getGridY());
            $clone->setGridWidth($row->getGridWidth());
            $clone->setGridHeight($row->getGridHeight());
            $clone->setIsCompulsory($row->getIsCompulsory());
            $clone->setIsVisible($row->getIsVisible());
            $clone->setStyleConfig($row->getStyleConfig());
            $clone->setCustomTitle($row->getCustomTitle());
            $clone->setCustomIcon($row->getCustomIcon());
            $clone->setShowTitle($row->getShowTitle());
            $clone->setSortOrder($row->getSortOrder());
            $clone->setTileType($row->getTileType());
            $clone->setTileTitle($row->getTileTitle());
            // REQ-DASH-022: same `/apps/mydash/resource/...` URL — no
            // file is duplicated in app data, both dashboards reference
            // the shared resource record.
            $clone->setTileIcon($row->getTileIcon());
            $clone->setTileIconType($row->getTileIconType());
            $clone->setTileBackgroundColor($row->getTileBackgroundColor());
            $clone->setTileTextColor($row->getTileTextColor());
            $clone->setTileLinkType($row->getTileLinkType());
            $clone->setTileLinkValue($row->getTileLinkValue());
            $clone->setCreatedAt($now);
            $clone->setUpdatedAt($now);

            $this->insert(entity: $clone);
            $count++;
        }//end foreach

        return $count;
    }//end cloneToDashboard()

    /**
     * Count placement rows whose `dashboard_id` no longer points at
     * any row in `mydash_dashboards`.
     *
     * Used by the orphaned-data-cleanup scan path (REQ-CLN-001).
     * Placements are normally cleared by `DashboardService::delete()`;
     * a row left behind here usually indicates a crashed delete path
     * or a manual SQL operation.
     *
     * @return int The number of orphaned placement rows.
     */
    public function countOrphaned(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('p.id'))
            ->from(from: $this->getTableName(), alias: 'p')
            ->leftJoin(
                fromAlias: 'p',
                join: 'mydash_dashboards',
                alias: 'd',
                condition: 'd.id = p.dashboard_id'
            )
            ->where($qb->expr()->isNull(x: 'd.id'));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) ($count ?? 0);
    }//end countOrphaned()

    /**
     * Delete placement rows whose `dashboard_id` no longer points at
     * any row in `mydash_dashboards`.
     *
     * Companion to {@see self::countOrphaned()} on the purge path
     * (REQ-CLN-002). Resolves the orphan IDs first via a SELECT and
     * then deletes by primary key for portability across drivers.
     *
     * @return int The number of rows deleted.
     */
    public function deleteOrphaned(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: 'p.id')
            ->from(from: $this->getTableName(), alias: 'p')
            ->leftJoin(
                fromAlias: 'p',
                join: 'mydash_dashboards',
                alias: 'd',
                condition: 'd.id = p.dashboard_id'
            )
            ->where($qb->expr()->isNull(x: 'd.id'));

        $result = $qb->executeQuery();
        $ids    = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (int) $row['id'];
        }

        $result->closeCursor();

        if (count(value: $ids) === 0) {
            return 0;
        }

        $delete = $this->db->getQueryBuilder();
        $delete->delete(delete: $this->getTableName())
            ->where(
                $delete->expr()->in(
                    x: 'id',
                    y: $delete->createNamedParameter(
                        value: $ids,
                        type: IQueryBuilder::PARAM_INT_ARRAY
                    )
                )
            );

        return $delete->executeStatement();
    }//end deleteOrphaned()

    /**
     * Count widget placements for a dashboard (REQ-TMPL-014).
     *
     * Used by the gallery serialiser so the response includes the
     * `widgetCount` field without fetching the full placement entities
     * (the gallery is a list view; widget bodies are not included).
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return int The number of placements (0 when none).
     */
    public function countByDashboardId(int $dashboardId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
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

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false || isset($row['cnt']) === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }//end countByDashboardId()

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
