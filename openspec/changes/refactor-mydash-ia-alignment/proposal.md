# Refactor MyDash IA to align with the proposed information architecture

## Why

The mydash app today is a **two-mount-point** Vue 2 single-page app:

1. **Workspace page** (`#workspace-vue` / `WorkspaceApp.vue`) — a single dashboard canvas
   with a sidebar, action menu, and a modal-driven widget picker. No router; no
   `/catalog/`, `/templates/`, or `/admin/` pages exist on the user side.
2. **Admin settings page** (`#mydash-admin-settings` / `AdminSettings.vue`) — one
   long form mounted under NC Settings ▸ Administration that stacks **11 unrelated
   sections** as cards in a single scroll: default settings, group priorities,
   templates, group-shared dashboards, org-nav editor, export/import,
   bulk operations, Confluence import, analytics, demo data, role permissions,
   and a setup-wizard banner. Everything an admin can do lives here.

The proposed IA (see `ia-implemented-mydash.tsv`) instead places admin features
in a **dedicated "Beheer" area with tabs** (Operations, Roles & Permissions,
Versioning & Audit, Sharing), promotes **Templates** to its own SUB_PAGE,
and groups **Widgets / Tiles** under a new **Catalog** SUB_PAGE. It also places
**Conditional Visibility** as a per-widget micro-UI (currently API-only) and
elevates **Dashboard Sharing** from an inline form field to a top-bar action
plus per-dashboard tab.

The audit found **7 specs drift, 4 specs aligned, 0 uncertain** out of 11
implemented specs surveyed. Without this refactor admins continue to scroll one
monolithic form to find role permissions or operations, conditional-visibility
remains invisible to end users despite its backend support, and the legacy-widget
bridge has no surface at all in the catalog/operations IA.

## What Changes

- **Promote `admin-templates`** from a section inside `AdminSettings.vue` to its
  own SUB_PAGE (Templates) rendered as a tab inside the admin settings page or
  a top-level route in a future mydash router shell.
- **Add per-widget Conditional Visibility UI** to `WidgetContextMenu` /
  `AddWidgetModal` (a "Visibility rules" action that opens a rule editor),
  AND add the matching admin overview tab under **Beheer ▸ Versioning & Audit**.
- **Restructure Dashboard Sharing** from the inline `DashboardConfigModal` field
  set into (a) a top-bar share action button on the dashboard canvas, and
  (b) a "Sharing" tab inside the dashboard config drawer. The admin org-wide
  sharing policy moves to a **Beheer** tab.
- **Split `AdminSettings.vue` into tabbed sub-views** with the four-tab
  Beheer layout:
  - **Beheer ▸ Operations** — `prometheus-metrics` health/metrics + ops actions.
  - **Beheer ▸ Roles & Permissions** — current `RolePermissionsSection`.
  - **Beheer ▸ Versioning & Audit** — `conditional-visibility` overview + audit log.
  - **Beheer ▸ Sharing** — org-wide `dashboard-sharing` policy + share defaults.
- **Promote Widgets/Tiles into a `Catalog` SUB_PAGE** as a navigable browse view
  (in addition to the existing modal `WidgetPicker`), with the legacy-widget
  bridge surfacing as a **Bridge category** filter inside the catalog AND a
  matching toggle inside **Beheer ▸ Operations**.
- **Keep `dashboards` and `grid-layout` in their current canvas location** —
  these already match the IA (WIDGET under Dashboards root / Canvas).

## Impact

- **Affected specs**:
  - `admin-settings` — split capability surface into Beheer tabs.
  - `admin-templates` — relocated to SUB_PAGE root.
  - `conditional-visibility` — adds UI capabilities (per-widget editor + admin tab).
  - `dashboard-sharing` — adds top-bar action + admin tab; removes inline modal field.
  - `legacy-widget-bridge` — adds Bridge catalog category + admin Operations toggle.
  - `permissions` — relocated from inline section to Beheer tab.
  - `prometheus-metrics` — adds admin Operations tab surface.
  - `widgets` — adds Catalog SUB_PAGE browse view alongside the modal picker.
- **Unaffected specs**: `dashboards`, `grid-layout`, `tiles` (already aligned —
  tiles is a widget type in the picker; dashboards/grid-layout live on the canvas).
- **Affected code**:
  - `src/components/admin/AdminSettings.vue` — decomposed into tabs.
  - `src/components/admin/*` — sections become tab panel children.
  - `src/components/Widgets/WidgetContextMenu.vue` — adds "Visibility rules" item.
  - `src/components/DashboardConfigModal.vue` — sharing extracted into its own tab.
  - `src/views/WorkspaceApp.vue` — adds top-bar share action wiring.
  - `src/views/Views.vue` and `src/components/WidgetPicker.vue` — new Catalog
    browse mode reusing the registry.
  - **New files**: `src/components/admin/BeheerTabs.vue`,
    `src/components/admin/tabs/OperationsTab.vue`,
    `src/components/admin/tabs/RolesPermissionsTab.vue`,
    `src/components/admin/tabs/VersioningAuditTab.vue`,
    `src/components/admin/tabs/SharingTab.vue`,
    `src/components/admin/tabs/TemplatesPage.vue`,
    `src/components/Widgets/VisibilityRulesModal.vue`,
    `src/views/CatalogView.vue`.
- **No backend changes**: conditional-visibility, metrics, sharing, and
  permissions APIs already exist; this refactor is UI relocation only.
