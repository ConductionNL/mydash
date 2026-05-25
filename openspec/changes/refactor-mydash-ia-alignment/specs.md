# Per-Spec Relocation

Format: each capability lists ADDED / REMOVED requirements describing the IA
relocation. Backend-only requirements (data model, API contracts) are
intentionally untouched — this change is UI placement only.

## admin-settings

### REMOVED Requirement: Monolithic Settings Form

The single-page `AdminSettings.vue` form that stacks every admin capability
as flat sections MUST be removed and replaced with the tabbed Beheer layout.

#### Scenario: Admin opens MyDash settings

- **GIVEN** an admin lands on NC Settings ▸ Administration ▸ MyDash
- **WHEN** the page renders
- **THEN** the legacy "stacked sections" layout MUST NOT be visible
- **AND** a tab strip with at least Templates / Operations / Roles & Permissions /
  Versioning & Audit / Sharing tabs MUST be visible

### ADDED Requirement: Tabbed Beheer Layout

The admin settings page MUST organise its sections into discrete tabs
matching the IA's Beheer area: Operations, Roles & Permissions,
Versioning & Audit, Sharing — plus a Templates SUB_PAGE and existing
Org-Navigation and Demo-Data tabs.

#### Scenario: Switch to Operations tab

- **GIVEN** the admin settings page is open
- **WHEN** the admin clicks the "Operations" tab
- **THEN** the Operations panel MUST render Prometheus metrics, health,
  bulk operations, export/import, Confluence import, and the legacy-widget
  bridge toggle
- **AND** no other tab's content MUST be in the DOM

#### Scenario: Deep-link to a tab via query string

- **GIVEN** the admin opens `…/settings/admin/mydash?tab=roles-permissions`
- **WHEN** the page hydrates
- **THEN** the Roles & Permissions tab MUST be the active tab on first paint

#### Scenario: Default tab on first visit

- **GIVEN** an admin who has never opened the settings page before
- **WHEN** the page renders without a `?tab=` query string
- **THEN** the Templates tab MUST be the active tab (matches the IA's
  Templates SUB_PAGE as the canonical admin landing surface)

## admin-templates

### ADDED Requirement: Templates SUB_PAGE

Template management MUST live in its own SUB_PAGE under the admin area
(rendered as the "Templates" tab inside Beheer), not as an inline section
of the global settings form.

#### Scenario: Templates tab is the landing surface

- **GIVEN** an admin opens NC Settings ▸ Administration ▸ MyDash for the
  first time
- **WHEN** the page renders
- **THEN** the Templates tab MUST be the default active tab
- **AND** the template list + "Create template" CTA MUST be visible
  without scrolling past unrelated sections

#### Scenario: Templates page is the only place to manage templates

- **GIVEN** the admin is on any non-Templates tab
- **WHEN** the admin looks for template controls
- **THEN** the legacy inline "Dashboard templates" card under "Default
  settings" MUST NOT be present anywhere outside the Templates tab

## conditional-visibility

### ADDED Requirement: Per-Widget Visibility Rules Editor

End users with `canEdit` on a dashboard MUST be able to open a visibility-rules
editor for any widget placement from the widget context menu, and add / edit /
delete rules without leaving the canvas.

#### Scenario: Open the editor from the widget context menu

- **GIVEN** alice owns dashboard D with widget placement P (id 10)
- **WHEN** she right-clicks placement P and selects "Visibility rules…"
- **THEN** a `VisibilityRulesModal` MUST open
- **AND** the modal MUST fetch `GET /api/widgets/10/rules` on open
- **AND** existing rules MUST be listed with their type, scope, and
  include / exclude flag

#### Scenario: Add a group rule from the editor

- **GIVEN** the visibility rules modal is open for placement 10
- **WHEN** alice picks "Group", selects the "marketing" group, sets
  "Include", and saves
- **THEN** the modal MUST POST to `/api/widgets/10/rules`
- **AND** the new rule MUST appear in the modal's list on success

### ADDED Requirement: Admin Versioning & Audit Tab

The Beheer ▸ Versioning & Audit tab MUST surface a per-widget overview
listing every placement that has at least one visibility rule, with
links to the owning dashboard.

#### Scenario: Versioning & Audit lists rule-bearing widgets

- **GIVEN** dashboards in the system contain 12 widget placements with at
  least one conditional rule
- **WHEN** an admin opens Beheer ▸ Versioning & Audit
- **THEN** all 12 placements MUST be listed with dashboard name, widget
  type, rule count, and include / exclude breakdown

## dashboard-sharing

### REMOVED Requirement: Inline Sharing Field Set

The current `DashboardConfigModal.vue` MUST NOT render the sharing
sharee picker and per-share permission rows as a free-floating field
set in the modal body.

#### Scenario: Open the dashboard config modal

- **GIVEN** alice clicks the cog on her dashboard
- **WHEN** the modal opens
- **THEN** the sharee picker MUST NOT appear in the General tab
- **AND** sharing MUST be reachable only from the Sharing tab (per the
  ADDED requirement below)

### ADDED Requirement: Top-Bar Share Action + Per-Dashboard Sharing Tab

The dashboard canvas MUST expose a top-bar share button next to the
hamburger / title strip; the per-dashboard config drawer MUST organise
sharing into its own tab.

#### Scenario: Share button on the canvas strip

- **GIVEN** alice is viewing a dashboard she owns
- **WHEN** the workspace shell renders
- **THEN** a share icon button MUST appear in the strip, labelled "Share"
- **AND** clicking it MUST open the config drawer with the Sharing tab
  pre-selected

