# Design — Calendar widget

## Context

The calendar-widget spec (REQ-CAL-001..010) describes a merged calendar view for MyDash that combines internal Nextcloud calendars and external ICS feeds. Several architectural choices were left open in the proposal. This document resolves them based on ground-truth source code from an existing production implementation covering the same problem domain.

Source root examined: `intravox-source/`

## Goals / Non-Goals

**Goals**
- Pin the recurring-event expansion approach (server vs client).
- Define the canonical event response shape.
- Define the caching strategy for external ICS feeds (TTL, key, store).
- Define the allow-list mechanism and SSRF protection approach.
- Clarify how ACL on internal calendars is enforced.

**Non-Goals**
- Defining UI render details (covered by REQ-CAL-008).
- Choosing a public-share mechanism (not in scope for calendar-widget v1).

---

## Decisions

### D1: Recurring event expansion — server vs client

**Decision**: Expansion is done **server-side**, using `Sabre\VObject\VCalendar::expand()` for external ICS feeds, and relying on `OCP\Calendar\IManager::search()` timerange filtering (which itself uses sabre/vobject internally) for internal calendars. The client receives only fully-expanded, flat event instances scoped to the requested `from..to` range. No RRULE strings are sent to the frontend.

**Source evidence**:
- `intravox-source/lib/Service/ExternalIcsService.php:112-115` — `$vcalendar->expand(new \DateTime($rangeStart…), new \DateTime($rangeEnd…))` called before iterating `VEVENT`.
- `intravox-source/lib/Service/CalendarService.php:93-98` — `$calendar->search('', [], ['timerange' => ['start' => $rangeStart, 'end' => $rangeEnd]], …)` delegates expansion to NC's Calendar backend.
- `intravox-source/lib/Controller/CalendarController.php:105` — date range is capped at 1 year max to prevent unbounded RRULE expansion: `$end = $start->modify('+1 year')`.
- `intravox-source/src/components/CalendarWidget.vue:156-172` — frontend calls the events endpoint and stores `response.data.events`; no client-side expansion logic exists.

**Implication for sabre/vobject dependency**: the source app does **not** vendor sabre/vobject itself (`composer.json` has no sabre dependency). It uses the copy bundled in Nextcloud via the autoloader. MyDash must do the same — import `Sabre\VObject\…` without adding it to `composer.json`.

---

### D2: Cross-source deduplication by UID

**Decision**: **No cross-source deduplication is performed**. Internal calendar events and external ICS events are merged by a simple `array_merge` and then sorted by `start`. If the same UID appears in both an internal calendar and an external ICS feed (e.g., a user has subscribed an ICS feed both via NC Calendar and directly via the widget), duplicate instances will appear.

**Source evidence**:
- `intravox-source/lib/Service/CalendarService.php:117-127` — external ICS events are `array_merge`d with internal events with no dedup step.
- Both services suffix the UID with `-{start ISO}` to produce stable sort keys (e.g., `uid-2026-05-01T09:00:00+00:00`), but this composite key is not used to de-duplicate across sources.
- The source app explicitly **hides NC ICS subscriptions** from the calendar picker (`CalendarService.php:35-37`: `str_contains(get_class($calendar), 'Subscription')` → skip), steering users toward the external URL field instead. This separation-of-concerns design makes cross-source UID collisions unlikely in practice.

**Implication for spec**: REQ-CAL-003 does not mandate deduplication; the spec should clarify the response is a **union** (not intersection/dedup) of sources.

---

### D3: Response shape

**Decision**: The response is a **flat JSON array** of event objects, not grouped by source or calendar. Internal and external events share the same shape except for an `isExternal: bool` flag. The internal calendar includes `url` for deep-linking to the NC Calendar app; external events include `url` from the ICS `URL` property (or a heuristically derived link for known platforms such as Brightspace and Moodle).

**Canonical event shape observed**:
```json
{
  "uid": "<icalUID>-<startISO>",
  "summary": "string",
  "location": "string",
  "start": "ISO 8601",
  "end": "ISO 8601 | absent",
  "isAllDay": false,
  "calendarColor": "#hex",
  "calendarName": "string",
  "url": "string | absent",
  "isExternal": true
}
```

Internal events omit `isExternal` (field absent → falsy in JS). External events always have `isExternal: true`.

**Delta vs proposal / spec (REQ-CAL-003)**:
- Spec uses `title`; source uses `summary` (iCalendar field name). Align spec field name to `summary` or explicitly map it.
- Spec uses `source: 'internal'|'external'`; source uses `isExternal: bool`. Either works; `isExternal` is simpler.
- Spec includes `calendarId`; source includes `calendarName` + `calendarColor` but **not** a `calendarId`. The internal calendar key is not forwarded. The spec's `calendarId` requirement needs a decision (include or drop).
- Spec includes `description`; source does not map `DESCRIPTION`. Add it or mark as optional.

---

### D4: External ICS caching

**Decision**: Raw ICS content (before parsing) is cached using `ICacheFactory::createDistributed('intravox-ics')` with a **per-URL cache key** (`'ics_' . md5($url)`) and a **fixed 30-minute TTL** (`CACHE_TTL = 1800`). There is no admin-configurable TTL setting and no per-placement cache key. Two placements referencing the same URL share a single cache entry (confirmed: same URL → same `md5` key).

