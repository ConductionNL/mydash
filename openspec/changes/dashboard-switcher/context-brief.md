---
status: implemented
---

# Dashboard Switcher Specification

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Dashboards / Top-bar dropdown

**Rationale:** Switching UI  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The dashboard switcher is a left-edge slide-in sidebar that lets a user see every dashboard visible to them and switch between them with a single click. Dashboards are grouped into three labelled sections by source (primary group, default group, personal). The sidebar also surfaces personal-dashboard creation and deletion when allowed.

## Data Model

The component is stateless beyond its `v-model:open` flag. Its inputs:

| Prop | Type | Required | Notes |
|---|---|---|---|
| `isOpen` | `Boolean` | yes | controlled by parent via `v-model:open` |
| `groupName` | `String` | no | display name of the user's primary group; falls back to `'Dashboards'` |
| `groupDashboards` | `Array<{id, name, icon, source: 'group'\|'default'}>` | yes | combined matched + folded default group |
| `userDashboards` | `Array<{id, name, icon}>` | yes | personal dashboards |
| `activeDashboardId` | `String` | no | id of currently active dashboard for highlighting |
| `allowUserDashboards` | `Boolean` | no | when true, the dedicated "Add Dashboard" card affordance is shown (REQ-SWITCH-008) |

Emits: `switch(id: string, source: 'group'\|'default'\|'user')`, `create-dashboard()`, `delete-dashboard(id: string)`, `update:open(boolean)`.

## Requirements

### Requirement: REQ-SWITCH-001 Three-section navigation

The sidebar MUST render up to three sections in this fixed order, separated by a horizontal divider when both adjacent sections are non-empty:

1. **Primary group dashboards** — items from `groupDashboards` where `source !== 'default'`. Section label: `groupName || t('Dashboards')`.
2. **Default group dashboards** — items from `groupDashboards` where `source === 'default'`. Section label: `t('Default')`.
3. **My Dashboards** — items from `userDashboards`. Rendered when `userDashboards.length > 0` OR `allowUserDashboards === true`. Section label: `t('My Dashboards')`.

Empty sections MUST NOT render their label or container at all (no empty heading).

#### Scenario: All three sections present

- GIVEN `groupDashboards` contains 2 matched + 1 default-source items, and `userDashboards` contains 2 items
- WHEN the sidebar renders
- THEN it MUST render 3 section headings in order: `groupName`, `'Default'`, `'My Dashboards'`
- AND there MUST be exactly 2 dividers (between sections 1↔2 and 2↔3)

#### Scenario: Only personal dashboards section visible

- GIVEN `groupDashboards = []` and `userDashboards` has 1 item
- WHEN the sidebar renders
- THEN only the `'My Dashboards'` section MUST be visible
- AND no dividers MUST render

#### Scenario: Personal section visible when allowed even with empty list

- GIVEN `userDashboards = []` and `allowUserDashboards: true`
- WHEN the sidebar renders
- THEN the `'My Dashboards'` section heading MUST render
- AND the dedicated Add-Dashboard card (REQ-SWITCH-008) MUST be the only entry in the section

### Requirement: REQ-SWITCH-002 Switch click semantics

Clicking any dashboard item MUST emit `switch(id, source)` where `source` is derived from the item's section:

