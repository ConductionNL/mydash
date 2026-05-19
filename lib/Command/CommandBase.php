<?php

/**
 * CommandBase
 *
 * Abstract base class for every `mydash:*` console command
 * (REQ-CLI-002, REQ-CLI-008). Provides:
 *   - registration of the three global flags `--quiet|-q`, `--json`,
 *     `--no-interaction|-n`,
 *   - a `runCommand()` template method that wraps a child's
 *     `handle()` with timing, JSON envelope writing, and the
 *     audit-log line required by REQ-CLI-010,
 *   - small helpers (`emitJson`, `errorJson`, `confirm`) so children
 *     stay focused on business logic.
 *
 * Children only override {@see configureCommand()} and
 * {@see handle()} — they MUST NOT redefine `--quiet`, `--json`, or
 * `--no-interaction` (Symfony will error on duplicate registration).
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
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Abstract base for MyDash CLI commands (REQ-CLI-002).
 */
abstract class CommandBase extends Command
{
    /**
     * Constructor.
     *
     * @param CommandService $commandService Shared exit-code, JSON and
     *                                       audit-log helper.
     * @param IUserSession   $userSession    Caller resolution for the
     *                                       audit log (REQ-CLI-010).
     */
    public function __construct(
        protected readonly CommandService $commandService,
        private readonly IUserSession $userSession
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Wire the three global flags shared by every `mydash:*` command
     * (REQ-CLI-002), then defer to the child for per-command options.
     *
     * @return void
     */
    final protected function configure(): void
    {
        $this->addOption(
            name: 'json',
            shortcut: null,
            mode: InputOption::VALUE_NONE,
            description: 'Emit a single JSON envelope on stdout (REQ-CLI-007).'
        );
        $this->addOption(
            name: 'quiet',
            shortcut: 'q',
            mode: InputOption::VALUE_NONE,
            description: 'Suppress non-essential output. Errors still go to stderr.'
        );
        $this->addOption(
            name: 'no-interaction',
            shortcut: 'n',
            mode: InputOption::VALUE_NONE,
            description: 'Skip confirmation prompts (assume yes) — for CI/automation.'
        );

        $this->configureCommand();
    }//end configure()

    /**
     * Hook for subclasses to declare name, description, arguments and
     * extra options. The three global flags are registered by
     * {@see configure()}; subclasses MUST NOT re-declare them.
     *
     * @return void
     */
    abstract protected function configureCommand(): void;

    /**
     * Execute the command's business logic.
     *
     * Returning an exit code MUST use one of the
     * {@see CommandService}::EXIT_* constants.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output (use the helpers on
     *                                this class to honour `--quiet`
     *                                and `--json`).
     *
     * @return int
     */
    abstract protected function handle(
        InputInterface $input,
        OutputInterface $output
    ): int;

    /**
     * Symfony entry point — wraps {@see handle()} with timing, JSON
     * envelope on uncaught exception, and the audit log line
     * (REQ-CLI-010).
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int
     */
    final protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $started  = (int) round(num: (microtime(as_float: true) * 1000));
        $exitCode = CommandService::EXIT_ERROR;
        try {
            $exitCode = $this->handle(input: $input, output: $output);
        } catch (Throwable $e) {
            $exitCode = CommandService::EXIT_ERROR;
            $envelope = $this->commandService->envelopeError(
                exitCode: $exitCode,
                code: 'INTERNAL_ERROR',
                message: $e->getMessage(),
                context: ['exceptionClass' => $e::class]
            );
            if ($this->isJson(input: $input) === true) {
                $output->writeln(messages: $this->commandService->encodeEnvelope(envelope: $envelope));
            } else {
                $this->writeError(output: $output, message: '<error>'.$e->getMessage().'</error>');
            }
        } finally {
            $finished = (int) round(num: (microtime(as_float: true) * 1000));
            $this->commandService->audit(
                command: $this->stripPrefix(name: (string) $this->getName()),
                args: $this->collectArgsForAudit(input: $input),
                exitCode: $exitCode,
                durationMs: ($finished - $started),
                byUser: $this->resolveByUser()
            );
        }//end try

        return $exitCode;
    }//end execute()

    /**
     * Whether the caller asked for JSON output.
     *
     * @param InputInterface $input The CLI input.
     *
     * @return boolean
     */
    final protected function isJson(InputInterface $input): bool
    {
        return (bool) $input->getOption(name: 'json');
    }//end isJson()

    /**
     * Whether the caller asked for quiet output.
     *
     * @param InputInterface $input The CLI input.
     *
     * @return boolean
     */
    final protected function isQuiet(InputInterface $input): bool
    {
        return (bool) $input->getOption(name: 'quiet');
    }//end isQuiet()

