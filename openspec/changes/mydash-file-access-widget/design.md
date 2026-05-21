# Design — File Access Widget for MyDash Dashboard

## Context

MyDash users work with dossiers (cases) that have associated files (documents, evidence, permits). Today, accessing these files requires:
1. Navigate to the dossier's detail page
2. Open the "Files" sidebar tab
3. Click to open/download from there

This is cumbersome for dashboard-centric workflows where users spend most of their time in the widget view. A dedicated file-access widget on the dashboard—showing the current dossier's files with direct viewer links—keeps users in flow.

The widget consumes OpenRegister's existing file abstractions (via `FileService` and object relations) rather than building parallel file logic (ADR-022 principle).

## Goals / Non-Goals

**Goals:**

- Display files linked to the current dossier on the dashboard
- Provide direct links to open/download files without leaving the dashboard
- Respect each user's per-file read permissions (inherited from OpenRegister RBAC)
- Hide files the user cannot access (no "access denied" error message shown)
- Responsive design (works at 320px tablet, 768px, and 1920px desktop)

**Non-Goals (defer to Phase 2):**

- File filtering by type, date, or custom metadata
- File search across dossier documents
- Bulk download as ZIP
- File upload from the dashboard
- Custom document viewer (use Nextcloud's native viewer)
- Sharing/permission management from the widget

## Decisions

### D1: Single widget component with OpenRegister file relations as data source

**Decision**: The widget queries OpenRegister's `relations` mechanism to fetch files linked to a dossier, applies the user's per-file permissions, and displays the results.

**Alternatives considered:**

- Query a custom `mydash_dossier_files` junction table. Rejected — ADR-022 requires consuming OR's abstractions; custom tables duplicate OR's relation logic.
- Fetch files from Nextcloud's NC Files API directly. Rejected — doesn't apply OR's RBAC filtering; we'd re-implement permission checks.

**Rationale**: OpenRegister already maintains file associations via `relations` (registered to the dossier object) and provides permission evaluation via `PropertyRbacHandler`. Consuming this avoids building parallel access-control logic.

### D2: Backend-enforced access control with silent frontend hiding

**Decision**: The backend (`FileAccessController::list()`) applies OpenRegister's RBAC via `PropertyRbacHandler` and returns only files the user can read. The frontend does not attempt authorization — it receives a filtered list and displays all of it.

**Alternatives considered:**

- Frontend attempts authorization and shows "Access denied" messages. Rejected — creates a false sense of security (user sees the system tried to enforce rules but doesn't know if those rules are correct); backend is the source of truth.
- Return all files and let the frontend decide which to show. Rejected — frontend-only auth is a vulnerability (ADR-005 Rule 2).

**Rationale**: Backend returns only what the user is permitted to see. The frontend's job is display, not gatekeeping. Users naturally assume "if I don't see it, I can't access it", which is the correct mental model.

### D3: Widget slot integration via GridStack + dashboard composable

**Decision**: The widget is registered as a draggable GridStack widget on `CnDashboardPage`. It fetches data on mount and when the active dossier changes.

**Alternatives considered:**

- Embed the widget in a fixed section above the grid. Rejected — breaks the drag-drop layout paradigm MyDash users expect.
- Load files on every dashboard render. Rejected — unnecessary API calls; fetch once per dossier + cache.

**Rationale**: GridStack integration keeps the widget consistent with other MyDash widgets (drag-to-reposition, resizable, add/remove via menu). Dossier-change detection reloads data when context shifts.

### D4: Use `CnObjectDataWidget` or `CnDetailCard` for list rendering

**Decision**: Render the file list as a `CnDetailCard` with a simple table (filename, size, type, modified-date, open-link).

**Alternatives considered:**

- Use `CnObjectDataWidget` for editable grid. Rejected — files are read-only in this context; inline editing is out of scope.
- Build a custom file list component. Rejected — ADR-017 requires using self-contained library components; custom lists duplicate `CnTableWidget` / `CnDetailCard` logic.

**Rationale**: `CnDetailCard` is self-contained (renders its own card frame); we populate it with a custom file list table. Avoids duplication while keeping the card layout consistent.

### D5: Fetch files via new backend endpoint + Pinia store plugin

**Decision**: `FileAccessController::list(dossierId)` returns `{files: [{id, name, size, type, modifiedAt, url}]}`. The Pinia store wraps this via a `filesPlugin` (following `@conduction/nextcloud-vue` pattern).

**Alternatives considered:**

- Call the backend directly from the widget. Rejected — no centralized fetch, no caching, no retry logic.
- Add a new Pinia store from scratch. Rejected — store plugins are the pattern; build on the existing infrastructure.

**Rationale**: Store plugins provide fetch + caching + error handling. The widget calls `store.fetchDossierFiles(dossierId)` and handles loading/error states via `try/catch`.

## Reuse Analysis (ADR-012)

This change consumes:

| OpenRegister Abstraction | How we use it |
|---|---|
| **Files + Relations** | Query `relations` mechanism to find files linked to a dossier; fetch via OpenRegister's `FileService` |
| **RBAC / PropertyRbacHandler** | Backend applies per-file permissions; frontend receives filtered list |
| **Integration Registry (ADR-019)** | If Nextcloud has a file viewer integration, we reference it via the registry |
| **@conduction/nextcloud-vue components** | `CnDetailCard`, `CnDetailPage`, dashboard widget slots, store plugins |

**No duplication found**. The widget is a thin consumer layer; all data/auth logic lives in OR.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│ DashboardFileAccessWidget.vue                           │
│ (GridStack widget, mounted on CnDashboardPage)          │
│                                                         │
│ ┌────────────────────────────────────────────┐          │
│ │ on mount + dossierId change:               │          │
│ │   store.fetchDossierFiles(dossierId)       │          │
│ │                                            │          │
│ │ on success:                                │          │
│ │   <CnDetailCard :title="Files">            │          │
│ │     [file-list table]                      │          │
│ │   </CnDetailCard>                          │          │
│ │                                            │          │
│ │ on error:                                  │          │
│ │   <NcEmptyContent icon="alert">            │          │
│ │     Failed to load files                   │          │
│ │   </NcEmptyContent>                        │          │
│ └────────────────────────────────────────────┘          │
│                                                         │
│ store.fetchDossierFiles(dossierId) ──────────────>     │
│                                                         │
│ (via Pinia filesPlugin)                                 │
└─────────────────────────────────────────────────────────┘
          │
          │ axios.get(/api/file-access/list?dossierId=X)
          │
          v
┌─────────────────────────────────────────────────────────┐
│ FileAccessController::list()                            │
│                                                         │
│  1. Get dossierId from query param                      │
│  2. Fetch OR dossier object                            │
│  3. Get user via IUserSession                          │
│  4. Fetch all files related to dossier                 │
│  5. For each file, check PropertyRbacHandler permission │
│  6. Return only readable files                          │
│                                                         │
│  return JSONResponse(['files' => [...]]);              │
└─────────────────────────────────────────────────────────┘
          │
          │ (consumes OpenRegister FileService + RBAC)
          │
          v
┌─────────────────────────────────────────────────────────┐
│ OpenRegister FileService + Relations + PropertyRbac     │
└─────────────────────────────────────────────────────────┘
```

## Error Handling

| Scenario | Response |
|----------|----------|
| No dossierId provided | Widget shows "Select a dossier" message |
| No files linked to dossier | Widget shows "No files" empty state |
| User has zero permissions on all files | Widget shows "No files" (same as empty) |
| Backend API error (500, 503) | NcEmptyContent with "Failed to load files" + retry button |
| Network timeout | Retry button + exponential backoff |

## Responsive Design

| Viewport | Behavior |
|----------|----------|
| 320px (mobile) | Single-column list, filename + size, open-link as action button |
| 768px (tablet) | Two-column card layout, filename + type + size |
| 1024px+ (desktop) | Multi-column, full table (filename, type, size, modified-date, actions) |

CSS uses NL Design System tokens only; no hardcoded colors or breakpoints.

## Security Considerations

- **No IDOR**: Backend checks that the requesting user owns / can access the dossier before returning its files (not just that the user is logged in)
- **No PII in logs**: File names and user IDs are NOT logged
- **Permission inheritance**: File permissions are defined in OpenRegister; this widget enforces them, doesn't invent new rules
- **CSRF protection**: Widget uses `@nextcloud/axios` for API calls (auto-attaches CSRF token)

## Migration Plan

1. **Backend lands first** — `FileAccessController` + `FileAccessService` + tests in one PR
2. **Frontend widget lands next** — `DashboardFileAccessWidget.vue` + store plugin + composable
3. **Integration with dashboard** — register widget in `CnDashboardPage` slot list
4. **Playwright tests** — verify file access, access denial, responsive layout
5. **Rollback**: No schema changes. Removing the PR removes the widget; files are unaffected.

## Open Questions

1. **What is the "current dossier" context on the dashboard?** Does the widget require the user to select a dossier first, or does MyDash have a default dossier per user? (Answer: design assumes MyDash provides a `selectedDossierId` from the dashboard state or route params.)
2. **Should we show file version history?** (Answer: defer to Phase 2; show only the latest version per file in Phase 1.)
3. **What file types should we support?** (Answer: all types that OpenRegister relations support; no custom filtering in Phase 1.)
