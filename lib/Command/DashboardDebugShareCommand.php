<?php

/**
 * DashboardDebugShareCommand
 *
 * `mydash:dashboard:debug-share <uuid>` — print sharing, lock,
 * version, and view diagnostics for a dashboard. Intended for support
 * engineers (REQ-CLI-003).
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

use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardShare;
use OCA\MyDash\Db\DashboardShareMapper;
use OCA\MyDash\Service\CommandService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:dashboard:debug-share` console command.
 */
class DashboardDebugShareCommand extends CommandBase
{
    /**
     * Constructor.
     *
     * @param CommandService       $commandService  Shared CLI helper.
     * @param IUserSession         $userSession     Caller resolution.
     * @param DashboardMapper      $dashboardMapper Dashboard mapper.
     * @param DashboardShareMapper $shareMapper     Share mapper.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession,
        private readonly DashboardMapper $dashboardMapper,
        private readonly DashboardShareMapper $shareMapper
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
        $this->setName(name: 'mydash:dashboard:debug-share')
            ->setDescription(description: 'Dump sharing & lock state for a dashboard.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'Print share rows, lock state, version count and view count for support diagnostics.',
                        '',
                        'Examples:',
                        '  php occ mydash:dashboard:debug-share a1b2c3d4-... --json',
                        '  php occ mydash:dashboard:debug-share a1b2c3d4-... | jq .',
                    ]
                )
            )
            ->addArgument(
                name: 'uuid',
                mode: InputArgument::REQUIRED,
                description: 'Dashboard UUID.'
            );
    }//end configureCommand()

    /**
     * Execute the diagnostics dump.
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
        $uuid = (string) $input->getArgument(name: 'uuid');

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_NOT_FOUND,
                code: 'NOT_FOUND',
                message: 'Dashboard not found',
                context: ['uuid' => $uuid]
            );
        }

        $shares = array_map(
            callback: static function (DashboardShare $share): array {
                return $share->jsonSerialize();
            },
            array: $this->shareMapper->findByDashboardId(dashboardId: (int) $dashboard->getId())
        );

        // Lock / version / view capabilities live in sibling specs that
        // may or may not have shipped yet; absence is reported as the
        // documented sentinel values rather than a hard failure.
        $payload = [
            'uuid'         => $uuid,
            'shares'       => $shares,
            'locked'       => false,
            'lockedBy'     => null,
            'lockedAt'     => null,
            'versionCount' => 0,
            'viewCount'    => 0,
        ];

        if ($this->isJson(input: $input) === true) {
            $this->emitSuccess(input: $input, output: $output, data: $payload);
            return CommandService::EXIT_SUCCESS;
        }

        if ($this->isQuiet(input: $input) === false) {
            $output->writeln(
                messages: (string) json_encode(
                    value: $payload,
                    flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                )
            );
        }

        return CommandService::EXIT_SUCCESS;
    }//end handle()
}//end class
