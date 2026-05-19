<?php

/**
 * Version001016Date20260502130000
 *
 * Migration that creates the dashboard-metadata-fields capability
 * tables: `oc_mydash_meta_fields` (the global field-definition
 * registry) and `oc_mydash_meta_values` (per-dashboard typed values).
 * Required by REQ-MDFL-001..008.
 *
 * Zero-impact: both tables are new; no existing data is touched and
 * no backfill is required. Dashboards without any metadata continue
 * to read as `{}` from the metadata endpoint.
 *
 * Composite indexes:
 *   - `mydash_meta_fkey`     unique(field_key)
 *   - `mydash_meta_forder`   (sort_order)
 *   - `mydash_meta_vunique`  unique(dashboard_uuid, field_id)
 *   - `mydash_meta_vdash`    (dashboard_uuid)
 *   - `mydash_meta_vfield`   (field_id)
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
 * Create metadata-field registry + per-dashboard value tables
 * (REQ-MDFL-001..008).
 */
class Version001016Date20260502130000 extends SimpleMigrationStep
{
    /**
     * Create the `mydash_meta_fields` and `mydash_meta_values` tables.
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

        MetadataTablesBuilder::create(schema: $schema);

        return $schema;
    }//end changeSchema()
}//end class
