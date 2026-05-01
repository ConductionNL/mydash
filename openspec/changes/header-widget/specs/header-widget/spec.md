---
capability: header-widget
status: draft
---

# Header Widget Specification

## ADDED Requirements

### Requirement: REQ-HDR-001 Widget registration

The system MUST register a MyDash dashboard widget with id `mydash_header` via `OCP\Dashboard\IManager::registerWidget()` so it appears in the widget picker alongside other Nextcloud dashboard widgets.

#### Scenario: Widget appears in picker

- GIVEN the header-widget app is installed and enabled
- WHEN a user opens the MyDash widget picker dialog
- THEN the `mydash_header` widget MUST appear in the list with a title (e.g., "Header Banner") and an icon
- AND the widget MUST be selectable for placement on a dashboard

#### Scenario: Multiple instances allowed

- GIVEN a dashboard already has one header widget placement
- WHEN the user adds a second header widget to the same dashboard
- THEN both placements MUST coexist with independent configurations
- AND the system MUST treat them as separate instances

#### Scenario: Widget registration survives app reload

- GIVEN the widget is registered
- WHEN Nextcloud cache is cleared or the app is reloaded
- THEN the `mydash_header` widget MUST still be discoverable in the picker

#### Scenario: Widget registration includes metadata

- GIVEN the widget is registered
- WHEN the widget picker fetches widget metadata
- THEN the widget object MUST include at minimum: id, title, icon_url

### Requirement: REQ-HDR-002 Placement configuration structure

The system MUST store per-placement widget configuration in the `oc_mydash_widget_placements.widgetContent` JSON field, allowing users to specify content, styling, image source, and optional CTA.

#### Scenario: Config for title and subtitle

- GIVEN a user is configuring a header widget placement
- WHEN they set the title and optional subtitle
- THEN the configuration MUST store a `title: string` field (required)
- AND a `subtitle: string|null` field (optional, default null)

#### Scenario: Config for background image URL

- GIVEN a user is configuring a header widget placement
- WHEN they provide an external image URL
- THEN the configuration MUST store a `backgroundImageUrl: string|null` field
- AND each URL MUST be HTTP/HTTPS
- AND the URL is subject to allow-list validation (see REQ-HDR-005)

#### Scenario: Config for background image file ID

- GIVEN a user is configuring a header widget placement
- WHEN they select an image from Nextcloud Files
- THEN the configuration MUST store a `backgroundImageFileId: number|null` field
- AND the file ID MUST be validated for file-read ACL at render time (see REQ-HDR-006)

#### Scenario: Config for background color

- GIVEN a user is configuring a header widget placement
- WHEN they set a solid background color
- THEN the configuration MUST store a `backgroundColor: string|null` field
- AND the color MUST be a valid CSS color string (hex, rgb, or named)

#### Scenario: Config for overlay mode

- GIVEN a user is configuring a header widget placement
- WHEN they choose an overlay effect
- THEN the configuration MUST store an `overlayMode: 'none'|'tint'|'gradient-bottom'` field
- AND the default value MUST be `'tint'` if backgroundImageUrl or backgroundImageFileId is set, otherwise `'none'`

#### Scenario: Config for overlay opacity

- GIVEN a user is configuring a header widget placement
- WHEN they adjust the overlay opacity
- THEN the configuration MUST store an `overlayOpacity: number` field
- AND the default value MUST be `0.4`
- AND the value MUST be in the range [0.0, 1.0]

#### Scenario: Config for text color

- GIVEN a user is configuring a header widget placement
- WHEN they set the text color
- THEN the configuration MUST store a `textColor: string|null` field
- AND the default behavior (when null) MUST auto-derive contrast-safe text color from backgroundColor or image
- AND if explicitly set, MUST be a valid CSS color string

#### Scenario: Config for text and vertical alignment

- GIVEN a user is configuring a header widget placement
- WHEN they choose text positioning
- THEN the configuration MUST store:
  - `textAlign: 'left'|'center'|'right'` (default `'center'`)
  - `verticalAlign: 'top'|'middle'|'bottom'` (default `'middle'`)

#### Scenario: Config for height preset

- GIVEN a user is configuring a header widget placement
- WHEN they choose a height
- THEN the configuration MUST store a `height: 'small'|'medium'|'large'|'xlarge'` field
- AND the default value MUST be `'medium'`
- AND the heights MUST map to: small=120px, medium=200px, large=320px, xlarge=480px

#### Scenario: Config for CTA button

- GIVEN a user is configuring a header widget placement
- WHEN they optionally add a call-to-action button
- THEN the configuration MUST store a `cta: {label: string, url: string, style: 'primary'|'secondary'|'ghost'}|null` field
- AND the cta field MUST be optional (default null)
- AND if present, all three properties (label, url, style) MUST be non-empty strings

