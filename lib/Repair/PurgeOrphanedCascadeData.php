<?php

/**
 * PurgeOrphanedCascadeData
 *
 * One-time repair step that removes orphaned rows left by pre-C4 stub
 * listeners. Runs on post-migration so existing installs are cleaned up
 * without manual intervention (REQ-CSC-003, REQ-CLN-001).
 *
 * Tables cleaned:
 *   - oc_mydash_widget_placements   (dashboard_id no longer in dashboards)
 *   - oc_mydash_dashboard_locks     (dashboard_uuid no longer in dashboards)
 *   - oc_mydash_metadata_values     (dashboard_uuid no longer in dashboards)
 *   - oc_mydash_dash_translations   (dashboard_uuid no longer in dashboards)
 *   - oc_mydash_dashboard_views     (dashboard_uuid no longer in dashboards)
 *   - oc_mydash_dashboard_shares    (dashboard_id no longer in dashboards)
 *   - oc_mydash_dashboard_versions  (dashboard_uuid no longer in dashboards)
 *
 * @category Repair
 * @package  OCA\MyDash\Repair
 *
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

namespace OCA\MyDash\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Purge orphaned cascade-data rows left by pre-C4 stub listeners.
 *
 * @spec openspec/specs/dashboard-cascade-events/spec.md
 */
class PurgeOrphanedCascadeData implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param IDBConnection   $db     The database connection.
     * @param LoggerInterface $logger PSR-3 logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec openspec/specs/dashboard-cascade-events/spec.md
     */
    public function getName(): string
    {
        return 'Purge orphaned cascade data from deleted dashboards (C4/REQ-CSC-003)';
    }//end getName()

    /**
     * Delete orphaned rows from all cascade tables.
     *
     * Each DELETE uses a NOT IN subquery against oc_mydash_dashboards so
     * only rows whose parent no longer exists are removed. Failures per
     * table are caught and logged without stopping the overall step.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard-cascade-events/spec.md
     */
    public function run(IOutput $output): void
    {
        $output->info('mydash: purging orphaned cascade data…');

        $total = 0;

        // UUID-keyed tables: uuid columns reference dashboards.uuid.
        $uuidTables = [
            ['mydash_dashboard_locks',    'dashboard_uuid'],
            ['mydash_metadata_values',    'dashboard_uuid'],
            ['mydash_dash_translations',  'dashboard_uuid'],
            ['mydash_dashboard_views',    'dashboard_uuid'],
            ['mydash_dashboard_versions', 'dashboard_uuid'],
        ];

        foreach ($uuidTables as [$table, $fkCol]) {
            $total += $this->purgeOrphansUuid(
                output: $output,
                table: $table,
                fkCol: $fkCol
            );
        }

        // ID-keyed tables: id columns reference dashboards.id.
        $idTables = [
            ['mydash_widget_placements',  'dashboard_id'],
            ['mydash_dashboard_shares',   'dashboard_id'],
        ];

        foreach ($idTables as [$table, $fkCol]) {
            $total += $this->purgeOrphansId(
                output: $output,
                table: $table,
                fkCol: $fkCol
            );
        }

        $output->info(
            sprintf(
                'mydash: orphaned cascade purge complete — %d row(s) removed.',
                $total
            )
        );
    }//end run()

    /**
     * Delete rows from a table whose UUID FK has no matching dashboards.uuid.
     *
     * @param IOutput $output Repair output channel.
     * @param string  $table  Table name (without oc_ prefix).
     * @param string  $fkCol  FK column name.
     *
     * @return int Number of rows deleted.
     */
    private function purgeOrphansUuid(
        IOutput $output,
        string $table,
        string $fkCol
    ): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where(
                    $qb->expr()->notIn(
                        x: $fkCol,
                        y: $qb->createFunction(
                            call: '(SELECT `uuid` FROM `*PREFIX*mydash_dashboards`)'
                        )
                    )
                );

            $count = $qb->executeStatement();

            if ($count > 0) {
                $output->info(
                    sprintf(
                        '  %s: removed %d orphaned row(s).',
                        $table,
                        $count
                    )
                );
            }

            return $count;
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash PurgeOrphanedCascadeData: failed on %s: %s',
                    $table,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
            $output->warning(
                sprintf('  %s: purge failed (%s) — skipped.', $table, $t->getMessage())
            );
            return 0;
        }//end try
    }//end purgeOrphansUuid()

    /**
     * Delete rows from a table whose integer FK has no matching dashboards.id.
     *
     * @param IOutput $output Repair output channel.
     * @param string  $table  Table name (without oc_ prefix).
     * @param string  $fkCol  FK column name.
     *
     * @return int Number of rows deleted.
     */
    private function purgeOrphansId(
        IOutput $output,
        string $table,
        string $fkCol
    ): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where(
                    $qb->expr()->notIn(
                        x: $fkCol,
                        y: $qb->createFunction(
                            call: '(SELECT `id` FROM `*PREFIX*mydash_dashboards`)'
                        )
                    )
                );

            $count = $qb->executeStatement();

            if ($count > 0) {
                $output->info(
                    sprintf(
                        '  %s: removed %d orphaned row(s).',
                        $table,
                        $count
                    )
                );
            }

            return $count;
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash PurgeOrphanedCascadeData: failed on %s: %s',
                    $table,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
            $output->warning(
                sprintf('  %s: purge failed (%s) — skipped.', $table, $t->getMessage())
            );
            return 0;
        }//end try
    }//end purgeOrphansId()
}//end class
