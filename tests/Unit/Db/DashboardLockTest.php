<?php

/**
 * DashboardLock Entity Test
 *
 * Unit tests for the DashboardLock entity covering field accessors,
 * the JSON serialisation contract (REQ-LOCK-004) and the
 * `isExpired` / `expiresIn` helpers used by the service layer.
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
use OCA\MyDash\Db\DashboardLock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DashboardLock entity.
 */
class DashboardLockTest extends TestCase
{
    /**
     * Helper that returns a `Y-m-d H:i:s` timestamp $offset seconds
     * relative to now (negative values point to the past).
     *
     * @param int $offset Offset in seconds.
     *
     * @return string The formatted timestamp.
     */
    private function timestamp(int $offset): string
    {
        $dt = new DateTime();
        $dt->setTimestamp(timestamp: (time() + $offset));
        return $dt->format(format: 'Y-m-d H:i:s');
    }

    /**
     * REQ-LOCK-005: a lock whose updatedAt is more than 15 minutes in
     * the past MUST be considered expired.
     */
    public function testIsExpiredReturnsTrueForOldHeartbeat(): void
    {
        $lock = new DashboardLock();
        $lock->setUpdatedAt($this->timestamp(offset: -1000));

        $this->assertTrue($lock->isExpired());
    }

    /**
     * REQ-LOCK-005: a lock with a fresh heartbeat MUST be considered
     * active.
     */
    public function testIsExpiredReturnsFalseForFreshHeartbeat(): void
    {
        $lock = new DashboardLock();
        $lock->setUpdatedAt($this->timestamp(offset: -10));

        $this->assertFalse($lock->isExpired());
    }

    /**
     * REQ-LOCK-005: a lock with no updatedAt is treated as expired
     * (defensive default).
     */
    public function testIsExpiredReturnsTrueWhenUpdatedAtMissing(): void
    {
        $lock = new DashboardLock();
        $this->assertTrue($lock->isExpired());
    }

    /**
     * `expiresIn` MUST return a positive number for a fresh lock and
     * 0 (never negative) for an expired one.
     */
    public function testExpiresInRespectsFloor(): void
    {
        $fresh = new DashboardLock();
        $fresh->setUpdatedAt($this->timestamp(offset: -10));
        $this->assertGreaterThan(0, $fresh->expiresIn());

        $stale = new DashboardLock();
        $stale->setUpdatedAt($this->timestamp(offset: -1000));
        $this->assertSame(0, $stale->expiresIn());
    }

    /**
     * REQ-LOCK-004: jsonSerialize MUST surface the full public lock
     * contract (id, dashboardUuid, userId, displayName, acquiredAt,
     * lastHeartbeat, plus implied expiry fields).
     */
    public function testJsonSerializeShapeMatchesContract(): void
    {
        $lock = new DashboardLock();
        $lock->setDashboardUuid('d1');
        $lock->setUserId('alice');
        $lock->setDisplayName('Alice Smith');
        $lock->setCreatedAt('2026-05-02 12:00:00');
        $lock->setUpdatedAt($this->timestamp(offset: -5));

        $json = $lock->jsonSerialize();

        $this->assertArrayHasKey('dashboardUuid', $json);
        $this->assertArrayHasKey('userId', $json);
        $this->assertArrayHasKey('displayName', $json);
        $this->assertArrayHasKey('acquiredAt', $json);
        $this->assertArrayHasKey('lastHeartbeat', $json);
        $this->assertArrayHasKey('expiresAt', $json);
        $this->assertArrayHasKey('expiresIn', $json);
        $this->assertArrayHasKey('lockTimeoutSec', $json);

        $this->assertSame('d1', $json['dashboardUuid']);
        $this->assertSame('alice', $json['userId']);
        $this->assertSame('Alice Smith', $json['displayName']);
        $this->assertSame(900, $json['lockTimeoutSec']);
    }
}
