# NC Dashboard Widget Proxy — Picker UX Design

## Overview

The widget picker is a card-grid interface inside the `NcDashboardForm` sub-form component. It allows users to discover and select Nextcloud-discovered widgets (e.g., Mail, Calendar, Talk, Weather) when configuring an `nc-widget` placement in their MyDash dashboard.

## Component Architecture

### Frontend

| Component | Location | Role |
|---|---|---|
| `NcDashboardForm` | `src/components/Widgets/Forms/NcDashboardForm.vue` | Sub-form for `nc-widget` placement config; contains the picker + validation |
| `WidgetPicker` | `src/components/Widgets/Pickers/WidgetPicker.vue` | Renderless grid-layout for widget cards with keyboard navigation |
| `WidgetPickerCard` | `src/components/Widgets/Pickers/WidgetPickerCard.vue` | Individual card: icon + title + selected state |

### Data Flow

1. **Initialize** — `NcDashboardForm` receives `widgets` array from initial state (populated by backend via `IManager::getWidgets()`)
2. **Render** — `WidgetPicker` renders a `<div role="radiogroup">` with:
   - One `<button role="radio">` per widget
   - CSS grid with `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))` and `gap: 12px`
3. **Interact** — User clicks a card or navigates via arrow keys
4. **Select** — Selected widget id updates form v-model; selected card gets `aria-checked="true"` + visual highlight
5. **Submit** — `NcDashboardForm.validate()` requires a non-empty `widgetId`; on success, placement persists as `{type: 'nc-widget', content: {widgetId, displayMode}}`

### Widget Metadata

Each widget in the initial-state `widgets` array contains:
- **id**: `string` — Nextcloud widget identifier (e.g., `'weather_status'`)
- **title**: `string` — Display name (e.g., `'Weather Status'`)
- **iconUrl**: `string` (optional) — URL to the widget's icon (40×40 px recommended); falls back to generic widget icon if missing

### Seed Data

Example initial-state widgets array (3-5 realistic Nextcloud widgets):

```json
{
  "widgets": [
    {
      "id": "weather_status",
      "title": "Weather Status",
      "iconUrl": "/apps/weather_status/img/icon.svg"
    },
    {
      "id": "calendar",
      "title": "Calendar",
      "iconUrl": "/apps/calendar/img/app-calendar.svg"
    },
    {
      "id": "mail",
      "title": "Mail",
      "iconUrl": "/apps/mail/img/app.svg"
    },
    {
      "id": "talk",
      "title": "Talk",
      "iconUrl": "/apps/spreed/img/app-dark.svg"
    },
    {
      "id": "recommendations",
      "title": "Recommended",
      "iconUrl": "/apps/recommendations/img/app.svg"
    }
  ]
}
```

## Key Design Decisions

1. **Grid instead of Select** — Provides better visual scannability and icon support; matches modern dashboard UX patterns
2. **Radiogroup ARIA role** — Semantically correct for single-selection; improves screen reader support
3. **Keyboard-first** — Arrow keys match desktop application conventions; Tab-out enables efficient form navigation
4. **Icon fallback** — Missing iconUrl uses a generic widget icon; prevents visual breaks
5. **Responsive minmax** — 140px cards wrap automatically; scales from phone (single column) to wide desktop (6+ columns)
6. **Empty state message** — Localised string when no widgets installed; guides user expectation

## Styling

- **Grid container**: `role="radiogroup"`, `display: grid`, `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))`, `gap: 12px`
- **Card button**: `role="radio"`, `appearance: none` (reset browser default), `border: 2px solid transparent`, `padding: 8px`, `cursor: pointer`, `background: var(--color-background-secondary)`
- **Card on focus**: `outline: 2px solid var(--color-primary-element-light)`
- **Card on selected**: `border: 2px solid var(--color-primary-element)`, show check-mark icon overlay
- **Icon**: 40×40 px square, centered, fall back to `<NcIcon name="apps" />`
- **Title**: Single line, `text-align: center`, `white-space: nowrap`, `overflow: hidden`, `text-overflow: ellipsis`, `font-size: 12px`
- **Empty state**: Centered message, Nextcloud CSS variables for text color and spacing

## Accessibility

- **WCAG AA Compliance**:
  - `role="radiogroup"` on container
  - `role="radio"` on each card with `aria-checked="true|false"`
  - `aria-label="Select {widget-title} widget"` on each card
  - `aria-label="Available Nextcloud widgets"` on container
  - Keyboard nav: Up/Down/Left/Right move focus; Enter/Space toggle; Tab exits
  - Focus managed via `tabindex` (only current card has `tabindex="0"`, rest have `tabindex="-1"`)
  - Empty state has explicit ARIA live region if dynamically shown
- **Color**: Selection shown via border + icon, not color alone (meets contrast requirement)
- **Touch**: Cards are 140px minimum, safe for finger-sized touch targets

## Reuse Analysis

The picker uses these platform services provided by `@conduction/nextcloud-vue`:
- `NcIcon` — renders MDI icons with fallback
- `NcEmptyState` or custom empty div — for "no widgets" message
- CSS variables from NL Design System for theming

No custom services needed. The widget metadata comes from the backend's `IManager::getWidgets()`, already available in initial state per `widgets` capability (REQ-WDG-001).

## Deduplication Check

Checked against:
- **OpenRegister UI**: `CnIndexPage` + `CnDataTable` handle entity pickers, but those operate on backend objects. The widget picker is frontend-only discovery, distinct.
- **nc-vue SelectBox**: `NcSelect` exists but is dropdown-only. The grid layout and icon support require a custom grid component.
- **Nextcloud Vue RadioGroup**: No existing accessible radiogroup pattern in the shared library; implementing here is justified.

**Conclusion**: No duplication with existing OpenRegister or platform services.

## Open Questions / Future Work

1. Widget icons — fallback strategy if iconUrl is a broken link (consider cached preview or generic icon)
2. Widget search/filter — v1 does not include filtering by name; future enhancement
3. Widget categories/groups — v1 shows flat list; grouping by category (Communication, Office, etc.) could improve large widget lists
