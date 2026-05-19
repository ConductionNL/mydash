<?php

/**
 * Version001021Date20260502130000
 *
 * Migration that creates the `mydash_dashboard_locks` table required by
 * REQ-LOCK-001..008 (dashboard editing-lock workflow with 15-minute TTL
 * and 60-second client heartbeat).
 *
 * Zero-impact: a brand-new table only — no changes to existing dashboard,
 * share, placement, or rule tables. Existing data is unaffected.
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
 * Add the `mydash_dashboard_locks` table (REQ-LOCK-001..008).
 */
class Version001021Date20260502130000 extends SimpleMigrationStep
{
    /**
     * Create the dashboard locks table via DashboardLockTableBuilder.
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

        DashboardLockTableBuilder::create(schema: $schema);

        return $schema;
    }//end changeSchema()
}//end class
