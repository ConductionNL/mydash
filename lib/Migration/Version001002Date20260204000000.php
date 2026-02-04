<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to increase icon column size for SVG paths.
 */
class Version001002Date20260204000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Increase icon column size to support longer SVG paths.
		if ($schema->hasTable('mydash_tiles')) {
			$table = $schema->getTable('mydash_tiles');
			
			if ($table->hasColumn('icon')) {
				$iconColumn = $table->getColumn('icon');
				// Increase from 500 to 2000 characters to support complex SVG paths.
				$iconColumn->setLength(2000);
			}
		}

		return $schema;
	}
}
