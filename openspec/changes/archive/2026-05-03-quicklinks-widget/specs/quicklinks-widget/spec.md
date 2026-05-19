---
capability: quicklinks-widget
delta: false
status: draft
---

# Quicklinks Widget

## ADDED Requirements

### Requirement: REQ-QLNK-001 Registration and widget id

The system MUST register a new dashboard widget with id `mydash_quicklinks` via `OCP\Dashboard\IManager::registerWidget()`. The widget MUST appear in the widget picker under a translatable name `t('Quicklinks')` and MUST have a default grid size of `gridWidth = 4` and `gridHeight = 2`.

#### Scenario: Widget registered and appears in picker

- GIVEN the MyDash app has been installed and the widget is properly registered
- WHEN a user opens the widget picker
- THEN the picker MUST display an entry for `Quicklinks` (or locale-translated equivalent)
- AND selecting it MUST add a new widget placement with default config

#### Scenario: Default sizing is compact

- GIVEN a new quicklinks widget has been added to an empty dashboard
- WHEN the widget renders
- THEN it MUST occupy a 4-column × 2-row grid cell by default
- AND users can resize it via the grid-layout resize handles

### Requirement: REQ-QLNK-002 Per-placement configuration shape

The `widgetContent` JSON blob for a quicklinks widget MUST contain all of the following fields:

- `links: Array<{label: string, url: string, icon: string, color?: string, openInNewTab?: boolean}>` — array of link objects (required; empty array is valid).
- `iconSize: 'small'|'medium'|'large'|'xlarge'` (default `medium`) — controls icon rendering size.
- `iconShape: 'square'|'rounded'|'circle'` (default `rounded`) — controls border-radius of icon container.
- `showLabels: boolean` (default `true`) — toggles label text visibility.
- `labelPosition: 'below'|'overlay'` (default `below`) — `below` = labels under icons; `overlay` = labels appear on hover only.
- `columns: number | 'auto'` (default `'auto'`) — `'auto'` triggers flex-wrap; a number 1..12 triggers CSS Grid with fixed columns.
- `tileBackgroundStyle: 'transparent'|'solid'|'gradient'` (default `transparent`) — background behind each icon.
- `hoverEffect: 'lift'|'fade'|'border'|'none'` (default `lift`) — hover animation style.

The form MUST preserve all fields on save; omitted fields MUST be populated with defaults when loading.

#### Scenario: New widget receives all defaults

- GIVEN a new quicklinks widget is created
- WHEN the backend saves the initial `widgetContent` with only `links: []`
- THEN the renderer MUST apply all remaining field defaults
- AND the form MUST pre-fill all fields with defaults when editing

#### Scenario: Config round-trip preserves all fields

- GIVEN saved content `{links: [...], iconSize: 'large', iconShape: 'circle', showLabels: false, labelPosition: 'overlay', columns: 6, tileBackgroundStyle: 'solid', hoverEffect: 'fade'}`
- WHEN the form loads and saves without changes
- THEN all eight fields MUST be persisted exactly as input
- AND no defaults MUST override user choices

### Requirement: REQ-QLNK-003 Icon resolution

Each link's `icon` field MUST follow the same dual-mode convention as link-button-widget (REQ-LBN-002):

- A custom URL (starts with `/` or `http(s)://`) MUST render as `<img>` inside the icon container.
- A bare name (e.g., `folder`, `calendar`, `mail`) MUST render via the shared `IconRenderer` (built-in MDI component).
- An empty or null value MUST render a fallback "link" icon (MDI `link` icon or similar).

Icon size is controlled by the `iconSize` field (REQ-QLNK-004); label rendering by `showLabels` and `labelPosition` (REQ-QLNK-005).

#### Scenario: Custom URL icon renders as image

- GIVEN content `{links: [{label: 'Docs', url: 'https://example.com', icon: '/apps/mydash/resource/docs.png'}]}`
- WHEN the widget renders with `iconSize: 'medium'`
- THEN the icon container MUST display `<img src="/apps/mydash/resource/docs.png">` sized 48 px square
- AND the label `Docs` MUST appear below (or overlay on hover, depending on `labelPosition`)

#### Scenario: MDI icon name renders via IconRenderer

- GIVEN content `{links: [{label: 'Calendar', url: 'https://nc/calendar', icon: 'calendar'}]}`
- WHEN the widget renders
- THEN the icon container MUST render the calendar icon via `<IconRenderer :name="calendar" />`
- AND at the size specified by `iconSize`

#### Scenario: Empty icon renders fallback

- GIVEN content `{links: [{label: 'Quick link', url: 'https://example.com', icon: ''}]}`
- WHEN the widget renders
- THEN the icon container MUST display the fallback "link" icon (MDI `link`)

