<?php

/**
 * Version001006Date20260502000000
 *
 * Migration to add the `icon` column on `mydash_dashboards` for the
 * `dashboard-icons` capability. The column is an opaque string — see
 * `lib/Db/Dashboard.php::$icon` for the legal value classes (NULL or
 * empty / registry key / URL).
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

class Version001006Date20260502000000 extends SimpleMigrationStep
{
    /**
     * Add the `icon` column to `mydash_dashboards`.
     *
     * Length 2000 mirrors `mydash_tiles.icon` so that an uploaded icon
     * URL added by the sibling `custom-icon-upload-pattern` capability
     * fits without a follow-up migration.
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
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'mydash_dashboards') === false) {
            return null;
        }

        $table = $schema->getTable(tableName: 'mydash_dashboards');
        if ($table->hasColumn(name: 'icon') === false) {
            $table->addColumn(
                name: 'icon',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 2000,
                    'comment' => 'Dashboard icon: registry key (dashboard-icons) or upload URL; NULL = default.',
                ]
            );
        }

        return $schema;
    }//end changeSchema()
}//end class
