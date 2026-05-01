# Tasks — video-widget

## 1. Video URL parsing and validation service

- [ ] 1.1 Create `lib/Service/VideoParsingService.php` with static methods for each platform
- [ ] 1.2 Implement `parseYouTubeUrl(string $url): string` — extract video ID, return `https://www.youtube.com/embed/{ID}`, preserve time offset via `?start=N` query param
- [ ] 1.3 Implement `parseVimeoUrl(string $url): string` — extract video ID, return `https://player.vimeo.com/video/{ID}`
- [ ] 1.4 Implement `parsePeerTubeUrl(string $url): string` — extract domain and UUID/path, return embed-compatible URL
- [ ] 1.5 Implement `validateDomain(string $url, string $sourceType): bool` — extract hostname from URL, check against allowlist from `IAppConfig`, throw `DomainNotAllowedException` if blocked
- [ ] 1.6 Implement `normalizeUrl(string $url, string $sourceType): string` — dispatcher to the platform-specific methods; handles URL format variations (trailing slashes, query params, etc.)

## 2. File access service for Nextcloud videos

- [ ] 2.1 Create `lib/Service/VideoFileService.php`
- [ ] 2.2 Implement `getAccessibleUrl(int $fileId, string $userId): string` — load file via `RootFolder::getById()`, check MIME type is video, verify `PERMISSION_READ`, return streaming URL
- [ ] 2.3 Implement `isVideoMimeType(string $mimeType): bool` — return true for `video/mp4`, `video/webm`, `video/ogg`, `video/quicktime`, `video/x-msvideo`
- [ ] 2.4 Throw `AccessDeniedException` if user lacks read permission; throw `InvalidMimeTypeException` if file is not video; throw `FileNotFoundException` if file does not exist
- [ ] 2.5 Use `IRootFolder` from Nextcloud's container to safely resolve files

## 3. Video widget provider (Dashboard API registration)

- [ ] 3.1 Create `lib/Dashboard/VideoWidgetProvider.php` implementing `OCP\Dashboard\IWidget`
- [ ] 3.2 Implement `getId(): string` returning `"mydash_video"`
- [ ] 3.3 Implement `getTitle(): string` returning localized "Video" (English/Dutch via `ITranslationManager`)
- [ ] 3.4 Implement `getOrder(): int` returning a stable sort order (e.g., 40)
- [ ] 3.5 Implement `getIconClass(): string` returning a Material Design icon class (e.g., `"icon-video"`)
- [ ] 3.6 Implement `getUrl(): ?string` returning null (widget content is loaded via placements, not a separate URL)
- [ ] 3.7 Register the provider in `lib/AppManager.php` via the Nextcloud `IManager::registerWidget()` API in a boot hook

## 4. Video widget controller

- [ ] 4.1 Create `lib/Controller/VideoWidgetController.php`
- [ ] 4.2 Implement `parse(string $sourceType, string $videoUrl): JSONResponse` — POST `/api/widgets/video/parse`
  - Input: `{"sourceType": "youtube", "videoUrl": "..."}`
  - Call `VideoParsingService::validateDomain()` and `normalizeUrl()`
  - Return: `{"videoId": "ABC123", "canonicalUrl": "...", "isValid": true}`
  - On error: `{"isValid": false, "error": "Domain not allowed by administrator"}` (HTTP 400)
- [ ] 4.3 Implement `getFileUrl(int $fileId): JSONResponse` — GET `/api/widgets/video/file/{fileId}`
  - Call `VideoFileService::getAccessibleUrl($fileId, getCurrentUserId())`
  - Return: `{"fileId": 12345, "streamingUrl": "...", "mimeType": "video/mp4", "isAccessible": true}`
  - On error (access denied): return HTTP 403 without exposing the file URL
  - On error (not video): return HTTP 400 with error message
- [ ] 4.4 Add attribute `#[NoCSRFRequired]` to both endpoints (parse endpoint needs CSRF exemption for frontend validation calls)
- [ ] 4.5 Add attribute `#[NoAdminRequired]` (both endpoints need to work for logged-in users, not admin-only)
- [ ] 4.6 Inject `VideoParsingService`, `VideoFileService`, `IAppConfig`, `ILogger`, `IUserSession` into controller constructor

## 5. Admin settings for allowed domains and nocookie

