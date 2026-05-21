# Design — links-widget

## Context

MyDash widget placements today are bound to widgets discovered via Nextcloud's `IManager::getWidgets()` (see `widgets` capability) — every placement points at a registered Nextcloud dashboard widget by id and renders whatever that widget callback emits. There is also a growing family of built-in widget types whose content lives entirely in the placement `widgetContent` JSON (e.g. `text-display-widget`, `image-widget`), with no external widget callback.

Customers have asked repeatedly for a widget that renders a curated collection of links, with optional sections, descriptions, and custom icons. The existing `quicklinks-widget` is optimised for bare-icon navigation (high density, no descriptions); the `link-button-widget` is a single clickable tile. Neither scales to "link directory" layouts where the user wants to display 20+ links across named sections, each with optional descriptions and iconography.

The right primitive is a built-in MyDash widget type whose entire content lives in the placement record's `widgetContent` JSON, with three layout modes so it adapts to user preference: full cards with descriptions (discovery-friendly), compact icon+label rows (balanced), or icon-only with hover labels (dense). No backend endpoint, no migration, no external data dependency.

This change introduces that primitive as a new widget type `links` and a new capability `links-widget`. The capability is intentionally narrow — one widget type, one renderer, one sub-form, one registry entry — so it can be evolved or deprecated independently of the broader widget-rendering machinery.

## Goals / Non-Goals

**Goals:**

- Ship a `links` widget type that renders multi-column grids of link cards organised into named sections.
- Support three layout modes (card, inline, icon-only) so users can choose between rich descriptions (cards), balanced display (inline), and dense navigation (icons).
- Provide a rich configuration UI allowing users to add/edit/delete sections and links, reorder them via drag-and-drop, and preview changes in real-time.
- Support icon resolution via Nextcloud icon names (rendered as SVG) or external image URLs (rendered as `<img>`).
- Validate and sanitise all URLs at save time (reject `javascript:`, `data:`, `file://`, empty URLs) to defend against XSS.
- Handle empty sections gracefully — sections with zero links are hidden at render time but remain in the configuration for later editing.
- Display a user-friendly empty-state message when the widget has no visible links.
- Distinguish between external and internal URLs at render time so external links use `rel="noopener noreferrer"` while internal links preserve cross-tab messaging.
- Default to theme-aware values (`showSectionTitles: true`, `showLinkDescriptions: true`) so the widget looks rich out-of-the-box.
- Keep the widget type self-contained — no new database tables, no new backend services, no new API endpoints. The persisted content lives in the existing `oc_mydash_widget_placements.widgetContent` JSON column.

**Non-Goals:**

- Dynamic data binding (pull links from an external API or CRM at render time) — links are static, authored once, and live in the widget configuration.
- Rich-text descriptions — descriptions are plain text; future changes could add HTML sanitisation if demanded, but it's out of scope here.
- Per-user link filtering or personalisation — all users see the same links (the dashboard placement itself can be scope-restricted via existing dashboard sharing mechanisms).
- Keyboard navigation inside the link grid (Tab/arrow-key walking) — browsers handle basic keyboard access; advanced keyboard UX is a possible future enhancement.
- Keyboard shortcut registration for common destinations — out of scope; use the `internal-actions` registry if needed.
- Link analytics or click tracking — no telemetry or logging of link usage.

## Decisions

### D1: Use CSS Grid for responsive multi-column layout instead of flexbox

**Decision**: Render the link grid using CSS Grid with `grid-template-columns: repeat(N, 1fr)` where N is the user-configured column count.

**Alternatives considered:**

- Flexbox with flex-wrap. Rejected because it doesn't guarantee equal-width columns; items wrap based on content width, leading to ragged right edges in the last row.
- Fixed-width cells (e.g., `width: 300px`). Rejected because it doesn't adapt to different widget cell sizes on different dashboard scales.
- A masonry-style layout (CSS Columns or JS-based). Rejected because it's overkill for a simple grid and doesn't preserve reading order (left-to-right, top-to-bottom).

**Rationale**: CSS Grid with `1fr` units provides automatic equal-width columns, responsive reflow, and predictable item placement. Users expect a grid of cards to fill left-to-right, then wrap to the next row — that's exactly what Grid delivers.

### D2: Three layout modes (card, inline, icon-only) rather than a single "compact mode"

**Decision**: Support three distinct link layout modes configured at the widget level:
- `card`: icon + label (bold) + description (if configured) + card styling
- `inline`: icon + label (left-aligned) beside icon + no description
- `icon-only`: icon only + label as hover tooltip

**Alternatives considered:**

