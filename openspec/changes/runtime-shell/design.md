# Design — runtime-shell

## Context

The MyDash workspace page today does not have a formal "shell" capability — the page chrome (mount point, sidebar, toolbar, active-dashboard label, empty state, and lifecycle management) is bundled inside a generic dashboard-view component. This conflates page-level coordination with dashboard data handling and the GridStack surface, making it difficult to reason about which interactions are page-level (sidebar toggle, save, empty state) and which are dashboard-scoped (widget operations, layout changes).

The runtime-shell capability is the page-level orchestrator. It sits above four sibling capabilities (`dashboard-switcher`, `widget-add-edit-modal`, `widget-context-menu`, `grid-layout`) and coordinates their interactions, gates editing affordances via a single `canEdit` rule, and owns the fixed page regions (sidebar, hamburger+title strip, toolbar, grid mount). This is the LAST capability to land — it depends on virtually everything else being in place.

## Goals / Non-Goals

**Goals:**

- Introduce a page-level shell component that owns workspace chrome (mount point, sidebar, toolbar, title strip, empty state, lifecycle).
- Formalise the `canEdit = isAdmin || dashboardSource === 'user'` permission rule in one place, gating all edit affordances consistently.
- Keep the shell stateless with respect to data — every key consumed flows through the `initial-state-contract` via `provide`/`inject`; every persistence call routes through existing dashboard endpoints.
- Make the page-level interactions (sidebar open/close, save, toolbar visibility, empty state branching, lifecycle cleanup) formally specified so they cannot drift from the implementation.

**Non-Goals:**

- Changing how dashboards are resolved or loaded — the `active-dashboard-resolution` capability owns that.
- Changing the widget type registry or add-modal behavior — the `widget-add-edit-modal` capability owns that.
- Changing how the sidebar row list is fetched or rendered — the `dashboard-switcher` capability owns that.
- Adding new persistence layers or caching strategies — all state flows to/from existing endpoints.

## Architecture

### Page Layout (Four Regions)

```
┌─────────────────────────────────────────┐
│  Nextcloud chrome (50px header)         │
├─────────────────────────────────────────┤
│   [≡]  Dashboard Name  │  Toolbar      │
│ ├─────────────────────────────────────┤│
│ │  ┌───────────────────────────────┐ ││
│ │  │                               │ ││
│ │  │   GridStack Layout            │ ││
│ │  │   (or empty-state if none)    │ ││
│ │  │                               │ ││
│ │  └───────────────────────────────┘ ││
│ └─────────────────────────────────────┘│
│
│  ┌──────────┐
│  │ Sidebar  │
│  │ (slide   │
│  │  in at   │
│  │  50px)   │
│  └──────────┘
│  (backdrop closes it on click)
```

The shell is responsible for rendering all four regions:
- **Title strip**: Hamburger button + active-dashboard name label + right-side toolbar
- **Toolbar** (conditional on `canEdit`): Add Widget dropdown + Save Layout button
- **Grid area**: GridStack instance (or empty-state UI if `activeDashboard` is null)
- **Sidebar**: Slide-in list of dashboards (sourced from `dashboard-switcher` capability)
- **Backdrop**: Fixed overlay (top: 50px; closes sidebar on click)

### Component Hierarchy

```
WorkspaceApp (the shell)
├── SidebarBackdrop (v-if="sidebarOpen")
├── Sidebar (v-if="sidebarOpen")
│   └── dashboard-switcher (imported capability)
├── TitleStrip
│   ├── HamburgerButton (@click="toggleSidebar")
│   └── ActiveDashboardLabel
├── Toolbar (v-if="canEdit")
│   ├── AddWidgetDropdown (from widget-add-edit-modal)
│   └── SaveLayoutButton (@click="saveLayout")
└── GridContainer
    ├── GridStack instance (via grid-layout composable)
    └── EmptyState (v-if="!activeDashboard")
        └── CreateDashboardButton (v-if="allowUserDashboards")
```

### State and Data Flow

