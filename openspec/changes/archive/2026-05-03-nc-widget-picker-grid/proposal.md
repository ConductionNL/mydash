# NC widget picker — grid view with icons

## Why

The `nc-dashboard-widget-proxy` capability ships a `NcDashboardForm.vue` sub-form whose widget picker is a plain `<select>` listing every Nextcloud-discovered widget by name. Two UX problems:

1. **No icons.** Each NC widget has a discoverable icon (per `IManager::getWidgets()` REQ-WDG-001), but the dropdown shows text labels only. Users have to read names instead of recognising icons.
2. **Hidden affordance.** Click-to-open-dropdown adds a step before the user can scan options. With 5-15 widgets typically discovered, a grid of cards is faster to scan and less hidden.

The grid is also what users expect from competitor dashboards (Notion, Confluence, Trello-style "add widget" galleries).

## What Changes

- Replace the `<NcSelect>` in `NcDashboardForm.vue` with a CSS-grid of widget cards.
- Each card renders the widget's icon (from `widgets` initial-state catalog REQ-WDG-001) + display name + (optional) short description.
- Cards are arranged in a responsive grid (auto-fill, min card width ~140px).
- Selected card has a visual selected state (border highlight + check mark icon).
- Keyboard navigation: arrow keys move focus, Enter selects.
- Accessibility: cards have `role="radio"`, the grid has `role="radiogroup"`, ARIA-labelled.

## Capabilities

### Modified Capabilities

- `nc-dashboard-widget-proxy` — REQ-NCDP for the picker UX is MODIFIED to specify grid-of-cards-with-icons instead of a flat select.

## Impact

**Affected code:**

- `src/components/Widgets/Forms/NcDashboardForm.vue` — replace `<NcSelect>` with grid component
- `src/components/Widgets/Forms/NcWidgetGridPicker.vue` — new sub-component (or inline if compact)
- CSS for grid layout + selected state

**Affected APIs:** none. Initial state already carries `widgets` with icon URLs (REQ-WDG-001).

**Dependencies:** none.

**Migration:** none.
