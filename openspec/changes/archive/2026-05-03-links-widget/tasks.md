# Tasks — links-widget

## 1. Widget registration

- [ ] 1.1 Register the widget in `AppInfo/Bootstrap.php` or via a listener on Nextcloud's widget discovery:
  - Call `IManager::registerWidget()` (or equivalent discovery hook) with widget id `mydash_links`
  - Provide widget metadata: title (translatable `app.mydash.links_widget_title`), icon URL
- [ ] 1.2 Create PHPUnit test:
  - `testLinksWidgetIsRegistered` — verify widget appears in `IManager::getWidgets()`

## 2. Placement configuration schema and parsing

- [ ] 2.1 Define canonical configuration structure in placement `widgetContent JSON`:
  ```json
  {
    "sections": [
      {
        "title": "string",
        "links": [
          {
            "label": "string",
            "url": "string (HTTP(S) or relative)",
            "icon": "string (empty, Nextcloud icon name, or URL)",
            "description": "string (optional)"
          }
        ]
      }
    ],
    "columns": number (1-6, default 3),
    "linkLayout": "card" | "inline" | "icon-only" (default "card"),
    "iconSize": "small" | "medium" | "large" (default "medium", maps to 24/40/64 px),
    "openInNewTab": boolean (default true),
    "showSectionTitles": boolean (default true),
    "showLinkDescriptions": boolean (default true, only honoured in "card" layout)
  }
  ```
- [ ] 2.2 Add helper function in `WidgetPlacementService` or config parser:
  - `extractLinksConfig(WidgetPlacement $placement): array` — deserialize `widgetContent` with defaults
  - Validate schema: sections is array, each section has title+links, links have label+url, columns in range 1–6, layout is valid enum, iconSize is valid enum, booleans are booleans
  - Return parsed config or throw validation exception
- [ ] 2.3 Create PHPUnit test:
  - `testExtractLinksConfigWithDefaults` — verify defaults applied
  - `testExtractLinksConfigInvalidColumns` — columns > 6 rejected
  - `testExtractLinksConfigEmptySections` — empty sections array is valid

## 3. URL sanitisation (backend validation on config save)

- [ ] 3.1 Add URL validator in `WidgetPlacementService::validateLinksConfig()`:
  - For each link in each section, validate `url`:
    - If starts with `/`, must not be path traversal (no `..`); accept relative
    - If starts with `http://` or `https://`, parse and accept
    - If any other scheme (javascript, data, file, etc.), reject with HTTP 400
    - Empty URLs are rejected
  - Return validation error with HTTP 400 if any URL invalid
- [ ] 3.2 Call this validator on PUT/PATCH placement to prevent bad URLs at save time
- [ ] 3.3 Create PHPUnit test:
  - `testValidateUrlHttps` — https://example.com accepted
  - `testValidateUrlRelative` — /apps/myapp/doc accepted
  - `testValidateUrlJavascript` — javascript:alert(...) rejected
  - `testValidateUrlData` — data:text/html rejected
  - `testValidateUrlEmpty` — empty string rejected

## 4. Frontend widget component (Vue 3 SFC)

- [ ] 4.1 Create `src/components/widgets/LinksWidget.vue`:
  - Props: `placement: object` (with widgetContent config)
  - Computed: `config` (parsed from placement widgetContent with defaults)
  - Computed: `visibleSections` — filter to sections with > 0 links
  - Computed: `columnCount` — layout CSS variable
  - Data: none (fully static, no API calls)
  - Template: 
    - Empty state if no visible sections
    - Else: `<div style="display: grid; grid-template-columns: repeat(var(--cols), 1fr);">`
    - For each visible section: section header (if showSectionTitles) + list of links
    - For each link: render based on linkLayout mode (card, inline, or icon-only)
  - CSS: CSS Grid, responsive column fallback for mobile, no hardcoded colors (use CSS variables for nldesign)
- [ ] 4.2 Create `src/components/widgets/links/LinkCard.vue` (sub-component, card mode):
  - Props: `link: object`, `iconSize: string`, `showDescription: boolean`, `openInNewTab: boolean`
  - Template: card container with icon + label + description
  - Icon resolution: call `resolveIcon()` helper (see 4.5)
  - Click handler: sanitize URL, determine `rel` attribute based on URL type, call `navigateTo()`
  - CSS: card border, padding, hover effect, responsive for small screens
- [ ] 4.3 Create `src/components/widgets/links/LinkInline.vue` (sub-component, inline mode):
  - Props: `link: object`, `iconSize: string`, `openInNewTab: boolean`
  - Template: flat list item with icon + label only (description ignored)
  - Click handler: same as LinkCard