```
PHP initial-state (WorkspaceController::index)
│
├─ isAdmin (boolean)
├─ dashboardSource (enum: 'user' | 'group' | 'admin')
├─ activeDashboardId (string | null)
├─ activeDashboard.name (string)
├─ allowUserDashboards (boolean)
├─ layout (array of widget objects)
└─ (other keys consumed by sibling capabilities)
      │
      ▼
app.provide(key, value) for each
      │
      ▼
WorkspaceApp.vue
├─ inject('isAdmin') → computed canEdit
├─ inject('dashboardSource') → computed canEdit
├─ inject('activeDashboardId') → conditional render
├─ inject('activeDashboard') → label text
├─ inject('allowUserDashboards') → empty-state button
├─ inject('layout') → grid-layout composable
└─ local state
   ├─ sidebarOpen (boolean)
   └─ saving (boolean, for Save button disable)
      │
      ▼
User interactions → method calls
├─ toggleSidebar() → sidebarOpen.value = !sidebarOpen.value
├─ saveLayout() → PUT /api/dashboards/{uuid} with {layout}
└─ createPersonalDashboard() → POST /api/dashboards with default name
```

The shell **deliberately holds no source-of-truth data**. Mutations flow out to existing endpoints; reads come from injected initial state.

### Lifecycle and Event Delegation

