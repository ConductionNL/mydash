<?php

/**
 * ReactionsListenerTest
 *
 * Unit tests for ReactionsListener covering REQ-RXN-009 (cascade
 * delete via DashboardDeletedEvent) and REQ-CSC-006 (log-and-continue
 * failure isolation).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Listener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Listener;

use DateTimeImmutable;
use OCA\MyDash\Event\DashboardDeletedEvent;
use OCA\MyDash\Listener\ReactionsListener;
use OCA\MyDash\Service\ReactionService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ReactionsListener cascade delete.
 */
class ReactionsListenerTest extends TestCase
{
    /**
     * Live cleanup invokes ReactionService::deleteReactionsByDashboard
     * with the UUID from the event. REQ-RXN-009.
     */
    public function testHandleInvokesServiceWithEventUuid(): void
    {
        $service = $this->createMock(originalClassName: ReactionService::class);
        $logger  = $this->createMock(originalClassName: LoggerInterface::class);

        $service->expects($this->once())
            ->method('deleteReactionsByDashboard')
            ->with($this->equalTo('abc-123'))
            ->willReturn(7);

        $listener = new ReactionsListener(
            reactionService: $service,
            logger: $logger
        );

        $listener->handle(
            event: new DashboardDeletedEvent(
                dashboardUuid: 'abc-123',
                ownerUserId: 'alice',
                type: 'user',
                deletedAt: new DateTimeImmutable(),
            )
        );
    }

    /**
     * Foreign event types are silently ignored — the instanceof guard
     * short-circuits before the service is touched.
     */
    public function testIgnoresForeignEventTypes(): void
    {
        $service = $this->createMock(originalClassName: ReactionService::class);
        $logger  = $this->createMock(originalClassName: LoggerInterface::class);

        $service->expects($this->never())
            ->method('deleteReactionsByDashboard');

        $listener = new ReactionsListener(
            reactionService: $service,
            logger: $logger
        );

        $foreign = new class extends Event {
        };

        $listener->handle(event: $foreign);
    }

    /**
     * REQ-CSC-006 — listener swallows service failure, logs at WARNING,
     * does not let the exception escape (failure isolation between
     * cascade siblings).
     */
    public function testServiceFailureIsLoggedAndSwallowed(): void
    {
        $service = $this->createMock(originalClassName: ReactionService::class);
        $logger  = $this->createMock(originalClassName: LoggerInterface::class);

        $service->method('deleteReactionsByDashboard')
            ->willThrowException(new RuntimeException(message: 'boom'));

        $logger->expects($this->once())->method('warning');

        $listener = new ReactionsListener(
            reactionService: $service,
            logger: $logger
        );

        $listener->handle(
            event: new DashboardDeletedEvent(
                dashboardUuid: 'abc-123',
                ownerUserId: 'alice',
                type: 'user',
                deletedAt: new DateTimeImmutable(),
            )
        );
    }
}
