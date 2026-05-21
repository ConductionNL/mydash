# Design — video-widget

## Context

MyDash dashboards store widget placements in `oc_mydash_widget_placements` with a `styleConfig` JSON column that holds per-widget-type configuration. Built-in widget types (text-display, image, etc.) use this column with a discriminated shape `{type, content}` — no external widget callback, no separate tables. The video widget follows this same pattern.

Video embedding is security-sensitive: hosted platforms (YouTube, Vimeo, PeerTube) are embedded via iframes and their security properties depend on correct sandbox attributes and CSP headers. Internal Nextcloud files must be served only to users with read permission. Domain allowlisting is the primary control to prevent arbitrary iframe injection.

This change introduces a video widget type with four source branches:
1. **YouTube** → canonical embed URL from `youtube.com/embed/{ID}` (or `youtube-nocookie.com` if enabled)
2. **Vimeo** → canonical embed URL from `player.vimeo.com/video/{ID}`
3. **PeerTube** → instance-supplied embed URL (with domain validation)
4. **Nextcloud Files** → HTML5 `<video>` tag with ACL-checked streaming URL

## Goals / Non-Goals

**Goals:**

- Ship a `video` widget type that safely embeds hosted and internal video sources on dashboards.
- Support YouTube, Vimeo, PeerTube, and Nextcloud Files as four distinct source types.
- Normalize user-provided URLs to canonical embed forms server-side and cache them to avoid re-parsing.
- Enforce an admin-controlled domain allowlist to prevent embedding from untrusted sources.
- Apply correct iframe sandbox attributes (allow-scripts, allow-same-origin, no allow-top-navigation, no allow-forms) per OWASP guidelines.
- Verify file ACL (via `PERMISSION_READ`) for Nextcloud Files sources before generating playback URLs.
- Apply user-selected playback options (autoplay, muted, loop, controls, aspect-ratio) respecting browser autoplay policies.
- Support optional YouTube no-cookie embedding for privacy-conscious deployments.
- Localize all UI text, error messages, and settings to English and Dutch.
- Default to theme-aware styling (aspect-ratio CSS, responsive containers) so the widget integrates visually with surrounding dashboards.

**Non-Goals:**

- Livestream support (YouTube Live, Twitch, etc.) — can be a follow-up.
- Transcoding or re-hosting of internal files — Nextcloud Files are served as-is via standard streaming endpoints.
- Closed-caption UI controls — hosted platforms expose captions natively; internal files inherit browser support.
- Player API control (seek-to-time, skip, etc.) via JavaScript — would require vendor-specific integrations.
- Analytics or event tracking — third-party integrations can instrument videos independently.
- Fallback poster image generation from video frames — if no custom poster is supplied, browsers show a default.

## Decisions

### D1: Normalize URLs server-side and cache in styleConfig

**Decision**: `VideoParsingService` extracts video IDs from user-provided URLs at save time, generates the canonical embed URL, validates the domain, and stores the canonical form in `styleConfig.content.videoUrl`. On render, the cached URL is used directly — no re-parsing.

**Rationale**:
- Parse failures happen once at save time with clear error messaging to the user.
- The render path is fast — no network calls, no regex parsing.
- URLs can be updated if parsing rules change without users having to re-edit placements (admin revalidates on next render).
- Caching allows future optimizations (e.g., prefetching via Link headers).

### D2: Use HTML5 `<video>` for Nextcloud Files, `<iframe>` for hosted platforms

**Decision**: 
- **Hosted platforms** (YouTube, Vimeo, PeerTube) → `<iframe sandbox="allow-scripts allow-same-origin" allowfullscreen>`
- **Nextcloud Files** → `<video controls>` with `src` from an ACL-checked streaming endpoint

**Rationale**:
- Hosted platforms require iframe for their player UI and API.
- iframes enforce the sandbox boundary — malicious platform JavaScript cannot escape to the parent.
- Nextcloud Files use HTML5 native player — simpler, no iframe overhead, respects native browser capabilities (fullscreen, seek, volume).
- The two rendering paths are discriminated by `sourceType` in the widget form + renderer.

### D3: Domain allowlist controls hosted platform sources

**Decision**: `VideoParsingService` checks the embed URL's domain against `IAppConfig.getValueString('mydash', 'video_widget_allowed_domains')`. Default allowlist is `["youtube.com", "www.youtube.com", "youtu.be", "vimeo.com", "player.vimeo.com"]`. Admin can override via MyDash settings. PeerTube URLs require explicit domain addition. Nextcloud Files skip the allowlist check (only ACL matters).

**Rationale**:
- Default list covers the most common platforms without admin intervention.
- Admin override is essential for custom PeerTube instances or future hosted platforms.
- Fail-safe default: empty list blocks all hosted content.
- Nextcloud Files are trusted internal storage — allowlist doesn't apply.

### D4: File ACL checked at render time, not save time

**Decision**: When saving a widget with `sourceType: 'nc-file'` and `fileId: 12345`, the backend validates the file exists and the file's MIME type is video (REQ-VID-006 scenario: file MIME type checks). The ACL check (`PERMISSION_READ`) happens at render time via `VideoFileService::getAccessibleUrl()` — per-user, per-render.