### Requirement: REQ-QLNK-004 Icon sizes and shapes

The `iconSize` field MUST map to pixel dimensions as follows:

- `small` → 32 px square
- `medium` → 48 px square
- `large` → 64 px square
- `xlarge` → 96 px square

The `iconShape` field MUST control the CSS `border-radius` of the icon container as follows:

- `square` → `border-radius: 0`
- `rounded` → `border-radius: 8px`
- `circle` → `border-radius: 50%`

When `tileBackgroundStyle = 'transparent'`, the shape only affects the icon's background clip (no visible border). When `tileBackgroundStyle = 'solid'` or `'gradient'`, the shape is visually prominent.

#### Scenario: Icon size small renders 32px

- GIVEN content with `iconSize: 'small'` and `iconShape: 'circle'`
- WHEN the widget renders
- THEN each icon container MUST be exactly 32 px × 32 px with `border-radius: 50%`

#### Scenario: Icon size xlarge with rounded shape

- GIVEN `iconSize: 'xlarge'` and `iconShape: 'rounded'`
- WHEN the widget renders
- THEN each icon container MUST be 96 px × 96 px with `border-radius: 8px`

#### Scenario: Shape has no effect when background is transparent

- GIVEN `tileBackgroundStyle: 'transparent'` and `iconShape: 'circle'`
- WHEN the widget renders
- THEN the icon container MUST use `border-radius: 50%` but no visible background fill
- AND only the icon itself is visible (no coloured tile behind it)

### Requirement: REQ-QLNK-005 Label position control

When `showLabels: true`, each link MUST display a label. The `labelPosition` field MUST control where and when the label appears:

- `below` → label appears directly below the icon at all times; each link cell occupies `iconSize + label height` vertical space.
- `overlay` → label appears centred over the icon only on hover (`:hover`, keyboard focus); default rendering shows icon only; hovering or focusing the link shows the label centred over the icon.

When `showLabels: false`, no label MUST be rendered regardless of `labelPosition`.

The label text MUST be the link's `label` field, truncated to a sensible max length (e.g., 20 chars) and wrapped if `labelPosition: 'below'`.

#### Scenario: Labels below icons

- GIVEN content with `showLabels: true`, `labelPosition: 'below'`, and three links with labels `Docs`, `Calendar`, `Team`
- WHEN the widget renders
- THEN each link MUST display its label directly below the icon, visible at all times
- AND the vertical space consumed is `iconSize (48px) + label area (approx 20px)` ≈ 68 px per row

#### Scenario: Labels overlay on hover

- GIVEN `showLabels: true`, `labelPosition: 'overlay'`, and `iconSize: 'medium'`
- WHEN the widget renders without hover
- THEN icons are visible but labels are hidden
- AND the vertical space consumed is only `iconSize (48px)` per row
- AND when the user hovers over a link, the label MUST appear centred over the icon
- AND the label MUST disappear when hover ends

#### Scenario: showLabels false suppresses all labels

- GIVEN `showLabels: false`, regardless of `labelPosition`
- WHEN the widget renders
- THEN no label text MUST appear, even on hover
- AND the vertical footprint is minimized

### Requirement: REQ-QLNK-006 Column layout flexibility

The `columns` field MUST control the layout grid as follows:

- `'auto'` (default) → CSS Flexbox with `flex-wrap: wrap`. Items flow left-to-right, wrapping to the next row when the widget width is exhausted. Item width is elastic based on `iconSize + label area`.
- A number `1..12` → CSS Grid with `grid-template-columns: repeat(N, 1fr)`, where `N` is the specified number. Each item occupies exactly `1/N` of the widget's width.

The renderer MUST ignore invalid values (e.g., `columns: 13` or `columns: 'invalid'`) and fall back to `'auto'`.

#### Scenario: Auto columns with wrap

- GIVEN a 300px-wide widget, `columns: 'auto'`, and `iconSize: 'medium'` (48px + label ≈ 60px wide)
- WHEN the widget renders five links
- THEN the layout MUST flow left-to-right:
  - Row 1: links 1, 2, 3, 4, 5 (if width permits) or wrap when insufficient space
  - Each row wraps automatically based on available width

#### Scenario: Fixed columns with grid

- GIVEN `columns: 3` and `iconSize: 'large'` (64px + label ≈ 80px wide)
- WHEN the widget renders nine links
- THEN the layout MUST be a 3-column grid:
  - Row 1: links 1, 2, 3
  - Row 2: links 4, 5, 6
  - Row 3: links 7, 8, 9
- AND each column occupies exactly `1/3` of the widget width

#### Scenario: Invalid column value falls back to auto

- GIVEN `columns: 0` or `columns: 'invalid'` or `columns: 15`
- WHEN the widget renders
- THEN the system MUST fall back to `columns: 'auto'` (flex-wrap)
- AND log a console warning (optional)

