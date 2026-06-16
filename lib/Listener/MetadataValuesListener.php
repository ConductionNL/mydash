<?php

/**
 * MetadataValuesListener
 *
 * Cleans up `oc_mydash_metadata_values` rows when a dashboard is
 * soft-deleted. Stub registered as part of the cascade-events
 * scaffolding; the live implementation is owned by the
 * dashboard-metadata-fields follow-up. REQ-CSC-003.
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

use OCA\MyDash\Db\MetadataValueMapper;
use OCA\MyDash\Event\DashboardDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes metadata values for the deleted dashboard. C4 fix (REQ-CSC-003).
 *
 * @implements IEventListener<DashboardDeletedEvent>
 */
class MetadataValuesListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param MetadataValueMapper $metadataMapper Metadata value mapper.
     * @param LoggerInterface     $logger         PSR-3 logger for
     *                                            log-and-continue failure
     *                                            handling per REQ-CSC-006.
     */
    public function __construct(
        private readonly MetadataValueMapper $metadataMapper,
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
            $deleted = $this->metadataMapper->deleteByDashboard(
                dashboardUuid: $uuid
            );

            $this->logger->debug(
                message: sprintf(
                    'mydash MetadataValuesListener: deleted %d metadata rows for dashboard %s',
                    $deleted,
                    $uuid
                ),
                context: ['app' => 'mydash']
            );
        } catch (Throwable $t) {
            $this->logger->warning(
                message: sprintf(
                    'mydash MetadataValuesListener: failed for dashboard %s: %s',
                    $uuid,
                    $t->getMessage()
                ),
                context: ['app' => 'mydash']
            );
        }//end try
    }//end handle()
}//end class