On `onMounted`:
1. Register a global `document.click` listener (delegated to the grid composable's `handleClickOutside`).
2. Call `nextTick()` to ensure the grid container ref is non-null.
3. Initialise the GridStack instance via the grid composable.

On `onBeforeUnmount`:
1. Remove the global `document.click` listener.
2. Destroy the GridStack instance (to prevent memory leaks and DOM orphans).

This ensures the page-level click handling and grid lifecycle are tied to the shell's existence — if the shell unmounts, all listeners and grid state are cleaned up.

## Decisions

### D1: Shell holds NO data, only UI state

**Decision**: The shell is a **pure presenter** of data provided via `inject()`. The only local state is `sidebarOpen` and `saving` — UI-only fields used for rendering.

**Alternatives considered:**

- Cache `activeDashboard`, `layout`, etc. locally in the shell and sync via watchers. Rejected because it creates a second source of truth; the injected initial state is canonical, and any mutations go straight to the backend.
- Use Pinia for all shell state. Rejected because the initial state snapshot is read-only by design; `provide`/`inject` is simpler and makes the data flow explicit.

**Rationale**: One source of truth (the PHP initial-state snapshot) reduces bugs and makes code reviews easier. The shell is a view layer that coordinates other capabilities, not a data layer.

### D2: canEdit rule — isAdmin || dashboardSource === 'user'

**Decision**: `canEdit = isAdmin || dashboardSource === 'user'`.

**Alternatives considered:**

- Per-capability permission checks (e.g. widget-context-menu checks `canEdit` itself). Rejected — centralising the rule in the shell makes it visible in one spec and prevents inconsistency between toolbar, context menu, and grid mode.
- Role-based scheme with multiple roles. Rejected — MyDash's permission model is simpler (admin vs. user, user vs. group); this rule covers all cases.

**Rationale**: Admins can edit any dashboard. Users can only edit their own personal dashboards (where `dashboardSource === 'user'`). Group dashboards are read-only for non-admins. This rule gates the toolbar, context menu, and `staticGrid` mode uniformly.

### D3: Sidebar as a slide-in overlay, not a sticky left region

**Decision**: The sidebar is a fixed, slide-in panel that overlays the page (starting at `top: 50px`, the Nextcloud header height). A backdrop behind it intercepts clicks to close it.

**Alternatives considered:**

- Sticky left column layout like Nextcloud's file manager. Rejected — MyDash's workspace is content-heavy and needs the grid to use the full width when the sidebar is hidden.
- Bottom-drawer (mobile-style) sidebar. Rejected — desktop-first design; the Nextcloud chrome already establishes left-align conventions.

**Rationale**: An overlay sidebar maximizes the grid area when hidden, and the Nextcloud header offset (50px) places the sidebar contents below Nextcloud's chrome, maintaining the established visual hierarchy.

### D4: Empty state is conditional, not a lazy-loaded route

**Decision**: The empty-state UI is a conditional render inside the grid container (`v-if="!activeDashboard"`). No route change, no redirect — the user stays on `/` and sees the CTA.

**Alternatives considered:**

- Redirect to `/empty` or a dedicated route. Rejected — the URL should not change; the shell's only job is to coordinate what's rendered.
- Use a slot-based system where the parent (e.g. NextcloudApp) decides what to render. Rejected — the empty-state decision depends on `activeDashboard` which is local to the shell.

**Rationale**: The empty state is a presentation choice, not a routing choice. Keeping the user on `/` simplifies bookmarking and deep-linking behavior.

### D5: Toolbar entries always `v-if`, never `v-show`

**Decision**: The toolbar and its children use `v-if="canEdit"`, not `v-show`, so the DOM is clean for non-edit users.

**Alternatives considered:**

- `v-show` for faster toggling between edit and view. Rejected — toggling edit mode is not a frequent operation; cleaner DOM (fewer hidden nodes) is worth the re-mount cost.

**Rationale**: Removing the toolbar from the DOM entirely (via `v-if`) keeps the page lightweight for users who cannot edit and prevents event listener leaks if the toolbar has unmanaged handlers.

### D6: GridStack initialisation delayed until nextTick()

**Decision**: The grid is initialised **after** `nextTick()` on mount, ensuring the grid container ref is non-null.

**Alternatives considered:**

- Initialise synchronously in `onMounted`. Rejected — the grid container might not be rendered yet if the template uses `:ref` binding.
- Use a watcher on the ref value. Rejected — `nextTick()` is simpler and clearer for this specific pattern.

**Rationale**: `nextTick()` guarantees that the DOM has been flushed and the ref is populated before we try to access it. This is a standard Vue pattern for third-party library integration.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Non-admin user views a group dashboard and sees `canEdit = false`, but a toolbar still renders due to a regression | Tasks include a Playwright matrix covering both roles and both `dashboardSource` values; toolbar visibility tests are in the required test suite (Task 9). |
| GridStack instance is not destroyed on unmount, causing a memory leak | Task 7 explicitly manages `onBeforeUnmount` cleanup; Vitest in Task 11 verifies the listener is removed and the instance is destroyed. |
| Sidebar click handlers bubble to the backdrop and close the sidebar unexpectedly | The backdrop is a sibling of the sidebar in the DOM; handlers on the sidebar must use `@click.stop` to prevent bubble. Code review verifies this. |
| `activeDashboard` is null but the empty-state button is missing because `allowUserDashboards` was not injected | The `loadInitialState` reader (from `initial-state-contract`) provides a default for every key; the empty state tests verify that the button appears/disappears correctly. |
| Save button is disabled but never re-enabled if the request hangs indefinitely | The save timeout/error handlers MUST set `saving = false` in all code paths (success, error, timeout). Tests in Task 10 verify this. |

## Seed Data

This change introduces no new OpenRegister schemas. The shell is a Vue component that reads from the injected initial-state payload (seeded by `initial-state-contract` and the controllers it instruments) and dispatches saves to existing `/api/dashboards/` endpoints (which handle persistence via their own entity models).

The four sibling capabilities (`dashboard-switcher`, `widget-add-edit-modal`, `widget-context-menu`, `grid-layout`) seed their own data; the shell simply orchestrates their integration.

No `_registers.json` entry is required for this change.

## Test Strategy

- **Playwright (Task 9–10)**:
  - Admin viewing any `dashboardSource` sees the toolbar and can edit (grid is draggable).
  - Non-admin viewing `dashboardSource: 'group'` sees no toolbar and grid is `staticGrid: true`.
  - Non-admin viewing `dashboardSource: 'user'` sees the toolbar and can edit.
  - Hamburger toggles sidebar; backdrop click closes it; click on sidebar content does NOT close it.
  - Empty state with `allowUserDashboards: true` shows a Create button; with `false` shows a message and no button.
  - Save button is disabled while a request is in flight; shows success/error toast on completion.

- **Vitest (Task 11)**:
  - `onBeforeUnmount` removes the `document.click` listener and calls `gridStack.destroy()`.
  - No memory leaks (verify via snapshot or manual inspection of event listener counts).

- **E2E (implicit in Task 10)**:
  - The workspace page renders without errors and displays the four regions correctly.
  - Grid layout changes persist after a save (verifies integration with the backend endpoint).

- **Quality gates (Task 12)**:
  - ESLint + Stylelint pass on all touched Vue/JS/CSS files.
  - PHPCS passes on `templates/index.php` and `lib/Controller/WorkspaceController.php`.
  - SPDX headers on all new/touched PHP files.
  - All 10 hydra-gates pass (no unauthorized auth checks, no inline modals, etc.).
