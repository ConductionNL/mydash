---
capability: file-access-widget
delta: false
status: draft
---

# File Access Widget — Dashboard document and file access

## NEW Requirements

### Requirement: REQ-FILE-001 File List Display with Access Control

The MyDash dashboard MUST display a file-access widget that shows files linked to the current dossier. The widget MUST:

1. Fetch the list of files related to the active dossier via a backend API endpoint
2. Apply the current user's per-file read permissions (inherited from OpenRegister RBAC)
3. Display only files the user is authorized to read
4. Show for each file: filename, file type, size in bytes, and last-modified date
5. Render the list in a responsive table/card format suitable for 320px–1920px viewports
6. Handle empty state: show "No files" message when the dossier has no files or user has zero permissions
7. Handle error state: show "Failed to load files" with a retry button when the backend is unavailable

#### Scenario: List accessible files for a dossier

- **GIVEN** a dossier with three linked files: "application.pdf" (readable by user), "supporting_doc.docx" (readable by user), "confidential.pdf" (not readable by user)
- **WHEN** a dashboard user views the file-access widget with that dossier selected
- **THEN** the widget MUST display "application.pdf" and "supporting_doc.docx" in the file list
- **AND** the widget MUST NOT display "confidential.pdf"
- **AND** each file MUST show its filename, type, size, and modification date

#### Scenario: Empty state when dossier has no files

- **GIVEN** a dossier with zero files linked
- **WHEN** a dashboard user views the file-access widget with that dossier selected
- **THEN** the widget MUST show an empty-state message: "No files"
- **AND** the message MUST NOT be an error state (no alert icon or retry button)

#### Scenario: Hidden inaccessible files (same UX as empty)

- **GIVEN** a dossier with three linked files, all marked as non-readable for the current user
- **WHEN** a dashboard user views the file-access widget
- **THEN** the widget MUST show the same "No files" empty state
- **AND** the user MUST NOT see any indication that files exist but are denied

#### Scenario: Error state and retry

- **GIVEN** the backend file-list endpoint returns HTTP 500
- **WHEN** the widget attempts to load files on mount
- **THEN** the widget MUST display "Failed to load files" with an alert icon
- **AND** a "Retry" button MUST be clickable
- **WHEN** the user clicks "Retry"
- **THEN** the widget MUST re-request the file list from the backend

#### Scenario: Responsive display at tablet (768px)

- **GIVEN** the widget is displayed on a 768px-wide viewport
- **WHEN** the file list is rendered
- **THEN** the file list MUST be readable and fully functional
- **AND** files MUST be displayed in a two-column card layout or vertically-stacked rows (not horizontally scrolling)

#### Scenario: Responsive display at mobile (320px)

- **GIVEN** the widget is displayed on a 320px-wide viewport
- **WHEN** the file list is rendered
- **THEN** each file entry MUST display filename + size as minimum information
- **AND** additional columns (type, date) MAY be hidden or shown in a detail view
- **AND** no horizontal scrolling MUST be required to access the file open-link

### Requirement: REQ-FILE-002 File Viewer Integration

The file-access widget MUST provide direct links to open and view/download files. The widget MUST:

1. Display an "Open" button or clickable filename for each file
2. When clicked, open the file using Nextcloud's native file viewer/downloader
3. Pass the correct file object reference (register + schema + objectId) to the viewer
4. Handle file open in a new tab (do not reload the dashboard page)

#### Scenario: Open file in viewer

- **GIVEN** a dashboard user can read "application.pdf" (a linked dossier file)
- **WHEN** the user clicks the "Open" link next to the filename
- **THEN** Nextcloud's file viewer MUST open in a new browser tab
- **AND** the file MUST be displayed (or downloaded, depending on file type and Nextcloud settings)
- **AND** the dashboard page MUST remain unchanged (no navigation/reload)

#### Scenario: File open respects file type

- **GIVEN** files of mixed types in the widget list: "photo.jpg" (image), "summary.pdf" (document), "data.xlsx" (spreadsheet)
- **WHEN** a user opens each file
- **THEN** images MUST preview in the viewer
- **AND** PDFs MUST preview in the document viewer (if available)
- **AND** spreadsheets MUST either preview or prompt download based on Nextcloud configuration

#### Scenario: File viewer inherits dossier context

- **GIVEN** a file "quarterly_report.pdf" linked to dossier "Case-2026-001"
- **WHEN** the user opens the file from the file-access widget
- **THEN** the file viewer MUST be aware of its parent dossier context
- **AND** breadcrumb navigation (if present) MUST show "Case-2026-001 > quarterly_report.pdf"

### Requirement: REQ-FILE-003 Access Denial Handling

The system MUST handle authorization failures gracefully. The widget MUST:

1. Never display files the user cannot read
2. Never attempt to open a file the user cannot access
3. Never show "Permission Denied" error messages to the user for specific files (errors are backend-only)
4. Gracefully handle the case where a file is deleted or moved while the widget is displayed

#### Scenario: Access-denied files are hidden

- **GIVEN** a dossier has three files, but the current user can only read one ("summary.pdf")
- **WHEN** the file-access widget loads
- **THEN** ONLY "summary.pdf" MUST be visible to the user
- **AND** the other two files MUST NOT appear in any form (no grayed-out entries, no "access denied" messages)

#### Scenario: File deleted after widget load

- **GIVEN** the file-access widget has loaded and is displaying three files
- **WHEN** another user (with admin privileges) deletes one of the displayed files from OpenRegister
- **AND** the first user clicks "Refresh" or the dashboard refreshes
- **THEN** the widget MUST reload and display only the two remaining files
- **AND** no error MUST be shown (the deleted file simply no longer appears)

#### Scenario: User permissions change mid-session

- **GIVEN** a user is viewing the file-access widget and can see files A, B, C
- **WHEN** an admin revokes the user's read permission on file B
- **AND** the user refreshes the dashboard or the widget auto-refreshes
- **THEN** files A and C MUST remain visible
- **AND** file B MUST disappear silently (treated as "no longer accessible")

## Deferred Requirements (Phase 2)

The following capabilities are designed but NOT implemented in Phase 1:

- **REQ-FILE-004** (deferred) — File filtering by type (images, documents, spreadsheets)
- **REQ-FILE-005** (deferred) — File search within dossier documents
- **REQ-FILE-006** (deferred) — Bulk download as ZIP
- **REQ-FILE-007** (deferred) — Inline file preview (thumbnails for images)
- **REQ-FILE-008** (deferred) — Sort files by name, date, size
