<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add custom_icon column to widget placements
 *
 * @category Migration
 * @package  OCA\MyDash\Migration
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://conduction.nl
 */
class Version001004Date20260204150000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('mydash_widget_placements');

		if (!$table->hasColumn('custom_icon')) {
			$table->addColumn('custom_icon', Types::TEXT, [
				'notnull' => false,
				'length' => 2000,
			]);
		}

		return $schema;
	}
}
