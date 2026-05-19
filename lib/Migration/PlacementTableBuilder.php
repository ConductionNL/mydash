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
            'mydash_widget_placements'
        ) === true
        ) {
            return;
        }

        $table = $schema->createTable(
            'mydash_widget_placements'
        );

        self::addColumns(table: $table);
        self::addIndexes(table: $table);
    }//end create()

    /**
     * Add columns to the widget placements table.
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addColumns($table): void
    {
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
            'dashboard_id',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'widget_id',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'grid_x',
            Types::INTEGER,
            [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            'grid_y',
            Types::INTEGER,
            [
                'notnull' => true,
                'default' => 0,
            ]
        );
        $table->addColumn(
            'grid_width',
            Types::INTEGER,
            [
                'notnull' => true,
                'default' => 4,
            ]
        );
        $table->addColumn(
            'grid_height',
            Types::INTEGER,
            [
                'notnull' => true,
                'default' => 4,
            ]
        );
        $table->addColumn(
            'is_compulsory',
            Types::SMALLINT,
            [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'is_visible',
            Types::SMALLINT,
            [
                'notnull'  => true,
                'default'  => 1,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'style_config',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'custom_title',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'show_title',
            Types::SMALLINT,
            [
                'notnull'  => true,
                'default'  => 1,
                'unsigned' => true,
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
                'notnull' => true,
            ]
        );
        $table->addColumn(
            'updated_at',
            Types::DATETIME,
            [
                'notnull' => true,
            ]
        );
    }//end addColumns()

    /**
     * Add indexes to the widget placements table.
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addIndexes($table): void
    {
        $table->setPrimaryKey(['id']);
        $table->addIndex(
            ['dashboard_id'],
            'mydash_placement_dashboard'
        );
        $table->addIndex(
            ['widget_id'],
            'mydash_placement_widget'
        );
    }//end addIndexes()
}//end class
