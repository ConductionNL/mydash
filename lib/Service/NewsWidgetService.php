<?php

/**
 * NewsWidgetService
 *
 * Fetches, parses, sanitises, and merges RSS / Atom feed items for the
 * MyDash news widget (REQ-NEWS-001..011). The `background-job-feed-refresh`
 * sibling change is not yet on this branch — per REQ-NEWS-004 the widget
 * gracefully degrades to synchronous on-demand fetches and caches the
 * raw feed payload via Nextcloud's `ICache` for `news_widget_feed_cache_ttl_seconds`
 * (default 3600s).
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DOMDocument;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Service for fetching, parsing, merging, deduplicating, sanitising, and
 * filtering RSS / Atom feed items for the news widget.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Combines feed
 *     fetching, parsing (RSS + Atom), allow-listing, sanitisation,
 *     deduplication, sorting, caching and graceful-degradation logic in
 *     one cohesive unit. Splitting into multiple services would
 *     fragment the REQ-NEWS-001..011 contract across files without
 *     reducing the surface that the news-widget capability has to
 *     present to callers.
 */
class NewsWidgetService
{

    /**
     * Default per-feed cache TTL in seconds (60 minutes). Overridden at
     * runtime by the app-config key
     * `mydash.news_widget_feed_cache_ttl_seconds`.
     */
    private const DEFAULT_CACHE_TTL = 3600;

    /**
     * Hard ceiling on the per-request item count. Server-side cap that
     * enforces a safety rail regardless of client-supplied `limit` —
     * see design D4 in `openspec/changes/news-widget/design.md`.
     */
    private const HARD_ITEM_CEILING = 200;

    /**
     * HTML tags retained by the summary sanitiser (REQ-NEWS-005).
     *
     * @var array<int,string>
     */
    private const ALLOWED_SUMMARY_TAGS = [
        'p',
        'a',
        'strong',
        'em',
        'br',
        'ul',
        'ol',
        'li',
    ];

    /**
     * Per-feed HTTP fetch timeout in seconds (REQ-NEWS-008).
     */
    private const FETCH_TIMEOUT_SECONDS = 10;

    /**
     * Lazily resolved {@see ICache} backing the per-feed payload cache.
     * Created on first use so unit tests can construct the service
     * with a stub `ICacheFactory` that never gets called.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param WidgetPlacementMapper $placementMapper Resolves placements by id.
     * @param IClientService        $clientService   Builds the HTTP client used
     *                                               for synchronous on-demand
     *                                               feed fetches.
     * @param IAppConfig            $appConfig       Reads admin settings:
     *                                               `news_widget_feed_cache_ttl_seconds`
     *                                               and
     *                                               `news_widget_allowed_feed_hosts`.
     * @param ICacheFactory         $cacheFactory    Backing factory for the
     *                                               distributed cache used to
     *                                               hold raw feed payloads.
     * @param LoggerInterface       $logger          PSR logger.
     */
    public function __construct(
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a placement and return the merged + sanitised feed items
     * for it. Honours the placement's metadata filter — when the filter
     * does not match, an empty array is returned without performing any
     * HTTP fetch (REQ-NEWS-007).
     *
     * @param integer $placementId Placement entity id.
     * @param integer $limit       Max items to return; clamped to
     *                             [1, HARD_ITEM_CEILING].
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     feedsFailed: int,
     *     failedUrls: array<int, string>
     * }
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function getItemsForPlacement(int $placementId, int $limit=10): array
    {
        $clamped = $this->clampLimit(limit: $limit);

        try {
            $placement = $this->placementMapper->find(id: $placementId);
        } catch (DoesNotExistException $e) {
            return $this->emptyResponse();
        } catch (Throwable $e) {
            $this->logger->warning(
                message: 'NewsWidget: failed to resolve placement '.$placementId,
                context: ['exception' => $e]
            );
            return $this->emptyResponse();
        }

        $config = $this->extractNewsConfig(placement: $placement);

        // REQ-NEWS-007: if a metadata filter is configured and does not
        // match the dashboard, short-circuit before any feed fetch.
        if ($config['metadataFilter'] !== null
            && $this->checkMetadataFilter(
                dashboardId: (int) $placement->getDashboardId(),
                metadataFilter: $config['metadataFilter']
            ) === false
        ) {
            return $this->emptyResponse();
        }

        return $this->fetchAndMergeFeeds(
            feedUrls: $config['feedUrls'],
            limit: $clamped
        );
    }//end getItemsForPlacement()

