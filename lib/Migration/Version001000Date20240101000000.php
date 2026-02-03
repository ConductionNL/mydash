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

class Version001000Date20240101000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create mydash_dashboards table
		if (!$schema->hasTable('mydash_dashboards')) {
			$table = $schema->createTable('mydash_dashboards');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('uuid', Types::STRING, [
				'notnull' => true,
				'length' => 36,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'user',
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('based_on_template', Types::BIGINT, [
				'notnull' => false,
				'unsigned' => true,
			]);
			$table->addColumn('grid_columns', Types::INTEGER, [
				'notnull' => true,
				'default' => 12,
			]);
			$table->addColumn('permission_level', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'full',
			]);
			$table->addColumn('target_groups', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('is_default', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
			]);
			$table->addColumn('is_active', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'mydash_dashboard_uuid');
			$table->addIndex(['user_id'], 'mydash_dashboard_user');
			$table->addIndex(['type'], 'mydash_dashboard_type');
			$table->addIndex(['user_id', 'is_active'], 'mydash_dashboard_active');
		}

		// Create mydash_widget_placements table
		if (!$schema->hasTable('mydash_widget_placements')) {
			$table = $schema->createTable('mydash_widget_placements');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('dashboard_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('widget_id', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('grid_x', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('grid_y', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('grid_width', Types::INTEGER, [
				'notnull' => true,
				'default' => 4,
			]);
			$table->addColumn('grid_height', Types::INTEGER, [
				'notnull' => true,
				'default' => 4,
			]);
			$table->addColumn('is_compulsory', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
			]);
			$table->addColumn('is_visible', Types::SMALLINT, [
				'notnull' => true,
				'default' => 1,
				'unsigned' => true,
			]);
			$table->addColumn('style_config', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('custom_title', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('show_title', Types::SMALLINT, [
				'notnull' => true,
				'default' => 1,
				'unsigned' => true,
			]);
			$table->addColumn('sort_order', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['dashboard_id'], 'mydash_placement_dashboard');
			$table->addIndex(['widget_id'], 'mydash_placement_widget');
		}

		// Create mydash_admin_settings table
		if (!$schema->hasTable('mydash_admin_settings')) {
			$table = $schema->createTable('mydash_admin_settings');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('setting_key', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('setting_value', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['setting_key'], 'mydash_setting_key');
		}

		// Create mydash_conditional_rules table
		if (!$schema->hasTable('mydash_conditional_rules')) {
			$table = $schema->createTable('mydash_conditional_rules');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('widget_placement_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('rule_type', Types::STRING, [
				'notnull' => true,
				'length' => 50,
			]);
			$table->addColumn('rule_config', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('is_include', Types::SMALLINT, [
				'notnull' => true,
				'default' => 1,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['widget_placement_id'], 'mydash_rule_placement');
		}

		return $schema;
	}
}
