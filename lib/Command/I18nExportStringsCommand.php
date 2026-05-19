<?php

/**
 * I18nExportStringsCommand
 *
 * `mydash:i18n:export-strings` — extract translatable strings from
 * `lib/` (PHP) and `src/` (Vue/JS) into `l10n/mydash.pot` (REQ-CLI-005).
 *
 * The implementation is intentionally minimal — it scans for the four
 * standard markers (`t(`, `n(`, `$l->t(`, `$this->l->t(`) so the
 * scaffolding is in place; richer extraction (xgettext-equivalent
 * features) is an explicit follow-up.
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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:i18n:export-strings` console command.
 */
class I18nExportStringsCommand extends CommandBase
{
    /**
     * Translation marker patterns. Each entry produces a captured
     * single- or double-quoted string literal.
     *
     * @var list<string>
     */
    private const MARKER_PATTERNS = [
        '/->t\(\s*[\'"]([^\'"]+)[\'"]/',
        '/\bt\(\s*[\'"]([^\'"]+)[\'"]/',
        '/\bn\(\s*[\'"]([^\'"]+)[\'"]/',
    ];

    /**
     * Constructor.
     *
     * @param CommandService $commandService Shared CLI helper.
     * @param IUserSession   $userSession    Caller resolution.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession
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
        $this->setName(name: 'mydash:i18n:export-strings')
            ->setDescription(description: 'Extract translatable strings to l10n/mydash.pot.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'Scan lib/ and src/ for translatable strings and write them to l10n/mydash.pot.',
                        'Idempotent — overwrites the existing POT file.',
                        '',
                        'Examples:',
                        '  php occ mydash:i18n:export-strings',
                        '  php occ mydash:i18n:export-strings --json',
                    ]
                )
            );
    }//end configureCommand()

    /**
     * Execute the extraction.
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
        $appRoot = (string) realpath(path: __DIR__.'/../..');
        if ($appRoot === '') {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_ERROR,
                code: 'INTERNAL_ERROR',
                message: 'Could not resolve app root directory.'
            );
        }

        $strings = [];
        foreach (['lib', 'src'] as $sub) {
            $path = $appRoot.'/'.$sub;
            if (is_dir(filename: $path) === false) {
                continue;
            }

            $this->collectFromDir(directory: $path, sink: $strings);
        }

        ksort(array: $strings);
        $this->writePot(strings: array_keys(array: $strings), outPath: $appRoot.'/l10n/mydash.pot');

        $this->emitSuccess(
            input: $input,
            output: $output,
            data: ['count' => count(value: $strings), 'output' => 'l10n/mydash.pot'],
            human: 'Wrote '.count(value: $strings).' strings to l10n/mydash.pot'
        );

        return CommandService::EXIT_SUCCESS;
    }//end handle()

    /**
     * Recursively scan `$directory` for translatable markers and
     * accumulate them into `$sink` (using the string as key for
     * dedup).
     *
     * @param string             $directory Directory to scan.
     * @param array<string,bool> $sink      Accumulator (modified by reference).
     *
     * @return void
     */
    private function collectFromDir(string $directory, array &$sink): void
    {
        $iterator = new RecursiveIteratorIterator(
            iterator: new RecursiveDirectoryIterator(
                $directory,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() === false) {
                continue;
            }

            $ext = strtolower(string: $file->getExtension());
            if (in_array(needle: $ext, haystack: ['php', 'vue', 'js', 'ts'], strict: true) === false) {
                continue;
            }

            $contents = (string) file_get_contents(filename: $file->getPathname());
            foreach (self::MARKER_PATTERNS as $pattern) {
                $matches = [];
                $found   = preg_match_all(pattern: $pattern, subject: $contents, matches: $matches);
                if ($found === false || $found === 0) {
                    continue;
                }

                foreach ($matches[1] as $string) {
                    $sink[(string) $string] = true;
                }
            }
        }//end foreach
    }//end collectFromDir()

    /**
     * Write the POT file at `$outPath`. Missing parent directories
     * are created.
     *
     * @param list<string> $strings Sorted, unique source strings.
     * @param string       $outPath Target file path.
     *
     * @return void
     */
    private function writePot(array $strings, string $outPath): void
    {
        $dir = dirname(path: $outPath);
        if (is_dir(filename: $dir) === false) {
            mkdir(directory: $dir, permissions: 0775, recursive: true);
        }

        $lines   = [];
        $lines[] = '# MyDash translatable strings — generated by `mydash:i18n:export-strings`.';
        $lines[] = 'msgid ""';
        $lines[] = 'msgstr ""';
        $lines[] = '"Content-Type: text/plain; charset=UTF-8\n"';
        $lines[] = '';
        foreach ($strings as $string) {
            $escaped = str_replace(search: ['\\', '"'], replace: ['\\\\', '\\"'], subject: $string);
            $lines[] = 'msgid "'.$escaped.'"';
            $lines[] = 'msgstr ""';
            $lines[] = '';
        }

        file_put_contents(filename: $outPath, data: implode(separator: "\n", array: $lines));
    }//end writePot()
}//end class