- Single layout with size toggle (small/medium/large). Rejected because users want fundamentally different presentation modes, not just scaling — card mode demands space for descriptions, icon-only demands minimal footprint.
- Per-link layout override. Rejected because it complicates the config UI and the preview — users want consistency across a section.
- Card layout only (simplest). Rejected because customers repeatedly ask for dense icon-based navigation for internal Nextcloud tools (Files, Talk, etc.).

**Rationale**: Three modes address distinct use cases without exploding complexity. The config UI makes the mode a simple radio button or select; the renderer has three render branches (card, inline, icon-only) that share icon and label logic but differ in DOM structure.

### D3: Icons resolved via Nextcloud icon name OR external URL, not auto-detected

**Decision**: The `icon` field of a link can be:
1. Empty/null → render generic link icon (fallback)
2. Nextcloud icon name (bare word, no slashes; e.g., `icon-files`, `icon-download`) → render via shared IconRenderer as MDI SVG
3. URL starting with `/` or `http` → render as `<img>` element
4. Unrecognized format → fall back to generic link icon (not error, not crash)

**Alternatives considered:**

- Auto-detect icon type (does it look like an icon name or a URL?). Rejected because auto-detection is fragile — a bare word that happens to be a valid URL creates ambiguity, and regex heuristics fail on obscure cases.
- Icon name only, no URLs. Rejected because some teams want to link to logos from external services (GitHub, GitLab, Jira, etc.) and would need to manually upload PNG files.
- URL only, no icon names. Rejected because Nextcloud has a rich set of built-in MDI icons and forcing users to upload PNGs for common icons like `icon-files` wastes space and breaks theme consistency.

**Rationale**: Explicit type discrimination (icon name vs URL) is clearer than heuristics. The form can provide two separate input fields (icon picker + URL field) or a single smart field with visual hints ("Nextcloud icon name or image URL"). The renderer's precedence rules are unambiguous.

### D4: Empty sections are hidden at render time but kept in configuration

**Decision**: A section with zero links is not rendered (no heading, no grid). However, the section object remains in the `sections` array of the stored configuration.

**Alternatives considered:**

- Delete empty sections automatically. Rejected because users might be in the middle of populating a section and accidentally delete the heading by removing the last link.
- Show empty sections with a "No links" placeholder. Rejected because it wastes dashboard space and confuses users — the empty-widget-state message is the right affordance for "nothing to display".

**Rationale**: Keeping empty sections in config allows users to add links later without reconfiguring the section title. The render-time filter (hide empty sections) keeps the display clean without destroying user work.

### D5: Drag-to-reorder via inline HTML5 `draggable` attribute, not a separate library

**Decision**: Sections and links both use `draggable="true"` with `@dragstart`, `@dragover`, `@drop`, `@dragend` event handlers. Reordered items are moved within the array and the config is updated reactively.

**Alternatives considered:**

- SortableJS / Vue.Draggable. Rejected because the form is already lightweight; adding a JS drag library increases bundle size and complexity for a simple reorder interaction.
- Drag-handle buttons + keyboard (Ctrl+↑/↓). Rejected because drag-and-drop is the more intuitive UX; keyboard shortcuts can be added later if demanded.

**Rationale**: Native drag-and-drop is supported by all modern browsers, requires no npm dependency, and is simple to test. Users drag the visual ⋮⋮ handle to reorder; visual feedback (drop target highlight, cursor change) is provided via CSS hover/dragover states.

### D6: Client-side URL validation at form save, not server-side

**Decision**: When the user saves the widget configuration, the form validates all URLs before emitting the `update:content` event. Malicious schemes (`javascript:`, `data:`, `file://`) are rejected with a user-facing error ("Invalid URL format"); empty URLs are rejected with "URL is required".

**Alternatives considered:**

- Server-side validation. Rejected because there is no server endpoint — configuration is saved directly to the placement record via the existing `PATCH /api/mydash/placements/:id` endpoint, which already performs generic JSON schema validation but not URL-specific sanitisation. Adding URL checks to that endpoint would couple it to widget-specific logic.
- No validation. Rejected because malicious URLs are an XSS vector.

**Rationale**: Client-side validation provides immediate feedback ("Invalid URL") before the user commits the change, preventing invalid state from ever being saved. The form can show a red border on the URL input and disable the "Save" button while errors exist.

### D7: Distinct empty-state message when no visible links remain

**Decision**: When a widget has `sections: []` OR all sections have zero links (all hidden), render an italic placeholder: "No links yet — click the gear icon to add some." with low-contrast colour (`var(--color-text-maxcontrast)`).

**Alternatives considered:**

