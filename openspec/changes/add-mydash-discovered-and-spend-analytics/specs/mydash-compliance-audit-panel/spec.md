# Spec: mydash-compliance-audit-panel

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, widget-add-edit-modal, dashboards, permissions, conditional-visibility, role-based-content; cross-app runtime sources: openregister (audit-trail-immutable + archival-destruction-workflow + GraphQL, read-only), shillinq (retention rules via GraphQL), docudesk (compliance evidence via GraphQL)

## Purpose

Surface the organisation's compliance posture on a mydash dashboard
through one widget (`mydash_compliance_audit`). The widget reads
audit-trail, retention, and compliance-evidence data at runtime via
GraphQL — consuming OR's `audit-trail-immutable` and
`archival-destruction-workflow` abstractions (ADR-022 table rows
"Audit trail" + "Archival + destruction workflow") plus shillinq's
Archiefwet retention rules and docudesk's compliance documents.

mydash MUST NOT add an install-time dependency on any of these
sibling apps — per `feedback_mydash-no-or-dependency.md`. Every
data flow is a runtime read, every absent sibling renders an
empty-state.

Sourced from Specter draft `compliance-audit-panel` (2 features:
deadline alert, portfolio filter).

## ADDED Requirements

### REQ-CAP-001: The system SHALL register a `mydash_compliance_audit` widget type

The widget MUST appear in `src/constants/widgetRegistry.js` and the
unified Add Widget modal (REQ-WDG-010 / REQ-WDG-014). The registry
entry MUST carry the standard fields plus a soft
`requires.graphql: ['openregister.auditTrail', 'openregister.archival', 'shillinq.retentionRules']`
declaration.

#### Scenario: Widget registered and discoverable

- **GIVEN** the registry completeness test
- **WHEN** it runs
- **THEN** `compliance-audit` MUST appear in EXPECTED_TYPES
- **AND** the entry MUST surface in `listWidgetTypes()` with all
  required fields non-null

#### Scenario: Widget appears in the Add modal

- **GIVEN** the Add Widget modal is open
- **WHEN** the user opens the type picker
- **THEN** `Compliance audit` MUST be selectable

### REQ-CAP-002: The widget content shape SHALL declare posture, filter, and alert configuration

The placement persists `{type: 'compliance-audit', content: {...}}` with:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `frameworks` | string[] | Yes | `['BIO', 'ISO-27001', 'AVG']` | Frameworks tracked (`BIO`, `ISO-27001`, `AVG`, `NEN-7510`, custom slugs allowed) |
| `portfolioFilter` | string[] | No | `[]` | Filter to specific portfolios (Specter source: "filter by portfolio") |
| `deadlineWindowDays` | integer | No | `30` | Look-ahead horizon for "imminent" alerts |
| `showAuditTrailCard` | boolean | No | `true` | Surface the audit-trail summary card |
| `showRetentionCard` | boolean | No | `true` | Surface the retention disposition counts |
| `showEvidenceCard` | boolean | No | `true` | Surface docudesk-attached compliance evidence |

#### Scenario: Default placement validates

- **GIVEN** the content shape contract
- **WHEN** `{type: 'compliance-audit', content: {frameworks: ['BIO']}}` is saved
- **THEN** validation MUST pass
- **AND** the defaults above MUST apply to unset fields

#### Scenario: Unknown framework slug accepted (forward-compat)

- **GIVEN** `content.frameworks = ['BIO', 'CUSTOM-FRAMEWORK-X']`
- **WHEN** validation runs
- **THEN** validation MUST pass — the widget renders an empty card
  for unknown frameworks rather than blocking placement save (the
  framework definition lives on the compliance sibling app, not
  in mydash)

### REQ-CAP-003: The audit-trail card SHALL be consumed from OR's `audit-trail-immutable` abstraction — never from a local audit table

