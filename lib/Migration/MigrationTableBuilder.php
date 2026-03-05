<?php

/**
 * MigrationTableBuilder
 *
 * Facade for building all database table schemas in migrations.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Migration;

use OCP\DB\ISchemaWrapper;

/**
 * Facade for building all database table schemas in migrations.
 */
class MigrationTableBuilder
{
    /**
     * Create the mydash_dashboards table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function createDashboardsTable(
        ISchemaWrapper $schema
    ): void {
        DashboardTableBuilder::create(schema: $schema);
    }//end createDashboardsTable()

    /**
     * Create the mydash_widget_placements table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function createWidgetPlacementsTable(
        ISchemaWrapper $schema
    ): void {
        PlacementTableBuilder::create(schema: $schema);
    }//end createWidgetPlacementsTable()

    /**
     * Create the mydash_admin_settings table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function createAdminSettingsTable(
        ISchemaWrapper $schema
    ): void {
        SettingsTableBuilder::create(schema: $schema);
    }//end createAdminSettingsTable()

    /**
     * Create the mydash_conditional_rules table.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function createConditionalRulesTable(
        ISchemaWrapper $schema
    ): void {
        RulesTableBuilder::create(schema: $schema);
    }//end createConditionalRulesTable()
}//end class
