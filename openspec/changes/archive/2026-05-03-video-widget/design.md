# Design — Video Widget

## Context

The video widget allows dashboard editors to embed video content directly on a MyDash dashboard from four source types: two major hosted platforms, self-hosted open-source video instances, and internal file storage. The widget is a MyDash-native invention — it has no sibling dependency on existing background jobs or external data pipelines.

Security is the dominant concern for embedded video. Hosted-platform embeds use a sandboxed iframe with a minimal capability set; the embed URL is extracted and canonicalised server-side at save time so the frontend never re-parses raw user-supplied URLs at render time. An admin-controlled domain allow-list governs which hosted origins may be embedded; an empty list means "allow nothing" rather than "allow all", so a freshly cleared setting fails safe.

Internal file storage video is handled differently: a native HTML5 video element is used instead of an iframe, and the file is served via a streaming endpoint that enforces viewer ACL before returning data. This avoids the cross-origin complexity of iframes for content that lives within the same origin.

## Goals / Non-Goals

**Goals:**
- Support four source types behind a single widget: two hosted platforms, self-hosted instances, and internal file storage.
- Extract and canonicalise embed URLs server-side at save time; the frontend reads the stored canonical URL directly.
- Enforce an admin domain allow-list for all hosted-platform sources; default to a known-safe set.
- Use a sandboxed iframe for hosted platforms and a native video element for internal files.
- Default to privacy-preserving embed mode for applicable hosted platforms.

**Non-Goals:**
- Transcoding, re-encoding, or processing of video files is not in scope.
- Playlist or chapter support is not provided in this iteration.
- Analytics or view-tracking within the widget is out of scope.

## Decisions

### D1: Source type enum

**Decision:** The `sourceType` placement config field accepts exactly `youtube | vimeo | peertube | nc-file`. The backend service dispatches URL parsing and embed URL construction based on this enum. Adding a new source type in future requires a backend service change and a frontend branch — there is no dynamic plugin mechanism.

**Alternatives considered:** Open-ended URL field with server-side domain matching to infer type. Simpler configuration UX but ambiguous when a domain could match multiple source types (e.g., a self-hosted instance on a generic domain).

**Rationale:** An explicit enum makes source type unambiguous, simplifies the parsing dispatch table, and allows the admin allow-list to use type-specific defaults without inferencing.

### D2: Server-side URL parsing at save time — store canonical embed URL

**Decision:** When a placement is saved, the backend parses the raw video URL, extracts the video ID, validates the domain against the allow-list, and stores the canonical embed URL in the `embedUrl` field of `widgetContent`. At render time, the frontend reads `embedUrl` directly and constructs the iframe `src` from it without re-parsing.

**Alternatives considered:** Re-parse the raw URL at render time in the frontend. Moves security-sensitive parsing into client-side JavaScript and requires shipping URL-parsing logic for each source type to the browser.

**Rationale:** Server-side parsing at save time is the correct security boundary: domain validation and ID extraction happen once, in a controlled environment, before the URL is stored. The frontend becomes a thin renderer with no knowledge of URL formats.

### D3: Admin domain allow-list — empty means deny-all

**Decision:** Admin setting `mydash.video_widget_allowed_domains` is a JSON array of permitted hostnames for hosted-platform embeds. The default value includes a small set of well-known hostnames. An empty array means no hosted-platform embeds are permitted. A non-existent or null setting is treated as the default, not as "allow all".

**Alternatives considered:** Empty value means "allow all" — simpler for administrators who want open embedding. Creates an unsafe default when the setting is accidentally cleared.

**Rationale:** The allow-list's security value comes from its restrictive default. "Empty = deny all" is the fail-safe interpretation that matches OWASP guidance for allowlist-based controls. Administrators who want unrestricted embedding must explicitly enumerate permitted domains.

### D4: Iframe sandbox attributes for hosted platforms

**Decision:** All hosted-platform iframes use `sandbox="allow-scripts allow-same-origin allow-presentation"`. No additional capabilities (`allow-forms`, `allow-popups`, `allow-top-navigation`) are granted.

**Alternatives considered:** Full `sandbox` (allow everything). Defeats the purpose of sandboxing.

**Alternatives considered:** No sandbox attribute. Maximum compatibility with platform players but no CSP isolation.

**Rationale:** `allow-scripts` and `allow-same-origin` are the minimum required for hosted video players to initialise and play. `allow-presentation` supports fullscreen. Excluding `allow-popups` and `allow-top-navigation` prevents embedded content from redirecting the outer page, which is the primary iframe injection risk.

### D5: Native video element for internal file storage sources

**Decision:** When `sourceType = nc-file`, the widget renders an HTML5 `<video controls>` element whose `src` points to a streaming endpoint (`GET /api/widgets/video/file/{fileId}`). The streaming endpoint verifies viewer ACL before serving the file and returns HTTP 403 if the viewer lacks read access.

**Alternatives considered:** Serve internal files via an iframe pointing to the built-in media viewer. Adds a full viewer shell around what the widget needs as a simple playback element, and the iframe approach requires cross-frame ACL coordination.

**Rationale:** A native video element is simpler, accessible (native browser controls, captions support), and works without cross-origin complexity since the streaming endpoint shares the same origin as the dashboard.

### D6: Privacy-preserving mode for applicable hosted platforms — opt-out

**Decision:** Admin setting `mydash.video_widget_use_nocookie_youtube` defaults to `true`. When enabled, the URL parser substitutes the standard domain with the privacy-enhanced equivalent for applicable platforms at save time, so the stored `embedUrl` already reflects the privacy-preserving variant.

**Alternatives considered:** Default to `false` (standard domain). Reduces tracking only for admins who discover and enable the setting.

**Rationale:** Dashboard content is often viewed by many users. Defaulting to privacy-preserving mode protects viewers who have not opted into tracking by the hosted platform, without requiring each viewer to take action. Administrators with strong integration requirements (e.g., analytics-based features) can opt out by changing the setting.

### D7: Autoplay and mute interaction rule

**Decision:** If `autoplay = true` and `muted = false`, the backend coerces `muted = true` before storing the placement config, and logs a notice. Autoplay without mute is blocked by all major browser autoplay policies and would silently fail; the coercion makes the intended behaviour explicit and prevents "why won't my video autoplay?" support requests.

**Alternatives considered:** Reject the configuration and return a validation error. Interrupts the save flow for a common misconfiguration that has a clear correct resolution.

**Rationale:** Silent coercion with a saved-config note is friendlier than a hard error for an easily-fixed misconfiguration. The coercion is documented in the config UI with an inline hint.

## Risks / Trade-offs

- **Hosted platform embed URL format changes** → Parser logic must be updated per source type when platforms change their embed schemes; integration tests must cover known URL format variations.
- **Allow-list maintenance for self-hosted instances** → Admins must add each instance domain manually; provide a parse-test endpoint (`GET /api/widgets/video/parse?url=...`) so admins can validate before saving.
- **Sandbox compatibility with future platform player features** → Features requiring `allow-popups` (share dialogs, some quality pickers) will not function; this is an accepted trade-off for the security boundary.

## Open follow-ups

- Evaluate whether `allow-fullscreen` belongs in the sandbox attribute set — currently absent; some browsers block fullscreen without it even when `allow-presentation` is present.
- Confirm whether self-hosted instances require individual domain entries in the allow-list or whether a subdomain wildcard pattern is needed.
- Specify captions / subtitle support for internal file sources (WebVTT sidecar files alongside the video).
