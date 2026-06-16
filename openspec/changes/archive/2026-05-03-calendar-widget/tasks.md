# Tasks — calendar-widget

## 1. Widget registration and service layer

- [ ] 1.1 Create `lib/Service/CalendarWidgetService.php` with core methods:
  - `getWidgetInfo(): array` — returns widget metadata (id, title, icon_url, v2 API support)
  - `getEventsForPlacement(int $placementId, string $from, string $to): array` — orchestrates internal + external event fetching
  - `fetchInternalEvents(array $principals, string $from, string $to): array` — calls `IManager::search()`
  - `fetchExternalIcsEvents(array $urls, string $from, string $to): array` — HTTP fetch + caching + parsing
  - `expandRecurringEvents(VCalendar $vcal, string $from, string $to): array` — uses sabre/vobject
  - `checkAllowList(string $url): bool` — checks hostname against allow-list setting
- [ ] 1.2 Register the widget in a boot/lifecycle hook or service provider:
  - Hook into Nextcloud's dashboard widget registration (e.g., in `AppInfo/Bootstrap.php` or a listener on `IManager`)
  - Call `IManager::registerWidget()` with a widget provider or inline metadata
  - Widget id: `mydash_calendar`, title: translatable `app.mydash.calendar_widget_title`, icon: calendar icon URL
- [ ] 1.3 Create fixture-based PHPUnit tests for `CalendarWidgetService`:
  - `testFetchInternalEventsSuccess` — mock `IManager::search()` with sample events
  - `testFetchExternalIcsWithCache` — mock HTTP fetch, verify caching via `ICache`
  - `testExpandRecurringEventsDaily` — sabre/vobject expansion for FREQ=DAILY
  - `testAllowListMatching` — hostname match, mismatch, case-insensitivity

## 2. Backend controller and routing

- [ ] 2.1 Add endpoint method to `lib/Controller/WidgetController.php`:
  - `public function calendarEvents(int $placementId, string $from, string $to): DataResponse`
  - Validate placement ownership (return 403 if user cannot see dashboard)
  - Validate date format (ISO 8601; return 400 if invalid)
  - Call `CalendarWidgetService::getEventsForPlacement()`
  - Return HTTP 200 with event array
  - Decorate with `#[NoCSRFRequired]` and `#[NoAdminRequired]`
- [ ] 2.2 Register route in `appinfo/routes.php`:
  - `GET /api/widgets/calendar/{placementId}/events` → `WidgetController::calendarEvents()`
  - Route requirements: placementId (digits), from/to (query params, ISO 8601)
- [ ] 2.3 Create PHPUnit test for controller:
  - `testCalendarEventsSuccess` — mock service, verify HTTP 200 + event payload
  - `testCalendarEventsMissingPlacement` — return 404 when placement doesn't exist
  - `testCalendarEventsAccessDenied` — return 403 when user cannot see dashboard
  - `testCalendarEventsInvalidDateFormat` — return 400 for invalid from/to

## 3. Placement configuration and schema migration

- [ ] 3.1 Create `lib/Migration/VersionXXXXDate2026...AddCalendarWidgetSettings.php`:
  - Add app config table entries for `mydash.calendar_widget_ics_cache_ttl_seconds` (default 1800)
  - Add app config table entries for `mydash.calendar_widget_allowed_ics_hosts` (default `null` or `[]`)
  - NOTE: No new `oc_mydash_*` table columns required; all config is stored in placement `widgetContent` JSON
- [ ] 3.2 Add getter/setter methods in `WidgetPlacementService` or factory to safely parse `widgetContent` JSON:
  - `extractCalendarConfig(WidgetPlacement $placement): array` — returns parsed config with defaults (internalCalendars, externalIcsUrls, viewMode, daysAhead, colorByCalendar)
  - Validate and sanitize config (URLs must be HTTP/HTTPS, principals must match pattern)
- [ ] 3.3 Create PHPUnit test for placement config parsing:
  - `testExtractCalendarConfigWithDefaults` — verify default values are applied
  - `testExtractCalendarConfigValidation` — invalid URLs are rejected, valid ones accepted

## 4. External ICS fetching, caching, and allow-list

- [ ] 4.1 Implement in `CalendarWidgetService::fetchExternalIcsEvents()`:
  - For each URL in `externalIcsUrls`:
    - Check allow-list via `checkAllowList($url)`
    - If disallowed, skip and log warning
    - If allowed, try to fetch from cache key `mydash_calendar_ics_{placementId}_{urlHash}`
    - If cache hit, use cached raw ICS
    - If cache miss, HTTP fetch with 10-second timeout (use `IClientService`)
    - On HTTP 4xx/5xx, log warning, skip URL (see REQ-CAL-009)
    - On timeout, log warning, skip URL
    - On success, cache raw ICS for TTL (read from `IAppConfig::getValueInt()`)
    - Parse cached/fetched ICS via `\Sabre\VObject\Reader::read()`
    - Expand recurring events (see task 4.2)
    - Collect failure info for UI notice
