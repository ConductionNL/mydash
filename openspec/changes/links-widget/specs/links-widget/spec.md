---
capability: links-widget
status: draft
---

# Links Widget Specification

## ADDED Requirements

### Requirement: REQ-LNKS-001 Widget registration

The system MUST register a MyDash dashboard widget with id `mydash_links` via `OCP\Dashboard\IManager::registerWidget()` so it appears in the widget picker alongside other Nextcloud dashboard widgets.

#### Scenario: Widget appears in picker

- GIVEN the links-widget app is installed and enabled
- WHEN a user opens the MyDash widget picker dialog
- THEN the `mydash_links` widget MUST appear in the list with a title (e.g., "Links") and an icon
- AND the widget MUST be selectable for placement on a dashboard

#### Scenario: Multiple instances allowed

- GIVEN a dashboard already has one links widget placement
- WHEN the user adds a second links widget to the same dashboard
- THEN both placements MUST coexist with independent configurations
- AND each placement MUST render its own sections and links separately

#### Scenario: Widget registration survives reload

- GIVEN the widget is registered
- WHEN Nextcloud cache is cleared or the app is reloaded
- THEN the `mydash_links` widget MUST still be discoverable in the picker

#### Scenario: Widget metadata is complete

- GIVEN the widget is registered
- WHEN the widget picker fetches widget metadata
- THEN the widget object MUST include at minimum: id, title, icon_url

### Requirement: REQ-LNKS-002 Placement configuration schema

The system MUST store per-placement widget configuration in the `oc_mydash_widget_placements.widgetContent` JSON field, allowing users to specify sections with links, layout preferences, and display options.

#### Scenario: Config stores sections array

- GIVEN a user is configuring a links widget placement
- WHEN they add sections and links
- THEN the configuration MUST store a `sections: [{title: string, links: [...]}, ...]` field
- AND each section MUST have a title string and an array of link objects
- AND the config MUST serialize and deserialize cleanly as JSON

#### Scenario: Config stores column count

- GIVEN a user is configuring a links widget placement
- WHEN they set the number of columns
- THEN the configuration MUST store a `columns: number` field
- AND the value MUST be in the range 1–6
- AND the default value MUST be 3

#### Scenario: Config stores link layout mode

- GIVEN a user is configuring a links widget placement
- WHEN they select a display mode
- THEN the configuration MUST store a `linkLayout: 'card'|'inline'|'icon-only'` field
- AND the default value MUST be `'card'`

#### Scenario: Config stores icon size preference

- GIVEN a user is configuring a links widget placement
- WHEN they set icon size
- THEN the configuration MUST store an `iconSize: 'small'|'medium'|'large'` field
- AND the default value MUST be `'medium'`
- AND the size MUST map to pixel dimensions: small=24px, medium=40px, large=64px

#### Scenario: Config stores open-in-new-tab preference

- GIVEN a user is configuring a links widget placement
- WHEN they toggle the new-tab behavior
- THEN the configuration MUST store an `openInNewTab: boolean` field
- AND the default value MUST be `true`

#### Scenario: Config stores section visibility preference

- GIVEN a user is configuring a links widget placement
- WHEN they toggle section title visibility
- THEN the configuration MUST store a `showSectionTitles: boolean` field
- AND the default value MUST be `true`

#### Scenario: Config stores description visibility preference

- GIVEN a user is configuring a links widget placement
- WHEN they toggle description visibility
- THEN the configuration MUST store a `showLinkDescriptions: boolean` field
- AND the default value MUST be `true`
- AND the value MUST only affect the `'card'` layout mode (other modes ignore descriptions)

### Requirement: REQ-LNKS-003 Link data structure

The system MUST support a link object with label, URL, icon, and optional description, and MUST allow zero or more links per section.

#### Scenario: Link has required fields

- GIVEN a link in a section
- WHEN the widget configuration is saved
- THEN each link MUST have at minimum: `label: string` and `url: string`
- AND the label MUST be a non-empty string
- AND the url MUST be non-empty after sanitisation

#### Scenario: Link has optional icon field

- GIVEN a link in a section
- WHEN the widget configuration is saved
- THEN the link MAY have an `icon: string` field (can be empty, a Nextcloud icon name, or a URL)
- AND if present, the icon MUST be resolved per REQ-LNKS-004

