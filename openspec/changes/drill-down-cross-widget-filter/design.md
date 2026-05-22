# Design — Drill-down Cross-widget Filter

## Context

MyDash dashboards today render each widget as an isolated island. A chart widget pulls its own data with its own filter context; clicking a bar either does nothing or drops the user into a separate list view. Every drill-down step requires reopening filter modals on three widgets, re-entering gemeentecode and wijkcode, and reconciling inevitable inconsistencies — "is this widget's 'older than 6 weeks' calculation the same as that one's?" The user mental model is simple ("show me bezwaren in Zeist, then Vollenhove, then open ones > 6 weeks") but the UI breaks it into three separate journeys through three separate modals.

The OpenRegister API already supports multi-dimension filtering via `_filter` query parameters and uses composite indexes to serve them efficiently. The gap is in the dashboard host: no glue code detects clicks, no pub/sub layer coordinates subscribers, no URL encoding makes filtered views bookmarkable. This change adds that glue.

## Goals / Non-Goals

**Goals:**

- Make filter state shared across the dashboard via a per-dashboard pub/sub bus.
- Declare widget compatibility (which dimensions a widget can publish/consume) via manifest, not runtime guesswork.
- Make filtered views bookmarkable and reproducible via URL encoding.
- Support drill-down hierarchies (gemeente → wijk → buurt) so a child-level click infers and applies ancestor filters.
- Resolve filter conflicts explicitly with audit trails, not silent overwrites.
- Allow users to save named filter combinations and apply them with one click.
- Measure which drill-down paths users exercise (opt-in telemetry, no PII).

**Non-Goals:**

- Rewrite the OpenRegister query layer. The bus translates to `_filter` parameters; OR's index strategy unchanged.
- Create a new visual filter builder / query-construction UI. Filtering happens via widget clicks (app-driven) or saved views (one-click apply), not ad-hoc form building.
- Support cross-register filtering (e.g., filter one dashboard that pulls from Register A AND Register B). Scope is per-dashboard, per-register.
- Implement cross-user collaboration features (e.g., "user A applied a filter, broadcast to user B's dashboard"). Each user has their own filter bus; persistence happens via bookmarkable URLs and shared saved views.
- Support stateful filter-history or "undo" beyond the browser's back button.

## Decisions

### D1: Pub/sub filter bus in the dashboard host, not a new query engine

**Decision**: Implement a thin pub/sub layer in `src/composables/useFilterBus.js` that accepts `{dimension, operator, value}` clauses from widgets, applies conflict resolution, and broadcasts to subscribers. Clauses map to `_filter` query parameters on widget data fetches; no new OR query engine.

**Alternatives considered:**

- Push filter logic into OpenRegister. Rejected — OR is a generic query-execution service; dashboards are a higher-level application concern. Coupling filter logic to OR would either duplicate the logic in other OR clients or force those clients to live with dashboard-specific semantics.
- Use Pinia global store for filter state. Rejected — Pinia is great for component-level state, but filter state is tied to the URL and must survive page reloads. A composable that reads from/writes to the URL (via router) is the cleaner boundary.
- Implement a constraint-solver for conflict resolution (e.g., "common ancestor between two conflicting clauses, prefer the narrower scope"). Rejected — dramatically more complex, and last-write-wins with explicit audit is what collaborative tools (Tableau, Power BI, Kibana) actually do because it is predictable and undoable.

**Rationale**: The bus is a glue layer between widgets and OR, not a new subsystem. Reusing OR's existing `_filter` parameter means no new indexes, no new query planner, and the same performance characteristics widgets already have for share-link filtering.

### D2: Manifest declarations for dimension compatibility, not runtime detection

**Decision**: Widget manifests include `publishes: [{dimension, operator, fromField}]` and `consumes: [{dimension, operator, intoFilter}]` arrays. The dashboard validates these at widget render time and surfaces mismatches (e.g., "this widget consumes 'wijkCode' but no hierarchy on this dashboard includes it") as non-fatal warnings.

**Alternatives considered:**

- Detect dimension compatibility at runtime (inspect widget data shape on first fetch). Rejected — the shape only arrives after the query runs; this delays the validation and couples the decision to the data. Manifest-based validation is immediate and testable.
- Use a TypeScript interface to encode allowed dimensions. Rejected — manifests need to be readable by dashboard operators who are not developers; JSON is more approachable than type interfaces.

**Rationale**: Manifests are the source of truth for widget capabilities. Validation catches misconfigurations at dashboard-edit time ("you've added a widget that expects 'gemeenteCode' but your hierarchies don't mention it") and prevents silent failures at runtime.

### D3: Declarative hierarchy model with parent-relationships

