# Tasks — links-widget

## Tasks

- [ ] Task 1: Build `src/components/Widgets/Renderers/LinksWidget.vue` with conditional rendering: if `sections.length === 0` OR all sections have zero links, render empty-state placeholder (italic, `t('mydash', 'No links yet — click the gear icon to add some.')`); otherwise render grid of sections (per REQ-LNKS-009)
- [ ] Task 2: Implement grid layout in LinksWidget with CSS Grid `grid-template-columns: repeat(columns, 1fr)`, mapping `iconSize` config to `--icon-size` CSS custom property (24px for `small`, 40px for `medium`, 64px for `large` per REQ-LNKS-002/004)
- [ ] Task 3: Implement three link layout modes in LinksWidget: `card` (icon + bold label + description + card border/padding), `inline` (icon + label beside, no description), `icon-only` (icon only + label tooltip on hover per REQ-LNKS-005)
- [ ] Task 4: Icon resolution in LinksWidget per REQ-LNKS-004 precedence: (1) empty/null → generic link icon, (2) bare word → Nextcloud icon name via IconRenderer/MDI, (3) URL (starts with `/` or `http`) → `<img>`, (4) unrecognized → fallback to generic icon (no crash)
- [ ] Task 5: Section title rendering in LinksWidget: show section heading only when `showSectionTitles: true` (REQ-LNKS-002); hide entire section with zero links (REQ-LNKS-003); apply `text-overflow: ellipsis` + max-height for long titles
- [ ] Task 6: Description rendering in LinksWidget: only display descriptions in `card` layout mode AND when `showLinkDescriptions: true` (REQ-LNKS-005/002); descriptions MUST NOT appear in `inline` or `icon-only` modes
- [ ] Task 7: Link navigation in LinksWidget (REQ-LNKS-010): external URLs (http/https) with `openInNewTab: true` use `rel="noopener noreferrer"`; internal URLs (relative paths) use no `noopener` to preserve `window.opener`; same-window navigation ignores `rel` attributes; suppress click handler when dashboard is in edit mode
- [ ] Task 8: Responsive layout in LinksWidget: apply CSS media queries so layout collapses to single column on mobile (max-width 640px) while respecting configured column count on desktop (REQ-LNKS-006); grid remains usable on all viewport sizes
- [ ] Task 9: Build `src/components/Widgets/Forms/LinksForm.vue` matching existing AddWidgetModal sub-form contract (`editingWidget` prop, `update:content` emit on every input) with section list, nested link list, add/delete/reorder controls, and real-time config preview
- [ ] Task 10: Section management in LinksForm: "Add Section" button appends new section with title field (placeholder "Section title") + empty links table + delete button; section title updates trigger real-time config emit per REQ-LNKS-008
- [ ] Task 11: Link management in LinksForm: "Add Link" button appends new link with label, url, icon, description fields + icon preview + delete button; each field edit triggers `update:content` emit; deletion removes link from section but preserves empty section per REQ-LNKS-008
- [ ] Task 12: Drag-to-reorder in LinksForm: implement HTML5 `draggable="true"` on section drag handles (⋮⋮); on `@drop`, reorder the sections array and emit updated config; same for links within a section (only move within same section, not across sections per REQ-LNKS-008)
- [ ] Task 13: URL validation in LinksForm per REQ-LNKS-007: reject `javascript:`, `data:`, `file://` schemes; reject empty/null URLs; reject path traversal (multiple `../` chains); accept `http://`, `https://`, `/`, `../`; show red validation error on URL input when invalid; disable "Save" button if any link has invalid URL
- [ ] Task 14: Icon preview in LinksForm per REQ-LNKS-008: when user enters an icon name (e.g., `icon-files`), show small MDI icon preview (resolved via IconRenderer); when user enters a URL (e.g., `https://example.com/logo.png`), show `<img>` preview (or placeholder if URL not yet valid); empty icon shows generic link icon
- [ ] Task 15: Form `validate()` returns array of error messages per AddWidgetModal contract: iterate all sections→links, collect validation errors (required fields, invalid URLs), return `[]` when valid, otherwise `[error1, error2, ...]`; modal uses array length to enable/disable "Save" button
- [ ] Task 16: Register `links` in `src/constants/widgetRegistry.js` with `component: LinksWidget`, `form: LinksForm`, `label: t('mydash', 'Links')`, and defaults `{sections: [], columns: 3, linkLayout: 'card', iconSize: 'medium', openInNewTab: true, showSectionTitles: true, showLinkDescriptions: true}`; confirm AddWidgetModal's type picker surfaces it
- [ ] Task 17: i18n — add all visible UI strings to `l10n/en.json`: `Links` (widget title), `No links yet — click the gear icon to add some.` (empty state), `Add Section`, `Section title` (placeholder), `Add Link`, `Label` (label), `URL` (url), `Icon` (icon), `Description` (description), `Invalid URL`, `Link URL is required`, `Card`, `Inline`, `Icon Only`, `Columns`, `Icon Size`, `Small`, `Medium`, `Large`, `Open in new tab`, `Show section titles`, `Show link descriptions`, `Delete` (action)
- [ ] Task 18: Dutch i18n in `l10n/nl.json`: `Koppelingen`, `Nog geen koppelingen — klik op het tandwielpictogram om er enkele toe te voegen.`, `Sectie toevoegen`, `Sectietitel` (placeholder), `Koppeling toevoegen`, `Label`, `URL`, `Pictogram`, `Beschrijving`, `Ongeldige URL`, `Koppelings-URL is verplicht`, `Kaart`, `Inline`, `Alleen pictogram`, `Kolommen`, `Pictogramgrootte`, `Klein`, `Gemiddeld`, `Groot`, `In nieuw tabblad openen`, `Sectietitels tonen`, `Koppelingsbeschrijvingen tonen`, `Verwijderen`
- [ ] Task 19: Vitest renderer coverage per REQ-LNKS-003/005/006/009: empty config renders placeholder; sections with zero links are hidden from DOM but present in config; card layout displays icon + bold label + description + card styling; inline layout displays icon + label, no description; icon-only layout displays icon + tooltip label, no description; grid uses CSS Grid with configured columns and responds to iconSize changes
- [ ] Task 20: Vitest renderer coverage per REQ-LNKS-004/010: icon names resolve to SVG classes; icon URLs resolve to `<img>` elements; empty icons fall back to generic link icon; external URLs render with `rel="noopener noreferrer"` when `openInNewTab: true`; internal URLs render without `noopener`; click handler suppressed in edit mode
- [ ] Task 21: Vitest form coverage per REQ-LNKS-008: validate() reports required fields correctly (label, url); pre-fills all fields from `editingWidget.content`; emits `update:content` reactively on each input; drag-to-reorder reorders sections and links without losing data; add/delete section and links work correctly
- [ ] Task 22: Vitest form coverage per REQ-LNKS-007: URL validation rejects `javascript:`, `data:`, `file://`, empty URLs; accepts `http://`, `https://`, `/`, relative paths; displays validation error message on invalid URL; "Save" button disabled when validation errors present
- [ ] Task 23: Playwright per REQ-LNKS-002/008: add links widget via AddWidgetModal, configure 3 sections with multiple links, save, reload page, content persists; edit existing widget (open edit modal), change layout mode + column count + icon size, content updates without loss; empty-text widget shows localised placeholder
- [ ] Task 24: Playwright per REQ-LNKS-005/010: verify card layout displays descriptions + card styling; inline layout hides descriptions + flat row styling; icon-only layout shows tooltip on hover; external link opens in new tab with `noopener`; internal link opens in same-origin new tab without `noopener`; responsive layout adapts on mobile viewport
- [ ] Task 25: Quality gates — ESLint clean on new/touched `.vue`/`.js` files in `src/components/Widgets/` and `src/constants/`; Stylelint clean on `<style>` blocks in LinksWidget.vue and LinksForm.vue; `npm run build` succeeds with no new warnings; no new npm dependencies added
- [ ] Task 26: Documentation — add changelog entry describing the new Links widget type, three layout modes, section-based organization, and URL sanitisation; user-guide screenshot showing card layout with three sections; mention feature parity with quicklinks/link-button for customers evaluating widgets

## Verification

`openspec validate` exits clean. Widget appears in the type picker, renders sections with multiple layout modes, sanitises hostile URLs, handles icon resolution correctly, and round-trips through edit mode without state leakage. Empty state displays when widget has no visible links.

## Tests (company-wide ADR-009)

Vitest + Playwright per Tasks 19–24. No backend surface — configuration is stored entirely in JSON.

## Documentation (company-wide ADR-010)

Changelog entry and user-guide screenshot per Task 26.

## i18n (company-wide ADR-007)

`nl_NL` + `en_US` per Tasks 17–18.
