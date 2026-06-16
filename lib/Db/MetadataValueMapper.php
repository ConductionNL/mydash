<?php

/**
 * MetadataValueMapper
 *
 * Database mapper for the per-dashboard typed value rows
 * (`oc_mydash_meta_values`). Owns CRUD plus the upsert helper that
 * collapses insert / update into a single call against the unique
 * (`dashboard_uuid`, `field_id`) pair (REQ-MDFL-005).
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
 * Mapper for the metadata-value table.
 *
 * @extends QBMapper<MetadataValue>
 */
class MetadataValueMapper extends QBMapper
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
            tableName: 'mydash_meta_values',
            entityClass: MetadataValue::class
        );
    }//end __construct()

    /**
     * Find every value row for the given dashboard.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return MetadataValue[] The matching rows.
     */
    public function findByDashboard(string $dashboardUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByDashboard()

    /**
     * Find the single value row for the (dashboardUuid, fieldId) pair.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param int    $fieldId       The field id.
     *
     * @return MetadataValue|null The value row, or null when missing.
     */
    public function findOne(string $dashboardUuid, int $fieldId): ?MetadataValue
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq(
                        x: 'dashboard_uuid',
                        y: $qb->createNamedParameter(value: $dashboardUuid)
                    ),
                    $qb->expr()->eq(
                        x: 'field_id',
                        y: $qb->createNamedParameter(
                            value: $fieldId,
                            type: IQueryBuilder::PARAM_INT
                        )
                    )
                )
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findOne()

    /**
     * Insert or update the value row for the (dashboardUuid, fieldId) pair.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param int    $fieldId       The field id.
     * @param string $value         The encoded value.
     *
     * @return MetadataValue The persisted entity.
     */
    public function upsert(
        string $dashboardUuid,
        int $fieldId,
        string $value
    ): MetadataValue {
        $existing = $this->findOne(
            dashboardUuid: $dashboardUuid,
            fieldId: $fieldId
        );

        if ($existing !== null) {
            // Entity __call routes setter args via $args[0]; named params
            // would land in the wrong slot.
            // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $existing->setValue($value);
            return $this->update(entity: $existing);
        }

        $row = new MetadataValue();
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $row->setDashboardUuid($dashboardUuid);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $row->setFieldId($fieldId);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $row->setValue($value);

        return $this->insert(entity: $row);
    }//end upsert()

    /**
     * Delete every value row for the given dashboard (used when a
     * dashboard itself is deleted upstream).
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows removed.
     */
    public function deleteByDashboard(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        return $qb->executeStatement();
    }//end deleteByDashboard()

    /**
     * Find every dashboard UUID with a value for the given field id.
     * Used by the metadata-filter query path (REQ-MDFL-007).
     *
     * @param int $fieldId The field id.
     *
     * @return MetadataValue[] The matching rows.
     */
    public function findByField(int $fieldId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'field_id',
                    y: $qb->createNamedParameter(
                        value: $fieldId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByField()
}//end class
