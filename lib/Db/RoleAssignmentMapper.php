<?php

/**
 * RoleAssignmentMapper
 *
 * Database mapper for RoleAssignment entities. Covers the
 * `oc_mydash_role_assignments` table introduced by the admin-roles
 * capability. REQ-ROLE-004.
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
 * Mapper for RoleAssignment entities.
 *
 * @extends QBMapper<RoleAssignment>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The CRUD, lookup, and
 *                                                cascade methods are
 *                                                each cohesive entry
 *                                                points covering the
 *                                                REQ-ROLE-004 / 010 /
 *                                                011 specifications;
 *                                                splitting would obscure
 *                                                the table contract.
 */
class RoleAssignmentMapper extends QBMapper
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
            tableName: 'mydash_role_assignments',
            entityClass: RoleAssignment::class
        );
    }//end __construct()

    /**
     * Find an assignment by its primary key.
     *
     * @param int $id The assignment ID.
     *
     * @return RoleAssignment The assignment.
     *
     * @throws DoesNotExistException When not found.
     */
    public function findById(int $id): RoleAssignment
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
    }//end findById()

    /**
     * Return every assignment, ordered by ID for stable listing.
     *
     * @return RoleAssignment[] Every row in the table.
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->orderBy(sort: 'id', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find all direct user assignments for a single user.
     *
     * Per REQ-ROLE-005 only direct user assignments live here — group
     * assignments are looked up via {@see findByGroupIds} given the user's
     * group memberships.
     *
     * @param string $userId The Nextcloud user ID.
     *
     * @return RoleAssignment[] Zero or more direct user assignments.
     */
    public function findByUser(string $userId): array
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
            ->orderBy(sort: 'id', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByUser()

    /**
     * Find all assignments scoped to a single group.
     *
     * @param string $groupId The Nextcloud group ID.
     *
     * @return RoleAssignment[] Zero or more group assignments.
     */
    public function findByGroup(string $groupId): array
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
            ->orderBy(sort: 'id', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByGroup()

    /**
     * Find all assignments scoped to any of the given group IDs.
     *
     * Used by RoleService when collecting candidate group assignments
     * during effective-role resolution. REQ-ROLE-005.
     *
     * @param string[] $groupIds The Nextcloud group IDs.
     *
     * @return RoleAssignment[] Zero or more matching group assignments.
     */
    public function findByGroupIds(array $groupIds): array
    {
        if (count($groupIds) === 0) {
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
            )
            ->orderBy(sort: 'group_id', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByGroupIds()

    /**
     * Find a duplicate user-role assignment (defensive uniqueness check).
     *
     * @param string $userId The user ID.
     * @param string $role   The role name.
     *
     * @return RoleAssignment|null The match or null when none exists.
     */
    public function findUserRole(string $userId, string $role): ?RoleAssignment
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
                    x: 'role',
                    y: $qb->createNamedParameter(value: $role)
                )
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findUserRole()

    /**
     * Find a duplicate group-role assignment (defensive uniqueness check).
     *
     * @param string $groupId The group ID.
     * @param string $role    The role name.
     *
     * @return RoleAssignment|null The match or null when none exists.
     */
    public function findGroupRole(
        string $groupId,
        string $role
    ): ?RoleAssignment {
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
                    x: 'role',
                    y: $qb->createNamedParameter(value: $role)
                )
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findGroupRole()

    /**
     * Delete all direct user assignments for a single user.
     *
     * Invoked by the user-deletion cascade. REQ-ROLE-010.
     *
     * @param string $userId The user ID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByUserId(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        return $qb->executeStatement();
    }//end deleteByUserId()

    /**
     * Delete all assignments scoped to a single group.
     *
     * Invoked by the group-deletion cascade. REQ-ROLE-011.
     *
     * @param string $groupId The group ID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByGroupId(string $groupId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            );

        return $qb->executeStatement();
    }//end deleteByGroupId()

    /**
     * Delete a single assignment by ID.
     *
     * @param int $id The assignment ID.
     *
     * @return int The number of rows deleted (0 when no row matched).
     */
    public function deleteById(int $id): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $id,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $qb->executeStatement();
    }//end deleteById()
}//end class
