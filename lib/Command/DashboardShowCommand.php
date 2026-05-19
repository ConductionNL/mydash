<?php

/**
 * DashboardShowCommand
 *
 * `mydash:dashboard:show <uuid>` — print the full configuration of a
 * single dashboard (metadata + widget tree) as JSON (REQ-CLI-003).
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
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\CommandService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:dashboard:show` console command.
 */
class DashboardShowCommand extends CommandBase
{
    /**
     * Pattern matching a UUID v4 (the format MyDash mints) — accepts
     * the relaxed v* variant so older fixtures still validate.
     *
     * @var string
     */
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Constructor.
     *
     * @param CommandService        $commandService  Shared CLI helper.
     * @param IUserSession          $userSession     Caller resolution.
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget mapper.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession,
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper
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
        $this->setName(name: 'mydash:dashboard:show')
            ->setDescription(description: 'Display full dashboard configuration.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'Display the full configuration of a single dashboard, including the widget tree.',
                        '',
                        'Examples:',
                        '  php occ mydash:dashboard:show a1b2c3d4-e5f6-4789-abcd-ef1234567890',
                        '  php occ mydash:dashboard:show a1b2c3d4-e5f6-4789-abcd-ef1234567890 --json',
                    ]
                )
            )
            ->addArgument(
                name: 'uuid',
                mode: InputArgument::REQUIRED,
                description: 'The dashboard UUID.'
            );
    }//end configureCommand()

    /**
     * Execute the show.
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

        if (preg_match(pattern: self::UUID_REGEX, subject: $uuid) !== 1) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_INVALID_ARGS,
                code: 'INVALID_ARGUMENT',
                message: "Invalid UUID format: '".$uuid."'",
                context: ['field' => 'uuid', 'providedValue' => $uuid]
            );
        }

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

        $placements = array_map(
            callback: static function (WidgetPlacement $placement): array {
                return $placement->jsonSerialize();
            },
            array: $this->placementMapper->findByDashboardId(dashboardId: (int) $dashboard->getId())
        );

        $payload = [
            'dashboard' => $dashboard->jsonSerialize(),
            'widgets'   => $placements,
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
