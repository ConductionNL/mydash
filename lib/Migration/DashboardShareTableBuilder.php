<?php

/**
 * DashboardShareTableBuilder
 *
 * Builder for the dashboard_shares database table schema.
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
 * Builder for the dashboard_shares database table schema.
 */
class DashboardShareTableBuilder
{
    /**
     * Create the mydash_dashboard_shares table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_dashboard_shares') === true) {
            return;
        }

        $table = $schema->createTable('mydash_dashboard_shares');

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
            'dashboard_id',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'share_type',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 8,
            ]
        );
        $table->addColumn(
            'share_with',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'permission_level',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 16,
                'default' => 'view_only',
            ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            ['notnull' => true]
        );
        $table->addColumn(
            'updated_at',
            Types::DATETIME,
            ['notnull' => false]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(
            ['dashboard_id'],
            'mydash_share_dash_idx'
        );
        $table->addIndex(
            ['share_type', 'share_with'],
            'mydash_share_recip_idx'
        );
        $table->addUniqueIndex(
            ['dashboard_id', 'share_type', 'share_with'],
            'mydash_share_unique_idx'
        );
    }//end create()
}//end class