    /**
     * Parse the placement's persisted JSON content into the canonical
     * news config shape, applying defaults where fields are missing or
     * malformed (REQ-NEWS-002).
     *
     * @param WidgetPlacement $placement The placement whose
     *                                   `styleConfig` JSON column carries
     *                                   the news widget configuration.
     *
     * @return array{
     *     feedUrls: array<int, string>,
     *     layout: string,
     *     itemLimit: int,
     *     showThumbnails: bool,
     *     showSummary: bool,
     *     summaryMaxChars: int,
     *     dateFormat: string,
     *     metadataFilter: array{fieldKey: string, value: string}|null
     * }
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function extractNewsConfig(WidgetPlacement $placement): array
    {
        $decoded = $this->decodeStyleConfigBlob(raw: $placement->getStyleConfig());

        return [
            'feedUrls'        => $this->extractFeedUrls(decoded: $decoded),
            'layout'          => $this->extractLayout(decoded: $decoded),
            'itemLimit'       => $this->extractIntInRange(
                decoded: $decoded,
                key: 'itemLimit',
                default: 10,
                min: 1,
                max: 50
            ),
            'showThumbnails'  => $this->extractBool(decoded: $decoded, key: 'showThumbnails', default: true),
            'showSummary'     => $this->extractBool(decoded: $decoded, key: 'showSummary', default: true),
            'summaryMaxChars' => $this->extractIntInRange(
                decoded: $decoded,
                key: 'summaryMaxChars',
                default: 200,
                min: 0,
                max: 5000
            ),
            'dateFormat'      => $this->extractDateFormat(decoded: $decoded),
            'metadataFilter'  => $this->extractMetadataFilter(decoded: $decoded),
        ];
    }//end extractNewsConfig()

    /**
     * Parse the widget's persisted JSON blob and unwrap the outer
     * `{type, content}` envelope when present so callers always get
     * the flat content map.
     *
     * @param string|null $raw Raw JSON string from the placement.
     *
     * @return array<string, mixed> Decoded content map (empty when invalid).
     */
    private function decodeStyleConfigBlob(?string $raw): array
    {
        if (is_string(value: $raw) === false || $raw === '') {
            return [];
        }

        $parsed = json_decode(json: $raw, associative: true);
        if (is_array(value: $parsed) === false) {
            return [];
        }

        if (isset($parsed['content']) === true && is_array(value: $parsed['content']) === true) {
            return $parsed['content'];
        }

        return $parsed;
    }//end decodeStyleConfigBlob()

    /**
     * Extract the configured feed URL list, dropping non-HTTP(S) entries.
     *
     * @param array<string, mixed> $decoded Decoded config map.
     *
     * @return array<int, string>
     */
    private function extractFeedUrls(array $decoded): array
    {
        $candidates = $decoded['feedUrls'] ?? null;
        if (is_array(value: $candidates) === false) {
            return [];
        }

        $out = [];
        foreach ($candidates as $candidate) {
            if (is_string(value: $candidate) === false || $candidate === '') {
                continue;
            }

            $lower = strtolower(string: $candidate);
            if (str_starts_with(haystack: $lower, needle: 'http://') === true
                || str_starts_with(haystack: $lower, needle: 'https://') === true
            ) {
                $out[] = $candidate;
            }
        }

        return $out;
    }//end extractFeedUrls()

    /**
     * Extract the layout enum (`list` | `grid` | `carousel`).
     *
     * @param array<string, mixed> $decoded Decoded config map.
     *
     * @return string
     */
    private function extractLayout(array $decoded): string
    {
        $value = $decoded['layout'] ?? null;
        if (is_string(value: $value) === true
            && in_array(needle: $value, haystack: ['list', 'grid', 'carousel'], strict: true) === true
        ) {
            return $value;
        }

        return 'list';
    }//end extractLayout()

