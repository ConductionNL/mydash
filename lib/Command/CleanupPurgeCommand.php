<?php

/**
 * CleanupPurgeCommand
 *
 * `occ mydash:cleanup:purge` — REQ-CLN-002. Deletes orphaned rows in
 * one or more categories. Defaults to interactive confirmation; the
 * `--yes` flag skips the prompt for non-interactive use (cron, CI).
 * `--dry-run` runs the same code path under a transaction rollback
 * so the operator can preview impact without modifying data
 * (REQ-CLN-003).
 *
 * Examples:
 *   occ mydash:cleanup:purge
 *   occ mydash:cleanup:purge --dry-run
 *   occ mydash:cleanup:purge --category=expired_locks --yes
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

use OCA\MyDash\Service\Cleanup\CategoryRegistryService;
use OCA\MyDash\Service\OrphanedDataCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `mydash:cleanup:purge` CLI command.
 */
class CleanupPurgeCommand extends Command
{
    /**
     * Constructor.
     *
     * @param OrphanedDataCleanupService $cleanupService The orchestrator.
     * @param CategoryRegistryService    $registry       Category registry
     *                                                   (for the
     *                                                   unknown-name
     *                                                   error path).
     */
    public function __construct(
        private readonly OrphanedDataCleanupService $cleanupService,
        private readonly CategoryRegistryService $registry,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure the command name, description and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'mydash:cleanup:purge')
            ->setDescription(
                description: 'Delete orphaned MyDash data. See options for dry-run and per-category limits.'
            )
            ->addOption(
                name: 'category',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Limit to one category by name. Default is all.'
            )
            ->addOption(
                name: 'dry-run',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Wrap deletes in a rolled-back transaction.'
            )
            ->addOption(
                name: 'yes',
                shortcut: 'y',
                mode: InputOption::VALUE_NONE,
                description: 'Skip the interactive confirmation prompt. Required for cron / CI use.'
            );
    }//end configure()

    /**
     * Execute the purge.
     *
     * @param InputInterface  $input  The console input.
     * @param OutputInterface $output The console output.
     *
     * @return int 0 on success, 1 on validation failure.
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $categoryOption = $input->getOption(name: 'category');
        $dryRun         = (bool) $input->getOption(name: 'dry-run');
        $assumeYes      = (bool) $input->getOption(name: 'yes');

        $categoryNames = [];
        if (is_string(value: $categoryOption) === true && $categoryOption !== '') {
            if ($this->registry->getCategoryByName(name: $categoryOption) === null) {
                $output->writeln(
                    messages: sprintf(
                        '<error>Unknown cleanup category: %s</error>',
                        $categoryOption
                    )
                );
                $output->writeln(
                    messages: sprintf(
                        'Valid categories: %s',
                        implode(separator: ', ', array: $this->registry->getCategoryNames())
                    )
                );

                return 1;
            }

            $categoryNames = [$categoryOption];
        }

        $effectiveCategories = $categoryNames;
        if (count(value: $effectiveCategories) === 0) {
            $effectiveCategories = $this->registry->getCategoryNames();
        }

        if ($assumeYes === false) {
            $helper = $this->getHelper(name: 'question');
            if ($helper instanceof QuestionHelper) {
                $question = new ConfirmationQuestion(
                    question: sprintf(
                        'Delete orphaned data in categories: [%s]? (y/N) ',
                        implode(separator: ', ', array: $effectiveCategories)
                    ),
                    default: false
                );

                if ($helper->ask(input: $input, output: $output, question: $question) === false) {
                    $output->writeln(messages: 'Purge cancelled.');
                    return 0;
                }
            }
        }

        $result = $this->cleanupService->purge(
            categoryNames: $categoryNames,
            dryRun: $dryRun,
            userId: null,
            source: 'cli',
        );

        $prefix = 'Purged';
        if ($dryRun === true) {
            $prefix = 'DRY-RUN: Would purge';
        }

        $summaryMessage = sprintf(
            '<info>%s %d items across %d categories in %dms.</info>',
            $prefix,
            $result->getTotalRows(),
            count(value: $result->getByCategory()),
            $result->getDurationMs()
        );
        if (count(value: $categoryNames) === 1) {
            $summaryMessage = sprintf(
                '<info>%s %d items from category \'%s\' in %dms.</info>',
                $prefix,
                $result->getTotalRows(),
                $categoryNames[0],
                $result->getDurationMs()
            );
        }

        $output->writeln(messages: $summaryMessage);

        $skipped = $result->getSkipped();
        if (count(value: $skipped) > 0) {
            $output->writeln(
                messages: sprintf(
                    '<comment>Skipped categories: %s</comment>',
                    implode(separator: ', ', array: $skipped)
                )
            );
        }

        return 0;
    }//end execute()
}//end class
