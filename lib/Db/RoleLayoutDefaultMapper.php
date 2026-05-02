<?php

/**
 * RoleLayoutDefaultMapper
 *
 * QBMapper for RoleLayoutDefault entities. Provides ordered lookups by
 * group ID used during dashboard seeding (REQ-RFP-002).
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
 * Mapper for `mydash_role_layout_defaults`.
 *
 * @extends QBMapper<RoleLayoutDefault>
 */
class RoleLayoutDefaultMapper extends QBMapper
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
            tableName: 'mydash_role_layout_defaults',
            entityClass: RoleLayoutDefault::class
        );
    }//end __construct()

    /**
     * List all default layout rows ordered by group then sort_order.
     *
     * @return RoleLayoutDefault[] All rows.
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->orderBy(sort: 'group_id', order: 'ASC')
            ->addOrderBy(sort: 'sort_order', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find all default-layout rows for a single group, ordered by `sort_order`.
     * Used by `RoleFeaturePermissionService::seedLayoutFromRoleDefaults()`.
     *
     * @param string $groupId The Nextcloud group ID.
     *
     * @return RoleLayoutDefault[] Ordered rows; empty array when nothing seeded.
     */
    public function findByGroupId(string $groupId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            )
            ->orderBy(sort: 'sort_order', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByGroupId()

    /**
     * Find an existing row by `(group_id, widget_id)` — used to enforce
     * upsert behaviour when an admin re-saves the same default.
     *
     * @param string $groupId  The Nextcloud group ID.
     * @param string $widgetId The Nextcloud Dashboard widget ID.
     *
     * @return RoleLayoutDefault The matching row.
     *
     * @throws DoesNotExistException When no row exists for the combination.
     */
    public function findByGroupAndWidget(string $groupId, string $widgetId): RoleLayoutDefault
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'widget_id',
                    y: $qb->createNamedParameter(value: $widgetId)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByGroupAndWidget()
}//end class
