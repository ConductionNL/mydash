<?php

/**
 * Version001013Date20260502120000
 *
 * Migration that adds the nullable `comments_enabled` SMALLINT column to
 * `oc_mydash_dashboards` for the dashboard-comments capability. NULL
 * inherits the global `mydash.comments_enabled_default` setting; 1 forces
 * comments on for the dashboard; 0 forces comments off. Zero-impact:
 * existing dashboards default to NULL and inherit the global setting.
 * REQ-CMNT-007.
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
 * Add the nullable `comments_enabled` column to `oc_mydash_dashboards`
 * (REQ-CMNT-007).
 */
class Version001013Date20260502120000 extends SimpleMigrationStep
{
    /**
     * Add the column when it does not yet exist.
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

        if ($schema->hasTable('mydash_dashboards') === false) {
            return $schema;
        }

        $table = $schema->getTable('mydash_dashboards');

        if ($table->hasColumn('comments_enabled') === false) {
            $table->addColumn(
                'comments_enabled',
                Types::SMALLINT,
                [
                    'notnull'  => false,
                    'default'  => null,
                    'unsigned' => true,
                    'comment'  => 'Per-dashboard comments toggle: NULL = inherit global, 1 = on, 0 = off.',
                ]
            );
        }

        return $schema;
    }//end changeSchema()
}//end class
