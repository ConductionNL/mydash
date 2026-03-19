<?php

/**
 * Version001004Date20260204150000
 *
 * Migration to add custom_icon column to widget placements.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001004Date20260204150000 extends SimpleMigrationStep
{
    /**
     * Add custom_icon column to widget placements.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure returns an ISchemaWrapper.
     * @param array   $options       The migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - required by SimpleMigrationStep interface
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        // Get the schema wrapper.
        $schema = $schemaClosure();

        $table = $schema->getTable(
            tableName: 'mydash_widget_placements'
        );

        if ($table->hasColumn(name: 'custom_icon') === false) {
            $table->addColumn(
                name: 'custom_icon',
                typeName: Types::TEXT,
                options: [
                    'notnull' => false,
                    'length'  => 2000,
                ]
            );
        }

        return $schema;
    }//end changeSchema()
}//end class