- [ ] 4.4 Create `src/components/widgets/links/LinkIconOnly.vue` (sub-component, icon-only mode):
  - Props: `link: object`, `iconSize: string`, `openInNewTab: boolean`
  - Template: icon grid item with `<img>` or `<svg>` (size from iconSize)
  - Hover: show tooltip with link label
  - Click handler: same as LinkCard
- [ ] 4.5 Create helper function `resolveIcon(iconField: string): {type: 'svg'|'img', src?: string, class?: string}`:
  - If `iconField` is empty or null: return `{type: 'svg', class: 'icon-link'}` (generic link icon)
  - Else if `iconField` matches Nextcloud icon pattern (bare word, no slashes): return `{type: 'svg', class: 'icon-' + iconField}`
  - Else if `iconField` starts with `/` or `http`: return `{type: 'img', src: iconField}`
  - Else: default to generic link icon
- [ ] 4.6 Create helper function `navigateTo(url: string, openInNewTab: boolean, isExternal: boolean)`:
  - Determine `rel` attribute:
    - If external: `rel="noopener noreferrer"`
    - If internal (same NC instance): no `rel` or `rel=""` (preserve `window.opener`)
  - If `openInNewTab`: `window.open(url, '_blank', 'noopener,noreferrer')` (for external) or `window.open(url, '_blank')` (for internal)
  - Else: `window.location.href = url`
  - Note: for `<a>` elements, set `rel` and `target` attributes instead of using JavaScript
- [ ] 4.7 Create Playwright E2E tests:
  - `testLinksWidgetRenderCard` — widget renders links as cards with all fields visible
  - `testLinksWidgetRenderInline` — widget renders links as flat list (no description)
  - `testLinksWidgetRenderIconOnly` — widget renders icon grid with tooltips on hover
  - `testLinksWidgetEmptyState` — no sections or no links → empty state message
  - `testLinksWidgetExternalLinkOpensNewTab` — click external link → opens new tab with noopener
  - `testLinksWidgetInternalLinkSameWindow` — click internal link (relative URL) → navigates same window or new tab per config
  - `testLinksWidgetHiddenSections` — section with zero links not rendered, but still in config
  - `testLinksWidgetResponsiveColumns` — column count matches config, grid reflows on resize

## 5. Widget configuration UI component

- [ ] 5.1 Create `src/components/widgets/links/LinksWidgetConfig.vue`:
  - Used by `WidgetAddEditModal.vue` when configuring a links widget placement
  - Sections editor:
    - Render array of sections, one per row
    - Each row has: title text input, delete button, drag handle (⋮⋮)
    - Click "Add Section" button to append empty section
    - Links sub-editor per section (see 5.2)
  - Global options panel:
    - `columns`: number input (1–6, default 3)
    - `linkLayout`: radio or dropdown (card, inline, icon-only; default card)
    - `iconSize`: radio or dropdown (small, medium, large; default medium)
    - `openInNewTab`: checkbox (default true)
    - `showSectionTitles`: checkbox (default true)
    - `showLinkDescriptions`: checkbox (default true, disabled if linkLayout !== 'card')
  - Validation on save:
    - All section titles non-empty
    - Each section has ≥ 1 link (or warn user)
    - All link URLs valid (call backend validator or do client-side check)
    - Columns in range 1–6
  - On save: call backend URL sanitisation endpoint, collect errors, emit `update:widgetContent` with serialized config
- [ ] 5.2 Create `src/components/widgets/links/SectionEditor.vue` (sub-component):
  - Props: `section: object` (with title+links), `index: number`
  - Events: `update:section`, `delete`, `reorder-links`
  - Template:
    - Title input field
    - Delete section button
    - Links table below (rows of links in that section)
    - "Add Link" button to append empty link row
  - Link rows:
    - Drag handle (⋮⋮), label input, URL input, icon input, description input (collapsible/optional)
    - Delete button per link
    - Drag-to-reorder links within section
- [ ] 5.3 Create `src/components/widgets/links/LinkEditor.vue` (sub-component):
  - Props: `link: object` (label, url, icon, description), `index: number`, `showDescriptionField: boolean`
  - Events: `update:link`, `delete`, `reorder`
  - Template:
    - Row with inputs: label, url, icon (text input with icon preview), optional description
    - Delete button, drag handle
    - Inline validation feedback (URL format check)
  - Icon preview: show resolved icon (SVG or img) in a small preview area
- [ ] 5.4 Integrate into `WidgetAddEditModal.vue`:
  - When user selects `mydash_links` widget from picker
  - Display `LinksWidgetConfig.vue` instead of default generic config panel
  - Pass placement config to component, listen for `update:widgetContent` to update form state
