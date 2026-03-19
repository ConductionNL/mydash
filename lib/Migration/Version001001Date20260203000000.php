<?php

/**
 * Version001001Date20260203000000
 *
 * Migration to add custom tiles feature.
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

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001001Date20260203000000 extends SimpleMigrationStep
{
    /**
     * Create the tiles table.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure returns an ISchemaWrapper.
     * @param array   $options       The migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - required by SimpleMigrationStep interface
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) - migration column definitions are verbose by nature
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        // Get the schema wrapper.
        $schema = $schemaClosure();

        // Create mydash_tiles table.
        if ($schema->hasTable(tableName: 'mydash_tiles') === false) {
            $table = $schema->createTable(tableName: 'mydash_tiles');

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
                name: 'user_id',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 64,
                ]
            );
            $table->addColumn(
                name: 'title',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                name: 'icon',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 2000,
                    'comment' => 'Icon class, URL to icon image, or SVG path data.',
                ]
            );
            $table->addColumn(
                name: 'icon_type',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 20,
                    'default' => 'class',
                    'comment' => 'Type of icon: class, url, or emoji.',
                ]
            );
            $table->addColumn(
                name: 'background_color',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 7,
                    'default' => '#0082c9',
                    'comment' => 'Hex color code for tile background.',
                ]
            );
            $table->addColumn(
                name: 'text_color',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 7,
                    'default' => '#ffffff',
                    'comment' => 'Hex color code for text.',
                ]
            );
            $table->addColumn(
                name: 'link_type',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 20,
                    'comment' => 'Type of link: app or url.',
                ]
            );
            $table->addColumn(
                name: 'link_value',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 1000,
                    'comment' => 'App ID or URL.',
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

            $table->setPrimaryKey(columnNames: ['id']);
            $table->addIndex(
                columnNames: ['user_id'],
                indexName: 'mydash_tiles_user'
            );
        }//end if

        return $schema;
    }//end changeSchema()
}//end class
