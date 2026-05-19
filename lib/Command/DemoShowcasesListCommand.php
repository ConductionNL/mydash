<?php

/**
 * DemoShowcasesListCommand
 *
 * `php occ mydash:demo-showcases:list [--json]`
 *
 * Lists every bundled showcase with its installation status. Supports
 * a `--json` flag for machine-parseable output (REQ-DEMO-009).
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

use OCA\MyDash\Service\DemoShowcasesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:demo-showcases:list` console command.
 */
class DemoShowcasesListCommand extends Command
{
    /**
     * Constructor.
     *
     * @param DemoShowcasesService $showcases Showcase service.
     */
    public function __construct(
        private readonly DemoShowcasesService $showcases,
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
        $this->setName(name: 'mydash:demo-showcases:list')
            ->setDescription(description: 'List every bundled MyDash demo showcase.')
            ->addOption(
                name: 'json',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Emit machine-parseable JSON instead of a table.'
            );
    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Exit code.
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $showcases = $this->showcases->getAvailableShowcases();

        if ((bool) $input->getOption(name: 'json') === true) {
            $output->writeln(
                messages: (string) json_encode(
                    value: $showcases,
                    flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                )
            );
            return self::SUCCESS;
        }

        $table = new Table(output: $output);
        $table->setHeaders(headers: ['ID', 'Name', 'Language', 'Status', 'Dashboard UUID']);

        foreach ($showcases as $showcase) {
            $status = 'Not installed';
            if ($showcase['isInstalled'] === true) {
                $status = 'Installed';
            }

            $table->addRow(
                row: [
                    $showcase['id'],
                    $showcase['name'],
                    $showcase['language'],
                    $status,
                    (string) ($showcase['installedDashboardUuid'] ?? '-'),
                ]
            );
        }

        $table->render();
        return self::SUCCESS;
    }//end execute()
}//end class
