<?php

/**
 * DashboardVersionMapper
 *
 * Database mapper for {@see DashboardVersion} entities backing the
 * `oc_mydash_dashboard_versions` table. Handles per-dashboard listing,
 * monotonic versionNumber allocation, retention pruning (50-row limit
 * per dashboard, REQ-VERS-006), and cascade cleanup on dashboard
 * deletion.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for DashboardVersion entities.
 *
 * @extends QBMapper<DashboardVersion>
 */
class DashboardVersionMapper extends QBMapper
{

    /**
     * Default per-dashboard retention limit (REQ-VERS-006).
     *
     * @var integer
     */
    public const DEFAULT_RETENTION = 50;

    /**
     * Constructor
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_dashboard_versions',
            entityClass: DashboardVersion::class
        );
    }//end __construct()

    /**
     * Find every version row for a given dashboard, newest-first.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardVersion[] The version rows ordered by versionNumber DESC.
     */
    public function findByDashboardUuid(string $dashboardUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->orderBy(sort: 'version_number', order: 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByDashboardUuid()

    /**
     * Find the newest N version rows for a dashboard, newest-first.
     *
     * @param string  $dashboardUuid The dashboard UUID.
     * @param integer $limit         Maximum number of rows.
     *
     * @return DashboardVersion[] The newest-first version rows.
     */
    public function findLatestByDashboard(
        string $dashboardUuid,
        int $limit
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
            ->orderBy(sort: 'version_number', order: 'DESC')
            ->setMaxResults(maxResults: $limit);

        return $this->findEntities(query: $qb);
    }//end findLatestByDashboard()

    /**
     * Find a single version row by (dashboardUuid, versionNumber).
     *
     * @param string  $dashboardUuid The dashboard UUID.
     * @param integer $versionNumber The version number.
     *
     * @return DashboardVersion The version row.
     *
     * @throws DoesNotExistException When the row does not exist.
     */
    public function findByDashboardAndVersion(
        string $dashboardUuid,
        int $versionNumber
    ): DashboardVersion {
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
                    x: 'version_number',
                    y: $qb->createNamedParameter(
                        value: $versionNumber,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByDashboardAndVersion()

    /**
     * Find the highest versionNumber currently stored for a dashboard.
     *
     * Used by {@see DashboardVersionService::captureSnapshot()} to allocate
     * the next monotonic versionNumber. Returns `0` when the dashboard
     * has no snapshots yet.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return integer The highest versionNumber, or 0 if none.
     */
    public function findMaxVersionNumber(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $qb->select($qb->func()->max('version_number'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        $result = $qb->executeQuery();
        $value  = $result->fetchOne();
        $result->closeCursor();

        if ($value === false || $value === null) {
            return 0;
        }

        return (int) $value;
    }//end findMaxVersionNumber()

    /**
     * Count how many version rows exist for a dashboard.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return integer The version row count.
     */
    public function countByDashboard(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $qb->select($qb->func()->count('*'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        $result = $qb->executeQuery();
        $value  = $result->fetchOne();
        $result->closeCursor();

        if ($value === false || $value === null) {
            return 0;
        }

        return (int) $value;
    }//end countByDashboard()

    /**
     * Delete the oldest rows for a dashboard until at most `$keepCount`
     * remain (REQ-VERS-006).
     *
     * Pruning is monotonic-safe: surviving rows keep their original
     * versionNumber values, so the next snapshot continues the sequence
     * (REQ-VERS-006 scenario "pruning does not affect versionNumber
     * sequence").
     *
     * @param string  $dashboardUuid The dashboard UUID.
     * @param integer $keepCount     The maximum row count to retain.
     *
     * @return integer The number of rows deleted.
     */
    public function pruneOldVersions(
        string $dashboardUuid,
        int $keepCount=self::DEFAULT_RETENTION
    ): int {
        $total = $this->countByDashboard(dashboardUuid: $dashboardUuid);
        if ($total <= $keepCount) {
            return 0;
        }

        // Delete the lowest version_number rows (oldest by monotonic
        // numbering). We DO NOT use ORDER BY + LIMIT in the DELETE
        // because Postgres does not support it; instead we determine
        // the cutoff version number then delete everything below it.
        $cutoff = $this->findCutoffVersionNumber(
            dashboardUuid: $dashboardUuid,
            keepCount: $keepCount
        );
        if ($cutoff === null) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->lt(
                    x: 'version_number',
                    y: $qb->createNamedParameter(
                        value: $cutoff,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $qb->executeStatement();
    }//end pruneOldVersions()

    /**
     * Delete every version row for a given dashboard (cascade cleanup).
     *
     * Invoked from the dashboard delete path / cascade-events listener.
     * Idempotent — calling on a dashboard with no versions is a no-op.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return integer The number of rows deleted.
     */
    public function deleteByDashboardUuid(string $dashboardUuid): int
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
    }//end deleteByDashboardUuid()

    /**
     * Resolve the lowest versionNumber that should survive pruning.
     *
     * Returns the (total - keep + 1)-th newest versionNumber. Anything
     * strictly less than this value is older and gets dropped.
     *
     * @param string  $dashboardUuid The dashboard UUID.
     * @param integer $keepCount     The maximum row count to retain.
     *
     * @return integer|null The cutoff versionNumber, or null if nothing to prune.
     */
    private function findCutoffVersionNumber(
        string $dashboardUuid,
        int $keepCount
    ): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: 'version_number')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->orderBy(sort: 'version_number', order: 'DESC')
            ->setMaxResults(maxResults: $keepCount);

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        if (count($rows) < $keepCount) {
            return null;
        }

        $last = end($rows);
        if ($last === false || isset($last['version_number']) === false) {
            return null;
        }

        return (int) $last['version_number'];
    }//end findCutoffVersionNumber()
}//end class
