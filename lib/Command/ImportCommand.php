<?php

/**
 * ImportCommand
 *
 * `php occ mydash:import` — restore dashboards from a versioned ZIP
 * archive on disk. Implements REQ-EXIM-010.
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

use InvalidArgumentException;
use OCA\MyDash\Service\ImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `mydash:import` console command.
 */
class ImportCommand extends Command
{
    /**
     * Constructor.
     *
     * @param ImportService $importService Import service.
     */
    public function __construct(
        private readonly ImportService $importService,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure CLI options.
     *
     * @return void
     */
    /** @spec openspec/specs/dashboard-export-import/spec.md */
    protected function configure(): void
    {
        $this->setName(name: 'mydash:import')
            ->setDescription(description: 'Import MyDash dashboards from a versioned ZIP archive.')
            ->addOption(
                name: 'file',
                shortcut: 'f',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Path to the ZIP archive to import.'
            )
            ->addOption(
                name: 'preserve-uuids',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Preserve dashboard UUIDs (fail on collision).'
            )
            ->addOption(
                name: 'user',
                shortcut: 'u',
                mode: InputOption::VALUE_REQUIRED,
                description: 'User ID to attribute the import to (defaults to "cli").',
                default: 'cli'
            );
    }//end configure()

    /**
     * Execute the import.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Exit code (0 success, 1 error).
     */
    /** @spec openspec/specs/dashboard-export-import/spec.md */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $file          = (string) ($input->getOption(name: 'file') ?? '');
        $preserveUuids = (bool) $input->getOption(name: 'preserve-uuids');
        $user          = (string) ($input->getOption(name: 'user') ?? 'cli');

        if ($file === '') {
            $output->writeln(messages: '<error>--file parameter is required</error>');
            return self::FAILURE;
        }

        if (file_exists(filename: $file) === false) {
            $output->writeln(messages: '<error>File not found: '.$file.'</error>');
            return self::FAILURE;
        }

        try {
            $result = $this->importService->import(
                zipPath: $file,
                preserveUuids: $preserveUuids,
                currentUserId: $user
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln(messages: '<error>'.$e->getMessage().'</error>');
            return self::FAILURE;
        } catch (Throwable $e) {
            $output->writeln(messages: '<error>Import failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

        if ($result['status'] === ImportService::ERR_UUID_COLLISION) {
            $output->writeln(messages: '<error>UUID collisions detected (--preserve-uuids):</error>');
            foreach ($result['errors'] as $err) {
                $msg = (string) ($err['message'] ?? 'collision');
                $output->writeln(messages: ' - '.$msg);
            }

            return self::FAILURE;
        }

        $imported = $result['importedDashboardCount'];
        $skipped  = $result['skippedDashboardCount'];
        $errors   = $result['errors'];

        $head = 'Imported '.(string) $imported.' dashboards, ';
        $tail = 'skipped '.(string) $skipped.', errors: '.(string) count($errors);
        $output->writeln(messages: ($head.$tail));

        foreach ($errors as $err) {
            $msg = (string) ($err['message'] ?? '');
            if ($msg !== '') {
                $output->writeln(messages: ' - '.$msg);
            }
        }

        return self::SUCCESS;
    }//end execute()
}//end class
