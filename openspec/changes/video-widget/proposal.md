# Video Widget

## Why

MyDash dashboards today have no native way to embed video content. Customers currently work around this using iframe widgets pointed at external video URLs, iframe embeds copy-pasted from YouTube/Vimeo, or custom markdown — none of which are safe, accessible, or integrated with Nextcloud's file storage. A dedicated video widget is essential for dashboard applications that surface instructional videos, corporate media, user-generated content, or internally-hosted training materials. Competitor dashboard platforms (Grafana, Power BI, Looker) all include native video widgets.

The widget must support four source types: YouTube, Vimeo, self-hosted PeerTube instances, and Nextcloud Files (with ACL enforcement). Hosted platforms must be embedded via sandboxed iframes; internal files must use HTML5 `<video>` with server-side ACL checks. Admin-controlled domain allowlisting prevents embedding videos from untrusted sources. Optional YouTube no-cookie embedding reduces tracking. All content and error messages must be localized (English/Dutch).

## What Changes

- Add a new widget type `video` rendered via `src/components/Widgets/Renderers/VideoWidget.vue`.
- Persisted shape: `{type: 'video', content: {sourceType, videoUrl, fileId, autoplay, muted, loop, controls, aspectRatio, posterUrl, error}}` stored in the widget placement's `styleConfig` JSON column. No schema migration required.
- Backend parsing service (`VideoParsingService`) normalizes YouTube, Vimeo, and PeerTube URLs to canonical embed forms; validates domains against admin-controlled allowlist; caches canonical URLs to avoid re-parsing on every render.
- File service (`VideoFileService`) resolves Nextcloud file IDs with ACL checks; validates MIME type; returns streaming URL only if the viewing user has read permission.
- Parse API endpoint (`POST /api/widgets/video/parse`) allows frontend to validate URLs during editing.
- Widget form (`src/components/Widgets/Forms/VideoForm.vue`) offers source-type selector, URL/file-picker input, playback toggles (autoplay, muted, loop, controls), aspect-ratio picker, and optional poster image.
- Register the new type in `src/constants/widgetRegistry.js` with defaults.

## Capabilities

### New Capabilities

- `video-widget`: adds REQ-VID-001 (Dashboard API registration), REQ-VID-002 (storage in styleConfig), REQ-VID-003 (source type support), REQ-VID-004 (domain allowlisting), REQ-VID-005 (URL parsing), REQ-VID-006 (file ACL), REQ-VID-007 (iframe sandbox), REQ-VID-008 (playback options), REQ-VID-009 (aspect ratio), REQ-VID-010 (no-cookie YouTube), REQ-VID-011 (error states).

### Modified Capabilities

(none — this is a self-contained renderer + form + backend services; existing widget capabilities are untouched.)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/VideoWidget.vue` — new renderer (props: `content`, `placement`)
- `src/components/Widgets/Forms/VideoForm.vue` — new form sub-component for `AddWidgetModal`
- `src/constants/widgetRegistry.js` — register `type: 'video'` with defaults
- Backend services:
  - `VideoParsingService` — normalize and validate hosted-platform URLs
  - `VideoFileService` — resolve Nextcloud file IDs and ACL
  - `VideoController` — endpoint `POST /api/widgets/video/parse`
- Translation entries: all UI labels and error messages in both English and Dutch (ADR-005, ADR-007)

**Affected APIs:**

- `POST /api/widgets/video/parse` — new endpoint for URL validation during widget editing
- Admin settings: `mydash.video_widget_allowed_domains` (JSON array), `mydash.video_widget_use_nocookie_youtube` (boolean)

**Dependencies:**

- No new composer or npm dependencies (no third-party parser libraries).
- Nextcloud file API (RootFolder, PERMISSION_READ, standard MIME type checks).
- Existing widget placement CRUD in the `widgets` capability.

**Migration:**

- No database migration. Widget content is stored in the existing `oc_mydash_widget_placements.styleConfig` JSON blob. Old placements without `type: 'video'` are unaffected.

**Out of scope:**

- Livestream support (YouTube Live, Twitch) — can be added in a follow-up if demand emerges.
- Closed captioning selection UI — hosted platforms handle captions natively; internal files inherit browser support.
- Chromecast / AirPlay integration — browser APIs only.
- Analytics tracking (view count, play events) — left to third-party integrations.
