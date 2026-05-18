# Spec: mydash-meeting-calendar-actions

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, widget-add-edit-modal, calendar-widget (peer pattern), permissions, conditional-visibility; cross-app runtime sources: Nextcloud Calendar (DAV), decidesk (meetings + agendas via GraphQL, read-only), openregister (object-interactions for meeting attachments)

## Purpose

Surface meeting and agenda actions on a mydash dashboard via the
widget `mydash_meeting_actions`. The widget composes data from two
sources, presented as one timeline:

- **Nextcloud Calendar events** (via DAV) — the standard
  cross-app calendar surface.
- **decidesk meetings + agenda items** (via decidesk GraphQL) —
  Conduction's governance app with motion / vote / annotation
  semantics absent from generic calendar events.

It is a **distinct widget** from the existing `calendar-widget`
(which surfaces only Nextcloud DAV calendars). This widget joins
the two sources and adds **agenda-item annotation** (the Specter
source acceptance).

mydash MUST NOT add an install-time dependency on decidesk —
absent decidesk, the widget falls back to pure DAV calendar
rendering per `feedback_mydash-no-or-dependency.md`.

Sourced from Specter draft `meeting-calendar-actions` (1 feature:
annotate agenda items on dashboard).

## ADDED Requirements

### REQ-MCA-001: The system SHALL register a `mydash_meeting_actions` widget type distinct from `calendar-widget`

The widget MUST appear in `src/constants/widgetRegistry.js` and the
unified Add Widget modal. The registry entry MUST carry the standard
fields and a soft `requires.dav: ['calendar']` plus
`requires.graphql: ['decidesk.meetings', 'decidesk.agendaItems']`
declaration. The widget MUST coexist with `calendar` — the two
types fulfil different use-cases (broad calendar view vs governance
+ annotation timeline).

#### Scenario: Both calendar widget types registered

- **GIVEN** the registry completeness test
- **WHEN** it runs
- **THEN** both `calendar` and `meeting-actions` MUST appear in
  EXPECTED_TYPES
- **AND** their `displayName`s MUST disambiguate
  (`'Calendar'` vs `'Meetings & actions'`)

#### Scenario: Widget appears in the Add modal

- **GIVEN** the Add Widget modal type picker
- **WHEN** rendered
- **THEN** `Meetings & actions` MUST be selectable

### REQ-MCA-002: The widget content shape SHALL describe the source bindings and timeline window

