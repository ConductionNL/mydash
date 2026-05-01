# Design — News Widget

## Context

The news widget aggregates RSS and Atom feed items from one or more configured sources and renders them directly on a MyDash dashboard. Feed data is never fetched at render time: the sibling change `background-job-feed-refresh` owns the scheduled fetch-and-cache cycle, writing items into a server-side cache table. The news widget reads exclusively from that cache, so dashboard page loads are fast even when upstream feed servers are slow or unreachable.

Per-placement configuration (stored in `widgetContent` JSON) controls which feed URLs are included, the layout mode, an optional item cap, and an optional metadata filter. When a `metadataFilter` is configured, the widget only surfaces items tagged with a matching value, enabling context-sensitive feeds — for example, a department dashboard that surfaces only items tagged for that department.

HTML sanitisation is applied server-side before items reach the client: only a small set of inline elements is permitted, and all links receive `rel="noopener noreferrer"`. This design keeps the frontend component simple and prevents re-sanitisation inconsistencies across layout modes.

## Goals / Non-Goals

**Goals:**
- Render merged, deduplicated feed items from the server-side cache with no on-render upstream fetch.
- Support list, grid, and carousel layout modes driven by a single CSS layout enum — no per-layout backend variation.
- Apply server-side metadata filtering when a `metadataFilter` is configured for a placement.
- Enforce an admin-controlled feed host allow-list and a per-render item cap.
- Degrade gracefully: a single failing feed source must not suppress items from healthy sources.

**Non-Goals:**
- The widget does not own feed fetching or scheduling — that is `background-job-feed-refresh`'s responsibility.
- Full-text search across feed items is out of scope for this change.
- Offline / push notification for new items is not provided.

## Decisions

### D1: No on-render fetch — cache-only reads

**Decision:** The widget backend reads exclusively from the feed-cache table written by `background-job-feed-refresh`. If the cache is empty for a placement's feed URLs the response returns an empty item list and the frontend displays an "Updating…" placeholder.

**Alternatives considered:** Perform a live fetch when cache is cold, falling back to cache. Maintains freshness but adds latency and introduces upstream failures at page-load time.

**Rationale:** Predictable, fast page loads are more important than absolute freshness for a feed widget. The background job ensures cache is populated within its configured interval; a one-time "Updating…" state on first install is acceptable.

### D2: Server-side metadata filter

**Decision:** When a placement specifies `metadataFilter: {fieldKey, value}`, the backend joins cached items against per-item metadata stored alongside the cache entry and applies a `fieldKey = value` equality filter before returning results.

**Alternatives considered:** Client-side filtering after receiving all items. Simpler backend but leaks items the filter should suppress, and is impractical at large item counts.

**Rationale:** Server-side filtering avoids sending unwanted data to the client and keeps the Vue component stateless with respect to filtering logic. The metadata is already stored in the cache table by `background-job-feed-refresh`.

### D3: Three-mode layout enum (list / grid / carousel)

**Decision:** The `layoutMode` placement config field accepts exactly `list | grid | carousel`. All three are implemented as CSS display modes (`flex-column`, `grid`, `flex-row overflow-x-scroll`) on a shared item template. The backend API is layout-agnostic.

**Alternatives considered:** Separate Vue components per layout. More isolated but triples the component surface and duplicates item-rendering logic.

**Rationale:** A single item template with a CSS wrapper class switch is maintainable, keeps the API response identical across modes, and lets future layout additions avoid backend changes.

### D4: Max items per render — admin cap with hard ceiling

**Decision:** Admin setting `mydash.news_widget_max_items_per_render` controls the default maximum returned per API call (default 50). A hard ceiling of 200 is enforced server-side regardless of the admin setting or the `limit` query parameter to prevent out-of-memory conditions on large merged feeds.

**Alternatives considered:** No server-side cap — rely on the client's requested `limit`. Allows integrators to accidentally configure unbounded fetches; feeds can contain thousands of entries.

**Rationale:** The hard ceiling is a safety rail. The admin-configurable default is a sensible per-deployment tuning knob. Both are enforced in `NewsWidgetService` before the response is built.

### D5: Thumbnail fallback chain

**Decision:** For each item, thumbnail resolution follows: `<enclosure url>` → `og:image` meta extracted at cache time → first `<img>` src in body content → null (no image rendered). The fallback chain is resolved by `background-job-feed-refresh` at cache-write time and stored as `thumbnailUrl` alongside the cache entry; the widget reads the pre-resolved value.

**Alternatives considered:** Resolve thumbnails at render time in the frontend. Adds client-side image probing requests and inconsistent resolution across browsers.

**Rationale:** Resolving the chain once at cache time and storing the result avoids per-render external requests and keeps the widget component simple.

### D6: Feed host allow-list

**Decision:** Admin setting `mydash.news_widget_allowed_feed_hosts` (JSON array of hostnames) controls which feed origins are permitted. An empty array means all hosts are allowed. URLs whose hostnames are not on a non-empty list are silently skipped at config-save time, not at fetch time.

**Alternatives considered:** Block at fetch time in the background job. Cleaner failure surface but the widget config UI would not surface the restriction to the configuring user.

**Rationale:** Skipping at config-save time surfaces the restriction immediately in the config UI (the saved feed list omits blocked hosts), giving editors immediate feedback and preventing background-job attempts against disallowed origins.

### D7: Failure-tolerance badge

**Decision:** When one or more feed sources fail to populate the cache (tracked via a `fetchStatus` map in the cache table), the widget renders available items and shows a small corner badge with a count of unavailable sources. Clicking the badge expands a tooltip listing affected feed URLs.

**Alternatives considered:** Show a top-level error banner that blocks rendering. Disruptive — a single broken feed should not degrade the whole widget.

**Rationale:** Partial failure is the expected steady state for external feeds. Surfacing it as a non-blocking badge keeps the widget useful while informing the dashboard admin that remediation may be needed.

## Risks / Trade-offs

- **Cache staleness** → Acceptable given the background job interval; "Updating…" placeholder communicates state on cold start.
- **HTML sanitisation bypass via future allowed-tag additions** → Allowlist must remain minimal; any expansion requires a security review.
- **Metadata filter field mismatch** → If `fieldKey` references a non-existent field the filter returns zero items silently; empty-state copy should hint at checking filter config.

## Open follow-ups

- Evaluate whether the "Updating…" placeholder should trigger a single synchronous fetch on cold-start rather than staying blank until the background job runs.
- Consider exposing per-source fetch status via a dedicated admin view rather than per-widget corner badges.
- Specify the deduplication algorithm for items shared across sources — tie-break rule for identical GUID/link collisions is undefined.
