<?php

/**
 * QueryHelper
 *
 * Utility for building common query conditions.
 *
 * @category  Db
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Utility for building common query conditions.
 */
class QueryHelper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }//end __construct()

    /**
     * Create a select-all query builder for a table.
     *
     * @param string $tableName The table name.
     *
     * @return IQueryBuilder The query builder.
     */
    public function selectAll(string $tableName): IQueryBuilder
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $tableName);

        return $qb;
    }//end selectAll()

    /**
     * Add an integer equality condition.
     *
     * @param IQueryBuilder $qb     The query builder.
     * @param string        $column The column name.
     * @param int           $value  The integer value.
     *
     * @return IQueryBuilder The query builder.
     */
    public function whereIntEquals(
        IQueryBuilder $qb,
        string $column,
        int $value
    ): IQueryBuilder {
        $qb->andWhere(
            $qb->expr()->eq(
                x: $column,
                y: $qb->createNamedParameter(
                    value: $value,
                    type: IQueryBuilder::PARAM_INT
                )
            )
        );

        return $qb;
    }//end whereIntEquals()

    /**
     * Add a string equality condition.
     *
     * @param IQueryBuilder $qb     The query builder.
     * @param string        $column The column name.
     * @param string        $value  The string value.
     *
     * @return IQueryBuilder The query builder.
     */
    public function whereStringEquals(
        IQueryBuilder $qb,
        string $column,
        string $value
    ): IQueryBuilder {
        $qb->andWhere(
            $qb->expr()->eq(
                x: $column,
                y: $qb->createNamedParameter(value: $value)
            )
        );

        return $qb;
    }//end whereStringEquals()

    /**
     * Add an order-by clause.
     *
     * @param IQueryBuilder $qb     The query builder.
     * @param string        $column The column name.
     * @param string        $order  The sort direction (ASC or DESC).
     *
     * @return IQueryBuilder The query builder.
     */
    public function orderBy(
        IQueryBuilder $qb,
        string $column,
        string $order='ASC'
    ): IQueryBuilder {
        $qb->orderBy(sort: $column, order: $order);

        return $qb;
    }//end orderBy()
}//end class
