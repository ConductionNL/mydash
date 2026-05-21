# NC Dashboard Widget Proxy — Picker UX

## Problem

The unified Add Custom Widget modal needs a discoverable, accessible way for users to select from Nextcloud-discovered widgets when configuring an `nc-widget` placement. The current interface lacks proper visual hierarchy, keyboard navigation, and WCAG AA accessibility.

## Proposed Solution

Replace the widget picker with a responsive CSS-grid card layout in `NcDashboardForm`. Each card displays:
- The widget's icon (40px square, with fallback to generic icon)
- The widget's display name (single-line with ellipsis overflow)
- Visual selected state (border highlight + check-mark overlay)
- Full keyboard navigation (arrow keys, Enter/Space, Tab)
- Empty state message when no Nextcloud widgets are installed

The grid uses `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))` for responsive wrapping and 12px gaps between cards. All WCAG AA accessibility requirements are met via proper ARIA roles (`radiogroup` on container, `radio` on cards) and semantic keyboard interactions.

## Scope

This change defines the picker UX inside `NcDashboardForm` — specifically how users discover and select Nextcloud widgets when adding or editing an `nc-widget` placement. The selected widget is then rendered by the `nc-widget` placement renderer (owned by `widgets` capability) and the polling/bridging behavior is owned by `legacy-widget-bridge` capability.

## Success Criteria

- Widget picker renders as a responsive CSS grid (not a `<select>` dropdown)
- Each widget card displays icon, title, and selection state correctly
- Keyboard navigation works with arrow keys, Enter/Space for selection, Tab to exit
- Empty state displays with proper i18n when no Nextcloud widgets are installed
- All WCAG AA accessibility requirements are met (labels, roles, keyboard nav)
- Responsive grid adapts to viewport size without wrapping cards awkwardly
