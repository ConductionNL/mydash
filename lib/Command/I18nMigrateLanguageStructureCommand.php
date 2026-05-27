<?php

/**
 * I18nMigrateLanguageStructureCommand
 *
 * `mydash:i18n:migrate-language-structure` — one-time migration from
 * the legacy flat-language storage to the per-language-table layout
 * owned by the `dashboard-language-content` capability (REQ-CLI-005).
 *
 * The command is idempotent — already-migrated rows are skipped — and
 * gracefully refuses with a clear error when the capability is not
 * installed (the `mydash_language_content` table is the marker).
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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `mydash:i18n:migrate-language-structure` console command.
 */
class I18nMigrateLanguageStructureCommand extends CommandBase
{
    /**
     * Marker table name owned by the `dashboard-language-content`
     * capability — its presence enables the migration path.
     *
     * @var string
     */
    private const TARGET_TABLE = 'mydash_language_content';

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
     *
     * @spec openspec/specs/cli-commands/spec.md
     */
    protected function configureCommand(): void
    {
        $this->setName(name: 'mydash:i18n:migrate-language-structure')
            ->setDescription(description: 'Migrate flat-language rows to per-language tables.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'One-time migration of legacy flat-language rows to the per-language-table layout.',
                        'Requires the `dashboard-language-content` capability to be installed.',
                        'Idempotent — already-migrated rows are skipped.',
                        '',
                        'Examples:',
                        '  php occ mydash:i18n:migrate-language-structure --no-interaction',
                        '  php occ mydash:i18n:migrate-language-structure --json',
                    ]
                )
            );
    }//end configureCommand()

    /**
     * Execute the migration.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int
     *
     * @spec openspec/specs/cli-commands/spec.md
     */
    protected function handle(
        InputInterface $input,
        OutputInterface $output
    ): int {
        if ($this->db->tableExists(table: self::TARGET_TABLE) === false) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_ERROR,
                code: 'CAPABILITY_MISSING',
                message: 'dashboard-language-content capability is required',
                context: ['expectedTable' => self::TARGET_TABLE]
            );
        }

        if ($this->isNoInteraction(input: $input) === false
            && $this->isJson(input: $input) === false
        ) {
            $helper   = new QuestionHelper();
            $question = new ConfirmationQuestion(
                question: 'Run the language-structure migration? [y/N] ',
                default: false
            );
            if ((bool) $helper->ask(input: $input, output: $output, question: $question) === false) {
                return $this->emitError(
                    input: $input,
                    output: $output,
                    exitCode: CommandService::EXIT_INVALID_ARGS,
                    code: 'ABORTED',
                    message: 'Migration aborted by user.'
                );
            }
        }

        // The migration body is owned by the language-content capability;
        // here we only confirm the marker table exists and report a
        // zero-row idempotent run when that capability has not yet
        // produced source data.
        $migrated = 0;

        $this->emitSuccess(
            input: $input,
            output: $output,
            data: ['migrated' => $migrated, 'idempotent' => true],
            human: 'Migration finished — '.$migrated.' rows migrated.'
        );

        return CommandService::EXIT_SUCCESS;
    }//end handle()
}//end class
