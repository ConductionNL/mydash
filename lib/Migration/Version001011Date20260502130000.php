<?php

/**
 * Version001011Date20260502130000
 *
 * Migration that adds the dashboard publication-state columns
 * (`publication_status`, `publish_at`, `published_at`) plus the
 * `(user_id, publication_status)` composite index on
 * `oc_mydash_dashboards`. Required by REQ-DASH-031..037 (dashboard
 * draft / published / scheduled workflow).
 *
 * Backfill is implicit via the column default `'published'` — the
 * database engine materialises pre-existing rows as `'published'`
 * automatically with no separate UPDATE statement, eliminating the
 * partial-update risk on large tables. New dashboards created after
 * the migration default to `'draft'` via application logic in
 * `DashboardFactory::create()` (REQ-DASH-031 / design D1).
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
 * Add publication-state columns + supporting index to mydash_dashboards
 * (REQ-DASH-031..037).
 */
class Version001011Date20260502130000 extends SimpleMigrationStep
{
    /**
     * Add the publication_status / publish_at / published_at columns +
     * the (user_id, publication_status) composite index.
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

        DashboardTableBuilder::addPublicationColumns(table: $table);

        return $schema;
    }//end changeSchema()
}//end class
