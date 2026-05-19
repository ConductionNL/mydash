<?php

/**
 * DashboardViewMapper
 *
 * Mapper for the dashboard view-analytics aggregate table
 * (REQ-ANLT-001..010). Owns the upsert path that increments daily
 * counters, the date-range scans used by the admin endpoints, and
 * the retention-purge that keeps the table bounded
 * (REQ-ANLT-009).
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for dashboard view-analytics aggregate rows.
 *
 * @extends QBMapper<DashboardView>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DashboardViewMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_dashboard_views',
            entityClass: DashboardView::class
        );
    }//end __construct()

    /**
     * Find the aggregate row for `(dashboardUuid, viewBucket)`.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $viewBucket    The UTC date `YYYY-MM-DD`.
     *
     * @return DashboardView|null The row or `null` when absent.
     */
    public function findByDashboardAndBucket(
        string $dashboardUuid,
        string $viewBucket
    ): ?DashboardView {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $viewBucket)
                )
            )
            ->setMaxResults(maxResults: 1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findByDashboardAndBucket()

    /**
     * Find every aggregate row for one dashboard within an inclusive
     * date range, ordered by `viewBucket` ascending (REQ-ANLT-007).
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $startDate     Inclusive lower bound (`YYYY-MM-DD`).
     * @param string $endDate       Inclusive upper bound (`YYYY-MM-DD`).
     *
     * @return DashboardView[] The rows in ascending date order.
     */
    public function findByDashboardInRange(
        string $dashboardUuid,
        string $startDate,
        string $endDate
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->gte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $startDate)
                )
            )
            ->andWhere(
                $qb->expr()->lte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $endDate)
                )
            )
            ->orderBy(sort: 'view_bucket', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByDashboardInRange()

    /**
     * Find the top-N dashboards by total view count in an inclusive
     * date range (REQ-ANLT-006). Returns an array of plain assoc
     * rows (not entities) because the result is an aggregate over
     * many rows per dashboard, not a single row per UUID.
     *
     * @param string $startDate Inclusive lower bound (`YYYY-MM-DD`).
     * @param string $endDate   Inclusive upper bound (`YYYY-MM-DD`).
     * @param int    $limit     Maximum rows to return.
     *
     * @return array<int, array{dashboardUuid: string, viewCount: int, uniqueViewerCount: int}>
     *   Aggregate rows ordered by `viewCount` descending.
     */
    public function findTopDashboardsInRange(
        string $startDate,
        string $endDate,
        int $limit
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'dashboard_uuid'
        )
            ->selectAlias(
                $qb->func()->sum('view_count'),
                'view_count_sum'
            )
            ->selectAlias(
                $qb->func()->sum('unique_viewer_count'),
                'unique_viewer_sum'
            )
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->gte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $startDate)
                )
            )
            ->andWhere(
                $qb->expr()->lte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $endDate)
                )
            )
            ->groupBy('dashboard_uuid')
            ->orderBy(sort: 'view_count_sum', order: 'DESC')
            ->setMaxResults(maxResults: $limit);

        $cursor = $qb->executeQuery();
        $rows   = [];
        while (($row = $cursor->fetch()) !== false) {
            $rows[] = [
                'dashboardUuid'     => (string) $row['dashboard_uuid'],
                'viewCount'         => (int) $row['view_count_sum'],
                'uniqueViewerCount' => (int) $row['unique_viewer_sum'],
            ];
        }

        $cursor->closeCursor();

        return $rows;
    }//end findTopDashboardsInRange()

    /**
     * Compute instance-wide totals across an inclusive date range
     * (REQ-ANLT-008).
     *
     * @param string $startDate Inclusive lower bound (`YYYY-MM-DD`).
     * @param string $endDate   Inclusive upper bound (`YYYY-MM-DD`).
     *
     * @return array{totalViewCount: int, totalUniqueViewers: int, dashboardCount: int}
     *   The computed totals.
     */
    public function findInstanceSummaryInRange(
        string $startDate,
        string $endDate
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias(
            $qb->func()->sum('view_count'),
            'total_view_count'
        )
            ->selectAlias(
                $qb->func()->sum('unique_viewer_count'),
                'total_unique'
            )
            ->selectAlias(
                $qb->createFunction(
                    'COUNT(DISTINCT dashboard_uuid)'
                ),
                'dashboard_count'
            )
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->gte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $startDate)
                )
            )
            ->andWhere(
                $qb->expr()->lte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $endDate)
                )
            );

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false) {
            return [
                'totalViewCount'     => 0,
                'totalUniqueViewers' => 0,
                'dashboardCount'     => 0,
            ];
        }

        return [
            'totalViewCount'     => (int) ($row['total_view_count'] ?? 0),
            'totalUniqueViewers' => (int) ($row['total_unique'] ?? 0),
            'dashboardCount'     => (int) ($row['dashboard_count'] ?? 0),
        ];
    }//end findInstanceSummaryInRange()

    /**
     * Find every aggregate row in an inclusive date range, ordered
     * for CSV export (REQ-ANLT-010).
     *
     * @param string $startDate Inclusive lower bound (`YYYY-MM-DD`).
     * @param string $endDate   Inclusive upper bound (`YYYY-MM-DD`).
     *
     * @return DashboardView[] The rows ordered by `(dashboard_uuid,
     *                         view_bucket)` ascending.
     */
    public function findAllInRange(
        string $startDate,
        string $endDate
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->gte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $startDate)
                )
            )
            ->andWhere(
                $qb->expr()->lte(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $endDate)
                )
            )
            ->orderBy(sort: 'dashboard_uuid', order: 'ASC')
            ->addOrderBy(sort: 'view_bucket', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAllInRange()

    /**
     * Increment counters for `(dashboardUuid, viewBucket)`,
     * inserting the row when it does not yet exist (REQ-ANLT-001
     * "one row per dashboard per day").
     *
     * @param string $dashboardUuid    The dashboard UUID.
     * @param string $viewBucket       The UTC date `YYYY-MM-DD`.
     * @param int    $viewCountDelta   Increment for `view_count`.
     * @param int    $uniqueCountDelta Increment for
     *                                 `unique_viewer_count`.
     *
     * @return DashboardView The persisted row after the update.
     */
    public function upsertView(
        string $dashboardUuid,
        string $viewBucket,
        int $viewCountDelta,
        int $uniqueCountDelta
    ): DashboardView {
        $existing = $this->findByDashboardAndBucket(
            dashboardUuid: $dashboardUuid,
            viewBucket: $viewBucket
        );

        if ($existing !== null) {
            $existing->setViewCount(
                $existing->getViewCount() + $viewCountDelta
            );
            $existing->setUniqueViewerCount(
                $existing->getUniqueViewerCount() + $uniqueCountDelta
            );

            return $this->update(entity: $existing);
        }

        $row = new DashboardView();
        $row->setDashboardUuid($dashboardUuid);
        $row->setViewBucket($viewBucket);
        $row->setViewCount(max($viewCountDelta, 0));
        $row->setUniqueViewerCount(max($uniqueCountDelta, 0));

        return $this->insert(entity: $row);
    }//end upsertView()

    /**
     * Delete every aggregate row whose `view_bucket` is strictly
     * older than `$beforeDate` (REQ-ANLT-009).
     *
     * @param string $beforeDate Exclusive cutoff (`YYYY-MM-DD`).
     *
     * @return int The number of rows deleted.
     */
    public function deleteOlderThan(string $beforeDate): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->lt(
                    x: 'view_bucket',
                    y: $qb->createNamedParameter(value: $beforeDate)
                )
            );

        return $qb->executeStatement();
    }//end deleteOlderThan()

    /**
     * Delete every aggregate row for a single dashboard (used when
     * the parent dashboard itself is deleted — eager cleanup so a
     * later cascade FK migration is not required).
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByDashboard(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        return $qb->executeStatement();
    }//end deleteByDashboard()
}//end class