The audit-trail summary card MUST issue a GraphQL query against OR
exposing `auditEvents { type count latestTimestamp }` for the
viewer's accessible objects. mydash MUST NOT define a local audit
table, MUST NOT mirror OR audit events, and MUST NOT post writes to
the audit trail (ADR-022 anti-pattern: "Home-grown audit trails").

#### Scenario: Audit summary card renders from OR GraphQL

- **GIVEN** OR is installed AND the viewer has 4 recent audit
  events across their objects
- **WHEN** the widget renders
- **THEN** the audit card MUST display the event types + counts +
  latest timestamp
- **AND** the GraphQL query MUST target OR's `/graphql` (no local
  table read, no axios to OR)

#### Scenario: OR absent renders empty-state inline

- **GIVEN** OR is not reachable (`/graphql` returns 404)
- **WHEN** the widget renders
- **THEN** the audit card MUST display
  `t('mydash', 'Audit trail unavailable — OpenRegister not reachable')`
- **AND** other cards on the widget MUST continue to render

#### Scenario: No write to audit trail

- **GIVEN** the compliance-audit widget source files
- **WHEN** scanned for POST / PUT against `/apps/openregister/.*audit`
- **THEN** zero matches MUST exist — the widget is read-only

### REQ-CAP-004: The retention card SHALL consume OR's `archival-destruction-workflow` + shillinq retention rules

Retention disposition counts MUST be queried via GraphQL from OR
(`archival { dispositionState count }`) and joined with shillinq's
Archiefwet retention rules (`retentionRules { selectielijstRef
durationYears }`) at the GraphQL composition layer — handled by OR's
relations abstraction (ADR-022). mydash MUST NOT join the two
locally and MUST NOT cache retention metadata.

#### Scenario: Retention card renders with disposition states

- **GIVEN** OR archival + shillinq are installed AND the viewer's
  scope includes 12 objects pending destruction
- **WHEN** the widget renders
- **THEN** the retention card MUST surface the disposition state
  buckets (`pending-review`, `destroy-eligible`, `archived`,
  `destroyed`) with counts
- **AND** the Selectielijst reference for the largest bucket MUST
  surface as a tooltip

#### Scenario: shillinq absent partial-renders the card

- **GIVEN** OR present but shillinq absent
- **WHEN** the card renders
- **THEN** disposition state counts MUST still render
- **AND** the Selectielijst tooltip MUST display
  `t('mydash', 'Retention rules unavailable — shillinq not installed')`
- **AND** no error MUST throw

### REQ-CAP-005: The widget SHALL surface deadline alerts with cross-session persistence (Specter acceptance)

When a compliance deadline falls within `content.deadlineWindowDays`,
the widget MUST render a visible alert naming the deadline and due
date. The alert MUST persist across sessions until dismissed or
the deadline passes. Dismissal state MUST persist via the existing
mydash placement state — NOT via a new mydash backend table; the
widget reuses the placement's existing
`acknowledgements: {deadlineId: dismissedAt}` JSON sub-field within
`content` (forward-compat extension of REQ-CAP-002).

#### Scenario: Imminent deadline surfaces alert (Specter source)

- **GIVEN** a compliance deadline within the configured threshold
  exists for the viewer's scope
- **WHEN** the viewer opens the dashboard
- **THEN** an alert MUST display naming the deadline and due date

#### Scenario: Alert persists until acknowledged (Specter source)

- **GIVEN** an unacknowledged imminent deadline alert
- **WHEN** the viewer signs out and back in
- **THEN** the alert MUST still be visible
- **AND** dismissal MUST persist by writing to the placement's
  `content.acknowledgements` map via the existing widget PUT
  endpoint (no new endpoint added)

#### Scenario: Passed deadline updates to overdue (Specter source)

- **GIVEN** the deadline has passed without acknowledgement
- **WHEN** the viewer next loads the dashboard
- **THEN** the alert MUST update to reflect overdue status (red
  styling + revised copy)

### REQ-CAP-006: The portfolio filter SHALL scope the widget's queries server-side, not client-side (Specter acceptance)

