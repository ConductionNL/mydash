# Drill-down Cross-widget Filter

Cross-widget filtering closes the analytical workflow gap where each widget operates as a standalone island. Today, clicking a bar in one chart does nothing, forcing users to manually re-enter the same filter values into three different widgets, leading to inconsistencies and inefficiency. This change introduces a shared filter bus per dashboard that allows any widget to publish filter events (on click) and any widget to consume them (on render), enabling seamless drill-down exploration where all widgets stay synchronized through a single source of truth: the URL.

The filter bus is built around five core principles. First, filter state is persisted in the URL as a bookmarkable, shareable snapshot — the same URL pasted elsewhere yields identical results. Second, widgets declare their publishing and consuming dimensions in their manifest, allowing the dashboard to validate compatibility statically and enable cross-widget interactions only where semantic links exist. Third, drill-down hierarchies are declarative, so clicking "Zeist" automatically narrows ancestor/descendant dimensions. Fourth, filter conflicts are resolved explicitly using last-write-wins per dimension, not silent merges, with audit tooltips so users understand what changed. Fifth, a single "Reset filters" action restores the unfiltered state, including the URL, ensuring users never get stranded.

## Affected code units

- `src/components/Dashboard.vue` — hosts the filter bus, renders active-filters panel, exposes "Reset filters" action
- `src/composables/useFilterBus.js` (new) — pub/sub implementation, dimension compatibility checking, hierarchy traversal
- `src/composables/useDashboardFilter.js` (new) — filter state serialization, URL encoding/decoding (base64url compact JSON)
- `src/components/widgets/ChartWidget.vue` + table widgets — accept `filterContext` prop (already passed for share-links), emit click events with dimension + value
- `lib/Register/DashboardFilterStateRegister.php` (new) — persists saved views and filter telemetry events
- Manifest schema: `widget.manifest.json` adds `publishes[]`, `consumes[]`, `hierarchies[]` fields per ADR-005
- Adds new capability `cross-widget-filter` with requirements REQ-CWF-001 through REQ-CWF-010
- Modifies dashboard routing to inject filter state on initial load (REQ-CWF-003)

## Why a new capability

Filter-driven collaboration between widgets is a top-level analytical feature, not an internal refactor. It touches the widget manifest schema, the dashboard host render, the OpenRegister integration layer, and persistence tier. Owning it as its own capability gives:

1. A clear contract of which dashboard dimensions participate in filtering
2. Audit-ready telemetry for understanding which drill-down paths users actually exercise
3. A testable boundary: widgets can be tested for filter conformance in isolation
4. Bookmarkable, reproducible views via URL — auditable for compliance scenarios

## Approach

- **Filter bus** — thin pub/sub layer in the dashboard host. Widgets publish `{dimension, operator, value, sourceWidgetId}`. The bus applies last-write-wins conflict resolution and broadcasts to consuming widgets.
- **Manifest integration** — widget manifests declare `publishes: [{dimension, operator, fromField}]` and `consumes: [{dimension, operator, intoFilter}]`. The dashboard validates at render time.
- **Hierarchy support** — dash manifest declares `hierarchies: [{id, levels: [{dimension, parentDimension, label}]}]`. When a wijk-level click arrives, the bus infers ancestor clauses (`source=parentScope`) and clears descendants.
- **URL persistence** — filter state serialized to `?f=<base64url>` parameter using compact JSON (`{d, o, v, s, w}` keys). Restores on page load with `source=urlBootstrap`.
- **Saved views** — users with edit permission save the current filter set as a named view in the register; the dropdown applies them with one click, replacing non-pinned clauses.
- **Telemetry** — org-level opt-in flag. `filter_event` rows record apply/reset/bookmark/share events, dimension paths, and widget IDs (never filter values). Aggregate report shows most-used drill-down paths.
- **Conflict resolution** — new clause replacing an existing dimension shows a one-line audit toast (e.g., "Gemeente filter changed: Zeist → Houten"). Pinned clauses are immutable; replacements are silently dropped.
- **Reset action** — single button clears all non-pinned clauses and removes the `f` URL parameter.

## Capabilities

**New Capabilities:**

- `cross-widget-filter` (adds REQ-CWF-001 through REQ-CWF-010)

## Notes

- The filter bus is opt-in per widget — widgets without `publishes`/`consumes` declarations continue to render with static filter context and do not participate.
- Existing widgets that already accept `filterContext` for share-links become instant bus participants with zero code change — they just need manifest updates.
- The bus translates clauses to OpenRegister `_filter` query parameters, reusing the existing index infrastructure; no new query engines.
- Hierarchy resolution (inferring ancestors from a child-level click) uses parent-relationships stable in the Dutch administrative model (wijk's first 4 digits are gemeentecode; gemeentecode's first 2 digits are provincecode).
- Filter values are never stored in telemetry — only operators, dimensions, and paths — for GDPR/privacy-by-design compliance (NEN-ISO 27001:2022 control 5.34).