#### Scenario: Config is JSON-serializable

- GIVEN a placement with header widget config
- WHEN the placement is fetched from the database
- THEN the `widgetContent` MUST deserialize cleanly to a JavaScript object
- AND all required fields MUST be present with no spurious whitespace or encoding issues

### Requirement: REQ-HDR-003 Image source precedence

When both `backgroundImageFileId` and `backgroundImageUrl` are present in the configuration, the system MUST apply the following precedence: file ID takes priority over URL.

#### Scenario: File ID overrides URL when both set

- GIVEN a placement with both backgroundImageFileId (123) and backgroundImageUrl ("https://example.com/image.jpg")
- WHEN the widget renders
- THEN the file preview route MUST be used
- AND the URL MUST be ignored

#### Scenario: URL used when file ID is null

- GIVEN a placement with backgroundImageFileId=null and backgroundImageUrl="https://example.com/image.jpg"
- WHEN the widget renders
- THEN the external URL MUST be fetched and displayed

#### Scenario: Neither set defaults to background color

- GIVEN a placement with backgroundImageFileId=null and backgroundImageUrl=null
- WHEN the widget renders
- THEN the widget MUST display backgroundColor only (no image)

### Requirement: REQ-HDR-004 Overlay rendering modes

The system MUST support three overlay modes for controlling how background images are layered with colors.

#### Scenario: Overlay mode none (no overlay)

- GIVEN a placement with overlayMode='none'
- WHEN the widget renders
- THEN no color overlay MUST be applied
- AND the background image (if present) MUST display at full opacity
- AND backgroundColor (if set) MUST be ignored

#### Scenario: Overlay mode tint (semi-transparent color)

- GIVEN a placement with overlayMode='tint', overlayOpacity=0.4, and backgroundColor='#000000'
- WHEN the widget renders
- THEN a semi-transparent black overlay with 40% opacity MUST be positioned absolutely over the background image
- AND text MUST be positioned above the overlay div

#### Scenario: Overlay mode gradient-bottom (linear gradient)

- GIVEN a placement with overlayMode='gradient-bottom' and backgroundColor='#000000'
- WHEN the widget renders
- THEN a linear gradient overlay MUST be applied: top=transparent, bottom=backgroundColor
- AND the gradient MUST transition from 50% down to 100% of widget height

#### Scenario: Default overlay mode based on image presence

- GIVEN a placement with backgroundImageUrl set but overlayMode not explicitly set
- WHEN the widget renders
- THEN overlayMode MUST default to 'tint'
- AND when no image is present, overlayMode MUST default to 'none'

### Requirement: REQ-HDR-005 External image allow-list

The system MUST enforce an allow-list of external image hostnames via admin setting `mydash.header_widget_allowed_image_domains` to prevent loading from untrusted sources.

#### Scenario: Allow-list enabled with allowed domain

- GIVEN mydash.header_widget_allowed_image_domains="[\"images.example.com\", \"cdn.trusted.org\"]"
- WHEN a placement is configured with backgroundImageUrl="https://images.example.com/banner.jpg"
- THEN the image MUST be allowed and loaded

#### Scenario: Allow-list enabled with disallowed domain

- GIVEN mydash.header_widget_allowed_image_domains="[\"images.example.com\"]"
- WHEN a placement is configured with backgroundImageUrl="https://untrusted-site.com/image.jpg"
- THEN the image MUST NOT load
- AND the widget MUST fall back to backgroundColor only
- AND no error UI MUST be displayed

#### Scenario: Empty allow-list allows all domains

- GIVEN mydash.header_widget_allowed_image_domains=null or []
- WHEN a placement is configured with any external backgroundImageUrl
- THEN the image MUST be loaded without restriction
- AND this is the default behavior (zero-config = all allowed)

#### Scenario: Same-origin URLs bypass allow-list

- GIVEN the dashboard is loaded from localhost:3000
- WHEN a placement is configured with backgroundImageUrl="http://localhost:3000/images/banner.jpg"
- THEN the URL MUST be loaded regardless of allow-list setting
- AND same-origin is determined by protocol, hostname, and port

#### Scenario: Allow-list hostname matching is case-insensitive

- GIVEN mydash.header_widget_allowed_image_domains="[\"Images.Example.COM\"]"
- WHEN a placement is configured with backgroundImageUrl="https://images.example.com/banner.jpg"
- THEN the image MUST be allowed (case-insensitive match)

### Requirement: REQ-HDR-006 File ID image with ACL validation

The system MUST validate file read permissions when `backgroundImageFileId` is set, falling back to backgroundColor if the user cannot read the file.

#### Scenario: User can read file

