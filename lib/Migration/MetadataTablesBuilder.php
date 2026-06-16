<?php

/**
 * MetadataTablesBuilder
 *
 * Builders for the dashboard metadata-fields capability tables
 * (REQ-MDFL-001..008): the global field-definition registry
 * (`mydash_meta_fields`) and the per-dashboard typed value rows
 * (`mydash_meta_values`).
 *
 * Both tables live behind the `dashboard-metadata-fields`
 * capability spec — see `openspec/specs/dashboard-metadata-fields/spec.md`
 * for the canonical column contract and orphan-tolerance rules.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
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

namespace OCA\MyDash\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;

/**
 * Builders for the metadata-field registry and per-dashboard value tables.
 */
class MetadataTablesBuilder
{
    /**
     * Create both metadata tables (idempotent — skip when present).
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        self::createFieldsTable(schema: $schema);
        self::createValuesTable(schema: $schema);
    }//end create()

    /**
     * Create the `mydash_meta_fields` registry table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    private static function createFieldsTable(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_meta_fields') === true) {
            return;
        }

        $table = $schema->createTable('mydash_meta_fields');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            'field_key',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'label',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'type',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 20,
            ]
        );
        $table->addColumn(
            'options',
            Types::TEXT,
            [
                'notnull' => false,
                'comment' => 'JSON array of strings; NULL for non-select types.',
            ]
        );
        $table->addColumn(
            'required',
            Types::SMALLINT,
            [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            'sort_order',
            Types::INTEGER,
            [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'updated_at',
            Types::DATETIME,
            [
                'notnull' => false,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['field_key'],
            'mydash_meta_fkey'
        );
        $table->addIndex(
            ['sort_order'],
            'mydash_meta_forder'
        );
    }//end createFieldsTable()

    /**
     * Create the `mydash_meta_values` per-dashboard value table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    private static function createValuesTable(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_meta_values') === true) {
            return;
        }

        $table = $schema->createTable('mydash_meta_values');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            'dashboard_uuid',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 36,
            ]
        );
        $table->addColumn(
            'field_id',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'value',
            Types::TEXT,
            [
                'notnull' => true,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['dashboard_uuid', 'field_id'],
            'mydash_meta_vunique'
        );
        $table->addIndex(
            ['dashboard_uuid'],
            'mydash_meta_vdash'
        );
        $table->addIndex(
            ['field_id'],
            'mydash_meta_vfield'
        );
    }//end createValuesTable()
}//end class
