<?php

/**
 * Version001014Date20260502120000
 *
 * Migration that creates the `oc_mydash_dashboard_reactions` table and
 * adds the `reactions_enabled` SMALLINT column to the existing
 * `oc_mydash_dashboards` table. Required by REQ-RXN-001..009.
 *
 * Zero-impact: the new column is nullable (NULL = follow global
 * setting), the new table starts empty, and the indexes only speed up
 * the new lookup paths added by the dashboard-reactions capability.
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
 * Add `mydash_dashboard_reactions` table + per-dashboard
 * `reactions_enabled` toggle column. REQ-RXN-001..009.
 */
class Version001014Date20260502120000 extends SimpleMigrationStep
{
    /**
     * Create the reactions table and add the per-dashboard toggle column.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure returns an
     *                               ISchemaWrapper.
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

        // 1. Create `mydash_dashboard_reactions` table.
        if ($schema->hasTable('mydash_dashboard_reactions') === false) {
            $table = $schema->createTable('mydash_dashboard_reactions');

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
                'dashboard_uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
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
                'emoji',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 32,
                ]
            );
            $table->addColumn(
                'reacted_at',
                Types::DATETIME,
                ['notnull' => true]
            );

            $table->setPrimaryKey(['id']);

            // Composite uniqueness — REQ-RXN-001 idempotency guarantee.
            $table->addUniqueIndex(
                ['dashboard_uuid', 'user_id', 'emoji'],
                'mydash_react_uuid_user_emoji'
            );

            // Aggregation lookups — REQ-RXN-003 / REQ-RXN-004.
            $table->addIndex(
                ['dashboard_uuid'],
                'mydash_react_uuid'
            );
            $table->addIndex(
                ['emoji'],
                'mydash_react_emoji'
            );
        }//end if

        // 2. Per-dashboard toggle column on the existing dashboards table.
        if ($schema->hasTable('mydash_dashboards') === true) {
            $dashTable = $schema->getTable('mydash_dashboards');
            if ($dashTable->hasColumn('reactions_enabled') === false) {
                $dashTable->addColumn(
                    'reactions_enabled',
                    Types::SMALLINT,
                    ['notnull' => false]
                );
            }
        }

        return $schema;
    }//end changeSchema()
}//end class
