# Design — Resource Serving

## Context

The `resource-uploads` capability (v1) completed the write side: an admin can POST a base64-encoded image to `POST /api/resources` and the system persists it under `<appdata>/resources/resource_<uniqid>.<ext>`. However, there is no first-party way for a rendered dashboard to fetch those bytes back. Dashboard widgets (image widget, link-button widget, dashboard icon picker) need to reference uploaded resources by stable URLs, and today they have no serving endpoint. This change adds the read side: a non-OCS `GET /resource/{filename}` that streams bytes with correct Content-Type and immutable cache headers, plus an OCS `GET /api/resources` listing endpoint for a future admin gallery or cleanup UI.

## Goals / Non-Goals

**Goals:**

- Serve uploaded resource bytes via a deterministic, cacheable URL that widgets can reference directly.
- Keep the serving implementation simple: filename lookup in app data, extension-derived Content-Type, no ACL or transformation logic.
- Use immutable cache headers (one-year max-age) since uniqid filenames already serve as cache busters — when the same logical asset changes, a new upload generates a new URL.
- Support future admin features (gallery, cleanup) with a listing endpoint that does NOT require admin access (uploaded resources are referenced by every dashboard, so listing must be visible to all logged-in users).
- Reject path traversal attempts with a 404 so system files are never leaked.
- Bound memory usage by refusing to load files larger than the 5 MB upload cap (protects against manual filesystem tampering).

**Non-Goals:**

- Per-resource access control (v1 limitation — all uploaded resources are public to logged-in users).
- Image transformation (resize, crop, format conversion) — serve stored bytes as-is.
- Compression or transfer-encoding optimization — let CDN or reverse proxy handle that layer.
- Garbage collection of orphaned resources — tracked as separate `resource-gc` follow-up.

## Decisions

### D1: Serve via non-OCS plain web route, not OCS API

**Decision:** `GET /resource/{filename}` is a non-OCS plain web route returning a `StreamResponse`, separate from the OCS listing endpoint `GET /api/resources`.

**Alternatives considered:**

