<?php

/**
 * ConditionalRuleMapper
 *
 * Database mapper for conditional rule entities.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * ConditionalRuleMapper
 *
 * Mapper for conditional rule entities.
 *
 * @extends QBMapper<ConditionalRule>
 */
class ConditionalRuleMapper extends QBMapper
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
            tableName: 'mydash_conditional_rules',
            entityClass: ConditionalRule::class
        );
    }//end __construct()

    /**
     * Find rule by ID.
     *
     * @param int $id The rule ID.
     *
     * @return ConditionalRule The found rule.
     *
     * @throws DoesNotExistException If not found.
     */
    public function find(int $id): ConditionalRule
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
     * Find all rules for a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return ConditionalRule[] The list of rules.
     */
    public function findByPlacementId(int $placementId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'widget_placement_id',
                    y: $qb->createNamedParameter(
                        value: $placementId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByPlacementId()

    /**
     * Delete all rules for a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return void
     */
    public function deleteByPlacementId(int $placementId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'widget_placement_id',
                    y: $qb->createNamedParameter(
                        value: $placementId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        $qb->executeStatement();
    }//end deleteByPlacementId()
}//end class
