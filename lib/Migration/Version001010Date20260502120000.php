<?php

/**
 * Version001010Date20260502120000
 *
 * Migration that adds the dashboard hierarchy columns
 * (`parent_uuid`, `slug`, `sort_order`) plus the supporting indexes on
 * `oc_mydash_dashboards`. Required by REQ-DASH-023..030
 * (dashboard tree, slug-based path resolution, sibling ordering, and
 * cascade-delete guard).
 *
 * Zero-impact: every column is nullable (or has a numeric default) and
 * no backfill is required. Existing rows become root dashboards
 * (`parent_uuid = NULL`) with `slug = NULL` (the service layer
 * generates a slug from the name on first read when missing) and
 * `sort_order = 0`.
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
 * Add hierarchy columns + supporting indexes to mydash_dashboards
 * (REQ-DASH-023..030).
 */
class Version001010Date20260502120000 extends SimpleMigrationStep
{
    /**
     * Add the parent_uuid / slug / sort_order columns and indexes.
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

        DashboardTableBuilder::addTreeColumns(table: $table);

        return $schema;
    }//end changeSchema()
}//end class
