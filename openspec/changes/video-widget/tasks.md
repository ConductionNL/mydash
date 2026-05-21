# Tasks — video-widget

## Backend Tasks

### URL Parsing & Validation

- [ ] **Task 1**: Create `lib/Service/VideoParsingService.php` with methods:
  - `parseYouTubeUrl(string $url): array` — extract video ID from `youtube.com/watch?v=`, `youtu.be/`, `youtube.com/watch?v=&t=` formats; return `{videoId, canonicalUrl, startTime}` (e.g., `youtube.com/embed/ABC123?start=120`)
  - `parseVimeoUrl(string $url): array` — extract video ID from `vimeo.com/{id}` or `player.vimeo.com/video/{id}`; return `{videoId, canonicalUrl}`
  - `parsePeerTubeUrl(string $url): array` — extract instance domain and path from `peertube.example.com/w/{UUID}`; validate against allowlist; return `{domain, canonicalUrl}`
  - `validateDomain(string $url): bool` — check embed URL's domain against `IAppConfig` allowlist; return true if allowed or if sourceType is 'nc-file'
  - All methods return `{isValid: bool, error?: string, ...}` on parse failure
  
- [ ] **Task 2**: Create `lib/Service/VideoFileService.php` with methods:
  - `getAccessibleUrl(int $fileId, string $userId): string` — load file via `RootFolder::getById()`, check `PERMISSION_READ`, validate MIME type is video, return streaming URL `/index.php/apps/files/api/v1/files/{fileId}/content`
  - `isVideoMimeType(string $mimeType): bool` — return true for `video/mp4`, `video/webm`, `video/ogg`, `video/quicktime`, `video/x-msvideo`
  - Throw `AccessDeniedException` if file not readable or MIME type invalid
  - Throw `NotFoundException` if file ID doesn't exist

- [ ] **Task 3**: Create `lib/Controller/VideoController.php` with route `POST /api/widgets/video/parse`:
  - Accept JSON body `{sourceType, videoUrl}`
  - Call `VideoParsingService::parse*()` based on sourceType
  - Call `validateDomain()` to check allowlist
  - Return `{isValid: bool, videoId?, canonicalUrl?, error?}` with HTTP 400 on invalid input
  - Localize error messages to `t('mydash', '...')` for English, Dutch to follow

### Admin Settings

- [ ] **Task 4**: Register admin settings in `lib/Migrations/Configuration/VideoWidgetDefaults.php` (or in an existing settings migration):
  - `mydash.video_widget_allowed_domains` — default JSON `["youtube.com","www.youtube.com","youtu.be","vimeo.com","player.vimeo.com"]`
  - `mydash.video_widget_use_nocookie_youtube` — default boolean `false`
  - Both via `IAppConfig::setValueString()` / `::setValueInt()` per ADR-001

### Render-Time Logic

- [ ] **Task 5**: Modify widget placement renderer to:
  - Check `styleConfig.content.sourceType` before rendering
  - For `sourceType: null` or missing `videoUrl`/`fileId` → set `content.error = "No video configured"`
  - For `sourceType: 'nc-file'` → call `VideoFileService::getAccessibleUrl()` and catch `AccessDeniedException` → set `content.error = "Video not accessible"` (or "Video file not found")
  - For `sourceType: 'nc-file'` → catch `NotFoundException` → set `content.error = "Video file not found"`
  - For hosted platforms → validate domain allowlist; if blocked → set `content.error = "Video domain is no longer allowed by administrator"`
  - For YouTube → if `use_nocookie_youtube` is true, rewrite URL from `youtube.com` to `youtube-nocookie.com` (render-time transformation only; stored URL remains canonical)
  - Pass populated `content` (including `error` field, `fileStreamingUrl` if nc-file) to renderer component
  - All error messages localized via `t('mydash', '...')`

## Frontend Tasks

### Renderer Component

