# Design: add-mydash-discovered-and-spend-analytics

## Context

Specter's intelligence pass for mydash produced 14 spec drafts (listed
in proposal.md → Why). Each draft was a context brief — feature
clusters, demand counts, and acceptance criteria — but none had been
authored as a mydash capability spec. The 8 spend-analytics drafts
were budget-bucket fragments of a single procurement-spend surface;
the other 6 were each a discrete widget capability.

mydash's existing `openspec/specs/` contains 56 capability specs that
already cover:

- the dashboard data model (`dashboards`, `widgets`, `tiles`,
  `permissions`, `grid-layout`, `runtime-shell`, `initial-state-contract`),
- 17 built-in widget types (`calendar-widget`, `files-widget`,
  `image-widget`, `link-button-widget`, `links-widget`, `menu-widget`,
  `news-widget`, `people-widget`, `quicklinks-widget`, `text-display-widget`,
  `video-widget`, `divider-widget`, `header-widget`, `label-widget`,
  `container-widget`, `nc-dashboard-widget-proxy`, `legacy-widget-bridge`),
- the cross-app NC integration surface (`nc-unified-search-integration`,
  `activity-feed-integration`, `legacy-widget-bridge`,
  `nc-dashboard-widget-proxy`),
- admin/template/conditional features (`admin-settings`, `admin-roles`,
  `admin-templates`, `conditional-visibility`, `role-based-content`,
  `setup-wizard`, `dashboard-export-import`, `dashboard-versioning`,
  `default-widget-bundle`).

What's **missing** is a fleet-aware widget set: widgets that consume
data from sibling Conduction apps. The 7 new specs in this change all
slot into this gap. They follow the existing widget convention
(manifest entry → `widgetRegistry.js` entry → sub-form → renderer →
content JSON shape persisted on the placement), with the data source
shifted from "self-contained" (label/image/text/divider) or
"Nextcloud-native" (calendar/files/people/news) to "sibling-app
runtime GraphQL".

## Reuse Analysis

Every widget in this set composes existing capabilities — no new
mydash infrastructure. The composition table:

| Spec | Reuses (mydash) | Consumes (cross-app, runtime only) |
|---|---|---|
| `mydash-spend-analytics-widget` | `widgets`, `widget-add-edit-modal`, `widget-collision-placement`, `runtime-shell`, `permissions`, `conditional-visibility` | financeq GraphQL (transactions, vendors, budgets), procest GraphQL (procurement-cases), docudesk attachments via OR `object-interactions` |
| `mydash-ai-dashboard-assistant` | `widgets`, `runtime-shell`, `initial-state-contract`, `widget-add-edit-modal` | openconnector LLM source (Ollama+Qwen per `feedback_llphant-ollama-think-false`); mydash never talks to Ollama directly |
| `mydash-compliance-audit-panel` | `widgets`, `widget-add-edit-modal`, `dashboards`, `permissions` | OR audit-trail-immutable + archival-destruction (ADR-022), shillinq retention-rule GraphQL, docudesk compliance evidence |
| `mydash-file-access-widget` | `widgets`, `files-widget` (sibling pattern), `widget-add-edit-modal` | Nextcloud Files OCS API + WebDAV (no OR dep) |
| `mydash-enterprise-security-access` | `widgets`, `admin-roles`, `role-based-content`, `permissions` | OR RBAC GraphQL (ADR-022), Nextcloud user_saml / TOTP provider status |
| `mydash-meeting-calendar-actions` | `widgets`, `calendar-widget` (existing internal pattern) | Nextcloud Calendar DAV, decidesk meeting/agenda GraphQL |
| `mydash-mobile-remote-access` | `widgets`, `responsive-grid-breakpoints` | None at runtime — this is a manifest-declaration spec |

The OpenRegister abstractions consumed (ADR-022 column "Abstraction"):

- **audit trail (immutable)** — `mydash-compliance-audit-panel`
- **archival + destruction workflow** — `mydash-compliance-audit-panel`
- **authorization RBAC** — `mydash-enterprise-security-access`
- **schemas + objects (read-only over GraphQL)** — every widget that
  surfaces sibling-app data goes through OR's GraphQL endpoint per the
  pattern documented in the `feedback_mydash-no-or-dependency` memory.
- **integration registry (ADR-019)** — `mydash-file-access-widget`
  reads `object-interactions` (files attached to dossier objects) via
  the same registry pattern as `feedback_integration-registry-end-to-end`.

The `@conduction/nextcloud-vue` shared library provides the runtime
GraphQL client + every UI primitive these widgets render
(`CnIndexPage`, `CnDataTable`, `CnFormDialog`,
`CnDetailGrid` per `feedback_vue-logic-in-ncvue.md`). No mydash-local
GraphQL plumbing, no mydash-local data tables.

## Declarative-vs-imperative decision

Per ADR-031, every requirement in this change set lands declaratively:

- **No mydash service classes.** mydash already follows ADR-022 (no
  install-time OR dep), so it cannot have lifecycle / aggregation /
  notification services over OR objects in the first place — every
  data surface is a runtime read.
- **Manifest entries** for each widget (`src/manifest.json`
  `widgets[]` per ADR-024) — declarative.
- **`widgetRegistry.js` entries** per spec (`{ component, label,
  defaults, requires }`) — declarative.
