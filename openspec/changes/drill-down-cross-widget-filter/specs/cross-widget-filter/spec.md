---
capability: cross-widget-filter
status: draft
---

# Cross-Widget Filter — New capability from change `drill-down-cross-widget-filter`

## Context

This capability adds a shared filter bus to mydash dashboards, allowing widgets to publish filter events (on click) and other widgets to consume them (on render). Filter state is persisted in the URL, made available via saved views, and optionally logged for aggregate telemetry.

## NEW Requirements

### Requirement: REQ-CWF-001 Widget click publishes to the filter bus

The dashboard SHALL detect clicks on chartable elements of bus-aware widgets and SHALL publish the corresponding filter clause to the bus, identifying the publishing widget so the click can be visually echoed back.

#### Scenario: Bar chart click publishes a clause

- **GIVEN** a bar chart widget A on a dashboard, with a `publishes` declaration of `{dimension: "gemeenteCode", operator: "eq", fromField: "category"}`
- **WHEN** the user clicks the bar labelled "Zeist" (gemeenteCode `GM0355`)
- **THEN** a filter clause `{dimension: "gemeenteCode", operator: "eq", value: "GM0355", source: "widgetClick", sourceWidgetId: A}` SHALL be added to the bus

#### Scenario: Widget without publishes declaration does nothing

- **GIVEN** a widget WITHOUT a `publishes` declaration
- **WHEN** the user clicks an element
- **THEN** no clause SHALL be added to the bus and the click MAY perform a widget-local action (e.g. open detail modal)

#### Scenario: Clicking the same clause again removes it (toggle)

- **GIVEN** the same bar is clicked a second time
- **WHEN** the bus already contains that exact clause sourced from the same widget
- **THEN** the clause SHALL be removed (toggle behaviour) and the URL SHALL be updated to reflect the cleared state

---

### Requirement: REQ-CWF-002 All consuming widgets re-render on filter change

The system SHALL recompute and re-render every widget whose `consumes` declaration matches one or more active filter dimensions, passing the merged filter context as a prop, within 300 ms of the click.

#### Scenario: Selective widget re-render

- **GIVEN** three widgets on a dashboard: A (publishes gemeenteCode), B (consumes gemeenteCode), C (does not consume gemeenteCode)
- **WHEN** the user clicks "Zeist" on A
- **THEN** A SHALL echo the selection visually, B SHALL re-render with `gemeenteCode=GM0355` applied, and C SHALL NOT re-render

#### Scenario: New widget receives current filter state on mount

- **GIVEN** a widget whose `consumes` declaration includes a dimension currently in the bus
- **WHEN** the widget mounts (or is dragged onto the dashboard)
- **THEN** it SHALL receive the current bus state on first render and SHALL NOT show a flash of unfiltered content

---

### Requirement: REQ-CWF-003 URL encoding makes filtered views bookmarkable

The system SHALL serialise the active filter state into a URL query parameter and SHALL restore the filter state on initial load when the parameter is present, producing the same view the URL-issuer saw.

#### Scenario: URL encodes active filter state

- **GIVEN** a dashboard with two active clauses (`gemeenteCode=GM0355`, `status=open`)
- **WHEN** the URL is copied
- **THEN** it SHALL contain a `?f=...` parameter encoding both clauses

#### Scenario: Loading a bookmarked URL restores filter state

- **GIVEN** that URL is pasted into a second browser by a second user with read access to the dashboard
- **WHEN** the page loads
- **THEN** both clauses SHALL be restored with `source=urlBootstrap` and the visible widgets SHALL reflect the filtered numbers without any user action

#### Scenario: Malformed URL parameter does not crash

- **GIVEN** a URL whose `f` parameter is malformed
- **WHEN** the page loads
- **THEN** the dashboard SHALL render with an empty filter state and SHALL log a single warning (no error toast on the user)

---

### Requirement: REQ-CWF-004 Drill-down hierarchies update breadcrumb and descendant filters

When a click publishes a clause for a dimension that is part of a `hierarchy_descriptor`, the system SHALL update the breadcrumb UI to show the active path and SHALL clear any descendant-level clauses from the bus before applying the new clause.

#### Scenario: Drilling up clears descendant filters

- **GIVEN** the `nl-administrative` hierarchy is active on a dashboard, with current clauses `gemeenteCode=GM0355` and `wijkCode=WK0355001`
- **WHEN** the user clicks "Provincie Utrecht" on a province-level widget (publishing `provincieCode=PV09`)
- **THEN** the bus SHALL contain only `{provincieCode=PV09}` (gemeente and wijk are cleared because they are descendants) and the breadcrumb SHALL show "Provincie Utrecht"

