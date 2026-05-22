---
status: draft
---
# Drill-down Cross-widget Filter

## Placement & Information Architecture

**Placement type:** `WIDGET` — Widget shown on a dashboard or another page. Has no dedicated page of its own; renders inside an existing surface as a tile/panel/card.

**Lives at:** Dashboards / Canvas interaction

**Rationale:** Inline canvas feature  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

mydash dashboards today render each widget as a standalone island: a chart pulls its own data from OpenRegister with its own filter context, and a click on a bar in that chart either does nothing or, at best, drops the user into a list view detached from the dashboard. That breaks the most basic analytical workflow — "show me the bezwaren in Zeist, broken down by wijk; now show me only Vollenhove; now show me only the open ones older than six weeks". Every step of that workflow today requires the user to reopen filter modals on three different widgets, re-enter the same gemeente/wijk/age values, and reconcile the inevitable inconsistencies when one widget interprets "older than six weeks" differently from the next. Drill-down Cross-widget Filter closes that gap by giving the dashboard a shared filter bus that any widget can publish to and any widget can subscribe to.

The capability is built around five ideas. First, the filter bus is per-dashboard and persists in the URL so a filtered dashboard view is bookmarkable, shareable, and reproducible — the same URL pasted into a colleague's browser yields the same numbers. Second, widgets advertise the dimensions they can publish (when clicked) and the dimensions they can consume (when filtered) in their manifest, so the dashboard can detect compatibility statically and only enable cross-widget clicks where a real semantic link exists. Third, drill-down hierarchies (gemeente → wijk → buurt → straat; year → quarter → month → week) are declarative so a click on "Zeist" doesn't just pin `gemeente=Zeist` but also narrows the wijk-aggregation widget to Zeist's wijken. Fourth, filter conflicts are resolved with explicit rules (last-write-wins per dimension, with a UI affordance to see the full active filter stack) rather than silent merges that produce subtly wrong numbers. Fifth, a single "Reset filters" action restores the dashboard to its unfiltered state, including clearing the URL fragment, so users never get stranded in an unrecoverable filter combination.

The feature layers on top of the existing widget infrastructure without requiring widgets to opt in: an unaware widget continues to render with its statically-configured filter context and simply does not participate in the bus. Widgets opt in by declaring their compatible dimensions in their manifest and consuming the `filterContext` prop they already receive — most existing chart and table widgets already accept a filter prop for share-link parameters and so become bus participants with no widget-code change at all. The bus is a thin pub/sub layer in the dashboard host, not a new query engine; the actual filtering happens at the OpenRegister query layer the widget already calls, so a filter publication translates to an OR `_filter` query parameter and respects the same indexes the widget already uses.

## Data Model

A `dashboard_filter_state` object holds the active filter set for a dashboard view. It is not persisted as a register row in the steady state — the URL is the system of record — but the same shape is used for the "Saved view" feature (REQ-CWF-009) which DOES persist. Fields: `id` (UUID, only set for saved views), `dashboardId` (UUID, references a dashboard), `name` (string, only set for saved views), `filters` (array of filter-clause objects), `appliedAt` (ISO timestamp, for telemetry).

A `filter_clause` object describes one active filter. Fields: `dimension` (string, e.g. `gemeenteCode`, `wijkCode`, `period.month`), `operator` (enum: `eq`, `in`, `between`, `gte`, `lte`, `contains`, `notNull`), `value` (scalar, array, or `[low, high]` tuple depending on operator), `source` (enum: `userExplicit`, `widgetClick`, `urlBootstrap`, `savedView`, `parentScope`), `sourceWidgetId` (UUID, set when `source=widgetClick`), `pinned` (boolean — pinned clauses survive "Reset filters" so an org-scope clause like "current-user's gemeente" sticks).

