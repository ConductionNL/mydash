<?php

/**
 * UserRevokeFeedTokenCommand
 *
 * `mydash:user:revoke-feed-token <uid>` — revoke the RSS feed token
 * for a user (REQ-CLI-004). Depends on the `dashboard-rss-feeds`
 * capability; refuses gracefully when that capability is absent so
 * the command stays safe to ship before the sibling spec lands.
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

use DateTimeImmutable;
use OCA\MyDash\Service\CommandService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mydash:user:revoke-feed-token` console command.
 */
class UserRevokeFeedTokenCommand extends CommandBase
{
    /**
     * Database table name carrying RSS feed tokens — owned by the
     * `dashboard-rss-feeds` sibling spec. Detected at runtime so the
     * command degrades gracefully when the spec has not yet shipped.
     *
     * @var string
     */
    private const RSS_TOKEN_TABLE = 'mydash_feed_tokens';

    /**
     * Constructor.
     *
     * @param CommandService $commandService Shared CLI helper.
     * @param IUserSession   $userSession    Caller resolution.
     * @param IUserManager   $userManager    User existence lookup.
     * @param IDBConnection  $db             Database connection.
     */
    public function __construct(
        CommandService $commandService,
        IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IDBConnection $db
    ) {
        parent::__construct(commandService: $commandService, userSession: $userSession);
    }//end __construct()

    /**
     * Wire command name, description, and per-command options.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard-rss-feeds/spec.md
     */
    protected function configureCommand(): void
    {
        $this->setName(name: 'mydash:user:revoke-feed-token')
            ->setDescription(description: 'Revoke a user\'s RSS feed token.')
            ->setHelp(
                help: implode(
                    separator: "\n",
                    array: [
                        'Revoke the RSS feed token for the given user.',
                        'Depends on the `dashboard-rss-feeds` capability; gracefully fails when absent.',
                        '',
                        'Examples:',
                        '  php occ mydash:user:revoke-feed-token alice',
                        '  php occ mydash:user:revoke-feed-token alice --json',
                    ]
                )
            )
            ->addArgument(
                name: 'uid',
                mode: InputArgument::REQUIRED,
                description: 'User id whose feed token should be invalidated.'
            );
    }//end configureCommand()

    /**
     * Execute the revocation.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int
     *
     * @spec openspec/specs/dashboard-rss-feeds/spec.md
     */
    protected function handle(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $uid = (string) $input->getArgument(name: 'uid');

        if ($this->db->tableExists(table: self::RSS_TOKEN_TABLE) === false) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_ERROR,
                code: 'CAPABILITY_MISSING',
                message: 'The dashboard-rss-feeds capability is not available',
                context: ['expectedTable' => self::RSS_TOKEN_TABLE]
            );
        }

        if ($this->userManager->userExists(uid: $uid) === false) {
            return $this->emitError(
                input: $input,
                output: $output,
                exitCode: CommandService::EXIT_NOT_FOUND,
                code: 'NOT_FOUND',
                message: "User not found: '".$uid."'",
                context: ['userId' => $uid]
            );
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: self::RSS_TOKEN_TABLE)
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $uid)
                )
            );
        $qb->executeStatement();

        $revokedAt = (new DateTimeImmutable())->format(format: DATE_ATOM);

        $this->emitSuccess(
            input: $input,
            output: $output,
            data: ['uid' => $uid, 'revokedAt' => $revokedAt],
            human: 'Revoked feed token for '.$uid.' at '.$revokedAt
        );

        return CommandService::EXIT_SUCCESS;
    }//end handle()
}//end class
