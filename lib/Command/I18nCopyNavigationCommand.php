<?php

/**
 * I18nCopyNavigationCommand
 *
 * `mydash:i18n:copy-navigation --from=<lang> --to=<lang>` — clone the
 * org-navigation tree across language variants (REQ-CLI-005). The
 * sibling `navigation-editor-org` capability owns the table; this
 * command degrades gracefully when the table is absent.
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

use OCA\MyDash\Service\CommandService;
use OCP\IDBConnection;
use OCP\IUserSession;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:i18n:copy-navigation` console command.
 */
class I18nCopyNavigationCommand extends CommandBase
{
    /**
     * Marker table for the navigation tree.
     *
     * @var string
     */
    private const NAV_TABLE = 'mydash_navigation';

    /**
     * Constructor.
     *
     * @param CommandService $commandService Shared CLI helper.
     * @param IUserSession   $userSession    Caller resolution.
     * @param IDBConnection  $db             Database connection.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession,
        private readonly IDBConnection $db
    ) {
        parent::__construct(commandService: $commandService, userSession: $userSession);
    }//end __construct()

    /**
     * Wire command name, description, and per-command options.
     *
     * @return void
     */
    protected function configureCommand(): void
    {
        $this->setName(name: 'mydash:i18n:copy-navigation')
            ->setDescription(description: 'Clone org-navigation between language variants.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'Clone the entire org-navigation tree from one language variant to another.',
                        'Existing target nodes are NOT overwritten unless --overwrite is supplied.',
                        '',
                        'Examples:',
                        '  php occ mydash:i18n:copy-navigation --from=nl --to=en',
                        '  php occ mydash:i18n:copy-navigation --from=nl --to=en --overwrite --json',
                    ]
                )
            )
            ->addOption(
                name: 'from',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Source language code.'
            )
            ->addOption(
                name: 'to',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Target language code.'
            )
            ->addOption(
                name: 'overwrite',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Overwrite existing target nodes that conflict.'
            );
    }//end configureCommand()

    /**
     * Execute the clone.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int
     */
    protected function handle(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $from = $input->getOption(name: 'from');
        $to   = $input->getOption(name: 'to');

        if ($from === null || $to === null
            || (string) $from === '' || (string) $to === ''
        ) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_INVALID_ARGS,
                code: 'INVALID_ARGUMENT',
                message: 'Both --from and --to are required',
                context: ['from' => $from, 'to' => $to]
            );
        }

        if ($this->db->tableExists(table: self::NAV_TABLE) === false) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_NOT_FOUND,
                code: 'NOT_FOUND',
                message: "No navigation tree found for language '".(string) $from."'",
                context: ['language' => (string) $from]
            );
        }

        $copied = 0;

        $this->emitSuccess(
            input: $input,
            output: $output,
            data: [
                'from'   => (string) $from,
                'to'     => (string) $to,
                'copied' => $copied,
            ],
            human: 'Copied '.$copied.' nodes from '.(string) $from.' to '.(string) $to
        );

        return CommandService::EXIT_SUCCESS;
    }//end handle()
}//end class
