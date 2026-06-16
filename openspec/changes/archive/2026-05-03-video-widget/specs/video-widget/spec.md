---
status: draft
---

# Video Widget Specification

## ADDED Requirements

### Requirement: REQ-VID-001 Register Video Widget with Nextcloud Dashboard API

The system MUST register a video widget with Nextcloud's Dashboard API so it appears in widget discovery and can be added to dashboards.

#### Scenario: Widget is discovered in the widget list
- GIVEN Nextcloud has the Dashboard Widget API enabled
- WHEN a user navigates to the dashboard widget picker via MyDash
- THEN GET /api/widgets MUST include a widget with `id: "mydash_video"`, `title: "Video"`, and an icon
- AND the widget MUST be registered via `OCP\Dashboard\IManager::registerWidget()` in `VideoWidgetProvider`
- AND the widget MUST implement `OCP\Dashboard\IWidget`

#### Scenario: Widget metadata is localized
- GIVEN the video widget is registered
- WHEN a user requests the widget list
- THEN the widget title MUST be localized to English ("Video") and Dutch ("Video")
- AND the description MUST be localized (English: "Embed video from YouTube, Vimeo, PeerTube, or Nextcloud Files")

#### Scenario: Widget icon is visually distinct
- GIVEN the video widget is displayed in the widget picker
- WHEN rendered alongside other widgets
- THEN the icon MUST be a clear video-player symbol (play button or film reel)
- AND the icon file MUST be stored at `img/widgets/video.svg`

#### Scenario: Widget registration occurs on app boot
- GIVEN the MyDash app is installed and enabled
- WHEN Nextcloud boots and initializes app managers
- THEN `VideoWidgetProvider::load()` MUST be called by Nextcloud's AppManager
- AND the video widget registration MUST complete without errors

### Requirement: REQ-VID-002 Store Video Widget Configuration per Placement

The widget placement's `widgetContent` JSON MUST store source type, URL/file ID, and display options.

#### Scenario: Widget stores YouTube URL
- GIVEN a user adds the video widget to their dashboard
- WHEN they configure it with `sourceType: 'youtube'` and `videoUrl: 'https://youtube.com/watch?v=ABC123'`
- THEN a widget placement is created with `widgetContent` containing:
  ```json
  {
    "sourceType": "youtube",
    "videoUrl": "https://www.youtube.com/embed/ABC123",
    "autoplay": false,
    "muted": true,
    "loop": false,
    "controls": true,
    "aspectRatio": "16:9",
    "posterUrl": null
  }
  ```
- AND `videoUrl` MUST be stored as the canonical embed URL (server-side parsed, not user-entered raw form)

#### Scenario: Widget stores Nextcloud file reference
- GIVEN a user selects a video file from their Nextcloud storage
- WHEN they add the video widget with `sourceType: 'nc-file'` and `fileId: 12345`
- THEN `widgetContent` MUST contain:
  ```json
  {
    "sourceType": "nc-file",
    "fileId": 12345,
    "autoplay": false,
    "muted": true,
    "loop": false,
    "controls": true,
    "aspectRatio": "16:9"
  }
  ```
- AND `posterUrl` MUST be absent for `nc-file` type (or null)

#### Scenario: Placement update modifies video config
- GIVEN a widget placement with a stored video URL
- WHEN the user sends PUT /api/widgets/{placementId} with updated `widgetContent`
- THEN the new config (including revalidation of domain, re-parsing of URL if changed) MUST be stored
- AND the change MUST take effect on the next render

#### Scenario: Config defaults are applied
- GIVEN a user submits a new widget placement with minimal config
- WHEN only `sourceType` and `videoUrl` are provided
- THEN the backend MUST apply defaults:
  - `autoplay: false`
  - `muted: true`
  - `loop: false`
  - `controls: true`
  - `aspectRatio: "16:9"`
  - `posterUrl: null`

### Requirement: REQ-VID-003 Support Multiple Video Source Types

The widget MUST handle YouTube, Vimeo, PeerTube, and Nextcloud Files as distinct source types with appropriate rendering logic.

#### Scenario: YouTube URL is normalized to embed form
- GIVEN a user submits raw YouTube URLs in various formats:
  - `https://www.youtube.com/watch?v=ABC123`
  - `https://youtu.be/ABC123`
  - `https://youtube.com/watch?v=ABC123&t=30s`
