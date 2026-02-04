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
 * Migration to add tile configuration fields to widget placements
 * 
 * This migration adds tile-specific fields to the widget_placements table,
 * allowing tiles to be stored directly as placements rather than as separate entities.
 * 
 * @category Migration
 * @package  OCA\MyDash\Migration
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/mydash
 */
class Version001003Date20260204120000 extends SimpleMigrationStep {

	/**
	 * Add tile configuration columns to widget_placements table
	 *
	 * @param IOutput $output Migration output handler.
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`.
	 * @param array $options Migration options.
	 * 
	 * @return null|ISchemaWrapper The modified schema wrapper or null.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add tile configuration fields to widget_placements table.
		if ($schema->hasTable('mydash_widget_placements')) {
			$table = $schema->getTable('mydash_widget_placements');
			
			// Add tile_type to distinguish between widgets and tiles.
			if (!$table->hasColumn('tile_type')) {
				$table->addColumn('tile_type', Types::STRING, [
					'notnull' => false,
					'length' => 20,
					'default' => null,
					'comment' => 'Type of tile: custom (null for regular widgets)',
				]);
			}

			// Add tile_title for custom tiles.
			if (!$table->hasColumn('tile_title')) {
				$table->addColumn('tile_title', Types::STRING, [
					'notnull' => false,
					'length' => 255,
					'default' => null,
					'comment' => 'Title for custom tiles',
				]);
			}

			// Add tile_icon.
			if (!$table->hasColumn('tile_icon')) {
				$table->addColumn('tile_icon', Types::STRING, [
					'notnull' => false,
					'length' => 2000,
					'default' => null,
					'comment' => 'Icon class, URL, emoji, or SVG path data for tiles',
				]);
			}

			// Add tile_icon_type.
			if (!$table->hasColumn('tile_icon_type')) {
				$table->addColumn('tile_icon_type', Types::STRING, [
					'notnull' => false,
					'length' => 20,
					'default' => null,
					'comment' => 'Type of icon: class, url, emoji, or svg',
				]);
			}

			// Add tile_background_color.
			if (!$table->hasColumn('tile_background_color')) {
				$table->addColumn('tile_background_color', Types::STRING, [
					'notnull' => false,
					'length' => 7,
					'default' => null,
					'comment' => 'Hex color code for tile background',
				]);
			}

			// Add tile_text_color.
			if (!$table->hasColumn('tile_text_color')) {
				$table->addColumn('tile_text_color', Types::STRING, [
					'notnull' => false,
					'length' => 7,
					'default' => null,
					'comment' => 'Hex color code for tile text',
				]);
			}

			// Add tile_link_type.
			if (!$table->hasColumn('tile_link_type')) {
				$table->addColumn('tile_link_type', Types::STRING, [
					'notnull' => false,
					'length' => 20,
					'default' => null,
					'comment' => 'Type of link: app or url',
				]);
			}

			// Add tile_link_value.
			if (!$table->hasColumn('tile_link_value')) {
				$table->addColumn('tile_link_value', Types::STRING, [
					'notnull' => false,
					'length' => 1000,
					'default' => null,
					'comment' => 'App ID or URL for tile links',
				]);
			}
		}

		return $schema;
	}
}
