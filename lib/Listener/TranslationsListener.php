<?php

/**
 * TranslationsListener
 *
 * Cleans up `oc_mydash_dash_translations` rows when a dashboard
 * is soft-deleted. Stub registered as part of the cascade-events
 * scaffolding; the live implementation is owned by the
 * dashboard-language-content follow-up. REQ-CSC-003.
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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes dashboard translation rows for the deleted dashboard.
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class TranslationsListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR-3 logger for log-and-continue
     *                                failure handling per REQ-CSC-006.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the DashboardDeletedEvent.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof DashboardDeletedEvent) === false) {
            return;
        }

        try {
            // TODO(dashboard-language-content): DELETE FROM
            // oc_mydash_dash_translations WHERE dashboardUuid = ?.
            // Stub registered for cascade scaffolding — live cleanup
            // owned by the language-content subsystem.
            $this->logger->debug(
                message: sprintf(
                    'mydash TranslationsListener: stub invoked for dashboard %s',
                    $event->getDashboardUuid()
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash TranslationsListener: failed for dashboard %s: %s',
                    $event->getDashboardUuid(),
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
