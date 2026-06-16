# nc-dashboard-widget-proxy Specification

## Purpose

Defines the user-facing surface of the Nextcloud Dashboard widget proxy
(`nc-widget` placement type) — primarily the picker UX inside the unified
Add Custom Widget modal. The renderer/contract for `nc-widget` placements
themselves is owned by the `widgets` capability (REQ-WDG-018 onwards) and
the bridge polling behaviour by `legacy-widget-bridge`. This spec narrows
in on how end users discover and pick a Nextcloud-discovered widget when
configuring an `nc-widget` placement.

## Requirements

@e2e exclude NC widget picker UX lives inside the Add Widget modal — widget picker scenarios require real NC widgets to be installed; snapshot covered by wave3 tests

### Requirement: REQ-NCDP-PICKER NC widget picker UX

The `NcDashboardForm` sub-form's widget picker MUST render as a CSS-grid of cards, NOT a `<select>` dropdown. Each card MUST display:

- The widget's icon (40px square, sourced from `widgets` initial-state catalog per REQ-WDG-001 — fall back to a generic widget icon if the source URL is missing)
- The widget's display name (single line; ellipsis on overflow)
- A visible selected-state when the user has picked it (border highlight + check-mark icon overlay)

The grid MUST:

- Use `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))` for responsive wrapping
- Have 12px gap between cards
- Wrap the cards in an element with `role="radiogroup"` and an appropriate `aria-label`
- Each card MUST have `role="radio"` with correct `aria-checked` state

Keyboard navigation MUST work as follows:

- Arrow keys (Up/Down/Left/Right) move focus across the grid
- Enter or Space on a focused card MUST select it
- Tab MUST move focus out of the grid (not between cards)

#### Scenario: Grid renders one card per discovered NC widget

- **GIVEN** the workspace's initial state contains 8 Nextcloud-discovered widgets (each with `id`, `title`, `iconUrl`)
- **WHEN** the user opens the unified Add Custom Widget modal and picks "Nextcloud Widget" type
- **THEN** the picker MUST render 8 cards in a responsive grid
- **AND** each card MUST display the widget's icon and title
- **AND** the picker MUST NOT render a `<select>` element

#### Scenario: Selecting a card updates v-model

- **GIVEN** the picker shows 8 cards, none selected
- **WHEN** the user clicks the third card
- **THEN** the form's `widgetId` v-model MUST update to that widget's id
- **AND** the third card MUST display the selected-state border + check-mark
- **AND** the other cards MUST display their unselected state

#### Scenario: Empty state when no widgets discovered

- **GIVEN** the workspace's initial state contains zero Nextcloud widgets
- **WHEN** the picker renders
- **THEN** an empty-state message MUST display: localised `t('mydash', 'No Nextcloud widgets are installed')`
- **AND** no cards MUST render

#### Scenario: Keyboard navigation works

- **GIVEN** the picker has 4 cards in a single row
- **WHEN** the user presses Tab to focus the first card, then ArrowRight
- **THEN** focus MUST move to the second card
- **AND** the second card MUST have `tabindex="0"` while the others have `tabindex="-1"`
- **AND** pressing Enter MUST select the second card