A `dimension_descriptor` lives in the widget manifest and declares what a widget can publish and consume. Per widget: `publishes` (array of `{dimension, operator, fromField}` — describes what a click on a particular column/series/cell publishes), `consumes` (array of `{dimension, operator, intoFilter}` — describes which incoming dimensions narrow this widget's query), `hierarchies` (array of hierarchy descriptors used for drill-down). A typical chart widget publishes `{dimension: "gemeenteCode", operator: "eq", fromField: "category"}` and consumes the same.

A `hierarchy_descriptor` declares a drill-down chain. Fields: `id` (string, e.g. `nl-administrative`), `levels` (ordered array: `[{dimension: "provincieCode", label: "Provincie"}, {dimension: "gemeenteCode", label: "Gemeente", parentDimension: "provincieCode"}, {dimension: "wijkCode", label: "Wijk", parentDimension: "gemeenteCode"}, {dimension: "buurtCode", label: "Buurt", parentDimension: "wijkCode"}]`). When the bus receives a click on a level, all descendant filters are cleared and ancestor filters are kept; the breadcrumb UI reflects the active level.

A `saved_view` register object persists a named filter set. Fields: `id`, `dashboardId`, `name`, `description`, `filters` (array of filter-clause objects), `isShared` (boolean), `sharedWith` (array of user/group ids), `createdBy`, `createdAt`. Saved views are listed in a dropdown on the dashboard header and a click on one applies its filter set in full.

A `filter_event` object is written when an opt-in dashboard has filter telemetry enabled (org-level setting, default off). Fields: `id`, `dashboardId`, `userId`, `widgetId` (nullable, set for widget-click events), `eventType` (enum: `apply`, `reset`, `bookmark`, `share`), `filterDelta` (the clauses added/removed by this event), `timestamp`. Used for understanding which drill-downs users actually exercise and tuning hierarchies.

Filter state encoding in the URL uses a query parameter `?f=<base64url-encoded-compact-json>` to keep dashboards bookmark-safe; the compact-JSON shape uses short keys (`d` for dimension, `o` for operator, `v` for value, `s` for source) to stay under typical 2 KB URL limits even with a dozen active clauses.

## Requirements

### REQ-CWF-001 — Widget click publishes to the filter bus

The dashboard SHALL detect clicks on chartable elements of bus-aware widgets and SHALL publish the corresponding filter clause to the bus, identifying the publishing widget so the click can be visually echoed back.

- GIVEN a bar chart widget A on a dashboard, with a `publishes` declaration of `{dimension: "gemeenteCode", operator: "eq", fromField: "category"}`
  WHEN the user clicks the bar labelled "Zeist" (gemeenteCode `GM0355`)
  THEN a filter clause `{dimension: "gemeenteCode", operator: "eq", value: "GM0355", source: "widgetClick", sourceWidgetId: A}` SHALL be added to the bus.
- GIVEN a widget WITHOUT a `publishes` declaration
  WHEN the user clicks an element
  THEN no clause SHALL be added to the bus and the click MAY perform a widget-local action (e.g. open detail modal).
- GIVEN the same bar is clicked a second time
  WHEN the bus already contains that exact clause sourced from the same widget
  THEN the clause SHALL be removed (toggle behaviour) and the URL SHALL be updated to reflect the cleared state.

### REQ-CWF-002 — All consuming widgets re-render on filter change

The system SHALL recompute and re-render every widget whose `consumes` declaration matches one or more active filter dimensions, passing the merged filter context as a prop, within 300 ms of the click.

- GIVEN three widgets on a dashboard: A (publishes gemeenteCode), B (consumes gemeenteCode), C (does not consume gemeenteCode)
  WHEN the user clicks "Zeist" on A
  THEN A SHALL echo the selection visually, B SHALL re-render with `gemeenteCode=GM0355` applied, and C SHALL NOT re-render.
- GIVEN a widget whose `consumes` declaration includes a dimension currently in the bus
  WHEN the widget mounts (or is dragged onto the dashboard)
  THEN it SHALL receive the current bus state on first render and SHALL NOT show a flash of unfiltered content.

### REQ-CWF-003 — URL encoding makes filtered views bookmarkable

The system SHALL serialise the active filter state into a URL query parameter and SHALL restore the filter state on initial load when the parameter is present, producing the same view the URL-issuer saw.

- GIVEN a dashboard with two active clauses (`gemeenteCode=GM0355`, `status=open`)
  WHEN the URL is copied
  THEN it SHALL contain a `?f=...` parameter encoding both clauses.
- GIVEN that URL is pasted into a second browser by a second user with read access to the dashboard
  WHEN the page loads
  THEN both clauses SHALL be restored with `source=urlBootstrap` and the visible widgets SHALL reflect the filtered numbers without any user action.
- GIVEN a URL whose `f` parameter is malformed
  WHEN the page loads
  THEN the dashboard SHALL render with an empty filter state and SHALL log a single warning (no error toast on the user).

### REQ-CWF-004 — Drill-down hierarchies update breadcrumb and descendant filters

When a click publishes a clause for a dimension that is part of a `hierarchy_descriptor`, the system SHALL update the breadcrumb UI to show the active path and SHALL clear any descendant-level clauses from the bus before applying the new clause.

- GIVEN the `nl-administrative` hierarchy is active on a dashboard, with current clauses `gemeenteCode=GM0355` and `wijkCode=WK0355001`
  WHEN the user clicks "Provincie Utrecht" on a province-level widget (publishing `provincieCode=PV09`)
  THEN the bus SHALL contain only `{provincieCode=PV09}` (gemeente and wijk are cleared because they are descendants) and the breadcrumb SHALL show "Provincie Utrecht".
- GIVEN the same dashboard with no active filters
  WHEN the user clicks a wijk on a wijk-level widget (publishing `wijkCode=WK0355001`)
  THEN the bus SHALL contain `{wijkCode=WK0355001}` AND the hierarchy resolver SHALL infer and add ancestor clauses (`gemeenteCode=GM0355`, `provincieCode=PV09`) marked with `source=parentScope` so the breadcrumb shows the full path.
- GIVEN a click on a hierarchy level the user has no permission for (e.g. a gemeenteCode their role doesn't expose)
  WHEN the click is processed
  THEN the publication SHALL be rejected, the widget SHALL show a tooltip explaining "no access to this scope", and the bus SHALL NOT be mutated.

### REQ-CWF-005 — Filter conflict resolution is last-write-wins per dimension

When a new clause is published for a dimension that already has an active clause from a different source or operator, the system SHALL replace (not append) the existing clause and SHALL show a one-line audit tooltip indicating which previous clause was replaced.

- GIVEN an active clause `{gemeenteCode=GM0355, source=widgetClick}` from widget A
  WHEN widget B publishes `{gemeenteCode=GM0344, source=widgetClick}`
  THEN the bus SHALL contain only the new clause from B, widget A SHALL update its visual echo to deselect GM0355, and a brief toast SHALL indicate "Gemeente filter changed from Zeist to Houten".
- GIVEN an active clause sourced from `urlBootstrap`
  WHEN a widget-click clause for the same dimension arrives
  THEN the widget-click clause SHALL replace the URL-bootstrap one with no toast (URL bootstrap is treated as a transient initial state, not a deliberate user choice).
- GIVEN an active `pinned: true` clause
  WHEN a clause for the same dimension arrives from any source
  THEN the pinned clause SHALL NOT be replaced and the new publication SHALL be silently dropped with a one-line console log (so a developer can see why their click did not stick).

### REQ-CWF-006 — Reset filters clears all non-pinned clauses

The dashboard SHALL expose a "Reset filters" action that clears every non-pinned clause from the bus and SHALL update the URL to remove the `f` parameter.

- GIVEN a dashboard with five active clauses, one of which is pinned
  WHEN the user invokes "Reset filters"
  THEN four clauses SHALL be removed, the pinned clause SHALL remain, the URL SHALL be updated, and every consuming widget SHALL re-render with the reduced filter context.
- GIVEN a dashboard with no active clauses
  WHEN "Reset filters" is invoked
  THEN the action SHALL be a no-op (no URL change, no widget re-render, no telemetry event) so accidental double-clicks do not produce noise.

### REQ-CWF-007 — Filter stack UI shows every active clause with its source

The dashboard SHALL render a compact "Active filters" panel listing every active clause with its dimension label, value, source widget (when applicable), and an individual remove-action; the panel SHALL collapse when empty.

- GIVEN three active clauses (one from a widget click, one from URL bootstrap, one pinned by the org)
  WHEN the panel is rendered
  THEN it SHALL show three chips with distinct visual treatment per source (e.g. widget-click chips show the source widget's name, pinned chips show a lock icon and have no remove-action).
- GIVEN the user clicks the remove-action on a single chip
  WHEN the click is processed
  THEN only that clause SHALL be removed, the URL SHALL be updated, and the originating widget (if any) SHALL update its visual echo to deselect.

### REQ-CWF-008 — Widget manifest declares publishes/consumes; dashboard validates compatibility

The widget loader SHALL read `publishes` and `consumes` from the widget manifest and the dashboard SHALL refuse to render a widget whose manifest declares dimensions inconsistent with the dashboard's hierarchy descriptors, surfacing the mismatch in the widget config UI.

- GIVEN a widget manifest declaring `consumes: [{dimension: "wijkCode"}]` on a dashboard whose hierarchy descriptors do not include `wijkCode`
  WHEN the widget is added to the dashboard
  THEN the widget config UI SHALL show a non-fatal warning ("This widget consumes 'wijkCode' but no hierarchy on this dashboard publishes it") and the widget SHALL render as a non-bus-participant.
- GIVEN a widget manifest declaring `publishes: [{dimension: "gemeenteCode", fromField: "missingField"}]` where `missingField` is not in the widget's data shape
  WHEN the widget renders
  THEN the dashboard SHALL log a manifest-validation error and disable bus participation for that widget (no clicks publish).

### REQ-CWF-009 — Saved views persist named filter combinations

The system SHALL allow users with edit permission on a dashboard to save the current filter state as a named view, list saved views in a dashboard-header dropdown, and apply a saved view with one click.

- GIVEN a dashboard with three active clauses
  WHEN the user invokes "Save view" and provides the name "Open bezwaren Zeist 6w+"
  THEN a `saved_view` row SHALL be persisted with those three clauses, scoped to that dashboard, and the saved view SHALL appear in the header dropdown.
- GIVEN a saved view with five clauses
  WHEN a user with view permission on the dashboard selects it from the dropdown
  THEN all current non-pinned clauses SHALL be replaced with the saved view's clauses and the URL SHALL update accordingly.
- GIVEN a saved view marked `isShared: true`
  WHEN a different user with view permission opens the dashboard
  THEN the saved view SHALL appear in their dropdown (under a "Shared" section) and SHALL be applicable but not editable by them.

### REQ-CWF-010 — Telemetry of filter usage is opt-in and aggregated

When org-level telemetry is enabled, the system SHALL persist `filter_event` rows for `apply` / `reset` / `bookmark` / `share` events with no PII beyond the user id, and SHALL expose an aggregate report ("most-used drill-down paths per dashboard") to dashboard owners.

- GIVEN telemetry is disabled (default)
  WHEN any filter event occurs
  THEN no `filter_event` row SHALL be written.
- GIVEN telemetry is enabled and a user clicks a wijk on widget A
  WHEN the click is processed
  THEN a `filter_event` row SHALL be written with `eventType=apply`, the wijk clause delta, `widgetId=A`, and the user id, AND the URL/share event SHALL NOT be conflated with the click event (two separate rows).
- GIVEN telemetry is enabled and an authenticated user opens a bookmarked URL
  WHEN the page loads
  THEN exactly one row SHALL be written with `eventType=bookmark` and the full clause set as the delta.

## Standards & Sources

The publish/consume contract follows the same shape as ObservableHQ's reactive dashboards and Apache Superset's cross-filter pattern — both have converged on the same answer ("widgets declare what they emit and what they accept; the host wires them up via a shared filter state") because alternative designs (event-bus with implicit subscription, query rewriting at the database layer) either silently break when a widget's data shape changes or require central knowledge of every widget's SQL. Tableau's "Use as filter" action and Power BI's cross-filter behaviour are conceptually identical and the manifest field names (`publishes`, `consumes`) were chosen to be readable to operators coming from either platform without translating jargon. Bookmarking via URL parameter follows what Kibana, Grafana, and Metabase all do, and the base64url-compact-JSON encoding strategy is the same one Grafana uses for its `var-` parameters when the parameter set grows beyond a handful — we skip Grafana's per-parameter encoding (which produces unreadable URLs) and pick a single compact-JSON blob instead, on the assumption that humans read the dashboard, not the URL.

The hierarchy model is anchored in the Dutch government's official administrative geocoding: CBS gemeentecodes (GM####), wijkcodes (WK########), and buurtcodes (BU##########) follow CBS's hierarchical scheme and the parent-relationships are stable references (a wijk's first four digits are always its gemeenteCode's four trailing digits). For temporal hierarchies (year → quarter → month → week → day), ISO 8601 week numbering (ISO-8601:2019) is the only sane choice — calendars that use US-style "week 1 contains January 1" produce off-by-one errors at year boundaries that have plagued every BI tool that didn't pick a standard. NUTS codes are supported as an alternative hierarchy for cross-border / EU-context dashboards (NUTS-1 → NUTS-2 → NUTS-3) following the EU's stable 2024 NUTS classification. Hierarchy descriptors are declarative JSON, not code, so an organisation with a custom hierarchy (e.g. an internal product taxonomy) can register one without forking mydash.

Filter-state encoding in the URL follows the same compact-JSON-in-base64url pattern used by JWT, OAuth2 PKCE, and (for this exact purpose) Grafana's dashboard variables — base64url is chosen over base64 because the standard base64 alphabet includes `+`, `/`, and `=` which require percent-encoding in URLs and produce unreadable, fragile strings. The URL-length guidance (stay under 2 KB) is conservative based on browser and proxy limits documented in the URL Living Standard and confirmed against IIS / nginx default configurations; dashboards that habitually exceed this length are an indicator the dashboard is doing too much and should be split, but the system does not enforce a hard limit because the failure mode (URL too long) is well-understood and self-evident.

Conflict resolution as "last-write-wins per dimension with explicit replacement and audit tooltip" is informed by the operational-experience literature on collaborative editing systems: silent merges produce bugs that are nearly impossible to diagnose ("why is my widget showing GM0355 when I clicked GM0344?"), while explicit replacement with a brief audit affords the user the visibility to understand and undo. The pinned-clause concept maps to what Looker calls "locked filters" and what Power BI calls "filters on this report" — pinning is the mechanism by which an org-level scope (e.g. "this dashboard always filters to the current user's gemeente") survives the user's drill-down exploration.

The performance bound (300 ms per filter change) is informed by Jakob Nielsen's classic responsiveness thresholds (the 100 ms / 1 s / 10 s rule): below 100 ms feels instantaneous (rarely achievable when re-querying a backend), 100 ms to 1 s feels like a direct response, beyond 1 s the user starts to lose flow. 300 ms is the midpoint that mydash's widget infra can hit consistently when filters narrow rather than broaden the query (which is the common drill-down case); widget-level loading indicators kick in for the rare cases that take longer.

Telemetry is opt-in and intentionally minimal — `filter_event` rows record dimensions and operators but not the actual values, because filter values frequently are PII (a single-row filter is effectively a user lookup). The aggregate report queries paths and frequencies, never user-attributable filter sets. This aligns with NEN-ISO 27001:2022 control 5.34 (privacy by design) and with the GDPR/AVG principle of data minimisation — the only telemetry retained is what is necessary to make hierarchy tuning decisions.

## Cross-app integration

- **openregister**: the filter bus translates active clauses into OpenRegister `_filter` query parameters at widget query time, so a bus clause `{gemeenteCode=GM0355}` becomes a `_filter[gemeenteCode]=GM0355` on every consuming widget's OR query. This reuses OR's existing index strategy (composite indexes on `(register, schema, attribute_value)` from openregister#1234) without any new query plan. Saved views are persisted as register objects in the mydash register.
- **opencatalogi**: dashboards that reference catalog objects (e.g. a dashboard of softwareproducten in opencatalogi) inherit the catalog's facet vocabulary as additional hierarchy descriptors, so a click on "Categorie: Werkprocessen" on a catalog widget filters the rest of the dashboard correctly without manual descriptor wiring.
- **openconnector**: when a widget's data source is an OpenConnector call (rather than direct OR), the bus clauses are passed to OC as request parameters using the source's configured parameter-mapping; OC sources without parameter-mapping for a dimension are not bus-aware and the dashboard surfaces this in the widget config UI.
- **docudesk**: drill-down into a document-bearing object (e.g. clicking a zaak on a casework widget) MAY publish a `docudeskScope` clause that narrows a docudesk document-preview widget to that zaak's documents; opt-in via widget config.
- **mydash scheduled-exports**: a recipient's `filterContext` on a scheduled export reuses the same clause shape as the bus, so a user can "save the current filtered view as a weekly email" without re-entering values.
- **AI Chat Companion (ADR-034)**: the chat companion MAY apply filters by publishing clauses to the bus ("drill into Zeist"), and MAY answer questions about the active filter state by reading the bus directly.

## Target users

- **Data analysts / dashboard authors** declare publishes/consumes/hierarchies on their widgets and dashboards. They are the audience for the manifest validation warnings and the telemetry report; they tune hierarchies based on which drill-down paths users actually exercise.
- **Operational users (caseworkers, managers)** are the primary consumers — they click through dashboards conversationally, drill from gemeente to wijk to buurt, and rely on the bus to keep the rest of the dashboard in sync. The "Active filters" panel is their safety net so they can always see what they have applied and undo.
- **Executive / wethouder consumers** receive bookmarked URLs from analysts ("open this link to see the September gemeente-Zeist view") and rely on the URL bookmarking to reproduce exactly the view the analyst prepared. They rarely click to drill but they read the breadcrumb to orient.
- **Org administrators** configure pinned clauses (e.g. "always scope this dashboard to the current user's gemeente") and toggle telemetry at the org level. They are the audience for the aggregate "most-used drill-down paths" report and use it to retire unused widgets and promote popular drill-down patterns.
- **Compliance reviewers** open bookmarked URLs to reproduce a numbers-claim made in a report or council document — the URL-as-system-of-record property is what makes a dashboard claim auditable months after the fact.