#### Scenario: Link has optional description field

- GIVEN a link in a section
- WHEN the widget configuration is saved
- THEN the link MAY have a `description: string` field (empty or text)
- AND the description MUST only be displayed in `'card'` layout mode (REQ-LNKS-006)

#### Scenario: Empty sections are hidden at render time

- GIVEN a section with title but zero links
- WHEN the widget renders
- THEN the section MUST NOT appear in the DOM
- AND the section MUST remain in the stored config (for user convenience)
- NOTE: Users can add links later without reconfiguring the section

### Requirement: REQ-LNKS-004 Icon resolution precedence

The system MUST resolve the `icon` field of a link using the following precedence:

1. If `icon` is empty or null, render a generic link icon (stock Nextcloud icon).
2. Else if `icon` matches a known Nextcloud icon name pattern (bare word, no slashes; e.g., `icon-files`, `icon-download`), render it via the shared IconRenderer as an MDI `<svg>` class.
3. Else if `icon` is a URL (starts with `/` or `http`), render it as an `<img>` element with `src` set to the URL.
4. Else (unrecognized format), fall back to the generic link icon.

#### Scenario: Nextcloud icon name resolved to SVG class

- GIVEN link config `{label: 'Files', url: '/apps/files', icon: 'icon-files'}`
- WHEN the widget renders
- THEN the system MUST render an `<svg>` with class `icon-icon-files` (or equivalent Nextcloud MDI class)
- AND the icon MUST display at the configured iconSize (24/40/64 px)

#### Scenario: URL icon resolved to img element

- GIVEN link config `{label: 'Custom', url: 'https://example.com', icon: 'https://example.com/logo.png'}`
- WHEN the widget renders
- THEN the system MUST render `<img src="https://example.com/logo.png" alt="Custom">`
- AND the img size MUST match the configured iconSize

#### Scenario: Empty icon defaults to generic link icon

- GIVEN link config `{label: 'Document', url: '/docs/readme.pdf', icon: ''}`
- WHEN the widget renders
- THEN the system MUST render a generic link icon (e.g., `icon-link` or similar)

#### Scenario: Unrecognized icon format defaults gracefully

- GIVEN link config `{label: 'Bad Icon', url: 'https://example.com', icon: 'not-a-real-icon-name'}`
- WHEN the widget renders
- THEN the system MUST fall back to the generic link icon (not crash)

### Requirement: REQ-LNKS-005 Three link layout modes

The system MUST support three distinct layout modes for rendering links, controlled by the `linkLayout` config field.

#### Scenario: Card layout with full details

- GIVEN config `{linkLayout: 'card', showLinkDescriptions: true}`
- WHEN the widget renders
- THEN each link MUST display in a card container with:
  - Icon (resolved per REQ-LNKS-004)
  - Label text (bold or prominent)
  - Description text (if present in config and showLinkDescriptions=true)
  - Visual card border/padding/hover effect
- AND cards MUST be arranged in the CSS Grid (REQ-LNKS-007)

#### Scenario: Inline layout without descriptions

- GIVEN config `{linkLayout: 'inline'}`
- WHEN the widget renders
- THEN each link MUST display as a flat row:
  - Icon (resolved per REQ-LNKS-004)
  - Label text beside the icon
  - Description MUST NOT appear (even if present in config)
- AND rows MUST NOT have card styling (no border, minimal padding)

#### Scenario: Icon-only layout with hover tooltip

- GIVEN config `{linkLayout: 'icon-only'}`
- WHEN the widget renders
- THEN each link MUST display as:
  - Icon (resolved per REQ-LNKS-004) only
  - No visible label
  - Label appears on hover as a tooltip
- AND icons MUST be arranged in a grid (CSS Grid with equal-sized cells)
- AND description MUST NOT appear

#### Scenario: Description visibility toggle

- GIVEN a widget in `'card'` layout with showLinkDescriptions=false
- WHEN the widget renders
- THEN the description MUST NOT be displayed for any link
- AND the card MUST not expand to show description space

### Requirement: REQ-LNKS-006 Multi-column grid layout

The system MUST render sections and links using a CSS Grid layout with a configurable column count, allowing responsive multi-column link organization.

#### Scenario: Grid renders with configured columns

