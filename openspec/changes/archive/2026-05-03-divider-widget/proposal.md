# Divider Widget

## Why

MyDash dashboards with many widgets can feel visually cluttered without clear section breaks. Dashboard creators need lightweight visual separators to organize widgets into logical groups without adding unnecessary content. A simple divider widget provides the minimal component required to break up sections, with options for a thin line, whitespace, or a heading-based divider to hierarchically structure dashboard layouts.

## What Changes

- Register a new dashboard widget with id `mydash_divider` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` to specify divider style (`line`, `whitespace`, or `heading-break`), line color/thickness/style, whitespace size, and optional heading text.
- Implement fully client-side Vue 3 SFC `DividerWidget.vue` with three render modes: a thin horizontal line (themeable), a vertical spacer block, or a centered heading with horizontal lines above and below.
- Support sensible sizing defaults: dividers default to `gridHeight = 1` (minimal footprint) and `gridWidth = full dashboard width` in the widget add modal.
- Enforce accessibility: line/whitespace dividers use semantic `role="separator"` attributes; heading-break dividers use semantic `<h3>` with aria-label.
- Provide theme awareness: the default line color follows the active Nextcloud theme via CSS custom property `--color-border`; explicit lineColor config overrides the theme.
- Ensure print visibility: dividers MUST render on printed dashboards; no `display: none` rules apply in print mode.
- Provide a minimal edit form with only the four config fields (style, lineColor, lineThickness, lineStyle, whitespaceSize, headingText); no name/icon/click-target inputs to minimize UI noise.

## Capabilities

### New Capabilities

- `divider-widget` — A new MyDash dashboard widget capability providing lightweight visual section breaks and spacing controls for organizing dashboard layouts.

## Impact

**Affected code:**

- `src/components/widgets/DividerWidget.vue` — client-side Vue 3 SFC rendering line, whitespace, or heading-break dividers.
- `src/components/widgets/config/DividerWidgetConfig.vue` — placement config UI for selecting divider style, line properties, whitespace size, and heading text.
- `appinfo/routes.php` — no new routes required (divider is fully client-side).
- No database migrations required (widgetContent JSON is flexible; no schema changes).
- No backend service classes required.

**Affected APIs:**

- 0 new routes: all rendering is client-side. Widget discovery picks up the widget via Nextcloud's dashboard registration.

**Dependencies:**

- `OCP\Dashboard\IManager` — register the widget on app boot.
- No new composer or npm dependencies.

**Migration:**

- Zero-impact: no schema changes. Existing dashboards are unaffected; new divider widgets are added via the widget picker with default config.
- No data backfill required.
