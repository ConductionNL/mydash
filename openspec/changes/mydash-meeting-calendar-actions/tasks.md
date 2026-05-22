# Tasks — Meeting and calendar actions from dashboard

## 1. Backend service layer

- [ ] 1.1 Create `lib/Service/CalendarService.php` with method `updateEventAnnotation(string $eventId, string $annotation, IUser $user): array` per REQ-CAL-005
- [ ] 1.2 Implement Calendar RBAC check in `CalendarService::updateEventAnnotation()` — verify user can edit the event via Calendar's permission system (REQ-CAL-004)
- [ ] 1.3 Call the Nextcloud Calendar API to fetch the event: `GET /ocs/v2.php/apps/calendar/api/v1/events/{eventId}`
- [ ] 1.4 Update the event's `description` field with the annotation text
- [ ] 1.5 Call the Nextcloud Calendar API to persist: `PUT /ocs/v2.php/apps/calendar/api/v1/events/{eventId}`
- [ ] 1.6 Handle Calendar API errors (404, 403, 503) and throw appropriate exceptions with user-facing messages
- [ ] 1.7 Return the updated event object including `id`, `title`, `annotation`, and `updated` timestamp (REQ-CAL-005)

## 2. Backend API endpoint

- [ ] 2.1 Create `lib/Controller/CalendarController.php` with route `PUT /api/calendar-events/{eventId}/annotation`
- [ ] 2.2 Add `#[Route(method: 'PUT', url: '/api/calendar-events/{eventId}/annotation')]` and `#[NoAdminRequired]` annotations (REQ-CAL-004)
- [ ] 2.3 Implement `updateAnnotation(string $eventId, ?string $annotation = null): JSONResponse` method
- [ ] 2.4 Fetch authenticated user via `IUserSession::getUser()` and return 401 if null (REQ-CAL-004)
- [ ] 2.5 Call `CalendarService::updateEventAnnotation()` and handle exceptions with appropriate HTTP status codes (REQ-CAL-005)
- [ ] 2.6 Return 200 with updated event data on success; 403 on permission denied; 404 on not found; 500 on error
- [ ] 2.7 Ensure no stack traces or internal error messages leak to the client (ADR-015 common pattern)
- [ ] 2.8 Add `@spec openspec/changes/mydash-meeting-calendar-actions/tasks.md#task-N` PHPDoc tags to both class and method (ADR-003)

## 3. Register CalendarController route

- [ ] 3.1 Add the route to `appinfo/routes.php` per ADR-016 (single registration file, no runtime routes)
- [ ] 3.2 Verify specific routes are defined BEFORE wildcard `{slug}` routes to ensure correct matching

## 4. Frontend component — CalendarWidget.vue

- [ ] 4.1 Create or modify `src/components/CalendarWidget.vue` to render calendar events with annotation support
- [ ] 4.2 Add data properties for `events`, `editingId`, `annotationText`, `loading`, and `error` (REQ-CAL-006)
- [ ] 4.3 Render event list with title, date/time, and existing annotation (if present)
- [ ] 4.4 Add "Add annotation" button for each event, or allow clicking the annotation text to edit (REQ-CAL-001, REQ-CAL-002)
- [ ] 4.5 Show inline textarea with Save/Cancel buttons when `editingId === event.id` (REQ-CAL-001)
- [ ] 4.6 Implement `saveAnnotation(eventId)` method: trim text, call API, update `events` array on success (REQ-CAL-001, REQ-CAL-007)
- [ ] 4.7 Use `axios.put()` from `@nextcloud/axios` (NOT raw `fetch()`) to ensure CSRF token is attached (ADR-004, ADR-015)
- [ ] 4.8 Wrap the `axios.put()` call in `try/catch` with user-facing error feedback (ADR-004)
- [ ] 4.9 Disable the Save button while `loading === true` to prevent duplicate submissions (REQ-CAL-001)
- [ ] 4.10 Show transient error messages and allow retry (REQ-CAL-008)
- [ ] 4.11 Clear error message when user modifies the annotation text (REQ-CAL-008)
- [ ] 4.12 Do NOT reload the page; rely on Vue reactivity to update the event card (REQ-CAL-007)

## 5. Frontend translations

- [ ] 5.1 Add English translation keys in `l10n/en.json`: "Add annotation", "Edit annotation", "Save", "Cancel", "Add a note...", "Operation failed", "Permission denied"
- [ ] 5.2 Add Dutch translation keys in `l10n/nl.json` with exact same structure as `en.json`
- [ ] 5.3 Use `t('mydash', 'key')` in the Vue component for all user-visible strings (ADR-007)
- [ ] 5.4 Verify keys use sentence case (first word capitalized, rest lowercase) per ADR-007
- [ ] 5.5 Use proper Dutch translations (not machine-generated; review with Dutch speaker if available)

## 6. PHPUnit tests — backend

