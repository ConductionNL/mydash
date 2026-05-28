<?php

/**
 * Version002000Date20260519000000
 *
 * Migration that copies existing mydash dashboard rows from the
 * `mydash_dashboards` + `mydash_widget_placements` Nextcloud tables into
 * OpenRegister objects (register: `mydash`, schema: `dashboard`) per
 * ADR-036 Decision 8.
 *
 * ## Strategy
 *
 * mydash is a pre-production app. There are no real user databases with
 * significant live dashboards that need preserving. The migration therefore
 * takes a "best-effort copy then leave old tables in place" approach:
 *
 *   1. Iterate rows in `mydash_dashboards` that have type = 'user' or
 *      type = 'group_shared'. Admin-template rows are NOT migrated (they
 *      have no equivalent in the v2 OR-backed model yet).
 *   2. For each dashboard, collect its widget placements from
 *      `mydash_widget_placements` and build the v2 `widgets` array
 *      (widgetKey = widgetId, grid coords from columns).
 *   3. Save via ObjectService::saveObject(). On any error, log and
 *      continue (non-fatal — the user can recreate dashboards).
 *   4. The original tables are NOT dropped in this migration. They remain
 *      read-only artefacts. A follow-up migration can drop them once the
 *      OR pivot is stable.
 *
 * ## Breaking change
 *
 * After this migration the ManifestController reads exclusively from OR.
 * Dashboards that fail to migrate will not appear in the manifest.
 * Because mydash is pre-production this is acceptable — document in the PR.
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrate existing mydash dashboard rows into OpenRegister objects.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Version002000Date20260519000000 extends SimpleMigrationStep
{
    /**
     * OpenRegister register slug.
     *
     * @var string
     */
    private const REGISTER = 'mydash';

    /**
     * OpenRegister schema slug.
     *
     * @var string
     */
    private const SCHEMA = 'dashboard';

    /**
     * Maximum dashboards to migrate per run (safety cap for large instances).
     *
     * @var int
     */
    private const MAX_MIGRATE = 2000;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container Nextcloud DI container.
     * @param LoggerInterface    $logger    PSR logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * No schema changes — this migration only moves data.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure.
     * @param array   $options       Options.
     *
     * @return ISchemaWrapper|null Always null (no schema change).
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        return null;

    }//end changeSchema()

    /**
     * Copy dashboard rows from mydash tables into OpenRegister.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure (unused).
     * @param array   $options       Options (unused).
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Retrieve ObjectService via the DI container. If OpenRegister is not
        // installed / enabled on this instance, skip silently.
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->info('MyDash migration: OpenRegister not available — skipping dashboard copy.');
            return;
        }//end try

        // Retrieve the DB connection to query the legacy tables.
        try {
            /*
             * @var \OCP\IDBConnection $db
             */

            $db = $this->container->get(\OCP\IDBConnection::class);
        } catch (\Throwable $e) {
            $output->info('MyDash migration: could not get DB connection — skipping dashboard copy.');
            return;
        }//end try

        if ($this->legacyTableExists(db: $db) === false) {
            $output->info('MyDash migration: legacy mydash_dashboards table not found — nothing to migrate.');
            return;
        }

        $output->info('MyDash migration: starting dashboard copy to OpenRegister.');

        $migrated = 0;
        $failed   = 0;

        try {
            $qb = $db->getQueryBuilder();
            $qb->select('d.*')
                ->from('mydash_dashboards', 'd')
                ->where($qb->expr()->neq('d.type', $qb->createNamedParameter('admin_template')))
                ->setMaxResults(self::MAX_MIGRATE);

            $result = $qb->executeQuery();
            $row    = $result->fetch();

            while ($row !== false) {
                try {
                    $widgetData = $this->loadPlacements(db: $db, dashboardId: (int) $row['id']);

                    $data = [
                        '@self'       => [
                            'register' => self::REGISTER,
                            'schema'   => self::SCHEMA,
                            'slug'     => $row['uuid'] ?? $row['slug'] ?? ('dashboard-'.$row['id']),
                        ],
                        'slug'        => $row['slug'] ?? $row['uuid'] ?? ('dashboard-'.$row['id']),
                        'title'       => $row['name'] ?? 'Dashboard',
                        'description' => $row['description'] ?? '',
                        'version'     => '1.0.0',
                        'widgets'     => $widgetData,
                        'sharedWith'  => [],
                        'isDefault'   => ((int) ($row['is_default'] ?? 0)) === 1,
                    ];

                    $objectService->saveObject(self::REGISTER, self::SCHEMA, $data);
                    $migrated++;
                } catch (\Throwable $innerEx) {
                    $failed++;
                    $this->logger->warning(
                        'MyDash migration: failed to migrate dashboard id='.$row['id'].': '.$innerEx->getMessage(),
                        ['app' => 'mydash']
                    );
                }//end try

                $row = $result->fetch();
            }//end while

            $result->closeCursor();
        } catch (\Throwable $e) {
            $output->info('MyDash migration: query failed — '.$e->getMessage());
            $this->logger->error('MyDash migration error: '.$e->getMessage(), ['app' => 'mydash']);
        }//end try

        $output->info("MyDash migration complete: {$migrated} migrated, {$failed} failed.");

    }//end postSchemaChange()

    /**
     * Check whether the legacy dashboards table exists in the database.
     *
     * @param \OCP\IDBConnection $db The database connection.
     *
     * @return bool True when the table is present.
     */
    private function legacyTableExists(\OCP\IDBConnection $db): bool
    {
        try {
            $schema = $db->createSchema();
            return $schema->hasTable('mydash_dashboards');
        } catch (\Throwable) {
            return false;
        }//end try

    }//end legacyTableExists()

    /**
     * Load widget placements for a given dashboard and return the v2 widget array.
     *
     * @param \OCP\IDBConnection $db          The database connection.
     * @param int                $dashboardId The legacy dashboard row ID.
     *
     * @return array<int, array<string, mixed>> v2 widget entries.
     */
    private function loadPlacements(\OCP\IDBConnection $db, int $dashboardId): array
    {
        $widgets = [];

        try {
            $qb = $db->getQueryBuilder();
            $qb->select('p.*')
                ->from('mydash_widget_placements', 'p')
                ->where($qb->expr()->eq('p.dashboard_id', $qb->createNamedParameter($dashboardId)));

            $result    = $qb->executeQuery();
            $placement = $result->fetch();

            while ($placement !== false) {
                $widgets[] = [
                    'widgetKey'  => $placement['widget_id'] ?? '',
                    'slot'       => 'main',
                    'gridX'      => (int) ($placement['grid_x'] ?? 0),
                    'gridY'      => (int) ($placement['grid_y'] ?? 0),
                    'gridWidth'  => (int) ($placement['grid_width'] ?? 4),
                    'gridHeight' => (int) ($placement['grid_height'] ?? 4),
                ];

                $placement = $result->fetch();
            }//end while

            $result->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MyDash migration: could not load placements for dashboard '.$dashboardId.': '.$e->getMessage(),
                ['app' => 'mydash']
            );
        }//end try

        return $widgets;

    }//end loadPlacements()
}//end class
