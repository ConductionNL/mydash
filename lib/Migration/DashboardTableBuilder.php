<?php

/**
 * DashboardTableBuilder
 *
 * Builder for the dashboards database table schema.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
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

namespace OCA\MyDash\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;

/**
 * Builder for the dashboards database table schema.
 */
class DashboardTableBuilder
{
    /**
     * Create the mydash_dashboards table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable(tableName: 'mydash_dashboards') === true) {
            return;
        }

        $table = $schema->createTable(tableName: 'mydash_dashboards');

        self::addColumns(table: $table);
        self::addIndexes(table: $table);
    }//end create()

    /**
     * Add columns to the dashboards table.
     *
     * @param mixed $table The table instance.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private static function addColumns(mixed $table): void
    {
        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            name: 'uuid',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 36,
            ]
        );
        $table->addColumn(
            name: 'name',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            name: 'description',
            typeName: Types::TEXT,
            options: [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            name: 'type',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 20,
                'default' => 'user',
            ]
        );
        $table->addColumn(
            name: 'user_id',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            name: 'based_on_template',
            typeName: Types::BIGINT,
            options: [
                'notnull'  => false,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'grid_columns',
            typeName: Types::INTEGER,
            options: [
                'notnull' => true,
                'default' => 12,
            ]
        );
        $table->addColumn(
            name: 'permission_level',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 20,
                'default' => 'full',
            ]
        );
        $table->addColumn(
            name: 'target_groups',
            typeName: Types::TEXT,
            options: [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            name: 'is_default',
            typeName: Types::SMALLINT,
            options: [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'is_active',
            typeName: Types::SMALLINT,
            options: [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'created_at',
            typeName: Types::DATETIME,
            options: [
                'notnull' => true,
            ]
        );
        $table->addColumn(
            name: 'updated_at',
            typeName: Types::DATETIME,
            options: [
                'notnull' => true,
            ]
        );
    }//end addColumns()

    /**
     * Add indexes to the dashboards table.
     *
     * @param mixed $table The table instance.
     *
     * @return void
     */
    private static function addIndexes(mixed $table): void
    {
        $table->setPrimaryKey(columnNames: ['id']);
        $table->addUniqueIndex(
            columnNames: ['uuid'],
            indexName: 'mydash_dashboard_uuid'
        );
        $table->addIndex(
            columnNames: ['user_id'],
            indexName: 'mydash_dashboard_user'
        );
        $table->addIndex(
            columnNames: ['type'],
            indexName: 'mydash_dashboard_type'
        );
        $table->addIndex(
            columnNames: ['user_id', 'is_active'],
            indexName: 'mydash_dashboard_active'
        );
    }//end addIndexes()
}//end class