**Source evidence**:
- `intravox-source/lib/Service/ExternalIcsService.php:19` — `private const CACHE_TTL = 1800;`
- `intravox-source/lib/Service/ExternalIcsService.php:57-65` — check then populate cache using `$cacheFactory->createDistributed('intravox-ics')`.
- `intravox-source/lib/Service/ExternalIcsService.php:83` — `$this->cache->set($cacheKey, $body, self::CACHE_TTL)`.
- No `IAppConfig` read for TTL anywhere in the ICS service.

**Delta vs spec (REQ-CAL-005)**:
- Spec requires a `placementId` in the cache key (`mydash_calendar_ics_{placementId}_{urlHash}`); source uses URL-only key (shared across placements). Shared-key is more efficient. The spec's per-placement keying would prevent cache reuse. **Recommendation**: adopt the URL-only key pattern from source — update spec scenario "Cache key includes URL hash" to remove the `placementId` component.
- Spec requires admin-configurable TTL via `IAppConfig`. Source hardcodes it. The spec requirement is worth keeping as a MyDash improvement over the reference implementation.

---

### D5: Allow-list mechanism

**Decision**: There is **no hostname allow-list** in the reference implementation. Instead, the only controls are:
1. **HTTPS-only enforcement** — `validateUrl()` rejects any non-`https` scheme.
2. **SSRF protection** — `gethostbynamel($host)` resolves the hostname, then each resolved IP is checked with `FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. Requests to private/reserved IP ranges (RFC1918, loopback, link-local) are rejected.
3. **Response size cap** — responses larger than 1 MB are rejected (`MAX_RESPONSE_SIZE = 1048576`).
4. **Client-side pre-validation** — `CalendarController::parseExternalIcsUrls()` accepts only HTTPS URLs and silently drops non-HTTPS entries before reaching the service.

The admin settings page has no ICS allow-list UI; the only whitelist concept in admin settings is `video_domains`.

**Source evidence**:
- `intravox-source/lib/Service/ExternalIcsService.php:189-210` — `validateUrl()` method.
- `intravox-source/lib/Controller/CalendarController.php:222` — `parse_url($url, PHP_URL_SCHEME) === 'https'` pre-filter.
- `intravox-source/lib/Settings/AdminSettings.php:61-66` — only `video_domains` whitelist present.

**Delta vs spec (REQ-CAL-006)**:
- Spec designs an **admin hostname allow-list** (empty = allow all; populated = restrict). Source has **no such list** but has stronger SSRF protection instead.
- The SSRF DNS-resolution approach is production-grade and covers the primary attack surface. An optional allow-list remains worthwhile for enterprise lockdown, but the spec should add the SSRF/DNS-resolution guard as a **hard requirement** (not merely implied), and the allow-list as a **SHOULD** configurable feature.
- Spec currently says nothing about HTTPS-only enforcement on ICS URLs — this must be a hard requirement in REQ-CAL-006.

---

## Spec changes implied

- **REQ-CAL-003 response shape**: rename `title` → `summary`; change `source:'internal'|'external'` → `isExternal: bool`; mark `description` as optional; decide whether `calendarId` is required or optional (recommend optional).
- **REQ-CAL-004 expansion**: add explicit note that sabre/vobject is consumed from NC's bundled copy, not re-vendored; add 1-year range cap as a defensive requirement.
- **REQ-CAL-005 cache key**: remove `placementId` from cache key pattern — key MUST be `md5($url)` only so placements sharing a URL share one cache entry; keep admin-configurable TTL requirement as a MyDash addition.
- **REQ-CAL-006 allow-list**: add HTTPS-only as a hard requirement; add SSRF/private-IP DNS guard as a hard requirement; demote hostname allow-list from MUST to SHOULD (default empty = all HTTPS non-private-IP hosts allowed).
- **REQ-CAL-007 ACL**: add note that `CLASS:PRIVATE` and `CLASS:CONFIDENTIAL` events are filtered server-side by both `CalendarService` (internal) and `ExternalIcsService` (external) before being returned.
- **REQ-CAL-003 deduplication**: add a NOTE clarifying that no cross-source UID deduplication is performed; users who add the same feed as both an NC subscription and an external ICS URL will see duplicates.

---

## Open follow-ups

1. **`calendarId` in response**: decide whether to include the internal calendar's numeric key in the response (useful for deep-link construction) or omit it. Source omits it; spec currently requires it.
2. **`description` field**: spec includes it but source omits `DESCRIPTION` mapping entirely. Cost is low (one line in both parsers); confirm whether it is needed for the detail popover use-case.
3. **DNS-resolution SSRF timing**: `gethostbynamel()` is synchronous and blocking; under a slow DNS server this adds latency before the HTTP fetch. Consider caching the SSRF check result or using async DNS. Not blocking for v1 but worth noting.
4. **ICS subscription hiding**: source hides NC Calendar ICS subscriptions from the internal calendar picker. MyDash should adopt the same policy and document it clearly in the config UI help text to prevent user confusion.
5. **`url` field for internal events**: source deep-links to `/apps/calendar/timeGridDay/{date}` rather than to the specific event. If a more precise per-event deep-link is needed (e.g., via CalDAV href), it requires additional data not returned by `IManager::search()`.