    /**
     * Whether prompts should be suppressed (CI mode).
     *
     * @param InputInterface $input The CLI input.
     *
     * @return boolean
     */
    final protected function isNoInteraction(InputInterface $input): bool
    {
        return (bool) $input->getOption(name: 'no-interaction');
    }//end isNoInteraction()

    /**
     * Emit a successful payload as either JSON envelope (when `--json`)
     * or as the supplied human-readable text (skipped when `--quiet`).
     *
     * @param InputInterface                       $input  CLI input.
     * @param OutputInterface                      $output CLI output.
     * @param array<string,mixed>|list<mixed>|null $data   Payload.
     * @param string                               $human  Optional human-readable line.
     *
     * @return void
     */
    final protected function emitSuccess(
        InputInterface $input,
        OutputInterface $output,
        array|null $data,
        string $human=''
    ): void {
        if ($this->isJson(input: $input) === true) {
            $output->writeln(
                messages: $this->commandService->encodeEnvelope(
                    envelope: $this->commandService->envelopeSuccess(data: $data)
                )
            );
            return;
        }

        if ($human !== '' && $this->isQuiet(input: $input) === false) {
            $output->writeln(messages: $human);
        }
    }//end emitSuccess()

    /**
     * Emit an error envelope to stdout (when `--json`) or a `<error>`
     * line on stderr (always; `--quiet` does NOT mute errors per
     * REQ-CLI-002).
     *
     * @param InputInterface           $input    CLI input.
     * @param OutputInterface          $output   CLI output.
     * @param int                      $exitCode Exit code constant.
     * @param string                   $code     Stable error identifier.
     * @param string                   $message  Human-readable text.
     * @param array<string,mixed>|null $context  Optional metadata.
     *
     * @return int Echoes back the exit code for caller convenience.
     */
    final protected function emitError(
        InputInterface $input,
        OutputInterface $output,
        int $exitCode,
        string $code,
        string $message,
        array|null $context=null
    ): int {
        if ($this->isJson(input: $input) === true) {
            $output->writeln(
                messages: $this->commandService->encodeEnvelope(
                    envelope: $this->commandService->envelopeError(
                        exitCode: $exitCode,
                        code: $code,
                        message: $message,
                        context: $context
                    )
                )
            );
        } else {
            $this->writeError(output: $output, message: '<error>'.$message.'</error>');
        }

        return $exitCode;
    }//end emitError()

    /**
     * Write a line to the dedicated stderr stream when the runtime
     * `OutputInterface` actually exposes one (the production
     * `ConsoleOutput` does); fall back to the regular stream otherwise
     * (in-memory test buffers don't split stderr out).
     *
     * @param OutputInterface $output  Live output handle.
     * @param string          $message The fully decorated message.
     *
     * @return void
     */
    private function writeError(OutputInterface $output, string $message): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln(messages: $message);
            return;
        }

        $output->writeln(messages: $message);
    }//end writeError()

    /**
     * Strip the canonical `mydash:` prefix from the command name for
     * audit-log clarity (REQ-CLI-010).
     *
     * @param string $name The full command name.
     *
     * @return string
     */
    private function stripPrefix(string $name): string
    {
        if (str_starts_with(haystack: $name, needle: 'mydash:') === true) {
            return substr(string: $name, offset: 7);
        }

        return $name;
    }//end stripPrefix()

    /**
     * Build a single space-joined argv-tail string for the audit line.
     * We use the raw `$argv` so option ordering matches what the
     * operator typed (REQ-CLI-010). The Symfony `InputInterface` does
     * not expose the original argv slice, so reading `$_SERVER['argv']`
     * is intentional here.
     *
     * @param InputInterface $input CLI input (kept for future API use).
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function collectArgsForAudit(InputInterface $input): string
    {
        unset($input);

        $argv = (array) ($_SERVER['argv'] ?? []);
        // Drop the binary path and the command name (first two slots
        // when invoked via `php occ mydash:foo ...`).
        $tail = array_slice(array: $argv, offset: 2);

        return implode(separator: ' ', array: array_map(callback: 'strval', array: $tail));
    }//end collectArgsForAudit()

    /**
     * Resolve the caller user id for the audit log. Returns `null` to
     * indicate the special `cli` sentinel when no Nextcloud session is
     * active (typical for cron / shell invocations) — REQ-CLI-010.
     *
     * @return string|null
     */
    private function resolveByUser(): string|null
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return null;
            }

            $uid = $user->getUID();
            if ($uid === '') {
                return null;
            }

            return $uid;
        } catch (Throwable) {
            return null;
        }
    }//end resolveByUser()
}//end class
