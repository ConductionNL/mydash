<?php

/**
 * MetadataFieldMapper
 *
 * Database mapper for the dashboard-metadata-fields global registry
 * table (`oc_mydash_meta_fields`). Owns CRUD plus the cascade-delete
 * helper that wipes any matching `oc_mydash_meta_values` rows in the
 * same transaction.
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
 * Mapper for the metadata-field-definition table.
 *
 * @extends QBMapper<MetadataField>
 */
class MetadataFieldMapper extends QBMapper
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
            tableName: 'mydash_meta_fields',
            entityClass: MetadataField::class
        );
    }//end __construct()

    /**
     * Return all fields ordered by `sort_order` ascending, then by id.
     *
     * @return MetadataField[] The full registry.
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->orderBy(sort: 'sort_order', order: 'ASC')
            ->addOrderBy(sort: 'id', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find a field by primary key.
     *
     * @param int $id The field id.
     *
     * @return MetadataField The found field.
     *
     * @throws DoesNotExistException When the row is missing.
     */
    public function findById(int $id): MetadataField
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
     * Find a field by its unique machine-name slug.
     *
     * @param string $key The field slug.
     *
     * @return MetadataField The found field.
     *
     * @throws DoesNotExistException When the row is missing.
     */
    public function findByKey(string $key): MetadataField
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'field_key',
                    y: $qb->createNamedParameter(value: $key)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByKey()

    /**
     * Bulk-load fields by id (used by the read-side JOIN replacement).
     *
     * @param int[] $ids The field ids.
     *
     * @return array<int, MetadataField> Indexed by field id.
     */
    public function findByIds(array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->in(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $ids,
                        type: IQueryBuilder::PARAM_INT_ARRAY
                    )
                )
            );

        $entities = $this->findEntities(query: $qb);
        $byId     = [];
        foreach ($entities as $entity) {
            $byId[(int) $entity->getId()] = $entity;
        }

        return $byId;
    }//end findByIds()

    /**
     * Count how many value rows reference the given field id.
     *
     * Used by the soft/cascade delete gate: when the field has live
     * values the caller MUST supply `?cascade=true` to confirm.
     *
     * @param int $fieldId The field id.
     *
     * @return int The number of dependent value rows.
     */
    public function countValuesForField(int $fieldId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(from: 'mydash_meta_values')
            ->where(
                $qb->expr()->eq(
                    x: 'field_id',
                    y: $qb->createNamedParameter(
                        value: $fieldId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (is_array($row) === false || array_key_exists(key: 'cnt', array: $row) === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }//end countValuesForField()

    /**
     * Delete a field definition and cascade-delete every value row
     * that references it. Returns true even when the field had no
     * dependent rows (the caller already confirmed deletion).
     *
     * @param int $fieldId The field id.
     *
     * @return bool True on success.
     */
    public function deleteWithCascade(int $fieldId): bool
    {
        // Cascade values first so an interrupted run never leaves a
        // gap between definition and dependents.
        $valueDelete = $this->db->getQueryBuilder();
        $valueDelete->delete(delete: 'mydash_meta_values')
            ->where(
                $valueDelete->expr()->eq(
                    x: 'field_id',
                    y: $valueDelete->createNamedParameter(
                        value: $fieldId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );
        $valueDelete->executeStatement();

        $fieldDelete = $this->db->getQueryBuilder();
        $fieldDelete->delete(delete: $this->getTableName())
            ->where(
                $fieldDelete->expr()->eq(
                    x: 'id',
                    y: $fieldDelete->createNamedParameter(
                        value: $fieldId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );
        $fieldDelete->executeStatement();

        return true;
    }//end deleteWithCascade()
}//end class
