# Spec — Theming and Accessibility (REQ-EMB-005, REQ-EMB-010)

## REQ-EMB-005 — Per-tenant theming via CSS variables

The embed SHALL apply the token's `tenantThemeId` (if set) by injecting `cssVariables` into the embed's `:root` and SHALL refuse `customCss` rules that target selectors outside `:root`.

### Scenario 5.1 — CSS variables injected into :root

GIVEN a `tenant_theme` object with:
  ```json
  {
    "id": "theme-zeist",
    "name": "Gemeente Zeist",
    "cssVariables": {
      "--c-primary": "#21468B",
      "--font-family": "Inter, sans-serif",
      "--c-surface": "#FFFFFF",
      "--c-text": "#1a1a1a",
      "--c-border": "#e0e0e0"
    }
  }
  ```
  AND an `embed_token` with `tenantThemeId="theme-zeist"` for widget W
WHEN the embed render route generates HTML for widget W
THEN the response SHALL include:
  ```html
  <html>
    <head>
      <style>
        :root {
          --c-primary: #21468B;
          --font-family: Inter, sans-serif;
          --c-surface: #FFFFFF;
          --c-text: #1a1a1a;
          --c-border: #e0e0e0;
        }
      </style>
    </head>
    <body>
      <!-- widget HTML -->
    </body>
  </html>
  ```
  AND the widget's styles SHALL use these CSS variables
  AND the widget SHALL paint with the gemeente's brand colour (primary), font, and surface colour

### Scenario 5.2 — Custom CSS restricted to :root only

GIVEN a `tenant_theme` with `customCss=":root { --spacing: 8px; }"`
WHEN the theme is saved via `POST /api/tenant-themes`
THEN the API SHALL accept the theme and respond 201

### Scenario 5.3 — Custom CSS with non-:root selector rejected

GIVEN an admin attempting to save a `tenant_theme` with `customCss=".widget-title { color: red; }"`
WHEN the POST `/api/tenant-themes` request is processed
THEN the API SHALL respond 400 with body:
  ```json
  {
    "status": "error",
    "error": "invalid_customCss",
    "message": "CSS rules must be scoped to :root only. Found selector '.widget-title' which targets elements outside :root. Non-:root selectors can be used to disable required UI affordances (e.g., hide accessibility focus rings).",
    "fieldErrors": {
      "customCss": "non_root_selector"
    }
  }
  ```
  AND the theme SHALL NOT be created

### Scenario 5.4 — No theme uses platform default

GIVEN an `embed_token` with `tenantThemeId: null` (no theme selected)
WHEN the embed render route generates HTML
THEN the response SHALL use the platform's default NL Design theme (no custom :root injection)
  AND the widget SHALL render with standard MyDash colours and fonts

### Scenario 5.5 — Theme logo displayed in embed header

GIVEN a `tenant_theme` with `logoUrl="https://www.zeist.nl/logo.svg"`
  AND an `embed_token` referencing this theme
WHEN the embed is rendered in iframe mode
THEN the render route MAY include a minimal embed header with:
  ```html
  <div class="embed-header">
    <img src="https://www.zeist.nl/logo.svg" alt="Gemeente Zeist" class="embed-logo" />
  </div>
  ```
  AND the header SHALL be minimal (no chrome, no navigation, no user menu)
  OR the header MAY be omitted if the embed is configured for full-bleed rendering

### Scenario 5.6 — Theme inheritance in admin UI

GIVEN the admin creating a new embed token
WHEN they select a `tenantThemeId`
THEN the admin UI SHALL display:
  - Theme name ("Gemeente Zeist")
  - Live preview of brand colours (primary, surface, text)
  - Logo preview (if set)
  AND a "View CSS variables" button to inspect the variable overrides

### Scenario 5.7 — Multiple tokens can share a theme

GIVEN a `tenant_theme` "Gemeente Zeist"
WHEN two different `embed_token` objects both reference `tenantThemeId="theme-zeist"`
THEN both embeds SHALL render with the same brand
  AND theme updates affect both embeds immediately
  AND the theme is a reusable resource, not cloned per token

---

## REQ-EMB-010 — WCAG 2.1 AA compliance enforced in embed rendering

The embed render route SHALL produce HTML output that meets WCAG 2.1 AA success criteria for the widget being embedded, SHALL pass automated axe-core checks at build time on a representative sample of widget shapes, and SHALL surface accessibility violations in the embed-token admin UI as a non-fatal warning.

### Scenario 10.1 — WCAG AA rendering requirements

GIVEN an embed render of a chart widget
WHEN the HTML is inspected against WCAG 2.1 AA criteria
THEN EVERY interactive element SHALL have:
  - An accessible name (`aria-label`, `aria-labelledby`, or visible text content)
  - Colour contrast ≥ 4.5:1 for text (WCAG 1.4.3)
  - Colour contrast ≥ 3:1 for graphical objects (WCAG 1.4.11)