- Render nothing (completely invisible cell). Rejected because the user cannot discover that a widget exists if it has no content.
- Show a default "Edit me" string. Rejected because that string would persist into exports/screenshots if the user never configures the widget.
- Show an "Add widget" button inside. Rejected because edit affordance is dashboard-specific (varies by dashboard framework).

**Rationale**: A placeholder that looks obviously like a placeholder ("No links yet") cues the user that action is needed without being intrusive. The low-contrast colour and italic style signal "not real content". The gear icon reference assumes the dashboard framework provides an edit button — if not, the message is still helpful.

### D8: Icon size mapped to fixed pixel dimensions (24/40/64 px) with CSS custom properties

**Decision**: The `iconSize` config field is one of `'small' | 'medium' | 'large'`, mapped to:
- `small` → `24px`
- `medium` → `40px`
- `large` → `64px`

These dimensions are applied via CSS custom properties (`--icon-size: 24px`) set on the icon container.

**Alternatives considered:**

- Free-form pixel input (like text-display-widget's fontSize). Rejected because icon sizes have a natural quantisation: too-small icons are unclickable, too-large icons break card layouts.
- Continuous slider (16–64 px). Rejected because it offers false precision; most users want one of three preset sizes.

**Rationale**: Three presets cover the vast majority of use cases (navigation icon, decorative icon, large callout icon). The form presents them as radio buttons or a select dropdown, simplifying the UX. CSS custom properties allow the renderer to scale SVG and `<img>` elements uniformly.

### D9: External URLs use `rel="noopener noreferrer"`, internal URLs use no rel attribute

**Decision**: When rendering a link with `openInNewTab: true`:
- External URLs (starting with `http:` or `https:`) use `rel="noopener noreferrer"` to prevent the target site from accessing `window.opener`.
- Internal URLs (relative paths starting with `/` or `../`) are opened with `target="_blank"` but without `rel="noopener"`, so the new tab can communicate back to the dashboard via `window.opener` if needed.

**Alternatives considered:**

- Always use `rel="noopener noreferrer"`. Rejected because it breaks use cases where an internal app wants to communicate back (e.g., a custom workflow that updates the dashboard after the user performs an action in Files).
- Never use rel attributes. Rejected because it's an XSS vector — the opened site can steal tokens from `window.opener`.

**Rationale**: The `noopener` attribute is a security best practice for external links (prevents clickjacking and token theft). Internal links are same-origin (Nextcloud instance), so `opener` access is safe and sometimes useful. The heuristic (http/https vs relative path) is simple and covers 99% of cases.

### D10: Description field is optional, only displayed in card layout

**Decision**: Each link has an optional `description: string` field. Descriptions are only rendered when:
1. `linkLayout: 'card'` (other modes ignore descriptions)
2. `showLinkDescriptions: true` (card-only setting)

In `inline` and `icon-only` modes, descriptions are never displayed, even if present in the config.

**Alternatives considered:**

- Display descriptions in inline mode as a second line. Rejected because it breaks the "compact" nature of inline mode.
- A per-link visibility toggle for descriptions. Rejected because it's UI bloat; the widget-level setting is sufficient.

**Rationale**: Descriptions are a rich-context feature best suited to card layouts where there's space. Inline and icon-only modes are designed for density; adding descriptions would break that constraint. The card-level `showLinkDescriptions` toggle lets admins simplify the display even in card mode if space is tight.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| URL sanitisation is client-side only; a malicious user could inject a bad URL via direct API call, bypassing the form | The placement PATCH endpoint should perform the same URL validation server-side (or reject all placement POSTs/PATCH from non-dashboard-admin users). Out of scope for this change but critical for real deployment. |
| Drag-to-reorder relies on browser native drag-and-drop; older IE or mobile browsers may not support it well | The form includes fallback `↑`/`↓` buttons for reorder if drag fails. Mobile users can use buttons. All modern browsers (Chrome, Firefox, Safari, Edge 2020+) support native drag-and-drop fully. |
| Free-form section title strings can be very long, breaking card layout | CSS `text-overflow: ellipsis` and max-height constraints on the grid title. Long titles are truncated with a tooltip on hover. |
| Icon resolution requires a per-link "is this an icon name or a URL?" heuristic, which could fail on edge cases | The heuristic is explicit (icon name = bare word, no slashes; URL = starts with `/` or `http`). Ambiguous inputs (e.g., `my-icon` which happens to be a URL path) require the user to use the full URL form. Clear enough in practice. |
| Responsive layout on mobile may collapse all columns to one, making the grid hard to browse on small screens | CSS media queries adjust grid columns on mobile (e.g., `@media (max-width: 640px) { grid-template-columns: 1fr; }`). User configured N columns is a hint, not a hard requirement. |
| Very large icons (64px) in icon-only mode may dominate mobile screens | Icon size is a configuration option; users can choose `small` for mobile-friendly layouts. Out-of-the-box default is `medium` (40px), which is safe. |

## Data Model

Configuration shape stored in `oc_mydash_widget_placements.widgetContent`:

```json
{
  "type": "links",
  "content": {
    "sections": [
      {
        "title": "string (non-empty)",
        "links": [
          {
            "label": "string (non-empty, required)",
            "url": "string (non-empty, sanitised, required)",
            "icon": "string (optional, Nextcloud icon name or URL)",
            "description": "string (optional, plain text)"
          }
        ]
      }
    ],
    "columns": "number (1–6, default 3)",
    "linkLayout": "'card' | 'inline' | 'icon-only' (default 'card')",
    "iconSize": "'small' | 'medium' | 'large' (default 'medium')",
    "openInNewTab": "boolean (default true)",
    "showSectionTitles": "boolean (default true)",
    "showLinkDescriptions": "boolean (default true)"
  }
}
```

### Seed Data (for testing and documentation)

Example configuration with three sections and mixed content:

```json
{
  "type": "links",
  "content": {
    "sections": [
      {
        "title": "Collaboration Tools",
        "links": [
          {
            "label": "Talk",
            "url": "/apps/talk",
            "icon": "icon-talk",
            "description": "Real-time chat and video calls"
          },
          {
            "label": "Mail",
            "url": "/apps/mail",
            "icon": "icon-mail",
            "description": "Email client integrated with Nextcloud"
          },
          {
            "label": "Calendar",
            "url": "/apps/calendar",
            "icon": "icon-calendar",
            "description": "Shared calendar and scheduling"
          }
        ]
      },
      {
        "title": "File Management",
        "links": [
          {
            "label": "Files",
            "url": "/apps/files",
            "icon": "icon-files",
            "description": "Cloud storage and file sharing"
          },
          {
            "label": "Memories",
            "url": "/apps/memories",
            "icon": "icon-folder-image",
            "description": "Photo gallery and timeline"
          }
        ]
      },
      {
        "title": "External Resources",
        "links": [
          {
            "label": "GitHub",
            "url": "https://github.com",
            "icon": "https://github.com/favicon.ico",
            "description": "Code repository and CI/CD"
          },
          {
            "label": "Jira",
            "url": "https://jira.example.com",
            "icon": "https://jira.example.com/favicon.ico",
            "description": "Issue tracking and project management"
          },
          {
            "label": "Documentation",
            "url": "https://docs.example.com",
            "icon": "",
            "description": "Knowledge base and guides"
          }
        ]
      }
    ],
    "columns": 3,
    "linkLayout": "card",
    "iconSize": "medium",
    "openInNewTab": true,
    "showSectionTitles": true,
    "showLinkDescriptions": true
  }
}
```

Alternative configuration: dense icon-only mode for quick navigation

```json
{
  "type": "links",
  "content": {
    "sections": [
      {
        "title": "Quick Access",
        "links": [
          {
            "label": "Files",
            "url": "/apps/files",
            "icon": "icon-files"
          },
          {
            "label": "Talk",
            "url": "/apps/talk",
            "icon": "icon-talk"
          },
          {
            "label": "Mail",
            "url": "/apps/mail",
            "icon": "icon-mail"
          },
          {
            "label": "Calendar",
            "url": "/apps/calendar",
            "icon": "icon-calendar"
          },
          {
            "label": "Admin Panel",
            "url": "/settings/admin",
            "icon": "icon-settings"
          }
        ]
      }
    ],
    "columns": 5,
    "linkLayout": "icon-only",
    "iconSize": "medium",
    "openInNewTab": false,
    "showSectionTitles": false,
    "showLinkDescriptions": true
  }
}
```

## Migration

No data migration. The `widgetContent` column already exists; existing placements continue to work unchanged. New `links`-type placements simply use a new shape inside that JSON column.

## Open Questions

- Should the renderer support a `rel="noreferrer"` option for internal links to prevent referrer leaks? Out of scope here; can land in a follow-up if privacy-conscious teams demand it.
- Should icons support emoji (e.g., `icon: "🎯"`)? Out of scope; future enhancement if users request it.
- Should we rate-limit or sandbox external image URLs to prevent SSRF or runaway downloads? Out of scope for MVP; can be added if security audit flags it.
- Should the form offer a "duplicate section" action to speed up config when many sections have similar link structures? Out of scope; users can manually copy-paste link configs if needed.
