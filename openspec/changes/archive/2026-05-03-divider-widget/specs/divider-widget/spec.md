---
status: draft
---

# Divider Widget Specification

## Purpose

The divider widget is a lightweight, configurable visual separator for MyDash dashboards. It enables dashboard creators to break up widget sections into logical groups using minimal UI — a horizontal line, whitespace spacer, or centered heading with dividing lines — all rendered client-side with full theme awareness and print support. This capability adds no backend endpoints or data storage; all configuration is stored in the placement's `widgetContent JSON` blob and rendered in-browser.

## ADDED Requirements

### Requirement: REQ-DIV-001 Register Divider Widget

The system MUST register a divider widget with the Nextcloud Dashboard Widget API so it appears in the widget picker alongside other dashboard widgets.

#### Scenario: Widget appears in discovery
- GIVEN the MyDash app is installed and enabled
- WHEN the user opens the "Add Widget" modal on a dashboard
- THEN the divider widget MUST appear in the widget list with id `mydash_divider`
- AND the widget MUST have a title (e.g., "Divider") and an icon
- AND the widget MUST NOT fetch any data on discovery — it is fully client-side

#### Scenario: Widget registration via IManager
- GIVEN `OCP\Dashboard\IManager` is available in the Nextcloud container
- WHEN the MyDash app boots (appinfo/app.php or service provider)
- THEN the app MUST register the divider widget by calling `$manager->registerWidget(new DividerWidgetProvider())`
- AND the widget provider MUST return widget metadata: id, title, icon URL, and support flag (`supportsV2 = true` for consistency, though no items are loaded)

#### Scenario: Widget appears alongside standard widgets
- GIVEN the user's Nextcloud has weather_status, notes, and mydash_divider widgets installed
- WHEN the user opens the widget picker
- THEN all three widgets MUST be listed and sortable by the user's selection order
- AND mydash_divider MUST not be marked as requiring special permissions (it is available to all logged-in users)

### Requirement: REQ-DIV-002 Configure Divider Style

The system MUST store placement-level configuration in the `widgetContent JSON` blob to allow dashboard creators to choose between three divider styles: a horizontal line, whitespace spacer, or heading-break.

#### Scenario: Line style configuration
- GIVEN a divider widget is placed on a dashboard
- WHEN the user opens the widget's edit form and selects style = `line`
- THEN the form MUST expose three additional config fields: `lineColor` (color picker or text input, nullable, default theme border color), `lineThickness` (number 1–8 px, default 1), and `lineStyle` (dropdown: solid / dashed / dotted, default solid)
- AND the config MUST be persisted to `widgetContent` JSON as: `{ "style": "line", "lineColor": null, "lineThickness": 1, "lineStyle": "solid" }`

#### Scenario: Whitespace style configuration
- GIVEN a divider widget is placed on a dashboard
- WHEN the user opens the widget's edit form and selects style = `whitespace`
- THEN the form MUST expose one additional config field: `whitespaceSize` (dropdown: small / medium / large / xlarge, default medium)
- AND the config MUST map size names to heights: `small = 16px, medium = 32px, large = 64px, xlarge = 128px`
- AND the config MUST be persisted to `widgetContent` JSON as: `{ "style": "whitespace", "whitespaceSize": "medium" }`

#### Scenario: Heading-break style configuration
- GIVEN a divider widget is placed on a dashboard
- WHEN the user opens the widget's edit form and selects style = `heading-break`
- THEN the form MUST expose one required field: `headingText` (text input, required for this style)
- AND optionally expose `lineColor` and `lineStyle` to customize the horizontal lines above and below the heading
- AND the config MUST be persisted to `widgetContent` JSON as: `{ "style": "heading-break", "headingText": "Important Section", "lineColor": null, "lineStyle": "solid" }`

#### Scenario: Edit form is minimal
- GIVEN the divider widget's config form is open
- WHEN the user views the form
- THEN the form MUST contain ONLY the style dropdown and the config fields above (no name, icon, click-target, or other standard widget fields)
- AND the form MUST provide clear visual labels for each field (e.g., "Style", "Line Color", "Line Thickness", "Whitespace Size", "Heading Text")

