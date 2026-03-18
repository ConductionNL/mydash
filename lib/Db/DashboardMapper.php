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
            $db,
            'mydash_dashboards',
            Dashboard::class
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
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter(
                        $id,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity($qb);
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
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'uuid',
                    $qb->createNamedParameter($uuid)
                )
            );

        return $this->findEntity($qb);
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
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'user_id',
                    $qb->createNamedParameter($userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'type',
                    $qb->createNamedParameter(
                        Dashboard::TYPE_USER
                    )
                )
            )
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
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
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'user_id',
                    $qb->createNamedParameter($userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'type',
                    $qb->createNamedParameter(
                        Dashboard::TYPE_USER
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'is_active',
                    $qb->createNamedParameter(
                        1,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity($qb);
    }//end findActiveByUserId()

    /**
     * Find all admin templates.
     *
     * @return Dashboard[] The list of admin templates.
     */
    public function findAdminTemplates(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'type',
                    $qb->createNamedParameter(
                        Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            )
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
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
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'type',
                    $qb->createNamedParameter(
                        Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'is_default',
                    $qb->createNamedParameter(
                        1,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity($qb);
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
        $qb->update($this->getTableName())
            ->set(
                'is_active',
                $qb->createNamedParameter(
                    0,
                    IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                'updated_at',
                $qb->createNamedParameter(
                    (new DateTime())->format('Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    'user_id',
                    $qb->createNamedParameter($userId)
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
        $this->deactivateAllForUser($userId);

        // Then activate the specified dashboard.
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set(
                'is_active',
                $qb->createNamedParameter(
                    1,
                    IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                'updated_at',
                $qb->createNamedParameter(
                    (new DateTime())->format('Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter(
                        $dashboardId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'user_id',
                    $qb->createNamedParameter($userId)
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
        $qb->update($this->getTableName())
            ->set(
                'is_default',
                $qb->createNamedParameter(
                    0,
                    IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                'updated_at',
                $qb->createNamedParameter(
                    (new DateTime())->format('Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    'type',
                    $qb->createNamedParameter(
                        Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            );

        $qb->executeStatement();
    }//end clearDefaultTemplates()
}//end class
