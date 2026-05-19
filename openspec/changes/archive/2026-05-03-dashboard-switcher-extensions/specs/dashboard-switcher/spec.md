# Spec delta — dashboard-switcher

## REMOVED Requirements

### Requirement: REQ-SWITCH-005 Personal-section inline create row

**Reason:** The inline `+ New Dashboard` row at the end of the personal section is too easy to miss when the list scrolls. Replaced by REQ-SWITCH-008 (dedicated card button) which sits visually distinct from the dashboard rows.

## ADDED Requirements

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
