<?php

/**
 * Version001025Date20260508060000
 *
 * Migration to add the `content` JSON column to `mydash_widget_placements`.
 *
 * The widget registry (`src/constants/widgetRegistry.js`) declares a
 * `defaultContent` blob for each of the 17 custom widget types and the
 * AddWidgetModal collects per-type configuration into a `{type, content}`
 * payload. Every per-widget spec (e.g. `openspec/specs/quicklinks-widget/
 * spec.md`) names `oc_mydash_widget_placements.content` as the storage
 * location for that blob; this migration is the one that actually creates
 * the column the specs assume.
 *
 * Stored as nullable TEXT (JSON-encoded by the entity) so existing rows
 * created before this migration still load — they simply yield an empty
 * content array via `WidgetPlacement::getContentArray()`.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001025Date20260508060000 extends SimpleMigrationStep
{
    /**
     * Add the `content` column to the widget placements table.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure that returns an ISchemaWrapper.
     * @param array   $options       The migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null when no change is needed.
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('mydash_widget_placements') === false) {
            return null;
        }

        $table = $schema->getTable('mydash_widget_placements');

        if ($table->hasColumn('content') === false) {
            $table->addColumn(
                'content',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );
        }

        return $schema;
    }//end changeSchema()
}//end class
