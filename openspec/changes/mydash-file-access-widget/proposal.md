# Open dossier document from dashboard

Dashboard users need quick access to dossier documents without navigating away from the dashboard view. Currently, document links are buried in detail pages, forcing users to context-switch to find critical files. This change adds a file-access widget to the MyDash dashboard: a card displaying associated documents with direct open-in-viewer links, filterable by dossier and file type, with built-in access control to prevent unauthorized downloads.

## Affected code units

- `src/components/DashboardFileAccessWidget.vue` — new widget displaying files linked to the current dossier
- `src/store/modules/files.js` — extend store to fetch + cache dossier files
- `src/composables/useFileAccess.js` — new composable for file retrieval + access checks
- `src/dashboard/` — widget slot registration in dashboard layout
- `lib/Controller/FileAccessController.php` — new backend endpoint for authenticated file listing
- `lib/Service/FileAccessService.php` — business logic for file access + filtering
- Consumes `@conduction/nextcloud-vue` + OpenRegister `FileService` (no custom file handling)

## Why a delta

MyDash dashboards today are widget-based but lack direct document access. Users switch to detail pages or external document managers to view case-related files. This creates friction in case-management workflows where documents are the primary work product. The feature stays confined to the dashboard widget system and reuses OpenRegister's file abstractions (ADR-022 pattern); no new document storage or access-control logic is introduced.

## Approach

- **Widget integration** — `CnDashboardPage` loads the widget via GridStack; the widget fetches its data from the backend on mount and when the dossier changes
- **Backend file listing** — `FileAccessController::list()` queries OpenRegister's file associations for the dossier + applies user's per-file permissions via `PropertyRbacHandler`
- **File viewer link** — each file displays a link that opens the document via Nextcloud's `FileService` (existing `download` + `preview` endpoints, no custom routes)
- **Access control** — backend enforces authorization; frontend silently hides files the user cannot read. No attempt-to-view failures displayed to the user.
- **Filtering** (defer to Phase 2) — file-type filters and search are designed but not implemented in this change

## Capabilities

**NEW Capabilities:**

- `file-access-widget` (new)
  - Requirement: `REQ-FILE-001` — File list display with access control
  - Requirement: `REQ-FILE-002` — File viewer integration
  - Requirement: `REQ-FILE-003` — Access denial handling

## Notes

- File permissions are evaluated by OpenRegister; this widget is a read-only consumer, not an access-control enforcer
- The widget is responsive: works at 320px (stacked list) and 1920px (multi-column cards)
- Tested via Playwright scenarios: "view accessible files", "access denied files are hidden"
