<?php

/**
 * FeedRefreshServiceTest
 *
 * Unit tests for the {@see FeedRefreshService} (REQ-FRJ-001..012):
 * discovery dedup/sort, conditional GET, 304 fast-path, parse + cap,
 * per-feed failure isolation, allow-list enforcement, and aggregate
 * `refreshAll` summary.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\FeedCache;
use OCA\MyDash\Db\FeedCacheMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\FeedRefreshService;
use OCA\MyDash\Service\UrlSafetyValidator;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FeedRefreshService.
 */
class FeedRefreshServiceTest extends TestCase
{

    /** @var IClientService&MockObject */
    private $clientService;

    /** @var IClient&MockObject */
    private $client;

    /** @var IAppConfig&MockObject */
    private $appConfig;

    /** @var IConfig&MockObject */
    private $config;

    /** @var FeedCacheMapper&MockObject */
    private $cacheMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var UrlSafetyValidator&MockObject */
    private $urlValidator;

    private FeedRefreshService $service;

    /**
     * Wire up mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clientService   = $this->createMock(IClientService::class);
        $this->client          = $this->createMock(IClient::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->config          = $this->createMock(IConfig::class);
        $this->cacheMapper     = $this->createMock(FeedCacheMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->urlValidator    = $this->createMock(UrlSafetyValidator::class);

        $this->clientService->method('newClient')->willReturn($this->client);
        // Default: all URLs pass the SSRF guard in unit tests.
        $this->urlValidator->method('isSafe')->willReturn(true);

        $this->service = new FeedRefreshService(
            clientService: $this->clientService,
            appConfig: $this->appConfig,
            config: $this->config,
            cacheMapper: $this->cacheMapper,
            placementMapper: $this->placementMapper,
            logger: $this->logger,
            urlValidator: $this->urlValidator,
        );
    }//end setUp()

    /**
     * `discoverFeedUrls` deduplicates and sorts URLs from news-widget
     * placements; non-news placements are excluded; placements with no
     * `feedUrls` are skipped silently.
     *
     * @return void
     */
    public function testDiscoverFeedUrlsDedupesAndSorts(): void
    {
        $placementA = new WidgetPlacement();
        $placementA->setStyleConfigArray(
            ['feedUrls' => ['https://b.com/feed', 'https://a.com/rss']]
        );

        $placementB = new WidgetPlacement();
        $placementB->setStyleConfigArray(
            ['feedUrls' => ['https://b.com/feed', 'https://c.com/atom']]
        );

        $placementC = new WidgetPlacement();
        $placementC->setStyleConfigArray(['feedUrls' => []]);

        $this->placementMapper->expects($this->once())
            ->method('findByWidgetId')
            ->with(FeedRefreshService::NEWS_WIDGET_ID)
            ->willReturn([$placementA, $placementB, $placementC]);

        $urls = $this->service->discoverFeedUrls();

        $this->assertSame(
            expected: [
                'https://a.com/rss',
                'https://b.com/feed',
                'https://c.com/atom',
            ],
            actual: $urls
        );
    }//end testDiscoverFeedUrlsDedupesAndSorts()

    /**
     * `discoverFeedUrls` returns an empty array when no placements
     * exist at all (REQ-FRJ-003).
     *
     * @return void
     */
    public function testDiscoverFeedUrlsReturnsEmptyWhenNoPlacements(): void
    {
        $this->placementMapper->expects($this->once())
            ->method('findByWidgetId')
            ->willReturn([]);

        $this->assertSame(expected: [], actual: $this->service->discoverFeedUrls());
    }//end testDiscoverFeedUrlsReturnsEmptyWhenNoPlacements()

    /**
     * `refreshFeed` on HTTP 304: only `lastFetchedAt` is updated; items,
     * etag, lastModified are preserved (REQ-FRJ-004).
     *
     * @return void
     */
    public function testRefreshFeed304PreservesItems(): void
    {
        $existing = new FeedCache();
        $existing->setFeedUrl('https://example.com/rss');
        $existing->setEtag('"abc"');
        $existing->setLastModified('Wed, 21 Oct 2026 07:28:00 GMT');
        $existing->encodeItems(items: [['guid' => 'g1', 'title' => 'cached']]);
        $existing->setLastSuccessAt('2026-05-01 00:00:00');

        $this->configEmpty();
        $this->cacheMapper->expects($this->once())
            ->method('upsertUrl')
            ->with('https://example.com/rss')
            ->willReturn($existing);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(304);
        $response->method('getBody')->willReturn('');
        $response->method('getHeaders')->willReturn([]);

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $captured = null;
        $this->cacheMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function (FeedCache $row) use (&$captured): FeedCache {
                $captured = clone $row;
                return $row;
            });

        $result = $this->service->refreshFeed(feedUrl: 'https://example.com/rss');