AND the embed SHALL be navigable by keyboard alone:
  - All interactive elements reachable via Tab key
  - Tab order logical (left-to-right, top-to-bottom)
  - No keyboard traps (user can tab away from any element)
  - Focus indicators visible (outline or other visible focus marker)
AND semantic HTML is used:
  - Heading hierarchy correct (no skipped levels)
  - Lists marked with `<ul>`, `<ol>`, `<li>`
  - Form inputs have associated `<label>` elements

### Scenario 10.2 — Contrast check on theme CSS variables

GIVEN a `tenant_theme` with `cssVariables={"--c-primary": "#0099CC", "--c-surface": "#FFFFFF"}`
WHEN the theme is saved
THEN the API SHALL compute the colour contrast ratio using the WCAG luminance formula
  AND if contrast < 4.5:1, respond 400 with body:
  ```json
  {
    "status": "error",
    "error": "insufficient_contrast",
    "message": "Primary colour #0099CC against surface #FFFFFF has contrast ratio 3.8:1. WCAG AA requires 4.5:1 for text.",
    "fieldErrors": {
      "cssVariables.--c-primary": "insufficient_contrast"
    }
  }
  ```
  AND the theme SHALL NOT be created
  (Admin can choose a darker primary or lighter surface and retry)

### Scenario 10.3 — Build-time axe-core checks

GIVEN the MyDash build pipeline
WHEN `npm run build` executes
THEN a test step SHALL:
  1. Render a representative sample of widget shapes (e.g., chart, table, text, image, custom)
  2. Run axe-core automated checks against each rendered HTML
  3. Fail the build if high/critical violations are found
  4. Log a warning for medium/low violations with IDs
  AND the build output SHALL include an axe-core report

### Scenario 10.4 — Admin warned of accessibility violations on token creation

GIVEN an admin creating an `embed_token` for a widget W
  AND widget W has known accessibility violations (e.g., from a previous axe-core check)
WHEN the admin submits the token-create form
THEN the API SHALL check the widget's violation registry
  AND if violations exist, respond 200 (success, but with warning) with:
  ```json
  {
    "id": "token-1",
    "name": "...",
    ...
    "warnings": [
      {
        "level": "accessibility",
        "message": "Widget 'chart' has 2 known accessibility violations (axe-core build-time check):",
        "violations": [
          {
            "id": "color-contrast",
            "description": "Ensure the contrast between foreground and background colours meets WCAG AA thresholds",
            "help": "Element <text class=\"legend\"> has contrast ratio 2.8:1. Expected ≥4.5:1"
          },
          {
            "id": "aria-label",
            "description": "Ensure every graphical element has an accessible name",
            "help": "<svg class=\"chart\"> is missing aria-label"
          }
        ]
      }
    ]
  }
  ```
  AND the response includes an "Acknowledge and proceed" checkbox in the admin UI
  AND the admin MUST check the checkbox before creating the token (non-blocking, but requires acknowledgement)

### Scenario 10.5 — Axe-core check results per widget

GIVEN the admin viewing the list of available widgets
WHEN they see a widget's detail card
THEN the card SHALL display:
  - Widget name + description
  - Latest axe-core check date
  - Number of high/critical violations (in red if > 0)
  - Number of medium/low violations (in yellow if > 0)
  AND a "View accessibility report" link that opens the axe-core report for that widget

### Scenario 10.6 — Accessibility report exportable

GIVEN an admin with token-management permission
WHEN they request an accessibility compliance report
THEN the system SHALL generate a CSV/JSON report listing:
  - Widget name
  - Accessibility status (compliant / violations found)
  - Violation count by severity
  - Affected tokens (count of embeds using this widget)
  AND the report includes axe-core rule IDs and WCAG criteria references

### Scenario 10.7 — Manual accessibility review responsibility

GIVEN a widget with axe-core violations
WHEN the admin acknowledges and creates a token
THEN the token's audit trail SHALL record:
  - "Token created for widget 'chart' with acknowledged accessibility violations: color-contrast, aria-label"
  AND the responsibility for manual review (the remaining ~70% of WCAG criteria not mechanically checkable) is documented per-widget
  AND the widget author is responsible for ensuring manual compliance

### Scenario 10.8 — Keyboard navigation tested in embed iframe

GIVEN an embedded widget in an iframe
WHEN a user navigates using keyboard alone (Tab, Shift+Tab, Enter, Escape, arrow keys)
THEN:
  - All interactive elements are reachable
  - No keyboard traps exist (user can Tab out)
  - Focus indicator is visible and moves logically
  - Modals (if any) trap focus correctly
