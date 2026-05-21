# Design — image-widget

## Context

MyDash today has no first-class way to place a single image on a dashboard. Users currently workaround this by jamming logos, screenshots, branding, and decorative imagery into the markdown widget via `<img>` tags or using iframe widgets pointing at external image URLs. These workarounds lack proper `object-fit` control, broken-image fallback handling, click-through linking, and a first-class file-upload user experience.

The image widget addresses this with four distinct source types — direct URL input, file upload to managed storage, Nextcloud Files picker, and external URLs — each with its own UX path and security posture. The capability is intentionally narrow (one widget type, one renderer, one multi-branch form, one registry entry) so it can be evolved or deprecated independently of the broader widget-rendering machinery.

## Goals / Non-Goals

**Goals:**

- Ship an `image` widget type that renders a single image with proper `object-fit` control (cover, contain, fill, none).
- Provide three distinct source paths in the edit form: direct URL/link entry, file upload to the resource-uploads endpoint, and Nextcloud Files picker.
- Display a broken-image placeholder (camera icon + message) when URLs fail to load, without crashing the surrounding GridStack grid.
- Support click-through to an optional target URL; only show pointer cursor when a link is actually present.
- Persist file references (`fileId`, `filePath`) for Nextcloud Files sources so users can track which file a widget references.
- Provide proper permission-aware preview URL generation for Nextcloud Files (respects viewer's file ACLs).
- Hand off SVG files to the sibling `svg-sanitisation` capability for XSS protection.
- Keep the widget self-contained — no new database tables, no new backend services, no new OCS routes. Persisted content lives in the existing `oc_mydash_widget_placements.content` JSON column.

**Non-Goals:**

- Lightbox or zoom-on-click (a future change can layer this onto empty-link cells).
- Gallery mode or image cycling.
- Image cropping, rotation, or filters.
- Uploading directly to Nextcloud Files (always to resource-uploads managed storage for non-Files sources).
- Per-image access control beyond Nextcloud's file permissions (Files mode respects file ACLs; upload/URL modes are dashboard-placement-level only).

## Decisions

### D1: Three source types (`url`, `upload`, `files`) with mutually exclusive form branches

**Decision**: The edit form exposes three radio-button-controlled branches — "URL/Link", "Upload", "Pick from Files" — that show/hide the relevant inputs. Switching sources does NOT erase previous values for other sources; the form retains all three in component state so users can switch back without re-entering.

**Rationale**: Users have different needs. Some paste external URLs (lightweight, no storage cost); some upload brand assets once and reference them (managed storage, cache-friendly); some link to existing Nextcloud files they already manage (single source of truth, permission-tied). Forcing one path would bloat the form for most users. Retaining state on switch prevents data loss friction and enables exploratory editing.

**Alternatives considered:**

- Four separate form sections, all visible at once. Rejected — too dense, confusing branching of required/optional fields.
- One mode per placement (switch mode → clear other fields). Rejected — data loss on accident is jarring UX.

### D2: Persist both `fileId` and `filePath` for Files sources

**Decision**: When a file is selected via the file picker, the placement stores both `fileId` (BIGINT for the Nextcloud file ID) and `filePath` (VARCHAR max 2048 bytes, human-readable path). Both are persisted at the placement level and survive edits to the widget's title, grid position, etc.

**Rationale**: `fileId` is the stable anchor to the file's preview URL and metadata; `filePath` is for display in edit forms and error messages (users recognize `/Photos/sunset.jpg` but not `fileId: 12345`). Together they support three use cases: (a) at render time, use `fileId` to generate a permission-aware preview URL; (b) at edit time, show the user which file they picked; (c) on file deletion, surface a human-readable "file `/Photos/sunset.jpg` is missing" error instead of a blank.

**Alternatives considered:**

- Store only `fileId`, derive path from file metadata at render time. Rejected — adds per-widget latency at render time if the file API is slow; if the file is deleted, we lose the hint for the user.
- Store only `filePath`, re-resolve to `fileId` at render time. Rejected — path is not stable across renames; race condition if file is renamed between edit and render.

### D3: Use preview URL generation (IURLGenerator::linkToRoute) for permission-aware display

**Decision**: For Files-mode widgets, the renderer calls the Nextcloud preview service via `IURLGenerator::linkToRoute('core.preview.getPreview', ['fileId' => $fileId, ...])` (server) or the equivalent client-side route to generate a URL that respects the viewer's file ACLs. No preview is generated server-side; only the URL is computed, and the browser fetches it (which may fail with 404 if the file is deleted or the viewer lost access).

**Rationale**: Permissions are checked at fetch time, not at metadata-read time, so the viewer always sees the current truth about file access. If a file is deleted or a share is revoked, the preview request naturally fails with 404, triggering the broken-image fallback (REQ-IMP-005). No special per-file permission check is needed — the preview service already enforces permissions.

**Alternatives considered:**

- Pre-check file ACLs at placement render time, return null if denied. Rejected — adds latency and misses the natural 404 from the preview service; the fallback would still trigger anyway.
- Serve the full file bytes (not a preview thumbnail). Rejected — less efficient; the preview service is built for this exactly.

### D4: SVG files route to the svg-sanitisation capability

**Decision**: When a file-mode widget references an SVG file (MIME type `image/svg+xml`), the widget rendering layer does NOT render it as a raw `<img src>`. Instead, the `fileId` and `filePath` are routed to the sibling `svg-sanitisation` capability for sanitisation before display. For URL and Upload modes, external SVG URLs also route through sanitisation (same rationale).

**Rationale**: SVG is an XML dialect that can embed scripts (`<script>`) and event handlers (`onclick`). Rendering it raw as an `<img>` bypasses these, but rendering it as `<div v-html>` requires sanitisation. The `svg-sanitisation` capability centralizes SVG handling so this widget does not reimplement the policy. The handoff is explicit: this change defines the integration point; `svg-sanitisation` defines the sanitisation rules.

**Alternatives considered:**

- Sanitize inline in the image-widget renderer. Rejected — SVG policy belongs in one place; `svg-sanitisation` is the designated owner.
- Block SVG entirely in the file picker. Rejected — users do use SVG logos and diagrams; the right fix is sanitisation, not rejection.

### D5: Broken-image fallback is identical across all sources

**Decision**: When any source (url, upload, files) fails to load, the widget displays the same placeholder: a 48 px camera icon + the translated message `t('Image failed to load')`, centered, in `var(--color-text-maxcontrast)`. The cell remains in the grid; no exception is thrown.

**Rationale**: Users see one consistent "failed" state regardless of whether the failure was a 404 on a URL, an upload that failed after the fact, or a deleted Nextcloud file. The consistent placeholder reduces cognitive load. The camera icon is the same one used for the empty-URL placeholder (REQ-IMG-002), creating a visual convention.

**Alternatives considered:**

- Different fallback per source (e.g., "URL not found" vs "File deleted" vs "Upload failed"). Rejected — too much branching in the renderer; the common case is the user fixed it and reloaded the page anyway.

### D6: sourceType defaults to `'url'` for backward compatibility

**Decision**: When a placement is created without an explicit `sourceType`, it defaults to `'url'`. Existing image widgets created before this change implicitly had `sourceType: 'url'`.

**Rationale**: Backward compatibility. If an old dashboard has an image widget with just `{url, alt, link, fit}`, and we add the `sourceType` field, the default must match the widget's pre-existing behavior (it was a URL source).

**Alternatives considered:**

- Default to `'upload'`. Rejected — would break existing URL-sourced widgets that never uploaded anything.

### D7: File picker restricted to image MIME types

**Decision**: The Nextcloud file picker is opened with MIME type filtering to show only files with MIME types in: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`. Documents, videos, and other files are hidden or marked disabled.

**Rationale**: Users expect "pick an image" to filter the visible files to images; forcing them to scroll through 500 documents to find 3 images is frustrating. The MIME types cover standard web images plus SVG (which the `svg-sanitisation` capability will handle).

**Alternatives considered:**

- Accept any file, let the browser's `<img>` error event handle non-images. Rejected — poor UX; the picker should guide users upfront.

### D8: Single-file selection only (no multi-select)

**Decision**: The file picker enforces single-file selection. Shift-click and Ctrl+click do not open a multi-select mode; only the last clicked file is selected.

**Rationale**: One widget = one image. Multi-select would confuse the UX (which file goes in the widget?). If a user wants a gallery, a future capability can add that; this widget is intentionally single-image.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Form state leakage across switches — user switches URL→Upload→Files and values mix | Component state is explicitly keyed by sourceType; inputs are v-if'd by the active branch; tested to confirm no cross-contamination |
| File deleted after placement created — orphaned fileId/filePath | Widget falls back to broken-image placeholder (REQ-IMP-005); user can re-pick a file in edit mode or switch to URL mode |
| Permission revoked after Files source selected — user can no longer see the preview | Preview URL 404 naturally; widget shows broken-image fallback; user can edit and re-pick (with new permissions) or switch sources |
| SVG-sanitisation capability not ready when image-widget ships | Wrap SVG handoff in a feature flag or check `typeof window.SVGElement` at render time; URL sources degrade to non-sanitized display if sibling not available (conservative fallback) |
| Bundle size: added form complexity | Form is code-split with renderer; lazy-loads only when user adds an image widget; overhead is ~5KB minified (form + radio group) |
| Preview URL generation latency on render | URLs are computed at render time but only if fileId is present; if preview service is slow, render blocks; mitigated by next point: (cache, 1-year max-age on served previews) |

## Migration

No data migration. The `content` column already exists and is JSON-flexible. Existing image placements (if any) have `{type: 'image', content: {url, alt, link, fit}}` with no `sourceType` field — the renderer and form default to `sourceType: 'url'`, so they continue to work unchanged.

## Test Strategy

- **Vitest renderer** (`ImageWidget.spec.js`): object-fit inline style matches `fit` field; prop validator falls back from invalid values; cell cursor toggles with `link`; empty/null url shows placeholder; `<img>` error event swaps in the placeholder; SVG files route to sanitisation (mock the svg-sanitisation endpoint)
- **Vitest form** (`ImageForm.spec.js`): validate() reports required URL on empty; upload-error path shows inline message and leaves url untouched; form pre-fills from placement on edit; switching sources retains state; all form inputs update component state reactively
- **Playwright** (`image-widget.spec.js`): upload an image → preview appears → save → reload → image still visible; external URL with link opens new tab with noopener,noreferrer; empty-URL cell shows camera placeholder and is not clickable; file picker filters to images; deleted file shows fallback placeholder
- **CI quality gates**: ESLint + Stylelint clean on new .vue/.js files; SPDX headers on every new file; `npm run build` succeeds with no new warnings; no new dependencies beyond `@mdi/svg` (already bundled)

## Reuse Analysis

- **ImageService**: Not applicable; no new service required.
- **ObjectService**: Not used (no OpenRegister schema).
- **FileService**: Not used directly; Nextcloud file picker is invoked via `@nextcloud/dialogs` (already in deps for other features).
- **ResourceService**: Called by the upload branch of the form to POST `/api/resources` (owned by `resource-uploads` capability).
- **IURLGenerator**: Called on the server side to generate preview URLs for Files-mode widgets.
- **DOMPurify**: Not used (SVG sanitisation is delegated to `svg-sanitisation` capability).

## Deduplication Check

- **Existing similar widgets**: `text-display-widget` (uses `v-html`), `label-widget` (plain text), `link-button-widget` (button with link) — none render images.
- **Existing file pickers**: Nextcloud's `@nextcloud/dialogs` FilePicker is the standard; no custom picker needed.
- **Existing upload handlers**: `resource-uploads` capability owns the `/api/resources` endpoint; image-widget is a consumer, not a builder.
- **SVG handling**: `svg-sanitisation` capability owns SVG sanitisation policy; image-widget routes SVG references to it.

**Conclusion**: No overlap with existing capabilities. Image-widget is a new, non-duplicative consumer of existing services (resource-uploads, file picker, svg-sanitisation).
