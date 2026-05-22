# Specification — Dashboard Calendar Annotations

## REQ-CAL-001: Add annotation to event

**Type:** Feature | **Priority:** High | **Demand:** 14 mentions

GIVEN an agenda item is visible on the MyDash calendar widget
WHEN the user locates the event card and clicks the "Add annotation" button
THEN an inline textarea appears with placeholder text "Add a note..."
AND the textarea is focused and ready for input
AND the user can type text up to 500 characters

WHEN the user types an annotation (e.g., "Quorum verified")
AND clicks the "Save" button
THEN a `PUT /api/calendar-events/{eventId}/annotation` request is sent with the annotation text
AND on success (HTTP 200), the textarea closes
AND the annotation is displayed as a caption below the event title
AND the annotation persists when the dashboard is refreshed

WHEN the Calendar app denies write permission (HTTP 403)
THEN a user-facing error message is displayed: "You don't have permission to edit this event"
AND the textarea remains open for the user to cancel

ACCEPTANCE CRITERIA:
1. Save button is disabled while the request is in flight (prevents duplicate submissions)
2. Cancel button closes the textarea without saving
3. Annotation text is trimmed of leading/trailing whitespace before saving
4. Empty annotation text (after trim) is treated as "remove annotation" (see REQ-CAL-003)
5. All strings are translatable via `t('app', 'key')` with English default and Dutch in `l10n/nl.json`

---

## REQ-CAL-002: Edit existing annotation

**Type:** Feature | **Priority:** High | **Demand:** 14 mentions

GIVEN an event with an existing annotation is visible on the dashboard
WHEN the user clicks on the annotation text or an "Edit" button
THEN the annotation text moves into an editable textarea
AND the existing text is pre-filled in the field
AND Save/Cancel buttons appear alongside the textarea

WHEN the user modifies the text
AND clicks "Save"
THEN the `PUT /api/calendar-events/{eventId}/annotation` request is sent with the updated text
AND on success, the textarea closes
AND the updated annotation is displayed immediately (no page reload required)

ACCEPTANCE CRITERIA:
1. The original annotation text is not lost if the user clicks Cancel
2. Editing does not require page reload or dashboard refresh
3. Multiple events can be edited independently without interference
4. Network errors show a transient error message; user can retry

---

## REQ-CAL-003: Remove annotation

**Type:** Feature | **Priority:** Medium | **Demand:** 14 mentions

GIVEN an annotated event is visible on the dashboard
WHEN the user opens the annotation for editing
AND clears all text from the textarea
AND clicks "Save"
THEN a `PUT /api/calendar-events/{eventId}/annotation` request is sent with an empty string
AND the annotation is deleted from the Calendar event
AND the annotation caption disappears from the event card

ACCEPTANCE CRITERIA:
1. A "Delete annotation" button is not required; clearing the textarea + Save is sufficient
2. The event card layout does not shift when the annotation is removed (no visual jank)
3. Removing an annotation does not affect other event properties

---

## REQ-CAL-004: Permission enforcement

**Type:** Security | **Priority:** High

GIVEN a user who does not own or have edit permission on a Calendar event
WHEN they attempt to add or modify an annotation on that event
THEN the server-side `CalendarService::updateEventAnnotation()` checks the user's permission via Calendar's RBAC
AND if permission is denied, a 403 Forbidden response is returned
AND a user-facing message is displayed: "Permission denied"

GIVEN a user who is not authenticated
WHEN they attempt to add an annotation
THEN a 401 Unauthorized response is returned
AND the annotation form is not shown

ACCEPTANCE CRITERIA:
1. Permission check happens server-side before any Calendar API call
2. No information about the event is leaked if permission is denied
3. Unauthenticated users are redirected to login (Nextcloud default behavior)

---

## REQ-CAL-005: API endpoint specification

**Type:** Technical | **Priority:** High

The following HTTP endpoint is created:

```
PUT /index.php/apps/mydash/api/calendar-events/{eventId}/annotation
Content-Type: application/json

{
  "annotation": "Optional annotation text, or empty string to remove"
}
```

**Authentication:** Required (`#[NoAdminRequired]`), any authenticated user

**Response on success (200 OK):**

```json
{
  "id": "{eventId}",
  "title": "Board Meeting",
  "annotation": "Quorum met, 5 attendees",
  "updated": "2026-05-22T10:30:00Z"
}
```

**Response on permission denied (403 Forbidden):**

```json
{
  "message": "You don't have permission to edit this event"
}
```

