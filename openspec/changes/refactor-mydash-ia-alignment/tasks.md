# Tasks

Atomic, numbered, file-by-file. Sequential within each section unless noted.

## 1. Beheer tab shell

- [ ] 1.1 Create `src/components/admin/BeheerTabs.vue` — `NcAppNavigationCaption`-style
      tab strip with named slots per tab; reads `?tab=` query string and
      writes selection to `localStorage` (key `mydash.admin.activeTab`).
- [ ] 1.2 Add `src/components/admin/tabs/` directory and create stub files:
      `TemplatesPage.vue`, `OperationsTab.vue`, `RolesPermissionsTab.vue`,
      `VersioningAuditTab.vue`, `SharingTab.vue`, `OrgNavigationTab.vue`,
      `DemoDataTab.vue` (each renders an `<NcEmptyContent>` placeholder for now).
- [ ] 1.3 Update `src/components/admin/AdminSettings.vue` to render the
      Setup-wizard banner + Default-settings card at the top, then mount
      `<BeheerTabs />` with the seven tab components as slots.
- [ ] 1.4 Add `data-test="beheer-tabs"` and one `data-test="tab-{slug}"` per
      tab button.

## 2. Templates relocation

- [ ] 2.1 Move the template CRUD block (lines 95-143 + the Template Editor
      Modal at lines 250-303 in `AdminSettings.vue`) into
      `src/components/admin/tabs/TemplatesPage.vue`.
- [ ] 2.2 Move the `editingTemplate`, `templates`, `permissionOptions` data,
      and `createTemplate` / `editTemplate` / `saveTemplate` /
      `deleteTemplate` / `closeTemplateEditor` methods from
      `AdminSettings.vue` to `TemplatesPage.vue`.
- [ ] 2.3 Delete the dead inline template markup + script from
      `AdminSettings.vue`.
- [ ] 2.4 Set `TemplatesPage` as the default `localStorage` value for the
      Beheer active-tab when no value is set.

## 3. Operations tab

- [ ] 3.1 In `src/components/admin/tabs/OperationsTab.vue`, add a
      `PrometheusMetricsPanel` sub-component that fetches `/api/metrics`
      via `axios.get` on mount and renders the body in `<pre>` with a
      copy button.
- [ ] 3.2 Add a `HealthPanel` sub-component that fetches `/api/health` and
      renders a green / red badge based on `status === 'ok'`.
- [ ] 3.3 Move the `DashboardExportImport`, `DashboardBulkOperations`, and
      `ConfluenceImport` imports + mount points from `AdminSettings.vue`
      into `OperationsTab.vue`.
- [ ] 3.4 Add a `LegacyWidgetBridgeToggle` sub-component using the
      `NcCheckboxRadioSwitch` pattern from `AdminSettings.vue`; wire it to
      `PUT /api/admin/settings` with a `legacyWidgetBridgeEnabled` field
      (add the field to the backend `MyDashAdmin` form payload — covered
      by the legacy-widget-bridge spec backend addition).
- [ ] 3.5 Delete the moved imports + mount points from `AdminSettings.vue`.

## 4. Roles & Permissions tab

- [ ] 4.1 Move the `RolePermissionsSection` import + `<RolePermissionsSection />`
      mount from `AdminSettings.vue` into
      `src/components/admin/tabs/RolesPermissionsTab.vue`.
- [ ] 4.2 Delete the dead import + section from `AdminSettings.vue`.

## 5. Versioning & Audit tab

- [ ] 5.1 Create `src/components/admin/ConditionalVisibilityOverview.vue`
      that fetches a new admin endpoint
      `GET /api/admin/widgets/with-rules` (returns `[{ placementId,
      dashboardId, dashboardName, widgetType, ruleCount, includeCount,
      excludeCount }, ...]`) and renders the table per the spec scenario.
- [ ] 5.2 Add the backend `WithRulesController::index` action + route in
      `appinfo/routes.php` (admin auth required). Service method:
      `ConditionalService::listAllRules()` — already exists or
      add as `SELECT * FROM oc_mydash_conditional_rules JOIN
      oc_mydash_widget_placements`.
- [ ] 5.3 Mount `ConditionalVisibilityOverview` and the moved
      `AdminAnalytics` inside
      `src/components/admin/tabs/VersioningAuditTab.vue`.
- [ ] 5.4 Delete the `AdminAnalytics` import + mount from
      `AdminSettings.vue`.

## 6. Sharing tab (admin org policy)

- [ ] 6.1 Create `src/components/admin/DashboardSharingPolicy.vue` —
      reads / writes `defaultSharePermissionLevel` and `forcedShareGroups`
      via `PUT /api/admin/settings` (add fields to the backend
      `MyDashAdmin` form payload — covered by dashboard-sharing spec
      backend addition).
- [ ] 6.2 Mount it in `src/components/admin/tabs/SharingTab.vue`.
- [ ] 6.3 Move the `GroupPriorityOrder` import + mount from
      `AdminSettings.vue` into `SharingTab.vue`.
- [ ] 6.4 Delete the moved import + mount from `AdminSettings.vue`.

