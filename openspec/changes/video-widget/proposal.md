# Video widget

## Why

Dashboard users want to embed video content directly on their dashboards — whether from popular platforms like YouTube and Vimeo, self-hosted PeerTube instances, or internal Nextcloud Files storage. Currently there is no built-in video widget, forcing users to use generic iframes or external embedding plugins. This change introduces a first-class `video-widget` capability that registers a standardized Nextcloud dashboard widget with:

- Support for multiple video sources (YouTube, Vimeo, PeerTube, Nextcloud Files)
- Admin-controlled domain allowlist for embedded videos (security gate)
- Per-placement configuration: autoplay, muting, looping, controls, aspect ratio, poster image
- Server-side URL parsing to extract video IDs and validate against allowed domains
- HTML5 `<video>` tag for internal files with ACL checks; `<iframe>` with CSP-safe sandbox attributes for hosted platforms
- Optional YouTube no-cookie domain support to reduce tracking
- Responsive aspect-ratio enforcement via CSS

## What Changes

- Register a new widget `mydash_video` with Nextcloud's Dashboard API via `OCP\Dashboard\IManager`
- Add global admin setting `mydash.video_widget_allowed_domains` (JSON array, default: `["youtube.com","www.youtube.com","youtu.be","vimeo.com","player.vimeo.com"]`)
- Add optional admin setting `mydash.video_widget_use_nocookie_youtube` (boolean, default: `false`)
- Widget placement's `widgetContent` JSON stores:
  - `sourceType`: `'youtube' | 'vimeo' | 'peertube' | 'nc-file'`
  - `videoUrl` (for hosted) or `fileId` (for `nc-file`)
  - `autoplay`, `muted`, `loop`, `controls`, `aspectRatio`, `posterUrl`
- VideoWidgetController handles:
  - GET `/api/widgets/video/parse` — extract video ID from URL, return canonical embed URL, validate domain
  - GET `/api/widgets/video/file/{fileId}` — resolve Nextcloud file, check viewer ACL, return streaming URL
- VideoWidget.vue renders:
  - Hosted platforms (YouTube/Vimeo/PeerTube) via iframe with sandbox
  - Nextcloud Files via HTML5 video tag
  - Empty state, failure state, invalid-domain state

## Capabilities

### New Capabilities

- `video-widget`: REQ-VID-001 through REQ-VID-011 (registration, config schema, source-type handling, allowed-domains enforcement, URL parsing, nc-file ACL, iframe sandbox, autoplay+mute interaction, aspect-ratio, nocookie option, empty/failure states)

### Modified Capabilities

- (none — widget ecosystem already exists; this is a new widget plugin)

## Impact

**Affected code:**

- `lib/Service/VideoParsingService.php` — URL extraction, ID validation, domain checking against allowlist
- `lib/Controller/VideoWidgetController.php` — parse endpoint + file access endpoint
- `lib/Dashboard/VideoWidgetProvider.php` — Nextcloud Dashboard API registration
- `src/components/widgets/VideoWidget.vue` — frontend render logic (iframe, video tag, error states)
- `src/stores/widgets.js` — optional computed property for widget config helpers
- `appinfo/routes.php` — register two new controller routes
- `appinfo/info.xml` — declare `video-widget` capability
- No schema changes to MyDash tables (widget config is stored in existing `widgetContent` JSON)

**Affected APIs:**

- 2 new routes: `GET /api/widgets/video/parse` (public?) and `GET /api/widgets/video/file/{fileId}` (logged-in)
- Nextcloud Dashboard API registration adds widget to discovery list

**Dependencies:**

- No new Composer or npm dependencies (uses built-in PHP URL parsing, Nextcloud File API)

**Migration:**

- Zero-impact: no database schema changes. Existing dashboards continue to work. The video widget is opt-in at placement time.

## Standards & References

- OpenSpec: `openspec/specs/widgets/spec.md` — widget discovery and placement
- Nextcloud Dashboard API: `OCP\Dashboard\IManager`, `OCP\Dashboard\IWidget`, registration in `lib/AppManager.php`
- OWASP: iframe sandbox attribute for embedded content security (CSP-friendly)
- WCAG 2.1 AA: video controls, poster image for accessibility
- i18n requirement: all messages in English and Dutch