**Response on not found (404 Not Found):**

```json
{
  "message": "Event not found"
}
```

**Response on error (500 Internal Server Error):**

```json
{
  "message": "Operation failed"
}
```

ACCEPTANCE CRITERIA:
1. Request body is validated: `annotation` field is optional, if present must be a string
2. Response includes the updated event's `id`, `title`, `annotation` (current value), and `updated` timestamp
3. HTTP status codes are correct per the specification above
4. No stack traces or internal error details in response bodies
5. All dates in responses use ISO 8601 format (UTC)

---

## REQ-CAL-006: Frontend state management

**Type:** Technical | **Priority:** Medium

The calendar widget maintains frontend state for:

- `events`: array of event objects fetched from Calendar API, each including `annotation` field
- `editingId`: UUID of the event currently being edited, or null if none
- `annotationText`: the text in the edit form
- `loading`: boolean indicating an in-flight request
- `error`: transient error message, or null

GIVEN the user switches between editing multiple events
WHEN they switch from event A to event B
THEN the form for event A closes
AND the form for event B opens (if clicked)
AND the state for event A is not lost if the user navigates back

ACCEPTANCE CRITERIA:
1. State is stored in the Vue component (CalendarWidget.vue) using `data()` properties
2. Form state is discarded when the user navigates away from the dashboard
3. Annotations are always re-fetched from the API (no stale client-side cache)

---

## REQ-CAL-007: No page reload on annotation save

**Type:** UX | **Priority:** Medium

GIVEN a user adds or edits an annotation on an event
WHEN they click "Save"
AND the request completes successfully
THEN the dashboard page does NOT reload
AND only the affected event card is updated
AND other events remain unchanged

ACCEPTANCE CRITERIA:
1. Use `axios.put()` for the API call (from `@nextcloud/axios`)
2. On success, update the event object in the component's `events` array
3. Vue's reactivity automatically re-renders the affected event card
4. No `location.reload()` or `window.location` navigation

---

## REQ-CAL-008: Offline handling and error recovery

**Type:** UX | **Priority:** Low

GIVEN a network error occurs while saving an annotation
THEN a user-facing error message is shown: "Failed to save annotation. Check your connection and try again."
AND the annotation form remains open
AND the user can retry by clicking "Save" again

GIVEN the annotation was partially saved (transient state)
WHEN the user retries
THEN the server's last-saved value is used (no merge conflicts, last-write-wins)

ACCEPTANCE CRITERIA:
1. Network errors (timeouts, 5xx) are caught in the `try/catch` block
2. A retry button or automatic retry is not required; user can click Save again
3. No polling or background retry loops
4. Error message is cleared when the user modifies the text field

---

## REQ-CAL-009: Translation support

**Type:** i18n | **Priority:** High

All user-visible strings in CalendarWidget.vue are translatable:

- "Add annotation" / "Annotatie toevoegen"
- "Edit annotation" / "Annotatie bewerken"
- "Save" / "Opslaan"
- "Cancel" / "Annuleren"
- "Add a note..." / "Voeg een notitie toe..."
- "Operation failed" / "Bewerking mislukt"
- "Permission denied" / "Toestemming geweigerd"

GIVEN the user's Nextcloud instance is configured for Dutch
WHEN the calendar widget is rendered
THEN all labels and placeholders are displayed in Dutch

ACCEPTANCE CRITERIA:
1. All strings use `t('mydash', 'key')` in Vue templates
2. Both `l10n/en.json` and `l10n/nl.json` contain every key with translations
3. Keys use English text (ADR-007: English is the primary language)
4. Keys use sentence case, not title case
5. No hardcoded strings in Vue templates or PHP responses

---

## REQ-CAL-010: Calendar app integration

**Type:** Technical | **Priority:** High

The implementation depends on Nextcloud Calendar app being installed and enabled.

GIVEN the Calendar app is not installed
WHEN the user views the MyDash dashboard
THEN the calendar widget is not displayed (or displays an empty state)
AND no error is logged

GIVEN the Calendar app is disabled
WHEN the user clicks "Save" on an annotation
THEN a 503 Service Unavailable error is returned
AND the user sees: "Calendar service temporarily unavailable"

ACCEPTANCE CRITERIA:
1. MyDash checks for Calendar app availability on startup via Nextcloud's app manager
2. The calendar widget is conditionally rendered only if Calendar is available
3. The backend API gracefully handles Calendar app unavailability
4. No unhandled exceptions propagate to the user