- [ ] 5.1 Create or extend app settings UI to expose `mydash.video_widget_allowed_domains` (JSON array editor)
- [ ] 5.2 Create or extend app settings UI to expose `mydash.video_widget_use_nocookie_youtube` (boolean toggle)
- [ ] 5.3 Set default for `mydash.video_widget_allowed_domains` to:
  ```json
  ["youtube.com", "www.youtube.com", "youtu.be", "vimeo.com", "player.vimeo.com"]
  ```
- [ ] 5.4 Ensure `IAppConfig` can read both settings; fallback to defaults if not set
- [ ] 5.5 Document the settings in the admin panel help text (English and Dutch)

## 6. Frontend VideoWidget.vue component

- [ ] 6.1 Create `src/components/widgets/VideoWidget.vue`
- [ ] 6.2 Props: `widgetContent` (object), `placementId` (number)
- [ ] 6.3 Implement empty state: display "No video URL configured" if `sourceType` is null or missing `videoUrl`/`fileId`
- [ ] 6.4 Implement iframe rendering for `sourceType: 'youtube'`, `'vimeo'`, `'peertube'`:
  - Build iframe URL with query params for autoplay, mute, loop, start (time offset)
  - Apply sandbox attribute: `sandbox="allow-scripts allow-same-origin" allowfullscreen`
  - Apply aspect ratio via CSS (modern `aspect-ratio` property + fallback `padding-bottom` trick)
- [ ] 6.5 Implement HTML5 video rendering for `sourceType: 'nc-file'`:
  - Call API endpoint to get streaming URL: `GET /api/widgets/video/file/{fileId}`
  - Render `<video>` tag with `src`, `controls`, `autoplay`, `muted`, `loop` attributes as configured
  - Add `poster` attribute if `posterUrl` is set
  - Handle `canplay`, `error` events to show fallback error state
- [ ] 6.6 Implement error states:
  - Invalid URL: "Invalid video URL or domain not allowed."
  - File not found: "Video file not found"
  - File not accessible: "Video not accessible"
  - Missing MIME type: "File is not a video"
  - Render error: generic fallback
- [ ] 6.7 Computed property `embedUrl()` — applies nocookie transform if admin setting is enabled (YouTube only)
- [ ] 6.8 Computed property `videoStyles()` — generate CSS for aspect ratio enforcement
- [ ] 6.9 Add i18n keys for all error messages in both English and Dutch
- [ ] 6.10 Test responsive behavior on desktop (1920x1080) and mobile (375x667) viewports

## 7. Routes and API endpoints

- [ ] 7.1 Register `POST /api/widgets/video/parse` in `appinfo/routes.php` pointing to `VideoWidgetController::parse()`
- [ ] 7.2 Register `GET /api/widgets/video/file/{fileId}` in `appinfo/routes.php` pointing to `VideoWidgetController::getFileUrl()`
- [ ] 7.3 Both routes marked as `NoCSRFRequired`, `NoAdminRequired`
- [ ] 7.4 Ensure routes are scoped to `/apps/mydash/` prefix for consistency

## 8. Internationalization (i18n)

- [ ] 8.1 Create or extend `l10n/en.json` with keys:
  - `"No video URL configured"`
  - `"Invalid URL format"`
  - `"Invalid video URL or domain not allowed."`
  - `"Domain not allowed by administrator"`
  - `"Video not found"`
  - `"Video file not found"`
  - `"Video not accessible"`
  - `"File is not a video"`
  - `"Autoplay requires muting"`
  - `"Configure now"` (link text in empty state)
  - `"Video widget allowed domains"` (setting label)
  - `"Use YouTube no-cookie embedding"` (setting label)
- [ ] 8.2 Translate all keys to Dutch (`l10n/nl.json`)
- [ ] 8.3 Ensure all error responses from controller use i18n keys via `$this->l10n->t()`
- [ ] 8.4 Verify VideoWidget.vue imports and uses `$t()` for all user-facing text

## 9. Unit tests (PHPUnit)

- [ ] 9.1 `VideoParsingServiceTest`:
  - `testParseYouTubeUrl` — multiple URL formats → same video ID
  - `testParseYouTubeUrlWithTimeOffset` — preserve `t=30s` → `?start=30`
  - `testParseVimeoUrl` — extract video ID correctly
  - `testParsePeerTubeUrl` — extract domain + UUID
  - `testValidateDomainAllowed` — allowed domain passes
  - `testValidateDomainBlocked` — blocked domain throws `DomainNotAllowedException`
  - `testValidateDomainCustomAllowlist` — admin-configured domains work
  - `testNormalizeUrl` — dispatcher routes to correct platform parser
