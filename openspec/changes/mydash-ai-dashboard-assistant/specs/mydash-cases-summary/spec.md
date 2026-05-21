---
capability: mydash-cases-summary
delta: false
status: draft
---

# MyDash Cases Summary Widget

## NEW Requirements

### Requirement: REQ-CASES-001 Open case count display

The cases summary widget MUST display the total count of open cases assigned to the authenticated user. The count MUST be fetched from OpenRegister and reflect only cases with status `open`, `in_progress`, or `pending` (not `closed`, `archived`, `rejected`).

#### Scenario: Case count shown for authenticated user

- **GIVEN** a case worker with 5 open cases and 2 closed cases in OpenRegister
- **WHEN** they view the MyDash dashboard
- **THEN** the cases summary widget MUST display the count `5` (open only)
- **AND** the count MUST NOT include closed cases

#### Scenario: Empty state when no open cases

- **GIVEN** a case worker with no open cases in OpenRegister
- **WHEN** they view the MyDash dashboard
- **THEN** the widget MUST display "No open cases" with an empty state icon
- **AND** a link to "Browse all cases" MUST navigate to the cases list

### Requirement: REQ-CASES-002 Case status breakdown

The widget MUST display a breakdown of open cases by status (count of `open` vs. `in_progress` vs. `pending`). The breakdown MUST be visual (e.g., bar chart or stacked bar) and include the status name and count for each.

#### Scenario: Status breakdown is displayed

- **GIVEN** a case worker with 2 `open`, 2 `in_progress`, and 1 `pending` case
- **WHEN** they view the cases summary widget
- **THEN** the widget MUST show a visual breakdown with three segments:
  - `Open: 2`
  - `In Progress: 2`
  - `Pending: 1`
- **AND** each segment MUST use a distinct color from the NL Design System

#### Scenario: Last updated date is visible

- **GIVEN** the status breakdown is displayed
- **WHEN** the widget is rendered
- **THEN** a "Last updated" timestamp MUST be shown (e.g., "5 minutes ago" or "2026-05-21 14:32")
- **AND** the timestamp MUST reflect the time the data was last fetched

### Requirement: REQ-CASES-003 AI-generated summary with priority actions

The widget MUST display an AI-generated narrative summary of the open cases. The summary MUST include:
- Total case count and key status metrics
- Priority actions (e.g., "2 cases are overdue" or "1 case needs decision")
- Suggested next steps (e.g., "Review permit application for overdue case 2024-001")

#### Scenario: AI summary is generated

- **GIVEN** a case worker with 5 open cases (1 overdue, 2 due this week, 2 due later)
- **WHEN** they view the cases summary widget
- **THEN** the widget MUST display an AI-generated summary including:
  - "You have 5 open cases"
  - "1 case is overdue (action required)"
  - "2 cases are due this week"
- **AND** the summary MUST be a single readable paragraph, not a bulleted list

#### Scenario: Summary degrades gracefully if AI is unavailable

- **GIVEN** the Claude API is unavailable or quota-exhausted
- **WHEN** the cases summary widget loads
- **THEN** the widget MUST still display the case count and status breakdown
- **AND** the AI summary area MUST show "Summary unavailable" with an icon
- **AND** the widget MUST NOT fail or show an error

### Requirement: REQ-CASES-004 Permission filtering

The widget MUST respect OpenRegister's per-user permissions. Only cases that the user has read access to (via `createdBy`, `assignee`, or `owner` field matching the user's group) MUST be visible in the widget.

#### Scenario: Cases are filtered by user permissions

- **GIVEN** a case worker assigned to cases A and B, but not C (assigned to another worker)
- **WHEN** they view the cases summary widget
- **WHEN** OpenRegister's RBAC filters the `findObjects()` result
- **THEN** the widget MUST only show counts for cases A and B
- **AND** case C MUST NOT be included in the count or summary

### Requirement: REQ-CASES-005 Widget caching with event-driven invalidation

The widget data MUST be cached for 5 minutes to avoid excessive API calls. The cache MUST be invalidated immediately when an OpenRegister object-change event is emitted for a case.

#### Scenario: Summary is cached

- **GIVEN** a case worker views the widget at time T
- **WHEN** they navigate away and return at time T+2 minutes
- **THEN** the widget MUST display the cached result (no new API call)
- **AND** the timestamp MUST still show "2 minutes ago" (from the original fetch)

#### Scenario: Cache is invalidated on case update

- **GIVEN** a case is updated in OpenRegister (e.g., status changes from `open` to `closed`)
- **WHEN** the change event is emitted
- **THEN** the widget's cache entry MUST be invalidated immediately
- **AND** the next widget load MUST fetch fresh data and reflect the status change

### Requirement: REQ-CASES-006 Click-through navigation

The widget MUST include a "View all cases" link that navigates to the cases list page. The list page MUST preserve filters (if any) applied to the widget.

#### Scenario: Navigation to cases list

- **GIVEN** a case worker views the cases summary widget on the dashboard
- **WHEN** they click "View all cases"
- **THEN** they MUST be navigated to `/apps/mydash/cases` (or equivalent)
- **AND** the browser history MUST allow "back" navigation to the dashboard