- GIVEN a placement with backgroundImageFileId=123
- AND the requesting user has read access to file 123
- WHEN the widget renders
- THEN the file preview route MUST be constructed
- AND the image MUST load successfully

#### Scenario: User cannot read file

- GIVEN a placement with backgroundImageFileId=123
- AND the requesting user CANNOT read file 123 (permission denied or file deleted)
- WHEN the widget renders
- THEN the file preview route returns 404 or 403
- AND the widget MUST fall back to backgroundColor only
- AND no error message or icon MUST be displayed

#### Scenario: File ID converted to preview URL

- GIVEN a placement with backgroundImageFileId=42
- WHEN the widget renders
- THEN the frontend MUST construct a preview URL: `linkToRoute('files.api.v1.resources', {fileId: 42})`
- OR equivalent NC file preview route pattern

### Requirement: REQ-HDR-007 Image load failure graceful fallback

The system MUST gracefully handle image load failures (404, timeout, invalid URL) by falling back to backgroundColor and continuing to render the widget without error UI.

#### Scenario: External image returns 404

- GIVEN a placement with backgroundImageUrl="https://example.com/nonexistent.jpg"
- WHEN the widget renders and the image fails to load
- THEN the widget MUST display backgroundColor only
- AND the title, subtitle, and CTA MUST still be visible
- AND no broken-image icon, error message, or placeholder MUST be shown

#### Scenario: Image load timeout

- GIVEN a placement with backgroundImageUrl pointing to a slow/unresponsive server
- WHEN the image fails to load within browser timeout
- THEN the widget MUST fall back to backgroundColor
- AND continue rendering normally

#### Scenario: Invalid image MIME type

- GIVEN a placement with backgroundImageUrl pointing to a non-image file (e.g., .txt)
- WHEN the browser detects invalid image format
- THEN the widget MUST fall back to backgroundColor
- AND the widget MUST render successfully

### Requirement: REQ-HDR-008 Text rendering and styling

The system MUST render title and subtitle with semantic HTML and configurable styling (color, alignment, vertical positioning).

#### Scenario: Title rendered as h2

- GIVEN a placement with title="Welcome"
- WHEN the widget renders
- THEN an `<h2>` tag MUST contain the title text
- AND the heading hierarchy MUST be proper for page structure

#### Scenario: Subtitle rendered as paragraph

- GIVEN a placement with subtitle="Explore our dashboard"
- WHEN the widget renders
- THEN a `<p>` tag MUST contain the subtitle text
- AND no subtitle tag MUST appear if subtitle is null or empty

#### Scenario: Text alignment applied correctly

- GIVEN a placement with textAlign='right'
- WHEN the widget renders
- THEN the title and subtitle MUST be right-aligned
- AND text-align: right MUST apply to the content container

#### Scenario: Vertical alignment applied correctly

- GIVEN a placement with verticalAlign='top'
- WHEN the widget renders
- THEN the title and subtitle MUST be positioned at the top of the widget
- AND this MUST use absolute positioning or flexbox justify-content

#### Scenario: Text color applied with contrast fallback

