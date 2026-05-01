# Design — Files Widget

## Context

The files widget embeds an inline folder browser directly on a MyDash dashboard, allowing users to navigate, open, upload, and delete files without leaving the dashboard context. The widget operates entirely at render time: no background job or pre-cache layer is involved, because file system state changes frequently and a stale listing is worse than the marginal latency of a live read.

Access control is viewer-scoped. Every backend request is evaluated against the viewing user's permissions: files and folders the viewer cannot read are silently absent from the response. Upload and delete actions are similarly gated — the action buttons are rendered only when the placement allows the action AND the viewer holds the required permission on the folder. This means the same widget placement can present differently to different viewers depending on their rights.

Folder navigation within the widget is stateful client-side: sub-folder clicks update the widget's internal navigation stack and trigger a new fetch for the sub-folder's contents. A breadcrumb bar reflects the current path and supports jump-up navigation. The widget never navigates the outer application — all browsing happens within the widget's own viewport.

## Goals / Non-Goals

**Goals:**
- Provide a live, permission-filtered, paginated folder listing fetched at render time via the server-side file system API.
- Support click-to-open that deep-links to the standard Files application for preview or editing.
- Enforce per-viewer ACL silently — no indication to the viewer that hidden files exist.
- Support cursor-based pagination (50 files per page) for stable navigation across renames.
- Allow upload and delete actions behind both a placement-level toggle and a per-viewer permission check.

**Non-Goals:**
- In-widget file preview or editing is out of scope; the widget is a browser, not a viewer.
- Full-text search across file contents is not provided.
- Syncing or offline access is not in scope.

## Decisions

### D1: Live fetch at render time — no cache layer

**Decision:** The backend endpoint reads folder contents directly from the server-side file system API on every request. No intermediate cache is used.

**Alternatives considered:** Cache folder listings for a short TTL (e.g., 30 seconds). Reduces file system calls under high concurrency but serves stale data — a file uploaded seconds ago would not appear.

**Rationale:** For a file browser, users expect the listing to reflect reality. Stale data would cause user confusion (e.g., "I just uploaded a file — where is it?"). The small latency of a live read is the correct trade-off here.

### D2: Silent ACL filtering — no indication of hidden files

**Decision:** Files and folders the viewing user cannot read are excluded from the response silently. The API returns the subset the viewer may see; it does not indicate that additional items exist but are hidden.

**Alternatives considered:** Return a placeholder row ("N items hidden — request access") for filtered items. More transparent but leaks existence of restricted files, which may itself be sensitive.

**Rationale:** Standard file-system security convention is to not reveal the existence of inaccessible resources. The silent-omission approach matches expected behaviour in all supported permission models.

### D3: Cursor-based pagination anchored to fileId

**Decision:** Pagination uses a cursor equal to the `fileId` of the last item shown in the current page. The backend resolves the cursor to a position in the sorted listing and returns the next 50 items. Page size is fixed at 50.

**Alternatives considered:** Offset-based pagination (`?offset=50&limit=50`). Simple but unstable: a rename between pages shifts offsets and can cause items to appear twice or be skipped.

**Rationale:** `fileId` is stable across renames and moves within the same folder. Using it as a cursor means "Load more" produces a coherent continuation even if the folder contents changed since the first page was loaded.

### D4: Open-in-Files deep link

**Decision:** Clicking a file opens the standard Files application using `linkToRoute('files.View.index', {dir: parentPath, scrollto: fileId})`. The link opens in the same tab (no `target="_blank"`), taking the user into the full Files experience for preview and editing.

**Alternatives considered:** Open in a new tab to preserve the dashboard context. Splitting context is disorienting when the user intends to work on the file.

**Rationale:** The widget is a convenience entry point, not a replacement for the Files application. Users who need to work with a file should be in the full application. Same-tab navigation is the Nextcloud-native pattern for inter-app linking.

### D5: Folder selection via native path picker dialog

**Decision:** In the widget configuration UI, the folder path is selected using the platform's built-in folder picker dialog (`OC.dialogs.filepicker` with `FILEPICKER_TYPE_CHOOSE_FOLDER` and `modal: true`). The selected folder is stored as an absolute NC path in `widgetContent`.

**Alternatives considered:** Free-text path input. Simpler to implement but error-prone — typos produce silent empty states or wrong-folder displays with no helpful feedback.

**Rationale:** The native picker guarantees the selected path exists at configuration time, provides a familiar UX consistent with the rest of Nextcloud, and avoids an entire class of misconfiguration errors.

### D6: Upload and delete behind dual-gate (placement + ACL)

**Decision:** Upload and delete action buttons are rendered only when (a) the placement config has `allowUpload = true` / `allowDelete = true` AND (b) the viewing user holds write / delete permission on the folder. The backend re-validates both conditions on every write or delete request.

**Alternatives considered:** Placement config alone — show buttons based on `allowUpload` regardless of viewer permission. Simpler but leads to confusing "permission denied" errors after clicking an enabled button.

**Rationale:** Surfacing write/delete actions only when they will succeed prevents confusing error states. The backend re-check on every mutation request is the authoritative permission enforcement; the UI check is a UX convenience, not a security gate.

### D7: Deleted-folder empty state

**Decision:** If the configured folder is no longer accessible (deleted or moved), the widget displays a dedicated "Folder no longer exists" empty state rather than an error banner or crash. A hint directs widget editors to update the configuration.

**Alternatives considered:** Propagate the error as an HTTP 500 or show a generic failure message. Neither communicates to the viewer or editor what action is needed.

**Rationale:** Folder deletion is a foreseeable operational condition. A named empty state with actionable copy ("Update widget settings to choose a new folder") is more helpful than a generic failure.

## Risks / Trade-offs

- **High-frequency renders on large folders** → Every dashboard load hits the file system; conservative default page size (50) limits cost per request.
- **Cursor stability across concurrent mutations** → A deleted file between pages may invalidate the cursor; fall back to offset-based position when cursor resolution fails.
- **ACL evaluation latency on large group hierarchies** → No per-request caching is planned; may surface as latency on first load for complex permission setups.

## Open follow-ups

- Define the empty-state copy when the viewer's HTTP 403 is returned for the configured folder root.
- Evaluate whether a 30-second client-side cache is acceptable on read-heavy dashboards where stale-file risk is low.
- Confirm whether the tree display mode (hierarchical) is in scope for this iteration or deferred.
