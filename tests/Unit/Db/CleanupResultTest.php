<?php

/**
 * CleanupResult Test
 *
 * Covers the {@see \OCA\MyDash\Db\CleanupResult} DTO — the
 * `fromCounts()` factory must derive `totalRows` from the
 * per-category map and the JSON shape must round-trip through
 * `jsonSerialize()` without mutation.
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

use OCA\MyDash\Db\CleanupResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CleanupResult.
 */
class CleanupResultTest extends TestCase
{
    /**
     * `fromCounts` MUST sum the per-category map into `totalRows`.
     *
     * @return void
     */
    public function testFromCountsDerivesTotalRows(): void
    {
        $result = CleanupResult::fromCounts(
            byCategory: ['a' => 3, 'b' => 0, 'c' => 7],
            durationMs: 42,
        );

        $this->assertSame(expected: 10, actual: $result->getTotalRows());
        $this->assertSame(expected: 42, actual: $result->getDurationMs());
        $this->assertFalse(condition: $result->isDryRun());
        $this->assertSame(expected: [], actual: $result->getSkipped());
    }

    /**
     * `fromCounts` MUST stamp an ISO-8601 UTC `scannedAt`.
     *
     * @return void
     */
    public function testFromCountsStampsScannedAt(): void
    {
        $result = CleanupResult::fromCounts(byCategory: [], durationMs: 0);

        $this->assertMatchesRegularExpression(
            pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            string: $result->getScannedAt()
        );
    }

    /**
     * Dry-run flag MUST round-trip through the constructor.
     *
     * @return void
     */
    public function testDryRunFlagRoundTrips(): void
    {
        $result = CleanupResult::fromCounts(
            byCategory: ['x' => 1],
            durationMs: 5,
            dryRun: true,
            skipped: ['y'],
        );

        $this->assertTrue(condition: $result->isDryRun());
        $this->assertSame(expected: ['y'], actual: $result->getSkipped());
    }

    /**
     * `jsonSerialize` MUST expose every field for the API envelope.
     *
     * @return void
     */
    public function testJsonSerializeExposesEveryField(): void
    {
        $result = CleanupResult::fromCounts(
            byCategory: ['a' => 1, 'b' => 2],
            durationMs: 9,
            dryRun: true,
            skipped: ['c'],
        );

        $json = $result->jsonSerialize();

        $this->assertSame(expected: ['a' => 1, 'b' => 2], actual: $json['byCategory']);
        $this->assertSame(expected: 3, actual: $json['totalRows']);
        $this->assertSame(expected: 9, actual: $json['durationMs']);
        $this->assertTrue(condition: $json['dryRun']);
        $this->assertSame(expected: ['c'], actual: $json['skipped']);
        $this->assertArrayHasKey(key: 'scannedAt', array: $json);
    }
}
