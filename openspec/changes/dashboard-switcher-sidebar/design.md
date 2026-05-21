# Design — Dashboard switcher sidebar

## Context

MyDash introduced multi-scope dashboards (`multi-scope-dashboards` change) which enables three sources of dashboards:

1. **Personal dashboards** (`source: 'user'`) — user-owned, freely editable
2. **Group-shared dashboards** (`source: 'group'`) — admin-curated, visible to all members of a group
3. **Default dashboards** (`source: 'default'`) — admin-curated, visible to all users

The runtime shell (`WorkspaceApp.vue`) has no first-class navigation surface for switching between these dashboards. With three potential sources and feature flags like `allow_user_dashboards` and `allow_personal_dashboard_creation`, users need a unified, discoverable way to see all visible dashboards and pick one.

An inline topbar dropdown would not scale beyond a handful of items and cannot host affordances like personal-dashboard creation and deletion. A dedicated slide-in sidebar provides:

- **Scalability**: unlimited dashboards per section
- **Discoverability**: grouped by source (primary group, default, personal) so users understand where each dashboard comes from
- **Affordances**: personal-dashboard create/delete buttons isolated in their own section
- **Animation isolation**: slide-in animation and state are encapsulated in the component, allowing independent evolution from the runtime shell

## Goals / Non-Goals

**Goals:**

- Add a slide-in left sidebar for dashboard switching that renders all visible dashboards grouped by source.
- Surface personal-dashboard creation and deletion affordances (gated by `allowUserDashboards` feature flag).
- Emit a `source` discriminator alongside each switch event so the parent knows which API endpoint to call.
- Use shared `IconRenderer` component (from `dashboard-icons` capability) so icon rendering logic is unified across the app.
- Provide CSS-only animation (`transform: translateX`) so the sidebar is lightweight and predictable.
- Include a click-to-close backdrop so the parent has a clear interaction model without inline state management.

**Non-Goals:**

- Search or filtering within the sidebar (out of scope; addressed separately if needed).
- Drag-to-reorder dashboards (out of scope; managed via admin panel).
- Read-only badges on group/default dashboards (future enhancement, tracked separately).
- Real-time live-update of the dashboard list as admins create/delete (user sees changes on next page load).

## Component Architecture

### DashboardSwitcherSidebar.vue

A stateless, controlled component that receives dashboard data and emits switch/create/delete events.

**Props:**

- `isOpen: Boolean` (required) — controlled via `v-model:open` from parent
- `groupName: String` (optional) — display name of the user's primary group; falls back to `t('Dashboards')`
- `groupDashboards: Array<{id, name, icon, source: 'group'|'default'}>` (required) — union of group and default-group dashboards, pre-fetched and deduplicated by parent
- `userDashboards: Array<{id, name, icon}>` (required) — personal dashboards
- `activeDashboardId: String` (optional) — id of currently active dashboard for highlighting
- `allowUserDashboards: Boolean` (optional, default false) — when true, "+ New Dashboard" affordance is shown

**Emits:**

- `switch(id: string, source: 'group'|'default'|'user')` — user clicked a dashboard item; parent handles API call
- `create-dashboard()` — user clicked "+ New Dashboard"
- `delete-dashboard(id: string)` — user clicked delete on a personal dashboard
- `update:open(boolean)` — request to open/close the sidebar (e.g., backdrop clicked, or after switching)

### SidebarBackdrop.vue

A simple overlay component rendered at the `NcContent` level to handle click-to-close semantics.

**Props:**

- `visible: Boolean` — whether the backdrop is rendered and clickable

**Emits:**

- `click` — parent wires this to set `sidebarOpen = false`

## Design Decisions

### D1: Fixed three-section layout, not dynamic

**Decision:** Render sections in strict order: primary group → default → personal. Empty sections are completely hidden (no placeholder headings).

**Rationale:** Users recognize patterns; a consistent order means they know where to look. Hiding empty sections prevents orphan headings and keeps the sidebar compact when not all sources are in use. The fixed order is documented in REQ-SWITCH-001.

### D2: Source discriminator is server-computed and client-unmapped

**Decision:** Parent computes three separate API calls (`/api/dashboards`, `/api/dashboards/group/{groupId}`, `/api/dashboards/default`) and merges them into `groupDashboards` and `userDashboards` props. Each section's `source` is derived from which section the item is rendered in, not from a `source` property on the item itself.

**Rationale:** The parent already fetches the dashboard union via `multi-scope-dashboards` endpoints; adding redundant source markers would duplicate schema responsibility. The frontend sidebar simply knows "items in the group section came from the group endpoint" and derives `source` from that structural fact.

### D3: Delete affordance only on personal dashboards

**Decision:** Personal dashboards get a hover-revealed delete button. Group and default dashboards have no delete affordance in the sidebar.

**Rationale:** Group and default dashboard deletion is an admin action, not a user action. Users do not own those dashboards. Personal dashboard deletion is a user-level cleanup action and warrants quick access. The `@click.stop` handler ensures delete never triggers a switch event.

