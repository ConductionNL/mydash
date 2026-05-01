# Tasks — quicklinks-widget

## 1. Widget registration and setup

- [ ] 1.1 Create `src/constants/widgetRegistry.js` entry for `type: 'quicklinks'` with default content shape (REQ-QLNK-002)
- [ ] 1.2 Register the widget in `WidgetController.php` or entry point via `OCP\Dashboard\IManager::registerWidget()` with id `mydash_quicklinks` and default `gridWidth = 4`, `gridHeight = 2` (REQ-QLNK-001)
- [ ] 1.3 Add translation entries in `l10n/en.js` and `l10n/nl.js`: `Quicklinks`, `No quicklinks yet — click the gear icon to add some.`

## 2. Renderer (QuicklinksWidget.vue)

- [ ] 2.1 Create `src/components/Widgets/Renderers/QuicklinksWidget.vue` as a Vue 3 SFC
- [ ] 2.2 Import and use existing `IconRenderer` component for MDI icon resolution (REQ-QLNK-003)
- [ ] 2.3 Implement CSS Flexbox layout for `columns: 'auto'` with `flex-wrap: wrap` (REQ-QLNK-006)
- [ ] 2.4 Implement CSS Grid layout for `columns: 1..12` with `grid-template-columns: repeat(N, 1fr)` (REQ-QLNK-006)
- [ ] 2.5 Map `iconSize` field to pixel dimensions: `small` 32px, `medium` 48px, `large` 64px, `xlarge` 96px (REQ-QLNK-004)
- [ ] 2.6 Map `iconShape` field to CSS `border-radius`: `square` 0, `rounded` 8px, `circle` 50% (REQ-QLNK-004)
- [ ] 2.7 Implement `labelPosition: 'below'` — labels visible at all times below icons (REQ-QLNK-005)
- [ ] 2.8 Implement `labelPosition: 'overlay'` — labels hidden by default, visible on `:hover` and `:focus` (REQ-QLNK-005)
- [ ] 2.9 Respect `showLabels: false` to suppress all labels regardless of position (REQ-QLNK-005)
- [ ] 2.10 Implement `hoverEffect: 'lift'` — translate up 2–4px + drop shadow on hover (REQ-QLNK-007)
- [ ] 2.11 Implement `hoverEffect: 'fade'` — non-hovered links fade to 0.6 opacity on hover (REQ-QLNK-007)
- [ ] 2.12 Implement `hoverEffect: 'border'` — add 2–3px border to hovered link (REQ-QLNK-007)
- [ ] 2.13 Implement `hoverEffect: 'none'` — no visual change on hover except cursor to pointer (REQ-QLNK-007)
- [ ] 2.14 Render each link as an `<a>` element with `href` and `aria-label` (REQ-QLNK-010)
- [ ] 2.15 Set `target="_blank"` and `rel="noopener noreferrer"` for external URLs (REQ-QLNK-009)
- [ ] 2.16 Suppress all click handlers when `isAdmin === true` and surrounding dashboard is in edit mode (REQ-QLNK-009)
- [ ] 2.17 Implement empty state: display `t('No quicklinks yet — click the gear icon to add some.')` when `links.length === 0` (REQ-QLNK-011)
- [ ] 2.18 Empty state MUST include a clickable gear icon that opens the edit form (REQ-QLNK-011)
- [ ] 2.19 Icon resolution: custom URL → `<img>`, MDI name → `<IconRenderer>`, empty → fallback "link" icon (REQ-QLNK-003)
- [ ] 2.20 Apply icon background colour when `tileBackgroundStyle: 'solid'` and link's `color` field is set (REQ-QLNK-002, REQ-QLNK-004)

## 3. Edit form (QuicklinksForm.vue)

- [ ] 3.1 Create `src/components/Widgets/Forms/QuicklinksForm.vue` as a Vue 3 SFC
- [ ] 3.2 Implement link management table with columns: label, URL, icon picker, optional color picker (REQ-QLNK-002)
- [ ] 3.3 Implement drag-to-reorder: links array MUST be reorderable via drag handles (REQ-QLNK-002)
- [ ] 3.4 Implement row add/delete buttons to add new links and delete existing links (REQ-QLNK-002)
- [ ] 3.5 Implement bulk-add via CSV paste: accept comma-separated `label,url` lines, parse and append to links array (REQ-QLNK-002)
- [ ] 3.6 Implement icon picker: click → opens modal to select built-in MDI name or upload custom URL (REQ-QLNK-003)
- [ ] 3.7 Implement colour picker for per-link `color` field (optional; only show if `tileBackgroundStyle: 'solid'` is selected) (REQ-QLNK-002)
- [ ] 3.8 Implement per-URL validation: inline red border + error text for invalid URLs (REQ-QLNK-008)
- [ ] 3.9 Implement URL sanitisation: reject `javascript:`, `data:`, `vbscript:` and non-HTTP(S)/relative URLs (REQ-QLNK-008)
- [ ] 3.10 On form submit with invalid URLs, display toast: `t('Invalid URL in one or more links')` (REQ-QLNK-008)
- [ ] 3.11 `validate()` method MUST return non-empty error array if any link has empty or invalid URL (REQ-QLNK-008)
- [ ] 3.12 Implement dropdowns for all enum fields: `iconSize`, `iconShape`, `showLabels`, `labelPosition`, `columns`, `tileBackgroundStyle`, `hoverEffect` (REQ-QLNK-002)
- [ ] 3.13 Pre-fill all form fields from `editingWidget.content` when editing (REQ-QLNK-002)

