<?php

/**
 * RoleLayoutDefaultTableBuilder
 *
 * Builder for the `mydash_role_layout_defaults` schema (REQ-RFP-002).
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
 * Schema builder for the role-layout-defaults table.
 */
class RoleLayoutDefaultTableBuilder
{
    /**
     * Create the table when missing.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable(tableName: 'mydash_role_layout_defaults') === true) {
            return;
        }

        $table = $schema->createTable(tableName: 'mydash_role_layout_defaults');

        self::addColumns(table: $table);
        self::addIndexes(table: $table);
    }//end create()

    /**
     * Add columns to the table.
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addColumns($table): void
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
            name: 'group_id',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            name: 'widget_id',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            name: 'grid_x',
            typeName: Types::INTEGER,
            options: [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            name: 'grid_y',
            typeName: Types::INTEGER,
            options: [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            name: 'grid_width',
            typeName: Types::INTEGER,
            options: [
                'notnull' => true,
                'default' => 4,
            ]
        );
        $table->addColumn(
            name: 'grid_height',
            typeName: Types::INTEGER,
            options: [
                'notnull' => true,
                'default' => 4,
            ]
        );
        $table->addColumn(
            name: 'sort_order',
            typeName: Types::INTEGER,
            options: [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            name: 'is_compulsory',
            typeName: Types::SMALLINT,
            options: [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            name: 'created_at',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 32,
            ]
        );
        $table->addColumn(
            name: 'updated_at',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 32,
            ]
        );
    }//end addColumns()

    /**
     * Add primary key + uniqueness index on (group_id, widget_id).
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addIndexes($table): void
    {
        $table->setPrimaryKey(columnNames: ['id']);
        $table->addUniqueIndex(
            columnNames: ['group_id', 'widget_id'],
            indexName: 'mydash_rld_group_widget'
        );
        $table->addIndex(
            columnNames: ['group_id'],
            indexName: 'mydash_rld_group'
        );
    }//end addIndexes()
}//end class
