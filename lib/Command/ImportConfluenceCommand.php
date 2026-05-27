<?php

/**
 * ImportConfluenceCommand
 *
 * `php occ mydash:import:confluence` — REQ-CFLI-011 CLI surface for
 * the Confluence HTML export importer. Mirrors the HTTP controller but
 * works headless so admins can run imports from cron / shell scripts.
 *
 * @category  Command
 * @package   OCA\MyDash\Command
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

namespace OCA\MyDash\Command;

use InvalidArgumentException;
use OCA\MyDash\Service\ConfluenceImportService;
use OCA\MyDash\Service\DashboardTreeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `mydash:import:confluence` console command.
 */
class ImportConfluenceCommand extends Command
{
    /**
     * Constructor.
     *
     * @param ConfluenceImportService $importService Importer.
     * @param DashboardTreeService    $treeService   Path resolver.
     */
    public function __construct(
        private readonly ConfluenceImportService $importService,
        private readonly DashboardTreeService $treeService,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure CLI options.
     *
     * @return void
     *
     * @spec openspec/specs/confluence-html-import/spec.md
     */
    protected function configure(): void
    {
        $this->setName(name: 'mydash:import:confluence')
            ->setDescription(description: 'Import a Confluence HTML export ZIP into MyDash dashboards.')
            ->addOption(
                name: 'file',
                shortcut: 'f',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Path to the Confluence HTML export ZIP archive.'
            )
            ->addOption(
                name: 'parent-path',
                shortcut: 'p',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Slug-chain path under which root pages should be slotted.'
            )
            ->addOption(
                name: 'user',
                shortcut: 'u',
                mode: InputOption::VALUE_REQUIRED,
                description: 'User ID to attribute the imported dashboards to.',
                default: 'cli'
            )
            ->addOption(
                name: 'dry-run',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Inspect the archive without creating any dashboards.'
            );
    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Exit code (0 success, 1 failure).
     *
     * @spec openspec/specs/confluence-html-import/spec.md
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $file       = (string) ($input->getOption(name: 'file') ?? '');
        $parentPath = (string) ($input->getOption(name: 'parent-path') ?? '');
        $userId     = (string) ($input->getOption(name: 'user') ?? 'cli');
        $isDryRun   = (bool) $input->getOption(name: 'dry-run');

        if ($file === '') {
            $output->writeln(messages: '<error>--file parameter is required</error>');
            return self::FAILURE;
        }

        if (file_exists(filename: $file) === false) {
            $output->writeln(messages: '<error>File not found: '.$file.'</error>');
            return self::FAILURE;
        }

        $parentUuid = null;
        if ($parentPath !== '') {
            $parent = $this->treeService->resolvePath(path: $parentPath);
            if ($parent === null) {
                $output->writeln(
                    messages: '<error>Parent path not found: '.$parentPath.'</error>'
                );
                return self::FAILURE;
            }

            $parentUuid = $parent->getUuid();
        }

        try {
            if ($isDryRun === true) {
                return $this->runDryRun(file: $file, output: $output);
            }

            return $this->runImport(
                file: $file,
                userId: $userId,
                parentUuid: $parentUuid,
                output: $output
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln(messages: '<error>'.$e->getMessage().'</error>');
            return self::FAILURE;
        } catch (Throwable $e) {
            $output->writeln(messages: '<error>Import failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }
    }//end execute()

    /**
     * Run a dry-run preview.
     *
     * @param string          $file   ZIP path.
     * @param OutputInterface $output CLI output.
     *
     * @return int Exit code.
     */
    private function runDryRun(string $file, OutputInterface $output): int
    {
        $result = $this->importService->dryRun(zipPath: $file);

        $summary = sprintf(
            'Pages: %d, attachments: %d, estimated dashboards: %d, asset folder: %s',
            (int) $result['pageCount'],
            (int) $result['attachmentCount'],
            (int) $result['estimatedDashboards'],
            (string) $result['assetFolder']
        );

        $output->writeln(messages: $summary);

        foreach ($result['warnings'] as $warning) {
            $output->writeln(messages: '<comment>warning: '.$warning.'</comment>');
        }

        return self::SUCCESS;
    }//end runDryRun()

    /**
     * Run a full import.
     *
     * @param string          $file       ZIP path.
     * @param string          $userId     Importing user UID.
     * @param string|null     $parentUuid Optional parent dashboard UUID.
     * @param OutputInterface $output     CLI output.
     *
     * @return int Exit code.
     */
    private function runImport(
        string $file,
        string $userId,
        ?string $parentUuid,
        OutputInterface $output
    ): int {
        $result = $this->importService->import(
            zipPath: $file,
            currentUserId: $userId,
            parentUuid: $parentUuid
        );

        $summary = sprintf(
            'Imported %d dashboards, skipped %d, errors: %d, asset folder: %s',
            (int) $result['createdDashboardCount'],
            (int) $result['skippedPageCount'],
            count(value: $result['errors']),
            (string) $result['assetFolder']
        );

        $output->writeln(messages: $summary);

        foreach ($result['errors'] as $err) {
            $output->writeln(
                messages: ' - '.$err['pageId'].': '.$err['reason']
            );
        }

        foreach ($result['warnings'] as $warning) {
            $output->writeln(messages: '<comment>warning: '.$warning.'</comment>');
        }

        return self::SUCCESS;
    }//end runImport()
}//end class