### Requirement: REQ-QLNK-007 Hover effects

The `hoverEffect` field MUST control the CSS animation when a user hovers over a link:

- `lift` (default) → translate the icon upward by 2–4 px and add a subtle box-shadow (e.g., `0 4px 8px rgba(0, 0, 0, 0.1)`). Transition duration ≈ 200ms.
- `fade` → reduce opacity of non-hovered links to 0.6 while hovered link remains 1.0. Transition duration ≈ 150ms.
- `border` → add a 2–3 px border or outline to the hovered icon container (colour: primary brand colour or icon `color` if set). Transition duration ≈ 100ms.
- `none` → no hover effect; link remains identical on hover (only cursor changes to pointer).

Effects MUST apply only to the hovered link, not the entire widget.

#### Scenario: Lift effect on hover

- GIVEN `hoverEffect: 'lift'` and a widget with five links
- WHEN the user hovers over link 3
- THEN link 3 MUST translate upward 2–4 px and display a soft shadow
- AND links 1, 2, 4, 5 remain unmoved
- AND the transition duration MUST be ~200ms

#### Scenario: Fade effect on hover

- GIVEN `hoverEffect: 'fade'` and five links
- WHEN the user hovers over link 2
- THEN link 2 MUST remain at opacity 1.0
- AND links 1, 3, 4, 5 MUST fade to opacity 0.6
- AND the transition duration MUST be ~150ms

#### Scenario: Border effect on hover

- GIVEN `hoverEffect: 'border'` and `iconShape: 'rounded'`
- WHEN the user hovers over a link
- THEN a 2–3 px border MUST appear around the icon container
- AND the border colour MUST be the link's `color` field (if set) or the primary brand colour
- AND the border MUST disappear when hover ends

#### Scenario: No hover effect

- GIVEN `hoverEffect: 'none'`
- WHEN the user hovers over a link
- THEN the link rendering MUST be identical (no transform, opacity change, or border)
- AND only the cursor MUST change to `pointer`

### Requirement: REQ-QLNK-008 URL sanitisation on save

When the edit form submits, the system MUST validate each link's `url` field. Invalid URLs MUST be rejected with an HTTP 400 response and a user-friendly error message. The validation rules are:

- MUST start with `http://`, `https://`, or `/` (relative Nextcloud URLs).
- MUST NOT contain `javascript:`, `data:`, `vbscript:`, or other dangerous protocols.
- MUST NOT be empty or null.
- MUST NOT exceed 2048 characters.

The form MUST display inline validation feedback (red border + error text) on the URL field before the user submits. On server-side save failure, the form MUST display a toast: `t('Invalid URL in one or more links')`.

#### Scenario: Valid HTTP URL accepted

- GIVEN a link with `url: 'https://example.com/page?param=value'`
- WHEN the form submits
- THEN the URL MUST be accepted and saved
- AND no error MUST be displayed

#### Scenario: Relative Nextcloud URL accepted

- GIVEN a link with `url: '/apps/files/'`
- WHEN the form submits
- THEN the URL MUST be accepted and saved

#### Scenario: JavaScript protocol rejected

- GIVEN a link with `url: 'javascript:void(0)'` or `url: 'javascript:alert("xss")'`
- WHEN the form submits
- THEN the system MUST return HTTP 400 with error `{error: 'Invalid URL'}`
- AND the form MUST display a red border on the URL field and the inline error message

#### Scenario: Data URL rejected

- GIVEN a link with `url: 'data:text/html,<img src=x onerror=alert(1)>'`
- WHEN the form submits
- THEN HTTP 400 MUST be returned
- AND the URL field MUST be highlighted as invalid

#### Scenario: Empty URL rejected

- GIVEN a link with `url: ''` or `url: null`
- WHEN the form submits or validates
- THEN an error MUST be displayed (inline in the form, or on submit)
- AND the link MUST not be saved

### Requirement: REQ-QLNK-009 Click and navigation

When a user clicks a link in the rendered widget, the system MUST navigate based on the link's `url` and `openInNewTab` fields:

- If `url` starts with `/` (relative, internal Nextcloud URL) and `openInNewTab: false` (or not set and auto-detect resolves to same-tab), navigate in the same tab via `router.push(url)` or `window.location.href = url`.
- If `url` starts with `http(s)://` (external) OR `openInNewTab: true`, open in a new tab via `window.open(url, '_blank', 'noopener,noreferrer')`.
- The system MUST NOT navigate if the surrounding dashboard is in edit mode (admin/edit context), matching the pattern from link-button-widget.

Auto-detect for `openInNewTab` when not specified: if the URL is external (`http(s)://`), default to `true`; if relative (`/`), default to `false`.

