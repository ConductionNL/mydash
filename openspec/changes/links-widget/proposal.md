# Links Widget

A new widget type `links` that renders a multi-column grid of link cards organised into named sections. Distinct from the single-button `link-button-widget` and the high-density `quicklinks-widget`, this widget is optimised for "link directory" layouts: each section carries a heading and an arbitrary number of links, each link carries a label, URL, optional icon, and optional description, and the renderer offers three layout modes (`card`, `inline`, `icon-only`). All configuration lives in the placement `widgetContent` JSON — there is no backend data endpoint and no migration. URL sanitisation runs at save time to defend against `javascript:`, `data:`, and `file://` XSS vectors.

## Why

MyDash customers need a way to curate and display categorised collections of internal and external links on their dashboards. Today's workarounds (using quicklinks for bare-icon navigation, or layering multiple link-button widgets) break down when the user wants to display 20+ links across multiple named sections, each with descriptions and custom iconography. The right primitive is a built-in widget type whose content lives entirely in the placement `widgetContent` JSON, with no external callback, no new database tables, and three layout modes so the widget adapts to density preferences (full cards with descriptions, compact icon+label rows, or icon-only with hover labels).

## What Changes

- Introduce a new widget `type: 'links'` registered in `widgetRegistry.js` with default content `{sections: [], columns: 3, linkLayout: 'card', iconSize: 'medium', openInNewTab: true, showSectionTitles: true, showLinkDescriptions: true}`.
- Implement renderer `LinksWidget.vue` displaying sections as a CSS Grid with multi-column layout, supporting three link layout modes (`card`, `inline`, `icon-only`), icon resolution via Nextcloud icon names or URLs, and section title visibility toggling.
- Add an `IconRenderer` integration for both built-in MDI icon names and uploaded/external image URLs (dual-mode like `dashboard-icons`).
- Implement configuration sub-form `LinksForm.vue` with section management (add/delete), link management (add/edit/delete), drag-to-reorder for both sections and links, and real-time preview.
- Add client-side URL validation and sanitisation (reject `javascript:`, `data:`, `file://` schemes) in the form before saving.
- Implement empty-state message ("No links yet — click the gear icon to add some.") when the widget has no visible links.
- Translation strings for all visible UI labels and messages (English + Dutch).

## Capabilities

### New Capabilities

- `links-widget` — adds REQ-LNKS-001 (widget registration), REQ-LNKS-002 (placement configuration schema), REQ-LNKS-003 (link data structure), REQ-LNKS-004 (icon resolution), REQ-LNKS-005 (three layout modes), REQ-LNKS-006 (multi-column grid), REQ-LNKS-007 (URL sanitisation), REQ-LNKS-008 (edit form with drag-to-reorder), REQ-LNKS-009 (empty widget state), REQ-LNKS-010 (navigation handling and rel attributes).

### Modified Capabilities

(none — this change is fully additive)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/LinksWidget.vue` — new renderer with multi-column grid and three layout modes
- `src/components/Widgets/Forms/LinksForm.vue` — new add/edit sub-form with section and link management, drag-to-reorder
- `src/composables/useIconResolver.js` — extended to support icon resolution for links (may be shared with other widgets)
- `src/constants/widgetRegistry.js` — register `type: 'links'` with default content shape
- `src/utils/urlValidator.js` — new utility for client-side URL sanitisation (reject malicious schemes)
- `l10n/en.json`, `l10n/nl.json` — translation strings for all new UI labels, messages, and error feedback

**Affected APIs:**

(none — no new backend endpoints, all configuration persists in existing `widgetContent` JSON)

**Dependencies:**

- No new npm dependencies beyond existing widget-related packages (Vue, DOMPurify if shared icon rendering is sanitised)

**Migration:**

- Zero schema changes — widget shape lives inside the existing `widgetContent` JSON blob on widget placements.
- Existing dashboards continue to work unchanged; the `links` type only appears once an admin/user explicitly adds a Links widget.

## Scaling Notes

- Configuration is stored as JSON within a single row; no denormalization or indexing required.
- Rendering is entirely client-side; no backend queries per section or per link.
- Empty sections are stored but hidden at render time, allowing users to add links later without reconfiguring the section.