- **Per-widget content JSON shape** persisted on `oc_mydash_widget_placements.content` — declarative.
- **Runtime queries** are GraphQL operation strings inside the
  per-widget Vue components — declarative artefacts, no PHP service.

The only PHP surface is mydash's existing `WidgetService` (already
shipped, no change), and the existing API routes that serve widget
items. No new controllers, no new entities, no new mappers.

`kind: config` per ADR-032 — the entire change set is JSON / spec
text. Implementation chains for each widget will be `kind: code`
when they land (Vue components + manifest JSON edits), but per ADR-032
the spec change ships first, then per-widget chains follow.

## Seed Data

No seed data — mydash holds no OR-backed schemas; the widgets in this
set are read-only consumers. The sibling apps (procest, decidesk,
shillinq, docudesk) already carry their own seed data per ADR-016.
For local screenshot/QA purposes the demo dashboard bundle
(`demo-data-showcases` spec) will gain a "spend analytics demo"
dashboard in the per-widget implementation chain, not here.

## Cross-app data contract

Each widget MUST follow the same runtime-only contract documented in
`feedback_mydash-no-or-dependency.md`:

1. The widget's manifest entry declares its data source under
   `widgets[<id>].requires` as a soft requirement — never a hard
   dependency in `manifest.dependencies`.
2. The renderer issues a GraphQL query through
   `@conduction/nextcloud-vue` to the sibling app's OR-mounted
   `/graphql` endpoint (or to Nextcloud DAV / OCS for native data).
3. If the endpoint returns 404 or an empty result, the widget MUST
   render its empty-state without throwing. The widget MUST NOT
   show a "this app is required" error — mydash is the
   always-available shell and the absence of a sibling app is a
   normal runtime state.
4. The widget MUST NOT cache cross-app data beyond the Vue component's
   own lifetime. Persistent caching is the sibling app's
   responsibility.
5. Authorization is enforced by the sibling app's RBAC, not mydash —
   mydash receives only what the viewer is allowed to see.

## Spec-sizing decision (ADR-032)

This change is `kind: config` end-to-end:

- Files touched: 7 × `specs/<slug>/spec.md` + `proposal.md` +
  `design.md` + `tasks.md` — all markdown.
- No `.php`, `.vue`, `.ts`, `.js` files in scope.
- Each per-widget implementation lands as a follow-up chain, where
  chain spec 1 is the manifest + registry entry (`kind: config`,
  small), chain spec 2 is the renderer + sub-form (`kind: code`,
  small-medium), and chain spec 3 (if needed) wires the runtime
  GraphQL client (`kind: code`, small). Per-widget chain planning
  happens after this change is archived.

## Mixed-spec rationale

N/A — this change is pure `kind: config`. No thin-glue code edits.

## Alternatives considered

1. **Author the 8 spend-analytics drafts as 8 separate specs.**
   Rejected. The drafts are budget-bucket fragments of the same
   procurement-spend surface — splitting them would create 8 specs
   for one widget and force the reviewer to follow cross-cutting
   threads through artificial boundaries.

2. **Land a shillinq spend-analytics spec instead of a mydash one.**
   Rejected. shillinq already owns the **data** (transactions,
   vendors, budgets); mydash owns the **dashboard surface**. The
   capability the Specter drafts describe — "a widget on a
   dashboard showing spend" — is a mydash surface that consumes
   shillinq data. Putting the spec in shillinq would force a
   shillinq → mydash dependency reversal that violates ADR-022's
   abstractions hierarchy.

3. **Defer the AI assistant spec until openconnector ships the LLM
   provider plumbing.** Rejected. The AI assistant spec describes
   the widget contract (input box + streamed output + tool calls)
   and references the openconnector source by name; it does not
   author the source. The widget can ship behind a feature flag if
   the source is not yet present on the deployment, per the
   empty-state contract above.

4. **Author `mydash-mobile-remote-access` as a runtime capability
   instead of a manifest-declaration capability.** Rejected. mydash
   already ships responsive widget rendering via
   `responsive-grid-breakpoints` and GridStack breakpoints; the gap
   the Specter draft surfaces is the **manifest contract** for
   declaring widget mobile-readiness so the workspace can fall
   back to a stack layout when no mobile-ready widget is present.
   That is a declaration, not a runtime addition. PWA / offline
   work goes through a separate change.

## See also

- ADR-022 — apps consume OR abstractions (every widget here reads OR
  via runtime GraphQL only).
- ADR-024 — app manifest fleet-wide adoption (the manifest is the
  declaration surface for every widget in this set).
- ADR-031 — schema-declarative business logic (no mydash services
  added).
- ADR-032 — spec sizing + chained specs (this change is
  `kind: config`; per-widget implementations chain as follow-ups).
- `feedback_mydash-no-or-dependency.md` — the non-negotiable runtime
  GraphQL rule.
- `feedback_vue-logic-in-ncvue.md` — UI primitives live in
  `@conduction/nextcloud-vue`, not in mydash.
- `feedback_shared-deps.md` — GraphQL client comes from nc-vue.
- `decidesk/src/manifest.json` — canonical Tier-4 manifest example
  (mydash will not match decidesk's `pages[]` shape but will mirror
  its `widgets[]` shape).
- `feedback_integration-registry-end-to-end` — runtime registry
  pattern (`mydash-file-access-widget` follows the same shape).