- WHEN `VideoParsingService::parseYouTubeUrl()` processes each
- THEN each MUST extract the video ID `ABC123` and return canonical form `https://www.youtube.com/embed/ABC123`
- AND time offset (`t=30s`) MAY be appended as `?start=30` to the embed URL

#### Scenario: Vimeo URL is normalized
- GIVEN raw Vimeo URLs:
  - `https://vimeo.com/12345`
  - `https://player.vimeo.com/video/12345`
- WHEN parsed
- THEN the video ID `12345` MUST be extracted and returned as `https://player.vimeo.com/video/12345`

#### Scenario: PeerTube URL is preserved and validated
- GIVEN a PeerTube instance URL like `https://peertube.example.com/w/abc123`
- WHEN `VideoParsingService::parsePeerTubeUrl()` processes it
- THEN the domain and UUID/path MUST be extracted and validated as a PeerTube-compatible embed form
- AND the domain MUST be checked against the allowed domains list
- AND the returned URL MUST be suitable for iframe embedding

#### Scenario: Nextcloud file is resolved with ACL
- GIVEN a file ID `12345` in the user's Nextcloud instance
- WHEN the widget render logic calls `VideoFileService::getAccessibleUrl(12345, userId)`
- THEN the system MUST:
  - Load the file metadata via `RootFolder::getById()`
  - Check if the viewing user has `\OCP\Constants::PERMISSION_READ` on the file
  - Return the streaming URL if accessible, or throw `AccessDeniedException` if not

### Requirement: REQ-VID-004 Enforce Admin-Controlled Domain Allowlist

The system MUST prevent embedding videos from arbitrary domains unless explicitly allowed by the administrator.

#### Scenario: Allowed domains setting exists
- GIVEN no `mydash.video_widget_allowed_domains` setting is configured
- WHEN the system boots
- THEN the default allowlist MUST be:
  ```json
  ["youtube.com", "www.youtube.com", "youtu.be", "vimeo.com", "player.vimeo.com"]
  ```
- AND the admin MUST be able to override this via MyDash admin settings page

#### Scenario: Widget save rejects disallowed domain
- GIVEN admin has set `mydash.video_widget_allowed_domains` to only `["youtube.com", "www.youtube.com", "youtu.be"]`
- WHEN a user attempts to save a widget with `sourceType: 'vimeo'` and `videoUrl: 'https://vimeo.com/12345'`
- THEN the backend MUST return HTTP 400 with error message (English): "Domain not allowed by administrator"
- AND the widget MUST NOT be created/updated

#### Scenario: Admin adds a custom PeerTube instance to allowlist
- GIVEN admin edits MyDash settings to add `"peertube.example.com"` to the allowed list
- WHEN a user submits a widget with `sourceType: 'peertube'` and `videoUrl: 'https://peertube.example.com/w/abc123'`
- THEN the domain check MUST succeed and the widget MUST be saved

#### Scenario: Widget render shows domain-blocked error
- GIVEN an existing widget placement with a video URL from a now-blocked domain
- WHEN the dashboard is rendered
- THEN the widget MUST display an error message (English): "Video domain is no longer allowed by administrator"
- AND the video MUST NOT be embedded
- AND the error MUST be localized to Dutch

#### Scenario: Nextcloud file type always allowed
- GIVEN `sourceType: 'nc-file'`
- WHEN the widget is created
- THEN domain allowlist checks MUST be skipped (only ACL matters)
- AND the widget MUST be stored without domain validation

### Requirement: REQ-VID-005 Parse and Validate Video URLs Server-Side

The backend MUST extract video IDs from user-provided URLs and validate format before storage.

#### Scenario: URL parsing endpoint is available
- GIVEN a user is editing a widget config in the frontend
- WHEN they type or paste a URL and click "Validate" or on blur
- THEN POST /api/widgets/video/parse MUST be called with `{"sourceType": "youtube", "videoUrl": "https://..."}`
- AND the endpoint MUST return `{"videoId": "ABC123", "canonicalUrl": "https://...", "isValid": true}`
- OR return `{"isValid": false, "error": "..."}` if parsing fails

#### Scenario: Invalid URL format is rejected early
- GIVEN a user submits `videoUrl: "not-a-url"`
- WHEN the parse endpoint processes it
- THEN it MUST return HTTP 400 with `{"isValid": false, "error": "Invalid URL format"}`

#### Scenario: Domain validation happens at parse time
- GIVEN a user submits a URL from a blocked domain
- WHEN POST /api/widgets/video/parse is called
- THEN it MUST return `{"isValid": false, "error": "Domain not allowed by administrator"}`
- AND the domain MUST be extracted and checked against the allowlist