### Requirement: REQ-DIV-003 Render Line Divider

The system MUST render a horizontal line divider when style is `line`, respecting color, thickness, and line-style configuration.

#### Scenario: Default line render
- GIVEN a divider widget with style = `line` and no lineColor specified
- WHEN the dashboard is rendered
- THEN a 1-pixel solid horizontal line MUST appear using the theme's `--color-border` CSS custom property
- AND the line MUST span the full width of the widget container

#### Scenario: Custom line color render
- GIVEN a divider widget with style = `line`, lineColor = `#ff0000`, lineThickness = 3
- WHEN the dashboard is rendered
- THEN a 3-pixel solid red horizontal line MUST appear
- AND the theme's `--color-border` property MUST be ignored in favour of the explicit color

#### Scenario: Dashed line style
- GIVEN a divider widget with style = `line`, lineStyle = `dashed`, lineThickness = 2
- WHEN the dashboard is rendered
- THEN a 2-pixel dashed horizontal line MUST appear (CSS `border-style: dashed`)
- AND AND dotted lines MUST also be supported (CSS `border-style: dotted`)

#### Scenario: Line accessibility
- GIVEN a divider widget with style = `line` is rendered
- WHEN screen reader accesses the widget
- THEN the divider MUST have `role="separator"` attribute
- AND MUST have no text content (purely decorative)

### Requirement: REQ-DIV-004 Render Whitespace Divider

The system MUST render a vertical spacer block when style is `whitespace`, respecting the configured size.

#### Scenario: Default whitespace render
- GIVEN a divider widget with style = `whitespace` and whitespaceSize = `medium` (default)
- WHEN the dashboard is rendered
- THEN a transparent 32-pixel-tall block MUST appear
- AND the block MUST contribute to grid flow but contain no visible content

#### Scenario: Custom whitespace sizes
- GIVEN divider widgets with whitespaceSize = small / medium / large / xlarge
- WHEN the dashboard is rendered
- THEN the widgets MUST render as 16px / 32px / 64px / 128px tall respectively
- AND each size MUST scale predictably on responsive layouts

#### Scenario: Whitespace accessibility
- GIVEN a divider widget with style = `whitespace` is rendered
- WHEN screen reader accesses the widget
- THEN the divider MUST have `role="separator"` attribute
- AND MUST have no text content

### Requirement: REQ-DIV-005 Render Heading-Break Divider

The system MUST render a centered heading with horizontal lines above and below when style is `heading-break`, respecting the optional lineColor configuration.

#### Scenario: Default heading-break render
- GIVEN a divider widget with style = `heading-break`, headingText = "Key Section"
- WHEN the dashboard is rendered
- THEN "Key Section" MUST appear as a centered `<h3>` element between two horizontal lines
- AND the lines MUST use the theme's `--color-border` CSS custom property by default
- AND the heading MUST have semantic `<h3>` tag for proper heading hierarchy

#### Scenario: Custom line color on heading-break
- GIVEN a divider widget with style = `heading-break`, headingText = "Important", lineColor = `#00ff00`
- WHEN the dashboard is rendered
- THEN the horizontal lines above and below the heading MUST be green
- AND the heading text MUST be centered and span the widget width
- AND padding MUST provide visual breathing room around the heading

#### Scenario: Heading-break accessibility
- GIVEN a divider widget with style = `heading-break`, headingText = "Summary"
- WHEN screen reader accesses the widget
- THEN the `<h3>` element MUST contain the text "Summary"
- AND the divider MUST have `aria-label="Summary divider"` or similar on a wrapper for redundant clarity
- AND screen readers MUST announce the heading as a level-3 heading

### Requirement: REQ-DIV-006 Default Widget Sizing

The system MUST set sensible sizing defaults for divider widgets in the widget add modal to minimize their footprint on the dashboard.

