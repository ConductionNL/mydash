<?php

/**
 * CleanupScanCommand
 *
 * `occ mydash:cleanup:scan` — REQ-CLN-001. Reports the per-category
 * orphan counts as a table on stdout. Exits 0 when no orphans exist
 * and 1 when at least one row is found, so CI scripts can use the
 * exit code as a fail signal.
 *
 * Read-only: this command never deletes data; the destructive
 * counterpart is {@see CleanupPurgeCommand}.
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

use OCA\MyDash\Service\OrphanedDataCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:cleanup:scan` CLI command.
 */
class CleanupScanCommand extends Command
{
    /**
     * Constructor.
     *
     * @param OrphanedDataCleanupService $cleanupService The orchestrator.
     */
    public function __construct(
        private readonly OrphanedDataCleanupService $cleanupService,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure the command name + description.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'mydash:cleanup:scan')
            ->setDescription(
                description: 'Scan MyDash storage for orphans by category. Exits non-zero when any are found.'
            );
    }//end configure()

    /**
     * Execute the scan.
     *
     * @param InputInterface  $input  The console input.
     * @param OutputInterface $output The console output.
     *
     * @return int 0 when no orphans, 1 otherwise.
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $result = $this->cleanupService->scan();

        $table = new Table(output: $output);
        $table->setHeaders(headers: ['Category', 'Count']);

        foreach ($result->getByCategory() as $name => $count) {
            $table->addRow(row: [$name, (string) $count]);
        }

        $table->addRow(row: ['<info>TOTAL</info>', (string) $result->getTotalRows()]);
        $table->render();

        $skipped = $result->getSkipped();
        if (count(value: $skipped) > 0) {
            $output->writeln(
                messages: sprintf(
                    '<comment>Skipped categories (feature unavailable): %s</comment>',
                    implode(separator: ', ', array: $skipped)
                )
            );
        }

        $output->writeln(
            messages: sprintf(
                '<info>Scan completed in %dms.</info>',
                $result->getDurationMs()
            )
        );

        if ($result->getTotalRows() === 0) {
            return 0;
        }

        return 1;
    }//end execute()
}//end class
