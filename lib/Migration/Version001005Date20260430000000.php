<?php

/**
 * Version001005Date20260430000000
 *
 * Migration adding the group_id column and (type, group_id) composite index
 * to mydash_dashboards in support of REQ-DASH-011..014 (multi-scope
 * dashboards: group_shared scope plus default-group sentinel).
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

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration adding the group_id column and a (type, group_id) composite
 * index on the dashboards table.
 */
class Version001005Date20260430000000 extends SimpleMigrationStep
{
    /**
     * Add group_id column + composite index to mydash_dashboards.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure (returns ISchemaWrapper).
     * @param array   $options       The migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null.
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'mydash_dashboards') === false) {
            return $schema;
        }

        $table = $schema->getTable(tableName: 'mydash_dashboards');

        if ($table->hasColumn(name: 'group_id') === false) {
            $table->addColumn(
                name: 'group_id',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 64,
                ]
            );
        }

        if ($table->hasIndex(indexName: 'mydash_dash_type_group') === false) {
            $table->addIndex(
                columnNames: ['type', 'group_id'],
                indexName: 'mydash_dash_type_group'
            );
        }

        return $schema;
    }//end changeSchema()
}//end class
