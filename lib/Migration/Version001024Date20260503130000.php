<?php

/**
 * Version001024Date20260503130000
 *
 * Migration that adds the per-dashboard footer override columns
 * (`dashboard_footer_mode`, `dashboard_footer_html`) to
 * `oc_mydash_dashboards`. Required by REQ-FTR-006 (per-dashboard
 * footer override) and the supporting `footer-customization`
 * capability spec.
 *
 * Zero-impact: every column is nullable or has a string default and
 * no backfill is required. Existing rows materialise as
 * `dashboard_footer_mode = 'inherit'` (the column default) with
 * `dashboard_footer_html = NULL`, so all pre-existing dashboards
 * continue to inherit whatever the global footer resolves to.
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add footer-override columns to mydash_dashboards (REQ-FTR-006).
 */
class Version001024Date20260503130000 extends SimpleMigrationStep
{
    /**
     * Add the dashboard_footer_mode / dashboard_footer_html columns.
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

        if ($schema->hasTable('mydash_dashboards') === false) {
            return $schema;
        }

        $table = $schema->getTable('mydash_dashboards');

        DashboardTableBuilder::addFooterColumns(table: $table);

        return $schema;
    }//end changeSchema()
}//end class
