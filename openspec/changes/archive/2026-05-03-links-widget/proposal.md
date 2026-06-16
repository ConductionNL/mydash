# Links Widget

## Why

MyDash users often need to organize and present collections of related links (e.g., to tools, documents, external resources, or internal app shortcuts) in a curated, visually grouped manner. The existing link-button-widget handles single action buttons; the upcoming link-button-widget-list-mode extends that widget with a simple list view. This proposal adds a NEW widget type optimized for "link directory" layouts: a multi-column grid of link cards organized into named sections, with configurable icons, descriptions, and display styles. Users need this capability to create organized link hubs on their dashboards without resorting to external iframes or multiple single-button widgets.

## What Changes

- Register a new dashboard widget with id `mydash_links` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` to specify sections (each with title and array of links), column layout, and display mode preferences.
- Implement fully static rendering (no backend data endpoint required — all configuration is provided at placement edit time).
- Provide Vue 3 SFC `LinksWidget.vue` with three link layout modes: `card` (icon + label + description), `inline` (flat rows of icon + label), and `icon-only` (grid of icons with hover tooltips).
- Support configurable icon resolution: Nextcloud built-in icon names (e.g., `icon-files`), custom URLs (via `<img>`), or fall back to generic link icon.
- Implement tabular edit form with drag-to-reorder for both sections and links within each section.
- Guard against empty sections: sections with zero links are hidden at render time but retained in config for later editing.
- Display empty widget state ("No links yet — click the gear icon to add some.") when no sections or links exist.
- Render via CSS Grid with configurable column count (1–6, default 3).
- Enforce URL sanitisation on save: reject non-HTTP(S) and non-relative URLs; return HTTP 400 on invalid input.
- Handle navigation correctly: external URLs use `rel="noopener noreferrer"`, internal URLs (same NC instance) preserve `window.opener` for cross-tab messaging. Optionally open in new tab based on configuration.

## Capabilities

### New Capabilities

- `links-widget` — A new MyDash dashboard widget capability providing a multi-column curated link grid organized into named sections with configurable layout, icon resolution, and edit UI.

## Impact

**Affected code:**

- `src/components/widgets/LinksWidget.vue` — main render component with three layout modes (card, inline, icon-only), CSS Grid layout, and navigation handling.
- `src/components/widgets/links/LinksWidgetConfig.vue` — tabular edit form with drag-to-reorder for sections and links; URL validation.
- `src/components/widgets/links/SectionEditor.vue` — sub-component for editing a single section (title + nested link rows).
- `src/components/widgets/links/LinkEditor.vue` — sub-component for editing a single link (label, url, icon, description).
- `src/stores/widgets.js` — no new store methods required; widget uses placement config directly.
- No migration or schema changes required.

**Affected APIs:**

- 0 new routes (fully static, no backend data endpoint).
- 0 changes to existing routes.

**Dependencies:**

- No new composer or npm dependencies.
- Uses standard Nextcloud icon classes (MDI via shared NC components).
- Standard HTML5 `<a>` elements and CSS Grid for layout.

**Migration:**

- Zero-impact: no schema changes, no data migrations, no app config required.
- Existing dashboards are unaffected; users opt-in by adding the widget to a dashboard.

## Definitions

- **Section**: A named group of links rendered as a labelled container. Empty sections (zero links) are hidden at render time but retained in config.
- **Link**: A single navigation target with label, URL, optional icon, and optional description.
- **Layout mode**: How individual links are rendered: `card` (icon + label + description in a compact card), `inline` (flat list of icon+label rows), or `icon-only` (grid of icons with hover tooltip).
- **Icon resolution**: The process of determining how to render an `icon` field: if it's a Nextcloud built-in name, use MDI class; if it's a URL, render `<img>`; if blank, fall back to generic icon.
- **URL sanitisation**: Validation that rejects non-HTTP(S) and non-relative URLs (e.g., `javascript:`, `data:`, `file://`).
