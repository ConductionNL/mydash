<?php

/**
 * Version001003Date20260204120000
 *
 * Migration to add tile configuration fields to widget placements.
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

class Version001003Date20260204120000 extends SimpleMigrationStep
{
    /**
     * Add tile configuration columns to widget_placements table.
     *
     * @param IOutput $output        Migration output handler.
     * @param Closure $schemaClosure The schema closure returns an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema wrapper or null.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - required by SimpleMigrationStep interface
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  - migration safety checks require per-column conditionals
     * @SuppressWarnings(PHPMD.NPathComplexity)       - migration safety checks require per-column conditionals
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) - migration column definitions are verbose by nature
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        // Get the schema wrapper.
        $schema = $schemaClosure();

        // Add tile configuration fields to widget_placements table.
        if ($schema->hasTable(
            tableName: 'mydash_widget_placements'
        ) === true
        ) {
            $table = $schema->getTable(
                tableName: 'mydash_widget_placements'
            );

            // Add tile_type to distinguish between widgets and tiles.
            if ($table->hasColumn(name: 'tile_type') === false) {
                $table->addColumn(
                    name: 'tile_type',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 20,
                        'default' => null,
                        'comment' => 'Type of tile: custom (null for regular widgets).',
                    ]
                );
            }

            // Add tile_title for custom tiles.
            if ($table->hasColumn(name: 'tile_title') === false) {
                $table->addColumn(
                    name: 'tile_title',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Title for custom tiles.',
                    ]
                );
            }

            // Add tile_icon.
            if ($table->hasColumn(name: 'tile_icon') === false) {
                $table->addColumn(
                    name: 'tile_icon',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 2000,
                        'default' => null,
                        'comment' => 'Icon class, URL, emoji, or SVG path data for tiles.',
                    ]
                );
            }

            // Add tile_icon_type.
            if ($table->hasColumn(name: 'tile_icon_type') === false) {
                $table->addColumn(
                    name: 'tile_icon_type',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 20,
                        'default' => null,
                        'comment' => 'Type of icon: class, url, emoji, or svg.',
                    ]
                );
            }

            // Add tile_background_color.
            if ($table->hasColumn(
                name: 'tile_background_color'
            ) === false
            ) {
                $table->addColumn(
                    name: 'tile_background_color',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 7,
                        'default' => null,
                        'comment' => 'Hex color code for tile background.',
                    ]
                );
            }

            // Add tile_text_color.
            if ($table->hasColumn(name: 'tile_text_color') === false) {
                $table->addColumn(
                    name: 'tile_text_color',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 7,
                        'default' => null,
                        'comment' => 'Hex color code for tile text.',
                    ]
                );
            }

            // Add tile_link_type.
            if ($table->hasColumn(name: 'tile_link_type') === false) {
                $table->addColumn(
                    name: 'tile_link_type',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 20,
                        'default' => null,
                        'comment' => 'Type of link: app or url.',
                    ]
                );
            }

            // Add tile_link_value.
            if ($table->hasColumn(name: 'tile_link_value') === false) {
                $table->addColumn(
                    name: 'tile_link_value',
                    typeName: Types::STRING,
                    options: [
                        'notnull' => false,
                        'length'  => 1000,
                        'default' => null,
                        'comment' => 'App ID or URL for tile links.',
                    ]
                );
            }
        }//end if

        return $schema;
    }//end changeSchema()
}//end class
