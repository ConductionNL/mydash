# Design — Image Widget Media Picker

## Context

The image-widget capability (currently in `openspec/changes/`, not yet promoted) supports two image sources: a direct URL string and a file uploaded via the resource-uploads endpoint. Both paths store a URL in `content.url`. Authors who already have images in their Nextcloud Files do not want to download and re-upload them; they want a native file picker. This change adds a third source, `nc-file`, that opens the platform's built-in file picker and stores a `fileId` instead of a URL, so the image is resolved at render time against the viewer's own file permissions.

The key design constraint is that the file picker produces a `fileId`, not a URL. A `fileId` is opaque to external consumers and cannot be handed to a plain `<img src>`. The renderer must exchange the `fileId` for a time-limited or permission-respecting URL at view time, and must degrade gracefully when the referenced file has been deleted or is inaccessible to the viewer.

This spec extends `image-widget` and depends on it being in place. It does not modify the URL or upload paths.

## Goals / Non-Goals

**Goals:**
- Add a "Choose from Nextcloud" button to the image-widget edit form that opens the platform file picker.
- Store the selected file as `{source: 'nc-file', fileId: number}` on the placement.
- Resolve the `fileId` to a serving URL at render time, checking the viewer's read permission.
- Render the broken-image placeholder when the file is deleted or inaccessible.
- Enforce a MIME-type allow-list at pick time; reject non-image files.

**Non-Goals:**
- Picking files from external storage providers or federated shares.
- Serving image content directly from the MyDash backend (the platform's own file-serving URL is used).
- Permanent public sharing of the picked file (the image is only visible to users who can already read it).
- Editing or cropping the picked image.

## Decisions

### D1: Storage discriminator — `source` field on the placement
**Decision**: The placement content gains a `source: 'url' | 'upload' | 'nc-file'` discriminator; the `fileId` field is only present when `source === 'nc-file'`; existing `url`-source placements are unaffected.
**Alternatives considered**:
- Store the serving URL derived from `fileId` instead of the `fileId` itself — rejected: serving URLs can expire or change; `fileId` is stable.
- Overload `content.url` with a `ncfile://` scheme — rejected: fragile, requires a custom scheme parser, breaks the existing renderer's plain `<img src>`.
**Rationale**: A discriminator field makes the rendering branch explicit and avoids any ambiguity about how to interpret the `url` field.

### D2: File picker integration — platform built-in dialog
**Decision**: Clicking "Choose from Nextcloud" invokes the platform's built-in file picker dialog, which returns the selected file's node ID.
**Alternatives considered**:
- A custom file browser built inside the MyDash edit modal — rejected: duplicates existing platform functionality and requires ongoing maintenance.
**Rationale**: The built-in picker is already available to all Nextcloud apps, handles federated and local files uniformly, and is familiar to users.

### D3: Permission-respecting render via server-side check
**Decision**: At view time, the renderer calls `IRootFolder` to verify the current viewer can read the file before returning a serving URL; if the check fails, the broken-image placeholder is shown.
**Alternatives considered**:
- Serve all `nc-file` images without a permission check and rely on Nextcloud's file-serving to reject unauthorised requests — rejected: exposes the existence of the file (the browser makes a request that returns 403, leaking the fileId is in use).
- Cache permission results — rejected: caching introduces staleness if file permissions change between renders.
**Rationale**: A server-side check is the only way to avoid both information leakage and broken-image noise for users who legitimately cannot see the file.

### D4: MIME-type allow-list
**Decision**: The picker is constrained to `image/jpeg`, `image/png`, `image/gif`, `image/webp`, and `image/svg+xml`; SVG is only allowed if the admin SVG-sanitiser setting is enabled.
**Alternatives considered**:
- Allow any MIME type and rely on `<img>` to refuse non-image content — rejected: exposes the broken-image fallback UX for valid files that happen to not be images; also allows SVG without sanitisation.
- Exclude SVG entirely — rejected: SVG is a common logo/icon format and admins who have enabled the sanitiser should be able to use it.
**Rationale**: Restricting the picker MIME list prevents user confusion and closes the SVG XSS vector by linking it to the admin-controlled setting.

### D5: File deletion handling — broken-image placeholder plus admin orphan cleanup
**Decision**: When the `fileId` no longer exists or the viewer cannot read it, the renderer shows the same broken-image placeholder as the existing `@error` handler; a background admin job identifies dangling `fileId` values in placements.
**Alternatives considered**:
- Automatically remove the placement when the file is deleted — rejected: deleting a widget silently is destructive and confusing for the dashboard owner.
- Show a specific "file deleted" message instead of the generic placeholder — rejected: leaks information about why the image is missing to viewers who may not have had access.
**Rationale**: The placeholder is already the fallback for broken URLs; reusing it keeps the viewer experience consistent. The admin cleanup job is an operational backstop, not a correctness requirement.

### D6: No change to the URL or upload source paths
**Decision**: The `url` and `upload` source paths remain exactly as specified in `image-widget`; this change is purely additive.
**Alternatives considered**: none
**Rationale**: Additivity prevents regression and allows the media picker to be released independently of or after the base `image-widget` change.

## Risks / Trade-offs

- **Serving URL stability** → The URL returned by `IRootFolder` for a given `fileId` may change if the file is moved. Mitigation: resolve the URL on every render rather than caching it in the placement.
- **SVG sanitiser dependency** → Admins who enable the SVG MIME type without enabling the sanitiser may be surprised when SVG is still blocked. Mitigation: the UI shows a tooltip explaining the dependency.

## Open follow-ups

- Decide whether the broken-image placeholder for inaccessible `nc-file` images should be distinguishable from a genuinely broken URL to the dashboard owner (but not to plain viewers).
- Define the cadence and scope of the admin orphan-cleanup job — on-demand, nightly, or triggered by file deletion events.
- Confirm whether the platform file picker can be constrained to a specific MIME list programmatically or only by post-selection validation.
