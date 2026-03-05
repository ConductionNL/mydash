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
