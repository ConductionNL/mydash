<?php

/**
 * DashboardDeleteCommand
 *
 * `mydash:dashboard:delete <uuid> [--cascade]` — delete a dashboard
 * by UUID. Refuses when children exist unless `--cascade` is set.
 * Confirms unless `--no-interaction` is supplied (REQ-CLI-002,
 * REQ-CLI-003).
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
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\CommandService;
use OCA\MyDash\Service\DashboardTreeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `mydash:dashboard:delete` console command.
 */
class DashboardDeleteCommand extends CommandBase
{
    /**
     * Constructor.
     *
     * @param CommandService        $commandService  Shared CLI helper.
     * @param IUserSession          $userSession     Caller resolution.
     * @param DashboardMapper       $dashboardMapper Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper Widget mapper.
     * @param DashboardTreeService  $treeService     Tree service for
     *                                               cascading delete.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession,
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly DashboardTreeService $treeService
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
        $this->setName(name: 'mydash:dashboard:delete')
            ->setDescription(description: 'Delete a dashboard by UUID.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'Delete a dashboard. Refuses when children exist unless --cascade is set.',
                        '',
                        'Examples:',
                        '  php occ mydash:dashboard:delete a1b2c3d4-... --no-interaction',
                        '  php occ mydash:dashboard:delete a1b2c3d4-... --cascade --no-interaction',
                    ]
                )
            )
            ->addArgument(
                name: 'uuid',
                mode: InputArgument::REQUIRED,
                description: 'Dashboard UUID to delete.'
            )
            ->addOption(
                name: 'cascade',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Recursively delete child dashboards as well.'
            );
    }//end configureCommand()

    /**
     * Execute the deletion.
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
        $uuid    = (string) $input->getArgument(name: 'uuid');
        $cascade = (bool) $input->getOption(name: 'cascade');

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

        $childCount = $this->dashboardMapper->countChildrenByParent(parentUuid: $uuid);
        if ($childCount > 0 && $cascade === false) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_INVALID_ARGS,
                code: 'CHILDREN_EXIST',
                message: 'Use --cascade to also delete child dashboards',
                context: ['uuid' => $uuid, 'childCount' => $childCount]
            );
        }

        if ($this->isNoInteraction(input: $input) === false
            && $this->isJson(input: $input) === false
        ) {
            $helper   = new QuestionHelper();
            $question = new ConfirmationQuestion(
                question: sprintf(
                    'Delete dashboard "%s" (%s)? [y/N] ',
                    (string) $dashboard->getName(),
                    $uuid
                ),
                default: false
            );
            if ((bool) $helper->ask(input: $input, output: $output, question: $question) === false) {
                return $this->emitError(
                    input: $input,
                    output: $output,
                    exitCode: CommandService::EXIT_INVALID_ARGS,
                    code: 'ABORTED',
                    message: 'Deletion aborted by user.'
                );
            }
        }

        if ($cascade === true) {
            $this->treeService->deleteSubtree(dashboard: $dashboard);
        } else {
            $this->placementMapper->deleteByDashboardId(dashboardId: (int) $dashboard->getId());
            $this->dashboardMapper->delete(entity: $dashboard);
        }

        $cascadeNote = '';
        if ($cascade === true) {
            $cascadeNote = ' and '.$childCount.' descendant(s)';
        }

        $this->emitSuccess(
            input: $input,
            output: $output,
            data: ['uuid' => $uuid, 'cascade' => $cascade, 'childCount' => $childCount],
            human: 'Deleted dashboard '.$uuid.$cascadeNote
        );

        return CommandService::EXIT_SUCCESS;
    }//end handle()
}//end class
