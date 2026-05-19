<?php

/**
 * Version001012Date20260503000000
 *
 * Migration that adds the dashboard-templates discovery columns
 * (`template_category`, `template_description`, `template_preview_image`)
 * plus the `(type, template_category)` composite index on
 * `oc_mydash_dashboards`. Required by REQ-TMPL-014..017 (template gallery
 * + save-as-template + preview-image metadata).
 *
 * Zero-impact: every column is nullable. Existing rows of every type
 * (`user`, `admin_template`, `group_shared`) get NULL defaults — gallery
 * filtering and serialisation handle null values explicitly. No backfill
 * is required.
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
 * Add template discovery columns + supporting index to mydash_dashboards
 * (REQ-TMPL-014..017).
 */
class Version001012Date20260503000000 extends SimpleMigrationStep
{
    /**
     * Add the template_category / template_description / template_preview_image
     * columns and the (type, template_category) composite index.
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

        DashboardTableBuilder::addTemplateDiscoveryColumns(table: $table);

        return $schema;
    }//end changeSchema()
}//end class
