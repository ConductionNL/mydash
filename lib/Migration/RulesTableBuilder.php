<?php

/**
 * RulesTableBuilder
 *
 * Builder for the conditional rules database table schema.
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
 * Builder for the conditional rules database table schema.
 */
class RulesTableBuilder
{
    /**
     * Create the mydash_conditional_rules table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable(
            'mydash_conditional_rules'
        ) === true
        ) {
            return;
        }

        $table = $schema->createTable(
            'mydash_conditional_rules'
        );

        self::addColumns(table: $table);
        self::addIndexes(table: $table);
    }//end create()

    /**
     * Add columns to the conditional rules table.
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
            'widget_placement_id',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'rule_type',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 50,
            ]
        );
        $table->addColumn(
            'rule_config',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'is_include',
            Types::SMALLINT,
            [
                'notnull'  => true,
                'default'  => 1,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            [
                'notnull' => true,
            ]
        );
    }//end addColumns()

    /**
     * Add indexes to the conditional rules table.
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addIndexes($table): void
    {
        $table->setPrimaryKey(['id']);
        $table->addIndex(
            ['widget_placement_id'],
            'mydash_rule_placement'
        );
    }//end addIndexes()
}//end class