**Decision**: Hierarchy descriptors live in the dashboard manifest and include ordered level arrays with explicit parent-relationships: `{id, levels: [{dimension, parentDimension, label}, ...]}`. When a wijk-level click arrives, the bus infers the ancestor gemeentecode and provincecode (parent-of-parent) from the CBS administrative hierarchy and applies them as `source=parentScope` clauses.

**Alternatives considered:**

- Hard-code the hierarchy in code (a switch statement per known hierarchy). Rejected — organisations with custom taxonomies can't use them without forking mydash. Declarative JSON is extensible.
- Compute parent relationships at query time from the data (fetch all gemeentes for the clicked wijk, infer the gemeentecode). Rejected — adds latency and couples hierarchy to the data shape, making it brittle.
- Support arbitrary parent-relationships without inferring them (user manually clicks up the hierarchy). Rejected — the whole point of hierarchies is to make the breadcrumb and ancestor filters automatic.

**Rationale**: CBS gemeentecodes and wijkcodes have stable, well-documented parent-relationships (wijk code WK0701001 → gemeente GM0701, which is always the first 4 digits). Declarative hierarchies make the relationships explicit, auditable, and extensible for non-standard taxonomies.

### D4: URL encoding via base64url-compact-JSON, not parameter-per-clause

**Decision**: Serialize the entire filter state to a single `?f=<base64url>` parameter using compact JSON keys (`d` for dimension, `o` for operator, `v` for value, `s` for source) to stay within typical 2 KB URL length limits.

**Alternatives considered:**

- One query parameter per clause: `?gemeenteCode=GM0355&status=open`. Rejected — dimensions with special characters or namespaced names (e.g., `period.month`, `custom.myTaxonomy`) become unreadable; query-parameter order is not guaranteed; dashboards with 10+ clauses exceed URL limits.
- Use a different encoding (gzip, base64 without url-safe alphabet). Rejected — base64url is what JWT, OAuth2 PKCE, and (for this exact purpose) Grafana use; it's a proven standard that doesn't require percent-encoding in URLs.

**Rationale**: Grafana's `var-` parameters use the same approach and have solved the "dozens of clauses" problem. Base64url produces readable (if compact) URLs that can be copy-pasted, and compact JSON keys keep the size under typical proxy/browser limits.

### D5: Last-write-wins conflict resolution with audit tooltips

**Decision**: When a new clause for dimension X arrives and X already has an active clause, replace the old one silently or with a brief audit toast depending on the source transition (widget-click → widget-click: show toast; urlBootstrap → widget-click: silent, URL is transient). Show the old and new values (e.g., "Gemeente: Zeist → Houten").

**Alternatives considered:**

- Merge clauses (e.g., if X is already filtered to a set, add the new value to the set). Rejected — this produces subtle wrong answers ("why is my widget showing both GM0355 and GM0344 when I only clicked one?") and is nearly impossible for users to understand or undo.
- Reject conflicting clauses with an error toast (prevent replacement). Rejected — blocks the user's navigation; they have no way to override except by clicking "Reset filters" and starting over.
- Support multi-value filters (e.g., `{op: 'in', value: [GM0355, GM0344]}`). Rejected — the UI would need a multi-select affordance for every click, which breaks the "tap-to-filter" pattern. Multi-select can be a separate "drill-up" feature later.

**Rationale**: Collaborative tools (Tableau, Looker, Power BI) all use last-write-wins because it is predictable and debuggable — the user can see the change via the audit toast and undo by clicking elsewhere. Silent merges are the enemy of trust in analytics.

### D6: Pinned clauses survive "Reset filters"

**Decision**: Clauses marked `pinned: true` (set by org admins via dashboard config) are immutable. When "Reset filters" is invoked, unpinned clauses are cleared but pinned ones remain. When a new clause for a pinned dimension arrives, the publication is silently dropped with a console log (visible to developers, not users).

**Alternatives considered:**

- Allow users to unpin clauses individually. Rejected — pinned clauses are org-level policy ("always scope to current user's gemeente"); users should not override them.
- Show an error when a pinned clause can't be replaced. Rejected — noisy and confusing for users; a console message is sufficient for developer debugging.