`content.portfolioFilter` MUST be passed as a GraphQL variable on
every query the widget issues. Filtering MUST happen server-side
(in the sibling app's GraphQL resolver). Client-side post-filter
MUST be permitted only as a UX enhancement (sorting, paging);
client-side filter MUST NOT replace the server-side variable.

#### Scenario: Filter included in GraphQL variables

- **GIVEN** `content.portfolioFilter = ['portfolio-a']`
- **WHEN** the widget issues its queries
- **THEN** the variables payload MUST include
  `{portfolioFilter: ['portfolio-a']}`
- **AND** the GraphQL document MUST reference the variable in its
  argument list

#### Scenario: Cleared filter widens result set (Specter source)

- **GIVEN** a portfolio filter is active
- **WHEN** the viewer clears it
- **THEN** the widget MUST re-issue the queries with the variable
  omitted
- **AND** the dashboard cards MUST update to reflect every portfolio
  the viewer can read

#### Scenario: Summary metrics reflect filter (Specter source)

- **GIVEN** multiple portfolios exist AND the filter is applied
- **WHEN** the cards re-render
- **THEN** the audit-trail count, retention bucket counts, and
  evidence count MUST reflect only the selected portfolio's data

### REQ-CAP-007: The evidence card SHALL surface docudesk-attached compliance documents via OR's `object-interactions`

The evidence card MUST list compliance documents attached to OR
objects in the viewer's scope using OR's `object-interactions` files
integration (ADR-022 + ADR-019). The widget MUST NOT call docudesk
endpoints directly — uploads stay with docudesk.

#### Scenario: Evidence files surface

- **GIVEN** the viewer's scope includes 5 OR objects with attached
  compliance documents in docudesk
- **WHEN** the widget renders
- **THEN** the evidence card MUST list each document with title +
  attachment date + framework tag

#### Scenario: Click deep-links to docudesk

- **GIVEN** an evidence row
- **WHEN** the viewer clicks it
- **THEN** the browser MUST navigate to the docudesk viewer URL
  (resolved via OR's deep-link registry)

#### Scenario: Read-only evidence

- **GIVEN** the compliance-audit widget source files
- **WHEN** scanned for HTTP `POST` / `PUT` / `DELETE` to
  `/apps/docudesk/`
- **THEN** zero matches MUST exist

## Non-Functional Requirements

- **Performance:** All cards SHOULD render their initial payload
  within 2 s on a warm cache. Each card MUST query independently —
  one card's slow response MUST NOT block siblings.
- **Accessibility:** Alert banners (REQ-CAP-005) MUST carry
  `role="alert"` and adequate colour contrast for both light and
  high-contrast themes (WCAG 2.1 AA).
- **Localisation:** Framework names (`BIO`, `ISO-27001`, `AVG`,
  `NEN-7510`) stay as proper nouns; surrounding copy MUST be
  translated to Dutch + English.
- **Privacy:** Audit-trail payloads carry actor identifiers. The
  widget MUST defer to OR's audit-RBAC — a viewer who cannot see
  an actor's identity in OR MUST NOT see it in the widget.

## Reuses (mydash)

- `widgets`, `widget-add-edit-modal`
- `dashboards`, `permissions`, `conditional-visibility`
- `role-based-content` for portfolio scoping

## Standards & References

- ADR-022 — OR abstractions consumed: audit-trail-immutable (table
  row "Audit trail (immutable)"), archival + destruction workflow,
  integration registry (`object-interactions`), deep-link registry.
- ADR-024 — manifest widget entry + soft `requires`.
- `feedback_mydash-no-or-dependency.md`.
- Archiefwet 1995 + Selectielijst Gemeenten 2020 — retention
  context (sibling-app-owned definitions).
- BIO (Baseline Informatiebeveiliging Overheid),
  ISO/IEC 27001:2022, AVG / GDPR — framework references for
  card labels.
- `tender-compliance-status.md` — internal Conduction compliance
  baseline.