#### Scenario: Sharing tab inside the config drawer

- **GIVEN** the dashboard config drawer is open
- **WHEN** the user clicks the Sharing tab
- **THEN** the sharee picker, the current share rows, and the per-share
  permission controls MUST be visible inside that tab panel only

### ADDED Requirement: Admin Sharing Tab for Org Policy

The Beheer ▸ Sharing tab MUST host the org-wide share defaults
(forced share groups, default permission level for shares) and the
relocated `GroupPriorityOrder` editor (group resolution order is a
sharing concern).

#### Scenario: Org sharing defaults are admin-only

- **GIVEN** an admin opens Beheer ▸ Sharing
- **WHEN** the page renders
- **THEN** the default share permission selector and the forced-share-group
  picker MUST be visible
- **AND** GroupPriorityOrder MUST be visible on the same tab

## dashboards

(No requirement changes — current placement matches the IA: WIDGET under
Dashboards root / canvas.)

## grid-layout

(No requirement changes — current placement matches the IA: WIDGET on the
dashboards canvas.)

## legacy-widget-bridge

### ADDED Requirement: Bridge Category in Catalog

The catalog browse view MUST expose a "Bridge" filter / category that
lists every widget surfaced by the `widgetBridge.js` runtime adapter,
so end users can discover non-native widgets without reading the registry.

#### Scenario: Filter the catalog by Bridge

- **GIVEN** the workspace sidebar has the Catalog SUB_PAGE active
- **WHEN** the user clicks the "Bridge" category
- **THEN** every widget whose registry entry has `source: 'bridge'` (or
  equivalent) MUST appear in the grid
- **AND** native MyDash widgets MUST NOT appear in that filter

### ADDED Requirement: Bridge Toggle in Beheer / Operations

The Beheer ▸ Operations tab MUST host an enable / disable toggle for the
legacy widget bridge, with a hint explaining the impact on existing
dashboards that already embed bridged widgets.

#### Scenario: Admin disables the bridge

- **GIVEN** an admin opens Beheer ▸ Operations
- **WHEN** the admin flips the "Legacy widget bridge" switch off
- **THEN** the change MUST be persisted via the admin settings API
- **AND** a hint MUST warn that existing bridged widget placements will
  render an "Unavailable" state until the bridge is re-enabled

## permissions

### REMOVED Requirement: Inline RolePermissionsSection Card

The current placement of `RolePermissionsSection` as one of the 11
stacked sections inside `AdminSettings.vue` MUST be removed.

#### Scenario: Roles section is no longer inline

- **GIVEN** an admin opens the MyDash settings page
- **WHEN** the page renders
- **THEN** the Roles & Permissions controls MUST NOT appear in the
  scrollable section list below "Default settings"

### ADDED Requirement: Roles & Permissions Tab

`RolePermissionsSection` MUST be relocated to its own Beheer ▸ Roles &
Permissions tab.

#### Scenario: Roles tab hosts the section

- **GIVEN** the admin clicks the Roles & Permissions tab
- **WHEN** the tab panel renders
- **THEN** the role permissions matrix and CRUD controls MUST be the
  only content visible in the panel body

## prometheus-metrics

### ADDED Requirement: Operations Tab Surface

The Beheer ▸ Operations tab MUST present a Prometheus metrics panel
that fetches `/api/metrics` and renders the parsed output in a
read-only code block, plus a health panel reading `/api/health`.

#### Scenario: Operations tab shows current metrics

- **GIVEN** an admin opens Beheer ▸ Operations
- **WHEN** the tab panel mounts
- **THEN** a fetch to `/api/metrics` MUST fire
- **AND** the response body MUST render inside a `<pre>` block with a
  copy-to-clipboard button

#### Scenario: Health badge reflects /api/health

- **GIVEN** the Operations tab is open
- **WHEN** the `/api/health` endpoint returns `{ "status": "ok" }`
- **THEN** a green "Healthy" badge MUST render next to the metrics panel
- **AND** when the endpoint returns a non-200 response or no `status: ok`,
  a red "Degraded" badge MUST render instead

## tiles

(No requirement changes — tiles is already a widget type in the registry
and surfaces via the existing `WidgetPicker` modal; the ADDED catalog
SUB_PAGE for `widgets` will simply also list it under a "Custom Tiles"
category. No `tiles`-specific spec changes are required.)

## widgets

### ADDED Requirement: Catalog SUB_PAGE Browse View

The workspace sidebar MUST gain a "Catalog" entry that opens a full-page
browse view (`CatalogView.vue`) listing every registered widget grouped
by category — separate from the existing modal `WidgetPicker` that
opens from the canvas action menu.

#### Scenario: Sidebar opens the Catalog SUB_PAGE

- **GIVEN** alice is on her dashboard
- **WHEN** she opens the sidebar and clicks the "Catalog" entry
- **THEN** the canvas region MUST be replaced by `CatalogView.vue`
- **AND** the existing dashboard state (active dashboard, layout) MUST
  be preserved so returning to the canvas restores it without a reload

#### Scenario: Catalog groups widgets by category

- **GIVEN** the catalog page is open
- **WHEN** widgets are listed
- **THEN** they MUST be grouped under at least: Built-in, Custom Tiles,
  Bridge (legacy NC widgets), and any user-defined categories from the
  registry
- **AND** each group MUST be collapsible and remember its open / closed
  state across reloads via `localStorage`
