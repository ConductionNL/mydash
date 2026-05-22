# Tasks — drill-down-cross-widget-filter

## Tasks

### Core Filter Bus Implementation

- [ ] Task 1: Create `src/composables/useFilterBus.js` with the pub/sub implementation. Export a composable that manages the filter state, accepts `publish(clause)` calls, and broadcasts to subscribers. Implement internal `_applyConflictResolution(newClause)` that enforces last-write-wins per dimension and tracks audit info (old value, new value, source transition). Inline comment linking REQ-CWF-001 through REQ-CWF-005.

- [ ] Task 2: Implement hierarchy resolution in `useFilterBus.js`. Add `_resolveHierarchy(clause, dashboardHierarchies)` that detects when a published clause belongs to a hierarchy level, infers ancestor clauses (marked `source=parentScope`) from the administrative/temporal hierarchy, and clears any descendant clauses currently in the bus. Handle missing parent levels gracefully with a console warning. Link REQ-CWF-004.

- [ ] Task 3: Create `src/composables/useDashboardFilter.js` with URL serialization/deserialization. Export `serializeFilterState(clauses): string` (returns base64url-encoded compact JSON with keys `d, o, v, s, w, p`) and `deserializeFilterState(param): {clauses, error}`. Implement lenient parsing (malformed param logs warning, returns empty clauses). Link REQ-CWF-003.

- [ ] Task 4: Integrate the filter bus into `src/components/Dashboard.vue`. Instantiate `useFilterBus()` on mount, hydrate from URL parameter via `useDashboardFilter()` on initial load (mark restored clauses with `source=urlBootstrap`), and sync the bus to the URL whenever the filter state changes (debounced 500ms to avoid excessive URL rewrites). Link REQ-CWF-003.

- [ ] Task 5: Render the "Active filters" panel in `Dashboard.vue` header. For each active clause, render a chip with dimension label, value, and source icon (widget name for `widgetClick`, lock for `pinned`, none for `userExplicit`). Add a remove-button for non-pinned clauses. Pinned clauses show a lock icon and no remove-button. Panel collapses when empty. Link REQ-CWF-007.

- [ ] Task 6: Add "Reset filters" button to the dashboard header in `Dashboard.vue`. Invoke callback that clears all non-pinned clauses from the bus, updates the URL (removes `f` parameter), and triggers widget re-renders. No-op when the bus is already empty (no telemetry, no URL change). Link REQ-CWF-006.

### Widget Integration

- [ ] Task 7: Update `src/components/widgets/ChartWidget.vue` (and analogous table/list widgets) to accept `filterContext` prop (already passed from dashboard for share-links; no change needed). Emit a `filter-published` event on chartable element click (bar, row, cell) with payload `{dimension, operator, value}`. The dashboard's bus subscriber listens for this event and calls `bus.publish()`. Link REQ-CWF-001, REQ-CWF-002.

- [ ] Task 8: Update widget manifest schema to include `publishes`, `consumes`, and `hierarchies` arrays. For existing chart and table widgets, populate `consumes` with the dimensions they accept in `filterContext`. For "click-enabled" widgets (charts with drill-down configured), populate `publishes` with the clickable dimensions. Hierarchies are inherited from the dashboard config. Link REQ-CWF-008.

- [ ] Task 9: Implement widget manifest validation in `Dashboard.vue` on render. Read widget's `publishes` and `consumes` declarations; cross-reference against the dashboard's hierarchy descriptors. Surface non-fatal warnings in the widget config UI if a consuming dimension is not in any dashboard hierarchy, or if a publishing dimension's `fromField` is not in the widget's data shape. Log validation errors to console. Link REQ-CWF-008.

- [ ] Task 10: Ensure all consuming widgets re-render within 300ms of a filter change (REQ-CWF-002). When the bus publishes a new clause, compute the set of consuming widgets (those whose `consumes` includes that dimension), trigger their `refetch()` methods (standard in REQ-WDG-006), and await the promise. If a fetch exceeds 300ms, the widget's existing loading indicator (REQ-WDG-007) kicks in. Profile on the production OR cluster to confirm the 300ms bound is met for typical drill-down queries (narrower scope, better selectivity). Link REQ-CWF-002.

