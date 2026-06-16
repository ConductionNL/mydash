# Design: MyDash IA Alignment

## Current Topology (as found by audit)

```
NC navigation: "MyDash" → /index.php/apps/mydash/
└── #workspace-vue (WorkspaceApp.vue)
    ├── OrgNavigationPanel (when configured)
    ├── Strip: hamburger + active-dashboard title
    ├── Views.vue
    │   ├── DashboardGrid (gridstack canvas)
    │   ├── WidgetPicker (modal, opened from action menu)
    │   ├── DashboardConfigModal (per-dashboard, opened from cog)
    │   │   ├── inline sharing field set
    │   │   └── icon / name / description fields
    │   ├── TileEditor (modal)
    │   ├── WidgetRenderer / WidgetWrapper / WidgetContextMenu
    │   │   └── (no visibility-rule UI today)
    │   └── DashboardSwitcherSidebar
    └── DashboardFooter

NC Settings ▸ Administration ▸ MyDash
└── #mydash-admin-settings (AdminSettings.vue)  ← ONE PAGE, 11 sections:
    ├── Setup wizard banner
    ├── Default settings
    ├── GroupPriorityOrder
    ├── Dashboard templates (inline list + modal)
    ├── Group-shared dashboards
    ├── OrgNavigationEditor
    ├── DashboardExportImport
    ├── DashboardBulkOperations
    ├── ConfluenceImport
    ├── AdminAnalytics
    ├── AdminDemoData
    └── RolePermissionsSection
```

There is **no router** in mydash; navigation between IA "pages" is currently
all-or-nothing (workspace vs. admin settings).

## Proposed Topology

```
NC navigation: "MyDash" → workspace
└── #workspace-vue (WorkspaceApp.vue)
    ├── OrgNavigationPanel
    ├── Strip: hamburger + title + SHARE ACTION (new top-bar button)
    ├── Sidebar tabs:
    │   ├── "Dashboards" (current switcher + DashboardConfigModal)
    │   │   └── DashboardConfigModal — TABBED
    │   │       ├── Tab: General (name/desc/icon)
    │   │       ├── Tab: Sharing (relocated from inline field set)
    │   │       └── Tab: Default (per-user pin)
    │   └── "Catalog" (NEW SUB_PAGE)
    │       └── CatalogView.vue
    │           ├── Widget categories (registry-driven)
    │           ├── Custom Tiles category (existing TileWidget)
    │           └── Bridge category (legacy-widget-bridge surface)
    └── Canvas (Views.vue + DashboardGrid)
        └── WidgetContextMenu — adds "Visibility rules…"
            └── VisibilityRulesModal.vue (NEW — wraps existing
                /api/widgets/{id}/rules API)

NC Settings ▸ Administration ▸ MyDash
└── #mydash-admin-settings (AdminSettings.vue → orchestrator only)
    ├── Top strip: Setup wizard banner + Default settings (always visible)
    └── BeheerTabs.vue (NEW)
        ├── Tab: "Templates" (admin-templates SUB_PAGE)
        │   └── TemplatesPage.vue (extracted template list + modal)
        ├── Tab: "Operations" (Beheer / Operations)
        │   ├── PrometheusMetricsPanel (new surface, reads /api/metrics)
        │   ├── HealthPanel (reads /api/health)
        │   ├── LegacyWidgetBridge admin toggle
        │   ├── DashboardExportImport
        │   ├── DashboardBulkOperations
        │   └── ConfluenceImport
        ├── Tab: "Roles & Permissions" (Beheer / Roles & Permissions)
        │   └── RolePermissionsSection (relocated)
        ├── Tab: "Versioning & Audit" (Beheer / Versioning & Audit)
        │   ├── ConditionalVisibilityOverview (new — lists per-widget rules)
        │   └── AdminAnalytics (relocated — already audit-like)
        └── Tab: "Sharing" (Beheer / Sharing)
            ├── DashboardSharingPolicy (NEW — defaults / forced share groups)
            └── GroupPriorityOrder (relocated — related to share resolution)
        ├── Tab: "Org navigation" (existing OrgNavigationEditor)
        └── Tab: "Demo data" (existing AdminDemoData)
```

## Routing Model

mydash has no Vue Router today. Adding one for the workspace adds risk for an
SPA that auto-saves to backend state per dashboard. Instead:

- **Sidebar mode switch** (Dashboards / Catalog) in the existing sidebar
  uses local component state and lazy-loads `CatalogView.vue`. No URL change.
- **Beheer tabs** use a `<NcAppNavigationCaption>`-style tab strip inside the
  admin form. Tab selection is stored in `localStorage` so a deep-link
  (`?tab=operations`) round-trips on reload.
- **Dashboard config drawer** uses an internal `currentTab` ref with
  `NcAppSidebarTab`-style switching.

This keeps the contract with `manifest.json` (`pages: []` because mydash is a
single-shell app) intact — no manifest changes required.

## Affected Initial-State Keys

No new initial-state keys are added. The relocated sections consume the
existing keys they already inject (`allowUserDashboards`, `configuredGroups`,
`allGroups`, etc.). The new VisibilityRulesModal reads
`/api/widgets/{id}/rules` lazily on open.

## Backward Compatibility

- `AdminSettings.vue` keeps its current mount id `#mydash-admin-settings` and
  its position under NC Settings ▸ Administration; the visual change is the
  introduction of tabs. Admins relying on `data-test=` selectors get
  unchanged hooks for the wizard banner and group default badge; new tab
  panels get fresh `data-test` ids.
- All backend routes are untouched.
- The inline sharing field in `DashboardConfigModal` becomes a tab; the
  underlying `localShares` reactive state and emit contract are preserved.

## Out of Scope

- A real Vue Router. If the app ever grows to need URLs per area, that's a
  separate `add-mydash-router` change.
- Reworking the activity log; analytics relocates to Versioning & Audit but
  keeps its current data model.
- Manifest v2 page entries — the runtime manifest stays `pages: []`.