**Rationale**: Pinned clauses are the mechanism for org-scope constraints (e.g., "this dashboard always filters to the logged-in user's gemeente"). They need to survive user exploration (so the user can't accidentally scope up to provincial level) without being visible as a restriction in the UI.

### D7: Saved views persist filter combinations in the register

**Decision**: Signed-in users with edit permission on a dashboard can save the current filter state as a named `saved_view` row in the mydash register with fields `{id, dashboardId, name, description, filters, isShared, sharedWith, createdBy, createdAt}`. Saved views appear in a dropdown in the dashboard header; clicking one applies its clauses.

**Alternatives considered:**

- Store saved views in localStorage. Rejected — not shareable between users, no access control, lost on device change.
- Use a separate "views" register. Rejected — the mydash register is the system of record for dashboard metadata; saved views are dashboard artifacts.

**Rationale**: Register-backed persistence makes saved views shareable ("team lead creates 'Open bezwaren > 6w' and shares with the team") and auditable (who created the view, when) without introducing a new persistence tier.

### D8: Telemetry is opt-in, records paths not values

**Decision**: Org-level config flag controls telemetry. When enabled, `filter_event` rows record `{id, dashboardId, userId, widgetId, eventType, filterDelta, timestamp}` with `eventType` one of `apply` / `reset` / `bookmark` / `share`. The `filterDelta` records dimensions and operators, never filter values (which frequently are PII).

**Alternatives considered:**

- Record full filter values for perfect audit trails. Rejected — violates GDPR/AVG principle of data minimisation (NEN-ISO 27001:2022 control 5.34). Single-row filters are user lookups; if telemetry records `status=GM0355` the aggregated report is useless (no pattern) and retention is a liability.
- No telemetry at all. Rejected — orgs need to understand which drill-down paths users exercise to tune hierarchies and retire unused widgets.

**Rationale**: Aggregate reports ("most-used paths: gemeente → wijk → buurt", "least-used widget: [name]") drive tuning; individual filter values do not. The org gets actionable insights without exposing sensitive data.

### D9: 300 ms responsiveness bound for widget re-renders

**Decision**: When a filter clause is published, all consuming widgets MUST re-render and reflect the new filter within 300 ms. If a widget's fetch takes longer, the widget shows a loading indicator (existing pattern in REQ-WDG-007).

**Alternatives considered:**

- No bound, let widgets re-render whenever their fetch completes. Rejected — on slow networks this creates confusing visual delays ("did my click do anything?").
- 100 ms to match Nielsen's "feels instantaneous" threshold. Rejected — achievable only for in-memory caches; most widgets fetch from OR, which takes 150-250 ms on normal networks. 300 ms is the midpoint that mydash consistently hits for drill-down queries (which narrow scope, hitting better-selectivity indexes).

**Rationale**: Jakob Nielsen's responsiveness thresholds — below 100 ms feels instantaneous, 100–1000 ms feels direct, beyond 1 s users lose flow. 300 ms is what real-world widget fetches from OR can sustain without artificial delays.

## Risks / Trade-offs

- **Risk:** URL grows unbounded if users apply a dozen clauses. → **Mitigation:** Conservative 2 KB limit documented in user guides; dashboards that habitually exceed this are an indicator they're doing too much and should be split. Failure mode (URL too long) is self-evident.
- **Risk:** Hierarchy inference fails if a clicked value doesn't exist in the parent level (e.g., user clicks a wijk that belongs to no gemeente in the dashboard's data). → **Mitigation:** The click is rejected with a tooltip "no access to this scope" (REQ-CWF-004). This prevents orphaned hierarchies.
- **Risk:** Pinned clauses are confusing if the UI doesn't signal them clearly. → **Mitigation:** Active-filters panel shows pinned clauses with a lock icon and no remove button; the restriction is visible.
- **Trade-off:** Last-write-wins ignores nuance ("I wanted to add this clause, not replace"). → **Mitigation:** The audit toast is explicit; users can undo via the active-filters panel.
- **Trade-off:** Telemetry does not record which user clicked which filter, only the aggregate. → **Accepted:** Orgs get path usage without exposing user behavior; precise click attribution is not necessary for hierarchy tuning.

## Migration Plan

1. **Manifest schema + composables land first** — add `publishes`, `consumes`, `hierarchies` fields to widget manifests; implement `useFilterBus` and `useDashboardFilter` with unit tests. No UX change yet.
2. **Dashboard host integration** — update `Dashboard.vue` to instantiate the bus, render the active-filters panel, expose "Reset filters". Wire widget clicks to publish events.
3. **Widget adaptation** — update chart and table widgets to emit click events with dimension + value. No manifest changes needed for widgets already accepting `filterContext` prop.
4. **Saved views UI** — add dropdown in dashboard header; "Save view" modal on the active-filters panel.
5. **Register integration** — persist saved views and filter events to the register (REQ-CWF-009 + REQ-CWF-010).
6. **Rollback:** Backend-persisted saved views and telemetry remain, but the filter bus ceases to function if the frontend is rolled back. This is acceptable because saved views are standalone (can be manually applied via the register) and telemetry is only for analytics, not operational.

## Open Questions

- Should hierarchy resolution be modal (all levels must exist) or lenient (infer as far as possible)? → Decision: Lenient, with a console warning for missing parent levels, so dashboards with partial hierarchies still work.
- Is 300 ms the right responsiveness target? → Decision: Confirmed via testing on production OR cluster with typical network latencies; 250 ms would be unachievable, 400 ms feels sluggish.
- Should users be able to pin their own clauses, or only org admins? → Decision: Org admins only (via dashboard config). Users can save named views instead for personal filter preferences.