- Single OCS endpoint for both `GET /api/resources/{id}` read + list. Rejected because OCS responses are JSON-wrapped and unsuitable for binary streaming — would add wrapping overhead and client-side unwrapping logic.
- Serve via a registered file download hook (Nextcloud's file sharing layer). Rejected — overkill for a small, flat file store with no sharing rules.
- Serve via the Files app's direct download route. Rejected — resources are NOT user Files, they're admin-controlled app-data assets.

**Rationale:** Non-OCS routes are appropriate for binary streaming. Separating listing (OCS, JSON) from serving (plain route, bytes) keeps concerns clean.

### D2: Reject path traversal with 404, not 403 or error response

**Decision:** Any decoded `/` or `..` in the `{filename}` parameter triggers HTTP 404 with an empty or generic body. No error detail (e.g., `{error: 'invalid_path'}`).

**Alternatives considered:**

- Return 403 Forbidden. Rejected — 403 signals "auth denied," not "resource not found," and can leak that a traversal attempt was detected.
- Return 400 Bad Request with `{error: 'invalid_path'}`. Rejected — same leak as 403, plus inconsistent with "missing file → 404" response.

**Rationale:** 404 is semantically correct (the requested resource does not exist) and provides no information to an attacker probing the path traversal vector. Symfony's route constraint `{filename}` matches `[^/]+` by default (no slashes), so the primary defense is in the route definition; the controller method adds belt-and-braces validation.

### D3: Content-Type derived from filename extension, not `finfo_file`

**Decision:** Content-Type is mapped from the file extension (e.g., `.png` → `image/png`) rather than sniffing the file bytes with `finfo_file`.

**Alternatives considered:**

- Always sniff bytes with `finfo_file`. Rejected — adds latency and memory footprint (must read bytes before deciding the header).
- Require an explicit `Content-Type` property stored in app-data metadata. Rejected — no metadata store for resources; uniqid filenames + extension is the complete source of truth.

**Rationale:** The extension map is lightweight and aligns with the upload side (REQ-RES-002 validates the declared type in the data-URL prefix and uses the same extension-to-type map). Consistent with the principle that filename = truth.

### D4: Immutable cache with one-year max-age, not shorter or versioned ETags

**Decision:** `Cache-Control: public, max-age=31536000` (one year). No ETag or `Last-Modified` header.

**Alternatives considered:**

- Shorter cache lifetime (e.g., 1 day, 1 week). Rejected — uniqid filenames already prevent collisions; shorter lifetimes force unnecessary revalidation and serve no real cache-busting purpose.
- Conditional requests with ETag or `Last-Modified`. Rejected — resources are immutable once written (no update-in-place); a new upload = new filename = new URL, so conditional revalidation adds overhead without benefit.
- No cache header (cache-as-default). Rejected — dashboard loads repeat frequently; one-year immutable is safe and reduces client-side bandwidth.

**Rationale:** Uniqid suffix in the filename is the cache buster. If the admin wants a "new version" of an icon, they upload it again, get a new filename/URL, and dashboards fetch the new URL (which they must because the old widget config referenced the old URL). This design ensures every URL is immutable and maximum-cacheable.

### D5: List endpoint is authenticated but NOT admin-gated

**Decision:** `GET /api/resources` requires authentication (any logged-in user) but does NOT require admin privileges.

**Alternatives considered:**

- Admin-only listing. Rejected — uploaded resources are referenced by every dashboard in the app, and users need to see which resources are available when picking icons; restricting to admins breaks the workflow.
- Public (unauthenticated) listing. Rejected — resource filenames carry uniqid suffixes (hard to enumerate) but listing every one is unnecessary exposure.

**Rationale:** Uploaded resources are branding/icon assets, not sensitive. Any logged-in user can see all dashboards (and thus infer which resources are in use). Listing is a read-only discovery operation, not a privilege escalation vector.

### D6: Empty folder returns `{resources: []}` HTTP 200, not 404

**Decision:** When the `resources/` folder does not exist yet, `GET /api/resources` returns HTTP 200 with `{status: 'success', resources: []}`.

**Alternatives considered:**

- Return 404 when the folder doesn't exist. Rejected — a missing folder is not an error; it's the initial state before the first upload.
- Return 204 No Content (empty response). Rejected — client code expects a JSON response body with the `resources` key for consistency with the non-empty case.

**Rationale:** REST conventions treat "no data" differently from "resource not found." The listing endpoint succeeds and returns an empty list, aligning with the principle that the app is functional even if no uploads exist yet.

### D7: Stream-via-memory buffer, not disk-based streaming

**Decision:** Implementation streams bytes into a `php://memory` buffer bounded by the 5 MB upload cap. Oversize files (>5 MB, only possible via manual filesystem tampering) return HTTP 413.

**Alternatives considered:**

- Direct filesystem read with `readfile()`. Rejected — less control over buffer boundaries and harder to enforce the 5 MB cap.
- Disk-based temporary buffer (`php://temp`). Rejected — adds latency and complexity for no gain; 5 MB in memory is acceptable for icon-sized assets.

**Rationale:** The upload side enforces a 5 MB cap (REQ-RES-003), so all valid resources fit in memory. The 413 guard protects against manual filesystem tampering (e.g., an admin manually places a 50 MB file) without exhausting memory. Simple implementation.

## Risks / Trade-offs

- **Risk:** No per-resource ACL means all authenticated users can fetch any resource by name. → **Mitigation:** Filenames are uniqid-suffixed (hard to enumerate); resources are branding assets, not secrets. Risk is low.
- **Risk:** If the admin deletes a resource file, dashboards render broken-image icons. → **Mitigation:** Resources live in app-data (not user Files), so deletion requires direct filesystem access or a hypothetical `resource-gc` feature. Acceptable for v1.
- **Risk:** Memory-based streaming could fail for clients on slow connections (timeout while buffering). → **Mitigation:** 5 MB at typical internet speeds is seconds; acceptable for a read-only feature. If it becomes a problem, a future change can layer on streaming via `php://temp`.
- **Trade-off:** No `Last-Modified` or ETag means the client cannot detect stale cache locally. → **Mitigation:** Cache key is in the URL (uniqid); the admin control workflow is "upload new, get new URL, update widget config to point to new URL." Conditional revalidation adds no value.

## Migration Plan

1. **Controller methods land first** — add `getResource` + `listResources` with full impl + tests in one PR.
2. **Routes registered** in `appinfo/routes.php` in the same PR.
3. **OpenAPI updated** for the listing endpoint.
4. **Hydra gates checked** — gate-route-auth, gate-semantic-auth, all others green.
5. **Changelog + docs updated** with the new endpoints and cache strategy.
6. **Rollback:** Pure read-only code, no schema changes. Reverting removes the endpoints with no data loss.

## Open Questions

- Should the listing endpoint support filtering or pagination? Current decision: no — expected resource counts (icons, logos) are small. Add if future use cases require it.
- Should the serving endpoint log access? Current decision: no — not an audit surface; leave to reverse proxy/CDN logging if needed.
- Should the extension map be configurable? Current decision: no — hardcoded map aligns with upload side's declared-type validation. Revisit only if new image types become critical.
