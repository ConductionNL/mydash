<?php

/**
 * DashboardVersion Test
 *
 * Unit tests for the {@see \OCA\MyDash\Db\DashboardVersion} entity
 * (REQ-VERS-001..009). Covers field round-tripping, integer column
 * coercion, and the API serialisation contract that lists MUST NOT
 * include the snapshot body.
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

use OCA\MyDash\Db\DashboardVersion;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DashboardVersion entity.
 */
class DashboardVersionTest extends TestCase
{
    /**
     * Setters / getters round-trip every field correctly.
     *
     * @return void
     */
    public function testRoundTripsAllFields(): void
    {
        $entity = new DashboardVersion();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setDashboardUuid('uuid-1');
        $entity->setVersionNumber(7);
        $entity->setSnapshotJson('{"a":1}');
        $entity->setCreatedBy('alice');
        $entity->setCreatedAt('2026-05-02 10:00:00');
        $entity->setNote('manual save');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->assertSame('uuid-1', $entity->getDashboardUuid());
        $this->assertSame(7, $entity->getVersionNumber());
        $this->assertSame('{"a":1}', $entity->getSnapshotJson());
        $this->assertSame('alice', $entity->getCreatedBy());
        $this->assertSame('2026-05-02 10:00:00', $entity->getCreatedAt());
        $this->assertSame('manual save', $entity->getNote());
    }//end testRoundTripsAllFields()

    /**
     * jsonSerialize MUST omit `snapshotJson` and surface `sizeBytes`.
     *
     * @return void
     */
    public function testJsonSerializeOmitsSnapshotBody(): void
    {
        $entity = new DashboardVersion();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $entity->setDashboardUuid('uuid-1');
        $entity->setVersionNumber(2);
        $entity->setSnapshotJson('1234567890');
        $entity->setCreatedBy('alice');
        $entity->setCreatedAt('2026-05-02 10:00:00');
        $entity->setNote(null);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $payload = $entity->jsonSerialize();

        $this->assertSame(2, $payload['versionNumber']);
        $this->assertSame('alice', $payload['createdBy']);
        $this->assertSame(10, $payload['sizeBytes']);
        $this->assertNull($payload['note']);
        $this->assertArrayNotHasKey(key: 'snapshotJson', array: $payload);
    }//end testJsonSerializeOmitsSnapshotBody()

    /**
     * Empty snapshot results in `sizeBytes = 0`.
     *
     * @return void
     */
    public function testJsonSerializeReportsZeroSizeForEmptySnapshot(): void
    {
        $entity  = new DashboardVersion();
        $payload = $entity->jsonSerialize();
        $this->assertSame(0, $payload['sizeBytes']);
    }//end testJsonSerializeReportsZeroSizeForEmptySnapshot()
}//end class
