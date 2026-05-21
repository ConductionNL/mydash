# Design — Demo Data Showcases

## Context

MyDash ships as an empty application. The first time an administrator logs in, they see no dashboards, no groups, and no guidance about what the platform can do. This prevents sales demos from showing real-world use cases and blocks user onboarding.

The reference implementation (a commercial dashboard platform) addresses this by bundling a single `demo_data_imported` boolean flag that, when toggled, populates the database with a pre-built dashboard. MyDash improves on this design in two ways:

1. **Multi-showcase support** — support multiple distinct showcases (each for a different organizational archetype) rather than a single monolithic dataset, using per-showcase `metadata.showcaseId` instead of an app-wide boolean flag.
2. **ZIP-based distribution** — package each showcase as a self-contained ZIP archive containing a manifest, localized page definitions, and media assets, enabling future multi-locale support and easing the burden of maintaining demo data in the codebase.

The showcase ZIPs are bundled under `showcases/` within the app root and loaded on-demand when an administrator requests installation. Each showcase defines a complete dashboard (pages, widgets, navigation, footer, media) using a JSON export format compatible with the MyDash dashboard serialization schema.

## Goals / Non-Goals

**Goals:**

- Ship 5 pre-built, organization-specific showcases (healthcare, university, municipality, tech startup, law firm) as ZIP archives.
- Provide a one-click install experience: admin calls `POST /api/admin/demo-showcases/{id}/install` and receives a ready-to-use `group_shared` dashboard visible to all users.
- Support idempotent installation: calling install twice for the same showcase returns the same dashboard UUID without creating a duplicate (tracked via `metadata.showcaseId`).
- Gracefully degrade when widgets are unknown: if a showcase references a widget type not registered in the current app, skip that widget silently, log the event, and return the list of skipped types to the caller.
- Provide CLI and REST API entry points so installations can be automated during setup or migration.
- Support localization at the showcase level: v1 bundles NL-only showcases; the architecture allows future multi-locale variants.
- Keep showcase source files immutable: admins cannot edit or delete showcase ZIPs via the admin UI. Only the installed dashboard (a copy in the database) is mutable.

**Non-Goals:**

- Multi-locale v1 support — all 5 showcases are NL-only; multi-locale is a v2 goal.
- A rich showcase editor — showcases are defined and packaged offline; no in-app authoring.
- Scheduled showcase rollout — all 5 showcases are available immediately; no gradual rollout or feature-gating.
- Media asset generation — images are manually curated; no procedural or AI generation.
- Custom widget types in showcases — only the 8 core widget types (`heading`, `text`, `divider`, `links`, `image`, `file`, `news`, `video`, `people`) are supported; showcases do not define custom types.
- Import/export of showcase definitions via the admin UI — showcases are bundled; the ZIP format is for packaging, not for user sharing.

## Decisions

### D1: ZIP-based distribution with machine-readable `export.json` manifest

**Decision**: Each showcase is a self-contained ZIP archive under `showcases/{id}/{id}.zip` containing:
- `export.json` — the canonical machine-readable manifest (OpenAPI 3.0 compatible shape)
- `{locale}/` directory tree (e.g., `nl/`) with per-locale page and navigation definitions
- `{locale}/_media/*.jpg` — bundled image assets

The `export.json` includes the full page content (inline), and per-locale directories provide alternate translations for future multi-locale support.

**Alternatives considered:**

- **Single flat JSON file per showcase** — rejecting because it does not separate per-locale content and complicates future multi-locale support; also harder to package media assets alongside JSON.
- **Database-seeded SQL dump** — rejecting because it couples demo data to a specific schema version, complicates migrations, and does not allow easy offline editing or version control of showcase definitions.
- **Nextcloud native export format (OCS)** — rejecting because it is app-specific and less portable; our own JSON schema is simpler and more durable.

**Rationale**: ZIP archives are portable, versioned, and self-contained. A machine-readable manifest allows programmatic validation and transformation. Separating locale-specific content into directories future-proofs for multi-locale support. Media assets bundled in the ZIP avoid external dependencies and ensure consistent asset availability.

### D2: Idempotent installation via per-showcase `metadata.showcaseId`

**Decision**: Installations track showcase identity in the installed dashboard record's `metadata.showcaseId` field (string, e.g., `'de-bron'`). Reinstalling the same showcase queries existing dashboards with matching showcase ID in metadata and returns the existing UUID instead of creating a duplicate.

**Alternatives considered:**