### Saved Views

- [ ] Task 11: Create a "Save view" modal in `Dashboard.vue` (triggered by a button in the active-filters panel or dashboard header). Capture a name and optional description, bundle the current filter state, and POST to the register endpoint `POST /register/saved-views` with payload matching the `saved_view` shape (dashboard scoped, creator marked, timestamp set). Success redirects to the saved-views dropdown. Link REQ-CWF-009.

- [ ] Task 12: Add a "Saved views" dropdown in the `Dashboard.vue` header. On mount, fetch the dashboard's saved views from `GET /register/saved-views?dashboard=<dashboardId>`. Render saved views in two sections: "My views" (createdBy current user) and "Shared views" (isShared=true). Clicking a saved view applies all its clauses to the bus (replacing non-pinned clauses), updates the URL, and triggers widget re-renders. Link REQ-CWF-009.

- [ ] Task 13: Add edit/delete affordances to saved views. Users who created a view can edit its name/description and delete it (delete endpoint `DELETE /register/saved-views/<id>`). Shared views are read-only for non-creators. Include an "unshare" action for the creator. Link REQ-CWF-009.

### Telemetry & Persistence

- [ ] Task 14: Create `lib/Register/DashboardFilterStateRegister.php` as a new register schema for persisting saved views and telemetry. Implement the `saved_view` shape with fields: `id`, `dashboardId`, `name`, `description`, `filters` (JSON), `isShared`, `sharedWith`, `createdBy`, `createdAt`. Add endpoints: `POST /register/saved-views`, `GET /register/saved-views?dashboard=<id>`, `PATCH /register/saved-views/<id>`, `DELETE /register/saved-views/<id>`. Link REQ-CWF-009.

- [ ] Task 15: Implement filter telemetry logging in `useFilterBus.js`. When a clause is published and an org-level telemetry flag is enabled, construct a `filter_event` row with `eventType=apply`, the published clause's dimension/operator (no value), the source widget ID (if applicable), and the user ID. POST to `POST /register/filter-events` (implementation in Task 16). When "Reset filters" is invoked, write a separate `filter_event` with `eventType=reset` and the cleared clauses. When a bookmarked URL is loaded, write a `filter_event` with `eventType=bookmark` on next tick. Link REQ-CWF-010.

- [ ] Task 16: Create endpoints in `lib/Register/DashboardFilterStateRegister.php` for telemetry: `POST /register/filter-events` persists the event row with no value data (dimensions + operators only). Implement a read-only aggregate report endpoint `GET /register/filter-events/report?dashboard=<id>` that returns paths and frequency counts (e.g., `[{path: "gemeenteCode → wijkCode", count: 42}, ...]`). This report is visible to dashboard owners and admins; restrict per-user telemetry to the current user's own events. Link REQ-CWF-010.

- [ ] Task 17: Add org-level telemetry toggle to the admin settings (or dashboard config). Default is `false` (telemetry disabled). When toggled on, `useFilterBus.js` starts writing `filter_event` rows for subsequent filter operations. Existing events are not retroactively generated. Link REQ-CWF-010.

### Testing

- [ ] Task 18: Vitest — `useFilterBus.js` conflict resolution. New clause for dimension X replaces old clause for X when sources differ; widget-click replaces widget-click (shows toast); urlBootstrap → widget-click is silent; pinned clause cannot be replaced (publication dropped, console log). Test that toggle (same clause clicked twice) removes the clause. Link REQ-CWF-001, REQ-CWF-005.

- [ ] Task 19: Vitest — `useFilterBus.js` hierarchy resolution. When a wijk-level click arrives, infer ancestor clauses (gemeentecode, provincecode) marked `source=parentScope`. When a province-level click arrives with existing wijk clauses, clear the wijk. When a wijk from a different gemeente is clicked, reject and log error (no matching parent). Test breadcrumb updates. Link REQ-CWF-004.