**Rationale**:
- File permissions can change between save and render.
- ACL checks must be per-user (different users may have different permissions).
- Render-time checks prevent accidentally caching a URL that's later inaccessible.
- File deletion (or MIME-type change) surfaces immediately as an error on next dashboard load.

### D5: iframe sandbox attributes prevent escape and form submission

**Decision**: All hosted iframes use `sandbox="allow-scripts allow-same-origin" allowfullscreen` (no `allow-top-navigation`, no `allow-forms`, no `allow-popups`).

**Rationale**:
- `allow-scripts` is necessary for the player UI and controls.
- `allow-same-origin` lets cross-origin iframes interact with their own domain (required for YouTube/Vimeo full-screen).
- `allowfullscreen` enables the browser fullscreen API (not in the sandbox list but needed for embedded players).
- Omitting `allow-top-navigation` prevents the iframe from breaking out and navigating the top window (OWASP guideline).
- Omitting `allow-forms` prevents the iframe from submitting forms (defense-in-depth).
- Omitting `allow-popups` prevents the iframe from opening new windows.

### D6: Autoplay + unmuted is browser-forbidden; enforce mute server-side

**Decision**: If a user sets `autoplay: true` and `muted: false`, the backend coerces `muted: true` before storing. The frontend displays a hint: "Autoplay requires muting". The invariant is enforced on save, not just on render.

**Rationale**:
- Modern browsers block autoplay of unmuted video (chromium since v66, Firefox since v66).
- Server-side enforcement prevents the widget from "failing silently" on render.
- Client-side hint helps users understand why their setting was changed.
- Invariant is captured in tasks + tests to prevent regressions.

### D7: YouTube no-cookie via optional setting, not per-widget toggle

**Decision**: Admin setting `mydash.video_widget_use_nocookie_youtube` controls YouTube cookie policy globally. When true, cached YouTube URLs are rewritten at render time from `youtube.com` to `youtube-nocookie.com`. Existing placements don't change; the rewrite happens per-render.

**Rationale**:
- YouTube no-cookie is an organizational policy, not a per-placement choice.
- Render-time rewrite means admins can toggle the policy without editing placements.
- Stored URLs remain canonical (`youtube.com`) for future flexibility.
- No-cookie has no effect on Vimeo/PeerTube or Nextcloud Files.

### D8: Aspect-ratio via modern CSS property with fallback

**Decision**: Render with `aspect-ratio: 16 / 9;` (or 4:3, 1:1, 9:16 per user config) for modern browsers. Fallback for older browsers uses `padding-bottom` trick with JavaScript detection.

**Rationale**:
- `aspect-ratio` CSS property is supported in all modern browsers (Chrome 88+, Firefox 89+, Safari 15+).
- Fallback ensures older Nextcloud deployments still render correctly (e.g., older Safari, IE11 edge cases).
- Sentinel class allows frontend to detect modern support and skip fallback styles.

### D9: Error field in content for runtime issues

**Decision**: `styleConfig.content` includes an optional `error` field set by the parent (not the widget itself). When `error` is truthy, the widget renders the error placeholder instead of the video.

**Rationale**:
- Errors happen at render time (domain blocked, file deleted, permission lost).
- Parent controller sets `error` before passing content to the renderer.
- Widget doesn't need to re-fetch or validate; it just reads the flag.
- Allows future bulk-error-reporting (e.g., "3 videos on this dashboard are not accessible").

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| iframe sandbox escapes — future browser vulnerabilities | Avoid `allow-top-navigation` and `allow-forms`. Pin sandbox attributes. Test sandbox behavior in security-focused task. |
| Domain allowlist bypass via DNS rebinding | No mitigation in scope; DNS rebinding is a platform-level concern (ideally mitigated by network infra / CSP frame-ancestors). Document in risk register. |
| MIME type spoofing (user uploads video.mp4 with wrong MIME type) | Validate MIME type server-side at save + render. Reject if not in `['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo']`. Document accepted types in UI. |
| File permission escalation (share a dashboard with a video the user shouldn't see) | ACL is checked per-render per-user. Sharing a dashboard shares the placement, not the file. Viewing user's ACL is enforced. No escalation risk. |
| PeerTube instance downtime → broken widgets | Error state shows "Video domain not accessible" or similar. No persistence issue. |
| Aspect-ratio CSS property not supported in older browsers | Fallback padding-bottom technique. Sentinel class prevents double-applied constraints. |
| Bundle size: no new deps, but +~5KB for parsing regex + translations | Acceptable — inline in main bundle, lazy-load not applicable to widget registry. |
| YouTube/Vimeo API changes (embed URL structure) | Parsing regex is versioned per ADR-032. Updates ship in maintenance releases. Old cached URLs may break → error state handled gracefully. |

## Migration

No data migration. The `styleConfig` column already exists; existing placements continue to work unchanged. New `video`-type placements simply use a new shape inside that JSON column.

## Open Questions

- Should we support m3u8 (HLS) or DASH manifest URLs for internal files? Out of scope; Nextcloud's streaming endpoint handles the protocol negotiation.
- Should we log video plays for analytics? Out of scope; can be a follow-up integrating with Nextcloud activity/notifications.
- Should we support multiple videos in one widget (carousel)? Out of scope; a dedicated carousel widget could be built on top of this.
