<?php

/**
 * RoleAssignmentTableBuilder
 *
 * Builder for the `mydash_role_assignments` database table introduced
 * by the admin-roles capability. REQ-ROLE-004.
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

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;

/**
 * Builder for the role-assignments table schema (REQ-ROLE-004).
 *
 * The XOR constraint between user_id and group_id is enforced at the
 * service layer (RoleService::validateTarget). Database-level CHECK
 * constraints are not portable across the SQL dialects Nextcloud
 * supports, so we instead enforce uniqueness via two partial-style
 * indexes (composite (user_id, role) and (group_id, role)) plus the
 * service-layer XOR validation. Either user_id OR group_id is set per
 * row, never both.
 */
class RoleAssignmentTableBuilder
{
    /**
     * Create the `mydash_role_assignments` table when missing.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_role_assignments') === true) {
            return;
        }

        $table = $schema->createTable('mydash_role_assignments');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            'user_id',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'group_id',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'role',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 16,
            ]
        );
        $table->addColumn(
            'assigned_by',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'assigned_at',
            Types::DATETIME,
            ['notnull' => true]
        );

        $table->setPrimaryKey(['id']);

        // Lookup indexes for cascade and resolution paths.
        $table->addIndex(
            ['user_id'],
            'mydash_role_user_idx'
        );
        $table->addIndex(
            ['group_id'],
            'mydash_role_group_idx'
        );

        // Enforce one assignment per (user, role) and per (group, role).
        // Rows are XOR — only one of user_id / group_id is populated —
        // so a single row never participates in both unique indexes.
        $table->addUniqueIndex(
            ['user_id', 'role'],
            'mydash_role_user_uniq'
        );
        $table->addUniqueIndex(
            ['group_id', 'role'],
            'mydash_role_group_uniq'
        );
    }//end create()
}//end class