- GIVEN a placement with backgroundColor='#ffffff' and textColor=null
- WHEN the widget renders
- THEN the system MUST apply a dark text color (e.g., #000000) for readability
- AND when textColor is explicitly set, that color MUST be used instead

### Requirement: REQ-HDR-009 Height presets and responsive sizing

The system MUST support four height presets (small, medium, large, xlarge) that map to fixed pixel values, and default widgets to full dashboard width.

#### Scenario: Height small (120px)

- GIVEN a placement with height='small'
- WHEN the widget renders
- THEN the widget height MUST be 120px
- AND the widget MUST span the full dashboard width

#### Scenario: Height medium (200px)

- GIVEN a placement with height='medium'
- WHEN the widget renders
- THEN the widget height MUST be 200px
- AND this is the default when height is not set

#### Scenario: Height large (320px)

- GIVEN a placement with height='large'
- WHEN the widget renders
- THEN the widget height MUST be 320px

#### Scenario: Height xlarge (480px)

- GIVEN a placement with height='xlarge'
- WHEN the widget renders
- THEN the widget height MUST be 480px

#### Scenario: Default grid sizing for header widget

- GIVEN a header widget is added to a dashboard
- WHEN the placement is created
- THEN gridWidth MUST default to the full dashboard width (gridColumns value)
- AND gridHeight MUST be auto-calculated from the height preset and grid row size

### Requirement: REQ-HDR-010 Call-to-action button rendering and navigation

The system MUST render an optional CTA button with configurable style and handle navigation to internal or external URLs.

#### Scenario: CTA button with primary style

- GIVEN a placement with cta={label: "Sign up", url: "https://example.com/signup", style: "primary"}
- WHEN the widget renders
- THEN a button with primary styling MUST appear with text "Sign up"
- AND clicking the button MUST navigate to https://example.com/signup

#### Scenario: CTA button with secondary style

- GIVEN a placement with cta={label: "Learn more", url: "/internal/docs", style: "secondary"}
- WHEN the widget renders
- THEN a button with secondary styling MUST appear
- AND clicking MUST navigate to /internal/docs in the same tab (internal URL)

#### Scenario: CTA button with ghost style

- GIVEN a placement with cta={label: "Dismiss", url: "#", style: "ghost"}
- WHEN the widget renders
- THEN a button with ghost (outline/minimal) styling MUST appear

#### Scenario: External URL opens in new tab

- GIVEN a placement with cta={label: "Visit", url: "https://example.com", style: "primary"}
- WHEN the user clicks the CTA button
- THEN the URL MUST open in a new tab
- AND the link MUST have `target="_blank"` and `rel="noopener noreferrer"`

#### Scenario: Internal URL opens in same tab

- GIVEN a placement with cta={label: "Go to settings", url: "/settings", style: "primary"}
- WHEN the user clicks the CTA button
- THEN the URL MUST navigate in the current tab
- AND `target` and `rel` MUST NOT be set

#### Scenario: No CTA button when cta is null

- GIVEN a placement with cta=null
- WHEN the widget renders
- THEN no button MUST appear
- AND the widget MUST render with title, subtitle, and background only

### Requirement: REQ-HDR-011 Accessibility for header widgets

The system MUST ensure header widgets are accessible to keyboard navigation and screen readers, with proper ARIA labels and semantic HTML.

#### Scenario: Title is keyboard-navigable

- GIVEN a header widget is rendered on a dashboard
- WHEN a keyboard user presses Tab
- THEN the widget MUST be reachable in the tab order
- AND the widget's heading (`<h2>`) MUST be focusable or part of the widget's accessible region

#### Scenario: CTA button is keyboard accessible

- GIVEN a header widget with a CTA button
- WHEN a keyboard user presses Tab to reach the button
- THEN the button MUST be focusable
- AND pressing Enter or Space MUST trigger the navigation action

#### Scenario: CTA button has accessible label

- GIVEN a placement with cta={label: "Sign up", url: "https://example.com/signup"}
- WHEN a screen reader user encounters the widget
- THEN the button MUST have an accessible label
- AND the label SHOULD combine button text and destination (e.g., "Sign up, opens in new tab" for external)

#### Scenario: Subtitle accessible to screen readers

- GIVEN a placement with subtitle="Explore our dashboard"
- WHEN a screen reader parses the widget
- THEN the subtitle MUST be read as a paragraph or descriptive text
- AND it MUST NOT be hidden from assistive technology (no aria-hidden)

#### Scenario: Image alt text or absence

- GIVEN a header widget with a background image
- WHEN a screen reader encounters the widget
- THEN if the image is decorative (background-image CSS), it MUST NOT have an alt attribute or MUST be aria-hidden
- AND the semantic content (title, subtitle, CTA) MUST be independent of the image for understanding

### Requirement: REQ-HDR-012 Print-friendly rendering

The system MUST render header widgets visibly when printed, including background images and colors, subject to the user's "print backgrounds" browser setting.

#### Scenario: Header widget prints with background image

- GIVEN a header widget with a background image on a dashboard
- WHEN the user prints the page (Ctrl+P or browser print menu)
- THEN the widget MUST appear with its background image visible
- AND this is subject to the user's browser "Print backgrounds" setting

#### Scenario: Header widget prints with overlay and text

- GIVEN a header widget with overlayMode='tint', backgroundColor, and text
- WHEN the page is printed
- THEN the overlay, background color, and all text MUST be visible in the print
- AND text color MUST have sufficient contrast for print readability

## Non-Functional Requirements

- **Performance**: Header widgets MUST render and display images within 500ms, even with large background images.
- **Image loading**: External image fetch MUST timeout after 10 seconds; local file previews after 5 seconds.
- **Compatibility**: The widget MUST support all modern browsers (Chrome, Firefox, Safari, Edge) and gracefully degrade on older browsers (fallback to solid color).
- **Data integrity**: Placement deletion MUST not leave orphaned config data; widgetContent JSON MUST validate on save.
- **Accessibility**: Header widgets MUST meet WCAG 2.1 AA standards for color contrast, keyboard navigation, and screen reader compatibility.
- **Localization**: Widget configuration labels and defaults MUST support English and Dutch.

### Current Implementation Status

**Not yet implemented:**
- REQ-HDR-001 through REQ-HDR-012: All requirements are new with this change proposal.