- [ ] **Task 6**: Create `src/components/Widgets/Renderers/VideoWidget.vue` with props `content` + `placement`:
  - Root wrapper: flex container, width/height 100%, preserve aspect-ratio
  - **Empty state** (REQ-VID-011): If `!content.videoUrl && !content.fileId` or `content.sourceType === null` → render centered play-icon + `t('No video configured')` + "Edit widget to add" text
  - **Error state** (REQ-VID-011): If `content.error` is truthy → render error icon + `t(content.error)` centered in the cell, no video element
  - **Hosted platforms** (youtube/vimeo/peertube, REQ-VID-007):
    - Render `<iframe :src="content.videoUrl" sandbox="allow-scripts allow-same-origin" allowfullscreen></iframe>`
    - Wrapper: `aspect-ratio: {16/9 | 4/3 | 1/1 | 9/16}` based on `content.aspectRatio`
    - iframe: width 100%, height 100%
    - Append query params based on settings:
      - If `autoplay: true` → append `?autoplay=1&mute=1` (REQ-VID-008)
      - If `loop: true` → append `&loop=1&playlist={videoId}` for YouTube (REQ-VID-008, REQ-VID-010)
  - **Nextcloud Files** (REQ-VID-006, REQ-VID-008):
    - Render `<video :src="content.fileStreamingUrl" :controls="content.controls">` + `<source type="video/mp4">`
    - Apply `poster` if `content.posterUrl` is non-empty (REQ-VID-011)
    - Apply attributes: `:autoplay="content.autoplay"`, `:muted="content.muted"`, `:loop="content.loop"`
    - Wrapper: same aspect-ratio handling
  - Localized placeholder text via `$t('mydash', ...)`

- [ ] **Task 7**: Apply inline styles for aspect-ratio (REQ-VID-009):
  - Render `<style scoped>` block with CSS `aspect-ratio` property (Chrome 88+, Firefox 89+, Safari 15+)
  - Fallback for older browsers: `padding-bottom: calc({9/16*100}%)` on wrapper + `position: absolute` on iframe/video
  - Sentinel class `supports-aspect-ratio` detected via JS feature test; skip padding-bottom if present
  - Render helper: compute aspect-ratio value from `content.aspectRatio: '16:9' | '4:3' | '1:1' | '9:16'`

- [ ] **Task 8**: Apply poster image (REQ-VID-011):
  - For HTML5 `<video>` tag: bind `:poster="content.posterUrl"` if non-empty
  - If poster URL fails to load (`<img @error>`), show generic video icon instead
  - For iframes: no poster support (hosted platforms handle preview thumbs)

### Form Component

- [ ] **Task 9**: Create `src/components/Widgets/Forms/VideoForm.vue` matching `AddWidgetModal` sub-form contract:
  - Props: `editingWidget` (the placement being edited)
  - Emits: `update:content` on any input
  - Layout:
    1. **Source Type** radio/select: `'youtube' | 'vimeo' | 'peertube' | 'nc-file'`
    2. **Conditional input** (changes based on sourceType):
       - For hosted platforms: text input for video URL + "Validate" button (calls parse endpoint)
       - For nc-file: file picker (Nextcloud file browser / file selector from `@conduction/nextcloud-vue`)
    3. **Live preview** thumbnail (for valid hosted URLs)
    4. **Playback options**:
       - Checkbox: Autoplay (`autoplay` bool)
       - Checkbox: Mute (`muted` bool, disabled when autoplay is true, auto-enabled if user tries to set both)
       - Checkbox: Loop (`loop` bool)
       - Checkbox: Show Controls (`controls` bool)
    5. **Aspect Ratio** select: `'16:9' | '4:3' | '1:1' | '9:16'` (default `'16:9'`)
    6. **Optional Poster Image** URL input or file upload (for HTML5 videos)

- [ ] **Task 10**: Form URL validation flow:
  - On URL input blur or "Validate" button click → `POST /api/widgets/video/parse` with `{sourceType, videoUrl}`
  - On success: show green checkmark + display canonical URL returned
  - On failure: show error message inline under the URL input, disable the save button
  - Pre-fill from `editingWidget.content` on mount

- [ ] **Task 11**: Form file picker for nc-file:
  - Use `@conduction/nextcloud-vue` file selector or integrate with existing file-picker component
  - On file selection: populate `form.fileId` and display file name
  - Validate MIME type if possible (show warning if not video); backend will validate on save

- [ ] **Task 12**: Enforce autoplay + mute invariant (REQ-VID-008):
  - If user checks `autoplay` → auto-check `muted` and show hint "Autoplay requires muting"
  - If user has `autoplay` checked and unchecks `muted` → show warning and re-check `muted`
  - Server also enforces this invariant on save

