<?php

/**
 * ExportCommand
 *
 * `php occ mydash:export` — write a versioned ZIP archive of one or
 * more dashboards to disk. Implements REQ-EXIM-010.
 *
 * @category  Command
 * @package   OCA\MyDash\Command
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Command;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Service\ExportService;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use ZipArchive;

/**
 * `mydash:export` console command.
 */
class ExportCommand extends Command
{
    /**
     * Constructor.
     *
     * @param ExportService   $exportService   Export service.
     * @param DashboardMapper $dashboardMapper Dashboard mapper for
     *                                         site-scope iteration.
     */
    public function __construct(
        private readonly ExportService $exportService,
        private readonly DashboardMapper $dashboardMapper,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure CLI options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'mydash:export')
            ->setDescription(description: 'Export MyDash dashboards to a versioned ZIP archive.')
            ->addOption(
                name: 'scope',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Export scope: "site" (default) or "dashboard".',
                default: 'site'
            )
            ->addOption(
                name: 'dashboard-uuid',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Dashboard UUID, required when --scope=dashboard.'
            )
            ->addOption(
                name: 'output',
                shortcut: 'o',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Output file path for the ZIP archive.'
            );
    }//end configure()

    /**
     * Execute the export.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Exit code (0 success, 1 error).
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $scope        = (string) $input->getOption(name: 'scope');
        $outputPath   = (string) ($input->getOption(name: 'output') ?? '');
        $dashboardUid = (string) ($input->getOption(name: 'dashboard-uuid') ?? '');

        if ($outputPath === '') {
            $output->writeln(messages: '<error>--output parameter is required</error>');
            return self::FAILURE;
        }

        if (in_array(needle: $scope, haystack: ['site', 'dashboard'], strict: true) === false) {
            $output->writeln(
                messages: '<error>Unsupported scope: '.$scope.'. Use "site" or "dashboard".</error>'
            );
            return self::FAILURE;
        }

        if ($scope === 'dashboard' && $dashboardUid === '') {
            $output->writeln(
                messages: '<error>--dashboard-uuid is required when --scope=dashboard</error>'
            );
            return self::FAILURE;
        }

        try {
            $count = $this->writeArchive(
                scope: $scope,
                dashboardUuid: $dashboardUid,
                outputPath: $outputPath
            );
        } catch (DoesNotExistException) {
            $output->writeln(messages: '<error>Dashboard not found: '.$dashboardUid.'</error>');
            return self::FAILURE;
        } catch (Throwable $e) {
            $output->writeln(messages: '<error>Export failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

        $noun = 'dashboards';
        if ($count === 1) {
            $noun = 'dashboard';
        }

        $output->writeln(
            messages: 'Exported '.(string) $count.' '.$noun.' to '.$outputPath
        );
        return self::SUCCESS;
    }//end execute()

    /**
     * Write the archive to disk by reusing the export service helpers.
     *
     * The CLI uses the same serializer the HTTP path uses; we copy the
     * temporary stream back to the requested on-disk location.
     *
     * @param string $scope         The export scope.
     * @param string $dashboardUuid Dashboard UUID (when scope=dashboard).
     * @param string $outputPath    Destination file path.
     *
     * @return int The dashboard count written.
     *
     * @throws DoesNotExistException When the dashboard is not found.
     */
    private function writeArchive(
        string $scope,
        string $dashboardUuid,
        string $outputPath
    ): int {
        $dashboards = $this->collectDashboards(
            scope: $scope,
            dashboardUuid: $dashboardUuid
        );

        $zip = new ZipArchive();
        if ($zip->open(filename: $outputPath, flags: ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(message: 'Could not open ZIP archive at '.$outputPath);
        }

        $manifest = $this->exportService->buildManifest(
            scope: $scope,
            dashboardCount: count($dashboards),
            currentUserId: 'cli'
        );
        $zip->addFromString(
            name: 'manifest.json',
            content: (string) json_encode(
                value: $manifest,
                flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            )
        );

        foreach ($dashboards as $dashboard) {
            $payload = $this->exportService->serializeDashboard(dashboard: $dashboard);
            $uuid    = (string) $dashboard->getUuid();
            if ($uuid === '') {
                continue;
            }

            $zip->addFromString(
                name: 'dashboards/'.$uuid.'.json',
                content: (string) json_encode(value: $payload, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            );
            $zip->addEmptyDir(dirname: 'assets/widgets/'.$uuid.'/');
        }

        $zip->addFromString(name: 'metadata-fields.json', content: '[]');
        $zip->addEmptyDir(dirname: 'assets/icons/');

        $zip->close();

        return count($dashboards);
    }//end writeArchive()

    /**
     * Resolve the dashboard collection for the requested scope.
     *
     * @param string $scope         The export scope.
     * @param string $dashboardUuid Dashboard UUID (when scope=dashboard).
     *
     * @return Dashboard[] The dashboards to export.
     *
     * @throws DoesNotExistException When the dashboard is not found.
     */
    private function collectDashboards(
        string $scope,
        string $dashboardUuid
    ): array {
        if ($scope === 'dashboard') {
            return [$this->dashboardMapper->findByUuid(uuid: $dashboardUuid)];
        }

        $all = [];
        foreach ($this->dashboardMapper->findAdminTemplates() as $tpl) {
            $all[] = $tpl;
        }

        foreach ($this->dashboardMapper->findByParent(parentUuid: null) as $root) {
            $all[] = $root;
            $uuid  = (string) $root->getUuid();
            if ($uuid === '') {
                continue;
            }

            foreach ($this->dashboardMapper->findDescendants(ancestorUuid: $uuid) as $child) {
                $all[] = $child;
            }
        }

        return $all;
    }//end collectDashboards()
}//end class
