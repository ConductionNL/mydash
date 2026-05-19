<?php

/**
 * DemoShowcasesInstallCommand
 *
 * `php occ mydash:demo-showcases:install <id> [--lang=nl] [--force]`
 *
 * Mirrors the `POST /api/admin/demo-showcases/{id}/install` endpoint
 * for ops convenience. Idempotent unless `--force` is passed; in that
 * case the existing dashboard is removed and reinstalled (REQ-DEMO-009).
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

use OCA\MyDash\Exception\ShowcaseNotFoundException;
use OCA\MyDash\Service\DemoShowcasesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `mydash:demo-showcases:install` console command.
 */
class DemoShowcasesInstallCommand extends Command
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
        $this->setName(name: 'mydash:demo-showcases:install')
            ->setDescription(description: 'Install a bundled MyDash demo showcase dashboard.')
            ->addArgument(
                name: 'id',
                mode: InputArgument::REQUIRED,
                description: 'Showcase ID (e.g. de-bron, gemeente-duin).'
            )
            ->addOption(
                name: 'lang',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Locale (forward-compatible; v1 always resolves to nl).',
                default: 'nl'
            )
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Reinstall even if the showcase is already installed.'
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
        $id    = (string) $input->getArgument(name: 'id');
        $lang  = (string) ($input->getOption(name: 'lang') ?? 'nl');
        $force = (bool) $input->getOption(name: 'force');

        try {
            $result = $this->showcases->installShowcase(
                showcaseId: $id,
                lang: $lang,
                force: $force
            );
        } catch (ShowcaseNotFoundException) {
            $output->writeln(messages: '<error>Showcase not found: '.$id.'</error>');
            return self::FAILURE;
        } catch (Throwable $e) {
            $output->writeln(messages: '<error>Installation failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

        if ($result['alreadyInstalled'] === true) {
            $output->writeln(
                messages: 'Showcase '.$id.' is already installed (UUID: '.$result['installedDashboardUuid'].').'
            );
            $output->writeln(messages: 'Use --force to reinstall.');
            return self::SUCCESS;
        }

        $output->writeln(
            messages: 'Installed dashboard '.$result['installedDashboardUuid']
        );

        $skipped = $result['skippedWidgets'];
        if ($skipped !== []) {
            $output->writeln(
                messages: '<comment>Skipped unknown widgets: '.implode(separator: ', ', array: $skipped).'</comment>'
            );
        }

        return self::SUCCESS;
    }//end execute()
}//end class
