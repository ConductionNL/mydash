---
capability: calendar-widget
status: draft
---

# Calendar Widget Specification

## ADDED Requirements

### Requirement: REQ-CAL-001 Widget registration

The system MUST register a MyDash dashboard widget with id `mydash_calendar` via `OCP\Dashboard\IManager::registerWidget()` so it appears in the widget picker alongside other Nextcloud dashboard widgets.

#### Scenario: Widget appears in picker

- GIVEN the calendar-widget app is installed and enabled
- WHEN a user opens the MyDash widget picker dialog
- THEN the `mydash_calendar` widget MUST appear in the list with a title (e.g., "Calendar") and an icon
- AND the widget MUST be selectable for placement on a dashboard

#### Scenario: Multiple instances allowed

- GIVEN a dashboard already has one calendar widget placement
- WHEN the user adds a second calendar widget to the same dashboard
- THEN both placements MUST coexist with independent configurations
- AND the system MUST treat them as separate instances

#### Scenario: Widget registration survives app reload

- GIVEN the widget is registered
- WHEN Nextcloud cache is cleared or the app is reloaded
- THEN the `mydash_calendar` widget MUST still be discoverable in the picker

#### Scenario: Widget registration includes metadata

- GIVEN the widget is registered
- WHEN the widget picker fetches widget metadata
- THEN the widget object MUST include at minimum: id, title, icon_url, and v2 API support indication

### Requirement: REQ-CAL-002 Placement configuration

The system MUST store per-placement widget configuration in the `oc_mydash_widget_placements.widgetContent` JSON field, allowing users to specify which calendars to display, view mode, and other display preferences.

#### Scenario: Config for internal calendars

- GIVEN a user is configuring a calendar widget placement
- WHEN they select internal Nextcloud Calendar sources
- THEN the configuration MUST store an `internalCalendars: string[]` field
- AND each entry MUST be a valid NC Calendar principal URI (e.g., `principals/users/alice/calendar-id`)
- NOTE: Principal resolution is delegated to `OCP\Calendar\IManager`

#### Scenario: Config for external ICS URLs

- GIVEN a user is configuring a calendar widget placement
- WHEN they add external ICS URLs
- THEN the configuration MUST store an `externalIcsUrls: string[]` field
- AND each entry MUST be a valid HTTP/HTTPS URL ending in `.ics` or a feed-like path
- NOTE: URL validation and fetch failures are handled gracefully (see REQ-CAL-009)

#### Scenario: Config for view mode

- GIVEN a user is configuring a calendar widget placement
- WHEN they choose a display mode
- THEN the configuration MUST store a `viewMode: 'month'|'week'|'agenda'|'upcoming-list'` field
- AND the default viewMode MUST be `'upcoming-list'` when not explicitly set

#### Scenario: Config for days-ahead threshold

- GIVEN a user is configuring a calendar widget placement
- WHEN they set how far ahead to display events
- THEN the configuration MUST store a `daysAhead: number` field
- AND the default value MUST be `14`
- AND the field MUST only affect `agenda` and `upcoming-list` modes; month/week modes ignore it

#### Scenario: Config for color-by-calendar preference

- GIVEN a user is configuring a calendar widget placement
- WHEN they toggle event coloring behavior
- THEN the configuration MUST store a `colorByCalendar: boolean` field
- AND the default value MUST be `true`

#### Scenario: Config is JSON-serializable

- GIVEN a placement with calendar widget config
- WHEN the placement is fetched from the database
- THEN the `widgetContent` MUST deserialize cleanly to a JavaScript object
- AND all string array fields MUST have no spurious whitespace or encoding issues

### Requirement: REQ-CAL-003 Events endpoint

The system MUST expose `GET /api/widgets/calendar/{placementId}/events?from=ISO&to=ISO` that returns a merged array of events from internal Nextcloud calendars and external ICS URLs, filtered to the requested date range.

#### Scenario: Fetch events for date range

- GIVEN a calendar widget placement with internalCalendars=[...] and externalIcsUrls=[...]
- AND the requesting user has read access to those internal calendars
- WHEN the frontend sends GET /api/widgets/calendar/{placementId}/events?from=2026-05-01&to=2026-05-31
- THEN the system MUST return HTTP 200 with a JSON array of event objects
- AND each event MUST include: uid, title, start (ISO 8601), end (ISO 8601), allDay (boolean), location (string, nullable), description (string, nullable), calendarId (string), calendarName (string), color (string, hex or NC theme var), source ('internal'|'external')

#### Scenario: No events returns empty array

- GIVEN a calendar widget with valid config but no events in the date range
- WHEN the frontend fetches events
- THEN the system MUST return HTTP 200 with an empty array `[]`

