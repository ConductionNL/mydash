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
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        // Get the schema wrapper.
        $schema = $schemaClosure();

        // Create mydash_tiles table.
        if ($schema->hasTable('mydash_tiles') === false) {
            $table = $schema->createTable('mydash_tiles');

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
                'user_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
            );
            $table->addColumn(
                'title',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'icon',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 2000,
                    'comment' => 'Icon class, URL to icon image, or SVG path data.',
                ]
            );
            $table->addColumn(
                'icon_type',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 20,
                    'default' => 'class',
                    'comment' => 'Type of icon: class, url, or emoji.',
                ]
            );
            $table->addColumn(
                'background_color',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 7,
                    'default' => '#0082c9',
                    'comment' => 'Hex color code for tile background.',
                ]
            );
            $table->addColumn(
                'text_color',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 7,
                    'default' => '#ffffff',
                    'comment' => 'Hex color code for text.',
                ]
            );
            $table->addColumn(
                'link_type',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 20,
                    'comment' => 'Type of link: app or url.',
                ]
            );
            $table->addColumn(
                'link_value',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 1000,
                    'comment' => 'App ID or URL.',
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

            $table->setPrimaryKey(['id']);
            $table->addIndex(
                ['user_id'],
                'mydash_tiles_user'
            );
        }//end if

        return $schema;
    }//end changeSchema()
}//end class
