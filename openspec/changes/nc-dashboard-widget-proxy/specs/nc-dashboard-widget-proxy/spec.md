---
capability: nc-dashboard-widget-proxy
status: draft
---

# NC Dashboard Widget Proxy — Picker UX Specification

## Purpose

Defines the user-facing picker interface for selecting Nextcloud-discovered widgets when configuring an `nc-widget` placement inside the unified Add Custom Widget modal. The picker must provide visual discoverability, keyboard accessibility, and responsive layout to help users discover and select from available widgets.

## Data Model

The widget picker displays data from the backend-provided `widgets` array (via initial state, populated by `IManager::getWidgets()`):

```typescript
interface WidgetMetadata {
  id: string;              // Nextcloud widget identifier (e.g., 'weather_status')
  title: string;           // Display name (e.g., 'Weather Status')
  iconUrl?: string;        // Optional URL to 40×40 px icon; falls back to generic widget icon
}
```

The form v-model shape:

```typescript
interface NcWidgetContent {
  widgetId: string;        // Selected widget id from the picker
  displayMode: 'vertical' | 'horizontal'; // How the widget renders (owned by REQ-WDG-020)
}
```

## Requirements

### REQ-NCDP-PICKER: NC Widget Picker UX

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

### REQ-NCDP-VALIDATION: Form Validation

The `NcDashboardForm` MUST validate that a `widgetId` is selected before allowing the placement to be created.

#### Scenario: Submit blocked when no widget selected

- **GIVEN** the picker is empty (no widget selected)
- **WHEN** the user clicks the "Add" button
- **THEN** the form MUST show an error message: localised `t('mydash', 'Please select a widget')`
- **AND** the placement MUST NOT be created

#### Scenario: Submit allowed when widget selected

- **GIVEN** the picker shows the selected widget (card has `aria-checked="true"`)
- **WHEN** the user clicks the "Add" button
- **THEN** the form MUST NOT show an error
- **AND** the placement MUST be created with the selected `widgetId`

### REQ-NCDP-ICON-FALLBACK: Icon Fallback Handling

When a widget's `iconUrl` is missing or returns an HTTP error, the picker MUST display a generic widget icon.

#### Scenario: Missing iconUrl uses fallback

- **GIVEN** a widget has no `iconUrl` in the initial state (or `iconUrl` is `null` / empty)
- **WHEN** the picker renders that card
- **THEN** a generic widget icon MUST display (e.g., `<NcIcon name="apps" />`)
- **AND** the card layout MUST remain consistent (no blank space)

#### Scenario: 404 iconUrl uses fallback

- **GIVEN** a widget has `iconUrl: '/apps/mywidget/missing.png'` but the file returns HTTP 404
- **WHEN** the card's image loads
- **THEN** the `onerror` handler MUST replace it with the generic widget icon
- **AND** the card layout MUST remain consistent

### REQ-NCDP-RESPONSIVE: Responsive Grid Layout

The grid MUST adapt to viewport width and render correctly on mobile, tablet, and desktop.

#### Scenario: Desktop (1200px+) shows multiple columns

- **GIVEN** viewport width is 1400 px
- **WHEN** the picker renders 8 widgets
- **THEN** the grid MUST display approximately 8–10 cards per row
- **AND** cards MUST NOT wrap awkwardly

#### Scenario: Tablet (768px–1199px) shows fewer columns

- **GIVEN** viewport width is 900 px
- **WHEN** the picker renders 8 widgets
- **THEN** the grid MUST display approximately 6–7 cards per row
- **AND** cards MUST NOT wrap awkwardly

#### Scenario: Mobile (< 768px) shows single column

- **GIVEN** viewport width is 375 px
- **WHEN** the picker renders 8 widgets
- **THEN** the grid MUST display 2–3 cards per row (minmax 140px allows ~2–3 at mobile width)
- **AND** cards MUST remain clickable and keyboard-navigable

### REQ-NCDP-I18N: Internationalization

All user-visible strings in the picker MUST be localised.

#### Scenario: Dutch locale shows Dutch text

- **GIVEN** the user's language is set to Dutch (nl_NL)
- **WHEN** the picker or error messages render
- **THEN** all strings MUST appear in Dutch (e.g., 'Geen Nextcloud-widgets geïnstalleerd')

#### Scenario: English locale shows English text

- **GIVEN** the user's language is set to English (en_US)
- **WHEN** the picker or error messages render
- **THEN** all strings MUST appear in English (e.g., 'No Nextcloud widgets are installed')