- [ ] **Task 13**: Form `validate()` method:
  - Return `[]` (empty array) when form is valid
  - Return array of localized error strings when invalid:
    - "Video source type is required" if sourceType is null
    - "Video URL is required" if sourceType is hosted and videoUrl is empty
    - "File is required" if sourceType is nc-file and fileId is empty
    - "Invalid URL format" if URL parsing fails
    - "Domain not allowed by administrator" if domain is not in allowlist
  - Modal uses array length to enable/disable the save button

### Widget Registration

- [ ] **Task 14**: Register `video` in `src/constants/widgetRegistry.js`:
  - Type: `'video'`
  - Component: `VideoWidget` (renderer)
  - Form: `VideoForm` (sub-form)
  - Label: `t('mydash', 'Video')`
  - Icon: play button or film-reel SVG (from `@mdi/svg`)
  - Defaults: `{sourceType: null, videoUrl: '', fileId: null, autoplay: false, muted: true, loop: false, controls: true, aspectRatio: '16:9', posterUrl: '', error: null}`

## Internationalization (i18n)

- [ ] **Task 15**: Add translation keys to `l10n/en.json`:
  - UI labels: `Video`, `Source Type`, `YouTube`, `Vimeo`, `PeerTube`, `Nextcloud File`, `Video URL`, `File`, `Autoplay`, `Mute`, `Loop`, `Show Controls`, `Aspect Ratio`, `Poster Image (optional)`, `No video configured`, `Edit widget to add video`
  - Validation errors: `Video source type is required`, `Video URL is required`, `File is required`, `Invalid URL format`, `Domain not allowed by administrator`
  - File errors: `Video file not found`, `Video not accessible`, `File is not a video`
  - Domain errors: `Video domain is no longer allowed by administrator`
  - Settings: `Allowed domains (JSON array)`, `Use YouTube no-cookie embed`, `Enable no-cookie embedding for YouTube`

- [ ] **Task 16**: Add Dutch equivalents to `l10n/nl.json`:
  - `Video` → `Video`
  - `Source Type` → `Videobron`
  - `YouTube` → `YouTube`
  - `Vimeo` → `Vimeo`
  - `PeerTube` → `PeerTube`
  - `Nextcloud File` → `Nextcloud-bestand`
  - `Video URL` → `Video-URL`
  - `File` → `Bestand`
  - `Autoplay` → `Automatisch afspelen`
  - `Mute` → `Dempen`
  - `Loop` → `Herhalen`
  - `Show Controls` → `Besturingselementen tonen`
  - `Aspect Ratio` → `Beeldverhouding`
  - `Poster Image (optional)` → `Voorafbeelding (optioneel)`
  - `No video configured` → `Geen video geconfigureerd`
  - `Edit widget to add video` → `Bewerk de widget om een video toe te voegen`
  - `Video source type is required` → `Videobron is verplicht`
  - `Video URL is required` → `Video-URL is verplicht`
  - `File is required` → `Bestand is verplicht`
  - `Invalid URL format` → `Ongeldig URL-formaat`
  - `Domain not allowed by administrator` → `Domein niet toegestaan door beheerder`
  - `Video file not found` → `Videobestand niet gevonden`
  - `Video not accessible` → `Video niet toegankelijk`
  - `File is not a video` → `Bestand is geen video`
  - `Video domain is no longer allowed by administrator` → `Videodomein is niet langer toegestaan door beheerder`

## Testing

- [ ] **Task 17**: Vitest renderer tests (`src/components/Widgets/Renderers/VideoWidget.spec.js`):
  - Empty state: renders placeholder when `sourceType` is null
  - Error state: renders error message when `content.error` is truthy
  - YouTube iframe: renders with correct `sandbox` attribute, appends `autoplay=1&mute=1` when applicable, appends `loop=1&playlist={ID}` when loop is true
  - Vimeo iframe: renders with sandbox and no query params (Vimeo autoplay not supported; document limitation)
  - PeerTube iframe: renders with sandbox, preserves query string
  - Nextcloud File: renders `<video>` tag with correct `src`, applies autoplay/muted/loop/controls attributes, applies poster image
  - Aspect ratio: CSS renders `aspect-ratio: 16 / 9` (or 4:3, 1:1, 9:16); fallback padding-bottom computed correctly
  - Poster image fallback: if poster URL is broken, show video icon instead

