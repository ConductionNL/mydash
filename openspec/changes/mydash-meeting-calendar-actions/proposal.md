# Meeting and calendar actions from dashboard

## Why

MyDash displays agenda items and calendar events to users, but today they are read-only. Users frequently need to add context, notes, or annotations to events directly from the dashboard view — without navigating away to the Nextcloud Calendar app. Common use cases:

- A participant jots down "agenda item pushed to next meeting" on a postponed event
- A chair adds "quorum met" or "absence excused" annotations during a meeting
- A secretary records "decision approved unanimously" alongside a governance event

Today this workflow requires opening the Calendar app separately, navigating to the event, then returning to the dashboard. The feature consolidates this into a single dashboard interface.

## What Changes

- Users can add annotations to visible agenda items / calendar events directly from the dashboard widget
- Annotations are saved to the Nextcloud Calendar app as event notes/descriptions (via the Calendar app's API)
- Annotations persist and are visible on subsequent dashboard loads
- Users can edit or remove existing annotations in-place without page reload

## Capabilities

### New Capabilities

- **dashboard-calendar-annotations**: Inline annotation of agenda items on the dashboard widget

### Related Capabilities

- `admin-templates` (group-routing): determines which group's calendars are visible
- `dashboards` (multi-scope-dashboards): the dashboard widget that renders agenda items
- Nextcloud Calendar integration: annotations are stored in Calendar events

## Impact

**Affected code:**

- `src/components/CalendarWidget.vue` — add annotation UI (edit, save, cancel buttons)
- `src/store/modules/calendarStore.js` — new actions to sync annotations with Calendar API
- `lib/Controller/CalendarController.php` — new endpoint `PUT /api/calendar/{id}/annotation` to persist annotations
- `lib/Service/CalendarService.php` — annotation persistence logic

**Affected APIs:**

- New HTTP endpoint: `PUT /index.php/apps/mydash/api/calendar-events/{eventId}/annotation` (authenticated, non-admin)
- Existing Nextcloud Calendar `/ocs/v2.php/apps/calendar/api/v1/events/{id}` is called server-side

**Dependencies:**

- Nextcloud Calendar app (must be installed and enabled)
- No new composer or npm dependencies

**Migration:**

- Zero schema impact: annotations are stored in Calendar event properties
- Existing calendar events are unmodified; the feature is purely additive
- No data backfill required
