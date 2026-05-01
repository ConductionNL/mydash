# Design — Background feed refresh job

## Context

MyDash's news widget currently fetches external RSS/Atom feeds on-demand at page load. This couples every dashboard render to the availability and latency of upstream feed servers — a single slow or failing feed degrades the whole page. Moving feed retrieval into a periodic background job decouples the widget render from the network call and lets users see cached content in sub-second time.

The reference implementation (see source paths below) provides a battle-tested blueprint: PHP's built-in XML parser, Nextcloud's HTTP client, a single cache table, and a TimedJob that runs on a configurable interval. This design document records the decisions made during porting and the rationale behind each one. Where our implementation diverges from the source, the divergence is explicit.

The feature spans a new database table (`oc_mydash_feed_cache`), a `TimedJob` class, a fetch-and-parse service, and one admin-only REST endpoint. All other capabilities (news-widget render, orphaned-data cleanup) interact with this job through shared mapper interfaces rather than direct coupling.

## Goals / Non-Goals

**Goals:**
- Refresh all active RSS/Atom feeds asynchronously on a configurable interval (default 60 min).
- Cache parsed items in `oc_mydash_feed_cache` so the news widget never waits on a live HTTP request.
- Use HTTP conditional GET (`If-None-Match` / `If-Modified-Since`) to avoid re-downloading unchanged feeds.
- Isolate per-feed failures so one bad feed cannot block the rest.
- Harden against malicious feed content (XXE, oversized bodies, non-HTTP schemes).
- Expose an admin endpoint for immediate on-demand refresh.

**Non-Goals:**
- Full-text indexing or search of feed items.
- Push/WebSocket delivery of new items to open browser tabs (future work).
- OPML bulk import of feed URLs (flagged as a follow-up below).
- Implementing the orphaned-feed deletion — that belongs to the `orphaned-data-cleanup` spec.

## Decisions

### D1: Parser library — `SimpleXMLElement` (PHP built-in), not SimplePie

**Decision:** Use PHP's built-in `simplexml_load_string()` with `LIBXML_NOCDATA | LIBXML_NONET` flags. Wrap the parsing logic behind a `FeedParserInterface` so the implementation can be swapped to SimplePie in a follow-up without touching the job or service layer.

**Alternatives considered:**
- **SimplePie:** Handles RSS 0.91/1.0/2.0, Atom 0.3/1.0, iTunes, and Media RSS extensions with ~10 years of edge-case fixes and robust malformed-feed recovery. Rejected for v1 because the added ~500 KB composer dependency and more complex initialisation are not justified for the target use case (modern RSS 2.0 and Atom 1.0 feeds from corporate intranets). Flag for revisit if customers report parsing regressions on unusual feed variants.

**Rationale:** MyDash's news widget is aimed at intranet and SaaS news sources that publish standard RSS 2.0 or Atom 1.0. The source implementation uses `SimpleXMLElement` successfully across those cases with no SimplePie dependency in `composer.json`. Keeping the parser built-in eliminates a supply-chain dependency and reduces the attack surface. The `FeedParserInterface` abstraction means adopting SimplePie later is a one-class swap, not a refactor.

**Source evidence:**
- `intravox-source/lib/Service/FeedReaderService.php:751,762,812,847` — `@simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET)` then dispatches to `parseXmlFeed(\SimpleXMLElement $xml)` → `normalizeRssItem()` or `normalizeAtomEntry()`.
- `intravox-source/lib/Service/FeedReaderService.php` constructor — dependencies are `IClientService`, `ICacheFactory`, `IConfig`, `ICrypto`, `LoggerInterface`; no SimplePie injection.
- `intravox-source/composer.json` — no `simplepie/simplepie` or equivalent; only `enshrined/svg-sanitize` as a third-party lib.

### D2: Security flags on parse — `LIBXML_NONET`

**Decision:** Always pass `LIBXML_NONET` (and `LIBXML_NOCDATA`) to `simplexml_load_string()`. `LIBXML_NONET` disables external entity loading, preventing XXE attacks via crafted feed bodies. `LIBXML_NOCDATA` coerces CDATA sections to plain text strings so field extraction works uniformly.

**Source evidence:**
- `intravox-source/lib/Service/FeedReaderService.php:751` — `LIBXML_NOCDATA | LIBXML_NONET` passed on every call.

**Rationale:** XXE is a known attack vector for XML parsers processing untrusted content. Disabling network access at the libxml level is the correct defence; it costs nothing and cannot be forgotten in a subclass. `LIBXML_NOCDATA` avoids defensive casting throughout the normaliser.

### D3: Response size cap — 10 MB

**Decision:** Reject any feed response body larger than 10 MB (`MAX_RESPONSE_SIZE = 10 * 1024 * 1024`). Feeds exceeding this limit are recorded as failed with `lastFailureReason = "response too large"` and their cached items are left unchanged.

**Source evidence:**
- `intravox-source/lib/Service/FeedReaderService.php` — `MAX_RESPONSE_SIZE` constant at 10 MB.

