# Header Widget

## Why

MyDash dashboards often need prominent visual headers to contextualize content—a banner with a title, subtitle, background imagery, and optional call-to-action. Currently, this requires custom styling of regular widgets or ad-hoc design. A dedicated header widget provides a first-class, configurable banner component that can be placed at the top of any dashboard, replacing the legacy "header row" concept with a reusable, flexible widget that supports images, overlays, text alignment, and accessibility.

## What Changes

- Register a new dashboard widget with id `mydash_header` via `OCP\Dashboard\IManager` that appears in the widget picker.
- Add per-placement configuration stored in `widgetContent JSON` to specify title, optional subtitle, background image (URL or NC file ID), overlay color/mode, text styling, sizing, and optional CTA button.
- Image sources: support external URLs with an allow-list (`mydash.header_widget_allowed_image_domains`), and internal Nextcloud file IDs (via NC Files preview route) with file-read ACL checks.
- Image-load failures gracefully fall back to solid background color (no error UI in header context).
- Overlay modes: `none` (solid background only), `tint` (semi-transparent color overlay), `gradient-bottom` (linear gradient).
- Height presets: `small` (120px), `medium` (200px), `large` (320px), `xlarge` (480px).
- Render: Vue 3 SFC `HeaderWidget.vue` using CSS `background-image` for images and absolute-positioned overlay div for tint/gradient.
- Text styling: configurable color, alignment (left/center/right), vertical alignment (top/middle/bottom).
- CTA button: optional link with configurable style (primary/secondary/ghost) and target (new tab for external URLs, same tab for internal).
- Accessibility: title as `<h2>`, subtitle as `<p>`, CTA with proper ARIA labels.
- Default sizing: headers fill the full dashboard width by default.

## Capabilities

### New Capabilities

- `header-widget` — A new MyDash dashboard widget capability providing full-width banners with images, overlays, text, and CTA buttons.

## Impact

**Affected code:**

- `lib/Service/HeaderWidgetService.php` — core logic for validating image sources, allow-list checks, file ACL validation.
- `lib/Controller/WidgetController.php` — no new endpoints required (fully client-side).
- `src/components/widgets/HeaderWidget.vue` — widget render component.
- `src/components/widgets/headerpicker/HeaderWidgetConfig.vue` — placement config UI for editing title, subtitle, image, overlay, text, and CTA.
- `appinfo/routes.php` — no new routes required.
- `lib/Migration/VersionXXXXDate2026...AddHeaderWidgetSettings.php` — schema migration adding app config setting `mydash.header_widget_allowed_image_domains` (JSON array; empty = all allowed).
- `src/stores/widgets.js` — optional: widget-specific runtime state for image load status.

**Affected APIs:**

- 0 new routes (fully client-side widget with standard NC image routes).

**Dependencies:**

- `OCP\Files\IRootFolder` — file read ACL check for `backgroundImageFileId`.
- `OCP\IAppConfig` — admin setting for allow-list.
- No new composer or npm dependencies.

**Migration:**

- Zero-impact: app config key created on demand via IAppConfig getter.
- No schema changes required beyond optional settings.
- No data backfill required.