- [ ] 6.1 Create `tests/Unit/Service/CalendarServiceTest.php`
- [ ] 6.2 Add test `testUpdateEventAnnotationSuccess()` — verify annotation is saved and event is returned
- [ ] 6.3 Add test `testUpdateEventAnnotationPermissionDenied()` — verify 403 is thrown when user lacks permission
- [ ] 6.4 Add test `testUpdateEventAnnotationNotFound()` — verify 404 is thrown when event does not exist
- [ ] 6.5 Add test `testUpdateEventAnnotationCalendarUnavailable()` — verify 503 is handled gracefully
- [ ] 6.6 Create `tests/Unit/Controller/CalendarControllerTest.php`
- [ ] 6.7 Add test `testUpdateAnnotationUnauthenticated()` — verify 401 is returned for unauthenticated requests
- [ ] 6.8 Add test `testUpdateAnnotationSuccess()` — verify endpoint returns 200 with correct response shape (REQ-CAL-005)
- [ ] 6.9 Add test `testUpdateAnnotationPermissionDenied()` — verify endpoint returns 403 with user-facing message
- [ ] 6.10 All tests MUST pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)

## 7. Frontend tests (Vue/JavaScript)

- [ ] 7.1 Create `tests/CalendarWidget.spec.js` (if using Jest or similar test framework)
- [ ] 7.2 Add test for rendering event list with annotations
- [ ] 7.3 Add test for opening edit form when user clicks "Add annotation"
- [ ] 7.4 Add test for closing form when user clicks "Cancel"
- [ ] 7.5 Add test for API call on "Save" (mock `axios.put`)
- [ ] 7.6 Add test for error message display on API failure
- [ ] 7.7 Add test for disabling Save button while `loading === true`
- [ ] 7.8 All tests MUST pass `npm run lint` and any JavaScript test command defined in `package.json`

## 8. Deduplication check

- [ ] 8.1 Search `openspec/specs/` and `openregister/lib/Service/` for overlapping annotation/event-note functionality
- [ ] 8.2 Verify no duplicate "event annotation" or "event note" capability exists in OpenRegister or other apps
- [ ] 8.3 Document findings: if "no overlap found", record that in the PR description
- [ ] 8.4 If overlap is found, reference it in the proposal.md and explain why new code is needed (ADR-001)

## 9. Reuse analysis verification

- [ ] 9.1 Confirm Nextcloud Calendar API is the only external service called for annotation persistence
- [ ] 9.2 Verify @conduction/nextcloud-vue components are used (not custom wrappers) — e.g., use CnIndexPage if displaying calendars
- [ ] 9.3 Confirm AuthorizationService is NOT re-implemented; Calendar's RBAC is trusted
- [ ] 9.4 Document in design.md that this change consumes Calendar API + @conduction/nextcloud-vue (already done)

## 10. SPDX headers and licensing

- [ ] 10.1 Add SPDX header to every new PHP file: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`
- [ ] 10.2 Add SPDX header to every new Vue file: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [ ] 10.3 Add SPDX header to every new JavaScript file: `// SPDX-License-Identifier: EUPL-1.2` as first line
- [ ] 10.4 Add PHPDoc tags to every PHP file: `@author`, `@copyright`, `@license`, `@link`, `@spec` (ADR-014)
- [ ] 10.5 Verify `hydra-gate-spdx` passes locally before opening PR

## 11. Quality gates

- [ ] 11.1 Run `composer check:strict` locally and fix any issues in touched files
- [ ] 11.2 Run `npm run lint` locally and fix any issues in touched Vue/JS files
- [ ] 11.3 Run all 10 `hydra-gates` locally (verify SPDX, route auth, semantic auth, etc.)
- [ ] 11.4 Verify no hardcoded English or Dutch strings in templates (all strings translatable)
- [ ] 11.5 Verify all `await` axios calls are wrapped in `try/catch` (ADR-004)
- [ ] 11.6 Verify no raw `fetch()` calls (must use `@nextcloud/axios` for CSRF)

## 12. Integration test — API endpoint

- [ ] 12.1 Create a Postman/Newman collection `tests/integration/calendar-annotations.json` (or equivalent test format)
- [ ] 12.2 Add request `PUT /api/calendar-events/{eventId}/annotation` with valid event ID and annotation text
- [ ] 12.3 Add request with missing auth (verify 401)
- [ ] 12.4 Add request with permission-denied scenario (verify 403)
- [ ] 12.5 Add request with missing event (verify 404)
- [ ] 12.6 Verify happy-path response shape matches REQ-CAL-005 specification
- [ ] 12.7 Collection uses env variable placeholders for credentials (never hardcoded defaults)

## 13. Smoke test before PR

- [ ] 13.1 Start the MyDash app locally (or in Docker test env)
- [ ] 13.2 Navigate to the dashboard and verify the calendar widget is visible
- [ ] 13.3 Click "Add annotation" on an event
- [ ] 13.4 Type text and click "Save"
- [ ] 13.5 Verify the annotation is displayed below the event title (no page reload)
- [ ] 13.6 Click the annotation to edit, modify text, and click "Save"
- [ ] 13.7 Verify the updated text is displayed immediately
- [ ] 13.8 Clear the text and click "Save"; verify the annotation disappears
- [ ] 13.9 Test with a second event to verify isolation
- [ ] 13.10 Verify error messages appear on network failure (throttle network to simulate)

## 14. Documentation

- [ ] 14.1 Add user-facing documentation in `docs/features/annotations.md` with screenshots (if docs/ directory exists)
- [ ] 14.2 Document the API endpoint in `docs/api/calendar.md` (or similar)
- [ ] 14.3 Include usage examples: "How to annotate an event from the dashboard"
- [ ] 14.4 Document any Calendar app version requirements or compatibility notes
