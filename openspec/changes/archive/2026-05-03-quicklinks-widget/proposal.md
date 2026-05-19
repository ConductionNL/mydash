# Quicklinks widget

## Why

MyDash users need a compact, high-density way to organize frequently accessed URLs on their dashboard. Today, they must place individual link-button widgets (one per placement) or use the links-widget (which spreads items across a multi-column section with descriptions). Neither scales well for 20+ bookmarks. A quicklinks widget fills this gap: a flat grid of small clickable icons, all inside ONE placement, with configurable sizing, shape, label position, and hover effects. Think "app launcher" or "favourites bar" — many shortcuts in a tight, flexible layout.

## What Changes

- Register a new dashboard widget with id `mydash_quicklinks` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` with:
  - `links: [{label, url, icon, color?: string}]` — flat array of shortcuts.
  - `iconSize: 'small'|'medium'|'large'|'xlarge'` (default `medium`) — renders as 32/48/64/96 px.
  - `iconShape: 'square'|'rounded'|'circle'` (default `rounded`) — border-radius 0 / 8px / 50%.
  - `showLabels: boolean` (default `true`) — toggle label visibility.
  - `labelPosition: 'below'|'overlay'` (default `below`) — `overlay` hides labels until hover, saving vertical space.
  - `columns: number` (default `'auto'` — flex-wrap; accepts 1..12 for fixed CSS Grid columns).
  - `tileBackgroundStyle: 'transparent'|'solid'|'gradient'` (default `transparent`) — optional icon background.
  - `hoverEffect: 'lift'|'fade'|'border'|'none'` (default `lift`) — hover animation style.
- Implement renderer `QuicklinksWidget.vue` (Vue 3 SFC) using CSS Flexbox for `auto` columns and CSS Grid for fixed columns, with per-link hover effects.
- Icon resolution: same precedence as link-button-widget (REQ-LBN-002) — built-in NC icon name → IconRenderer; URL → `<img>`; blank → fallback "link" icon.
- Color: optional per-link `color` field sets icon background tint when `tileBackgroundStyle = 'solid'`.
- Edit form: simple table of links (label + URL + icon picker + optional color picker) with drag-to-reorder. Bulk-add via paste CSV `label,url` to create multiple links at once.
- Click → navigates. External URLs carry `rel="noopener noreferrer" target="_blank"`. Internal Nextcloud URLs detect context (configurable per-link via `openInNewTab: boolean` defaulting to auto-detect).
- URL sanitisation on save: reject non-HTTP(S) and non-relative URLs with HTTP 400 — no `javascript:`, `data:`.
- Accessibility: each link is an `<a>` with descriptive `aria-label`; keyboard Tab navigation.
- Empty state: "No quicklinks yet — click the gear icon to add some."
- Default sizing: `gridWidth = 4`, `gridHeight = 2` (compact by default).

## Capabilities

### New Capabilities

- `quicklinks-widget` — adds REQ-QLNK-001 (registration and widget id), REQ-QLNK-002 (per-placement config shape), REQ-QLNK-003 (icon resolution), REQ-QLNK-004 (icon sizes and shapes), REQ-QLNK-005 (label position control), REQ-QLNK-006 (column layout flexibility), REQ-QLNK-007 (hover effects), REQ-QLNK-008 (URL sanitisation), REQ-QLNK-009 (click and navigation), REQ-QLNK-010 (accessibility), REQ-QLNK-011 (empty state and default sizing).

### Modified Capabilities

(none — this change is fully additive)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/QuicklinksWidget.vue` — new renderer with Flexbox + Grid layout, icon rendering, hover effects
- `src/components/Widgets/Forms/QuicklinksForm.vue` — new add/edit sub-form with link table, drag-to-reorder, CSV bulk-add
- `src/constants/widgetRegistry.js` — register `type: 'quicklinks'` with default content shape
- `lib/Controller/WidgetController.php` — new `validateUrls` action mapped to `POST /api/widgets/quicklinks/validate-urls` (optional; for real-time validation in edit form)
- `src/composables/useUrlSanitiser.js` — shared composable for URL validation/sanitisation (reusable by other widgets)
- `l10n/en.js`, `l10n/nl.js` — translation strings for all new labels, placeholders, and empty state

**Affected APIs:**

- 1 optional new route (`POST /api/widgets/quicklinks/validate-urls`) — no existing routes changed

**Dependencies:**

- `IconRenderer` (existing MyDash component) — reused from link-button-widget
- No new composer or npm dependencies

**Migration:**

- Zero schema changes — widget shape lives inside the existing `content` JSON blob on widget placements.
- Existing dashboards continue to work unchanged; the `quicklinks` type only appears once an admin explicitly adds a Quicklinks widget.
