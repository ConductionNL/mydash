<?php

/**
 * Version001002Date20260204000000
 *
 * Migration to increase icon column size for SVG paths.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
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

class Version001002Date20260204000000 extends SimpleMigrationStep
{
    /**
     * Increase icon column size for SVG paths.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure returns an ISchemaWrapper.
     * @param array   $options       The migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null.
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        // Get the schema wrapper.
        $schema = $schemaClosure();

        // Increase icon column size to support longer SVG paths.
        if ($schema->hasTable(tableName: 'mydash_tiles') === true) {
            $table = $schema->getTable(tableName: 'mydash_tiles');

            if ($table->hasColumn(name: 'icon') === true) {
                $iconColumn = $table->getColumn(name: 'icon');
                // Increase from 500 to 2000 characters for complex SVG paths.
                $iconColumn->setLength(length: 2000);
            }
        }

        return $schema;
    }//end changeSchema()
}//end class