- [ ] Task 20: Vitest — `useDashboardFilter.js` serialization/deserialization. Serialize a set of clauses (verschiedende dimensions, operators, sources) to base64url-compact-JSON and back; verify round-trip fidelity. Malformed parameter returns empty clauses + warning. Verify the compact JSON uses short keys (d, o, v, s, w, p) and stays under 2 KB for a 10-clause set. Link REQ-CWF-003.

- [ ] Task 21: Vitest — widget manifest validation. Dashboard loads a widget with `consumes: ["wijkCode"]` but no hierarchy includes wijkCode; validation surfaces warning and marks widget as non-bus-participant. Widget with `publishes: [{fromField: "missingField"}]` but data has no "missingField" key; validation logs error and disables bus participation. Matching dimensions pass validation. Link REQ-CWF-008.

- [ ] Task 22: Playwright — end-to-end filter workflow. Dashboard with three widgets (A: gemeente chart, B: wijk table consuming gemeenteCode, C: status chips not consuming any dimension). User clicks "Zeist" on A; B re-renders with filtered data, C does not re-render, URL updates with `f=...` parameter. User clicks "Vollenhove" on A; A's selection toggles to Vollenhove (or clears if double-click), B updates, URL changes. User copies URL and opens in incognito browser; same filters restore. Link REQ-CWF-001, REQ-CWF-002, REQ-CWF-003.

- [ ] Task 23: Playwright — hierarchy drill-down. Dashboard with nl-administrative hierarchy. User clicks a wijk; breadcrumb shows "Provincie X > Gemeente Y > Wijk Z", gemeentecode and provincieCode are inferred in the bus. User clicks up to provincie; wijk and gemeente are cleared, breadcrumb updates. User clicks a different wijk in a different gemeente; ancestors change, breadcrumb updates. Link REQ-CWF-004.

- [ ] Task 24: Playwright — conflict resolution and audit. Two charts (A and B) both publish gemeenteCode. User clicks Zeist on A; A highlights, B's data filters to Zeist. User clicks Houten on B; A's highlight clears, B highlights, a toast says "Gemeente filter changed: Zeist → Houten", active-filters panel shows only Houten. URL reflects the change. Link REQ-CWF-005.

- [ ] Task 25: Playwright — reset filters. Dashboard with 5 active clauses, one pinned. User clicks "Reset filters"; 4 non-pinned clauses disappear, pinned remains, URL `f` parameter is removed, consuming widgets re-render. Active-filters panel shows only the pinned clause. User clicks Reset on an already-empty bus; no change, no telemetry event. Link REQ-CWF-006.

- [ ] Task 26: Playwright — active-filters panel interactions. Dashboard with 3 clauses (one widgetClick, one urlBootstrap, one pinned). Panel renders all three with source icons (widget name for widgetClick, no icon for urlBootstrap, lock for pinned). Pinned clause has no remove-button; others do. User clicks remove on a widgetClick clause; it vanishes, URL updates, publishing widget's selection clears. Non-pinned clauses can be individually removed; pinned cannot. Link REQ-CWF-007.

- [ ] Task 27: Playwright — saved views. User applies filters (3 clauses), clicks "Save view", provides name "Q2 Trend". View is saved and appears in the dropdown. Same user clicks it again later with different filters active; all 3 clauses are restored. User shares the view; another user sees it in the "Shared" section. Shared view is read-only (no edit/delete). Link REQ-CWF-009.

- [ ] Task 28: Playwright — telemetry recording (when enabled). Enable org-level telemetry. User clicks Zeist on a chart; a `filter_event` row is written with `eventType=apply`, dimension=gemeenteCode, operator=eq, sourceWidgetId=<chart>, no value field. User copies URL and opens in another tab; on load, a `filter_event` with `eventType=bookmark` is written. User clicks "Reset filters"; a `filter_event` with `eventType=reset` and the cleared clauses is written. Disable telemetry; no events are written on subsequent actions. Link REQ-CWF-010.

