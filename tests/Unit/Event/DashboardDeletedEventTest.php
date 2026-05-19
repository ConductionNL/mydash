<?php

/**
 * DashboardDeletedEventTest
 *
 * Unit tests for DashboardDeletedEvent covering REQ-CSC-001: getter
 * fidelity and IEventDispatcher contract.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Event;

use DateTimeImmutable;
use OCA\MyDash\Event\DashboardDeletedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardDeletedEvent.
 */
class DashboardDeletedEventTest extends TestCase
{
    /**
     * Each constructor argument is round-tripped by its getter.
     *
     * REQ-CSC-001 scenario "Event carries required payload".
     *
     * @return void
     */
    public function testGettersReturnConstructorValues(): void
    {
        $deletedAt = new DateTimeImmutable(datetime: '2026-05-01T10:00:00+00:00');

        $event = new DashboardDeletedEvent(
            dashboardUuid: 'abc-123',
            ownerUserId: 'alice',
            type: 'user',
            deletedAt: $deletedAt,
        );

        $this->assertSame(expected: 'abc-123', actual: $event->getDashboardUuid());
        $this->assertSame(expected: 'alice', actual: $event->getOwnerUserId());
        $this->assertSame(expected: 'user', actual: $event->getType());
        $this->assertSame(expected: $deletedAt, actual: $event->getDeletedAt());
    }//end testGettersReturnConstructorValues()

    /**
     * Group-shared dashboards carry the actor's user ID, not the
     * group ID, in `ownerUserId`.
     *
     * REQ-CSC-001 scenario "Event carries correct type for
     * group-shared dashboard".
     *
     * @return void
     */
    public function testGroupSharedTypeAndActorOwner(): void
    {
        $event = new DashboardDeletedEvent(
            dashboardUuid: 'g1',
            ownerUserId: 'admin',
            type: 'group_shared',
            deletedAt: new DateTimeImmutable(),
        );

        $this->assertSame(expected: 'group_shared', actual: $event->getType());
        $this->assertSame(expected: 'admin', actual: $event->getOwnerUserId());
    }//end testGroupSharedTypeAndActorOwner()

    /**
     * The event class extends the Nextcloud event base class so it
     * can be passed to `IEventDispatcher::dispatchTyped()`.
     *
     * REQ-CSC-001 scenario "Event class extends Nextcloud
     * IEventDispatcher contract".
     *
     * @return void
     */
    public function testExtendsNextcloudEventBase(): void
    {
        $event = new DashboardDeletedEvent(
            dashboardUuid: 'x',
            ownerUserId: 'y',
            type: 'user',
            deletedAt: new DateTimeImmutable(),
        );

        $this->assertInstanceOf(expected: Event::class, actual: $event);
    }//end testExtendsNextcloudEventBase()
}//end class
