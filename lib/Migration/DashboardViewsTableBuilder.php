<?php

/**
 * DashboardViewsTableBuilder
 *
 * Builder for the dashboard view-analytics database table schema
 * (REQ-ANLT-001). Creates the `mydash_dashboard_views` table that
 * stores **daily aggregate** view-event counts per dashboard — one row
 * per `(dashboardUuid, viewBucket)` pair. Per-event rows are
 * deliberately not persisted; unique-viewer dedup happens entirely in
 * the cache layer (REQ-ANLT-003) so no per-user-per-event hashes ever
 * reach the database.
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
 * Builder for the dashboard view-analytics database table schema.
 */
class DashboardViewsTableBuilder
{
    /**
     * Create the `mydash_dashboard_views` table (REQ-ANLT-001).
     *
     * Idempotent — early-returns when the table already exists. The
     * composite unique index on `(dashboard_uuid, view_bucket)` is the
     * one-row-per-(dashboard, day) invariant; the secondary
     * `view_bucket` index speeds up date-range scans for the admin
     * top/summary endpoints.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_dashboard_views') === true) {
            return;
        }

        $table = $schema->createTable('mydash_dashboard_views');

        self::addColumns(table: $table);
        self::addIndexes(table: $table);
    }//end create()

    /**
     * Add columns to the `mydash_dashboard_views` table.
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addColumns($table): void
    {
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
                'comment' => 'The dashboard UUID this aggregate row belongs to (REQ-ANLT-001).',
            ]
        );
        $table->addColumn(
            'view_bucket',
            Types::DATE,
            [
                'notnull' => true,
                'comment' => 'Calendar date in UTC; one row per (dashboard, day).',
            ]
        );
        $table->addColumn(
            'view_count',
            Types::INTEGER,
            [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
                'comment'  => 'Total number of view events on this date.',
            ]
        );
        $table->addColumn(
            'unique_viewer_count',
            Types::INTEGER,
            [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
                'comment'  => 'Distinct viewers on this date (cache-deduped, REQ-ANLT-003).',
            ]
        );
    }//end addColumns()

    /**
     * Add indexes to the `mydash_dashboard_views` table.
     *
     * Composite unique index `(dashboard_uuid, view_bucket)` enforces
     * the "one row per dashboard per day" invariant (REQ-ANLT-001).
     * The standalone `view_bucket` index supports the date-range
     * predicates used by the admin top / summary / export endpoints
     * (REQ-ANLT-006..010).
     *
     * @param \Doctrine\DBAL\Schema\Table $table The table instance.
     *
     * @return void
     */
    private static function addIndexes($table): void
    {
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['dashboard_uuid', 'view_bucket'],
            'mydash_anlt_uniq'
        );
        $table->addIndex(
            ['view_bucket'],
            'mydash_anlt_bucket'
        );
    }//end addIndexes()
}//end class
