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
        if ($schema->hasTable(tableName: 'mydash_dashboard_shares') === true) {
            return;
        }

        $table = $schema->createTable(tableName: 'mydash_dashboard_shares');

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
            name: 'dashboard_id',
            typeName: Types::BIGINT,
            options: [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'share_type',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 8,
            ]
        );
        $table->addColumn(
            name: 'share_with',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            name: 'permission_level',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 16,
                'default' => 'view_only',
            ]
        );
        $table->addColumn(
            name: 'created_at',
            typeName: Types::DATETIME,
            options: ['notnull' => true]
        );
        $table->addColumn(
            name: 'updated_at',
            typeName: Types::DATETIME,
            options: ['notnull' => false]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addIndex(
            columnNames: ['dashboard_id'],
            indexName: 'mydash_share_dash_idx'
        );
        $table->addIndex(
            columnNames: ['share_type', 'share_with'],
            indexName: 'mydash_share_recip_idx'
        );
        $table->addUniqueIndex(
            columnNames: ['dashboard_id', 'share_type', 'share_with'],
            indexName: 'mydash_share_unique_idx'
        );
    }//end create()
}//end class
