<?php

/**
 * RoleFeaturePermissionMapper
 *
 * QBMapper for RoleFeaturePermission entities. Provides lookups by group
 * ID and by group ID list (used by the multi-group resolution algorithm).
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
 * Mapper for `mydash_role_feature_perms`.
 *
 * @extends QBMapper<RoleFeaturePermission>
 */
class RoleFeaturePermissionMapper extends QBMapper
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
            tableName: 'mydash_role_feature_perms',
            entityClass: RoleFeaturePermission::class
        );
    }//end __construct()

    /**
     * Find all RoleFeaturePermission rows.
     *
     * @return RoleFeaturePermission[] All rows ordered by group_id.
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->orderBy(sort: 'group_id', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find a permission row by group ID.
     *
     * @param string $groupId The Nextcloud group ID.
     *
     * @return RoleFeaturePermission The matching row.
     *
     * @throws DoesNotExistException When no row exists for the given group.
     */
    public function findByGroupId(string $groupId): RoleFeaturePermission
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByGroupId()

    /**
     * Find all permission rows whose group_id is in the supplied list. The
     * caller is responsible for ordering by `group_order` priority — this
     * mapper just returns whatever the storage engine supplies.
     *
     * @param array $groupIds The list of Nextcloud group IDs to match.
     *
     * @return RoleFeaturePermission[] Zero or more matching rows.
     */
    public function findByGroupIds(array $groupIds): array
    {
        if (count(value: $groupIds) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->in(
                    x: 'group_id',
                    y: $qb->createNamedParameter(
                        value: $groupIds,
                        type: IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByGroupIds()
}//end class