**Rationale:** No legitimate news feed requires more than 10 MB of XML. The cap prevents a malicious or misconfigured feed server from exhausting PHP memory. The constant is defined once and is trivially tunable in a follow-up if a customer has an unusually large internal feed.

### D4: HTTP fetch via NC `IClientService`, 10 s connect / 30 s total timeout

**Decision:** Use Nextcloud's `IClientService` (not raw cURL) for all outbound HTTP requests. Set a 10-second connect timeout and a 30-second total timeout. The source uses 5 s; we extend this slightly to accommodate slow intranet servers without making the job hang indefinitely. Proxy settings (`system.proxy`, `system.proxyuserpwd`, `system.noproxy`) are honoured transparently by `IClientService`.

**Source evidence:**
- `intravox-source/lib/Service/FeedReaderService.php` — `IClientService` injected; 5 s timeout configured on the client options.

**Rationale:** Using `IClientService` instead of raw cURL ensures proxy and SSL configuration set by the Nextcloud admin is respected without any custom logic. The spec (REQ-FRJ-006) defines 10 s connect / 30 s total — we align to the spec rather than the source's 5 s to reduce false-positive timeout failures on slow corporate proxies.

### D5: Cache key and TTL — NC `ICache`, 60-minute default (admin-tunable)

**Decision:** Persist parsed feed items in `oc_mydash_feed_cache.itemsJson` (database-backed, not NC `ICache`). The background job refresh interval acts as the effective TTL: default 60 minutes, admin-tunable via `mydash.feed_refresh_interval_seconds` (min 300 s, max 86 400 s). NC `ICache` is used only for transient in-request deduplication if needed, not for primary storage.

**Alternatives considered:**
- **Match source's 15-minute `CACHE_TTL`:** The source stores in NC `ICache` with a 900-second TTL. Rejected because a shared `ICache` entry is volatile (evicted under memory pressure) and does not survive across PHP processes. Database persistence is more reliable for a background-job pattern where the widget render must always find cached data.

**Source evidence:**
- `intravox-source/lib/Service/FeedReaderService.php` — `CACHE_TTL = 900` (15 min) used with `ICacheFactory`-backed cache.

**Rationale:** The proposal explicitly defines `oc_mydash_feed_cache` as the persistence layer and the 60-minute `TimedJob` interval as the refresh cadence. Database storage survives pod restarts and Redis evictions; 60 minutes is appropriate for intranet news that does not change by the minute. Admins who need fresher data can lower the interval to 5 minutes.

### D6: Conditional GET — `If-None-Match` + `If-Modified-Since`

**Decision:** Persist `etag` and `lastModified` response headers in `oc_mydash_feed_cache` after each successful 200 fetch. On subsequent fetches, send `If-None-Match` and `If-Modified-Since` headers. On HTTP 304, update only `lastFetchedAt` and skip parse entirely.

**Rationale:** Conditional GET is the standard mechanism for feed polling efficiency. It reduces bandwidth and CPU on both sides and signals to feed servers that MyDash is a well-behaved client. The spec (REQ-FRJ-004) requires it; the source does not implement it, so this is a deliberate improvement over the reference.

### D7: Per-feed isolation — one timeout does not block the rest

**Decision:** Each feed fetch and parse is wrapped in its own `try/catch` block. A failure (timeout, 4xx/5xx, malformed XML) on feed N is recorded in `lastFailureReason` and does not prevent feeds N+1 … M from being processed in the same job tick. Existing `itemsJson` is preserved on failure.

**Rationale:** A background job that aborts on the first bad feed would silently leave most feeds stale. Per-feed isolation with recorded failure state lets admins diagnose individual problem feeds without affecting healthy ones. This matches the source behaviour and the spec requirement REQ-FRJ-006.

## Spec changes implied

- **REQ-FRJ-005 (parser):** Replace any remaining SimplePie reference with `simplexml_load_string()` + `LIBXML_NOCDATA | LIBXML_NONET`. Note the `FeedParserInterface` abstraction.
- **REQ-FRJ-005 (size cap):** Pin the 10 MB response cap (`MAX_RESPONSE_SIZE`) as an explicit acceptance criterion.
- **REQ-FRJ-002 (interval):** Confirm 60-minute default; document the 5-minute minimum and 24-hour maximum clamp.
- **REQ-FRJ-006 (failure isolation):** Confirm per-feed `try/catch` with 10 s connect / 30 s total timeout values aligned to D4.

## Open follow-ups

- Whether to surface persistent feed-fetch failures to admins via the orphaned-data-cleanup scan output (e.g., "feed X has been failing for 7 consecutive days").
- Whether the NC `ICache` layer (used for in-request deduplication) should be Redis-aware or left fully agnostic (current spec: agnostic — Redis if available, else file/APCu).
- Whether to add OPML import for bulk feed-URL onboarding (out of scope for this spec; noted in proposal).
- Whether `MAX_RESPONSE_SIZE` should be admin-tunable or remain a hard-coded constant.