#### Scenario: Missing placement returns 404

- GIVEN a placement with id 999 does not exist
- WHEN the frontend sends GET /api/widgets/calendar/999/events
- THEN the system MUST return HTTP 404

#### Scenario: Ownership check

- GIVEN placement 100 belongs to user alice's dashboard
- WHEN user bob sends GET /api/widgets/calendar/100/events
- THEN the system MUST return HTTP 403 (forbidden — bob cannot see alice's private dashboard)

#### Scenario: Invalid date range returns 400

- GIVEN the frontend sends GET /api/widgets/calendar/{placementId}/events?from=invalid&to=2026-05-31
- THEN the system MUST return HTTP 400 with error detail
- AND both `from` and `to` MUST be required ISO 8601 date strings

#### Scenario: Endpoint requires no CSRF token

- GIVEN a dashboard page is loaded
- WHEN the frontend makes repeated async calls to fetch events (e.g., on scroll or filter change)
- THEN the endpoint MUST have `#[NoCSRFRequired]` so these calls do not require CSRF validation
- NOTE: The placement ownership check provides sufficient security since event data is already dashboard-scoped

### Requirement: REQ-CAL-004 Recurring event expansion

The system MUST expand recurring events into individual instances for the requested date range using sabre/vobject's RRULE expansion logic.

#### Scenario: RRULE expansion for internal events

- GIVEN an internal calendar contains a recurring event "Daily standup" with RRULE:FREQ=DAILY;COUNT=5 starting 2026-05-01
- WHEN the frontend fetches events for 2026-05-01 to 2026-05-31
- THEN the response MUST include 5 separate event instances
- AND each instance MUST have a distinct start/end time while sharing uid root + recurrence-id

#### Scenario: RRULE expansion for external ICS

- GIVEN an external ICS URL contains a weekly recurring event "Team meeting" with RRULE:FREQ=WEEKLY;UNTIL=2026-05-31
- WHEN the frontend fetches events for 2026-05-01 to 2026-05-31
- THEN the response MUST include individual instances for each week
- AND instances outside the from/to range MUST NOT be included

#### Scenario: All-day recurring events

- GIVEN a recurring all-day event (DTSTART;VALUE=DATE)
- WHEN expanded
- THEN each instance MUST preserve allDay: true
- AND the start/end MUST still be valid ISO 8601 dates (YYYY-MM-DD format acceptable)

#### Scenario: Exceptions to recurring series

- GIVEN a recurring event with one exception (EXDATE)
- WHEN expanded
- THEN the exception instance MUST NOT appear in the result
- AND other instances MUST be included normally

#### Scenario: Invalid RRULE is skipped

- GIVEN an external ICS contains a malformed RRULE
- WHEN expansion is attempted
- THEN the system MUST log a warning
- AND the event MUST be skipped (not cause a 500 error)
- NOTE: Error handling is covered by REQ-CAL-009 (failure tolerance)

### Requirement: REQ-CAL-005 External ICS caching

The system MUST cache fetched external ICS content for 30 minutes (default) using Nextcloud's `ICache` to reduce load on external servers and improve dashboard load time.

#### Scenario: First fetch populates cache

- GIVEN an external ICS URL is configured on a placement
- WHEN the frontend fetches events and this is the first request to that URL from this placement
- THEN the system MUST fetch the ICS from the external server
- AND cache the raw ICS content (before parsing) in the `ICache` with key pattern `mydash_calendar_ics_{placementId}_{urlHash}`
- AND return the parsed events

#### Scenario: Subsequent fetch uses cache

- GIVEN the cache is populated with fresh ICS content
- WHEN the frontend fetches events again within 30 minutes
- THEN the system MUST use the cached ICS (no external fetch)
- AND parse and expand it
- AND return the same events (with timing variation only from new expansion)

#### Scenario: Cache TTL is admin-configurable

- GIVEN the admin sets `mydash.calendar_widget_ics_cache_ttl_seconds` to 3600 (1 hour)
- WHEN events are fetched
- THEN the cache entry MUST expire after 1 hour instead of the default 30 minutes
- AND the system MUST read the setting from `IAppConfig::getValueInt('mydash', 'mydash.calendar_widget_ics_cache_ttl_seconds', 1800)`

#### Scenario: Cache key includes URL hash

- GIVEN two placements reference the same external ICS URL
- WHEN both fetch events
- THEN they MUST share the same cache entry (same URL hash)
- AND only one external fetch occurs across both placements

#### Scenario: Cache is invalidated on settings change

- GIVEN a placement is reconfigured to use a different ICS URL
- WHEN events are fetched
- THEN the old cached ICS MUST NOT be used
- AND the new URL MUST be fetched

### Requirement: REQ-CAL-006 External ICS allow-list

The system MUST enforce an allow-list of external ICS host names via the admin setting `mydash.calendar_widget_allowed_ics_hosts` (JSON array of hostnames) to restrict which external calendars can be fetched.

#### Scenario: Allow-list is empty (default)

- GIVEN the admin setting `mydash.calendar_widget_allowed_ics_hosts` is not set or is an empty array
- WHEN a user configures an external ICS URL from any hostname
- THEN the URL MUST be allowed and fetched

#### Scenario: Allow-list is populated

- GIVEN the admin sets `mydash.calendar_widget_allowed_ics_hosts` to `["calendar.example.com", "feeds.internal.org"]`
- AND a user adds a placement with externalIcsUrls: `["https://calendar.example.com/cal.ics"]`
- WHEN events are fetched
- THEN the system MUST parse the hostname from the URL
- AND check if it matches an entry in the allow-list (case-insensitive domain match)
- AND if it matches, fetch the ICS
- AND if it does NOT match, skip that URL and log a warning

#### Scenario: Disallowed URL is silently skipped

- GIVEN a placement references `https://untrusted.external.net/cal.ics`
- AND the allow-list does NOT include `untrusted.external.net`
- WHEN events are fetched
- THEN the system MUST skip that URL
- AND MUST NOT attempt a fetch
- AND MUST log a warning (at INFO or WARNING level, not ERROR — so the dashboard still renders)
- AND MUST NOT include events from that URL in the response

#### Scenario: Allow-list hostname matching is case-insensitive

- GIVEN the allow-list includes `Calendar.Example.COM`
- AND a URL uses `calendar.example.com`
- WHEN fetched
- THEN the match MUST succeed (case-insensitive)

#### Scenario: Subdomain matching is NOT applied

- GIVEN the allow-list includes `example.com`
- AND a URL is `https://sub.example.com/cal.ics`
- WHEN fetched
- THEN the match MUST fail (exact domain match only, no wildcard)
- NOTE: If admins want broad subdomain access, they must add each subdomain explicitly or use a script to manage the list

### Requirement: REQ-CAL-007 View-time access control

The system MUST respect Nextcloud's calendar access control so that only events the viewing user can read are included in the response.

#### Scenario: User can read internal calendar events

- GIVEN user alice is viewing her own dashboard
- AND the placement is configured to fetch from alice's personal calendar
- WHEN events are fetched via `OCP\Calendar\IManager::search()`
- THEN the system MUST request events within alice's user context
- AND alice's read permission is implicitly checked by `search()`
- AND only calendars alice can read MUST be included

#### Scenario: Private events are absent

- GIVEN bob's calendar contains a private event that alice cannot read
- AND alice's dashboard placement is configured to fetch from bob's calendar
- WHEN alice fetches events
- THEN the private event MUST NOT appear in the response
- NOTE: This is enforced by Nextcloud's `IManager::search()` delegation model — the calendar app handles ACL

#### Scenario: Shared calendar with read permission

- GIVEN bob has shared his calendar with alice (read-only)
- AND alice's placement is configured to fetch from bob's calendar
- WHEN alice fetches events
- THEN the events alice can read MUST be included
- AND the system MUST not attempt to modify them

#### Scenario: Shared calendar without permission

- GIVEN bob's calendar is NOT shared with alice
- AND alice's placement somehow references bob's calendar in config
- WHEN alice fetches events
- THEN the system MUST return an empty result for that calendar
- AND MUST NOT surface an error (graceful degradation)

### Requirement: REQ-CAL-008 Render modes

The frontend MUST support four distinct calendar display modes, selectable via `viewMode` config, each with appropriate layout and interaction patterns.

#### Scenario: Month view renders grid

- GIVEN a placement is configured with viewMode: 'month'
- WHEN the widget is rendered
- THEN `CalendarWidget.vue` MUST display a 7×N grid (Sunday–Saturday columns, multiple weeks rows)
- AND each day cell MUST show the date and a compact list of events for that day (title only, no time)
- AND the grid MUST be lightweight (no heavy calendar library — custom HTML/CSS)

#### Scenario: Week view renders 7-column day view

- GIVEN a placement is configured with viewMode: 'week'
- WHEN the widget is rendered
- THEN the widget MUST display 7 columns (one per day of the week)
- AND each column MUST show the day name, date, and a chronological list of events for that day
- AND time information MUST be visible (e.g., 2:00 PM – 3:00 PM)

#### Scenario: Agenda view renders chronological list by day

- GIVEN a placement is configured with viewMode: 'agenda'
- WHEN the widget is rendered
- THEN the widget MUST display a chronological list of events
- AND events MUST be grouped by date with a date header (e.g., "Wednesday, May 1")
- AND within each day, events MUST be sorted by start time
- AND each event entry MUST show title, time range, and calendar name/color

#### Scenario: Upcoming-list view shows next N events flat

- GIVEN a placement is configured with viewMode: 'upcoming-list' and daysAhead: 14
- WHEN the widget is rendered
- THEN the widget MUST display the next 14 days of events in a flat, chronological list
- AND each event MUST show title, start date/time, calendar name, and color
- AND events MUST be sorted by start time globally (not grouped by day)

#### Scenario: View mode selection is persistent

- GIVEN a user selects month view for a placement
- WHEN the page is reloaded
- THEN the widget MUST still display in month view (viewMode is stored in placement config)

#### Scenario: Empty month view shows all-day event space

- GIVEN the current month has no all-day events
- WHEN the month view is rendered
- THEN the widget MUST still display the 7-column grid
- AND cells MUST not be empty (a light background or border is acceptable to show structure)

### Requirement: REQ-CAL-009 Failure tolerance

The system MUST handle failures in fetching external ICS sources gracefully so that a single failed URL does not prevent the entire widget from rendering.

#### Scenario: Single ICS URL fetch timeout

- GIVEN a placement references two external ICS URLs: A (responsive) and B (times out after 10 seconds)
- WHEN the frontend fetches events with a 15-second timeout per URL
- THEN the system MUST fetch A successfully and include its events in the response
- AND B MUST timeout and be skipped
- AND a notice MUST be logged (not surfaced as an error banner, but available in logs)
- AND the response MUST still return HTTP 200 with events from A

#### Scenario: Single ICS URL returns 4xx

- GIVEN a placement references a URL that returns HTTP 404
- WHEN the frontend fetches events
- THEN the system MUST catch the HTTP error
- AND skip that URL
- AND log a warning
- AND return HTTP 200 with events from other sources

#### Scenario: Single ICS URL returns 5xx

- GIVEN a placement references a URL that returns HTTP 500
- WHEN the frontend fetches events
- THEN the system MUST catch the HTTP error
- AND skip that URL
- AND log a warning (not treat it as a widget error)
- AND return HTTP 200 with events from other sources

#### Scenario: Failure notice in UI

- GIVEN one or more ICS URLs failed during fetch
- WHEN the widget renders
- THEN a small notice MUST appear in the corner (e.g., bottom-right) indicating "1 calendar source unavailable"
- AND clicking the notice MUST show details of which URLs failed
- AND the widget content MUST still display all successfully fetched events

#### Scenario: All ICS URLs fail

- GIVEN a placement has only external ICS URLs and all of them fail to fetch
- WHEN the widget renders
- THEN the widget MUST display the empty-state message (see REQ-CAL-010)
- AND the failure notice MUST be prominent (warning color, centered, or dismissable)
- AND the dashboard MUST not be blocked (the widget gracefully degrades)

#### Scenario: Internal calendar fetch error

- GIVEN an internal calendar fetch via `OCP\Calendar\IManager::search()` throws an exception
- WHEN the widget fetches events
- THEN the system MUST log the error
- AND skip that calendar
- AND return events from other calendars
- AND NOT return a 500 response

### Requirement: REQ-CAL-010 Empty state and feedback

The system MUST display appropriate empty-state messaging when there are no events to display, and provide clear visual feedback during loading and error states.

#### Scenario: Empty state for no events

- GIVEN a placement is configured correctly but has no events in the requested range
- WHEN the widget renders
- THEN the widget MUST display a message like "No events in the next 14 days"
- AND the message MUST include the `daysAhead` value (or the explicit date range for month view)
- AND the message text MUST be translatable (en/nl per i18n requirement)

#### Scenario: Empty state for no sources

- GIVEN a placement has empty internalCalendars and externalIcsUrls arrays
- WHEN the widget renders
- THEN the widget MUST display a message like "No calendars configured"
- AND an optional hint: "Edit this widget to add calendars or external feeds"

#### Scenario: Loading state

- GIVEN the frontend is fetching events asynchronously
- WHEN the widget is first displayed
- THEN the widget MUST show a loading indicator (spinner, skeleton, or placeholder)
- AND the loading state MUST not exceed 30 seconds (if longer, show a timeout notice)

#### Scenario: Loading state cleared on success

- GIVEN the loading state is displayed
- WHEN the API response arrives
- THEN the loading indicator MUST be hidden
- AND the events MUST be rendered

#### Scenario: Error state persists until retry

- GIVEN the API returns an error (5xx)
- WHEN the widget renders
- THEN an error message MUST be displayed (e.g., "Failed to load events")
- AND a retry button MUST be present
- AND clicking retry MUST re-fetch events