#### Scenario: External URL opens in new tab

- GIVEN a link with `url: 'https://example.com'` and no `openInNewTab` field
- WHEN the user clicks the link (not in edit mode)
- THEN the system MUST call `window.open('https://example.com', '_blank', 'noopener,noreferrer')`
- AND a new tab MUST open

#### Scenario: Internal URL opens in same tab

- GIVEN a link with `url: '/apps/files/'` and no `openInNewTab` field
- WHEN the user clicks the link (not in edit mode)
- THEN the system MUST navigate to `/apps/files/` in the same tab
- AND no new tab MUST be opened

#### Scenario: Click suppressed in edit mode

- GIVEN the dashboard is in edit mode (admin context, `canEdit: true`)
- WHEN the user clicks a link
- THEN no navigation MUST occur (no `window.open`, no router navigation)
- AND the cursor MUST change to `not-allowed` or similar, indicating the link is inactive

#### Scenario: Explicit openInNewTab overrides auto-detect

- GIVEN a link with `url: '/apps/files/'` and `openInNewTab: true`
- WHEN the user clicks the link
- THEN the system MUST open the URL in a new tab (even though it's internal)

### Requirement: REQ-QLNK-010 Accessibility

Each link in the rendered widget MUST be an HTML `<a>` element (not a `<button>` or `<div>`). The `<a>` MUST have:

- `href` attribute set to the link's `url`.
- `aria-label` set to a descriptive label. If `showLabels: true` and `labelPosition: 'below'`, use the visible label. Otherwise, use `url` hostname or a fallback like `t('Link to') + ' ' + extractHostname(url)`.
- `target="_blank"` and `rel="noopener noreferrer"` when the link opens in a new tab.
- Keyboard accessibility: Tab navigation MUST cycle through all links in the widget in source order.
- Focus styles: links MUST have a visible focus indicator (e.g., outline or border) matching Nextcloud design standards.

#### Scenario: Link is keyboard accessible

- GIVEN the widget is rendered and has focus outside
- WHEN the user presses Tab
- THEN focus MUST move to the first link in the widget
- AND subsequent Tab presses MUST cycle through all links
- AND pressing Shift+Tab MUST move backwards through links

#### Scenario: Aria-label uses visible label when available

- GIVEN `showLabels: true`, `labelPosition: 'below'`, and a link with `label: 'Docs'`
- WHEN the widget renders
- THEN the `<a>` MUST have `aria-label="Docs"`

#### Scenario: Aria-label uses hostname for overlay labels

- GIVEN `showLabels: true`, `labelPosition: 'overlay'`, and a link with `label: 'Docs'` and `url: 'https://docs.example.com'`
- WHEN the widget renders
- THEN the `<a>` MUST have `aria-label` similar to `'Link to docs.example.com'` (hostname extracted)
- NOTE: The visible label `Docs` is only shown on hover; the screen reader must have a full context via aria-label

#### Scenario: Focus visible on keyboard navigation

- GIVEN a link in the widget
- WHEN the user navigates to it via Tab
- THEN a visible focus indicator MUST be present (outline, border, or similar)
- AND the focus style MUST meet WCAG AA contrast requirements

### Requirement: REQ-QLNK-011 Empty state and default sizing

When a quicklinks widget has zero links (`links: []`), the renderer MUST display an empty state message. The message MUST be:

- Translatable: `t('No quicklinks yet — click the gear icon to add some.')` (or similar in Dutch: `t('Nog geen snelkoppelingen — klik op het tandwielpictogram om er enkele toe te voegen.')`).
- Centred horizontally and vertically in the widget's viewport.
- Styled with a light text colour (e.g., `var(--color-text-maxcontrast)`) and icon (gear or edit icon).

When a user clicks the gear/edit icon displayed in the empty state OR the widget's edit button, the edit form MUST open.

By default, new quicklinks widgets MUST be sized to `gridWidth = 4` and `gridHeight = 2`. Users can resize via the grid-layout resize handles.

#### Scenario: Empty widget shows empty state

- GIVEN a new quicklinks widget with `links: []`
- WHEN the widget renders
- THEN the empty state message `'No quicklinks yet — click the gear icon to add some.'` MUST be displayed
- AND a gear icon or similar affordance MUST be visible

#### Scenario: Clicking empty state affordance opens edit form

- GIVEN the empty state is displayed
- WHEN the user clicks the gear icon or empty state text
- THEN the edit form MUST open
- AND the form MUST display an empty links table ready for the user to add links

#### Scenario: Default widget size is compact

- GIVEN a new quicklinks widget is added to a dashboard
- WHEN the widget is placed
- THEN it MUST default to `gridWidth = 4` and `gridHeight = 2`
- AND users can drag the resize handle to adjust size