- [ ] 5.5 Create Playwright test:
  - `testLinksWidgetConfigAddSection` — open config, add section, add links, verify structure
  - `testLinksWidgetConfigDragReorderSection` — drag section to different position, verify order in config
  - `testLinksWidgetConfigDragReorderLink` — drag link to different position within section, verify order
  - `testLinksWidgetConfigDeleteLink` — delete link, verify it's removed from config
  - `testLinksWidgetConfigValidateUrl` — enter invalid URL, see error, fix it, can save
  - `testLinksWidgetConfigIconPreview` — enter Nextcloud icon name, see preview; enter image URL, see img preview

## 6. Internationalization (i18n)

- [ ] 6.1 Add Dutch (nl) and English (en) translation keys:
  - `app.mydash.links_widget_title` — "Links" / "Links"
  - `app.mydash.links_empty_state` — "Geen links gedefinieerd — klik op het tandwielpictogram om er enkele toe te voegen." / "No links yet — click the gear icon to add some."
  - `app.mydash.links_column_count` — "Kolommen" / "Columns"
  - `app.mydash.links_link_layout` — "Lay-out" / "Layout"
  - `app.mydash.links_icon_size` — "Pictogramgrootte" / "Icon size"
  - `app.mydash.links_open_new_tab` — "In nieuw tabblad openen" / "Open in new tab"
  - `app.mydash.links_show_titles` — "Sectietitels weergeven" / "Show section titles"
  - `app.mydash.links_show_descriptions` — "Beschrijvingen weergeven" / "Show descriptions"
  - `app.mydash.links_add_section` — "Sectie toevoegen" / "Add section"
  - `app.mydash.links_add_link` — "Link toevoegen" / "Add link"
  - `app.mydash.links_section_title` — "Sectietitel" / "Section title"
  - `app.mydash.links_link_label` — "Label" / "Label"
  - `app.mydash.links_link_url` — "URL" / "URL"
  - `app.mydash.links_link_icon` — "Pictogram (naam of URL)" / "Icon (name or URL)"
  - `app.mydash.links_link_description` — "Beschrijving (optioneel)" / "Description (optional)"
  - `app.mydash.links_invalid_url` — "Ongeldige URL — gebruik HTTP(S) of relatieve paden." / "Invalid URL — use HTTP(S) or relative paths."
  - `app.mydash.links_layout_card` — "Kaart" / "Card"
  - `app.mydash.links_layout_inline` — "Inline" / "Inline"
  - `app.mydash.links_layout_icon_only` — "Alleen pictogram" / "Icon only"
  - `app.mydash.links_icon_size_small` — "Klein (24 px)" / "Small (24 px)"
  - `app.mydash.links_icon_size_medium` — "Normaal (40 px)" / "Medium (40 px)"
  - `app.mydash.links_icon_size_large` — "Groot (64 px)" / "Large (64 px)"
- [ ] 6.2 Add translation files:
  - `l10n/nl.json` — Dutch translations
  - `l10n/en.json` — English translations (fallback)

## 7. Quality gates and testing

- [ ] 7.1 Run `npm run lint` on all Vue/JS files:
  - `src/components/widgets/LinksWidget.vue`
  - `src/components/widgets/links/*.vue`
  - Fix any warnings or errors
- [ ] 7.2 Run Stylelint on component stylesheets
- [ ] 7.3 Run `composer check:strict` if any PHP code is touched (validator, registration)
- [ ] 7.4 Confirm all hydra-gates pass locally before opening PR
- [ ] 7.5 Add SPDX headers to every new file
- [ ] 7.6 PHPUnit test coverage:
  - Aim for 80%+ line coverage on URL validator logic (if in PHP service)
  - All validation scenarios covered (valid/invalid URLs, schema validation)
- [ ] 7.7 Playwright E2E test coverage:
  - Widget renders in all three layout modes
  - Empty state displays correctly
  - Config UI allows add/edit/delete/reorder
  - Navigation works (external opens new tab, internal same/new per config)
  - Drag-to-reorder works for sections and links
- [ ] 7.8 Manual testing:
  - Create a dashboard
  - Add links widget
  - Configure sections and links with various icon types (name, URL, blank)
  - Test all three layout modes (card, inline, icon-only)
  - Test all icon sizes (small, medium, large)
  - Verify responsive layout on desktop and mobile
  - Test external link opens with noopener
  - Test internal link (relative URL) preserves window.opener if not openInNewTab
  - Hide/show descriptions, section titles, verify UI updates

## 8. Documentation and changelog

- [ ] 8.1 Update `CHANGELOG.md` with:
  - "Add links-widget: curated multi-column link grid with sections, three layout modes, and drag-to-reorder editor"
- [ ] 8.2 Add code comments to `LinksWidget.vue` explaining:
  - Icon resolution strategy and precedence
  - Why empty sections are retained in config (user convenience)
  - URL sanitisation expectations (enforced at save time)
  - Internal vs. external link navigation strategy