#### Scenario: Divider defaults to gridHeight = 1
- GIVEN the user is adding a new divider widget to a dashboard
- WHEN the "Add Widget" modal opens and the user selects the divider widget
- THEN the modal MUST pre-populate `gridHeight = 1` as the default
- AND the user MAY override this in the placement settings if needed

#### Scenario: Divider defaults to full dashboard width
- GIVEN the user is adding a new divider widget to a dashboard
- WHEN the "Add Widget" modal opens and the user selects the divider widget
- THEN the modal MUST pre-populate `gridWidth = max dashboard width` (typically 4, 8, or 12 depending on dashboard grid configuration)
- AND the divider MUST span the full available width of the dashboard

#### Scenario: User can override defaults
- GIVEN the user is configuring a divider widget's sizing
- WHEN the user modifies `gridHeight` or `gridWidth` in the placement editor
- THEN the new values MUST be persisted and respected on render
- AND the system MUST allow gridHeight values down to 1 for minimal dividers

### Requirement: REQ-DIV-007 Theme Awareness and Color Inheritance

The system MUST automatically use the active Nextcloud theme's border color for divider lines by default and MUST support explicit color overrides.

#### Scenario: Default theme color
- GIVEN a divider widget with style = `line` and no lineColor specified
- WHEN the dashboard is rendered with the default Nextcloud theme (blue border)
- THEN the divider line MUST use CSS `border-color: var(--color-border)`
- AND the line color MUST update automatically if the user switches themes

#### Scenario: Explicit color overrides theme
- GIVEN a divider widget with style = `line`, lineColor = `#ff9800`
- WHEN the dashboard is rendered
- THEN the line MUST use the explicit orange color
- AND the theme's `--color-border` property MUST be ignored

#### Scenario: Heading-break inherits or overrides line color
- GIVEN a divider widget with style = `heading-break` and no lineColor specified
- WHEN the dashboard is rendered
- THEN the lines above and below the heading MUST use the theme's `--color-border`
- AND if lineColor is explicitly set, the heading-break lines MUST use that color instead

### Requirement: REQ-DIV-008 Print Support and Visibility

The system MUST ensure divider widgets render visibly on printed dashboards and MUST NOT hide them in print mode.

#### Scenario: Line divider prints
- GIVEN a divider widget with style = `line` on a dashboard
- WHEN the user prints or previews the printed dashboard (browser print stylesheet)
- THEN the line divider MUST be visible on the printed page
- AND the line MUST NOT have `display: none` or other hide rules in print media

#### Scenario: Whitespace divider prints
- GIVEN a divider widget with style = `whitespace` on a dashboard
- WHEN the user prints or previews the printed dashboard
- THEN the whitespace MUST be visible (rendered as a vertical gap of the configured size)
- AND the spacing MUST be preserved in the printed layout

#### Scenario: Heading-break divider prints
- GIVEN a divider widget with style = `heading-break`, headingText = "Section"
- WHEN the user prints or previews the printed dashboard
- THEN the heading and its lines MUST be visible
- AND the heading MUST remain readable with appropriate font size for print

### Requirement: REQ-DIV-009 No Backend Endpoints Required

The system MUST render dividers entirely client-side and MUST NOT require any backend API endpoints beyond standard widget discovery.

#### Scenario: Divider is rendered without API calls
- GIVEN a divider widget is placed on a dashboard
- WHEN the dashboard is rendered
- THEN no custom API endpoints (e.g., `/api/widgets/divider/...`) MUST be called
- AND the widget MUST use only the `widgetContent` JSON stored in the placement record to configure its render

#### Scenario: Widget discovery works via standard widget API
- GIVEN the user opens the widget picker
- WHEN the system calls `IManager::getWidgets()`
- THEN the divider widget metadata MUST be returned
- AND no additional backend setup is required

#### Scenario: No migration or schema changes required
- GIVEN the divider-widget capability is implemented
- WHEN the MyDash app is upgraded
- THEN no database migrations MUST be created
- AND existing placements MUST continue to work without modification