#### Scenario: Canonical URL is cached in widget config
- GIVEN a parsed and validated YouTube URL
- WHEN the widget is created
- THEN the stored `videoUrl` in `widgetContent` MUST be the canonical embed form
- AND on render, the backend MUST NOT re-parse; it uses the cached canonical URL directly

#### Scenario: Time offsets are preserved for YouTube
- GIVEN a YouTube URL with time offset: `https://www.youtube.com/watch?v=ABC123&t=120s`
- WHEN parsed
- THEN the canonical URL MUST be `https://www.youtube.com/embed/ABC123?start=120`
- AND the render MUST jump to 120 seconds on play

### Requirement: REQ-VID-006 Check File Access Control for Nextcloud Files

When `sourceType` is `nc-file`, the system MUST verify the viewing user can read the file before allowing playback.

#### Scenario: File is readable by viewer
- GIVEN a widget with `sourceType: 'nc-file'` and `fileId: 12345`
- AND user "alice" owns the file and has read permission
- WHEN the dashboard renders for "alice"
- THEN the render MUST call `VideoFileService::getAccessibleUrl(12345, "alice")`
- AND it MUST return a valid URL like `/index.php/apps/files/api/v1/files/12345/content`
- AND the video MUST embed successfully

#### Scenario: File is not readable by viewer
- GIVEN a widget with `sourceType: 'nc-file'` and `fileId: 12345`
- AND user "bob" has no read permission on the file
- WHEN the dashboard renders for "bob"
- THEN `VideoFileService::getAccessibleUrl()` MUST throw `AccessDeniedException`
- AND the widget MUST display an error message: "Video not accessible"
- AND no download URL MUST be leaked to the frontend

#### Scenario: File MIME type is video
- GIVEN `fileId: 12345` points to a file `movie.mp4` with MIME type `video/mp4`
- WHEN the file service checks the file
- THEN it MUST accept the file and proceed with URL generation
- AND the following MIME types MUST be accepted: `video/mp4`, `video/webm`, `video/ogg`, `video/quicktime`, `video/x-msvideo`

#### Scenario: File MIME type is not video
- GIVEN `fileId: 12345` points to a file `document.pdf` with MIME type `application/pdf`
- WHEN the file service checks the file
- THEN it MUST reject the file and return an error
- AND the widget MUST display: "File is not a video"

#### Scenario: File is deleted
- GIVEN a widget references a deleted file via `fileId: 12345`
- WHEN the dashboard renders
- THEN `RootFolder::getById()` MUST throw or return null
- AND the widget MUST display: "Video file not found"

### Requirement: REQ-VID-007 Use iframe with CSP-Safe Sandbox Attributes

Hosted video platforms (YouTube, Vimeo, PeerTube) MUST be embedded via iframe with strict sandbox restrictions.

#### Scenario: YouTube iframe uses proper sandbox
- GIVEN a widget with `sourceType: 'youtube'` and canonical URL `https://www.youtube.com/embed/ABC123`
- WHEN the widget renders
- THEN the HTML MUST include:
  ```html
  <iframe
    src="https://www.youtube.com/embed/ABC123"
    sandbox="allow-scripts allow-same-origin"
    allowfullscreen
  ></iframe>
  ```
- AND `sandbox` MUST NOT include `allow-top-navigation` (prevents video from breaking out)
- AND `sandbox` MUST NOT include `allow-forms` (no form submission from iframe)

#### Scenario: Vimeo iframe sandbox
- GIVEN a widget with `sourceType: 'vimeo'` and URL `https://player.vimeo.com/video/12345`
- WHEN rendered
- THEN the iframe MUST have `sandbox="allow-scripts allow-same-origin" allowfullscreen`

#### Scenario: CSP compliance
- GIVEN the app's Content-Security-Policy header
- WHEN an iframe embeds an external URL
- THEN the iframe source domain MUST be in the CSP `frame-src` directive
- NOTE: YouTube, Vimeo, PeerTube are typically already in CSP; app MUST document any domains needing CSP updates

#### Scenario: iframes respect aspect ratio
- GIVEN an iframe with `aspectRatio: '4:3'`
- WHEN rendered
- THEN the iframe container MUST use CSS `padding-bottom` trick to maintain 4:3 ratio
- AND the iframe itself MUST have `width: 100%` and `height: 100%` to fill the container

