<?php

/**
 * Version001000Date20240101000000
 *
 * Initial migration to create all MyDash database tables.
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

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial migration to create all MyDash database tables.
 */
class Version001000Date20240101000000 extends SimpleMigrationStep
{
    /**
     * Create the initial database schema.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure.
     * @param array   $options       The migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - required by SimpleMigrationStep interface
     * @SuppressWarnings(PHPMD.StaticAccess)          - delegates to static MigrationTableBuilder by design
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        // Get the schema wrapper.
        $schema = $schemaClosure();

        MigrationTableBuilder::createDashboardsTable(schema: $schema);
        MigrationTableBuilder::createWidgetPlacementsTable(
            schema: $schema
        );
        MigrationTableBuilder::createAdminSettingsTable(schema: $schema);
        MigrationTableBuilder::createConditionalRulesTable(
            schema: $schema
        );

        return $schema;
    }//end changeSchema()
}//end class