        $this->assertSame(expected: 'not-modified', actual: $result['status']);
        $this->assertNotNull(actual: $captured);
        $this->assertSame(expected: '2026-05-01 00:00:00', actual: $captured->getLastSuccessAt());
        $this->assertSame(expected: '"abc"', actual: $captured->getEtag());
        $this->assertCount(expectedCount: 1, haystack: $captured->decodeItems());
    }//end testRefreshFeed304PreservesItems()

    /**
     * `refreshFeed` on HTTP 200 with RSS body: parses items, normalises
     * to schema, persists items + etag + lastModified, clears any prior
     * failure reason (REQ-FRJ-005).
     *
     * @return void
     */
    public function testRefreshFeed200ParsesAndPersists(): void
    {
        $row = new FeedCache();
        $row->setFeedUrl('https://example.com/rss');

        $this->configEmpty();
        $this->cacheMapper->expects($this->once())
            ->method('upsertUrl')
            ->willReturn($row);

        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
<title>Example News</title>
<link>https://example.com</link>
<item>
  <title>Headline 1</title>
  <description>Body 1</description>
  <link>https://example.com/1</link>
  <pubDate>Mon, 01 May 2026 10:00:00 GMT</pubDate>
  <guid>g-1</guid>
</item>
<item>
  <title>Headline 2</title>
  <description>Body 2</description>
  <link>https://example.com/2</link>
  <pubDate>Tue, 02 May 2026 10:00:00 GMT</pubDate>
  <guid>g-2</guid>
</item>
</channel></rss>
XML;

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($rss);
        $response->method('getHeaders')->willReturn([
            'ETag'          => ['"new-etag"'],
            'Last-Modified' => ['Tue, 02 May 2026 10:00:00 GMT'],
        ]);

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $captured = null;
        $this->cacheMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function (FeedCache $r) use (&$captured): FeedCache {
                $captured = clone $r;
                return $r;
            });

        $result = $this->service->refreshFeed(feedUrl: 'https://example.com/rss');

        $this->assertSame(expected: 'ok', actual: $result['status']);
        $this->assertSame(expected: 2, actual: $result['itemCount']);
        $this->assertNotNull(actual: $captured);
        $this->assertSame(expected: '"new-etag"', actual: $captured->getEtag());
        $this->assertSame(
            expected: 'Tue, 02 May 2026 10:00:00 GMT',
            actual: $captured->getLastModified()
        );
        $this->assertNull(actual: $captured->getLastFailureReason());

        $items = $captured->decodeItems();
        // Sorted newest-first.
        $this->assertSame(expected: 'g-2', actual: $items[0]['guid']);
        $this->assertSame(expected: 'Example News', actual: $items[0]['sourceTitle']);
        $this->assertSame(expected: 'https://example.com/rss', actual: $items[0]['sourceUrl']);
    }//end testRefreshFeed200ParsesAndPersists()

    /**
     * `refreshFeed` on HTTP 500: records `"500 ..."` failure reason,
     * leaves prior `itemsJson` untouched (REQ-FRJ-006).
     *
     * @return void
     */
    public function testRefreshFeed500RecordsFailurePreservesItems(): void
    {
        $existing = new FeedCache();
        $existing->setFeedUrl('https://gone.example.com/rss');
        $existing->encodeItems(items: [['guid' => 'old']]);

        $this->configEmpty();
        $this->cacheMapper->expects($this->once())
            ->method('upsertUrl')
            ->willReturn($existing);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(503);
        $response->method('getBody')->willReturn('');
        $response->method('getHeaders')->willReturn([]);

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $captured = null;
        $this->cacheMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function (FeedCache $r) use (&$captured): FeedCache {
                $captured = clone $r;
                return $r;
            });

        $result = $this->service->refreshFeed(feedUrl: 'https://gone.example.com/rss');

        $this->assertSame(expected: 'failed', actual: $result['status']);
        $this->assertNotNull(actual: $captured);
        $this->assertStringStartsWith(prefix: '503', string: (string) $captured->getLastFailureReason());
        $this->assertCount(expectedCount: 1, haystack: $captured->decodeItems());
    }//end testRefreshFeed500RecordsFailurePreservesItems()

    /**
     * `refreshFeed` on transport exception (e.g. timeout): records
     * `"timeout: ..."` reason and does not propagate the exception
     * (REQ-FRJ-006).
     *
     * @return void
     */
    public function testRefreshFeedTimeoutRecorded(): void
    {
        $row = new FeedCache();
        $row->setFeedUrl('https://slow.example.com/rss');

        $this->configEmpty();
        $this->cacheMapper->expects($this->once())
            ->method('upsertUrl')
            ->willReturn($row);

        $this->client->expects($this->once())
            ->method('get')
            ->willThrowException(new RuntimeException('connect timeout after 10s'));

        $captured = null;
        $this->cacheMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function (FeedCache $r) use (&$captured): FeedCache {
                $captured = clone $r;
                return $r;
            });

        $result = $this->service->refreshFeed(feedUrl: 'https://slow.example.com/rss');

        $this->assertSame(expected: 'failed', actual: $result['status']);
        $this->assertNotNull(actual: $captured);
        $this->assertStringStartsWith(
            prefix: 'timeout:',
            string: (string) $captured->getLastFailureReason()
        );
    }//end testRefreshFeedTimeoutRecorded()

    /**
     * Disallowed host: skips the HTTP request entirely and records the
     * `"host not in allow-list"` reason (REQ-FRJ-011).
     *
     * @return void
     */
    public function testRefreshFeedAllowListSkip(): void
    {
        $row = new FeedCache();
        $row->setFeedUrl('https://blocked-site.com/feed');

        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === FeedRefreshService::CONFIG_KEY_ALLOWED_HOSTS) {
                    return 'bbc.com,example.org';
                }
                return $default;
            });
        $this->config->method('getSystemValue')->willReturn('');

        $this->cacheMapper->expects($this->once())
            ->method('upsertUrl')
            ->willReturn($row);

        $this->client->expects($this->never())->method('get');

        $captured = null;
        $this->cacheMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(static function (FeedCache $r) use (&$captured): FeedCache {
                $captured = clone $r;
                return $r;
            });

        $result = $this->service->refreshFeed(feedUrl: 'https://blocked-site.com/feed');

        $this->assertSame(expected: 'skipped', actual: $result['status']);
        $this->assertSame(
            expected: 'host not in allow-list',
            actual: $captured?->getLastFailureReason()
        );
    }//end testRefreshFeedAllowListSkip()

    /**
     * Allow-list hit: the HTTP request proceeds normally.
     *
     * @return void
     */
    public function testRefreshFeedAllowListHitFetches(): void
    {
        $row = new FeedCache();
        $row->setFeedUrl('https://bbc.com/news/rss.xml');

        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === FeedRefreshService::CONFIG_KEY_ALLOWED_HOSTS) {
                    return 'BBC.com';
                }
                return $default;
            });
        $this->config->method('getSystemValue')->willReturn('');

        $this->cacheMapper->expects($this->once())
            ->method('upsertUrl')
            ->willReturn($row);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(304);
        $response->method('getBody')->willReturn('');
        $response->method('getHeaders')->willReturn([]);

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $this->cacheMapper->expects($this->once())->method('update');

        $result = $this->service->refreshFeed(feedUrl: 'https://bbc.com/news/rss.xml');
        $this->assertSame(expected: 'not-modified', actual: $result['status']);
    }//end testRefreshFeedAllowListHitFetches()

    /**
     * `refreshAll` aggregates per-feed results; per-feed failures do
     * not stop subsequent feeds (REQ-FRJ-006).
     *
     * @return void
     */
    public function testRefreshAllAggregatesAndIsolatesFailures(): void
    {
        $placement = new WidgetPlacement();
        $placement->setStyleConfigArray(
            ['feedUrls' => ['https://a.com/rss', 'https://b.com/rss']]
        );
        $this->placementMapper->method('findByWidgetId')->willReturn([$placement]);

        $rowA = new FeedCache();
        $rowA->setFeedUrl('https://a.com/rss');
        $rowB = new FeedCache();
        $rowB->setFeedUrl('https://b.com/rss');

        $this->configEmpty();

        $this->cacheMapper->expects($this->exactly(2))
            ->method('upsertUrl')
            ->willReturnCallback(static function (string $url) use ($rowA, $rowB): FeedCache {
                return ($url === 'https://a.com/rss') ? $rowA : $rowB;
            });

        // Feed A succeeds with empty body, feed B raises.
        $okResponse = $this->createMock(IResponse::class);
        $okResponse->method('getStatusCode')->willReturn(304);
        $okResponse->method('getBody')->willReturn('');
        $okResponse->method('getHeaders')->willReturn([]);

        $callIndex = 0;
        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function () use (&$callIndex, $okResponse) {
                $callIndex++;
                if ($callIndex === 1) {
                    return $okResponse;
                }
                throw new RuntimeException('boom');
            });

        $this->cacheMapper->expects($this->exactly(2))->method('update');

        $summary = $this->service->refreshAll();
        $this->assertSame(expected: 2, actual: $summary['processedCount']);
        $this->assertSame(expected: 1, actual: $summary['successCount']);
        $this->assertSame(expected: 1, actual: $summary['failureCount']);
    }//end testRefreshAllAggregatesAndIsolatesFailures()

    /**
     * `refreshAll` short-circuits when no feeds are discovered.
     *
     * @return void
     */
    public function testRefreshAllNoFeedsReturnsZero(): void
    {
        $this->placementMapper->method('findByWidgetId')->willReturn([]);
        $this->configEmpty();

        $summary = $this->service->refreshAll();
        $this->assertSame(expected: 0, actual: $summary['processedCount']);
        $this->assertSame(expected: 0, actual: $summary['successCount']);
        $this->assertSame(expected: 0, actual: $summary['failureCount']);
    }//end testRefreshAllNoFeedsReturnsZero()

    /**
     * Configure the IConfig mock to return defaults / empty for every
     * lookup the service performs.
     *
     * @return void
     */
    private function configEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                return $default;
            });
        $this->config->method('getSystemValue')->willReturn('');
    }//end configEmpty()
}//end class
