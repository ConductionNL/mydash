<?php

/**
 * DashboardReactionTest
 *
 * Unit tests for the DashboardReaction entity covering field
 * registration, getters/setters, the `Y-m-d H:i:s` timestamp format
 * helper, and JSON serialisation. REQ-RXN-001.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\MyDash\Db\DashboardReaction;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardReaction entity.
 */
class DashboardReactionTest extends TestCase
{
    private DashboardReaction $reaction;

    protected function setUp(): void
    {
        $this->reaction = new DashboardReaction();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $types = $this->reaction->getFieldTypes();
        $this->assertSame('integer', $types['id']);
        $this->assertSame('datetime', $types['reactedAt']);
    }

    public function testSetAndGetDashboardUuid(): void
    {
        $this->reaction->setDashboardUuid('dash-123');
        $this->assertSame('dash-123', $this->reaction->getDashboardUuid());
    }

    public function testSetAndGetUserId(): void
    {
        $this->reaction->setUserId('alice');
        $this->assertSame('alice', $this->reaction->getUserId());
    }

    public function testSetAndGetEmoji(): void
    {
        $this->reaction->setEmoji('👍');
        $this->assertSame('👍', $this->reaction->getEmoji());
    }

    public function testReactedAtFormattedReturnsNullWhenUnset(): void
    {
        $this->assertNull($this->reaction->getReactedAtFormatted());
    }

    public function testReactedAtFormattedReturnsYmdHis(): void
    {
        $this->reaction->setReactedAt(new DateTime('2026-03-20 10:00:00'));
        $this->assertSame('2026-03-20 10:00:00', $this->reaction->getReactedAtFormatted());
    }

    public function testJsonSerializeShapesPayload(): void
    {
        $this->reaction->setDashboardUuid('dash-123');
        $this->reaction->setUserId('alice');
        $this->reaction->setEmoji('🎉');
        $this->reaction->setReactedAt(new DateTime('2026-03-20 10:05:00'));

        $serialized = $this->reaction->jsonSerialize();

        $this->assertSame('dash-123', $serialized['dashboardUuid']);
        $this->assertSame('alice', $serialized['userId']);
        $this->assertSame('🎉', $serialized['emoji']);
        $this->assertSame('2026-03-20 10:05:00', $serialized['reactedAt']);
        $this->assertArrayHasKey('id', $serialized);
    }

    public function testJsonSerializeDefaults(): void
    {
        $serialized = $this->reaction->jsonSerialize();
        $this->assertNull($serialized['dashboardUuid']);
        $this->assertNull($serialized['userId']);
        $this->assertNull($serialized['emoji']);
        $this->assertNull($serialized['reactedAt']);
    }
}