- [ ] 4.2 Implement `CalendarWidgetService::expandRecurringEvents()`:
  - Iterate VEVENT components in the VCalendar
  - For each event with RRULE, use sabre/vobject's recurrence expansion to generate instances in [from, to]
  - For events without RRULE, add as single instance if within [from, to]
  - Handle EXDATE (exceptions) and RECURRENCE-ID
  - Return flat array of event objects with normalized fields (uid, title, start, end, allDay, location, description, calendarId, calendarName, color, source)
  - Log malformed RRULEs at INFO/WARNING level (don't crash widget)
- [ ] 4.3 Implement `CalendarWidgetService::checkAllowList()`:
  - Read `mydash.calendar_widget_allowed_ics_hosts` from `IAppConfig::getValueString()`
  - If empty or null, return true (all allowed)
  - Otherwise, parse URL, extract hostname (case-insensitive)
  - Check for exact match in allow-list (no wildcard subdomain expansion)
  - Return boolean
- [ ] 4.4 Create PHPUnit tests:
  - `testFetchExternalIcsWithCacheTTL` — verify TTL setting is respected
  - `testFetchExternalIcsHttpError` — mock 4xx/5xx response, verify skip + warning
  - `testFetchExternalIcsTimeout` — mock timeout, verify graceful skip
  - `testFetchExternalIcsAllowListDisallowed` — URL not in allow-list, skipped
  - `testFetchExternalIcsAllowListAllowed` — URL matches allow-list, fetched

## 5. Internal calendar event fetching with Nextcloud Calendar integration

- [ ] 5.1 Implement in `CalendarWidgetService::fetchInternalEvents()`:
  - For each principal URI in `internalCalendars`:
    - Call `IManager::search()` with date range [from, to] and the principal context
    - Nextcloud's Calendar app handles ACL (user sees only calendars/events they can read)
    - Collect returned events, normalize to event object format
    - If `IManager::search()` throws (calendar deleted, permission denied), log error and skip
    - Return flat array of event objects with source='internal'
- [ ] 5.2 Create PHPUnit test:
  - `testFetchInternalEventsSuccess` — mock `IManager::search()`, verify events returned
  - `testFetchInternalEventsAccessDenied` — user cannot read calendar, skipped gracefully

## 6. Event normalization and deduplication

- [ ] 6.1 Implement in `CalendarWidgetService::normalizeEvent()` (private helper):
  - Input: raw event object (from Nextcloud or sabre/vobject VEVENT)
  - Output: standardized event object with fields: uid, title, start (ISO 8601), end (ISO 8601), allDay (boolean), location (nullable), description (nullable), calendarId, calendarName, color, source ('internal'|'external')
  - Handle edge cases: missing title → fallback to "Untitled", missing color → NC theme default
- [ ] 6.2 Implement deduplication in `getEventsForPlacement()`:
  - Merge internal + external events
  - Deduplicate by (uid, calendarId) pair (same event in multiple calendar sources should appear once)
  - Sort by start time
- [ ] 6.3 Create PHPUnit test:
  - `testNormalizeEventFromInternal` — verify field mapping from NC event
  - `testNormalizeEventFromVevent` — verify field mapping from sabre VEVENT
  - `testDeduplicationByUid` — same uid in multiple calendars appears once

## 7. Frontend widget component (Vue 3 SFC)

- [ ] 7.1 Create `src/components/widgets/CalendarWidget.vue`:
  - Props: `placement: object` (with widgetContent config)
  - Data: `events: []`, `loading: false`, `error: null`, `failedSources: []`
  - Computed: `viewMode`, `daysAhead`, `colorByCalendar` (from placement config with defaults)
  - Method `fetchEvents()`: async call to `GET /api/widgets/calendar/{placementId}/events?from=...&to=...`
    - Parse date range from viewMode (month=current month, week=current week, agenda/upcoming=today+daysAhead)
    - Handle loading state, errors, failure notices
  - Methods for each view mode: `renderMonth()`, `renderWeek()`, `renderAgenda()`, `renderUpcomingList()`
  - Click handler: open event detail via `linkToRoute('calendar.view.index')` or embed a detail panel
  - Lifecycle: `onMounted` → `fetchEvents()`, optional `watch` on placement config → refetch
- [ ] 7.2 Create `src/components/widgets/calendar/CalendarMonth.vue`:
  - Lightweight 7×N grid (no heavy library)
  - Display day numbers, event titles (clipped if space-limited)
  - Highlight current day
  - Color events by calendar (if `colorByCalendar=true`)
  - Click event → detail panel or NC Calendar app deep-link
- [ ] 7.3 Create `src/components/widgets/calendar/CalendarWeek.vue`:
  - 7-column layout (Sun–Sat)
  - Show day name, date, and events with times
  - Scroll horizontal on mobile or use responsive wrapping
- [ ] 7.4 Create `src/components/widgets/calendar/CalendarAgenda.vue`:
  - Chronological list grouped by date (with date headers)
  - Each event entry: title, time range, calendar name, color dot
  - Sortable by time within day
- [ ] 7.5 Create `src/components/widgets/calendar/CalendarUpcomingList.vue`:
  - Flat chronological list of next N events
  - No date grouping (one flat list)
  - Each entry: title, date+time, calendar name, color dot
- [ ] 7.6 Create empty-state and error handling:
  - `EmptyState.vue` — "No events in the next X days" or "No calendars configured"
  - `FailureNotice.vue` — corner notice showing failed sources, clickable for details
  - `ErrorState.vue` — "Failed to load events" with retry button
- [ ] 7.7 Create Playwright E2E tests:
  - `testCalendarWidgetMonthView` — widget renders month grid with sample events
  - `testCalendarWidgetClickEvent` — click event → opens detail or NC Calendar app
  - `testCalendarWidgetEmptyState` — no events → shows empty state message
  - `testCalendarWidgetFailureNotice` — one ICS URL fails → failure notice appears, other events render

## 8. Widget configuration UI component

- [ ] 8.1 Create `src/components/widgets/eventpicker/CalendarWidgetConfig.vue`:
  - Used by `WidgetAddEditModal.vue` when configuring a calendar widget placement
  - Form sections:
    - **Internal Calendars**: multi-select dropdown of available NC Calendar principals (load via API)
    - **External ICS URLs**: text input list (add/remove URLs)
    - **View Mode**: radio buttons or dropdown (month, week, agenda, upcoming-list)
    - **Days Ahead**: number input (default 14, only shown if viewMode is agenda/upcoming-list)
    - **Color by Calendar**: toggle (default true)
  - Validation: URLs must be HTTP/HTTPS, valid format
  - On save: emit `update:widgetContent` with serialized config object
- [ ] 8.2 Create `src/components/widgets/eventpicker/CalendarSourcePicker.vue` (sub-component):
  - Lists available Nextcloud Calendar principals (load via `CalendarService` or custom API)
  - Multi-select checkboxes
  - Display calendar name and icon
- [ ] 8.3 Integrate into `WidgetAddEditModal.vue`:
  - When user selects `mydash_calendar` widget from picker
  - Display `CalendarWidgetConfig.vue` instead of default generic config panel
- [ ] 8.4 Create Playwright test:
  - `testCalendarWidgetConfigUI` — open config modal, select calendars, set view mode, save

## 9. Internationalization (i18n)

- [ ] 9.1 Add Dutch (nl) and English (en) translation keys:
  - `app.mydash.calendar_widget_title` — "Kalender" / "Calendar"
  - `app.mydash.calendar_empty_state` — "Geen evenementen in de komende {N} dagen" / "No events in the next {N} days"
  - `app.mydash.calendar_no_sources` — "Geen kalenders geconfigureerd" / "No calendars configured"
  - `app.mydash.calendar_loading` — "Kalenders laden…" / "Loading calendars…"
  - `app.mydash.calendar_error` — "Fout bij laden evenementen" / "Failed to load events"
  - `app.mydash.calendar_retry` — "Opnieuw proberen" / "Retry"
  - `app.mydash.calendar_failure_notice` — "{N} kalenderbron(nen) niet beschikbaar" / "{N} calendar source(s) unavailable"
  - `app.mydash.calendar_internal` — "Nextcloud" / "Nextcloud"
  - `app.mydash.calendar_external` — "Extern" / "External"
- [ ] 9.2 Add translation files (if not using existing JSON structure):
  - `l10n/nl.json` — Dutch translations
  - `l10n/en.json` — English translations (fallback)

## 10. Quality gates and testing

- [ ] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan):
  - Fix any pre-existing issues in touched files
  - New PHP code must pass all checks