    /**
     * Extract a clamped int field.
     *
     * @param array<string, mixed> $decoded Decoded config map.
     * @param string               $key     Field key.
     * @param integer              $default Default value when missing/invalid.
     * @param integer              $min     Lower bound.
     * @param integer              $max     Upper bound.
     *
     * @return integer
     */
    private function extractIntInRange(array $decoded, string $key, int $default, int $min, int $max): int
    {
        $value = $decoded[$key] ?? null;
        if (is_int(value: $value) === false) {
            return $default;
        }

        return max($min, min($max, $value));
    }//end extractIntInRange()

    /**
     * Extract a boolean field with default fallback.
     *
     * @param array<string, mixed> $decoded Decoded config map.
     * @param string               $key     Field key.
     * @param boolean              $default Default value when missing.
     *
     * @return boolean
     */
    private function extractBool(array $decoded, string $key, bool $default): bool
    {
        if (array_key_exists(key: $key, array: $decoded) === false) {
            return $default;
        }

        return $decoded[$key] === true;
    }//end extractBool()

    /**
     * Extract the date-format enum, defaulting to `relative`.
     *
     * @param array<string, mixed> $decoded Decoded config map.
     *
     * @return string `relative` or `absolute`.
     */
    private function extractDateFormat(array $decoded): string
    {
        if (($decoded['dateFormat'] ?? null) === 'absolute') {
            return 'absolute';
        }

        return 'relative';
    }//end extractDateFormat()

    /**
     * Extract the optional metadata filter `{fieldKey, value}` shape.
     *
     * @param array<string, mixed> $decoded Decoded config map.
     *
     * @return array{fieldKey: string, value: string}|null
     */
    private function extractMetadataFilter(array $decoded): ?array
    {
        $filter = $decoded['metadataFilter'] ?? null;
        if (is_array(value: $filter) === false) {
            return null;
        }

        $key = $filter['fieldKey'] ?? null;
        $val = $filter['value'] ?? null;
        if (is_string(value: $key) === false || $key === '' || is_string(value: $val) === false) {
            return null;
        }

        return ['fieldKey' => $key, 'value' => $val];
    }//end extractMetadataFilter()

