<?php

/**
 * DashboardLockMapper
 *
 * Database mapper for DashboardLock entities. Covers the
 * `mydash_dashboard_locks` table. REQ-LOCK-001..008.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for DashboardLock entities.
 *
 * @extends QBMapper<DashboardLock>
 */
class DashboardLockMapper extends QBMapper
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
            tableName: 'mydash_dashboard_locks',
            entityClass: DashboardLock::class
        );
    }//end __construct()

    /**
     * Find any lock row for the given dashboard (active or stale).
     *
     * Returns the row regardless of expiry — callers SHOULD apply the
     * `DashboardLock::isExpired()` predicate after the fetch when they
     * need active-only semantics. This is used by the inline cleanup
     * helper as well as by the service-layer ownership check.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardLock The lock row.
     *
     * @throws DoesNotExistException When no lock row exists.
     */
    public function findByDashboardUuid(string $dashboardUuid): DashboardLock
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByDashboardUuid()

    /**
     * Find an active (non-expired) lock for the given dashboard.
     *
     * The active threshold is computed in-query as `now - 15 minutes`;
     * any row with `updated_at >= threshold` is returned. Stale rows
     * are silently treated as if they don't exist.
     *
     * Returns `null` rather than throwing when no active lock exists,
     * since "no active lock" is a normal state.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardLock|null The active lock or null.
     */
    public function findActive(string $dashboardUuid): ?DashboardLock
    {
        $threshold = $this->expiryThreshold();

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
                    x: 'updated_at',
                    y: $qb->createNamedParameter(value: $threshold)
                )
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findActive()

    /**
     * Find every lock owned by a given user (active and stale).
     *
     * Used for auditing — admins may want to see all editing sessions
     * for a particular user across dashboards.
     *
     * @param string $userId The owner user ID.
     *
     * @return DashboardLock[] The locks (newest heartbeat first).
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
            ->orderBy(sort: 'updated_at', order: 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByUserId()

    /**
     * Delete the lock row for a single dashboard when its `updated_at`
     * is older than `now - 15 minutes`.
     *
     * Targeted (not a full-table sweep) so the operation stays cheap
     * and deterministic — it only touches the row the caller is about
     * to act on. Returns the number of rows deleted (0 or 1).
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted (0 or 1).
     */
    public function deleteExpiredForDashboard(string $dashboardUuid): int
    {
        $threshold = $this->expiryThreshold();

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
                    x: 'updated_at',
                    y: $qb->createNamedParameter(value: $threshold)
                )
            );

        return $qb->executeStatement();
    }//end deleteExpiredForDashboard()

    /**
     * Delete every stale lock row across all dashboards.
     *
     * Provided for completeness — not called on the hot path. Useful
     * for an optional background sweeper in a follow-up change.
     *
     * @return int The number of rows deleted.
     */
    public function deleteAllExpired(): int
    {
        $threshold = $this->expiryThreshold();

        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->lt(
                    x: 'updated_at',
                    y: $qb->createNamedParameter(value: $threshold)
                )
            );

        return $qb->executeStatement();
    }//end deleteAllExpired()

    /**
     * Delete the lock row for a single dashboard regardless of expiry.
     *
     * Used by `DashboardLockService::releaseLock()` and the cascade
     * triggered by `DashboardService::delete()` (REQ-LOCK-008).
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted (0 or 1).
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
     * Count every stale lock row across all dashboards.
     *
     * Mirror of {@see self::deleteAllExpired()} for the orphaned-data
     * cleanup scan path (REQ-CLN-001). Same `updated_at < now - 15
     * min` predicate so the scan total matches the purge delete count
     * one-to-one.
     *
     * @return int The number of stale rows.
     */
    public function countAllExpired(): int
    {
        $threshold = $this->expiryThreshold();

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->lt(
                    x: 'updated_at',
                    y: $qb->createNamedParameter(value: $threshold)
                )
            );

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) ($count ?? 0);
    }//end countAllExpired()

    /**
     * Compute the active-lock threshold timestamp (`now - 15 min`).
     *
     * Centralised so the in-query expiry filter and the explicit
     * delete predicate stay in sync.
     *
     * @return string The threshold formatted as `Y-m-d H:i:s`.
     */
    private function expiryThreshold(): string
    {
        $threshold = (new DateTime());
        $threshold->setTimestamp(
            timestamp: (time() - DashboardLock::LOCK_TIMEOUT_SECONDS)
        );
        return $threshold->format(format: 'Y-m-d H:i:s');
    }//end expiryThreshold()
}//end class