- GIVEN config `{columns: 3}`
- WHEN the widget renders
- THEN the system MUST generate a CSS Grid with `grid-template-columns: repeat(3, 1fr)`
- AND all link cards/items MUST flow left-to-right, wrapping to the next row
- AND all items MUST have equal width

#### Scenario: Column count respects bounds

- GIVEN a user enters columns=7 (out of valid range 1–6)
- WHEN the config is saved
- THEN the system MUST reject the input (HTTP 400 or validation error)
- OR silently clamp to 6 and warn the user

#### Scenario: Single-column layout

- GIVEN config `{columns: 1}`
- WHEN the widget renders
- THEN links MUST be displayed as a single vertical list

#### Scenario: Six-column layout

- GIVEN config `{columns: 6}`
- WHEN the widget renders
- THEN links MUST be arranged in 6 columns (for compact dense display)

#### Scenario: Responsive behavior on mobile

- GIVEN the widget on a small viewport (mobile phone)
- WHEN the CSS Grid renders
- THEN the layout MUST adapt responsively:
  - Either wrap columns or collapse to single column (CSS media queries)
  - OR the grid respects the configured columns and horizontal scroll if needed
- NOTE: The specific responsive behavior is implementation-detail; requirement is that it MUST be usable on mobile

### Requirement: REQ-LNKS-007 URL sanitisation and validation

The system MUST validate and sanitise all URLs in the links configuration to reject malicious or malformed inputs.

#### Scenario: Valid HTTPS URL accepted

- GIVEN a link config with `url: 'https://example.com/page'`
- WHEN the config is saved
- THEN the URL MUST be accepted and stored
- AND the system MUST NOT reject it

#### Scenario: Valid HTTP URL accepted

- GIVEN a link config with `url: 'http://example.com/page'`
- WHEN the config is saved
- THEN the URL MUST be accepted and stored

#### Scenario: Valid relative URL accepted

- GIVEN a link config with `url: '/apps/files/list'` or `url: '../other'`
- WHEN the config is saved
- THEN relative URLs (starting with `/` or `../`) MUST be accepted
- AND path traversal (multiple `../` chains) MUST be rejected
- NOTE: Simple relative paths are for internal Nextcloud navigation

#### Scenario: JavaScript URL rejected

- GIVEN a link config with `url: 'javascript:alert("xss")'`
- WHEN the config is saved
- THEN the system MUST return HTTP 400 with error message
- AND the URL MUST NOT be stored
- AND the user MUST see validation feedback (e.g., "Invalid URL")

#### Scenario: Data URL rejected

- GIVEN a link config with `url: 'data:text/html,...'`
- WHEN the config is saved
- THEN the system MUST reject with HTTP 400

#### Scenario: File URL rejected

- GIVEN a link config with `url: 'file:///etc/passwd'`
- WHEN the config is saved
- THEN the system MUST reject with HTTP 400

#### Scenario: Empty URL rejected

- GIVEN a link config with `url: ''` or `url: null`
- WHEN the config is saved
- THEN the system MUST reject with HTTP 400 and error message
- AND a label "Link URL is required" MUST be shown to the user

### Requirement: REQ-LNKS-008 Edit form with drag-to-reorder

The system MUST provide an interactive configuration UI allowing users to add/edit/delete sections and links, and reorder them via drag-and-drop.

#### Scenario: Add a new section

- GIVEN the configuration form is open
- WHEN the user clicks "Add Section"
- THEN a new section row MUST be appended with:
  - Editable title text field (placeholder "Section title")
  - A nested links table (initially empty)
  - A delete button for the section

#### Scenario: Edit section title

- GIVEN a section exists in the form
- WHEN the user clicks the title field and types a new name
- THEN the title MUST update in the rendered config
- AND the widget preview (if any) MUST update

#### Scenario: Add link to section

- GIVEN a section is open in the form
- WHEN the user clicks "Add Link" under that section
- THEN a new link row MUST be appended with:
  - Label input
  - URL input
  - Icon input (with icon preview)
  - Description input (collapsible or conditional)
  - Delete button

#### Scenario: Edit link details

- GIVEN a link row is displayed
- WHEN the user edits any field (label, url, icon, description)
- THEN the config MUST update in real-time
- AND the widget preview (if any) MUST re-render