- **Single app-wide boolean flag** (`demo_data_imported: true/false`) — rejecting because it only supports one dataset; once set, there is no way to distinguish between multiple installed showcases or reinstall a subset.
- **Timestamp + hash-based deduplication** — rejecting because it is fragile (manifest changes invalidate the hash) and does not scale to multiple showcases.
- **Gallery/version table** — rejecting because it over-engineers the problem; the metadata field is sufficient.

**Rationale**: MyDash's per-showcase approach is strictly more correct and scales to multiple showcases. It enables admins to install showcase A, then later add showcase B, and reinstall A without creating a duplicate. The `metadata.showcaseId` field is part of the existing dashboard record schema and requires no migration.

**Note**: This is a MyDash improvement over the reference implementation's single-boolean approach and is documented in the context-brief as an intentional design difference.

### D3: Graceful widget-type skipping with logged warnings

**Decision**: At install time, the system iterates over widgets in the showcase and validates each type against the registered widget registry. Unknown types are skipped, recorded in the response's `skippedWidgets` array, logged, and the installation succeeds with valid widgets only.

**Alternatives considered:**

- **Fail-fast on unknown widget** — rejecting because it prevents installation if the app is older than the showcase was authored for; better to degrade gracefully.
- **Silent skipping with no logging** — rejecting because admins have no visibility into what was dropped; logging is essential for debugging.
- **Fallback to a generic "unknown" widget** — rejecting because it misleads the user into thinking content is present when it is not.

**Rationale**: Forward compatibility — if showcase JSON references a widget type added in a future app version, older app versions still install the showcase successfully (minus the unknown widgets). Logging provides an audit trail; the `skippedWidgets` response array tells the caller what was not installed.

### D4: NL-only v1 with `?lang=` query parameter for forward compatibility

**Decision**: All 5 v1 showcases contain only `nl/` locale directories. The install endpoint accepts an optional `?lang=` query parameter for forward compatibility but always resolves to `nl`. Installed dashboards record `metadata.sourceLanguage = 'nl'`. Multi-locale support (EN, DE, FR variants) is deferred to v2.

**Alternatives considered:**

- **No language parameter at all** — rejecting because it blocks the future path to multi-locale; accepting but ignoring the parameter now is cheaper than retrofitting it later.
- **Support EN fallback if NL not available** — rejecting because it introduces undefined behavior (which language is canonical?) and complicates the showcase authoring process.

**Rationale**: Accepting (but ignoring) the language parameter is a minimal forward-compatibility cost. Recording the source language in metadata enables future migration (if showcase `en/` is added later, a batch update can re-run installations with `?lang=en` for those who prefer English).

### D5: Admin-only endpoints with Nextcloud built-in auth

**Decision**: All three REST endpoints (`GET`, `POST`, `DELETE /api/admin/demo-showcases/*`) require admin role, enforced via Nextcloud's `IGroupManager::isAdmin()` on the backend. No custom login or token flow.

**Alternatives considered:**

- **Owner-or-admin per showcase** — rejecting because showcases are app-wide resources, not per-user; admin-only is simpler and correct.
- **Custom API key scheme** — rejecting because Nextcloud provides built-in admin auth; inventing a parallel scheme adds maintenance burden and security surface.

**Rationale**: Nextcloud's built-in `IGroupManager::isAdmin()` is audited and well-understood. All mutation endpoints (create, delete) are sensitive and must be admin-only to prevent misuse.

### D6: Media assets extracted to application filesystem at install time

