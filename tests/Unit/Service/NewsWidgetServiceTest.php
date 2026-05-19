<?php

/**
 * NewsWidgetService Test
 *
 * Covers REQ-NEWS-002 (config extraction with defaults), REQ-NEWS-003
 * (parse / merge / dedupe / sort), REQ-NEWS-005 (HTML sanitisation),
 * REQ-NEWS-006 (allow-list), REQ-NEWS-007 (metadata filter), and
 * REQ-NEWS-008 (failure tolerance).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\NewsWidgetService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Small]
class NewsWidgetServiceTest extends TestCase
{

    private NewsWidgetService $service;

    private WidgetPlacementMapper $placementMapper;

    private IClientService $clientService;

    private IAppConfig $appConfig;

    private ICacheFactory $cacheFactory;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->placementMapper = $this->createMock(originalClassName: WidgetPlacementMapper::class);
        $this->clientService   = $this->createMock(originalClassName: IClientService::class);
        $this->appConfig       = $this->createMock(originalClassName: IAppConfig::class);
        $this->cacheFactory    = $this->createMock(originalClassName: ICacheFactory::class);
        $cache = $this->createMock(originalClassName: ICache::class);
        $cache->method('get')->willReturn(null);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);
        $this->logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new NewsWidgetService(
            placementMapper: $this->placementMapper,
            clientService: $this->clientService,
            appConfig: $this->appConfig,
            cacheFactory: $this->cacheFactory,
            logger: $this->logger
        );
    }//end setUp()

    public function testExtractNewsConfigAppliesDefaults(): void
    {
        $placement = new WidgetPlacement();
        $placement->setStyleConfig(null);

        $config = $this->service->extractNewsConfig(placement: $placement);

        $this->assertSame([], $config['feedUrls']);
        $this->assertSame('list', $config['layout']);
        $this->assertSame(10, $config['itemLimit']);
        $this->assertTrue($config['showThumbnails']);
        $this->assertTrue($config['showSummary']);
        $this->assertSame(200, $config['summaryMaxChars']);
        $this->assertSame('relative', $config['dateFormat']);
        $this->assertNull($config['metadataFilter']);
    }//end testExtractNewsConfigAppliesDefaults()

    public function testExtractNewsConfigParsesTopLevelJson(): void
    {
        $placement = new WidgetPlacement();
        $placement->setStyleConfig(
                json_encode(
                value: [
                    'feedUrls'        => [
                        'https://example.com/rss',
                        'ftp://nope.example.com/feed',
                        '',
                    ],
                    'layout'          => 'grid',
                    'itemLimit'       => 25,
                    'showThumbnails'  => false,
                    'showSummary'     => false,
                    'summaryMaxChars' => 150,
                    'dateFormat'      => 'absolute',
                    'metadataFilter'  => ['fieldKey' => 'department', 'value' => 'marketing'],
                ]
                )
                );

        $config = $this->service->extractNewsConfig(placement: $placement);

        $this->assertSame(['https://example.com/rss'], $config['feedUrls']);
        $this->assertSame('grid', $config['layout']);
        $this->assertSame(25, $config['itemLimit']);
        $this->assertFalse($config['showThumbnails']);
        $this->assertFalse($config['showSummary']);
        $this->assertSame(150, $config['summaryMaxChars']);
        $this->assertSame('absolute', $config['dateFormat']);
        $this->assertSame(['fieldKey' => 'department', 'value' => 'marketing'], $config['metadataFilter']);
    }//end testExtractNewsConfigParsesTopLevelJson()

    public function testExtractNewsConfigUnwrapsNestedContent(): void
    {
        $placement = new WidgetPlacement();
        $placement->setStyleConfig(
                json_encode(
                value: [
                    'type'    => 'news',
                    'content' => [
                        'feedUrls' => ['https://example.com/feed'],
                        'layout'   => 'carousel',
                    ],
                ]
                )
                );

        $config = $this->service->extractNewsConfig(placement: $placement);

        $this->assertSame(['https://example.com/feed'], $config['feedUrls']);
        $this->assertSame('carousel', $config['layout']);
    }//end testExtractNewsConfigUnwrapsNestedContent()

    public function testExtractNewsConfigClampsItemLimit(): void
    {
        $placement = new WidgetPlacement();
        $placement->setStyleConfig(json_encode(value: ['itemLimit' => 999]));

        $config = $this->service->extractNewsConfig(placement: $placement);

        $this->assertSame(50, $config['itemLimit']);
    }//end testExtractNewsConfigClampsItemLimit()

    public function testParseRssFeedExtractsItemFields(): void
    {
        $rss = '<?xml version="1.0"?>
<rss version="2.0"><channel>
  <title>Example News</title>
  <item>
    <title>Hello</title>
    <link>https://example.com/a</link>
    <guid>article-1</guid>
    <pubDate>Mon, 01 May 2026 14:00:00 +0000</pubDate>
    <description>&lt;p&gt;Body&lt;/p&gt;</description>
  </item>
  <item>
    <title>Second</title>
    <link>https://example.com/b</link>
    <guid>article-2</guid>
    <pubDate>Mon, 01 May 2026 16:00:00 +0000</pubDate>
    <description>Plain body</description>
  </item>
</channel></rss>';

        $items = $this->service->parseRssFeed(
            feedContent: $rss,
            sourceUrl: 'https://example.com/rss',
            sourceTitle: 'fallback'
        );

        $this->assertCount(2, $items);
        $this->assertSame('article-1', $items[0]['guid']);
        $this->assertSame('Hello', $items[0]['title']);
        $this->assertSame('https://example.com/a', $items[0]['link']);
        $this->assertSame('Example News', $items[0]['sourceTitle']);
        $this->assertStringContainsString('<p>Body</p>', $items[0]['summary']);
    }//end testParseRssFeedExtractsItemFields()

    public function testParseRssFeedAcceptsAtom(): void
    {
        $atom = '<?xml version="1.0"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom Source</title>
  <entry>
    <id>urn:1</id>
    <title>Atom one</title>
    <link href="https://example.com/atom-one"/>
    <updated>2026-05-01T12:00:00Z</updated>
    <summary>Atom summary</summary>
  </entry>
</feed>';

        $items = $this->service->parseRssFeed(
            feedContent: $atom,
            sourceUrl: 'https://example.com/atom',
            sourceTitle: 'fallback'
        );

        $this->assertCount(1, $items);
        $this->assertSame('urn:1', $items[0]['guid']);
        $this->assertSame('Atom one', $items[0]['title']);
        $this->assertSame('https://example.com/atom-one', $items[0]['link']);
        $this->assertSame('Atom Source', $items[0]['sourceTitle']);
    }//end testParseRssFeedAcceptsAtom()

    public function testParseRssFeedReturnsEmptyOnGarbage(): void
    {
        $items = $this->service->parseRssFeed(
            feedContent: '<<not xml>>',
            sourceUrl: 'https://x.example.com/bad',
            sourceTitle: 'bad'
        );

        $this->assertSame([], $items);
    }//end testParseRssFeedReturnsEmptyOnGarbage()

    public function testDeduplicateItemsKeepsFirstOccurrence(): void
    {
        $items = [
            ['guid' => 'a', 'title' => 'first'],
            ['guid' => 'b', 'title' => 'second'],
            ['guid' => 'a', 'title' => 'duplicate'],
        ];

        $out = $this->service->deduplicateItems(items: $items);

        $this->assertCount(2, $out);
        $this->assertSame('first', $out[0]['title']);
        $this->assertSame('second', $out[1]['title']);
    }//end testDeduplicateItemsKeepsFirstOccurrence()

    public function testSortItemsByDateDescending(): void
    {
        $items = [
            ['guid' => '1', 'pubDate' => '2026-04-30T10:00:00Z'],
            ['guid' => '2', 'pubDate' => '2026-05-01T16:00:00Z'],
            ['guid' => '3', 'pubDate' => '2026-05-01T14:00:00Z'],
        ];

        $sorted = $this->service->sortItemsByDate(items: $items);

        $this->assertSame('2', $sorted[0]['guid']);
        $this->assertSame('3', $sorted[1]['guid']);
        $this->assertSame('1', $sorted[2]['guid']);
    }//end testSortItemsByDateDescending()

    public function testSanitiseSummaryHtmlAllowsWhitelistedTags(): void
    {
        $html = '<p>Read our <strong>latest</strong> post</p>';

        $clean = $this->service->sanitiseSummaryHtml(html: $html);

        $this->assertStringContainsString('<p>', $clean);
        $this->assertStringContainsString('<strong>', $clean);
    }//end testSanitiseSummaryHtmlAllowsWhitelistedTags()

    public function testSanitiseSummaryStripsScriptTags(): void
    {
        $html = '<p>Hi</p><script>alert(1)</script><iframe src="x"></iframe>';

        $clean = $this->service->sanitiseSummaryHtml(html: $html);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
    }//end testSanitiseSummaryStripsScriptTags()

    public function testSanitiseSummaryForcesRelOnLinks(): void
    {
        $html = '<p><a href="https://example.com">Link</a></p>';

        $clean = $this->service->sanitiseSummaryHtml(html: $html);

        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
    }//end testSanitiseSummaryForcesRelOnLinks()

    public function testSanitiseSummaryNeutralisesJavascriptHref(): void
    {
        $html = '<a href="javascript:alert(1)">danger</a>';

        $clean = $this->service->sanitiseSummaryHtml(html: $html);

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('href="#"', $clean);
    }//end testSanitiseSummaryNeutralisesJavascriptHref()

    public function testCheckAllowListAcceptsAllWhenEmpty(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('');

        $this->assertTrue($this->service->checkAllowList(url: 'https://anything.example.com/feed'));
    }//end testCheckAllowListAcceptsAllWhenEmpty()

    public function testCheckAllowListMatchesCaseInsensitively(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn(json_encode(value: ['Example.Org']));

        $this->assertTrue($this->service->checkAllowList(url: 'https://example.org/feed'));
    }//end testCheckAllowListMatchesCaseInsensitively()

    public function testCheckAllowListRejectsHostnamesNotInList(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn(json_encode(value: ['allowed.example.com']));

        $this->assertFalse($this->service->checkAllowList(url: 'https://blocked.example.org/feed'));
    }//end testCheckAllowListRejectsHostnamesNotInList()

    public function testCheckAllowListSubdomainNotImplied(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn(json_encode(value: ['example.org']));

        // Per spec REQ-NEWS-006: exact hostname required, no wildcard
        // subdomain expansion.
        $this->assertFalse($this->service->checkAllowList(url: 'https://news.example.org/feed'));
    }//end testCheckAllowListSubdomainNotImplied()

    public function testCheckMetadataFilterRejectsWhenSpecNotImplemented(): void
    {
        // dashboard-metadata-fields capability not on this branch — the
        // filter must conservatively return false (treat missing field
        // as null which never matches a configured equality).
        $this->assertFalse(
            $this->service->checkMetadataFilter(
                dashboardId: 1,
                metadataFilter: ['fieldKey' => 'department', 'value' => 'marketing']
            )
        );
    }//end testCheckMetadataFilterRejectsWhenSpecNotImplemented()

    public function testFetchAndMergeFeedsWithEmptyListReturnsEmptyResponse(): void
    {
        $response = $this->service->fetchAndMergeFeeds(feedUrls: [], limit: 10);

        $this->assertSame([], $response['items']);
        $this->assertSame(0, $response['feedsFailed']);
        $this->assertSame([], $response['failedUrls']);
    }//end testFetchAndMergeFeedsWithEmptyListReturnsEmptyResponse()
}//end class
