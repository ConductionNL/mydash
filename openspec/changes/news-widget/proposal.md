# News Widget

## Why

MyDash users often need to stay informed about industry news, product updates, and organizational announcements. Currently, there is no built-in way to aggregate RSS or Atom feeds directly on the dashboard. Users must either leave the dashboard and open feed readers separately, or manually check multiple sources. A news widget bridges this gap by rendering merged and deduplicated feed items from configurable sources, with optional filtering by dashboard metadata to surface only relevant content on dashboard contexts (e.g., marketing news only on a "marketing" dashboard).

## What Changes

- Register a new dashboard widget with id `mydash_news` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` to specify RSS/Atom feed URLs, layout mode, item limit, optional metadata filtering, and presentation preferences (thumbnails, summaries, date format).
- Implement backend `GET /api/widgets/news/{placementId}/items?limit=N` to return merged, deduplicated feed items with source attribution, sorted by publication date.
- Integrate with the sibling change `background-job-feed-refresh` (required dependency): this widget reads from a feed-cache table populated by that background job. On cold-start (cache empty), perform a single on-demand fetch; regular updates are delegated to the background job.
- Cache external feed fetches for 60 minutes using Nextcloud's `ICache`, tunable via admin setting `mydash.news_widget_feed_cache_ttl_seconds`.
- Enforce an allow-list of feed hosts via admin setting `mydash.news_widget_allowed_feed_hosts` (JSON array of hostnames; empty = all allowed; populated = restricted to those hosts). URLs not matching the allow-list are silently skipped.
- Implement HTML sanitisation for item summaries (allow `<p>`, `<a>`, `<strong>`, `<em>`, `<br>`, `<ul>`, `<ol>`, `<li>`; strip everything else; force `rel="noopener noreferrer"` on links).
- Support optional metadata filtering: if a widget specifies a `metadataFilter` with fieldKey and value, the widget only fetches items if the dashboard's metadata field (referenced by fieldKey) matches the value. This allows e.g. a "marketing" dashboard to show only marketing-tagged feed items.
- Provide Vue 3 SFC `NewsWidget.vue` with three layout modes: list (single column), grid (cards), and carousel (horizontal scroll).
- Display empty state ("No news yet — try adding feeds in the widget settings") and failure tolerance (single feed source fetch failure does not break the widget; successful sources render, failed ones are noted in a corner badge).
- Click on item → open in new tab (`target="_blank" rel="noopener"`).

## Capabilities

### New Capabilities

- `news-widget` — A new MyDash dashboard widget capability providing merged, deduplicated RSS/Atom feed streams with configurable sources, filtering, and layouts.

## Impact

**Affected code:**

- `lib/Service/NewsWidgetService.php` — core logic for fetching, parsing, merging, deduplicating, sanitising, and filtering feed items.
- `lib/Controller/WidgetController.php` — new endpoint `GET /api/widgets/news/{placementId}/items`.
- `src/components/widgets/NewsWidget.vue` — three-mode render component (list, grid, carousel).
- `src/components/widgets/newspicker/NewsWidgetConfig.vue` — placement config UI for feed URLs, layout, filtering preferences.
- `appinfo/routes.php` — register the new news widget items endpoint.
- `lib/Migration/VersionXXXXDate2026...AddNewsWidgetSettings.php` — schema migration adding app config settings (`mydash.news_widget_*` keys).
- `src/stores/widgets.js` — add widget-specific runtime state for cached item data and fetch status per placement.

**Affected APIs:**

- 1 new route: `GET /api/widgets/news/{placementId}/items`
- 0 changes to existing routes.

**Dependencies:**

- `lib/Service/FeedCacheService.php` — (from sibling change `background-job-feed-refresh`) provides feed cache table and refresh job orchestration.
- `OCP\ICache` — cache feed fetches for 60 minutes per placement.
- `OCP\IAppConfig` — admin settings for feed cache TTL and allow-list.
- `OCP\Dashboard\IManager` — widget registration.
- No new composer or npm dependencies. (HTML sanitisation via Nextcloud's existing utilities.)

**Migration:**

- Zero-impact: app config keys are created on demand via IAppConfig getter. No schema changes required beyond optional logging setup.
- No data backfill required. Existing placements without news widget config simply don't render the widget.
- Dependency on `background-job-feed-refresh` change: that change MUST be implemented first or in parallel; this widget's initial data load is synchronous (first fetch on-demand) and delegates polling to the background job.

**Interdependencies:**

- This change depends on `background-job-feed-refresh` being available to provide the feed-cache table and periodic refresh infrastructure.
- The sibling change `dashboard-metadata-fields` (if present) enables metadata-based filtering: a news widget can specify a filter keyed to a dashboard's metadata field.
