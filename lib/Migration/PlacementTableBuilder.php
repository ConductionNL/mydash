<?php

/**
 * PlacementTableBuilder
 *
 * Builder for the widget placements database table schema.
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
 * Builder for the widget placements database table schema.
 */
class PlacementTableBuilder
{
    /**
     * Create the mydash_widget_placements table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable(
            tableName: 'mydash_widget_placements'
        ) === true
        ) {
            return;
        }

        $table = $schema->createTable(
            tableName: 'mydash_widget_placements'
        );

        self::addColumns(table: $table);
        self::addIndexes(table: $table);
    }//end create()

    /**
     * Add columns to the widget placements table.
     *
     * @param mixed $table The table instance.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) - migration column definitions are verbose by nature
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
            name: 'dashboard_id',
            typeName: Types::BIGINT,
            options: [
                'notnull'  => true,
                'unsigned' => true,
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
            name: 'is_compulsory',
            typeName: Types::SMALLINT,
            options: [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'is_visible',
            typeName: Types::SMALLINT,
            options: [
                'notnull'  => true,
                'default'  => 1,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'style_config',
            typeName: Types::TEXT,
            options: [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            name: 'custom_title',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            name: 'show_title',
            typeName: Types::SMALLINT,
            options: [
                'notnull'  => true,
                'default'  => 1,
                'unsigned' => true,
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
     * Add indexes to the widget placements table.
     *
     * @param mixed $table The table instance.
     *
     * @return void
     */
    private static function addIndexes(mixed $table): void
    {
        $table->setPrimaryKey(columnNames: ['id']);
        $table->addIndex(
            columnNames: ['dashboard_id'],
            indexName: 'mydash_placement_dashboard'
        );
        $table->addIndex(
            columnNames: ['widget_id'],
            indexName: 'mydash_placement_widget'
        );
    }//end addIndexes()
}//end class