### Requirement: REQ-VID-008 Respect Autoplay, Muting, and Loop Settings

The widget MUST apply user-selected playback options while respecting browser autoplay policies.

#### Scenario: Autoplay with muted enforces mute
- GIVEN a user sets `autoplay: true` and `muted: false`
- WHEN the widget saves
- THEN the backend MUST enforce `muted: true` (browsers block autoplay of unmuted video)
- AND the frontend MUST inform the user: "Autoplay requires muting"
- NOTE: This is a client-side UX nicety; server always enforces the invariant

#### Scenario: YouTube iframe autoplay
- GIVEN a widget with `autoplay: true`, `muted: true`, `sourceType: 'youtube'`
- WHEN the render generates the iframe URL
- THEN it MUST append `?autoplay=1&mute=1` to the embed URL: `https://www.youtube.com/embed/ABC123?autoplay=1&mute=1`

#### Scenario: HTML5 video autoplay
- GIVEN a widget with `sourceType: 'nc-file'`, `autoplay: true`, `muted: true`
- WHEN the video tag is rendered
- THEN it MUST include attributes: `autoplay muted` (both required for browser autoplay policy)

#### Scenario: Loop is applied per platform
- GIVEN a widget with `loop: true`
- WHEN rendered:
  - For HTML5 video: add `loop` attribute to `<video>` tag
  - For YouTube: append `&loop=1&playlist=ABC123` to iframe URL
  - For Vimeo/PeerTube: may not be supported; append if platform supports it
- THEN the video MUST repeat indefinitely

#### Scenario: Controls toggle
- GIVEN a widget with `controls: false`
- WHEN rendered:
  - For HTML5 video: omit `controls` attribute
  - For iframes: no standard way to hide controls; document this limitation
- THEN playback controls visibility MUST be honored for HTML5

### Requirement: REQ-VID-009 Enforce Aspect Ratio via CSS

The widget MUST apply the configured aspect ratio using modern CSS and fallback techniques.

#### Scenario: Aspect ratio options
- GIVEN a user selects `aspectRatio` from a dropdown
- WHEN rendering
- THEN the following options MUST be supported: `"16:9"`, `"4:3"`, `"1:1"`, `"9:16"`
- AND each MUST render correctly in both desktop and mobile viewports

#### Scenario: Aspect ratio CSS property (modern)
- GIVEN a widget with `aspectRatio: '16:9'`
- WHEN rendered in a modern browser (Chrome 88+, Firefox 89+, Safari 15+)
- THEN the container MUST use: `aspect-ratio: 16 / 9;`
- AND the iframe/video MUST stretch to fill: `width: 100%; height: 100%;`

#### Scenario: Aspect ratio padding fallback
- GIVEN a widget needs to support older browsers
- WHEN the container doesn't support `aspect-ratio` CSS
- THEN the backend SHOULD render a fallback with `padding-bottom: calc(9 / 16 * 100%)` technique
- AND a sentinel class MUST allow frontend to detect modern support and override padding-bottom

#### Scenario: Portrait video (9:16)
- GIVEN a user selects `aspectRatio: '9:16'` for vertical video
- WHEN rendered
- THEN the container height MUST be constrained relative to width (e.g., max-width: 360px for full viewport)
- AND the video MUST not distort

### Requirement: REQ-VID-010 Support No-Cookie YouTube Embedding

The system MUST support an optional admin setting to enable YouTube no-cookie embedding to reduce tracking.

#### Scenario: No-cookie setting default
- GIVEN `mydash.video_widget_use_nocookie_youtube` is not configured
- WHEN a widget with `sourceType: 'youtube'` is created
- THEN the system MUST use the standard `https://www.youtube.com/embed/ABC123`
- AND YouTube's tracking cookies MUST be enabled by default

#### Scenario: Admin enables no-cookie mode
- GIVEN admin sets `mydash.video_widget_use_nocookie_youtube = true`
- WHEN a widget with `sourceType: 'youtube'` is created
- THEN the system MUST rewrite the embed URL to: `https://www.youtube-nocookie.com/embed/ABC123`
- AND the no-cookie domain MUST be used for all subsequent renders

#### Scenario: Existing widgets respect no-cookie toggle
- GIVEN an existing widget with URL `https://www.youtube.com/embed/ABC123`
- WHEN the admin toggles `use_nocookie_youtube` from false to true
- THEN on next render, the URL MUST be dynamically rewritten to `youtube-nocookie.com` if applicable
- NOTE: Stored URL is `www.youtube.com`; render-time transformation happens via controller logic