    /**
     * Fetch each URL (cache-first), parse, merge, deduplicate, sort, and
     * cap at $limit items. Failures of individual URLs are tolerated;
     * the response carries a count + list of failed URLs so the UI can
     * surface a corner badge (REQ-NEWS-008 / REQ-NEWS-010).
     *
     * @param array<int, string> $feedUrls List of feed URLs (already
     *                                     sanitised against http(s) by
     *                                     {@see extractNewsConfig()}).
     * @param integer            $limit    Hard cap on returned item count
     *                                     (already clamped).
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     feedsFailed: int,
     *     failedUrls: array<int, string>
     * }
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function fetchAndMergeFeeds(array $feedUrls, int $limit=10): array
    {
        if ($feedUrls === []) {
            return $this->emptyResponse();
        }

        $allItems   = [];
        $failedUrls = [];

        foreach ($feedUrls as $url) {
            if ($this->checkAllowList(url: $url) === false) {
                $this->logger->warning(
                    message: 'NewsWidget: URL skipped by allow-list',
                    context: ['url' => $url]
                );
                $failedUrls[] = $url;
                continue;
            }

            $payload = $this->fetchFeedPayload(url: $url);
            if ($payload === null) {
                $failedUrls[] = $url;
                continue;
            }

            try {
                $parsed = $this->parseRssFeed(
                    feedContent: $payload,
                    sourceUrl: $url,
                    sourceTitle: $url
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: 'NewsWidget: feed parse failed for '.$url,
                    context: ['exception' => $e]
                );
                $failedUrls[] = $url;
                continue;
            }

            foreach ($parsed as $item) {
                $allItems[] = $item;
            }
        }//end foreach

        $deduped = $this->deduplicateItems(items: $allItems);
        $sorted  = $this->sortItemsByDate(items: $deduped);
        $sliced  = array_slice(array: $sorted, offset: 0, length: $limit);

        return [
            'items'       => $sliced,
            'feedsFailed' => count(value: $failedUrls),
            'failedUrls'  => array_values(array: $failedUrls),
        ];
    }//end fetchAndMergeFeeds()

    /**
     * Parse a raw feed payload (RSS 2.0 or Atom 1.0) into the canonical
     * item shape. Items without a guid receive a synthetic
     * `sha1(title|pubDate|sourceUrl)` identifier (REQ-NEWS-003).
     *
     * @param string $feedContent Raw XML payload.
     * @param string $sourceUrl   URL the payload was fetched from.
     * @param string $sourceTitle Display name for the feed; replaced by
     *                            the channel/feed title when present.
     *
     * @return array<int, array<string, mixed>> Parsed items.
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function parseRssFeed(string $feedContent, string $sourceUrl, string $sourceTitle): array
    {
        if (trim(string: $feedContent) === '') {
            return [];
        }

        $previousErrors = libxml_use_internal_errors(use_errors: true);
        $xml            = simplexml_load_string(
            data: $feedContent,
            class_name: SimpleXMLElement::class,
            options: (LIBXML_NONET | LIBXML_NOENT)
        );
        libxml_clear_errors();
        libxml_use_internal_errors(use_errors: $previousErrors);

        if ($xml === false) {
            $this->logger->info(
                message: 'NewsWidget: could not parse feed XML for '.$sourceUrl
            );
            return [];
        }

        $items    = [];
        $rootName = $xml->getName();

        // Atom 1.0: <feed><entry/>…</feed>.
        if ($rootName === 'feed') {
            $resolvedTitle = $sourceTitle;
            if (isset($xml->title) === true) {
                $resolvedTitle = (string) $xml->title;
            }

            foreach ($xml->entry as $entry) {
                $items[] = $this->normaliseAtomEntry(
                    entry: $entry,
                    sourceUrl: $sourceUrl,
                    sourceTitle: $resolvedTitle
                );
            }

            return $items;
        }

        // RSS 2.0: <rss><channel><item/>…</channel></rss>.
        if ($rootName === 'rss' && isset($xml->channel) === true) {
            $channel       = $xml->channel;
            $resolvedTitle = $sourceTitle;
            if (isset($channel->title) === true) {
                $resolvedTitle = (string) $channel->title;
            }

            foreach ($channel->item as $item) {
                $items[] = $this->normaliseRssItem(
                    item: $item,
                    sourceUrl: $sourceUrl,
                    sourceTitle: $resolvedTitle
                );
            }

            return $items;
        }

        // Permit a bare <channel> wrapper (some publishers ship without <rss>).
        if ($rootName === 'channel') {
            $resolvedTitle = $sourceTitle;
            if (isset($xml->title) === true) {
                $resolvedTitle = (string) $xml->title;
            }

            foreach ($xml->item as $item) {
                $items[] = $this->normaliseRssItem(
                    item: $item,
                    sourceUrl: $sourceUrl,
                    sourceTitle: $resolvedTitle
                );
            }

            return $items;
        }

        $this->logger->info(
            message: 'NewsWidget: unsupported feed root <'.$rootName.'> for '.$sourceUrl
        );
        return [];
    }//end parseRssFeed()

    /**
     * Deduplicate parsed items by `guid`, keeping the first occurrence
     * (REQ-NEWS-003).
     *
     * @param array<int, array<string, mixed>> $items Items to dedupe.
     *
     * @return array<int, array<string, mixed>> Deduped items.
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function deduplicateItems(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $item) {
            $guid = '';
            if (isset($item['guid']) === true) {
                $guid = (string) $item['guid'];
            }

            if ($guid === '' || isset($seen[$guid]) === true) {
                if ($guid === '') {
                    // Items missing a guid bypass the dedupe map but
                    // still flow through so we don't lose them; the
                    // synthetic guid in normaliseRssItem / normaliseAtomEntry
                    // ensures this path is rarely hit.
                    $out[] = $item;
                }

                continue;
            }

            $seen[$guid] = true;
            $out[]       = $item;
        }//end foreach

        return $out;
    }//end deduplicateItems()

    /**
     * Sort items by ISO 8601 `pubDate`, descending (newest first).
     * Items with an invalid or missing pubDate sink to the end, in
     * input order, so the visible feed never silently drops them.
     *
     * @param array<int, array<string, mixed>> $items Items to sort.
     *
     * @return array<int, array<string, mixed>> Sorted items.
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function sortItemsByDate(array $items): array
    {
        $sortable = $items;
        usort(
            array: $sortable,
            callback: function (array $a, array $b): int {
                $aTs = false;
                if (isset($a['pubDate']) === true) {
                    $aTs = strtotime(datetime: (string) $a['pubDate']);
                }

                $bTs = false;
                if (isset($b['pubDate']) === true) {
                    $bTs = strtotime(datetime: (string) $b['pubDate']);
                }

                if ($aTs === false && $bTs === false) {
                    return 0;
                }

                if ($aTs === false) {
                    return 1;
                }

                if ($bTs === false) {
                    return -1;
                }

                return ($bTs <=> $aTs);
            }
        );

        return $sortable;
    }//end sortItemsByDate()

    /**
     * Allow-list the small set of inline tags safe to render inside a
     * feed item summary, strip everything else, and force `rel` on
     * surviving anchor tags (REQ-NEWS-005).
     *
     * @param string $html Raw summary HTML from a feed item.
     *
     * @return string Sanitised summary HTML.
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function sanitiseSummaryHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $allowed  = '<'.implode(separator: '><', array: self::ALLOWED_SUMMARY_TAGS).'>';
        $stripped = strip_tags(string: $html, allowed_tags: $allowed);

        // After tag stripping, any `javascript:` href that survives must
        // be neutralised; reuse PHP's HTML parser via DOMDocument so we
        // can rewrite href attributes safely without false-positives on
        // body text containing the substring.
        $previousErrors = libxml_use_internal_errors(use_errors: true);
        $document       = new DOMDocument();
        $document->loadHTML(
            source: '<?xml encoding="UTF-8"><div>'.$stripped.'</div>',
            options: (LIBXML_NONET | LIBXML_NOENT)
        );
        libxml_clear_errors();
        libxml_use_internal_errors(use_errors: $previousErrors);

        $anchors = $document->getElementsByTagName(qualifiedName: 'a');
        foreach ($anchors as $anchor) {
            $href       = $anchor->getAttribute(qualifiedName: 'href');
            $normalised = strtolower(string: trim(string: $href));
            if (str_starts_with(haystack: $normalised, needle: 'javascript:') === true
                || str_starts_with(haystack: $normalised, needle: 'data:') === true
            ) {
                $anchor->setAttribute(qualifiedName: 'href', value: '#');
            }

            $anchor->setAttribute(qualifiedName: 'rel', value: 'noopener noreferrer');
        }

        // Re-serialise just the wrapper's children so we don't leak
        // <html><body> from DOMDocument's auto-wrapping.
        $body = $document->getElementsByTagName(qualifiedName: 'div')->item(index: 0);
        if ($body === null) {
            return $stripped;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $serialised = $document->saveHTML(node: $child);
            if (is_string(value: $serialised) === true) {
                $out .= $serialised;
            }
        }

        return $out;
    }//end sanitiseSummaryHtml()

    /**
     * Whether the supplied URL's hostname is permitted by the admin
     * allow-list. Empty / unset list ⇒ every host allowed
     * (REQ-NEWS-006).
     *
     * @param string $url Candidate feed URL.
     *
     * @return boolean True when the host is allowed.
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function checkAllowList(string $url): bool
    {
        $raw = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'news_widget_allowed_feed_hosts',
            default: ''
        );

        if (trim(string: $raw) === '') {
            return true;
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (is_array(value: $decoded) === false || $decoded === []) {
            return true;
        }

        $host = parse_url(url: $url, component: PHP_URL_HOST);
        if (is_string(value: $host) === false || $host === '') {
            return false;
        }

        $needle = strtolower(string: $host);
        foreach ($decoded as $allowed) {
            if (is_string(value: $allowed) === true && strtolower(string: $allowed) === $needle) {
                return true;
            }
        }

        return false;
    }//end checkAllowList()

    /**
     * Hook for the (out-of-branch) `dashboard-metadata-fields` capability.
     * Until that capability ships, the metadata store is unavailable and
     * the filter result is "no metadata defined" — which means a
     * configured filter never matches and the widget returns no items
     * (REQ-NEWS-007 scenarios "Metadata field missing" and "spec not
     * yet implemented").
     *
     * @param integer                                $dashboardId    The dashboard whose
     *                                                               metadata to consult.
     * @param array{fieldKey: string, value: string} $metadataFilter The filter to apply.
     *
     * @return boolean True when the filter passes (allow fetch); false otherwise.
     */
    /** @spec openspec/specs/news-widget/spec.md */
    public function checkMetadataFilter(int $dashboardId, array $metadataFilter): bool
    {
        unset($dashboardId);
        unset($metadataFilter);

        // REQ-NEWS-007: gracefully treat missing dashboard-metadata-fields
        // implementation as "no fields defined", which causes any
        // configured equality filter to fail.
        return false;
    }//end checkMetadataFilter()

