<?php

/**
 * CommentsListener
 *
 * Removes Nextcloud comments attached to the deleted dashboard via
 * `ICommentsManager::deleteCommentsAtObject()`. This is the one
 * listener whose pattern is directly carried over from the source
 * reference implementation. Stub registered as part of the
 * cascade-events scaffolding; the live ICommentsManager wiring is
 * filled in by the dashboard-comments follow-up. REQ-CSC-003.
 *
 * @category  Listener
 * @package   OCA\MyDash\Listener
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

namespace OCA\MyDash\Listener;

use OCA\MyDash\Event\DashboardDeletedEvent;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes Nextcloud comments for the deleted dashboard.
 * C4 fix (REQ-CSC-003).
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class CommentsListener implements IEventListener
{
    /**
     * Object type constant used when attaching comments to a dashboard.
     */
    private const OBJECT_TYPE = 'mydash_dashboard';

    /**
     * Constructor.
     *
     * @param ICommentsManager $commentsManager Nextcloud comments manager.
     * @param LoggerInterface  $logger          PSR-3 logger for
     *                                          log-and-continue failure
     *                                          handling per REQ-CSC-006.
     */
    public function __construct(
        private readonly ICommentsManager $commentsManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the DashboardDeletedEvent.
     *
     * @param Event $event The event.
     *
     * @return void
     *
     * @spec openspec/specs/dashboard-cascade-events/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof DashboardDeletedEvent) === false) {
            return;
        }

        $uuid = $event->getDashboardUuid();

        try {
            $this->commentsManager->deleteCommentsAtObject(
                objectType: self::OBJECT_TYPE,
                objectId: $uuid
            );

            $this->logger->debug(
                message: sprintf(
                    'mydash CommentsListener: deleted comments for dashboard %s',
                    $uuid
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash CommentsListener: failed for dashboard %s: %s',
                    $uuid,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