### D4: CSS-only slide-in animation

**Decision:** Use `transform: translateX(-100% to 0)` with `.25s ease` transition. No JavaScript animation or timers.

**Rationale:** Transform animations are GPU-accelerated and smooth. CSS-only keeps the component lightweight and predictable. The `.open` class is the single toggle point, making state management simple (parent controls `isOpen` prop, component applies `.open` class reactively).

### D5: Fixed positioning and z-index layering

**Decision:** `position: fixed; top: 50px (below NC header); width: 280px; z-index: 1500`. Sidebar sits between the topbar and standard app content.

**Rationale:** Fixed positioning ensures the sidebar overlays content and scrolls with the viewport, not the page. `z-index: 1500` places it above typical app content (z-index ~500) but below modal dialogs (z-index >2000). The 50px offset clears the Nextcloud header.

### D6: Section visibility is a visibility contract, not a data contract

**Decision:** Parent is responsible for passing empty arrays or arrays with items. The component renders based on array non-emptiness OR feature flags (e.g., `allowUserDashboards`). The component does NOT infer visibility from counts.

**Rationale:** Clear responsibility boundary — parent decides what to fetch and pass; component renders what it receives. This makes testing simple and decouples sidebar logic from API availability.

### D7: Icon rendering delegates to shared IconRenderer

**Decision:** Every dashboard item's icon is rendered via `<IconRenderer :icon="dashboard.icon" />`. The sidebar template has NO inline `v-if="isCustomIconUrl"` logic.

**Rationale:** Icon rendering is already solved by `dashboard-icons` capability (built-in icon set, custom URL discrimination). Reusing that component avoids icon-rendering duplication and ensures consistency across the app. The `IconRenderer` accepts any icon value (slug, URL, or null) and renders the appropriate visual.

## Interaction Flow

1. **User opens sidebar** — parent sets `sidebarOpen = true` → `isOpen` prop updates → `.open` class applied → `transform: translateX(0)` animates in
2. **User clicks a dashboard** → component emits `update:open(false)` → parent closes sidebar → component emits `switch(id, source)` → parent calls the correct endpoint
3. **User clicks delete on personal dashboard** → component emits `delete-dashboard(id)` → parent handles deletion API call (sidebar stays open until parent decides to close)
4. **User clicks "+ New Dashboard"** → component emits `update:open(false)` → parent closes sidebar → component emits `create-dashboard()` → parent opens create dialog
5. **User clicks backdrop** → backdrop component emits `click` → parent sets `sidebarOpen = false` → sidebar closes
6. **User presses Esc** → component detects Esc and emits `update:open(false)` → parent closes

## Internationalization (ADR-007)

All user-visible strings are translated via `this.t(appId, 'key')` in Vue templates:

- `'Dashboards'` — primary group section label (fallback when `groupName` not provided)
- `'Default'` — default group section label
- `'My Dashboards'` — personal section label
- `'+ New Dashboard'` — personal section create button
- `'Delete dashboard'` — delete button aria-label

Both `en` and `nl` translations are required.

## WCAG AA Compliance (ADR-010)

- **Keyboard navigation**: Tab-through all focusable items (dashboard rows, delete button, create button); Enter/Space to activate
- **Esc key**: Closes the sidebar (emits `update:open(false)`)
- **Focus management**: When sidebar opens, focus shifts to the first interactive element. When sidebar closes, focus returns to the triggering button (or body if triggered by backdrop)
- **Semantic structure**: Sections use `<nav>` with `aria-label`; items use semantic `<button>` or `<a>` elements, not divs with click handlers
- **Labeling**: Every interactive element has `aria-label` or visible label text. Delete button is explicitly `aria-label="Delete dashboard"`
- **Color not sole conveyor**: Active state is indicated by both color (primary-element-light background) and icon tint; not color alone

## Reuse Analysis (ADR-012)

This change consumes:

- `IconRenderer` from `dashboard-icons` capability (no duplication of icon rendering logic)
- Nextcloud UI tokens from `@conduction/nextcloud-vue` (colors, spacing)
- Vue 2 Options API patterns and Pinia store patterns from the existing codebase

No OpenRegister abstractions are consumed (dashboards are in-app domain objects, not OpenRegister-backed). No parallel mechanisms are introduced.

## Seed Data (ADR-001)

This change is pure frontend (stateless component). No OpenRegister schemas or seed data required.

## Testing Strategy (ADR-008)

- **Vitest (unit):** Section visibility logic, emit order (update:open before switch), source discriminator accuracy, active-class reactivity, delete-no-switch guarantee, create-button absence when disabled
- **Playwright (e2e):** Hover-reveal delete button, backdrop click closes, keyboard Esc closes, animation completes ~250ms, switching updates parent state
- **a11y:** Screen reader announces section headings, delete button, create button; keyboard nav fully functional; no color-reliant information

## Migration & Backwards Compatibility

This is a pure additive change. No existing APIs or components are modified. The runtime shell wires the sidebar alongside existing dashboard logic with no breaking changes.
