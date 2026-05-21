# Quicklinks Widget

## Why

Dashboard admins today have the link-button widget (one shortcut per placement) and the links widget (grouped sections across columns), but neither is optimised for the most frequent dashboard use case: a dense, flat "app launcher" view of 8–40 frequently used URLs. The link-button widget creates one placement per URL (bloating dashboard config). The links widget groups by section and spreads across multiple columns (splitting a cohesive set of apps). A dedicated quicklinks widget fills this gap: all shortcuts in a single placement, icon size and label positioning under admin control, bulk-add from CSV for streamlined on-boarding, and hover effects for visual feedback.

## What Changes

- Introduce a new widget type `quicklinks` with persisted shape `{type: 'quicklinks', content: {links, iconSize, iconShape, showLabels, labelPosition, columns, tileBackgroundStyle, hoverEffect}}`.
- Add `src/components/Widgets/Renderers/QuicklinksWidget.vue` rendering a grid of icon-and-label tiles, with support for custom image URLs, MDI icon names, icon sizing (32–96px), shapes (square/rounded/circle), and hover effects (lift/fade/border/none).
- Add `src/components/Widgets/Forms/QuicklinksForm.vue` exposing controls for links (rows with label, URL, icon picker), icon size, shape, label position, columns, tile background, and hover effect; support bulk CSV paste for rapid URL entry.
- Register `quicklinks` in `src/constants/widgetRegistry.js` with defaults `{links: [], iconSize: 'medium', iconShape: 'rounded', showLabels: true, labelPosition: 'below', columns: 'auto', tileBackgroundStyle: 'transparent', hoverEffect: 'lift'}`, icon, and `displayName: t('Quicklinks')`.
- Implement link click handling with auto-open-in-new-tab for external URLs, and suppress navigation in dashboard edit mode.
- Implement URL validation on the client (GIVEN/WHEN/THEN scenarios in REQ-QLNK-008) and server to block dangerous protocols (`javascript:`, `data:`, `vbscript:`).
- Render an empty-state message when `links: []` with a clickable gear icon to prompt edit.
- Implement keyboard accessibility (Tab navigation through links, focus indicators, aria-labels matching label position).

## Capabilities

### New Capabilities

- `quicklinks-widget`: REQ-QLNK-001 (registration + picker), REQ-QLNK-002 (config shape + defaults), REQ-QLNK-003 (icon resolution), REQ-QLNK-004 (icon sizes/shapes), REQ-QLNK-005 (label position control), REQ-QLNK-006 (column layout), REQ-QLNK-007 (hover effects), REQ-QLNK-008 (URL sanitisation), REQ-QLNK-009 (click/navigation), REQ-QLNK-010 (accessibility), REQ-QLNK-011 (empty state).

### Modified Capabilities

(none — the existing link-button and links widgets remain untouched; the quicklinks widget is a parallel, complementary type)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/QuicklinksWidget.vue` — new file, single-file Vue component with grid/flex layout, icon rendering, label logic, click handlers
- `src/components/Widgets/Forms/QuicklinksForm.vue` — new file, sub-form for `AddWidgetModal` with links table, CSV paste handler, six control panels
- `src/constants/widgetRegistry.js` — add `quicklinks` entry with renderer + form references, `defaultContent`, icon, and display name
- `src/l10n/en.json`, `src/l10n/nl.json` — add translation keys for widget name, field labels, placeholders, error messages, empty state
- Server-side widget validation helper — extend or create a URL sanitisation function to reject dangerous protocols (if not already present via link-button REQ-LBN-008)

**Affected APIs:**

- No backend / HTTP API changes. The widget placement persistence layer already accepts arbitrary `{type, content}` blobs and persists them in `oc_mydash_widget_placements.content`.

**Dependencies:**

- No new composer or npm dependencies. Uses existing Nextcloud Vue components (IconPicker, colour picker, etc.).

**Migration:**

- Zero migration impact. Existing widget placements are unaffected. New quicklinks placements coexist with all other widget types in the same `oc_mydash_widget_placements.content` JSON column.

**Accessibility:**

- Every link is rendered as an `<a>` element with proper `href`, `aria-label` (using visible label or hostname), `target`/`rel` attributes, and focus indicators. Tab navigation cycles through links in source order. Meets WCAG AA requirements per ADR-010.

## Notes

- The form's CSV paste feature parses label, URL, icon (comma-separated) and auto-detects external URLs to set `openInNewTab`. An optional fourth column for icon color is supported.
- Icon resolution uses the same dual-mode convention as link-button-widget (REQ-QLNK-003): custom URL or bare MDI name. Falls back to a "link" icon if empty.
- Hover effects apply per-link, not widget-wide, so users see feedback on individual shortcuts.
- The empty state shows a translatable message and a gear icon that opens the edit form on click (matching the pattern from other widgets).
- URL validation rules (REQ-QLNK-008) block `javascript:`, `data:`, `vbscript:` and require a 2048-char limit; server-side validation mirrors the client to prevent bypass.
