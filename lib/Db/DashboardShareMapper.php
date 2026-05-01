<?php

/**
 * DashboardShareMapper
 *
 * Database mapper for DashboardShare entities. Covers the
 * oc_mydash_dashboard_shares table. REQ-SHARE-001.
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
 * Mapper for DashboardShare entities.
 *
 * @extends QBMapper<DashboardShare>
 */
class DashboardShareMapper extends QBMapper
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
            tableName: 'mydash_dashboard_shares',
            entityClass: DashboardShare::class
        );
    }//end __construct()

    /**
     * Find a share by ID.
     *
     * @param int $id The share ID.
     *
     * @return DashboardShare The share.
     *
     * @throws DoesNotExistException When not found.
     */
    public function find(int $id): DashboardShare
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
     * Find all shares for a dashboard.
     *
     * @param int $dashboardId The dashboard ID.
     *
     * @return DashboardShare[] The shares.
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
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByDashboardId()

    /**
     * Find shares for a dashboard with a specific permission level.
     *
     * @param int    $dashboardId     The dashboard ID.
     * @param string $permissionLevel The required permission level.
     *
     * @return DashboardShare[] The shares.
     */
    public function findByDashboardAndLevel(
        int $dashboardId,
        string $permissionLevel
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
                    x: 'permission_level',
                    y: $qb->createNamedParameter(value: $permissionLevel)
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByDashboardAndLevel()

    /**
     * Find a specific share by (dashboardId, shareType, shareWith).
     *
     * Returns null when not found (no exception).
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $shareType   The share type.
     * @param string $shareWith   The recipient.
     *
     * @return DashboardShare|null The share or null.
     */
    public function findShare(
        int $dashboardId,
        string $shareType,
        string $shareWith
    ): ?DashboardShare {
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
                    x: 'share_type',
                    y: $qb->createNamedParameter(value: $shareType)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'share_with',
                    y: $qb->createNamedParameter(value: $shareWith)
                )
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findShare()

    /**
     * Find every share that grants access to a given user, considering both
     * direct user shares and group shares. REQ-SHARE-002.
     *
     * @param string   $userId   The recipient user id.
     * @param string[] $groupIds The recipient's group ids.
     *
     * @return DashboardShare[] The shares.
     */
    public function findForRecipient(string $userId, array $groupIds): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName());

        $userClause = $qb->expr()->andX(
            $qb->expr()->eq(
                x: 'share_type',
                y: $qb->createNamedParameter(value: DashboardShare::SHARE_TYPE_USER)
            ),
            $qb->expr()->eq(
                x: 'share_with',
                y: $qb->createNamedParameter(value: $userId)
            )
        );

        if (count($groupIds) > 0) {
            $groupClause = $qb->expr()->andX(
                $qb->expr()->eq(
                    x: 'share_type',
                    y: $qb->createNamedParameter(value: DashboardShare::SHARE_TYPE_GROUP)
                ),
                $qb->expr()->in(
                    x: 'share_with',
                    y: $qb->createNamedParameter(
                        value: $groupIds,
                        type: IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );
            $qb->where($qb->expr()->orX($userClause, $groupClause));
        } else {
            $qb->where($userClause);
        }

        return $this->findEntities(query: $qb);
    }//end findForRecipient()

    /**
     * Delete all shares for a dashboard.
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
     * Delete all shares where the recipient is a specific user.
     *
     * Only removes user-type shares (group membership cleanup is handled
     * by Nextcloud's own group management hooks). REQ-SHARE-012.
     *
     * @param string $userId The recipient user ID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByRecipientUser(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'share_type',
                    y: $qb->createNamedParameter(value: DashboardShare::SHARE_TYPE_USER)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'share_with',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        return $qb->executeStatement();
    }//end deleteByRecipientUser()

    /**
     * Delete all shares not in the given set for a dashboard.
     *
     * Used by the bulk-replace endpoint to remove shares not in the new
     * payload. REQ-SHARE-009.
     *
     * @param int      $dashboardId The dashboard ID.
     * @param string[] $keepKeys    Keys of form "{shareType}:{shareWith}"
     *                              to preserve.
     *
     * @return int The number of rows deleted.
     */
    public function deleteNotIn(int $dashboardId, array $keepKeys): int
    {
        // Load existing rows and delete those whose composite key is not in keepKeys.
        $existing = $this->findByDashboardId(dashboardId: $dashboardId);
        $deleted  = 0;
        foreach ($existing as $share) {
            $key = $share->getShareType().':'.$share->getShareWith();
            if (in_array(needle: $key, haystack: $keepKeys, strict: true) === false) {
                $this->delete(entity: $share);
                $deleted++;
            }
        }

        return $deleted;
    }//end deleteNotIn()

    /**
     * Delete all shares the caller owns that target a specific recipient.
     *
     * Joins logically: finds dashboards owned by $ownerId via DashboardMapper
     * query. Uses a subquery on the dashboard table. REQ-SHARE-010.
     *
     * @param string $shareType The share type.
     * @param string $shareWith The recipient.
     * @param string $ownerId   The dashboard owner.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByOwnerAndRecipient(
        string $shareType,
        string $shareWith,
        string $ownerId
    ): int {
        // Subquery: SELECT id FROM mydash_dashboards WHERE user_id = ownerId.
        $sub = $this->db->getQueryBuilder();
        $sub->select(selects: 'id')
            ->from(from: 'mydash_dashboards')
            ->where(
                $sub->expr()->eq(
                    x: 'user_id',
                    y: $sub->createNamedParameter(value: $ownerId)
                )
            );

        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->in(
                    x: 'dashboard_id',
                    y: $qb->createFunction(
                        call: '('.$sub->getSQL().')'
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'share_type',
                    y: $qb->createNamedParameter(value: $shareType)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'share_with',
                    y: $qb->createNamedParameter(value: $shareWith)
                )
            );

        // Merge parameters from sub into main.
        foreach ($sub->getParameters() as $key => $value) {
            $qb->setParameter(key: $key, value: $value);
        }

        return $qb->executeStatement();
    }//end deleteByOwnerAndRecipient()
}//end class