- [ ] 10.2 Run ESLint on all Vue/JS files:
  - `npm run lint` on `src/components/widgets/`
  - Fix any warnings or errors
- [ ] 10.3 Run Stylelint on component stylesheets
- [ ] 10.4 Confirm all 10 hydra-gates pass locally before opening PR
- [ ] 10.5 Add SPDX-License-Identifier and SPDX-FileCopyrightText headers to every new PHP file (inside docblock)
- [ ] 10.6 PHPUnit test coverage:
  - Aim for 80%+ line coverage on `CalendarWidgetService`
  - All public methods tested
  - Edge cases (empty arrays, null fields, malformed data) covered
- [ ] 10.7 Playwright E2E test coverage:
  - Calendar widget renders in dashboard
  - All four view modes render correctly
  - Click event → interaction works
  - Empty state + failure tolerance scenarios
- [ ] 10.8 Manual testing on local Nextcloud instance:
  - Create a dashboard with calendar widget
  - Configure internal + external calendars
  - Verify events merge correctly
  - Test each view mode
  - Test failure recovery (disable external URL, verify no crash)

## 11. Documentation and changelog

- [ ] 11.1 Update `CHANGELOG.md` with:
  - New feature: "Add calendar widget for merged Nextcloud + external ICS events"
  - List view modes, caching, allow-list features
- [ ] 11.2 Update `README.md` (if applicable) with widget description
- [ ] 11.3 Add code comments to `CalendarWidgetService` explaining caching strategy, allow-list logic, and failure handling
