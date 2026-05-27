<?php

/**
 * DashboardListCommand
 *
 * `mydash:dashboard:list` — list dashboards with optional `--user`,
 * `--group`, and `--status` filters (REQ-CLI-003). Default output is a
 * compact table; with `--json` the standard envelope is emitted.
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

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Service\CommandService;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:dashboard:list` console command.
 */
class DashboardListCommand extends CommandBase
{
    /**
     * Allowed values for `--status` (REQ-CLI-003).
     *
     * @var list<string>
     */
    private const ALLOWED_STATUS = ['draft', 'published', 'scheduled'];

    /**
     * Constructor.
     *
     * @param CommandService  $commandService  Shared CLI helper.
     * @param IUserSession    $userSession     Caller resolution.
     * @param DashboardMapper $dashboardMapper Dashboard mapper.
     * @param IUserManager    $userManager     For `--user` validation.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession,
        private readonly DashboardMapper $dashboardMapper,
        private readonly IUserManager $userManager
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
        $this->setName(name: 'mydash:dashboard:list')
            ->setDescription(description: 'List dashboards with optional filters.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'List MyDash dashboards visible on this instance.',
                        '',
                        'Options:',
                        '  --user=<uid>     Restrict to dashboards owned by user.',
                        '  --group=<gid>    Restrict to group-shared dashboards for group.',
                        '  --status=<val>   Filter on publication status (draft|published|scheduled).',
                        '',
                        'Examples:',
                        '  php occ mydash:dashboard:list',
                        '  php occ mydash:dashboard:list --user=alice --status=published --json',
                    ]
                )
            )
            ->addOption(
                name: 'user',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Filter by owning user id.'
            )
            ->addOption(
                name: 'group',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Filter by group id (group-shared dashboards).'
            )
            ->addOption(
                name: 'status',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Filter by publication status.'
            );
    }//end configureCommand()

    /**
     * Execute the listing.
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
        $user   = $input->getOption(name: 'user');
        $group  = $input->getOption(name: 'group');
        $status = $input->getOption(name: 'status');

        if ($status !== null
            && in_array(needle: (string) $status, haystack: self::ALLOWED_STATUS, strict: true) === false
        ) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_INVALID_ARGS,
                code: 'INVALID_ARGUMENT',
                message: 'Invalid --status value: '.(string) $status,
                context: ['allowed' => self::ALLOWED_STATUS]
            );
        }

        if ($user !== null && $this->userManager->userExists(uid: (string) $user) === false) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_NOT_FOUND,
                code: 'NOT_FOUND',
                message: 'User not found: '.(string) $user,
                context: ['userId' => (string) $user]
            );
        }

        $userArg = null;
        if ($user !== null) {
            $userArg = (string) $user;
        }

        $groupArg = null;
        if ($group !== null) {
            $groupArg = (string) $group;
        }

        $statusArg = null;
        if ($status !== null) {
            $statusArg = (string) $status;
        }

        $dashboards = $this->collect(
            user: $userArg,
            group: $groupArg,
            status: $statusArg
        );

        $rows = array_map(
            callback: static function (Dashboard $dashboard): array {
                return [
                    'uuid'              => (string) $dashboard->getUuid(),
                    'name'              => (string) $dashboard->getName(),
                    'type'              => (string) $dashboard->getType(),
                    'owner'             => (string) ($dashboard->getUserId() ?? ''),
                    'group'             => (string) ($dashboard->getGroupId() ?? ''),
                    'publicationStatus' => (string) $dashboard->getPublicationStatus(),
                ];
            },
            array: $dashboards
        );

        if ($this->isJson(input: $input) === true) {
            $this->emitSuccess(
                input: $input,
                output: $output,
                data: ['dashboards' => $rows, 'count' => count(value: $rows)]
            );
            return CommandService::EXIT_SUCCESS;
        }

        if ($this->isQuiet(input: $input) === false && count(value: $rows) === 0) {
            $output->writeln(messages: 'No dashboards match the supplied filters.');
        }

        if ($this->isQuiet(input: $input) === false && count(value: $rows) > 0) {
            $output->writeln(
                messages: sprintf('%-36s  %-20s  %-14s  %-12s  %s', 'UUID', 'NAME', 'TYPE', 'STATUS', 'OWNER')
            );
            foreach ($rows as $row) {
                $output->writeln(
                    messages: sprintf(
                        '%-36s  %-20s  %-14s  %-12s  %s',
                        $row['uuid'],
                        mb_strimwidth(string: $row['name'], start: 0, width: 20, trim_marker: '..'),
                        $row['type'],
                        $row['publicationStatus'],
                        $row['owner']
                    )
                );
            }
        }

        return CommandService::EXIT_SUCCESS;
    }//end handle()

    /**
     * Collect dashboards across all relevant scopes for the supplied
     * filters. The mapper exposes scope-specific finders; we fan out
     * and apply post-filters in PHP to keep the command non-invasive
     * (no schema or mapper changes).
     *
     * @param string|null $user   Optional user filter.
     * @param string|null $group  Optional group filter.
     * @param string|null $status Optional publication status filter.
     *
     * @return list<Dashboard>
     */
    private function collect(
        string|null $user,
        string|null $group,
        string|null $status
    ): array {
        $dashboards = [];

        if ($user !== null) {
            foreach ($this->dashboardMapper->findByUserId(userId: $user) as $dashboard) {
                $dashboards[] = $dashboard;
            }
        }

        if ($user === null && $group !== null) {
            foreach ($this->dashboardMapper->findByGroup(groupId: $group) as $dashboard) {
                $dashboards[] = $dashboard;
            }
        }

        if ($user === null && $group === null) {
            foreach ($this->dashboardMapper->findAdminTemplates() as $dashboard) {
                $dashboards[] = $dashboard;
            }

            foreach ($this->dashboardMapper->findByParent(parentUuid: null) as $root) {
                $dashboards[] = $root;
                $uuid         = (string) $root->getUuid();
                if ($uuid === '') {
                    continue;
                }

                foreach ($this->dashboardMapper->findDescendants(ancestorUuid: $uuid) as $child) {
                    $dashboards[] = $child;
                }
            }
        }

        if ($status === null) {
            return $dashboards;
        }

        return array_values(
            array: array_filter(
                array: $dashboards,
                callback: static function (Dashboard $dashboard) use ($status): bool {
                    return $dashboard->getPublicationStatus() === $status;
                }
            )
        );
    }//end collect()
}//end class
