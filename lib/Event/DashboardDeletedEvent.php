<?php

/**
 * DashboardDeletedEvent
 *
 * Dispatched by `DashboardService` after a dashboard row has been
 * soft-deleted, before the HTTP response is returned. Carries the
 * minimal context every cascade listener needs to clean up its own
 * dependent table without re-loading the dashboard. REQ-CSC-001.
 *
 * @category  Event
 * @package   OCA\MyDash\Event
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

namespace OCA\MyDash\Event;

use DateTimeImmutable;
use OCP\EventDispatcher\Event;

/**
 * Event signalling that a dashboard has been soft-deleted.
 *
 * The event is fired synchronously inside the same PHP request — every
 * registered listener executes before `DashboardService::delete()`
 * returns. Listeners receive a row that is already soft-deleted; they
 * MUST NOT attempt to re-delete the main dashboard row.
 *
 * @see \OCA\MyDash\Listener\WidgetPlacementsListener
 * @see \OCA\MyDash\Listener\CommentsListener
 * @see \OCA\MyDash\Listener\ReactionsListener
 * @see \OCA\MyDash\Listener\LocksListener
 * @see \OCA\MyDash\Listener\VersionsListener
 * @see \OCA\MyDash\Listener\PublicSharesListener
 * @see \OCA\MyDash\Listener\MetadataValuesListener
 * @see \OCA\MyDash\Listener\TranslationsListener
 * @see \OCA\MyDash\Listener\ViewAnalyticsListener
 * @see \OCA\MyDash\Listener\TreeListener
 */
final class DashboardDeletedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string            $dashboardUuid The UUID of the deleted dashboard.
     * @param string            $ownerUserId   The owner user ID at the time of deletion.
     * @param string            $type          The dashboard type (`user`, `group_shared`, `admin_template`).
     * @param DateTimeImmutable $deletedAt     The instant the soft-delete completed.
     */
    public function __construct(
        private readonly string $dashboardUuid,
        private readonly string $ownerUserId,
        private readonly string $type,
        private readonly DateTimeImmutable $deletedAt,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the UUID of the deleted dashboard.
     *
     * @return string The dashboard UUID.
     */
    public function getDashboardUuid(): string
    {
        return $this->dashboardUuid;
    }//end getDashboardUuid()

    /**
     * Get the owner user ID at the time of deletion.
     *
     * For `group_shared` dashboards this is the actor (admin) who
     * performed the delete, not the original creator. REQ-CSC-001
     * scenario "Event carries correct type for group-shared dashboard".
     *
     * @return string The owner / actor user ID.
     */
    public function getOwnerUserId(): string
    {
        return $this->ownerUserId;
    }//end getOwnerUserId()

    /**
     * Get the dashboard type.
     *
     * @return string One of `user`, `group_shared`, `admin_template`.
     */
    public function getType(): string
    {
        return $this->type;
    }//end getType()

    /**
     * Get the deletion timestamp.
     *
     * @return DateTimeImmutable The instant the soft-delete completed.
     */
    public function getDeletedAt(): DateTimeImmutable
    {
        return $this->deletedAt;
    }//end getDeletedAt()
}//end class
