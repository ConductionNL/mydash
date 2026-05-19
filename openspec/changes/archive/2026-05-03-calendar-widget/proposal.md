# Calendar Widget

## Why

MyDash users often manage events across multiple sources — internal Nextcloud Calendar events and external calendar feeds (ICS URLs). Currently, there is no single place to see a merged view of all these events on the dashboard. Users must either leave the dashboard and navigate to the Calendar app, or manually track external calendar subscriptions. A unified calendar widget bridges this gap by rendering a single, configurable view that merges internal and external events, with multiple display modes (month, week, agenda, upcoming list) to suit different user workflows.

## What Changes

- Register a new dashboard widget with id `mydash_calendar` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` to specify internal calendars (via NC Calendar principal URIs), external ICS URLs, view mode, days-ahead threshold, and color-by-calendar preference.
- Implement backend `GET /api/widgets/calendar/{placementId}/events?from=ISO&to=ISO` to return merged, deduplicated event objects with source attribution (internal or external) and calendar metadata.
- Expand recurring events server-side into individual instances using sabre/vobject (already vendored in Nextcloud) for the requested `from..to` date range.
- Cache external ICS fetches for 30 minutes using Nextcloud's `ICache`, tunable via admin setting `mydash.calendar_widget_ics_cache_ttl_seconds`.
- Enforce an allow-list of external ICS hosts via admin setting `mydash.calendar_widget_allowed_ics_hosts` (JSON array of hostnames; empty = all allowed; populated = restricted to those hosts). URLs not matching the allow-list are silently skipped with a warning logged.
- Implement event-time access checks using Nextcloud's delegation model so private events the user cannot read are absent from responses.
- Provide Vue 3 SFC `CalendarWidget.vue` with four render modes: month (lightweight 7×N grid), week (7-column day-by-day), agenda (chronological list grouped by day), and upcoming-list (next N events flat). Click → open event detail or deep-link to NC Calendar app.
- Display empty state ("No events in the next N days") and failure tolerance (single ICS URL fetch failure does not break the whole widget; successful sources render, failed ones are noted in a corner notice).

## Capabilities

### New Capabilities

- `calendar-widget` — A new MyDash dashboard widget capability providing merged calendar views from Nextcloud and external ICS sources.

## Impact

**Affected code:**

- `lib/Service/CalendarWidgetService.php` — core logic for fetching and merging internal + external events, recurring expansion, caching, allow-list checks.
- `lib/Controller/WidgetController.php` — new endpoint `GET /api/widgets/calendar/{placementId}/events`.
- `src/components/widgets/CalendarWidget.vue` — four-mode render component (month, week, agenda, upcoming-list).
- `src/components/widgets/eventpicker/CalendarWidgetConfig.vue` — placement config UI for selecting internal calendars, ICS URLs, view mode, daysAhead.
- `appinfo/routes.php` — register the new calendar widget events endpoint.
- `lib/Migration/VersionXXXXDate2026...AddCalendarWidgetSettings.php` — schema migration adding app config settings (`mydash.calendar_widget_*` keys).
- `src/stores/widgets.js` — add widget-specific runtime state for cached event data and fetch status per placement.

**Affected APIs:**

- 1 new route: `GET /api/widgets/calendar/{placementId}/events`
- 0 changes to existing routes.

**Dependencies:**

- `sabre/vobject` — already vendored in Nextcloud; used for recurring event expansion.
- `OCP\Calendar\IManager` — Nextcloud Calendar ICS fetch and search integration.
- `OCP\ICache` — cache events for 30 minutes per placement.
- `OCP\IAppConfig` — admin settings for ICS cache TTL and allow-list.
- No new composer or npm dependencies.

**Migration:**

- Zero-impact: app config keys are created on demand via IAppConfig getter. No schema changes required beyond optional logging setup.
- No data backfill required. Existing placements without calendar widget config simply don't render the widget.
