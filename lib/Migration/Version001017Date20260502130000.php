<?php

/**
 * Version001017Date20260502130000
 *
 * Migration that adds the `oc_mydash_dash_translations` table
 * holding per-language content variants (widget tree, name, description)
 * for dashboards. REQ-DASH-038..044 (dashboard-language-content).
 *
 * The migration is purely additive — no columns on the existing
 * `oc_mydash_dashboards` table change. Backfill of primary translation
 * rows from existing dashboards is performed lazily by the service
 * layer on first read instead of at migration time, to keep the
 * schema-change path fast and avoid long-running UPDATEs on large
 * installations (REQ-DASH-044, design D2).
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
 * Add the per-language dashboard translations table (REQ-DASH-038..044).
 */
class Version001017Date20260502130000 extends SimpleMigrationStep
{
    /**
     * Create the dashboard translations table.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure returning an
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

        DashboardTranslationTableBuilder::create(schema: $schema);

        return $schema;
    }//end changeSchema()
}//end class
