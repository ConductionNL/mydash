---
kind: config
depends_on: []
chain: []
---

# Add mydash discovered widgets + spend-analytics widget

## Why

Specter's intelligence pass for mydash surfaced 14 spec drafts that have no
match in the existing `openspec/specs/` library. mydash today ships widget
plumbing (`widgets`, `runtime-shell`, `dashboards`, `permissions`,
`tiles`, plus 17 per-type widgets) but no widgets that consume
operational data from sibling Conduction apps. The fleet ships that data
(shillinq finance, procest cases, decidesk meetings, docudesk
compliance), but a Nextcloud user landing on mydash sees only the
generic widget bundle. This change adds the missing capabilities as
**widget specs** — mydash's first-class extensibility surface — so the
fleet's cross-app value lands on the user's home page.

Eight of the 14 drafts (`spend-analytics-reporting`, `-ai`, `-analytics-t1`,
`-analytics-t2`, `-document-management`, `-other-t1`, `-other-t2`,
`-other-t3`) all describe the same capability — a procurement /
financial-spend analytics surface with AI insights, drill-through, and
document attachment — fragmented into 8 budget-buckets by the
Specter generator. The drafts also carry a misleading `— Shillinq`
suffix because the cluster originated from the budgetq/shillinq
intelligence brief. They are consolidated into one mydash widget spec
(`mydash-spend-analytics-widget`) that frames the capability as a
mydash widget consuming runtime GraphQL from financeq + procest +
docudesk — per `feedback_mydash-no-or-dependency.md` mydash MUST NOT
acquire an install-time dependency on OpenRegister or any sibling app.

The remaining six drafts (`ai-dashboard-assistant`,
`compliance-audit-panel`, `dashboard-file-access`,
`enterprise-security-access`, `meeting-calendar-actions`,
`mobile-remote-access`) each describe a distinct mydash widget surface
and become their own specs.

## What Changes

Seven net-new capability specs, all under the existing mydash widget
convention (manifest-declared, GridStack-rendered, `widgetRegistry`
entry, sub-form + renderer, runtime GraphQL/DAV for cross-app data).
No code, no schemas, no service classes — this is a `kind: config`
spec set that lands behaviour as manifest declarations and capability
contracts. Implementation lands in follow-up chains, one per widget.

### New Capabilities

- **mydash-spend-analytics-widget** — spend-analytics dashboard widget
  consuming runtime GraphQL from financeq + procest, with AI-assisted
  insights, drill-through, and docudesk-attached evidence.
  Consolidates 8 Specter drafts.
- **mydash-ai-dashboard-assistant** — embedded AI assistant widget
  surface: summary generation + natural-language queries, consuming
  the established Conduction local-LLM plumbing (Ollama/Qwen via
  openconnector) per ADR-022; mydash does NOT host its own LLM client.
- **mydash-compliance-audit-panel** — compliance posture widget
  showing BIO/ISO/AVG status, audit-trail summary, retention
  disposition counts. Runtime GraphQL from openregister (audit-trail,
  archival-destruction) + shillinq (Archiefwet retention) + docudesk.
- **mydash-file-access-widget** — file browser / quick-access widget
  surfacing Nextcloud Files objects (typically dossier documents).
  Consumes the Nextcloud Files API per Nextcloud convention; no
  file storage in mydash.
- **mydash-enterprise-security-access** — read-only security/access
  panel surfacing role assignments, SSO status, MFA enforcement.
  Consumes OR's RBAC abstraction per ADR-022.
- **mydash-meeting-calendar-actions** — meeting + calendar actions
  widget surfacing upcoming meetings from Nextcloud Calendar (DAV)
  + decidesk meetings/agendas (runtime GraphQL).
- **mydash-mobile-remote-access** — responsive-widget + PWA capability
  declarations spec. Widget authors declare mobile-readiness in the
  manifest per ADR-024; this spec defines the contract.

### Modified Capabilities

(none — every requirement is net-new under a net-new capability slug;
the existing `widgets` spec stays untouched as the generic widget
shell, and these specs sit as peers, each describing one new
manifest-declared widget type.)

## Impact

**Affected docs:**

- `openspec/specs/mydash-*/spec.md` — 7 new capability specs.
- `openspec/changes/add-mydash-discovered-and-spend-analytics/` — this
  proposal + design + tasks (delivered together to give Hydra a
  ready-to-build envelope when the implementation chain is split out).

**Affected code:**

- (deferred to per-spec implementation chains) `src/manifest.json`
  widget registry entries, `src/constants/widgetRegistry.js`,
  per-widget sub-form + renderer components, runtime GraphQL
  clients in `src/services/graphql/`. Each widget becomes its own
  chain spec per ADR-032 (this proposal stays declarative).

**Affected APIs:**

- (deferred to per-spec implementation chains) every widget added by
  this set is a **read-only consumer** of existing APIs on sibling
  apps. No new endpoints on mydash. Runtime GraphQL endpoints
  consumed: `/graphql` on openregister (and via OR on every sibling
  app that exposes a register), Nextcloud Calendar DAV, Nextcloud
  Files OCS API.

**Dependencies:**

- **No new install-time dependencies.** Per
  `feedback_mydash-no-or-dependency.md` + ADR-024 §10, mydash's
  `manifest.dependencies` list MUST NOT include `openregister`,
  `shillinq`, `procest`, `decidesk`, or `docudesk`. Each widget
  declares a **soft `requires`** in its manifest entry (e.g.
  `requires: {graphql: ['financeq.transactions']}`) — the widget
  registers itself, gracefully renders an empty-state when the
  endpoint is absent at runtime, and never blocks app install.
- npm: no new packages. Apollo Client / urql are already present in
  `@conduction/nextcloud-vue` per `feedback_shared-deps.md`.

**Trade-offs:**

- Consolidating 8 Specter spend drafts into one spec drops the
  budget-bucket cluster names from the artefact list — that
  taxonomy was a Specter generation artifact, not a meaningful
  product boundary. The consolidated spec preserves the full feature
  set (procurement spend, AI insights, document evidence,
  drill-through, multi-source aggregation) under one coherent
  widget surface, which matches how a dashboard user encounters it.
- All seven specs are `widget-capabilities` tier and ship as
  `kind: config` (declarative manifest + capability contract). Per
  ADR-032, the actual widget implementations are chain follow-ups —
  one chain per widget — keeping each Hydra cycle scoped.
- Implementation defers the "which GraphQL schemas exist on each
  sibling app right now?" gap analysis to the per-widget chain
  spec. If a sibling app's GraphQL surface is incomplete, the
  widget's chain spec 1 (config) declares the empty-state
  contract; chain spec 2+ (code, in the sibling app) adds the
  missing GraphQL field. mydash itself stays unblocked.

## Out of scope

- Implementation code (Vue components, sub-forms, renderers, GraphQL
  clients) — deferred to per-widget chain specs.
- Schema additions on sibling apps (OR registers, decidesk meeting
  GraphQL fields, shillinq finance GraphQL fields) — tracked
  separately per sibling app's openspec.
- AI-model selection, prompt engineering, RAG plumbing for the
  AI assistant — `mydash-ai-dashboard-assistant` describes the
  widget surface and the openconnector hand-off, not the model
  side.
- New mydash routes or pages — every capability lands as a widget on
  the existing dashboard surface; no new manifest `pages[]` entries.
- PWA service-worker / offline-storage implementation —
  `mydash-mobile-remote-access` describes the manifest capability
  declarations only.