#### Scenario: No-cookie does not affect Vimeo/PeerTube
- GIVEN widgets with `sourceType: 'vimeo'` or `sourceType: 'peertube'`
- WHEN the no-cookie setting is toggled
- THEN only YouTube URLs are affected
- AND Vimeo/PeerTube render URLs MUST NOT change

### Requirement: REQ-VID-011 Display Appropriate Empty and Error States

The widget MUST show user-friendly messages for missing config, access issues, and invalid domains.

#### Scenario: Empty state - no video configured
- GIVEN a widget placement with `sourceType: null` or missing `videoUrl`/`fileId`
- WHEN the dashboard renders
- THEN the widget MUST display a centered message: "No video URL configured"
- AND a help text: "Edit the widget to add a video"
- AND a clickable "Configure now" link (in edit mode)

#### Scenario: Invalid URL error
- GIVEN a user attempts to save a widget with `videoUrl: "not a valid URL"`
- WHEN the parse endpoint is called
- THEN it MUST return `{"isValid": false, "error": "Invalid URL format"}`
- AND the frontend form MUST show the error below the URL field
- AND the save button MUST be disabled

#### Scenario: Domain not allowed error
- GIVEN a user attempts to save a widget with a blocked domain
- WHEN the parse endpoint is called
- THEN it MUST return `{"isValid": false, "error": "Domain not allowed by administrator"}`
- AND the frontend MUST display this error
- AND the admin SHOULD be prompted to check MyDash settings

#### Scenario: File not found error
- GIVEN a widget with `sourceType: 'nc-file'` and the file has been deleted
- WHEN the dashboard renders
- THEN the widget MUST display: "Video file not found"
- AND in edit mode, a "Remove widget" button MUST be offered

#### Scenario: File not accessible error
- GIVEN a widget with `sourceType: 'nc-file'` and the user has lost read permission
- WHEN the dashboard renders
- THEN the widget MUST display: "Video not accessible"
- AND the widget MUST NOT show the file URL in the DOM

#### Scenario: Poster image fallback
- GIVEN a widget with `posterUrl` set to a custom image URL
- WHEN the widget renders before video loads (or on pause in some platforms)
- THEN the poster image MUST be displayed as the preview
- AND if the poster URL is broken, a generic video icon MUST be shown instead

#### Scenario: All error messages are localized
- GIVEN any error message displayed to the user
- WHEN the user's Nextcloud language is set to Dutch
- THEN error messages MUST be available in Dutch
- AND English MUST be the fallback
- AND i18n keys MUST be registered in `l10n/en.json` and `l10n/nl.json`

## Non-Functional Requirements

- **Performance**: Video URL parsing MUST complete in < 500ms even for external URLs (YouTube, Vimeo). Cached canonical URLs in widget config MUST NOT be re-parsed on every render.
- **Security**: iframe sandbox MUST prevent form submission, navigation, and plugin execution. File access checks MUST honor Nextcloud ACLs. No tracking pixels or third-party scripts MUST be injected by MyDash itself (YouTube/Vimeo may add their own per their ToS).
- **Compatibility**: Widget MUST work with all supported Nextcloud versions and all Dashboard API versions (v1, v2). HTML5 video MUST play in all modern browsers (Chrome, Firefox, Safari, Edge 88+).
- **Accessibility**: Videos MUST have poster images or title text. Controls MUST be keyboard accessible. Error messages MUST be clear and visible to screen readers.
- **Localization**: All UI text, error messages, and settings labels MUST support English and Dutch.

## Standards & References

- OpenSpec: `openspec/specs/widgets/spec.md` — widget placement and discovery
- Nextcloud Dashboard API: `OCP\Dashboard\IManager`, `OCP\Dashboard\IWidget`, `OCP\Dashboard\IAPIWidget`
- OWASP: iframe sandbox attribute guidelines (CSP-friendly, restrict privileges)
- WCAG 2.1 AA: video controls, poster images, keyboard navigation
- HTML Living Standard: `<video>` element, `<iframe>` sandbox attribute
- YouTube Embed API: `youtube.com/embed/{VIDEO_ID}`, `youtube-nocookie.com` domain
- Vimeo Embed API: `player.vimeo.com/video/{VIDEO_ID}`
- PeerTube Embed API: `/w/{UUID}` path and custom domain support
- i18n requirement: ADR-005 (English + Dutch minimum)