#### Scenario: Drilling down infers ancestor filters

- **GIVEN** the same dashboard with no active filters
- **WHEN** the user clicks a wijk on a wijk-level widget (publishing `wijkCode=WK0355001`)
- **THEN** the bus SHALL contain `{wijkCode=WK0355001}` AND the hierarchy resolver SHALL infer and add ancestor clauses (`gemeenteCode=GM0355`, `provincieCode=PV09`) marked with `source=parentScope` so the breadcrumb shows the full path

#### Scenario: Permission-denied on hierarchy level

- **GIVEN** a click on a hierarchy level the user has no permission for (e.g. a gemeenteCode their role doesn't expose)
- **WHEN** the click is processed
- **THEN** the publication SHALL be rejected, the widget SHALL show a tooltip explaining "no access to this scope", and the bus SHALL NOT be mutated

---

### Requirement: REQ-CWF-005 Filter conflict resolution is last-write-wins per dimension

When a new clause is published for a dimension that already has an active clause from a different source or operator, the system SHALL replace (not append) the existing clause and SHALL show a one-line audit tooltip indicating which previous clause was replaced.

#### Scenario: Widget-click replaces widget-click

- **GIVEN** an active clause `{gemeenteCode=GM0355, source=widgetClick}` from widget A
- **WHEN** widget B publishes `{gemeenteCode=GM0344, source=widgetClick}`
- **THEN** the bus SHALL contain only the new clause from B, widget A SHALL update its visual echo to deselect GM0355, and a brief toast SHALL indicate "Gemeente filter changed from Zeist to Houten"

#### Scenario: Widget-click silently replaces URL-bootstrap

- **GIVEN** an active clause sourced from `urlBootstrap`
- **WHEN** a widget-click clause for the same dimension arrives
- **THEN** the widget-click clause SHALL replace the URL-bootstrap one with no toast (URL bootstrap is treated as a transient initial state, not a deliberate user choice)

#### Scenario: Pinned clause cannot be replaced

- **GIVEN** an active `pinned: true` clause
- **WHEN** a clause for the same dimension arrives from any source
- **THEN** the pinned clause SHALL NOT be replaced and the new publication SHALL be silently dropped with a one-line console log (so a developer can see why their click did not stick)

---

### Requirement: REQ-CWF-006 Reset filters clears all non-pinned clauses

The dashboard SHALL expose a "Reset filters" action that clears every non-pinned clause from the bus and SHALL update the URL to remove the `f` parameter.

#### Scenario: Reset clears non-pinned clauses, keeps pinned

- **GIVEN** a dashboard with five active clauses, one of which is pinned
- **WHEN** the user invokes "Reset filters"
- **THEN** four clauses SHALL be removed, the pinned clause SHALL remain, the URL SHALL be updated, and every consuming widget SHALL re-render with the reduced filter context

#### Scenario: Reset on empty bus is a no-op

- **GIVEN** a dashboard with no active clauses
- **WHEN** "Reset filters" is invoked
- **THEN** the action SHALL be a no-op (no URL change, no widget re-render, no telemetry event) so accidental double-clicks do not produce noise

---

### Requirement: REQ-CWF-007 Filter stack UI shows every active clause with its source

The dashboard SHALL render a compact "Active filters" panel listing every active clause with its dimension label, value, source widget (when applicable), and an individual remove-action; the panel SHALL collapse when empty.

#### Scenario: Active filters panel shows all clauses with source

- **GIVEN** three active clauses (one from a widget click, one from URL bootstrap, one pinned by the org)
- **WHEN** the panel is rendered
- **THEN** it SHALL show three chips with distinct visual treatment per source (e.g. widget-click chips show the source widget's name, pinned chips show a lock icon and have no remove-action)

#### Scenario: Remove single clause from panel

- **GIVEN** the user clicks the remove-action on a single chip
- **WHEN** the click is processed
- **THEN** only that clause SHALL be removed, the URL SHALL be updated, and the originating widget (if any) SHALL update its visual echo to deselect

---

### Requirement: REQ-CWF-008 Widget manifest declares publishes/consumes; dashboard validates compatibility

The widget loader SHALL read `publishes` and `consumes` from the widget manifest and the dashboard SHALL refuse to render a widget whose manifest declares dimensions inconsistent with the dashboard's hierarchy descriptors, surfacing the mismatch in the widget config UI.

#### Scenario: Consuming dimension not in dashboard hierarchies

- **GIVEN** a widget manifest declaring `consumes: [{dimension: "wijkCode"}]` on a dashboard whose hierarchy descriptors do not include `wijkCode`
- **WHEN** the widget is added to the dashboard
- **THEN** the widget config UI SHALL show a non-fatal warning ("This widget consumes 'wijkCode' but no hierarchy on this dashboard publishes it") and the widget SHALL render as a non-bus-participant

#### Scenario: Publishing from missing field

- **GIVEN** a widget manifest declaring `publishes: [{dimension: "gemeenteCode", fromField: "missingField"}]` where `missingField` is not in the widget's data shape
- **WHEN** the widget renders
- **THEN** the dashboard SHALL log a manifest-validation error and disable bus participation for that widget (no clicks publish)

---

### Requirement: REQ-CWF-009 Saved views persist named filter combinations

The system SHALL allow users with edit permission on a dashboard to save the current filter state as a named view, list saved views in a dashboard-header dropdown, and apply a saved view with one click.

#### Scenario: Save current filter state as named view

- **GIVEN** a dashboard with three active clauses
- **WHEN** the user invokes "Save view" and provides the name "Open bezwaren Zeist 6w+"
- **THEN** a `saved_view` row SHALL be persisted with those three clauses, scoped to that dashboard, and the saved view SHALL appear in the header dropdown

#### Scenario: Apply saved view

- **GIVEN** a saved view with five clauses
- **WHEN** a user with view permission on the dashboard selects it from the dropdown
- **THEN** all current non-pinned clauses SHALL be replaced with the saved view's clauses and the URL SHALL update accordingly

#### Scenario: Shared saved views appear for other users

- **GIVEN** a saved view marked `isShared: true`
- **WHEN** a different user with view permission opens the dashboard
- **THEN** the saved view SHALL appear in their dropdown (under a "Shared" section) and SHALL be applicable but not editable by them

---

### Requirement: REQ-CWF-010 Telemetry of filter usage is opt-in and aggregated

When org-level telemetry is enabled, the system SHALL persist `filter_event` rows for `apply` / `reset` / `bookmark` / `share` events with no PII beyond the user id, and SHALL expose an aggregate report ("most-used drill-down paths per dashboard") to dashboard owners.

#### Scenario: Telemetry disabled (default)

- **GIVEN** telemetry is disabled (default)
- **WHEN** any filter event occurs
- **THEN** no `filter_event` row SHALL be written

#### Scenario: Filter apply event is recorded

- **GIVEN** telemetry is enabled and a user clicks a wijk on widget A
- **WHEN** the click is processed
- **THEN** a `filter_event` row SHALL be written with `eventType=apply`, the wijk clause delta, `widgetId=A`, and the user id, AND the URL/share event SHALL NOT be conflated with the click event (two separate rows)

#### Scenario: Bookmark event on page load

- **GIVEN** telemetry is enabled and an authenticated user opens a bookmarked URL
- **WHEN** the page loads
- **THEN** exactly one row SHALL be written with `eventType=bookmark` and the full clause set as the delta

---

## Data Model

### dashboard_filter_state

Holds the active filter set for a dashboard view. In steady state, the URL is the system of record; saved views persist this shape to the register.

- `id` (UUID, nullable) — set only for saved views
- `dashboardId` (UUID) — references a dashboard
- `name` (string, nullable) — set only for saved views
- `filters` (array of filter_clause objects) — active clauses
- `appliedAt` (ISO timestamp) — for telemetry

#### Seed Data

```json
{
  "dashboardId": "d1a2b3c4-5678-90ab-cdef-1234567890ab",
  "name": "Open bezwaren Zeist > 6w",
  "filters": [
    {
      "dimension": "gemeenteCode",
      "operator": "eq",
      "value": "GM0355",
      "source": "widgetClick",
      "sourceWidgetId": "w-chart-gemeenten"
    },
    {
      "dimension": "status",
      "operator": "eq",
      "value": "open",
      "source": "widgetClick",
      "sourceWidgetId": "w-chip-status"
    },
    {
      "dimension": "ageInDays",
      "operator": "gte",
      "value": 42,
      "source": "userExplicit",
      "pinned": true
    }
  ],
  "appliedAt": "2026-05-22T14:35:00Z"
}
```

### filter_clause

Describes one active filter. Keyed in compact form for URL encoding as `{d, o, v, s, w, p}`.

- `dimension` (string, e.g. `gemeenteCode`, `wijkCode`, `period.month`) — the filtered attribute
- `operator` (enum: `eq`, `in`, `between`, `gte`, `lte`, `contains`, `notNull`) — comparison operator
- `value` (scalar, array, or `[low, high]` tuple) — depends on operator; never stored in telemetry
- `source` (enum: `userExplicit`, `widgetClick`, `urlBootstrap`, `savedView`, `parentScope`) — origin
- `sourceWidgetId` (UUID, nullable) — set when `source=widgetClick`
- `pinned` (boolean, default false) — pinned clauses survive "Reset filters"

#### Seed Data

```json
[
  {
    "dimension": "gemeenteCode",
    "operator": "eq",
    "value": "GM0355",
    "source": "widgetClick",
    "sourceWidgetId": "w-chart-gemeenten"
  },
  {
    "dimension": "wijkCode",
    "operator": "in",
    "value": ["WK0355001", "WK0355002"],
    "source": "widgetClick",
    "sourceWidgetId": "w-map-wijken"
  },
  {
    "dimension": "ageInDays",
    "operator": "gte",
    "value": 42,
    "source": "userExplicit",
    "pinned": true
  }
]
```

### dimension_descriptor

Lives in the widget manifest and declares what a widget can publish and consume.

- `publishes` (array of objects) — describes what a click on a particular column/series/cell publishes
  - `dimension` (string) — the dimension published
  - `operator` (enum) — typically `eq` for categorical clicks, can be `between` for range selections
  - `fromField` (string) — the data field that supplies the value (e.g. `category`, `date`)
- `consumes` (array of objects) — describes which incoming dimensions narrow this widget's query
  - `dimension` (string) — the dimension consumed
  - `operator` (enum) — filtering operator the widget applies
  - `intoFilter` (string, nullable) — the parameter name to pass to the data source (e.g. `_filter[gemeenteCode]`)
- `hierarchies` (array of hierarchy_descriptor objects) — see below

#### Seed Data

```json
{
  "publishes": [
    {
      "dimension": "gemeenteCode",
      "operator": "eq",
      "fromField": "category"
    }
  ],
  "consumes": [
    {
      "dimension": "gemeenteCode",
      "operator": "eq",
      "intoFilter": "_filter[gemeenteCode]"
    },
    {
      "dimension": "status",
      "operator": "eq",
      "intoFilter": "_filter[status]"
    }
  ],
  "hierarchies": [
    {
      "id": "nl-administrative",
      "levels": [
        {
          "dimension": "provincieCode",
          "label": "Provincie"
        },
        {
          "dimension": "gemeenteCode",
          "label": "Gemeente",
          "parentDimension": "provincieCode"
        },
        {
          "dimension": "wijkCode",
          "label": "Wijk",
          "parentDimension": "gemeenteCode"
        }
      ]
    }
  ]
}
```

### hierarchy_descriptor

Declares a drill-down chain for inferring ancestor and descendant relationships.

- `id` (string, e.g. `nl-administrative`, `temporal-iso8601`) — unique identifier
- `levels` (ordered array) — hierarchy levels from broadest to finest
  - `dimension` (string) — the dimension at this level
  - `label` (string, i18n key) — human-readable label for breadcrumb
  - `parentDimension` (string, nullable) — the dimension that is the parent level

#### Seed Data

```json
[
  {
    "id": "nl-administrative",
    "levels": [
      {
        "dimension": "provincieCode",
        "label": "hierarchy.level.province",
        "parentDimension": null
      },
      {
        "dimension": "gemeenteCode",
        "label": "hierarchy.level.municipality",
        "parentDimension": "provincieCode"
      },
      {
        "dimension": "wijkCode",
        "label": "hierarchy.level.district",
        "parentDimension": "gemeenteCode"
      },
      {
        "dimension": "buurtCode",
        "label": "hierarchy.level.neighbourhood",
        "parentDimension": "wijkCode"
      }
    ]
  },
  {
    "id": "temporal-iso8601",
    "levels": [
      {
        "dimension": "period.year",
        "label": "hierarchy.level.year"
      },
      {
        "dimension": "period.quarter",
        "label": "hierarchy.level.quarter",
        "parentDimension": "period.year"
      },
      {
        "dimension": "period.month",
        "label": "hierarchy.level.month",
        "parentDimension": "period.quarter"
      },
      {
        "dimension": "period.week",
        "label": "hierarchy.level.week",
        "parentDimension": "period.month"
      }
    ]
  }
]
```

### saved_view

Persisted named filter combination, scoped to a dashboard.

- `id` (UUID) — primary key
- `dashboardId` (UUID) — references the dashboard
- `name` (string) — user-visible name
- `description` (string, nullable) — optional explanation
- `filters` (array of filter_clause objects) — the saved filter set
- `isShared` (boolean) — whether other users can apply this view
- `sharedWith` (array of user/group IDs, nullable) — explicit share list (used only if `isShared=true`)
- `createdBy` (UUID) — user who created this view
- `createdAt` (ISO timestamp) — creation timestamp

#### Seed Data

```json
[
  {
    "id": "sv-001",
    "dashboardId": "d1a2b3c4-5678-90ab-cdef-1234567890ab",
    "name": "Open bezwaren Zeist > 6w",
    "description": "All open complaints in Zeist older than 6 weeks",
    "filters": [
      {
        "dimension": "gemeenteCode",
        "operator": "eq",
        "value": "GM0355",
        "source": "savedView"
      },
      {
        "dimension": "status",
        "operator": "eq",
        "value": "open",
        "source": "savedView"
      },
      {
        "dimension": "ageInDays",
        "operator": "gte",
        "value": 42,
        "source": "savedView"
      }
    ],
    "isShared": true,
    "sharedWith": null,
    "createdBy": "u-analyst-01",
    "createdAt": "2026-05-15T10:00:00Z"
  },
  {
    "id": "sv-002",
    "dashboardId": "d1a2b3c4-5678-90ab-cdef-1234567890ab",
    "name": "2026 Q2 Trend by Wijk",
    "description": "Filter to 2026 Q2 aggregated by wijk within the user's gemeente",
    "filters": [
      {
        "dimension": "period.quarter",
        "operator": "eq",
        "value": "2026-Q2",
        "source": "savedView"
      }
    ],
    "isShared": false,
    "sharedWith": null,
    "createdBy": "u-manager-01",
    "createdAt": "2026-05-20T14:22:00Z"
  }
]
```

### filter_event

Telemetry row written when an opt-in dashboard has telemetry enabled (org-level setting, default off). Records dimension and operator changes, not filter values (which may be PII).

- `id` (UUID) — primary key
- `dashboardId` (UUID) — references the dashboard
- `userId` (UUID) — authenticated user
- `widgetId` (UUID, nullable) — set for widget-click events
- `eventType` (enum: `apply`, `reset`, `bookmark`, `share`) — event classification
- `filterDelta` (array of filter_clause objects) — the clauses added/removed by this event (no values)
- `timestamp` (ISO timestamp) — when the event occurred

#### Seed Data

```json
[
  {
    "id": "fe-001",
    "dashboardId": "d1a2b3c4-5678-90ab-cdef-1234567890ab",
    "userId": "u-caseworker-01",
    "widgetId": "w-chart-gemeenten",
    "eventType": "apply",
    "filterDelta": [
      {
        "dimension": "gemeenteCode",
        "operator": "eq",
        "source": "widgetClick",
        "sourceWidgetId": "w-chart-gemeenten"
      }
    ],
    "timestamp": "2026-05-22T14:35:00Z"
  },
  {
    "id": "fe-002",
    "dashboardId": "d1a2b3c4-5678-90ab-cdef-1234567890ab",
    "userId": "u-caseworker-01",
    "widgetId": "w-map-wijken",
    "eventType": "apply",
    "filterDelta": [
      {
        "dimension": "wijkCode",
        "operator": "eq",
        "source": "widgetClick",
        "sourceWidgetId": "w-map-wijken"
      }
    ],
    "timestamp": "2026-05-22T14:35:45Z"
  },
  {
    "id": "fe-003",
    "dashboardId": "d1a2b3c4-5678-90ab-cdef-1234567890ab",
    "userId": "u-caseworker-02",
    "widgetId": null,
    "eventType": "bookmark",
    "filterDelta": [
      {
        "dimension": "gemeenteCode",
        "operator": "eq",
        "source": "urlBootstrap"
      },
      {
        "dimension": "status",
        "operator": "eq",
        "source": "urlBootstrap"
      }
    ],
    "timestamp": "2026-05-22T15:10:00Z"
  }
]
```
