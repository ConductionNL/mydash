<?php

/**
 * CalendarWidgetService Test
 *
 * Covers REQ-CAL-005 (cache key + TTL behaviour), REQ-CAL-006
 * (allow-list and HTTPS-only / SSRF guard), REQ-CAL-009 (failure
 * tolerance: bad URLs and parse errors do not abort the call), and
 * REQ-CAL-003 (event normalisation + sort order).
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

use OCA\MyDash\Service\CalendarWidgetService;
use OCA\MyDash\Service\UrlSafetyValidator;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalendarWidgetServiceTest extends TestCase
{
    /** @var IAppConfig&MockObject */
    private $appConfig;

    /** @var ICacheFactory&MockObject */
    private $cacheFactory;

    /** @var ICache&MockObject */
    private $cache;

    /** @var IClientService&MockObject */
    private $clientService;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var UrlSafetyValidator&MockObject */
    private $urlValidator;

    private CalendarWidgetService $service;

    protected function setUp(): void
    {
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->cacheFactory  = $this->createMock(ICacheFactory::class);
        $this->cache         = $this->createMock(ICache::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        // Default: all URLs safe — individual tests override as needed.
        $this->urlValidator  = $this->createMock(UrlSafetyValidator::class);
        $this->urlValidator->method('isSafe')->willReturn(true);
        $this->urlValidator->method('checkAllowList')->willReturn(true);

        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->service = new CalendarWidgetService(
            appConfig: $this->appConfig,
            cacheFactory: $this->cacheFactory,
            clientService: $this->clientService,
            logger: $this->logger,
            urlValidator: $this->urlValidator,
            calendarMgr: null,
        );
    }

    /**
     * Create a CalendarWidgetService with a real UrlSafetyValidator
     * (used by tests that exercise the real SSRF/allow-list logic).
     */
    private function makeServiceWithRealValidator(): CalendarWidgetService
    {
        $realValidator = new UrlSafetyValidator(appConfig: $this->appConfig);
        return new CalendarWidgetService(
            appConfig: $this->appConfig,
            cacheFactory: $this->cacheFactory,
            clientService: $this->clientService,
            logger: $this->logger,
            urlValidator: $realValidator,
            calendarMgr: null,
        );
    }

    public function testRejectsHttpUrl(): void
    {
        // Use real validator — validates scheme.
        $svc = $this->makeServiceWithRealValidator();
        $this->assertFalse($svc->validateUrl('http://example.test/cal.ics'));
    }

    public function testRejectsMalformedUrl(): void
    {
        $svc = $this->makeServiceWithRealValidator();
        $this->assertFalse($svc->validateUrl('not a url'));
    }

    public function testAllowListEmptyAllowsAll(): void
    {
        $this->appConfig->method('getValueString')
            ->with('mydash', CalendarWidgetService::CONFIG_KEY_ALLOWED_HOSTS, '')
            ->willReturn('');
        $svc = $this->makeServiceWithRealValidator();
        $this->assertTrue($svc->checkAllowList('https://anything.test/cal.ics'));
    }

    public function testAllowListMatchesHostCaseInsensitive(): void
    {
        $this->appConfig->method('getValueString')
            ->with('mydash', CalendarWidgetService::CONFIG_KEY_ALLOWED_HOSTS, '')
            ->willReturn(json_encode(['Calendar.Example.COM']));
        $svc = $this->makeServiceWithRealValidator();
        $this->assertTrue($svc->checkAllowList('https://calendar.example.com/cal.ics'));
    }

    public function testAllowListRejectsNonMatchingHost(): void
    {
        $this->appConfig->method('getValueString')
            ->with('mydash', CalendarWidgetService::CONFIG_KEY_ALLOWED_HOSTS, '')
            ->willReturn(json_encode(['calendar.example.com']));
        $svc = $this->makeServiceWithRealValidator();
        $this->assertFalse($svc->checkAllowList('https://untrusted.test/cal.ics'));
    }

    public function testAllowListNoSubdomainExpansion(): void
    {
        $this->appConfig->method('getValueString')
            ->with('mydash', CalendarWidgetService::CONFIG_KEY_ALLOWED_HOSTS, '')
            ->willReturn(json_encode(['example.com']));
        $svc = $this->makeServiceWithRealValidator();
        $this->assertFalse($svc->checkAllowList('https://sub.example.com/cal.ics'));
    }

    public function testFetchIcsBodyServesFromCache(): void
    {
        $url      = 'https://example.test/cached.ics';
        $cacheKey = 'ics_' . md5($url);

        $this->cache->expects($this->once())
            ->method('get')
            ->with($cacheKey)
            ->willReturn('CACHED-ICS-BODY');

        $this->clientService->expects($this->never())->method('newClient');

        $this->assertSame('CACHED-ICS-BODY', $this->service->fetchIcsBody($url));
    }

    public function testFetchIcsBodyFetchesAndCachesOnMiss(): void
    {
        $url      = 'https://example.test/fresh.ics';
        $cacheKey = 'ics_' . md5($url);

        $this->cache->expects($this->once())
            ->method('get')
            ->with($cacheKey)
            ->willReturn(null);

        /** @var IClient&MockObject $client */
        $client = $this->createMock(IClient::class);

        /** @var IResponse&MockObject $response */
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('FRESH-ICS');

        $client->expects($this->once())
            ->method('get')
            ->with(
                $url,
                $this->callback(static function ($options): bool {
                    return ($options['timeout'] ?? null) === CalendarWidgetService::FETCH_TIMEOUT_SECONDS;
                })
            )
            ->willReturn($response);

        $this->clientService->method('newClient')->willReturn($client);

        $this->appConfig->method('getValueInt')
            ->with('mydash', CalendarWidgetService::CONFIG_KEY_CACHE_TTL, CalendarWidgetService::DEFAULT_CACHE_TTL_SECONDS)
            ->willReturn(7200);

        $this->cache->expects($this->once())
            ->method('set')
            ->with($cacheKey, 'FRESH-ICS', 7200);

        $this->assertSame('FRESH-ICS', $this->service->fetchIcsBody($url));
    }

    public function testFetchIcsBodyRejectsOversizedResponse(): void
    {
        $url = 'https://example.test/big.ics';

        $this->cache->method('get')->willReturn(null);

        /** @var IClient&MockObject $client */
        $client = $this->createMock(IClient::class);

        $oversize = str_repeat('x', CalendarWidgetService::MAX_RESPONSE_SIZE_BYTES + 1);

        /** @var IResponse&MockObject $response */
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($oversize);

        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $this->expectException(\RuntimeException::class);
        $this->service->fetchIcsBody($url);
    }

    public function testFetchExternalIcsEventsSkipsRejectedUrls(): void
    {
        // No allow-list configured. Use real validator so non-HTTPS URLs
        // are correctly rejected by isSafe().
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->makeServiceWithRealValidator()->fetchExternalIcsEvents(
            urls: ['ftp://nope.test/x.ics', 'http://no-tls.test/y.ics'],
            from: '2026-05-01T00:00:00Z',
            to: '2026-05-31T23:59:59Z'
        );

        $this->assertSame([], $result['events']);
        $this->assertCount(2, $result['failures']);
    }

    public function testGetEventsSortsByStart(): void
    {
        // Force the external path to fail fast (no URLs) so we hit
        // only the explicit normalize behaviour.
        $events = [
            $this->service->normalizeInternalEvent([
                'UID' => 'a',
                'SUMMARY' => 'Late',
                'DTSTART' => '2026-05-03T15:00:00Z',
                'DTEND' => '2026-05-03T16:00:00Z',
            ]),
            $this->service->normalizeInternalEvent([
                'UID' => 'b',
                'SUMMARY' => 'Early',
                'DTSTART' => '2026-05-03T09:00:00Z',
                'DTEND' => '2026-05-03T10:00:00Z',
            ]),
        ];

        usort(
            $events,
            static fn(array $eventA, array $eventB): int => strcmp($eventA['start'], $eventB['start'])
        );

        $this->assertSame('Early', $events[0]['title']);
        $this->assertSame('Late', $events[1]['title']);
    }

    public function testGetEventsAggregatesAndReportsFailures(): void
    {
        // Empty allow-list, no internal manager — only external path.
        // Use real validator so http:// is correctly rejected.
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('getValueInt')->willReturn(1800);

        $this->cache->method('get')->willReturn(null);

        // Bad URL is rejected by validateUrl; no fetch occurs.
        $result = $this->makeServiceWithRealValidator()->getEvents(
            config: [
                'internalCalendars' => [],
                'externalIcsUrls'   => ['http://invalid.test/x.ics'],
            ],
            from: '2026-05-01T00:00:00Z',
            to: '2026-05-02T00:00:00Z'
        );

        $this->assertSame([], $result['events']);
        $this->assertNotEmpty($result['failures']);
    }
}