## 4. URL sanitisation composable

- [ ] 4.1 Create `src/composables/useUrlSanitiser.js` exporting `sanitiseUrl(url)` and `validateUrl(url)` (REQ-QLNK-008)
- [ ] 4.2 `sanitiseUrl()` MUST reject dangerous protocols: `javascript:`, `data:`, `vbscript:` (REQ-QLNK-008)
- [ ] 4.3 `validateUrl()` MUST require `http://`, `https://`, or `/` prefix (REQ-QLNK-008)
- [ ] 4.4 `validateUrl()` MUST reject empty, null, or oversized (>2048 char) URLs (REQ-QLNK-008)
- [ ] 4.5 Both functions MUST be used by the form (client-side) and a backend route (if implemented) (REQ-QLNK-008)

## 5. Optional server-side validation endpoint

- [ ] 5.1 Create `lib/Controller/WidgetController.php::validateUrls` mapped to `POST /api/widgets/quicklinks/validate-urls` (optional; use for real-time form validation)
- [ ] 5.2 Accept request body `{urls: []}` and return `{valid: boolean[], errors: string[]}` (REQ-QLNK-008)
- [ ] 5.3 Reuse sanitisation logic from composable (shared via a PHP utility class) (REQ-QLNK-008)

## 6. Translations

- [ ] 6.1 Add translation entries in `l10n/en.js`:
  - `Quicklinks`
  - `No quicklinks yet — click the gear icon to add some.`
  - `Add link`
  - `Delete link`
  - `Label`
  - `URL`
  - `Icon`
  - `Color (optional)`
  - `Icon Size`
  - `Icon Shape`
  - `Show Labels`
  - `Label Position`
  - `Columns`
  - `Tile Background`
  - `Hover Effect`
  - `Paste CSV (label,url)`
  - `Invalid URL in one or more links`
  - `Small`
  - `Medium`
  - `Large`
  - `Extra Large`
  - `Square`
  - `Rounded`
  - `Circle`
  - `Below`
  - `Overlay`
  - `Auto`
  - `Transparent`
  - `Solid`
  - `Gradient`
  - `Lift`
  - `Fade`
  - `Border`
  - `None`
  - `Link to`

- [ ] 6.2 Add Dutch translations in `l10n/nl.js` with equivalent keys and locale-appropriate text

## 7. Tests

- [ ] 7.1 Vitest: icon size mapping (32, 48, 64, 96 px) renders correctly
- [ ] 7.2 Vitest: icon shape CSS values (`border-radius: 0 / 8px / 50%`)
- [ ] 7.3 Vitest: label position `below` renders labels visible; `overlay` hides labels (visible only on `:hover`)
- [ ] 7.4 Vitest: `showLabels: false` suppresses all labels
- [ ] 7.5 Vitest: flex-wrap layout for `columns: 'auto'`; CSS Grid for `columns: 3` (e.g.)
- [ ] 7.6 Vitest: hover effects — `lift` translates, `fade` reduces opacity, `border` adds outline, `none` is noop
- [ ] 7.7 Vitest: click navigation — external URL calls `window.open(..., '_blank')`, internal URL navigates in same tab
- [ ] 7.8 Vitest: click suppressed in edit mode (admin + `canEdit: true`)
- [ ] 7.9 Vitest: empty state renders when `links.length === 0`; gear icon opens edit form
- [ ] 7.10 Vitest: icon resolution — custom URL renders `<img>`, MDI name renders `<IconRenderer>`, empty renders fallback
- [ ] 7.11 Vitest: form validation rejects empty URL, `javascript:` protocol, `data:` protocol
- [ ] 7.12 Vitest: form drag-to-reorder reorders links array correctly
- [ ] 7.13 Vitest: form bulk-add parses CSV `label,url` lines and appends to array
- [ ] 7.14 Vitest: aria-label uses visible label when available, hostname when overlay
- [ ] 7.15 Vitest: keyboard Tab navigation cycles through links in source order
- [ ] 7.16 Playwright: E2E flow — add new widget, add 3 links via form, save, render shows all 3 with correct icons and labels
- [ ] 7.17 Playwright: E2E bulk-add — paste CSV `"Docs,https://docs.example.com\nCalendar,/apps/calendar"`, verify 2 links added
- [ ] 7.18 Playwright: E2E hover effects — hover link with `lift` effect, verify translate + shadow
- [ ] 7.19 Playwright: E2E click external link, verify `window.open` in new tab
- [ ] 7.20 Playwright: E2E click internal link, verify same-tab navigation

## 8. Quality

- [ ] 8.1 ESLint clean (no warnings or errors)
- [ ] 8.2 All Vue 3 SFCs use script setup syntax (if preferred by codebase) or `<script>` + `<template>`
- [ ] 8.3 Accessibility: all links are `<a>` with `aria-label`, keyboard navigation works, focus styles visible
- [ ] 8.4 CSS uses Nextcloud design tokens (variables) for colours, spacing, fonts
- [ ] 8.5 OpenAPI spec updated for optional `POST /api/widgets/quicklinks/validate-urls` endpoint (if implemented)
- [ ] 8.6 No console errors or warnings when widget renders
- [ ] 8.7 Responsive design: widget scales correctly from small (mobile) to large (desktop) widths
- [ ] 8.8 WCAG AA contrast ratio for all text, focus indicators, and hover states

