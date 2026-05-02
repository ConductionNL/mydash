<?php

/**
 * Version001006Date20260430130000
 *
 * Migration creating the mydash_dashboard_shares table for REQ-SHARE-001.
 * Also includes an optional one-shot orphan-share cleanup step gated by
 * the admin setting `mydash.cleanup_orphan_shares`. REQ-SHARE-012.
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
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration creating the dashboard_shares table and an optional orphan cleanup.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Version001006Date20260430130000 extends SimpleMigrationStep
{
    /**
     * Constructor
     *
     * @param IConfig       $config       The app config.
     * @param IDBConnection $db           The database connection.
     * @param IUserManager  $userManager  The user manager.
     * @param IGroupManager $groupManager The group manager.
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly IDBConnection $db,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Create the mydash_dashboard_shares table.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure.
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

        if ($schema->hasTable(tableName: 'mydash_dashboard_shares') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'mydash_dashboard_shares');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            name: 'dashboard_id',
            typeName: Types::BIGINT,
            options: [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            name: 'share_type',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 16,
            ]
        );
        $table->addColumn(
            name: 'share_with',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            name: 'permission_level',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 32,
                'default' => 'view_only',
            ]
        );
        $table->addColumn(
            name: 'created_at',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 32,
            ]
        );
        $table->addColumn(
            name: 'updated_at',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 32,
            ]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addIndex(
            columnNames: ['dashboard_id'],
            indexName: 'mydash_shares_dashboard'
        );
        $table->addIndex(
            columnNames: ['share_type', 'share_with'],
            indexName: 'mydash_shares_recipient'
        );
        $table->addUniqueIndex(
            columnNames: ['dashboard_id', 'share_type', 'share_with'],
            indexName: 'mydash_shares_unique'
        );

        return $schema;
    }//end changeSchema()

    /**
     * Optional orphan-share cleanup, gated by admin setting.
     *
     * Deletes share rows where the recipient user/group no longer exists.
     * Only runs when `mydash.cleanup_orphan_shares` is explicitly `true`.
     * Default is `false` to avoid surprise deletions on federated environments.
     *
     * @param IOutput $output        The migration output handler.
     * @param Closure $schemaClosure The schema closure.
     * @param array   $options       The migration options.
     *
     * @return void
     */
    public function postSchemaChange(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): void {
        $enabled = $this->config->getAppValue(
            appName: 'mydash',
            key: 'cleanup_orphan_shares',
            default: 'false'
        );

        if ($enabled !== 'true') {
            return;
        }

        $output->info(message: 'mydash: scanning for orphan share rows…');

        if ($this->db->tableExists(table: 'mydash_dashboard_shares') === false) {
            return;
        }

        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select(selects: ['id', 'share_type', 'share_with'])
            ->from(from: 'mydash_dashboard_shares')
            ->executeQuery();

        $deleted = 0;
        $row     = $result->fetch();
        while ($row !== false) {
            $orphan = false;
            if ($row['share_type'] === 'user'
                && $this->userManager->get(uid: $row['share_with']) === null
            ) {
                $orphan = true;
            } else if ($row['share_type'] === 'group'
                && $this->groupManager->get(gid: $row['share_with']) === null
            ) {
                $orphan = true;
            }

            if ($orphan === true) {
                $del = $this->db->getQueryBuilder();
                $del->delete(delete: 'mydash_dashboard_shares')
                    ->where(
                        $del->expr()->eq(
                            x: 'id',
                            y: $del->createNamedParameter(
                                value: (int) $row['id'],
                                type: \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
                            )
                        )
                    )
                    ->executeStatement();
                $deleted++;
            }

            $row = $result->fetch();
        }//end while

        $result->closeCursor();
        $output->info(message: "mydash: removed {$deleted} orphan share row(s).");
    }//end postSchemaChange()
}//end class