- [ ] **Task 18**: Vitest form tests (`src/components/Widgets/Forms/VideoForm.spec.js`):
  - Source type selection: radio/select toggles conditional inputs
  - URL input: validates on blur, calls parse endpoint, shows errors
  - File picker: populates fileId on selection
  - Autoplay + mute: auto-mutes when autoplay is checked, shows warning
  - Aspect ratio: select updates form state
  - `validate()` method: returns correct error array based on form state
  - Pre-fill: on mount, populates all fields from `editingWidget.content`
  - Emits: `update:content` on every input change

- [ ] **Task 19**: Playwright integration tests (`e2e/video-widget.spec.js`):
  - **YouTube**: Add widget → enter valid YouTube URL → validate → save → reload → video plays with correct aspect ratio; edit widget → change aspect ratio → save → reload → new ratio applied
  - **Vimeo**: Add widget → enter valid Vimeo URL → save → video embeds; click play → Vimeo player opens
  - **Nextcloud File**: Add widget → select MP4 file from Nextcloud → save → video plays with native controls; file deleted → reload → error state shown
  - **Autoplay enforcement**: Set autoplay=true, muted=false → frontend shows warning, backend coerces to muted=true on save → reload → video plays muted
  - **Domain blocking**: Admin sets allowed_domains to only YouTube → try to add Vimeo → validation error → cannot save
  - **No-cookie toggle**: Admin enables no-cookie YouTube → YouTube videos rewritten to youtube-nocookie.com on render
  - **Poster image**: Add Nextcloud File video → upload poster image → reload → poster visible before play
  - **Error states**: Test file-not-found, file-not-accessible, domain-blocked errors; all display user-friendly messages

## Quality & Documentation

- [ ] **Task 20**: Code quality:
  - ESLint: all `.js`/`.vue` files pass linting (no console, no unused vars, proper indentation)
  - Stylelint: inline `<style>` blocks in `.vue` files pass linting
  - SPDX license headers: add `@license AGPL-3.0-or-later` + `@copyright 2025 Nextcloud` to every new file (per ADR-005/ADR-014)
  - PHP: `psalm --level=2` on `lib/Service/*`, `lib/Controller/*` (no type errors)
  - Vue: no TypeScript errors (if using TS); otherwise no unused props or emits
  - No new composer dependencies beyond existing Nextcloud APIs
  - No new npm dependencies (uses existing `@mdi/svg` for icons, existing `@conduction/nextcloud-vue` for file picker)

- [ ] **Task 21**: Documentation:
  - Update `CHANGELOG.md` with entry: "Add video widget for embedding YouTube, Vimeo, PeerTube, and Nextcloud Files on dashboards"
  - Add user guide section: "Video Widget" with screenshots of:
    - Adding a YouTube video (URL input + validation)
    - Adding a Nextcloud File (file picker)
    - Aspect ratio options (16:9, 4:3, 1:1, 9:16)
    - Autoplay + mute toggle
    - Admin settings: allowed domains, no-cookie YouTube
  - Add security/architecture note: iframe sandbox attributes, domain allowlist, file ACL enforcement

- [ ] **Task 22**: Admin documentation:
  - Document settings page: how to add/remove allowed domains, how to enable no-cookie YouTube embedding
  - Security best practices: "Why domains are allowlisted", "What happens if a domain is removed"
  - MIME type list: accepted video formats

## Verification

- [ ] **Task 23**: `openspec validate` exits clean with no errors
- [ ] **Task 24**: Widget appears in the widget type picker in AddWidgetModal
- [ ] **Task 25**: All four source types work end-to-end: YouTube, Vimeo, PeerTube, Nextcloud Files
- [ ] **Task 26**: Error states degrade gracefully without breaking the dashboard grid or affecting other widgets
- [ ] **Task 27**: ACL checks prevent unauthorized access to private Nextcloud Files
- [ ] **Task 28**: Domain allowlist blocks disallowed sources with clear error messaging