The placement persists `{type: 'meeting-actions', content: {...}}` with:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `calendarSources` | string[] | No | `[]` | NC Calendar principal URIs (empty = viewer's personal calendars only) |
| `decideskMeetings` | boolean | No | `true` | Include decidesk meetings (gracefully falls back when decidesk absent) |
| `daysAhead` | integer | No | `14` | Time horizon |
| `includeAgendaItems` | boolean | No | `true` | Surface decidesk agenda items inline under their meeting |
| `showAnnotations` | boolean | No | `true` | Surface agenda-item annotations (REQ-MCA-005) |
| `groupBy` | enum | No | `'day'` | `'day' \| 'meeting' \| 'none'` |

#### Scenario: Minimal placement validates

- **GIVEN** the content shape
- **WHEN** `{type: 'meeting-actions', content: {daysAhead: 7}}` is saved
- **THEN** validation MUST pass
- **AND** defaults MUST apply

#### Scenario: Negative `daysAhead` rejected

- **GIVEN** `content.daysAhead = -1`
- **WHEN** `validate()` runs
- **THEN** validation MUST return an error

### REQ-MCA-003: Nextcloud Calendar events SHALL be read via DAV — never via a mydash-local event table

The renderer MUST issue CalDAV `REPORT` queries against the viewer's
calendars to fetch events. mydash MUST NOT define an event table,
MUST NOT mirror calendar data, and MUST NOT issue writes via this
widget (annotations write to decidesk per REQ-MCA-005, never to
DAV). The DAV query MUST respect `calendarSources` when set, else
default to the viewer's enabled calendars per Nextcloud's existing
convention.

#### Scenario: DAV query issued on mount

- **GIVEN** the widget mounts with default `calendarSources`
- **WHEN** the renderer queries
- **THEN** a CalDAV `REPORT` MUST be issued against the viewer's
  default calendar collection
- **AND** the time range MUST equal `now .. now + daysAhead`

#### Scenario: No mydash event table

- **GIVEN** the mydash migrations after this widget ships
- **WHEN** inspected
- **THEN** no migration adding an event / calendar / meeting
  table MUST exist

### REQ-MCA-004: decidesk meetings + agenda items SHALL be fetched via decidesk GraphQL — empty-state when absent

When `decideskMeetings === true`, the widget MUST issue a GraphQL
query against decidesk's `/graphql` resolving
`meetings(window: {...}) { id title startTime agendaItems { id text } }`.
If decidesk is absent (404 / connection error), the widget MUST
gracefully render only the DAV calendar events and surface an
inline info chip:
`t('mydash', 'Decidesk meetings unavailable — install decidesk for governance features')`.

#### Scenario: decidesk present surfaces meetings inline

- **GIVEN** decidesk is installed AND has 2 upcoming meetings in
  the window
- **WHEN** the widget renders
- **THEN** the timeline MUST surface both meetings with their
  agenda items (when `includeAgendaItems === true`)

#### Scenario: decidesk absent falls back gracefully

- **GIVEN** decidesk is not installed
- **WHEN** the widget renders
- **THEN** the DAV calendar events MUST still render
- **AND** an info chip MUST surface naming the missing app
- **AND** the widget MUST NOT throw

### REQ-MCA-005: Agenda-item annotations SHALL persist on the agenda-item object in decidesk — never in a mydash table (Specter acceptance)

When the viewer adds, edits, or deletes an annotation on an agenda
item, the widget MUST issue a GraphQL mutation against decidesk's
agenda-item mutation endpoint. mydash MUST NOT define an annotations
table, MUST NOT store annotation text in placement content, and
MUST NOT mirror annotation state.

#### Scenario: Annotation save (Specter source)

- **GIVEN** an agenda item is visible on the dashboard
- **WHEN** the viewer adds an annotation
- **THEN** the annotation MUST persist via a GraphQL mutation to
  decidesk's agenda-item mutation
- **AND** the annotation MUST display alongside the agenda item
  on next render

#### Scenario: Annotation edit reflects immediately (Specter source)

- **GIVEN** an annotated agenda item on the dashboard
- **WHEN** the viewer edits the annotation
- **THEN** the updated text MUST reflect immediately without a
  page reload (via the same mutation + local component state
  refresh)

#### Scenario: Annotation removal (Specter source)

- **GIVEN** an annotated agenda item
- **WHEN** the viewer removes the annotation
- **THEN** the annotation MUST be deleted via a GraphQL mutation
  to decidesk
- **AND** the agenda item MUST return to its unannotated state

#### Scenario: decidesk absent disables annotation control

- **GIVEN** decidesk is not installed AND `showAnnotations === true`
- **WHEN** the widget renders DAV events
- **THEN** the annotation control MUST NOT surface on DAV events
  (DAV events have no agenda-item identity to annotate)

### REQ-MCA-006: Joining DAV events with decidesk meetings SHALL happen client-side via shared `referenceId` — server-side join is decidesk's responsibility

decidesk meetings that are also published as DAV events MUST be
deduplicated client-side by matching the DAV event's
`X-DECIDESK-MEETING-ID` property (or successor) against the
decidesk meeting's `id`. mydash MUST NOT POST a "register this
calendar event with decidesk" mutation — that linkage is decidesk's
own responsibility per its CalDAV integration spec.

#### Scenario: Duplicate event dedup

- **GIVEN** a decidesk meeting `m-1` exists both as a decidesk
  meeting and as a DAV event with `X-DECIDESK-MEETING-ID: m-1`
- **WHEN** the timeline renders
- **THEN** the event MUST appear exactly once
- **AND** the rendered row MUST surface both DAV metadata
  (location / attendees) and decidesk metadata (agenda items)

#### Scenario: No mydash↔decidesk link mutation

- **GIVEN** the widget source files
- **WHEN** scanned for POST/PUT to `/apps/decidesk/.*link` or
  `/apps/decidesk/.*calendar`
- **THEN** zero matches MUST exist

## Non-Functional Requirements

- **Performance:** DAV `REPORT` + decidesk GraphQL MUST run in
  parallel (Promise.all), not serially. The widget MUST surface
  the timeline as soon as the first source returns; the second
  source merges in when it resolves.
- **Accessibility:** Agenda items MUST be keyboard navigable
  (Tab/Shift+Tab through the timeline). Annotation save MUST
  surface a confirmation via `aria-live="polite"`.
- **Localisation:** English + Dutch. Date / time formatting MUST
  respect the viewer's Nextcloud locale.
- **Time zone:** All events / meetings MUST render in the viewer's
  configured Nextcloud time zone; UTC times in payloads MUST be
  converted client-side.

## Reuses (mydash)

- `widgets`, `widget-add-edit-modal`, `widget-collision-placement`
- `calendar-widget` — peer pattern for DAV consumption + caching
  conventions
- `permissions`, `conditional-visibility`

## Standards & References

- ADR-022 — OR abstractions: deep-link registry (for meeting
  detail navigation), object-interactions (meeting attachments).
- ADR-024 — manifest widget entry + soft `requires`.
- `feedback_mydash-no-or-dependency.md`.
- CalDAV (RFC 4791) + iCalendar (RFC 5545) — DAV / event format.
- decidesk's CalDAV integration spec (sibling-app-owned) — defines
  the `X-DECIDESK-MEETING-ID` property used for dedup.
- WCAG 2.1 AA — keyboard navigation + `aria-live` announcements.
