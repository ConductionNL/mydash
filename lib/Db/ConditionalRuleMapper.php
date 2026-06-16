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

    /**
     * Count conditional-rule rows whose `widget_placement_id` no
     * longer points at any row in `mydash_widget_placements`.
     *
     * Used by the orphaned-data-cleanup scan path (REQ-CLN-001).
     * Rules are normally cleared by the placement removal flow; a row
     * left behind here typically indicates a manual SQL delete or an
     * older code path that didn't cascade.
     *
     * @return int The number of orphaned rule rows.
     */
    public function countOrphaned(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('r.id'))
            ->from(from: $this->getTableName(), alias: 'r')
            ->leftJoin(
                fromAlias: 'r',
                join: 'mydash_widget_placements',
                alias: 'p',
                condition: 'p.id = r.widget_placement_id'
            )
            ->where($qb->expr()->isNull(x: 'p.id'));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) ($count ?? 0);
    }//end countOrphaned()

    /**
     * Delete conditional-rule rows whose `widget_placement_id` no
     * longer points at any row in `mydash_widget_placements`.
     *
     * Companion to {@see self::countOrphaned()} on the purge path
     * (REQ-CLN-002). Resolves the orphan IDs first via a SELECT and
     * then deletes by primary key for portability across drivers.
     *
     * @return int The number of rows deleted.
     */
    public function deleteOrphaned(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: 'r.id')
            ->from(from: $this->getTableName(), alias: 'r')
            ->leftJoin(
                fromAlias: 'r',
                join: 'mydash_widget_placements',
                alias: 'p',
                condition: 'p.id = r.widget_placement_id'
            )
            ->where($qb->expr()->isNull(x: 'p.id'));

        $result = $qb->executeQuery();
        $ids    = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (int) $row['id'];
        }

        $result->closeCursor();

        if (count(value: $ids) === 0) {
            return 0;
        }

        $delete = $this->db->getQueryBuilder();
        $delete->delete(delete: $this->getTableName())
            ->where(
                $delete->expr()->in(
                    x: 'id',
                    y: $delete->createNamedParameter(
                        value: $ids,
                        type: IQueryBuilder::PARAM_INT_ARRAY
                    )
                )
            );

        return $delete->executeStatement();
    }//end deleteOrphaned()
}//end class
