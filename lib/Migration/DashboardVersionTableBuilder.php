<?php

/**
 * DashboardVersionTableBuilder
 *
 * Builder for the `mydash_dashboard_versions` schema. Created by the
 * `dashboard-versioning` change (REQ-VERS-001..009).
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

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;

/**
 * Builder for the dashboard versions database table schema.
 */
class DashboardVersionTableBuilder
{
    /**
     * Create the mydash_dashboard_versions table.
     *
     * Columns:
     *   - id              BIGINT PK auto-increment
     *   - dashboard_uuid  STRING(36) NOT NULL
     *   - version_number  BIGINT NOT NULL
     *   - snapshot_json   TEXT NOT NULL (MEDIUMTEXT on MySQL via the
     *                                    `OCP\DB\Types::TEXT` mapping)
     *   - created_by      STRING(64) NOT NULL
     *   - created_at      DATETIME NOT NULL
     *   - note            STRING(500) NULL
     *
     * Indexes:
     *   - mydash_dvers_uuid_num : UNIQUE(dashboard_uuid, version_number)
     *   - mydash_dvers_uuid_ts  : (dashboard_uuid, created_at)
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_dashboard_versions') === true) {
            return;
        }

        $table = $schema->createTable(
            'mydash_dashboard_versions'
        );

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
            'version_number',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'snapshot_json',
            Types::TEXT,
            ['notnull' => true]
        );
        $table->addColumn(
            'created_by',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            ['notnull' => true]
        );
        $table->addColumn(
            'note',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 500,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['dashboard_uuid', 'version_number'],
            'mydash_dvers_uuid_num'
        );
        $table->addIndex(
            ['dashboard_uuid', 'created_at'],
            'mydash_dvers_uuid_ts'
        );
    }//end create()
}//end class