## 7. Org navigation + Demo data tabs

- [ ] 7.1 Move `OrgNavigationEditor` import + mount into
      `src/components/admin/tabs/OrgNavigationTab.vue`.
- [ ] 7.2 Move `AdminDemoData` import + mount into
      `src/components/admin/tabs/DemoDataTab.vue`.
- [ ] 7.3 Delete the two imports + mounts from `AdminSettings.vue`.

## 8. Per-widget Conditional Visibility editor

- [ ] 8.1 Create `src/components/Widgets/VisibilityRulesModal.vue` — props:
      `placementId`, `open`; emits `close`, `rule-added`, `rule-removed`.
      Mounts an `NcModal`, fetches `GET /api/widgets/{placementId}/rules`
      on open, renders a list + a "Add rule" form supporting the four
      rule types from the conditional-visibility spec.
- [ ] 8.2 Add `Visibility rules…` button to
      `src/components/Widgets/WidgetContextMenu.vue` between Edit and
      Remove; emit a new `visibility-rules` event.
- [ ] 8.3 In `src/views/Views.vue` (or the parent that wires the context
      menu), listen for `visibility-rules`, store the target placement id,
      and render `<VisibilityRulesModal :placement-id="..." />`.
- [ ] 8.4 Gate the button on `canEdit` (REQ-SHELL-002 rule already
      computed by `WorkspaceApp.vue`).

## 9. Dashboard Sharing relocation

- [ ] 9.1 Refactor `src/components/DashboardConfigModal.vue` to use a tab
      strip (`NcAppSidebarTab` or local component) with at least: General,
      Sharing, Default. Keep all current refs (`form`, `localShares`,
      `permissionOptions`, `shareeOptions`, `onShareeSearch`,
      `onShareeSelected`, `permissionOptionFor`) and just move the
      template fragments into the matching tabs.
- [ ] 9.2 Add a top-bar share button to
      `src/views/WorkspaceApp.vue` strip (between hamburger and title),
      `NcButton type="tertiary"` with `ShareVariant` icon; emit a new
      event up to `Views.vue` or call a `dashboardStore` action to open
      the config drawer with `initialTab: 'sharing'`.
- [ ] 9.3 Add the `initialTab` prop / data flow to
      `DashboardConfigModal.vue` so the share button lands directly on the
      Sharing tab.

## 10. Catalog SUB_PAGE + Bridge category

- [ ] 10.1 Create `src/views/CatalogView.vue` — full-region browse view
      that imports the widget registry (`src/constants/widgetRegistry.js`),
      groups widgets by category (`built-in`, `custom-tile`, `bridge`,
      user-defined), and renders a sticky filter strip + grid of cards.
- [ ] 10.2 Add a `mode` ref to the sidebar
      (`src/components/Workspace/DashboardSwitcherSidebar.vue`) toggling
      between `'dashboards'` and `'catalog'`; emit a `mode-change` event.
- [ ] 10.3 In `src/views/WorkspaceApp.vue`, switch the Region 3 render
      between `<Views />` and `<CatalogView />` based on the sidebar
      mode (preserve the dashboard store; do not unmount Views' store).
- [ ] 10.4 Tag bridge widgets in `src/constants/widgetRegistry.js` with
      `source: 'bridge'` (or derive from `widgetBridge.js` runtime
      registrations) so the catalog filter has a stable key.
- [ ] 10.5 Persist the open / closed state of each category group in
      `localStorage` under `mydash.catalog.openGroups`.

## 11. Documentation + tests

- [ ] 11.1 Update `docs/architecture.md` IA section with the new tab
      topology + sidebar mode switch.
- [ ] 11.2 Add Jest specs for `BeheerTabs.vue` (tab switching,
      localStorage persistence, `?tab=` deep-link).
- [ ] 11.3 Add Jest spec for `VisibilityRulesModal.vue` (open-fetches,
      add-rule POST happy path, delete-rule, error rollback).
- [ ] 11.4 Add Jest spec for `CatalogView.vue` (grouping, filter,
      localStorage open state).
- [ ] 11.5 Add Jest spec for `DashboardConfigModal.vue` covering the new
      tab strip and `initialTab` prop.
- [ ] 11.6 Add a Playwright integration test
      (`tests/integration/ia-tabs.spec.js`) that loads the admin page,
      clicks each Beheer tab, and asserts the expected `data-test` ids
      appear / disappear.

## 12. Cleanup

- [ ] 12.1 After all sections are moved, `AdminSettings.vue` should be
      ≤200 lines (orchestrator only). Verify no dead imports remain.
- [ ] 12.2 Verify the `data-test="setup-wizard-banner"`,
      `data-test="setup-wizard-rerun"`, `data-test="set-group-default"`,
      and `data-test="group-default-badge"` selectors still resolve in
      the new layout (banner stays at top of AdminSettings; group-default
      moves to TemplatesPage if linked, otherwise stays under
      group-shared dashboards — confirm during 2.1).
- [ ] 12.3 Run `composer check:strict` and `npm run lint` clean.
