<?php

/**
 * DashboardTranslationTableBuilder
 *
 * Builder for the oc_mydash_dash_translations database table schema
 * — per-language content variants for dashboards. REQ-DASH-038.
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
 * Builder for the dashboard_translations database table schema.
 */
class DashboardTranslationTableBuilder
{
    /**
     * Create the mydash_dash_translations table.
     *
     * Idempotent — returns immediately when the table already exists.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_dash_translations') === true) {
            return;
        }

        $table = $schema->createTable(
            'mydash_dash_translations'
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
            'language_code',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 16,
                'comment' => 'ISO 639-1 base code (nl, en, de, fr); normalised by mapper.',
            ]
        );
        $table->addColumn(
            'name',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'description',
            Types::TEXT,
            ['notnull' => false]
        );
        $table->addColumn(
            'widget_tree_json',
            Types::TEXT,
            [
                'notnull' => false,
                'comment' => 'Localised widget tree JSON — mirrors oc_mydash_dashboards shape.',
            ]
        );
        $table->addColumn(
            'is_primary',
            Types::SMALLINT,
            [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
                'comment'  => 'Exactly one row per dashboard MUST have is_primary=1 (service-enforced).',
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
            ['dashboard_uuid'],
            'mydash_trans_dash_idx'
        );
        $table->addUniqueIndex(
            ['dashboard_uuid', 'language_code'],
            'mydash_trans_unique_idx'
        );
        $table->addIndex(
            ['dashboard_uuid', 'is_primary'],
            'mydash_trans_primary_idx'
        );
    }//end create()
}//end class
