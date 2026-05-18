# Spec: mydash-spend-analytics-widget

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, widget-add-edit-modal, widget-collision-placement, runtime-shell, permissions, conditional-visibility, responsive-grid-breakpoints; cross-app runtime sources: financeq (GraphQL), procest (GraphQL), docudesk (GraphQL), openconnector (LLM source)

## Purpose

Surface procurement + financial spend analytics on a mydash dashboard
as a single widget (`mydash_spend_analytics`). The widget consumes
data live at render time via runtime GraphQL queries against
financeq + procest (and, for evidence attachment, docudesk through
OR's `object-interactions` integration registry per ADR-022). The
widget MUST NOT add an install-time dependency on any sibling app —
per `feedback_mydash-no-or-dependency.md`, mydash stays the
always-available shell.

Consolidates 8 Specter context briefs (`spend-analytics-reporting`,
`-ai`, `-analytics-t1`, `-analytics-t2`, `-document-management`,
`-other-t1`, `-other-t2`, `-other-t3`) — all budget-bucket fragments
of the same procurement-spend surface — into one coherent widget
capability. The misleading `— Shillinq` suffix on the source briefs
came from the budgetq/shillinq cluster origin and is dropped; this
is a mydash widget that reads sibling-app data, not a shillinq
feature.

## ADDED Requirements

### REQ-SAW-001: The system SHALL register a `mydash_spend_analytics` widget type discoverable via the unified Add Widget modal

The widget MUST appear in `src/constants/widgetRegistry.js` (per the
widgets capability REQ-WDG-014 single-source-of-truth rule) and MUST
be selectable from the Add Widget modal type picker (REQ-WDG-010).
The registry entry MUST carry `displayName`, `defaultContent`,
`renderer`, `form`, `icon`, and a soft `requires` declaration naming
the runtime GraphQL endpoints it consumes — never an entry in
`manifest.dependencies`.

#### Scenario: Widget type listed in registry completeness test

- **GIVEN** the registry completeness test (REQ-WDG-023 EXPECTED_TYPES)
- **WHEN** the test runs
- **THEN** `spend-analytics` MUST appear in EXPECTED_TYPES
- **AND** the registry entry MUST carry non-null `form`, `renderer`,
  `displayName`, `defaultContent`, and `icon` fields
- **AND** the entry's `requires` MUST list at minimum
  `{graphql: ['financeq.transactions', 'procest.cases']}`

#### Scenario: Widget appears in unified Add modal

- **GIVEN** the unified Add Widget modal is open with no preselected type
- **WHEN** the user opens the type picker
- **THEN** the picker MUST list `Spend analytics` as a selectable
  type
- **AND** selecting it MUST mount the `SpendAnalyticsForm` sub-form

### REQ-SAW-002: The widget SHALL declare a `mydash_spend_analytics` entry in `src/manifest.json` with a soft `requires` clause

The manifest entry MUST live in `widgets[]` (per ADR-024 + the mydash
widget convention). It MUST set `requires.graphql` listing the
sibling-app GraphQL schemas it queries. It MUST NOT add
`shillinq`, `financeq`, `procest`, `docudesk`, or `openconnector`
to top-level `manifest.dependencies` — runtime checks gate
behaviour, not install.

#### Scenario: Manifest entry present and soft-required

- **GIVEN** the mydash manifest at `src/manifest.json`
- **WHEN** parsed
- **THEN** `manifest.widgets[].find(w => w.id === 'mydash_spend_analytics')` MUST exist
- **AND** that entry's `requires.graphql` MUST be a non-empty array
- **AND** `manifest.dependencies` MUST NOT contain any of
  `financeq`, `procest`, `docudesk`, `shillinq`, `openconnector`

#### Scenario: Manifest validation passes

- **GIVEN** `npm run check:manifest` is wired per ADR-024 §5
- **WHEN** the script runs
- **THEN** the manifest MUST pass schema validation with the
  spend-analytics widget entry present

### REQ-SAW-003: The widget content shape SHALL be the persisted JSON contract for placements

The placement persists `{type: 'spend-analytics', content: {...}}`
in `oc_mydash_widget_placements.content` (per the widgets capability
REQ-WDG-010 emit shape). The `content` object MUST carry:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `viewMode` | enum | Yes | `'summary'` | `'summary' \| 'trend' \| 'top-vendors' \| 'top-categories'` |
| `period` | enum | Yes | `'quarter'` | `'month' \| 'quarter' \| 'ytd' \| 'fy'` |
| `filters.categoryIds` | string[] | No | `[]` | CPV / category filter |
| `filters.departmentIds` | string[] | No | `[]` | Cost-centre / department filter |
| `filters.vendorIds` | string[] | No | `[]` | Supplier / vendor filter |
| `drillThroughTarget` | enum | No | `'detail-page'` | `'detail-page' \| 'sibling-app'` |
| `attachEvidence` | boolean | No | `true` | Surface docudesk-attached evidence per OR object-interactions |
| `aiInsights.enabled` | boolean | No | `false` | Whether to surface LLM-generated narrative (REQ-SAW-006) |

#### Scenario: Minimal placement validates

- **GIVEN** the content shape contract
- **WHEN** a placement is saved with `{type: 'spend-analytics', content: {viewMode: 'summary', period: 'quarter'}}`
- **THEN** validation MUST pass
- **AND** defaults for unset fields MUST be applied by the renderer

#### Scenario: Invalid viewMode rejected

- **GIVEN** the form attempts to save `content.viewMode = 'forecast'`
- **WHEN** `validate()` runs
- **THEN** validation MUST return a non-empty error array
- **AND** the modal MUST keep the Add/Save button disabled

### REQ-SAW-004: The widget SHALL fetch spend data via runtime GraphQL against financeq + procest

The renderer MUST issue GraphQL operations to the sibling apps'
OR-mounted `/graphql` endpoints via the shared client from
`@conduction/nextcloud-vue` (per `feedback_shared-deps.md` +
`feedback_vue-logic-in-ncvue.md`). Queries MUST be read-only. The
renderer MUST NOT cache responses across component unmounts —
freshness is the sibling apps' responsibility. The renderer MUST
NOT call OR endpoints directly via `axios` from mydash code.

#### Scenario: Renderer issues GraphQL to financeq

- **GIVEN** a placement with `{viewMode: 'summary', period: 'quarter'}`
- **WHEN** the widget mounts
- **THEN** the renderer MUST issue a GraphQL query to financeq's
  `/graphql` endpoint covering at minimum the fields:
  `transactions { totalAmount currency }`
- **AND** the query MUST include the period filter as a variable

#### Scenario: Renderer issues GraphQL to procest for cases

- **GIVEN** a placement with `{viewMode: 'top-vendors'}`
- **WHEN** the widget mounts
- **THEN** a parallel GraphQL query MUST be issued to procest's
  `/graphql` endpoint resolving `cases { vendor totalCommitted }`
- **AND** both queries MUST run via the shared
  `@conduction/nextcloud-vue` GraphQL client

#### Scenario: No direct axios to sibling apps

- **GIVEN** the spend-analytics renderer source files
- **WHEN** scanned for `axios` / `fetch(` calls targeting
  `/index.php/apps/financeq/`, `/apps/procest/`, `/apps/shillinq/`,
  `/apps/docudesk/`
- **THEN** zero matches MUST exist — every cross-app call MUST
  route through the shared GraphQL client

### REQ-SAW-005: The widget SHALL render an empty-state without throwing when a required sibling app is absent

When any required GraphQL endpoint returns 404 (sibling app not
installed) or an empty result, the widget MUST render an empty-state
panel naming the missing source and a hint for the admin. The
widget MUST NOT show a 500 / red error, MUST NOT block the rest of
the dashboard, and MUST NOT prompt the user to install anything
client-side (install is an admin action).

#### Scenario: financeq absent renders empty-state

- **GIVEN** financeq is not installed on the Nextcloud instance
- **WHEN** the spend-analytics widget mounts
- **THEN** the GraphQL call MUST return 404
- **AND** the widget MUST render the empty-state with text
  including `t('mydash', 'No spend data — financeq is not installed')`
- **AND** the surrounding GridStack grid MUST remain interactive

#### Scenario: financeq present but no data in window

- **GIVEN** financeq is installed and returns `{transactions: {edges: []}}`
- **WHEN** the widget renders
- **THEN** the empty-state MUST display
  `t('mydash', 'No spend recorded for the selected period')`
- **AND** the period selector MUST remain operable so the viewer
  can widen the window

#### Scenario: Partial sibling availability

- **GIVEN** financeq returns data but procest is absent
- **WHEN** the `top-vendors` view renders
- **THEN** the financeq-derived summary cards MUST still render
- **AND** the vendor breakdown panel MUST render its own empty-state
  inline without disabling the rest of the widget

### REQ-SAW-006: The widget SHALL surface AI-generated narrative via the openconnector LLM source — never a direct LLM client in mydash

When `content.aiInsights.enabled === true`, the widget MUST issue an
inference call through the openconnector LLM source registered as
`local-llm` (per `reference_llphant-ollama-think-false` — Ollama +
Qwen, `think: false`, `keep_alive: -1`). The widget MUST NOT import
`@ollama/ollama` or any LLM SDK directly. The widget MUST stream
the reply into the panel using the established Conduction SSE
pattern from `project_ai-chat-companion-end-to-end` (ADR-034 chain).

#### Scenario: Narrative request routes through openconnector

- **GIVEN** `aiInsights.enabled === true` and the viewer requests an insight
- **WHEN** the widget issues the inference call
- **THEN** the request MUST target openconnector's source endpoint,
  NOT `localhost:11434` or any other Ollama / LLM URL directly
- **AND** the request body MUST include the spend summary as
  context payload prepared by the widget

#### Scenario: openconnector absent disables AI panel gracefully

- **GIVEN** openconnector is not installed or the `local-llm` source
  is not configured
- **WHEN** the user toggles `aiInsights.enabled`
- **THEN** the toggle MUST present a tooltip explaining the missing
  source
- **AND** no inference request MUST be issued
- **AND** the rest of the widget MUST continue to render data
  cards

#### Scenario: No LLM SDK import

- **GIVEN** the spend-analytics widget source files
- **WHEN** scanned for `import ... from '@ollama/'`,
  `from 'openai'`, `from '@anthropic-ai/'`, or `LLPhant`
- **THEN** zero matches MUST exist

### REQ-SAW-007: Drill-through SHALL navigate to the sibling app's detail surface — mydash MUST NOT mirror sibling-app pages

Clicking a row in the widget (transaction, vendor, case) MUST
navigate via deep-link to the owning sibling app's detail surface
(per OR's deep-link registry — ADR-022 table row "Deep link
registry"). mydash MUST NOT render a local detail page for sibling
data.

#### Scenario: Vendor row click deep-links to procest

- **GIVEN** the `top-vendors` view shows vendor `acme`
- **WHEN** the user clicks the row
- **THEN** the browser MUST navigate to procest's vendor detail URL
  (resolved via OR deep-link registry)
- **AND** mydash MUST NOT render a local `/spend/vendor/acme` page

#### Scenario: Transaction row click deep-links to financeq

- **GIVEN** the `summary` view's drill-through opens a transaction
  list
- **WHEN** the user clicks a transaction row
- **THEN** the navigation target MUST be financeq's transaction
  detail URL

#### Scenario: drillThroughTarget=detail-page renders mydash overlay

- **GIVEN** `content.drillThroughTarget === 'detail-page'` (the
  default — for users who prefer staying in mydash)
- **WHEN** the user clicks a row
- **THEN** the widget MUST surface the sibling app's detail
  payload via an OR object-interactions panel inside the widget
  cell — still served by the sibling app's GraphQL, not a mydash
  copy

### REQ-SAW-008: Evidence attachments SHALL be consumed via OR's `object-interactions` integration registry

When `content.attachEvidence === true`, each spend row (transaction,
case) MUST surface its docudesk-attached evidence (invoices,
contracts) using OR's `object-interactions` files integration per
ADR-022 (Integration registry row) + `feedback_integration-registry-end-to-end`.
The widget MUST NOT POST/PUT to docudesk endpoints — uploads are
the sibling app's responsibility.

#### Scenario: Evidence files surface inline

- **GIVEN** a transaction with 2 docudesk-attached invoices
- **WHEN** the widget renders the transaction row with
  `attachEvidence === true`
- **THEN** the row MUST surface 2 evidence chips with the
  document titles
- **AND** clicking a chip MUST deep-link to the docudesk viewer
  (via OR deep-link registry)

#### Scenario: Evidence widget is read-only

- **GIVEN** the spend-analytics widget source files
- **WHEN** scanned for HTTP method `POST` / `PUT` / `DELETE` against
  paths matching `/apps/docudesk/`
- **THEN** zero matches MUST exist — uploads happen in docudesk

#### Scenario: No evidence attached

- **GIVEN** a transaction with zero attached evidence
- **WHEN** the row renders
- **THEN** the evidence area MUST be hidden (no empty chip slot)
- **AND** the row MUST NOT show an "Attach evidence" affordance —
  attaching happens in docudesk

## Non-Functional Requirements

- **Performance:** The widget MUST debounce period / filter changes
  to ≤200 ms before re-issuing GraphQL queries. Aggregated queries
  SHOULD set GraphQL operation timeouts to 5 s; on timeout the
  widget MUST render a partial / empty-state, never a thrown error.
- **Accessibility:** Every panel inside the widget (summary cards,
  trend chart, drill-through list, AI narrative panel) MUST satisfy
  WCAG 2.1 AA. Numeric values MUST be screen-reader accessible
  (e.g. `aria-label="Total spend Q1 2026: 1.2 million euro"`).
- **Localisation:** Strings MUST be available in English and Dutch
  per the i18n requirement; numeric formatting MUST respect the
  Nextcloud user's locale (NL: `1.234.567,89`, EN: `1,234,567.89`).
- **Privacy:** The widget MUST NOT log spend payloads to the
  browser console or to any external telemetry. The optional AI
  inference payload (REQ-SAW-006) carries spend summary data — the
  openconnector LLM source MUST be the local `local-llm`
  (Ollama+Qwen) per Conduction default, never a hosted-LLM source,
  unless an admin explicitly configures one.
- **Browser support:** Same baseline as the broader widgets capability
  (modern evergreen browsers; no IE).

## Source consolidation

This spec replaces / supersedes the following Specter context
briefs as a single capability:

- `spend-analytics-reporting`
- `spend-analytics-reporting-ai` → REQ-SAW-006 (AI narrative)
- `spend-analytics-reporting-analytics-t1` → REQ-SAW-001..-004 (data + widget)
- `spend-analytics-reporting-analytics-t2` → REQ-SAW-007 (drill-through)
- `spend-analytics-reporting-document-management` → REQ-SAW-008 (evidence)
- `spend-analytics-reporting-other-t1` → REQ-SAW-001..-005 (consolidated)
- `spend-analytics-reporting-other-t2` → REQ-SAW-003 (filters)
- `spend-analytics-reporting-other-t3` → REQ-SAW-003 (category baseline)

The misleading `— Shillinq` suffix in those briefs is dropped; the
capability is reframed as a mydash widget consuming runtime data.

## Reuses (mydash)

- `widgets` — registry + add/edit modal contract
- `widget-add-edit-modal` — modal host + per-type validation
- `widget-collision-placement` — grid placement defaults
- `runtime-shell` — workspace mount + edit affordances
- `permissions` — view / edit gating
- `conditional-visibility` — per-role visibility rules
- `responsive-grid-breakpoints` — mobile fallback

## Standards & References

- ADR-022 — apps consume OR abstractions (object-interactions,
  deep-link registry, GraphQL via OR mount).
- ADR-024 — manifest declaration of widgets.
- `feedback_mydash-no-or-dependency.md` — runtime-only cross-app
  consumption.
- `feedback_shared-deps.md` — GraphQL client from
  `@conduction/nextcloud-vue`.
- `project_ai-chat-companion-end-to-end.md` — established SSE +
  openconnector LLM-source pattern.
- `reference_llphant-ollama-think-false.md` — `think: false` +
  `keep_alive: -1` for the Ollama path.
- ECCMA UNSPSC / CPV — category coding for `filters.categoryIds`
  (consumed from financeq/procest, not redefined here).