**Decision**: When a showcase is installed, all files in the `nl/_media/` directory of the ZIP are extracted and stored in a predictable location (e.g., `media/showcases/{showcase-id}/` under the app's data root or public assets directory). Widget content is rewritten to reference the extracted location.

**Alternatives considered:**

- **In-database media storage** — rejecting because JSON blobs with base64-encoded images are inefficient and hurt readability.
- **Reference media by URL from the ZIP** — rejecting because it couples rendering to the ZIP file staying at a stable path; extraction decouples the two.
- **No media support in v1** — rejecting because showcases without images are less visually compelling for demos.

**Rationale**: Extraction is simple, decouples showcase packaging from rendering, and makes media available to all downstream consumers (export, PDF generation, etc.) without special handling.

### D7: `group_shared` dashboard visibility with `groupId = 'default'`

**Decision**: Installed showcases are stored as `type = 'group_shared'` dashboards with `groupId = 'default'`, making them visible to all users via the REQ-DASH-012 default-group sentinel.

**Alternatives considered:**

- **Personal dashboards to all users** — rejecting because MyDash does not support automatic per-user copying; `group_shared` is the correct visibility model.
- **Admin-only dashboards** — rejecting because the goal is to show all users representative content; restricting to admins defeats the purpose.
- **Public (no auth required) dashboards** — rejecting because MyDash does not yet support public dashboards (deferred to the `dashboard-public-share` capability); `group_shared` is the closest available option.

**Rationale**: `group_shared` + `groupId = 'default'` is the least-restrictive visibility model available today. All authenticated users see the same set of installed showcases.

### D8: No in-app showcase management UI in v1

**Decision**: v1 provides only REST API and CLI commands. Admin UI for showcase management (list, install, uninstall buttons) is deferred to a v2 feature.

**Alternatives considered:**

- **Embed showcase buttons in the dashboard list UI** — rejecting because it requires UI changes and a new admin panel; deferring keeps v1 scope tight.

**Rationale**: REST API and CLI are sufficient for early adopters and automated setup scripts. A polished UI can land later without API changes.

## Seed Data

Five pre-built showcase datasets packaged as ZIP archives:

1. **de-bron** (healthcare/nursing organization)
   - Home page with "Welkom," team photo, and links to care protocols
   - Widgets: heading, text, image (team photo), links to internal wikis

2. **de-linden** (university)
   - Dashboard with research metrics, student enrollment, and course calendar
   - Widgets: heading, text, divider, statistics/KPI cards (if supported), links to portals

3. **gemeente-duin** (municipality)
   - Citizens services overview, service requests, and operational metrics
   - Widgets: heading, text, links to citizen portals, news feed, file downloads

4. **horizon-labs** (tech startup)
   - Product roadmap, team directory, and operational dashboards
   - Widgets: heading, text, people widget (team directory), links to dev tools, video tutorial

5. **van-der-berg** (law firm)
   - Practice areas, client contact directory, and document library
   - Widgets: heading, text, links to case management, people widget (attorney directory), file widget

All seed data is Dutch (NL) only in v1.

## Reuse Analysis

- **Widget validation**: Leverages existing `widgetRegistry` and `Widget` entity from the `widgets` capability. No duplication.
- **Dashboard CRUD**: Uses existing `DashboardService` for creation, read, update, delete. No custom persistence logic.
- **Media handling**: Follows existing file-upload patterns in MyDash (e.g., `groupfolder-storage-backend` capability). No new file service needed beyond PHP's `ZipArchive` and standard filesystem I/O.
- **Permission checks**: Uses Nextcloud's `IGroupManager::isAdmin()` and existing `DashboardService` authorization checks. No custom role system.
- **Logging**: Uses Nextcloud `ILogger` and existing audit patterns. No new logging infrastructure.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| ZIP file corruption or missing required fields in `export.json` | Validate ZIP structure and manifest schema on load; log errors; exclude broken showcases from the list |
| Unknown widget types cause data loss | Graceful skip with `skippedWidgets` response array; admin is informed; logs include widget type and showcase ID |
| Media file size bloats the app bundle | Optimize images (JPEG quality, dimensions); 5 showcases × ~50 KB media each = ~250 KB overhead, acceptable |
| Localization complexity in future (multi-locale v2) | Current per-locale directory structure in ZIP already supports EN, DE, FR variants; v2 just adds more locale dirs |
| Showcase metadata field added to all dashboards (even non-showcase ones) | Field is optional and nullable; non-showcase dashboards leave it empty; no schema change required |
| Admin creates showcase, then customer requests modifications | Showcase source is read-only; admin can install, then edit the resulting dashboard like any other |

## Migration

**Zero-impact**: No schema changes. The `showcases/` directory and ZIP files are bundled with the app release. Existing dashboard data is unaffected.

**Optional cleanup**: A future job could hard-delete installed showcases (dashboards with `metadata.showcaseId IS NOT NULL`) if admins want to reset to a clean slate. Out of scope for v1.

## Open Questions

- Should the showcase list include a thumbnail preview image? Deferred to UI spec.
- Should admins be able to upload custom showcase ZIPs, or are bundled showcases sufficient? Out of scope; bundled-only for v1.
- Should the CLI install command accept a `--force` flag to reinstall (delete + recreate) even if already installed? Yes — specified in REQ-DEMO-009.
- How are showcase definitions versioned or updated across app releases? Packaging question; out of scope for this spec.
