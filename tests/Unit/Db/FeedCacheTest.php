<?php

/**
 * FeedCache Entity Test
 *
 * Unit tests for the FeedCache entity (REQ-FRJ-001..012).
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

use OCA\MyDash\Db\FeedCache;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FeedCache entity.
 */
class FeedCacheTest extends TestCase
{
    /**
     * Constructor registers the integer id field type.
     *
     * @return void
     */
    public function testConstructorRegistersFieldTypes(): void
    {
        $row = new FeedCache();
        $this->assertSame(
            expected: 'integer',
            actual: $row->getFieldTypes()['id']
        );
    }//end testConstructorRegistersFieldTypes()

    /**
     * Defaults reflect a freshly-inserted "URL only" row.
     *
     * @return void
     */
    public function testDefaultsAreNull(): void
    {
        $row = new FeedCache();
        $this->assertNull(actual: $row->getFeedUrl());
        $this->assertNull(actual: $row->getLastFetchedAt());
        $this->assertNull(actual: $row->getLastSuccessAt());
        $this->assertNull(actual: $row->getLastFailureReason());
        $this->assertNull(actual: $row->getEtag());
        $this->assertNull(actual: $row->getLastModified());
        $this->assertNull(actual: $row->getItemsJson());
        $this->assertSame(expected: [], actual: $row->decodeItems());
    }//end testDefaultsAreNull()

    /**
     * `encodeItems` JSON-encodes and caps at MAX_ITEMS (50).
     *
     * @return void
     */
    public function testEncodeItemsCapsAt50(): void
    {
        $items = [];
        for ($i = 0; $i < 80; $i++) {
            $items[] = ['guid' => 'item-'.$i, 'title' => 'Title '.$i];
        }

        $row = new FeedCache();
        $row->encodeItems(items: $items);

        $decoded = $row->decodeItems();
        $this->assertCount(expectedCount: 50, haystack: $decoded);
        $this->assertSame(expected: 'item-0', actual: $decoded[0]['guid']);
        $this->assertSame(expected: 'item-49', actual: $decoded[49]['guid']);
    }//end testEncodeItemsCapsAt50()

    /**
     * `encodeItems` round-trips arbitrary values via JSON.
     *
     * @return void
     */
    public function testEncodeItemsRoundTrips(): void
    {
        $row = new FeedCache();
        $row->encodeItems(
            items: [['guid' => 'a', 'title' => 'Hello / world & "quotes"']]
        );
        $decoded = $row->decodeItems();
        $this->assertSame(
            expected: 'Hello / world & "quotes"',
            actual: $decoded[0]['title']
        );
    }//end testEncodeItemsRoundTrips()

    /**
     * `decodeItems` returns `[]` on null and on garbage JSON.
     *
     * @return void
     */
    public function testDecodeItemsTolerantToGarbage(): void
    {
        $row = new FeedCache();
        $row->setItemsJson('not-json');
        $this->assertSame(expected: [], actual: $row->decodeItems());
    }//end testDecodeItemsTolerantToGarbage()

    /**
     * `jsonSerialize` exposes every column plus the decoded items.
     *
     * @return void
     */
    public function testJsonSerializeIncludesAllColumns(): void
    {
        $row = new FeedCache();
        $row->setFeedUrl('https://example.com/rss');
        $row->setLastFetchedAt('2026-05-02 10:00:00');
        $row->setLastSuccessAt('2026-05-02 10:00:00');
        $row->setEtag('"abc123"');
        $row->setLastModified('Wed, 21 Oct 2026 07:28:00 GMT');
        $row->encodeItems(items: [['guid' => 'g1']]);

        $serialised = $row->jsonSerialize();

        $this->assertSame(expected: 'https://example.com/rss', actual: $serialised['feedUrl']);
        $this->assertSame(expected: '"abc123"', actual: $serialised['etag']);
        $this->assertSame(expected: 'g1', actual: $serialised['items'][0]['guid']);
    }//end testJsonSerializeIncludesAllColumns()
}//end class
