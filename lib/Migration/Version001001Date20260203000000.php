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
 * Migration to add custom tiles feature
 */
class Version001001Date20260203000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create mydash_tiles table.
		if (!$schema->hasTable('mydash_tiles')) {
			$table = $schema->createTable('mydash_tiles');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
		$table->addColumn('icon', Types::STRING, [
			'notnull' => true,
			'length' => 2000,
			'comment' => 'Icon class (e.g., icon-files), URL to icon image, or SVG path data',
		]);
			$table->addColumn('icon_type', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'class',
				'comment' => 'Type of icon: class, url, or emoji',
			]);
			$table->addColumn('background_color', Types::STRING, [
				'notnull' => true,
				'length' => 7,
				'default' => '#0082c9',
				'comment' => 'Hex color code for tile background',
			]);
			$table->addColumn('text_color', Types::STRING, [
				'notnull' => true,
				'length' => 7,
				'default' => '#ffffff',
				'comment' => 'Hex color code for text',
			]);
			$table->addColumn('link_type', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'comment' => 'Type of link: app or url',
			]);
			$table->addColumn('link_value', Types::STRING, [
				'notnull' => true,
				'length' => 1000,
				'comment' => 'App ID or URL',
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'mydash_tiles_user');
		}

		return $schema;
	}
}