- Primary-group items → `source: 'group'`
- Default-group items → `source: 'default'` (regardless of the dashboard's record-level source)
- Personal items → `source: 'user'`

Before emitting `switch`, the sidebar MUST emit `update:open(false)` so the parent can close the sidebar in the same tick.

#### Scenario: Click a personal dashboard

- GIVEN the user clicks an item in the `My Dashboards` section with `id = 'p1'`
- THEN the sidebar MUST emit `update:open(false)`
- AND THEN MUST emit `switch('p1', 'user')`

#### Scenario: Click a default-group dashboard

- GIVEN the user clicks an item in the `Default` section with `id = 'd1'`
- THEN the sidebar MUST emit `switch('d1', 'default')`
- AND MUST NOT emit `switch('d1', 'group')` even though the underlying dashboard is technically a `group_shared` type

### Requirement: REQ-SWITCH-003 Active item highlight

The dashboard whose `id === activeDashboardId` MUST receive a visual `.active` class with `--color-primary-element-light` background and `--color-primary` icon tint. At most one item may be active at a time.

#### Scenario: Active highlight follows prop

- GIVEN `activeDashboardId = 'd1'`
- WHEN the sidebar renders
- THEN the item with id `'d1'` MUST have CSS class `active`
- AND no other item MUST have that class

#### Scenario: Active highlight updates on prop change

- GIVEN `activeDashboardId` changes from `'d1'` to `'p1'`
- WHEN the next render cycle runs
- THEN `'d1'` MUST lose the `active` class
- AND `'p1'` MUST gain it

### Requirement: REQ-SWITCH-004 Personal dashboard delete affordance

Items in the `My Dashboards` section (excluding the `+ New Dashboard` row) MUST display a small close-icon delete button on hover (CSS-only `display: none → inline-flex`). Clicking it MUST emit `delete-dashboard(id)` and MUST NOT trigger the row's `switch` event (use `@click.stop`).

#### Scenario: Hover reveals delete button

- GIVEN a personal dashboard item with `id = 'p1'`
- WHEN the user hovers over the row
- THEN the delete button MUST become visible (CSS hover state)

#### Scenario: Delete click does not trigger switch

- GIVEN the delete button is visible
- WHEN the user clicks it
- THEN the sidebar MUST emit `delete-dashboard('p1')`
- AND MUST NOT emit `switch(...)`
- AND MUST NOT emit `update:open(false)` (closing decision is up to the parent)

### Requirement: REQ-SWITCH-006 Slide-in animation

The sidebar MUST be fixed-position (`top: 50px` to clear the Nextcloud header), `width: 280px`, `z-index: 1500`. Open/close MUST be animated via `transform: translateX(-100%)` ↔ `translateX(0)` over `0.25s ease`. The `.open` CSS class MUST be added when `isOpen === true`.

#### Scenario: Closed state is off-screen

- GIVEN `isOpen: false`
- WHEN the sidebar renders
- THEN its computed `transform` MUST equal `translateX(-100%)`
- AND it MUST be invisible to the user (off-screen)

#### Scenario: Open state slides in

- GIVEN `isOpen` transitions from `false` to `true`
- THEN the sidebar's `transform` MUST animate to `translateX(0)` over 250 ms
- AND `transition-timing-function` MUST be `ease`

### Requirement: REQ-SWITCH-007 Icon rendering via shared renderer

Each dashboard item's icon MUST be rendered via the shared `IconRenderer` component (from `dashboard-icons` capability). The sidebar MUST NOT branch on `isCustomIconUrl` itself.

#### Scenario: Mixed built-in and custom icons

- GIVEN three dashboards with `icon` values `'Star'`, `'/apps/mydash/resource/x.png'`, `null`
- WHEN the sidebar renders
- THEN all three MUST render correctly via `IconRenderer`
- AND no inline `v-if="iconUrl"` branches MUST exist in the sidebar template

### Requirement: REQ-SWITCH-008 Dedicated Add-Dashboard card button

The sidebar MUST render a dedicated "Add dashboard" card button below the personal-dashboards list (still inside the sidebar's scroll container, NOT in the footer). The card MUST:

- Be a `NcButton` with `type="outline"`, full sidebar width
- Render a `+` icon and the localised label `t('mydash', 'Add dashboard')`
- Be visible only when `allowUserDashboards === true`
- On click, emit `update:open(false)` then `create-dashboard()` — same event contract the previous inline row used

#### Scenario: Card visible with personal dashboards enabled

- **GIVEN** a user whose injected `allowUserDashboards` is `true`
- **WHEN** the sidebar is open
- **THEN** the Add-Dashboard card MUST render below the personal section's last dashboard row
- **AND** the card's icon MUST be a `+` symbol
- **AND** the card's label MUST be `Add dashboard` (English) or `Dashboard toevoegen` (Dutch)

#### Scenario: Card hidden when personal dashboards disabled

- **GIVEN** a user whose injected `allowUserDashboards` is `false`
- **WHEN** the sidebar is open
- **THEN** the Add-Dashboard card MUST NOT render
- **AND** the personal section MAY render an empty-state message

#### Scenario: Click invokes create flow

- **GIVEN** the Add-Dashboard card is visible
- **WHEN** the user clicks it
- **THEN** the sidebar MUST emit `update:open(false)` first
- **AND** then emit `create-dashboard()` with no arguments
- **AND** the parent (workspace shell) MUST handle the create flow

### Requirement: REQ-SWITCH-009 Persistent sidebar footer with brand attribution and Documentation

The sidebar MUST render a persistent footer at the bottom of its viewport (using `position: sticky; bottom: 0` so it stays visible while the dashboards list scrolls). The footer MUST contain:

1. A "Powered by" line with two brand logos:
   - Sendent — clickable link to the Sendent site, `target="_blank" rel="noopener noreferrer"`
   - Conduction — clickable link to the Conduction site, same target/rel
2. A Documentation link directly below the brand row, rendered as an icon + the localised label `t('mydash', 'Documentation')`. The link target MUST match the URL the gear menu's Documentation entry previously used (so behaviour is preserved across the move).

A divider rule MUST separate the footer from the dashboards list above.

#### Scenario: Footer renders both brand logos with safe target attributes

- **GIVEN** the sidebar is open
- **WHEN** the footer is inspected
- **THEN** the Sendent logo MUST be wrapped in `<a target="_blank" rel="noopener noreferrer">` linking to the Sendent site
- **AND** the Conduction logo MUST have the same target + rel attributes linking to the Conduction site
- **AND** neither link MUST omit `rel="noopener noreferrer"` (per the security gate)

#### Scenario: Footer documentation link uses the same URL as the gear-menu link did

- **GIVEN** the sidebar's Documentation link is rendered
- **WHEN** clicked
- **THEN** it MUST navigate to the same URL the gear-menu Documentation entry used before `runtime-shell-trim` removed that entry

#### Scenario: Footer stays visible while list scrolls

- **GIVEN** a user with 30+ dashboards in the personal section
- **WHEN** the user scrolls the dashboards list
- **THEN** the footer MUST remain visible at the bottom of the sidebar viewport
- **AND** MUST NOT scroll out of view with the list