#### Scenario: Delete link

- GIVEN a link row is displayed
- WHEN the user clicks the delete button
- THEN the link MUST be removed from the section
- AND if the section becomes empty, it MUST still exist (per REQ-LNKS-003)

#### Scenario: Delete section

- GIVEN a section row is displayed
- WHEN the user clicks the delete button
- THEN the section MUST be removed from the config
- AND all links in that section are deleted

#### Scenario: Drag to reorder sections

- GIVEN the configuration form displays multiple sections
- WHEN the user drags the drag handle (⋮⋮) of a section upward or downward
- THEN the section MUST move to the new position
- AND the order MUST persist in the config JSON
- NOTE: Drag handle visibility/affordance is implementation detail

#### Scenario: Drag to reorder links within a section

- GIVEN a section with multiple links
- WHEN the user drags the drag handle of a link up or down
- THEN the link MUST move to the new position within that section
- AND links in other sections MUST NOT be affected
- AND the order MUST persist in the config JSON

#### Scenario: Icon preview in edit form

- GIVEN the link editor displays an icon input field
- WHEN the user enters a Nextcloud icon name (e.g., `icon-files`)
- THEN a small preview MUST display the resolved icon (SVG)
- AND when the user enters a URL (e.g., `https://example.com/logo.png`)
- THEN the preview MUST show the `<img>` (or a placeholder if URL is not yet valid)

### Requirement: REQ-LNKS-009 Empty widget state

The system MUST display a user-friendly empty-state message when the widget has no links to display.

#### Scenario: Empty state appears with no sections

- GIVEN a links widget with empty config `{sections: []}`
- WHEN the widget renders
- THEN a message MUST appear: "No links yet — click the gear icon to add some."
- AND no links grid MUST be visible
- AND no sections MUST appear

#### Scenario: Empty state appears when all sections are empty

- GIVEN a links widget with `{sections: [{title: 'Tools', links: []}]}`
- WHEN the widget renders
- THEN the section MUST be hidden (per REQ-LNKS-003)
- AND the empty-state message MUST appear (since no visible sections remain)

#### Scenario: Gear icon navigates to edit

- GIVEN the empty-state message is displayed
- WHEN the user clicks "gear icon" (or clickable edit affordance)
- THEN the widget edit modal MUST open (if supported by the dashboard UI)
- OR the dashboard MUST switch to edit mode (platform dependent)

### Requirement: REQ-LNKS-010 Navigation handling and rel attributes

The system MUST correctly handle link navigation, distinguishing between external and internal URLs, and set appropriate `rel` attributes to prevent security issues and preserve cross-tab messaging where needed.

#### Scenario: External URL uses rel="noopener noreferrer"

- GIVEN a link config with `url: 'https://example.com'` (external)
- WHEN the user clicks the link
- AND openInNewTab=true
- THEN the system MUST call `window.open(url, '_blank', 'noopener,noreferrer')`
- OR render an `<a>` element with `rel="noopener noreferrer"` and `target="_blank"`
- NOTE: This prevents the external site from accessing `window.opener`

#### Scenario: Internal URL preserves window.opener

- GIVEN a link config with `url: '/apps/files'` (relative, internal to same NC instance)
- WHEN the user clicks the link
- AND openInNewTab=true
- THEN the system MUST allow `window.opener` to be accessible (no `noopener`)
- AND the link MUST open in a new tab
- NOTE: This allows the new tab to communicate back to the dashboard if needed

#### Scenario: Same-window navigation

- GIVEN a link config with `url: 'https://example.com'` and openInNewTab=false
- WHEN the user clicks the link
- THEN the system MUST navigate the same window (no new tab)
- AND `rel="noopener noreferrer"` is not applicable (no new window)

#### Scenario: Internal link in same window

- GIVEN a link config with `url: '/apps/mydash/dashboard/1'` and openInNewTab=false
- WHEN the user clicks the link
- THEN the system MUST navigate to the URL in the same window
- AND no `rel` attribute is needed

#### Scenario: Click handler does not fire in edit mode

- GIVEN the widget is in edit mode (dashboard edit UI is open)
- WHEN the user clicks a link
- THEN the click handler MUST be suppressed (no navigation)
- AND the link MUST remain editable (not navigated away)

