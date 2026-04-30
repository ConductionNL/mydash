<?php

/**
 * DashboardMapper
 *
 * Database mapper for dashboard entities.
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
 * DashboardMapper
 *
 * Mapper for dashboard entities.
 *
 * @extends QBMapper<Dashboard>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DashboardMapper extends QBMapper
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
            tableName: 'mydash_dashboards',
            entityClass: Dashboard::class
        );
    }//end __construct()

    /**
     * Find dashboard by ID.
     *
     * @param int $id The dashboard ID.
     *
     * @return Dashboard The found dashboard.
     *
     * @throws DoesNotExistException If not found.
     */
    public function find(int $id): Dashboard
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
     * Find dashboard by UUID.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return Dashboard The found dashboard.
     *
     * @throws DoesNotExistException If not found.
     */
    public function findByUuid(string $uuid): Dashboard
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $uuid)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByUuid()

    /**
     * Find all dashboards for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard[] The list of dashboards.
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
            ->andWhere(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_USER
                    )
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByUserId()

    /**
     * Find active dashboard for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard The active dashboard.
     *
     * @throws DoesNotExistException If no active dashboard exists.
     */
    public function findActiveByUserId(string $userId): Dashboard
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
            ->andWhere(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_USER
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'is_active',
                    y: $qb->createNamedParameter(
                        value: 1,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end findActiveByUserId()

    /**
     * Find all admin templates.
     *
     * @return Dashboard[] The list of admin templates.
     */
    public function findAdminTemplates(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            )
            ->orderBy(sort: 'name', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAdminTemplates()

    /**
     * Find default admin template.
     *
     * @return Dashboard The default template.
     *
     * @throws DoesNotExistException If no default template exists.
     */
    public function findDefaultTemplate(): Dashboard
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'is_default',
                    y: $qb->createNamedParameter(
                        value: 1,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end findDefaultTemplate()

    /**
     * Deactivate all dashboards for a user.
     *
     * @param string $userId The user ID.
     *
     * @return void
     */
    public function deactivateAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_active',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        $qb->executeStatement();
    }//end deactivateAllForUser()

    /**
     * Set a dashboard as active for a user.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The user ID.
     *
     * @return void
     */
    public function setActive(int $dashboardId, string $userId): void
    {
        // First, deactivate all dashboards for the user.
        $this->deactivateAllForUser(userId: $userId);

        // Then activate the specified dashboard.
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_active',
                value: $qb->createNamedParameter(
                    value: 1,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $dashboardId,
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

        $qb->executeStatement();
    }//end setActive()

    /**
     * Find all group-shared dashboards for a single group.
     *
     * Issues `WHERE type = 'group_shared' AND group_id = ?`. Used by the
     * group-scoped CRUD endpoints (REQ-DASH-014). The `default` group is
     * looked up the same way as any other group ID.
     *
     * @param string $groupId The group ID (real Nextcloud group ID, or
     *                        the literal {@see Dashboard::DEFAULT_GROUP_ID}
     *                        sentinel).
     *
     * @return Dashboard[] The group-shared dashboards in that group.
     */
    public function findByGroup(string $groupId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByGroup()

    /**
     * Find all dashboards visible to a user.
     *
     * Issues three indexed queries — personal `user`-type rows owned by
     * the user, `group_shared` rows whose `group_id` is in the user's
     * group memberships, and `group_shared` rows whose `group_id` equals
     * the {@see Dashboard::DEFAULT_GROUP_ID} sentinel — and unions /
     * dedupes the results by UUID. Each result row carries an extra
     * `_source` key (`'user'`, `'group'`, or `'default'`) so the caller
     * can attach the source field to the response. REQ-DASH-013.
     *
     * Priority order on UUID overlap is: user > group > default. This
     * keeps the `source` field deterministic when a user is in the
     * group _and_ also receives the dashboard via the default sentinel
     * (rare but handled).
     *
     * @param string   $userId       The user ID.
     * @param string[] $userGroupIds The user's Nextcloud group IDs.
     *
     * @return array<int, array{dashboard: Dashboard, source: string}>
     *   List of {dashboard, source} pairs, deduplicated by dashboard UUID.
     */
    public function findVisibleToUser(
        string $userId,
        array $userGroupIds
    ): array {
        $personal = $this->findByUserId(userId: $userId);

        $groupRows = [];
        if (empty($userGroupIds) === false) {
            $groupRows = $this->findGroupSharedInGroups(
                groupIds: $userGroupIds
            );
        }

        $defaultRows = $this->findByGroup(
            groupId: Dashboard::DEFAULT_GROUP_ID
        );

        // Dedup by UUID with priority user > group > default.
        $seen   = [];
        $result = [];

        foreach ($personal as $dashboard) {
            $uuid = (string) $dashboard->getUuid();
            if (isset($seen[$uuid]) === true) {
                continue;
            }

            $seen[$uuid] = true;
            $result[]    = [
                'dashboard' => $dashboard,
                'source'    => Dashboard::SOURCE_USER,
            ];
        }

        foreach ($groupRows as $dashboard) {
            $uuid = (string) $dashboard->getUuid();
            if (isset($seen[$uuid]) === true) {
                continue;
            }

            // The default-group sentinel rows are filtered out of the
            // group-bucket so they always appear under SOURCE_DEFAULT.
            if ($dashboard->getGroupId() === Dashboard::DEFAULT_GROUP_ID) {
                continue;
            }

            $seen[$uuid] = true;
            $result[]    = [
                'dashboard' => $dashboard,
                'source'    => Dashboard::SOURCE_GROUP,
            ];
        }

        foreach ($defaultRows as $dashboard) {
            $uuid = (string) $dashboard->getUuid();
            if (isset($seen[$uuid]) === true) {
                continue;
            }

            $seen[$uuid] = true;
            $result[]    = [
                'dashboard' => $dashboard,
                'source'    => Dashboard::SOURCE_DEFAULT,
            ];
        }

        return $result;
    }//end findVisibleToUser()

    /**
     * Find group-shared dashboards whose group_id is in the supplied list.
     *
     * Helper for {@see DashboardMapper::findVisibleToUser()}. Hits the
     * `(type, group_id)` composite index.
     *
     * @param string[] $groupIds The group IDs to match.
     *
     * @return Dashboard[] The matching group-shared dashboards.
     */
    private function findGroupSharedInGroups(array $groupIds): array
    {
        if (empty($groupIds) === true) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    x: 'group_id',
                    y: $qb->createNamedParameter(
                        value: $groupIds,
                        type: IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findGroupSharedInGroups()

    /**
     * Count group-shared dashboards in a single group.
     *
     * Used by the last-in-group delete guard (REQ-DASH-014).
     *
     * @param string $groupId The group ID.
     *
     * @return int The number of group-shared dashboards in that group.
     */
    public function countByGroup(string $groupId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            );

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false || isset($row['cnt']) === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }//end countByGroup()

    /**
     * Clear default flag on every group-shared dashboard in a group.
     *
     * Issues `UPDATE oc_mydash_dashboards SET is_default = 0 WHERE
     * type = 'group_shared' AND group_id = ? [AND uuid <> ?]`. Used by
     * {@see \OCA\MyDash\Service\DashboardService::setGroupDefault()} as
     * the first half of the transactional flip. REQ-DASH-015.
     *
     * @param string      $groupId    The group ID (real or
     *                                {@see Dashboard::DEFAULT_GROUP_ID}).
     * @param string|null $exceptUuid Optional uuid to leave untouched
     *                                (avoids a no-op write on the row that
     *                                will immediately be set to 1).
     *
     * @return int The number of rows affected.
     */
    public function clearGroupDefaults(
        string $groupId,
        ?string $exceptUuid=null
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_default',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            );

        if ($exceptUuid !== null) {
            $qb->andWhere(
                $qb->expr()->neq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $exceptUuid)
                )
            );
        }

        return $qb->executeStatement();
    }//end clearGroupDefaults()

    /**
     * Set the default flag on a single group-shared dashboard.
     *
     * Issues `UPDATE oc_mydash_dashboards SET is_default = 1 WHERE
     * type = 'group_shared' AND group_id = ? AND uuid = ?`. Returns the
     * row-count affected — `0` when the uuid does not belong to the
     * given group (caller treats as 404). REQ-DASH-015.
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return int The number of rows affected (0 or 1).
     */
    public function setGroupDefaultUuid(
        string $groupId,
        string $uuid
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_default',
                value: $qb->createNamedParameter(
                    value: 1,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $uuid)
                )
            );

        return $qb->executeStatement();
    }//end setGroupDefaultUuid()

    /**
     * Clear default flag on all admin templates.
     *
     * @return void
     */
    public function clearDefaultTemplates(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_default',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            );

        $qb->executeStatement();
    }//end clearDefaultTemplates()
}//end class