    /**
     * Clamp a caller-requested `limit` to the supported range [1, 200].
     * The hard ceiling guards against runaway feeds. Out-of-range values
     * collapse to the closer bound rather than returning an error so the
     * widget always renders something.
     *
     * @param integer $limit Caller-requested limit.
     *
     * @return integer Clamped limit.
     */
    private function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }

        return min(self::HARD_ITEM_CEILING, $limit);
    }//end clampLimit()

    /**
     * Cache-first feed fetch. Returns `null` on any failure so the
     * caller can record the URL as failed without short-circuiting the
     * other URLs in the batch.
     *
     * @param string $url Feed URL to fetch.
     *
     * @return string|null Raw feed payload, or null on failure.
     */
    private function fetchFeedPayload(string $url): ?string
    {
        $cache    = $this->getCache();
        $cacheKey = 'feed_'.sha1(string: $url);

        if ($cache !== null) {
            $cached = $cache->get(key: $cacheKey);
            if (is_string(value: $cached) === true && $cached !== '') {
                return $cached;
            }
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                uri: $url,
                options: [
                    'timeout'         => self::FETCH_TIMEOUT_SECONDS,
                    'connect_timeout' => self::FETCH_TIMEOUT_SECONDS,
                    'verify'          => true,
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning(
                    message: 'NewsWidget: feed fetch returned HTTP '.$statusCode.' for '.$url
                );
                return null;
            }

            $body = (string) $response->getBody();
            if ($body === '') {
                return null;
            }

            if ($cache !== null) {
                $ttl = $this->appConfig->getValueInt(
                    app: Application::APP_ID,
                    key: 'news_widget_feed_cache_ttl_seconds',
                    default: self::DEFAULT_CACHE_TTL
                );
                $cache->set(key: $cacheKey, value: $body, ttl: $ttl);
            }

            return $body;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: 'NewsWidget: feed fetch failed for '.$url,
                context: ['exception' => $e]
            );
            return null;
        }//end try
    }//end fetchFeedPayload()

    /**
     * Lazily resolve the distributed cache. Returns `null` when the
     * Nextcloud cache subsystem is unavailable (e.g. unit tests with a
     * stub factory that returns `null`).
     *
     * @return ICache|null
     */
    private function getCache(): ?ICache
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $this->cache = $this->cacheFactory->createDistributed(prefix: 'mydash_news_');
        } catch (Throwable $e) {
            $this->logger->info(
                message: 'NewsWidget: cache subsystem unavailable, falling back to direct fetch',
                context: ['exception' => $e]
            );
            $this->cache = null;
        }

        return $this->cache;
    }//end getCache()

    /**
     * Convert a single Atom `<entry>` into the canonical item shape.
     *
     * @param SimpleXMLElement $entry       The Atom entry node.
     * @param string           $sourceUrl   The feed URL.
     * @param string           $sourceTitle The display name for the feed.
     *
     * @return array<string, mixed> Canonical item.
     */
    private function normaliseAtomEntry(SimpleXMLElement $entry, string $sourceUrl, string $sourceTitle): array
    {
        $title = '';
        if (isset($entry->title) === true) {
            $title = (string) $entry->title;
        }

        $summary = '';
        if (isset($entry->summary) === true) {
            $summary = (string) $entry->summary;
        } else if (isset($entry->content) === true) {
            $summary = (string) $entry->content;
        }

        $link = '';
        if (isset($entry->link) === true) {
            // Atom links are <link href="…"/>.
            $href = $entry->link['href'] ?? null;
            if ($href !== null) {
                $link = (string) $href;
            }
        }

        $pubDate = '';
        if (isset($entry->updated) === true) {
            $pubDate = (string) $entry->updated;
        } else if (isset($entry->published) === true) {
            $pubDate = (string) $entry->published;
        }

        $guid = '';
        if (isset($entry->id) === true) {
            $guid = (string) $entry->id;
        }

        if ($guid === '') {
            $guid = sha1(string: $title.'|'.$pubDate.'|'.$sourceUrl);
        }

        return [
            'guid'         => $guid,
            'title'        => $title,
            'summary'      => $this->sanitiseSummaryHtml(html: $summary),
            'link'         => $link,
            'pubDate'      => $pubDate,
            'sourceUrl'    => $sourceUrl,
            'sourceTitle'  => $sourceTitle,
            'thumbnailUrl' => null,
        ];
    }//end normaliseAtomEntry()

    /**
     * Convert a single RSS `<item>` into the canonical item shape.
     *
     * @param SimpleXMLElement $item        The RSS item node.
     * @param string           $sourceUrl   The feed URL.
     * @param string           $sourceTitle The display name for the feed.
     *
     * @return array<string, mixed> Canonical item.
     */
    private function normaliseRssItem(SimpleXMLElement $item, string $sourceUrl, string $sourceTitle): array
    {
        $title = '';
        if (isset($item->title) === true) {
            $title = (string) $item->title;
        }

        $summary = '';
        if (isset($item->description) === true) {
            $summary = (string) $item->description;
        }

        $link = '';
        if (isset($item->link) === true) {
            $link = (string) $item->link;
        }

        $pubDate = '';
        if (isset($item->pubDate) === true) {
            $pubDate = (string) $item->pubDate;
        }

        $guid = '';
        if (isset($item->guid) === true) {
            $guid = (string) $item->guid;
        }

        if ($guid === '') {
            $guid = sha1(string: $title.'|'.$pubDate.'|'.$sourceUrl);
        }

        $thumbnail = null;
        if (isset($item->enclosure) === true) {
            $url  = $item->enclosure['url'] ?? null;
            $type = $item->enclosure['type'] ?? null;
            if ($url !== null
                && ($type === null || str_starts_with(haystack: (string) $type, needle: 'image/') === true)
            ) {
                $thumbnail = (string) $url;
            }
        }

        return [
            'guid'         => $guid,
            'title'        => $title,
            'summary'      => $this->sanitiseSummaryHtml(html: $summary),
            'link'         => $link,
            'pubDate'      => $pubDate,
            'sourceUrl'    => $sourceUrl,
            'sourceTitle'  => $sourceTitle,
            'thumbnailUrl' => $thumbnail,
        ];
    }//end normaliseRssItem()

    /**
     * Canonical empty response so callers don't repeat the literal.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     feedsFailed: int,
     *     failedUrls: array<int, string>
     * }
     */
    private function emptyResponse(): array
    {
        return [
            'items'       => [],
            'feedsFailed' => 0,
            'failedUrls'  => [],
        ];
    }//end emptyResponse()
}//end class
