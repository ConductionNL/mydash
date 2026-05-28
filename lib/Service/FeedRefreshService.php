<?php

/**
 * FeedRefreshService
 *
 * Discovers active news-widget feed URLs, fetches each feed via
 * Nextcloud's `IClientService` with HTTP conditional-get headers,
 * normalises RSS 2.0 / Atom 1.0 items to the news-widget schema, caps at
 * 50 items, and persists the cache row in `oc_mydash_feed_cache`. Each
 * feed is wrapped in its own try/catch so a single failure does not
 * block the rest (REQ-FRJ-006).
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DateTime;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\FeedCache;
use OCA\MyDash\Db\FeedCacheMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Background-job feed refresh service (REQ-FRJ-001..012).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class FeedRefreshService
{
    /**
     * Maximum response body size accepted from a feed server (10 MB).
     * Larger responses are rejected with `lastFailureReason = "response
     * too large"` (REQ-FRJ-005, design D3).
     *
     * @var integer
     */
    public const MAX_RESPONSE_SIZE = (10 * 1024 * 1024);

    /**
     * Default HTTP connect timeout in seconds (design D4).
     *
     * @var integer
     */
    public const CONNECT_TIMEOUT = 10;

    /**
     * Default HTTP total request timeout in seconds (design D4).
     *
     * @var integer
     */
    public const REQUEST_TIMEOUT = 30;

    /**
     * Per-tick batch size — feeds beyond this index are deferred to the
     * next tick via the cursor (REQ-FRJ-008).
     *
     * @var integer
     */
    public const BATCH_SIZE = 500;

    /**
     * Per-tick wall-clock budget in seconds (REQ-FRJ-008).
     *
     * @var integer
     */
    public const WALL_CLOCK_BUDGET = 300;

    /**
     * App config key — admin-tunable allow-list of feed hostnames
     * (REQ-FRJ-011). Empty string (default) means "no restrictions".
     *
     * @var string
     */
    public const CONFIG_KEY_ALLOWED_HOSTS = 'news_widget_allowed_feed_hosts';

    /**
     * App config key — batch cursor for resuming work across ticks
     * (REQ-FRJ-008).
     *
     * @var string
     */
    public const CONFIG_KEY_CURSOR = 'feed_refresh_cursor';

    /**
     * The widget id used by news placements. Discovery filters
     * placements on this exact id (REQ-FRJ-003).
     *
     * @var string
     */
    public const NEWS_WIDGET_ID = 'mydash_news';

    /**
     * Constructor.
     *
     * @param IClientService        $clientService   The HTTP client factory.
     * @param IAppConfig            $appConfig       The app config reader.
     * @param IConfig               $config          The system config reader.
     * @param FeedCacheMapper       $cacheMapper     The feed-cache mapper.
     * @param WidgetPlacementMapper $placementMapper The widget-placement mapper.
     * @param LoggerInterface       $logger          The diagnostic logger.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig,
        private readonly IConfig $config,
        private readonly FeedCacheMapper $cacheMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Discover the deduplicated, sorted set of active feed URLs from
     * news-widget placements (REQ-FRJ-003).
     *
     * The current schema does not yet carry a `widget_content` column on
     * `oc_mydash_widget_placements`; the news widget is a sibling spec.
     * Until the news widget lands, discovery returns an empty array if
     * no `mydash_news` placement is found OR if the placement does not
     * expose any feed URLs through the JSON-encoded `style_config`
     * fallback. The contract is forward-compatible: when the news widget
     * adds feed-URL storage, this method picks it up automatically via
     * the JSON-decode path.
     *
     * @return string[] The deduplicated, sorted feed URL list.
     *
     * @spec openspec/specs/background-job-feed-refresh/spec.md
     */
    public function discoverFeedUrls(): array
    {
        $rawUrls = [];

        try {
            $placements = $this->placementMapper->findByWidgetId(
                widgetId: self::NEWS_WIDGET_ID
            );
        } catch (Throwable $exception) {
            // News widget not yet wired up — treat as zero placements.
            $this->logger->debug(
                message: 'FeedRefreshService discovery skipped',
                context: [
                    'app'       => Application::APP_ID,
                    'exception' => $exception->getMessage(),
                ]
            );
            return [];
        }

        foreach ($placements as $placement) {
            $config = $placement->getStyleConfigArray();
            $urls   = ($config['feedUrls'] ?? []);
            if (is_array(value: $urls) === false) {
                continue;
            }

            foreach ($urls as $url) {
                if (is_string(value: $url) === true && $url !== '') {
                    $rawUrls[] = $url;
                }
            }
        }

        $unique = array_values(array: array_unique(array: $rawUrls));
        sort(array: $unique);

        return $unique;
    }//end discoverFeedUrls()

    /**
     * Refresh a single feed URL — performs HTTP conditional GET, parses
     * on 200, normalises items, persists the cache row. All failures
     * (transport, HTTP 4xx/5xx, parse error, allow-list reject, size
     * cap) are caught and recorded in `lastFailureReason` (REQ-FRJ-006).
     *
     * @param string $feedUrl The feed URL.
     *
     * @return array{status: string, itemCount: int, durationMs: int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/background-job-feed-refresh/spec.md
     */
    public function refreshFeed(string $feedUrl): array
    {
        $startedAt = (int) (microtime(as_float: true) * 1000);
        $row       = $this->cacheMapper->upsertUrl(feedUrl: $feedUrl);
        $now       = (new DateTime())->format(format: 'Y-m-d H:i:s');

        // Allow-list pre-check (REQ-FRJ-011).
        if ($this->isHostAllowed(feedUrl: $feedUrl) === false) {
            $row->setLastFailureReason('host not in allow-list');
            $row->setLastFetchedAt($now);
            $this->cacheMapper->update(entity: $row);
            $this->logger->warning(
                message: 'Feed host not in allow-list — skipping fetch',
                context: [
                    'app'     => Application::APP_ID,
                    'feedUrl' => $feedUrl,
                ]
            );
            return [
                'status'     => 'skipped',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        // H3: SSRF guard — reject URLs that resolve to private/reserved IP
        // ranges and enforce HTTPS-only. Mirrors CalendarWidgetService::validateUrl.
        if ($this->isPrivateIpGuarded(feedUrl: $feedUrl) === false) {
            $row->setLastFailureReason('SSRF guard: private/reserved IP or non-HTTPS URL rejected');
            $row->setLastFetchedAt($now);
            $this->cacheMapper->update(entity: $row);
            $this->logger->warning(
                message: 'Feed URL rejected by SSRF guard — private IP or non-HTTPS',
                context: [
                    'app'     => Application::APP_ID,
                    'feedUrl' => $feedUrl,
                ]
            );
            return [
                'status'     => 'failed',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        // Scheme guard — only HTTP/HTTPS (REQ-FRJ-010 scenario "Invalid
        // feedUrl scheme rejected" applies at the controller layer; here
        // we double-check at service layer for defence in depth).
        $scheme = strtolower(string: (string) parse_url(url: $feedUrl, component: PHP_URL_SCHEME));
        if (in_array(needle: $scheme, haystack: ['http', 'https'], strict: true) === false) {
            $row->setLastFailureReason('invalid scheme: '.$scheme);
            $row->setLastFetchedAt($now);
            $this->cacheMapper->update(entity: $row);
            return [
                'status'     => 'failed',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        try {
            $response = $this->doConditionalGet(row: $row, feedUrl: $feedUrl);
        } catch (Throwable $exception) {
            $row->setLastFailureReason(
                $this->classifyTransportError(exception: $exception)
            );
            $row->setLastFetchedAt($now);
            $this->cacheMapper->update(entity: $row);
            return [
                'status'     => 'failed',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        $statusCode = (int) $response['status'];
        $row->setLastFetchedAt($now);

        if ($statusCode === 304) {
            // Items untouched — only lastFetchedAt is updated
            // (REQ-FRJ-004 "items untouched, only lastFetchedAt updated").
            $this->cacheMapper->update(entity: $row);
            return [
                'status'     => 'not-modified',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $row->setLastFailureReason($statusCode.' '.$response['reason']);
            $this->cacheMapper->update(entity: $row);
            return [
                'status'     => 'failed',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        // Size cap (REQ-FRJ-005 design D3).
        if (strlen(string: $response['body']) > self::MAX_RESPONSE_SIZE) {
            $row->setLastFailureReason('response too large');
            $this->cacheMapper->update(entity: $row);
            return [
                'status'     => 'failed',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        try {
            $items = $this->parseFeedBody(
                body: $response['body'],
                feedUrl: $feedUrl
            );
        } catch (Throwable $exception) {
            $row->setLastFailureReason(
                'parse error: '.$exception->getMessage()
            );
            $this->cacheMapper->update(entity: $row);
            return [
                'status'     => 'failed',
                'itemCount'  => count(value: $row->decodeItems()),
                'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        $row->encodeItems(items: $items);
        $row->setLastSuccessAt($now);
        $row->setLastFailureReason(null);
        $row->setEtag($response['etag']);
        $row->setLastModified($response['lastModified']);
        $this->cacheMapper->update(entity: $row);

        return [
            'status'     => 'ok',
            'itemCount'  => count(value: $items),
            'durationMs' => ((int) (microtime(as_float: true) * 1000) - $startedAt),
        ];
    }//end refreshFeed()

    /**
     * Refresh all discovered feed URLs (or a single one when
     * `$onlyUrl` is provided). Honours the per-tick wall-clock budget
     * and the cursor-based batch state (REQ-FRJ-008).
     *
     * @param string|null $onlyUrl Optional single URL to refresh.
     *
     * @return array{processedCount: int, successCount: int, failureCount: int, durationMs: int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/background-job-feed-refresh/spec.md
     */
    public function refreshAll(?string $onlyUrl=null): array
    {
        $startedAt = (int) (microtime(as_float: true) * 1000);

        if ($onlyUrl !== null) {
            $urls = [$onlyUrl];
        } else {
            $urls = $this->discoverFeedUrls();
        }

        if (count(value: $urls) === 0) {
            $this->logger->info(
                message: 'No news widget placements found; nothing to refresh',
                context: ['app' => Application::APP_ID]
            );
            return [
                'processedCount' => 0,
                'successCount'   => 0,
                'failureCount'   => 0,
                'durationMs'     => ((int) (microtime(as_float: true) * 1000) - $startedAt),
            ];
        }

        // Apply cursor — only relevant for the full-refresh path.
        $startIndex = 0;
        if ($onlyUrl === null) {
            $cursor = $this->appConfig->getValueString(
                Application::APP_ID,
                self::CONFIG_KEY_CURSOR,
                ''
            );
            if ($cursor !== '') {
                $cursorPos = array_search(needle: $cursor, haystack: $urls, strict: true);
                if ($cursorPos === false) {
                    // Cursor stale (URL no longer in set) — restart.
                    $startIndex = 0;
                } else {
                    $startIndex = ((int) $cursorPos + 1);
                }
            }
        }

        $processed = 0;
        $success   = 0;
        $failure   = 0;

        $deadline   = (microtime(as_float: true) + self::WALL_CLOCK_BUDGET);
        $batchLimit = ($startIndex + self::BATCH_SIZE);
        $urlCount   = count(value: $urls);

        for ($index = $startIndex; $index < $urlCount; $index++) {
            if ($index >= $batchLimit) {
                break;
            }

            if (microtime(as_float: true) >= $deadline) {
                break;
            }

            $feedUrl = $urls[$index];
            try {
                $result = $this->refreshFeed(feedUrl: $feedUrl);
                $processed++;
                if ($result['status'] === 'ok' || $result['status'] === 'not-modified') {
                    $success++;
                } else {
                    $failure++;
                }
            } catch (Throwable $exception) {
                // RefreshFeed swallows all per-feed exceptions — this is
                // a defence-in-depth catch in case of a bug there.
                $processed++;
                $failure++;
                $this->logger->error(
                    message: 'FeedRefreshService unexpected refreshFeed exception',
                    context: [
                        'app'       => Application::APP_ID,
                        'feedUrl'   => $feedUrl,
                        'exception' => $exception->getMessage(),
                    ]
                );
            }//end try
        }//end for

        // Cursor bookkeeping — full refresh only.
        if ($onlyUrl === null) {
            $endIndex = ($startIndex + $processed);
            if ($endIndex >= $urlCount) {
                // Completed the full set — clear cursor.
                $this->appConfig->deleteKey(
                    Application::APP_ID,
                    self::CONFIG_KEY_CURSOR
                );
            } else {
                // More to do next tick — persist cursor at last URL processed.
                $lastIndex = ($endIndex - 1);
                if ($lastIndex >= 0 && $lastIndex < $urlCount) {
                    $this->appConfig->setValueString(
                        Application::APP_ID,
                        self::CONFIG_KEY_CURSOR,
                        $urls[$lastIndex]
                    );
                }
            }
        }

        return [
            'processedCount' => $processed,
            'successCount'   => $success,
            'failureCount'   => $failure,
            'durationMs'     => ((int) (microtime(as_float: true) * 1000) - $startedAt),
        ];
    }//end refreshAll()

    /**
     * Perform the conditional HTTP GET. Returns a normalised response
     * dict with keys: status, reason, body, etag, lastModified.
     *
     * @param FeedCache $row     The cache row (for ETag/Last-Modified).
     * @param string    $feedUrl The feed URL.
     *
     * @return array{status: int, reason: string, body: string, etag: ?string, lastModified: ?string}
     */
    private function doConditionalGet(
        FeedCache $row,
        string $feedUrl
    ): array {
        $client = $this->clientService->newClient();

        $headers = [
            'User-Agent' => $this->buildUserAgent(),
            'Accept'     => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5',
        ];

        if ($row->getEtag() !== null && $row->getEtag() !== '') {
            $headers['If-None-Match'] = (string) $row->getEtag();
        }

        if ($row->getLastModified() !== null && $row->getLastModified() !== '') {
            $headers['If-Modified-Since'] = (string) $row->getLastModified();
        }

        $options = [
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout'         => self::REQUEST_TIMEOUT,
            'headers'         => $headers,
            // 304 must NOT be treated as an HTTP exception by Guzzle.
            'http_errors'     => false,
        ];

        $proxy = (string) $this->config->getSystemValue('proxy', '');
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
            $proxyAuth        = (string) $this->config->getSystemValue('proxyuserpwd', '');
            if ($proxyAuth !== '') {
                $options['headers']['Proxy-Authorization'] = 'Basic '.base64_encode(string: $proxyAuth);
            }
        }

        $response = $client->get(uri: $feedUrl, options: $options);

        $headersOut = $response->getHeaders();

        return [
            'status'       => (int) $response->getStatusCode(),
            'reason'       => $this->extractReason(response: $response),
            'body'         => (string) $response->getBody(),
            'etag'         => $this->firstHeader(headers: $headersOut, name: 'ETag'),
            'lastModified' => $this->firstHeader(headers: $headersOut, name: 'Last-Modified'),
        ];
    }//end doConditionalGet()

    /**
     * Parse an RSS 2.0 / Atom 1.0 feed body and return normalised items
     * sorted newest-first (REQ-FRJ-005).
     *
     * @param string $body    The XML body.
     * @param string $feedUrl The feed URL (for sourceUrl/sourceTitle).
     *
     * @return array<int, array<string, mixed>> The normalised items.
     *
     * @throws RuntimeException When parsing fails.
     */
    private function parseFeedBody(string $body, string $feedUrl): array
    {
        $previous = libxml_use_internal_errors(use_errors: true);
        try {
            // C2: LIBXML_NOENT removed — it resolves (not disables) entities,
            // enabling XXE. LIBXML_NONET blocks external DTD/entity fetches.
            // L1: LIBXML_NOCDATA removed — it folds CDATA sections into text
            // nodes, which can mask sanitisation logic on downstream callers.
            $xml = simplexml_load_string(
                data: $body,
                class_name: 'SimpleXMLElement',
                options: LIBXML_NONET
            );
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $message = 'malformed XML';
                if (isset($errors[0]) === true) {
                    $message = trim(string: $errors[0]->message);
                }

                throw new RuntimeException(message: $message);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors(use_errors: $previous);
        }

        $sourceTitle = $this->extractFeedTitle(xml: $xml, feedUrl: $feedUrl);
        $items       = [];

        // RSS 2.0 — <channel><item>...
        if (isset($xml->channel) === true && isset($xml->channel->item) === true) {
            foreach ($xml->channel->item as $item) {
                $items[] = $this->normaliseRssItem(
                    item: $item,
                    feedUrl: $feedUrl,
                    sourceTitle: $sourceTitle
                );
            }
        } else if (isset($xml->entry) === true) {
            // Atom 1.0 — <feed><entry>...
            foreach ($xml->entry as $entry) {
                $items[] = $this->normaliseAtomEntry(
                    entry: $entry,
                    feedUrl: $feedUrl,
                    sourceTitle: $sourceTitle
                );
            }
        }

        // Sort newest-first by pubDate.
        usort(
            array: $items,
            callback: static function (array $left, array $right): int {
                $leftRaw  = strtotime(datetime: (string) ($left['pubDate'] ?? ''));
                $rightRaw = strtotime(datetime: (string) ($right['pubDate'] ?? ''));
                $leftTs   = 0;
                if ($leftRaw !== false) {
                    $leftTs = $leftRaw;
                }

                $rightTs = 0;
                if ($rightRaw !== false) {
                    $rightTs = $rightRaw;
                }

                return ($rightTs <=> $leftTs);
            }
        );

        return $items;
    }//end parseFeedBody()

    /**
     * Extract the channel-level title (RSS) or feed-level title (Atom),
     * falling back to the URL hostname.
     *
     * @param SimpleXMLElement $xml     The parsed feed.
     * @param string           $feedUrl The feed URL.
     *
     * @return string The source title.
     */
    private function extractFeedTitle(
        SimpleXMLElement $xml,
        string $feedUrl
    ): string {
        if (isset($xml->channel) === true && isset($xml->channel->title) === true) {
            $title = trim(string: (string) $xml->channel->title);
            if ($title !== '') {
                return $title;
            }
        }

        if (isset($xml->title) === true) {
            $title = trim(string: (string) $xml->title);
            if ($title !== '') {
                return $title;
            }
        }

        $host = (string) parse_url(url: $feedUrl, component: PHP_URL_HOST);
        if ($host !== '') {
            return $host;
        }

        return $feedUrl;
    }//end extractFeedTitle()

    /**
     * Normalise a single RSS 2.0 item to the news-widget schema.
     *
     * @param SimpleXMLElement $item        The RSS item element.
     * @param string           $feedUrl     The feed URL.
     * @param string           $sourceTitle The channel title.
     *
     * @return array<string, mixed>
     */
    private function normaliseRssItem(
        SimpleXMLElement $item,
        string $feedUrl,
        string $sourceTitle
    ): array {
        $title     = trim(string: (string) ($item->title ?? ''));
        $summary   = trim(string: (string) ($item->description ?? ''));
        $link      = trim(string: (string) ($item->link ?? ''));
        $pubDate   = trim(string: (string) ($item->pubDate ?? ''));
        $guidValue = trim(string: (string) ($item->guid ?? ''));
        $thumbnail = null;

        if (isset($item->enclosure) === true) {
            $attrs = $item->enclosure->attributes();
            if ($attrs !== null && isset($attrs['type']) === true) {
                $type = (string) $attrs['type'];
                if (str_starts_with(haystack: $type, needle: 'image/') === true) {
                    $thumbnail = (string) $attrs['url'];
                }
            }
        }

        if ($guidValue !== '') {
            $guid = $guidValue;
        } else {
            $guid = hash(algo: 'sha256', data: ($title.$pubDate.$feedUrl));
        }

        return [
            'guid'         => $guid,
            'title'        => $title,
            'summary'      => $summary,
            'link'         => $link,
            'pubDate'      => $pubDate,
            'sourceUrl'    => $feedUrl,
            'sourceTitle'  => $sourceTitle,
            'thumbnailUrl' => $thumbnail,
        ];
    }//end normaliseRssItem()

    /**
     * Normalise a single Atom 1.0 entry to the news-widget schema.
     *
     * @param SimpleXMLElement $entry       The Atom entry element.
     * @param string           $feedUrl     The feed URL.
     * @param string           $sourceTitle The feed title.
     *
     * @return array<string, mixed>
     */
    private function normaliseAtomEntry(
        SimpleXMLElement $entry,
        string $feedUrl,
        string $sourceTitle
    ): array {
        $title   = trim(string: (string) ($entry->title ?? ''));
        $summary = trim(string: (string) ($entry->summary ?? ''));
        if ($summary === '') {
            $summary = trim(string: (string) ($entry->content ?? ''));
        }

        $link = '';
        if (isset($entry->link) === true) {
            foreach ($entry->link as $linkElement) {
                $attrs = $linkElement->attributes();
                if ($attrs === null) {
                    continue;
                }

                $rel  = (string) ($attrs['rel'] ?? 'alternate');
                $href = (string) ($attrs['href'] ?? '');
                if ($rel === 'alternate' && $href !== '') {
                    $link = $href;
                    break;
                }
            }
        }

        $pubDate = trim(string: (string) ($entry->published ?? ''));
        if ($pubDate === '') {
            $pubDate = trim(string: (string) ($entry->updated ?? ''));
        }

        $idValue = trim(string: (string) ($entry->id ?? ''));
        if ($idValue !== '') {
            $guid = $idValue;
        } else {
            $guid = hash(algo: 'sha256', data: ($title.$pubDate.$feedUrl));
        }

        return [
            'guid'         => $guid,
            'title'        => $title,
            'summary'      => $summary,
            'link'         => $link,
            'pubDate'      => $pubDate,
            'sourceUrl'    => $feedUrl,
            'sourceTitle'  => $sourceTitle,
            'thumbnailUrl' => null,
        ];
    }//end normaliseAtomEntry()

    /**
     * Build the User-Agent header per REQ-FRJ-012.
     *
     * @return string The User-Agent string.
     */
    private function buildUserAgent(): string
    {
        $appVersion  = $this->appConfig->getValueString(
            Application::APP_ID,
            'installed_version',
            '1.0.0'
        );
        $instanceUrl = (string) $this->config->getSystemValue(
            'overwrite.cli.url',
            ''
        );
        if ($instanceUrl === '') {
            $instanceUrl = 'https://localhost';
        }

        return sprintf(
            'Mozilla/5.0 (compatible; MyDash/%s; +%s/apps/mydash)',
            $appVersion,
            rtrim(string: $instanceUrl, characters: '/')
        );
    }//end buildUserAgent()

    /**
     * Whether the URL's host matches the admin allow-list.
     *
     * Empty allow-list (default) accepts all hosts.
     *
     * @param string $feedUrl The feed URL.
     *
     * @return bool True if the host is allowed.
     */
    /**
     * Guard a feed URL against private/reserved IP ranges (H3 — SSRF fix).
     *
     * Mirrors the logic in CalendarWidgetService::validateUrl: resolves the
     * hostname via gethostbynamel() and rejects any IP that falls in a
     * private/reserved range. Also enforces HTTPS-only for feed sources as
     * an additional defence-in-depth measure.
     *
     * Note: this performs a DNS resolution at allow-list time. The HTTP
     * client will later re-resolve the hostname (TOCTOU), but for feed
     * refresh the background-job context means the attack window is narrow
     * and this guard is already a significant improvement over no check.
     *
     * @param string $feedUrl The feed URL to validate.
     *
     * @return bool True when the URL passes the private-IP guard.
     */
    private function isPrivateIpGuarded(string $feedUrl): bool
    {
        $parts = parse_url(url: $feedUrl);
        if (is_array(value: $parts) === false) {
            return false;
        }

        // Enforce HTTPS-only for feed sources (H3 defence-in-depth).
        $scheme = strtolower(string: (string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        // Resolve and reject private/reserved IPs — mirrors CalendarWidgetService.
        $ips = gethostbynamel(hostname: $host);
        if ($ips === false || $ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            $publicIp = filter_var(
                value: $ip,
                filter: FILTER_VALIDATE_IP,
                options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            );
            if ($publicIp === false) {
                return false;
            }
        }

        return true;
    }//end isPrivateIpGuarded()

    private function isHostAllowed(string $feedUrl): bool
    {
        $allowedRaw = $this->appConfig->getValueString(
            Application::APP_ID,
            self::CONFIG_KEY_ALLOWED_HOSTS,
            ''
        );
        if ($allowedRaw === '') {
            return true;
        }

        $host = strtolower(
            string: (string) parse_url(url: $feedUrl, component: PHP_URL_HOST)
        );
        if ($host === '') {
            return false;
        }

        $allowed = array_map(
            callback: static fn (string $entry): string => strtolower(
                string: trim(string: $entry)
            ),
            array: explode(separator: ',', string: $allowedRaw)
        );

        return in_array(needle: $host, haystack: $allowed, strict: true);
    }//end isHostAllowed()

    /**
     * Map a transport-layer exception to a stable failure-reason string
     * (REQ-FRJ-006). The string MUST be parseable / matchable by
     * monitoring tooling — keeping the prefix taxonomy stable.
     *
     * @param Throwable $exception The caught exception.
     *
     * @return string The failure reason.
     */
    private function classifyTransportError(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $needle  = strtolower(string: $message);
        if (str_contains(haystack: $needle, needle: 'timeout') === true
            || str_contains(haystack: $needle, needle: 'timed out') === true
        ) {
            return 'timeout: '.$message;
        }

        return 'transport error: '.$message;
    }//end classifyTransportError()

    /**
     * Best-effort extraction of the HTTP reason phrase from a Guzzle
     * (or NC-wrapped) response object.
     *
     * @param object $response The response object.
     *
     * @return string The reason phrase, or empty string when unknown.
     */
    private function extractReason(object $response): string
    {
        if (method_exists(object_or_class: $response, method: 'getReasonPhrase') === true) {
            return (string) $response->getReasonPhrase();
        }

        return '';
    }//end extractReason()

    /**
     * Pull the first value of a header from an associative
     * `name => string|string[]` headers map.
     *
     * @param array<string, string|array<int, string>> $headers The headers map.
     * @param string                                   $name    The header name.
     *
     * @return string|null The first header value, or null when absent.
     */
    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp(string1: $key, string2: $name) !== 0) {
                continue;
            }

            if (is_array(value: $value) === true) {
                return ($value[0] ?? null);
            }

            return (string) $value;
        }

        return null;
    }//end firstHeader()
}//end class
