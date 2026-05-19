<?php

/**
 * Version001023Date20260502130000
 *
 * Migration that creates the `oc_mydash_feed_cache` table backing the
 * background-job feed-refresh capability (REQ-FRJ-001..012). One row per
 * distinct external feed URL (`UNIQUE(feed_url)`) holding the conditional
 * GET headers, fetch metadata, and the cached normalised items as JSON.
 *
 * Migration is purely additive — there are no existing rows to backfill.
 * The cache fills naturally as the background job ticks; until then the
 * news widget will fall back to its on-demand fetch path.
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
 * Add the feed-cache table backing the background-job feed-refresh
 * capability (REQ-FRJ-001..012).
 */
class Version001023Date20260502130000 extends SimpleMigrationStep
{
    /**
     * Create the feed-cache table.
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

        FeedCacheTableBuilder::create(schema: $schema);

        return $schema;
    }//end changeSchema()
}//end class