- [ ] Task 29: Playwright — manifest validation warnings in widget config. Dashboard loads a widget that consumes "wijkCode" but wijkCode is not in the dashboard's hierarchies; widget config UI shows a yellow warning chip ("This widget consumes wijkCode but no hierarchy on this dashboard publishes it"). Widget still renders but does not participate in the bus. User can dismiss the warning or remove the widget. Link REQ-CWF-008.

- [ ] Task 30: Vitest — 300 ms responsiveness. Mock a widget's refetch to resolve after 100 ms, another after 250 ms. Trigger a filter publication; measure the time from publish to all consuming widgets updated in the DOM. Confirm both are within 300 ms (or log a performance warning if they exceed it). Link REQ-CWF-002.

### Quality & Documentation

- [ ] Task 31: ESLint clean on touched Vue/JS files. PHPCS/PHPMD clean on new PHP classes. PHPStan/Psalm type-checking on the new Register class. No new TSConfig errors if TypeScript is in use. Run `composer check:strict` before commit. Link company-wide ADR-009 (quality gates).

- [ ] Task 32: i18n review. No new user-facing strings expected except: "Active filters" panel header, "Reset filters" button, conflict resolution toast ("Gemeente filter changed: X → Y"), permission-denied tooltip ("no access to this scope"), "Save view" modal title, "Saved views" dropdown label, and the "Shared" section in the dropdown. All strings added to both `lang/nl.json` and `lang/en.json` with translation keys (e.g., `filter.activeFilters`, `filter.resetButton`). Link company-wide ADR-005 (i18n).

- [ ] Task 33: Inline documentation. `useFilterBus.js` includes a header comment summarizing the pub/sub contract, conflict resolution rules, and hierarchy semantics with links to REQ-CWF-001–005. `useDashboardFilter.js` documents the URL encoding scheme (compact JSON, keys, length guidance). `DashboardFilterStateRegister.php` documents the register shapes and endpoint contracts. No separate design doc needed; context-brief.md and this spec.md are the authoritative references. Link company-wide ADR-010 (documentation).

- [ ] Task 34: Changelog entry. Summarize the feature: "Add cross-widget filter bus enabling drill-down exploration across dashboard widgets. Widgets publish filters on click; all consuming widgets re-render. Filter state is bookmarkable via URL and saveable as named views. Opt-in telemetry measures drill-down path usage." Include a note that existing widgets that accept `filterContext` become instant bus participants with zero code change.

- [ ] Task 35: Performance audit. After widgets are wired to the bus, measure query time for drill-down operations (e.g., click gemeentecode, then wijkcode) on the production OR cluster. Confirm the 300 ms responsiveness bound is met. Log a performance warning if a single widget re-render exceeds 200 ms (indicating slow OR query). Profile memory usage on a dashboard with 100+ widgets to ensure the bus's pub/sub subscription overhead is negligible (<1 MB). Document results as a comment in `Dashboard.vue`. Link REQ-CWF-002.

## Verification

`openspec validate` exits clean. Dashboard with bus-aware widgets enables seamless drill-down filtering; filter state is reproducible via URL and bookmarkable; saved views work end-to-end; telemetry (if enabled) records paths without exposing values.

## Tests (company-wide ADR-009)

- Vitest: conflict resolution, hierarchy resolution, serialization, manifest validation, responsiveness (Tasks 18–21, 30).
- Playwright: end-to-end workflows, widget integration, saved views, telemetry (Tasks 22–29).
- No new backend-specific tests beyond register integration; existing REQ-WDG-006 (widget fetch) tests cover the query integration.

## Documentation (company-wide ADR-010)

- Inline composable comments (Tasks 33).
- Changelog entry (Task 34).
- No separate ADR needed; the feature is scoped to this change and well-documented in the context-brief.md and spec.md.
- User guide (out of scope for this change, but mentioned in design.md under "open questions"): URL bookmarking, saved views, hierarchy breadcrumb, conflict resolution toast.

## i18n (company-wide ADR-005)

- All user-facing strings added to both `nl.json` and `en.json` (Task 32).
- Dutch locale is the primary; English is a parity copy. No culture-specific logic (e.g., date/number formatting) required for filter state.
