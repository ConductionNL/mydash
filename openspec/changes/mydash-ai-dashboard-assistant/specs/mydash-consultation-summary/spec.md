---
capability: mydash-consultation-summary
delta: false
status: draft
---

# MyDash Consultation Response Summary Widget

## NEW Requirements

### Requirement: REQ-CONSULT-001 Active consultation tracking

The consultation summary widget MUST display a list of active consultations with the number of responses received for each. A consultation is `active` if its status is `open` or `in_progress` and deadline has not passed.

#### Scenario: Active consultations are displayed

- **GIVEN** a dashboard with 2 active consultations (CON-001, CON-002) and 1 closed (CON-003)
- **WHEN** the consultation summary widget loads
- **THEN** the widget MUST list the 2 active consultations:
  - CON-001: 45 responses
  - CON-002: 28 responses
- **AND** the closed consultation CON-003 MUST NOT appear

#### Scenario: Empty state when no active consultations

- **GIVEN** no active consultations exist
- **WHEN** the widget loads
- **THEN** the widget MUST display "No active consultations" with an empty state icon
- **AND** a link "Create consultation" MUST navigate to the create-consultation page

### Requirement: REQ-CONSULT-002 Response breakdown by sentiment

For each active consultation, the widget MUST display the response breakdown as a percentage:
- Agree (positive sentiment)
- Neutral (no clear stance)
- Disagree (negative sentiment)

The breakdown MUST be visual (e.g., stacked progress bar or pie chart) with labels and percentages.

#### Scenario: Response breakdown is shown

- **GIVEN** a consultation with 80 total responses: 50 agree, 20 neutral, 10 disagree
- **WHEN** the widget displays this consultation
- **THEN** the breakdown MUST show:
  - Agree: 62.5% (50/80)
  - Neutral: 25% (20/80)
  - Disagree: 12.5% (10/80)
- **AND** each segment MUST use a distinct color from the NL Design System (positive green, neutral gray, negative red)

#### Scenario: Percentages round to whole numbers

- **GIVEN** a consultation with 7 responses: 3 agree, 3 neutral, 1 disagree
- **WHEN** percentages are calculated
- **THEN** the widget MUST display rounded percentages: 43%, 43%, 14%
- **AND** the sum of displayed percentages MUST be 100%

### Requirement: REQ-CONSULT-003 AI-generated sentiment and theme summary

The widget MUST display an AI-generated narrative summary of the consultation responses. The summary MUST include:
- Overall sentiment (e.g., "Mostly positive" or "Mixed reactions")
- Key themes from the feedback (e.g., "Main concerns: cost and implementation timeline")
- Engagement level (e.g., "High engagement: 78 responses in 14 days")

#### Scenario: AI summary of consultation feedback

- **GIVEN** a consultation with 45 responses (mostly positive, concerns about timeline)
- **WHEN** the consultation summary widget loads
- **THEN** the widget MUST display an AI-generated summary such as:
  - "Strong positive response (62% agree)"
  - "Main concerns: implementation timeline and budget"
  - "High engagement: 45 responses received"
- **AND** the summary MUST be a readable paragraph, not a bulleted list

#### Scenario: Summary includes confidence indicator

- **GIVEN** an AI-generated summary of consultation responses
- **WHEN** the summary is rendered
- **THEN** a confidence indicator (optional) MAY be shown (e.g., "High confidence" or "?")
- **AND** the confidence MUST NOT block the summary display

#### Scenario: Summary degrades gracefully

- **GIVEN** the Claude API is unavailable or quota-exhausted
- **WHEN** the consultation summary widget loads
- **THEN** the widget MUST still display response counts and percentages
- **AND** the AI summary area MUST show "Summary unavailable"
- **AND** the widget MUST NOT fail

### Requirement: REQ-CONSULT-004 Permission filtering

The widget MUST respect OpenRegister permissions. Only consultations that the user has read access to (e.g., user is the owner, in the responsible group, or has `read` permission via OR's RBAC) MUST be visible.

#### Scenario: Consultations filtered by user role

- **GIVEN** a staff member responsible for consultations A and B, but consultation C is owned by another department
- **WHEN** they view the consultation summary widget
- **WHEN** OpenRegister's RBAC filters the `findObjects()` result
- **THEN** the widget MUST only show consultations A and B
- **AND** consultation C MUST NOT appear

### Requirement: REQ-CONSULT-005 Widget caching with event-driven invalidation

The widget data MUST be cached for 5 minutes to reduce API calls. The cache MUST be invalidated immediately when:
- A consultation's status changes
- A new response is added to a consultation
- Any consultation metadata is updated

#### Scenario: Data is cached

- **GIVEN** the consultation widget is loaded at time T
- **WHEN** the user navigates away and returns at T+3 minutes
- **THEN** the widget MUST show the cached data
- **AND** no new API call MUST be made

#### Scenario: Cache is invalidated on response update

- **GIVEN** a new response is submitted to an active consultation
- **WHEN** the response is saved in OpenRegister
- **THEN** the consultation widget's cache MUST be invalidated
- **AND** the next widget load MUST reflect the new response count

### Requirement: REQ-CONSULT-006 Response detail navigation

The widget MUST include a "View responses" link for each consultation that opens a detail view showing all individual responses grouped by sentiment.

#### Scenario: Navigate to consultation details

- **GIVEN** a consultation listed in the widget (CON-001)
- **WHEN** the user clicks "View responses" next to CON-001
- **THEN** they MUST navigate to `/apps/mydash/consultations/{id}/responses` (or equivalent)
- **AND** the detail view MUST display all responses sorted by sentiment (positive first, then neutral, then negative)

### Requirement: REQ-CONSULT-007 Deadline awareness

Consultations nearing their deadline (within 7 days) MUST be visually highlighted (e.g., warning badge or color change) to alert users of pending deadlines.

#### Scenario: Consultation deadline indicator

- **GIVEN** a consultation with deadline 2026-05-28 (6 days away)
- **WHEN** today is 2026-05-22
- **WHEN** the widget renders this consultation
- **THEN** a "Deadline soon" badge or warning icon MUST appear next to the consultation name
- **AND** the deadline date MUST be displayed (e.g., "Due 2026-05-28")

#### Scenario: No highlight for distant deadlines

- **GIVEN** a consultation with deadline 2026-06-15 (25 days away)
- **WHEN** the widget renders this consultation
- **THEN** NO warning badge MUST appear
- **AND** the deadline MAY still be displayed as metadata
