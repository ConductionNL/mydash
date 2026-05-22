# Design — Meeting and calendar actions from dashboard

## Overview

This change extends the calendar widget in MyDash to allow inline editing of event annotations. When a user views agenda items on the dashboard, they can add, edit, or remove brief annotations tied to each event. Annotations are persisted to the Nextcloud Calendar event's description field via the Calendar app's API.

## Architecture

### Data Storage

Annotations are stored as **event properties in Nextcloud Calendar**, not in MyDash's local database. This ensures:
- Consistency: users see the same annotations whether they view the event in Calendar or MyDash
- Durability: annotations survive dashboard widget updates
- RBAC: Calendar's existing event permissions apply

### Frontend Flow

1. **Render agenda items** — existing `CalendarWidget.vue` displays upcoming events
2. **Edit mode** — user clicks an annotation button/icon on an event card
3. **Inline form** — a small textarea appears alongside the event (or in a modal)
4. **Save** — frontend calls `PUT /api/calendar-events/{eventId}/annotation` with the text
5. **Reflect** — on success, the annotation is displayed inline; on error, a user-facing error message appears
6. **Persistence** — subsequent dashboard loads fetch events with their annotations via Calendar API

### Backend Flow

1. **Request arrives** — `PUT /api/calendar-events/{eventId}/annotation` with `annotation: "text"`
2. **Auth check** — verify user is authenticated and owns/can-edit the event (Calendar RBAC)
3. **Persist** — call `CalendarService::updateEventAnnotation()` which updates the event's description field in Calendar
4. **Return** — respond with the updated annotation text and HTTP 200

### Components

#### CalendarWidget.vue (Frontend)

```vue
<!-- Pseudo-code structure -->
<div class="calendar-widget">
  <div v-for="event in events" :key="event.id" class="event-card">
    <h4>{{ event.title }}</h4>
    <p v-if="event.annotation" class="annotation-text">{{ event.annotation }}</p>
    
    <!-- Edit button opens inline form -->
    <button @click="startEdit(event.id)">Edit annotation</button>
    
    <!-- Inline form when editing -->
    <form v-if="editingId === event.id" @submit.prevent="saveAnnotation(event.id)">
      <textarea v-model="annotationText" :placeholder="t('app', 'Add a note...')" />
      <button type="submit">{{ t('app', 'Save') }}</button>
      <button @click="cancelEdit">{{ t('app', 'Cancel') }}</button>
    </form>
  </div>
</div>
```

#### CalendarService.php (Backend)

```php
class CalendarService {
    /**
     * Update an event's annotation (description field).
     * 
     * @param string $eventId Event UUID
     * @param string $annotation New annotation text
     * @param IUser $user Authenticated user
     * @return array Updated event data
     * @throws OCSException If user lacks permission or event not found
     * @spec openspec/changes/mydash-meeting-calendar-actions/tasks.md#task-2
     */
    public function updateEventAnnotation(string $eventId, string $annotation, IUser $user): array {
        // Fetch the event from Calendar
        $event = $this->calendarService->getEvent($eventId, $user->getUID());
        
        // Check permission (Calendar RBAC)
        if (!$this->canEditEvent($event, $user)) {
            throw new OCSForbiddenException('Permission denied');
        }
        
        // Update the description field
        $event['description'] = $annotation;
        
        // Persist via Calendar API
        return $this->calendarService->updateEvent($eventId, $event);
    }
}
```

#### CalendarController.php (Backend)

```php
#[NoAdminRequired]
#[Route(method: 'PUT', url: '/api/calendar-events/{eventId}/annotation')]
public function updateAnnotation(string $eventId, ?string $annotation = null): JSONResponse {
    $user = $this->userSession->getUser();
    if ($user === null) {
        return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
    
    try {
        $updated = $this->calendarService->updateEventAnnotation($eventId, $annotation ?? '', $user);
        return new JSONResponse($updated);
    } catch (OCSException $e) {
        $this->logger->warning('Annotation update failed', ['exception' => $e]);
        return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
```

### Reuse Analysis

This change consumes:

- **Nextcloud Calendar API** — existing `/ocs/v2.php/apps/calendar/api/v1/events/{id}` endpoint for event fetching and updates
- **@conduction/nextcloud-vue** — `CnIndexPage` for the dashboard layout; no new shared components
- **AuthorizationService** (implicit) — Calendar's own RBAC is authoritative; we do not reimplement

No custom CRUD, audit trails, or storage beyond Calendar's native mechanisms.

### Scenarios

#### REQ-CAL-001: Add annotation to an event

```
GIVEN an agenda item is visible on the dashboard
WHEN the user clicks "Add annotation" on the item
AND enters text like "Quorum met"
AND clicks Save
THEN the annotation is persisted to the Calendar event
AND the text is displayed alongside the item on next dashboard refresh
```

#### REQ-CAL-002: Edit existing annotation

```
GIVEN an annotated event is visible on the dashboard
WHEN the user clicks the annotation text to edit it
AND modifies the text
AND clicks Save
THEN the updated text is persisted to Calendar
AND the annotation is immediately reflected on the widget (no page reload)
```

#### REQ-CAL-003: Remove annotation

```
GIVEN an annotated event is visible on the dashboard
WHEN the user clears the annotation field (empty string)
AND clicks Save
THEN the description field is emptied in Calendar
AND the annotation disappears from the widget
```

#### REQ-CAL-004: Permission denied on event edit

```
GIVEN a user views an event they do not own
WHEN they attempt to add an annotation
AND the Calendar app denies write permission
THEN a user-facing error message is shown
AND the annotation is not saved
```

## Translation Keys

**Dutch translations in `l10n/nl.json`:**

```json
{
  "Add annotation": "Annotatie toevoegen",
  "Edit annotation": "Annotatie bewerken",
  "Save": "Opslaan",
  "Cancel": "Annuleren",
  "Add a note...": "Voeg een notitie toe...",
  "Operation failed": "Bewerking mislukt",
  "Permission denied": "Toestemming geweigerd"
}
```

(English keys are identical in `l10n/en.json`.)

## Security Considerations

- **CSRF protection** — all `PUT` requests use `@nextcloud/axios` which auto-attaches the CSRF token
- **Authentication** — endpoint requires `#[NoAdminRequired]` + valid session; user identity derives from `IUserSession`
- **Authorization** — Calendar app's own RBAC is enforced server-side; annotations inherit event permissions
- **Input validation** — annotation text is passed as-is to Calendar; Calendar sanitizes on storage
- **Audit trail** — Calendar app's audit mechanism tracks the update (if enabled); MyDash does not duplicate

## NL Design System Compliance

- Button labels use **sentence case**: "Add annotation", "Edit annotation", not "ADD ANNOTATION"
- All user-visible strings are translatable via `t('app', 'key')`
- Colors and spacing use Nextcloud CSS variables (`var(--color-primary-element)`, etc.)
- Responsive on 320px–1920px viewports; critical actions (Save/Cancel) accessible at 768px

## No Seed Data Required

This change does not introduce new OpenRegister schemas or local data models. Annotations are stored by the Nextcloud Calendar app; MyDash does not own the storage layer. Therefore, no seed data section is required per ADR-001 exceptions.