- [ ] 9.2 `VideoFileServiceTest`:
  - `testGetAccessibleUrlSuccess` — user can read file → return streaming URL
  - `testGetAccessibleUrlAccessDenied` — user lacks permission → throw `AccessDeniedException`
  - `testGetAccessibleUrlNotFound` — file deleted → throw `FileNotFoundException`
  - `testGetAccessibleUrlNotVideo` — MIME type is PDF → throw `InvalidMimeTypeException`
  - `testIsVideoMimeType` — video/* types accepted, others rejected
- [ ] 9.3 `VideoWidgetControllerTest`:
  - `testParseYouTubeSuccess` — POST `/api/widgets/video/parse` with YouTube URL → HTTP 200 with canonical URL
  - `testParseDomainBlocked` — blocked domain → HTTP 400 with error message
  - `testGetFileUrlSuccess` — user can read file → HTTP 200 with streaming URL
  - `testGetFileUrlAccessDenied` — user lacks permission → HTTP 403
  - `testGetFileUrlNotVideo` — PDF file → HTTP 400

## 10. Frontend component tests (Vitest / Vue Test Utils)

- [ ] 10.1 `VideoWidget.spec.js`:
  - `testEmptyState` — no videoUrl → renders "No video URL configured"
  - `testYouTubeRender` — sourceType='youtube' → iframe with sandbox, aspect ratio CSS
  - `testVimeoRender` — sourceType='vimeo' → iframe with correct URL
  - `testNcFileRender` — sourceType='nc-file' → video tag, calls file API
  - `testAspectRatioCss` — CSS `aspect-ratio` property applied correctly
  - `testErrorState` — API error → displays error message
  - `testAutplayMute` — autoplay=true enforces muted=true in iframe params
  - `testPosterImage` — posterUrl set → poster attribute on video tag

## 11. End-to-end Playwright tests

- [ ] 11.1 Create `tests/e2e/video-widget.spec.js`
- [ ] 11.2 Setup: create dashboard, add video widget
- [ ] 11.3 `testAddYouTubeWidget` — admin (alice) adds YouTube video, sees it render on dashboard
- [ ] 11.4 `testValidationRejectsBlockedDomain` — user (bob) attempts Vimeo URL when Vimeo is blocked → form error
- [ ] 11.5 `testAdminUnblocksVimeo` — admin adds Vimeo to allowlist, bob's subsequent save succeeds
- [ ] 11.6 `testNcFileUploadAndEmbed` — user uploads video.mp4 to Files, adds via widget, plays successfully
- [ ] 11.7 `testNcFileAccessDenied` — alice uploads private video, shares only with herself; bob views shared dashboard, sees "Video not accessible"
- [ ] 11.8 `testNoLookupYouTubeToggle` — admin enables no-cookie YouTube, existing YouTube widget URL changes on render
- [ ] 11.9 `testResponsiveAspectRatio` — widget maintains 16:9 ratio on desktop and mobile

## 12. Quality gates

- [ ] 12.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 12.2 ESLint + Stylelint on `src/components/widgets/VideoWidget.vue` — no warnings
- [ ] 12.3 SPDX-License-Identifier + SPDX-FileCopyrightText inside docblock of every new PHP file (not line comments)
- [ ] 12.4 All new i18n keys have both English and Dutch translations
- [ ] 12.5 Update OpenAPI spec / Postman collection to reflect new `/api/widgets/video/*` endpoints
- [ ] 12.6 Run all `hydra-gates` locally before opening PR
- [ ] 12.7 Verify no hardcoded colors — use Nextcloud CSS variables and `nldesign` theme support

## 13. Documentation

- [ ] 13.1 Update `appinfo/info.xml` to list `video-widget` as a provided capability
- [ ] 13.2 Add inline comments in `VideoParsingService`, `VideoFileService`, `VideoWidgetController` explaining each platform's URL format and edge cases
- [ ] 13.3 Document admin settings in a dedicated section (English + Dutch) with examples of custom allowlists

## 14. Migration / Backward compatibility

- [ ] 14.1 No database schema changes required (widget config stored in existing `widgetContent` JSON)
- [ ] 14.2 Zero-impact deployment: existing dashboards and widgets unaffected; video widget is opt-in
- [ ] 14.3 No version constraints on Nextcloud (supported versions already have Dashboard API)
