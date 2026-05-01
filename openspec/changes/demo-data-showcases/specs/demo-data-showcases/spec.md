---
status: draft
---

# Demo Data Showcases Specification

## Purpose

The `demo-data-showcases` capability provides administrators with one-click installation of pre-built, fully populated example dashboards that illustrate different organizational use cases. Showcases are bundled as ZIP archives containing a machine-readable `export.json` manifest plus per-locale page JSON files and media assets, loaded from disk on demand, and installed as `group_shared` dashboards visible to all users (via REQ-DASH-012 default-group sentinel). The capability includes widget type validation, graceful skip-on-missing for unknown widgets, NL-only localization in v1, and idempotent installation via API and CLI commands.

## Data Model

Showcases are stored as ZIP archives under `showcases/{id}/{id}.zip` within the app bundle. Each ZIP contains:

- `export.json` — the canonical machine-readable manifest with full page content
- `{locale}/` — per-locale directory tree (e.g. `nl/`)
- `{locale}/home.json` — home page layout and widget definitions
- `{locale}/navigation.json` — navigation configuration
- `{locale}/footer.json` — footer configuration
- `{locale}/_media/*.jpg` — bundled image assets referenced by widget `src` fields

**`export.json` top-level shape:**

```json
{
  "exportVersion": "1.3",
  "schemaVersion": "1.3",
  "exportDate": "2026-03-07T12:00:00.000Z",
  "requiresMinVersion": "0.8.11",
  "language": "nl",
  "pages": [
    {
      "_exportPath": "home",
      "uniqueId": "page-<stable-uuid>",
      "title": "...",
      "content": { }
    }
  ],
  "navigation": { "type": "megamenu", "items": [] },
  "footer": { "content": "..." },
  "comments": []
}
```

**Per-page content object shape** (from `{locale}/home.json` or inline in `export.json` pages):

```json
{
  "uniqueId": "page-<stable-uuid>",
  "title": "...",
  "language": "nl",
  "layout": {
    "columns": 1,
    "rows": [
      {
        "columns": 1,
        "backgroundColor": "",
        "collapsible": false,
        "widgets": [
          {
            "type": "heading",
            "column": 1,
            "order": 1,
            "content": "Welkom",
            "level": 2
          },
          {
            "type": "text",
            "column": 1,
            "order": 2,
            "content": "Markdowntekst hier"
          },
          {
            "type": "image",
            "column": 1,
            "order": 3,
            "src": "zorgteam.jpg",
            "alt": "Het zorgteam",
            "objectFit": "cover"
          }
        ]
      }
    ],
    "sideColumns": {
      "left":  { "enabled": false, "backgroundColor": "", "widgets": [] },
      "right": { "enabled": false, "backgroundColor": "", "widgets": [] }
    }
  }
}
```

Widget objects use **inline flat fields** — there is no `position.{x,y,w,h}` or `config` sub-object. Placement is specified by `column` (integer) and `order` (integer) within a row. The layout is row-based, not grid-coordinate-based.

The 8 widget types used across bundled showcases are:

| Type | Required fields | Notes |
|---|---|---|
| `heading` | `content`, `level` (1–5) | |
| `text` | `content` (markdown) | |
| `divider` | `style`, `color`, `height` | |
| `links` | `layout` (`tiles`\|`list`), `columns`, `items[]` | |
| `image` | `src` (bare filename), `alt`, `objectFit` | `src` resolves relative to `_media/` on install |
| `file` | `path` (relative), `name` | File may not exist in fresh install; skip-on-missing applies |
| `news` | `sourcePath`, `layout`, `columns`, `limit`, `sortBy`, `sortOrder`, `showImage`, `showDate`, `showExcerpt`, `autoplayInterval`, `filters` | Runtime resolution — no embedded data |
| `video` | `provider`, `src` (URL), `title`, `autoplay`, `loop`, `muted` | |
| `people` | `selectionMode`, `filters`, `layout`, `columns`, `limit`, `sortBy`, `showFields{}` | Queries live user data at render time |

Showcase installations create `group_shared` dashboard records with:
- `type = 'group_shared'`
- `groupId = 'default'` (visible to all users via REQ-DASH-012)
- `metadata.showcaseId = '{showcase-id}'` (for idempotency tracking)
- `metadata.sourceLanguage = '{language-code}'`

> **NOTE — MyDash improvement**: Per-showcase idempotency tracking via `metadata.showcaseId` is a MyDash design decision. The reference implementation used a single app-wide boolean flag (`demo_data_imported`) which only works for a single dataset and cannot distinguish between multiple installed showcases. MyDash's per-showcase approach is strictly more correct and is not a port of the reference behavior.

The system maintains a registry of installed showcases by querying existing dashboards with matching showcase ID in metadata.

## ADDED Requirements

### Requirement: REQ-DEMO-001 Bundled showcase ZIP archives

The system MUST ship with exactly 5 showcase ZIP archives under `showcases/{id}/{id}.zip`. Each ZIP MUST contain a valid `export.json` manifest plus a `nl/` locale directory tree. Each `export.json` MUST contain:
- `exportVersion`: Schema version string
- `schemaVersion`: Schema version string
- `requiresMinVersion`: Minimum app version required to install
- `language`: Locale code (`'nl'` for all v1 showcases)
- `pages`: Array of page objects with `_exportPath`, `uniqueId`, `title`, and `content`
- `navigation`: Navigation configuration object
- `footer`: Footer configuration object

The 5 bundled showcases MUST be:
- `de-bron` — healthcare/nursing organization
- `de-linden` — university
- `gemeente-duin` — municipality
- `horizon-labs` — tech startup
- `van-der-berg` — law firm

All 5 showcases are NL-only in v1. Multi-locale support (EN, DE, FR variants) is a v2 goal.

#### Scenario: Showcase ZIP archives exist and load without error
- **GIVEN** MyDash is installed and enabled
- **WHEN** the system initializes
- **THEN** all 5 showcase ZIP archives MUST be readable from `showcases/{id}/{id}.zip`
- **AND** each ZIP MUST contain a valid `export.json` parseable as JSON with required fields

#### Scenario: export.json version is checked against minimum app version
- **GIVEN** showcase `de-bron` with `"requiresMinVersion": "0.8.11"` in `export.json`
- **WHEN** the running app version is older than `0.8.11`
- **THEN** the system MUST reject the install with HTTP 422 and a clear version mismatch message
- **AND** the showcase MUST still appear in the list endpoint with `isInstalled: false`

#### Scenario: All showcases are NL-only in v1
- **GIVEN** showcase `gemeente-duin`
- **WHEN** admin requests install with any language parameter
- **THEN** the system MUST always resolve to the `nl/` locale tree
- **AND** the installed dashboard MUST carry `metadata.sourceLanguage = 'nl'`

#### Scenario: Invalid showcase ZIP is rejected
- **GIVEN** a showcase ZIP whose `export.json` is malformed or missing
- **WHEN** the system attempts to load it
- **THEN** the system MUST log an error
- **AND** the showcase MUST NOT appear in the available list
- **AND** installation attempts MUST return HTTP 500

### Requirement: REQ-DEMO-002 List available showcases endpoint

The system MUST expose `GET /api/admin/demo-showcases` returning a JSON array of available showcases. Response format:

```json
[
  {
    "id": "de-bron",
    "name": "De Bron",
    "description": "Intranet dashboard voor een zorginstelling",
    "thumbnailUrl": "/apps/mydash/showcases/de-bron/thumbnail.png",
    "language": "nl",
    "isInstalled": true,
    "installedDashboardUuid": "9b2df4a1-2e8c-4a3b-8f1c-5d7e9a1b2c3d"
  }
]
```

The endpoint MUST be admin-only (HTTP 403 for non-admin users). Response MUST include installation status (`isInstalled` boolean) and UUID if installed.

#### Scenario: List endpoint requires admin role
- **GIVEN** a non-admin user
- **WHEN** they send `GET /api/admin/demo-showcases`
- **THEN** the system MUST return HTTP 403

#### Scenario: All showcases appear in the list
- **GIVEN** 5 showcase ZIP archives are present
- **WHEN** admin calls `GET /api/admin/demo-showcases`
- **THEN** the response MUST include 5 items
- **AND** each item MUST have `id`, `name`, `description`, `thumbnailUrl`, `language`, `isInstalled`

#### Scenario: Installation status is accurate
- **GIVEN** admin has installed showcase `de-bron`
- **WHEN** they call `GET /api/admin/demo-showcases`
- **THEN** the `de-bron` item MUST have `isInstalled: true`
- **AND** MUST include `installedDashboardUuid`

#### Scenario: Installation status is false if not yet installed
- **GIVEN** showcase `horizon-labs` has never been installed
- **WHEN** admin calls `GET /api/admin/demo-showcases`
- **THEN** the `horizon-labs` item MUST have `isInstalled: false`
- **AND** MUST NOT include `installedDashboardUuid` (or it MUST be null)

### Requirement: REQ-DEMO-003 Install showcase endpoint

The system MUST expose `POST /api/admin/demo-showcases/{id}/install` creating an installed showcase as a `group_shared` dashboard with `groupId = 'default'`. An optional `?lang=` query parameter is accepted for forward compatibility but always resolves to `nl` in v1. The created dashboard MUST be visible to all users (via REQ-DASH-012). Response format:

```json
{
  "installedDashboardUuid": "9b2df4a1-2e8c-4a3b-8f1c-5d7e9a1b2c3d",
  "skippedWidgets": []
}
```

If any widget types are unknown at install time, they MUST be silently skipped and listed in `skippedWidgets` array (graceful degradation). The endpoint MUST be admin-only (HTTP 403 for non-admin). Return HTTP 201 on success, HTTP 404 if showcase not found, HTTP 422 if version check fails, HTTP 400 if validation fails.

#### Scenario: Install showcase creates visible group-shared dashboard
- **GIVEN** admin sends `POST /api/admin/demo-showcases/gemeente-duin/install`
- **WHEN** the installation completes successfully
- **THEN** the system MUST create a dashboard with `type = 'group_shared'`, `groupId = 'default'`
- **AND** all users MUST see it in their `GET /api/dashboards/visible` response
- **AND** the response MUST return HTTP 201 with `installedDashboardUuid`

#### Scenario: Language parameter is accepted but resolves to NL in v1
- **GIVEN** admin sends `POST /api/admin/demo-showcases/de-linden/install?lang=en`
- **WHEN** the installation completes
- **THEN** the system MUST load the `nl/` locale tree (the only available locale)
- **AND** the installed dashboard MUST carry `metadata.sourceLanguage = 'nl'`

#### Scenario: Media assets are extracted on install
- **GIVEN** admin installs showcase `de-bron` which contains `nl/_media/zorgteam.jpg` inside the ZIP
- **WHEN** the installation completes
- **THEN** the service MUST extract all files from `nl/_media/` and store them in an accessible location
- **AND** `image` widgets referencing `"src": "zorgteam.jpg"` MUST resolve correctly at render time
- **AND** the response MUST not return an error due to missing media

#### Scenario: Unknown showcase returns 404
- **GIVEN** admin sends `POST /api/admin/demo-showcases/unknown-id/install`
- **WHEN** no showcase with that ID exists
- **THEN** the system MUST return HTTP 404 with message "Showcase not found"

#### Scenario: Non-admin user cannot install
- **GIVEN** a non-admin user
- **WHEN** they send `POST /api/admin/demo-showcases/de-bron/install`
- **THEN** the system MUST return HTTP 403

#### Scenario: Widgets with unknown types are skipped
- **GIVEN** showcase JSON references widget type `future-widget-v2` which is not registered
- **WHEN** admin installs the showcase
- **THEN** the widget MUST NOT be created
- **AND** the response MUST include `skippedWidgets: ["future-widget-v2"]`
- **AND** the installation MUST succeed (HTTP 201) with remaining valid widgets installed

#### Scenario: Response warns of skipped widgets
- **GIVEN** a showcase installation where 2 widgets are skipped
- **WHEN** the installation completes
- **THEN** the response MUST include `skippedWidgets: ["unknown-type-1", "unknown-type-2"]`
- **AND** the frontend or CLI MUST display a warning to the user

#### Scenario: Showcase metadata preserved for idempotency
- **GIVEN** an installed showcase dashboard
- **WHEN** the system queries it by dashboard ID
- **THEN** the dashboard metadata MUST contain `showcaseId: 'de-bron'` (or the relevant showcase ID)
- **AND** MUST also contain the source language

### Requirement: REQ-DEMO-004 Idempotent installation

Reinstalling an already-installed showcase MUST return the existing dashboard's UUID without creating a duplicate. The system MUST track installation state by querying existing `group_shared` dashboards with matching `showcaseId` in metadata.

> **NOTE**: The reference implementation tracked idempotency using a single app-wide boolean flag, which only supports one demo dataset. MyDash's per-showcase `metadata.showcaseId` approach is more granular and is the correct design for a multi-showcase system. This is a MyDash improvement, not a port.

#### Scenario: Reinstall returns same UUID
- **GIVEN** admin has installed showcase `van-der-berg`, receiving UUID `U1`
- **WHEN** they install `van-der-berg` again
- **THEN** the system MUST return the same UUID `U1`
- **AND** no new dashboard MUST be created
- **AND** the existing dashboard MUST NOT be modified

#### Scenario: Each showcase maintains separate installation state
- **GIVEN** admin has installed both `de-bron` and `de-linden`
- **WHEN** they query installation status for both
- **THEN** both MUST show `isInstalled: true` with different UUIDs
- **AND** each has its own showcase metadata

### Requirement: REQ-DEMO-005 Widget type validation and skip-on-missing

At install time, the system MUST validate each widget type in the showcase against the registered widget registry. If a widget type is not registered (unknown), it MUST be silently skipped, recorded in the response, and logged; the installation MUST succeed with valid widgets only. The 8 widget types known to ship in bundled showcases are: `heading`, `text`, `divider`, `links`, `image`, `file`, `news`, `video`, `people`.

#### Scenario: All known widget types are installed
- **GIVEN** a showcase with widgets of types `heading`, `text`, and `image` (all registered)
- **WHEN** the showcase is installed
- **THEN** all 3 widgets MUST be created
- **AND** `skippedWidgets` MUST be empty

#### Scenario: Unknown widget type is skipped
- **GIVEN** a showcase with widget type `future-timeline` (not registered)
- **WHEN** the showcase is installed
- **THEN** that widget MUST NOT be created
- **AND** `skippedWidgets` MUST include `"future-timeline"`
- **AND** other widgets in the showcase MUST still be created

#### Scenario: Mixed valid and invalid widgets
- **GIVEN** a showcase with 5 widgets: 3 known, 2 unknown
- **WHEN** the showcase is installed
- **THEN** 3 valid widgets MUST be created
- **AND** 2 invalid widgets MUST be skipped
- **AND** `skippedWidgets` MUST be `["unknown-1", "unknown-2"]`
- **AND** the response MUST warn the admin

#### Scenario: Skip is logged for audit
- **GIVEN** a showcase installation with skipped widgets
- **WHEN** the installation completes
- **THEN** the system MUST log the event with showcase ID, widget types skipped, and admin user ID
- **AND** logs MUST be queryable for audit purposes

### Requirement: REQ-DEMO-006 Uninstall showcase endpoint

The system MUST expose `DELETE /api/admin/demo-showcases/{id}` soft-removing an installed showcase (delete the dashboard and cascade to widget placements). The endpoint MUST be idempotent: calling it twice MUST both return HTTP 204, whether the showcase was installed or not.

#### Scenario: Uninstall deletes the dashboard
- **GIVEN** admin has installed showcase `gemeente-duin`, creating dashboard `D1`
- **WHEN** they send `DELETE /api/admin/demo-showcases/gemeente-duin`
- **THEN** the system MUST soft-delete dashboard `D1` and all its widget placements
- **AND** the response MUST return HTTP 204
- **AND** `GET /api/dashboards/visible` for any user MUST no longer include `D1`

#### Scenario: Uninstall is idempotent
- **GIVEN** admin sends `DELETE /api/admin/demo-showcases/de-bron`
- **WHEN** the showcase is not installed (or was already uninstalled)
- **THEN** the system MUST return HTTP 204 (not 404)

#### Scenario: Uninstall cascades to widgets
- **GIVEN** an installed showcase with 5 widgets
- **WHEN** admin uninstalls the showcase
- **THEN** the dashboard MUST be deleted
- **AND** all 5 widget placements MUST be deleted
- **AND** no orphaned widgets MUST remain

#### Scenario: Non-admin cannot uninstall
- **GIVEN** a non-admin user
- **WHEN** they send `DELETE /api/admin/demo-showcases/de-bron`
- **THEN** the system MUST return HTTP 403

### Requirement: REQ-DEMO-007 Localization support

All v1 bundled showcases are NL-only. Each showcase ZIP contains a single `nl/` locale directory. The install endpoint accepts an optional `?lang=` query parameter for forward compatibility; in v1 this parameter is accepted but always resolves to `nl`. Multi-locale support (EN, DE, FR variants) is a v2 goal. The installed dashboard MUST record its source language in metadata.

#### Scenario: Install always uses NL locale in v1
- **GIVEN** any of the 5 bundled showcases
- **WHEN** admin installs with or without a `?lang=` parameter
- **THEN** the system MUST load and install from the `nl/` locale tree
- **AND** the dashboard metadata MUST record `sourceLanguage: 'nl'`

#### Scenario: Language parameter accepted for forward compatibility
- **GIVEN** admin sends `POST /api/admin/demo-showcases/de-linden/install?lang=en`
- **WHEN** no `en/` locale exists in the ZIP
- **THEN** the system MUST fall back to `nl/` (the only available locale)
- **AND** MUST NOT return an error — graceful fallback is required

#### Scenario: List endpoint includes language code
- **GIVEN** showcase list response
- **WHEN** items are returned
- **THEN** each item MUST include a `language` field (e.g. `'nl'`)
- **AND** the frontend MUST display the language to the admin

### Requirement: REQ-DEMO-008 Read-only showcase source files

Bundled showcase ZIP archives under `showcases/` are read-only template definitions and MUST NOT be edited or deleted by admins via the admin UI. Only the installed dashboard (a copy in `oc_mydash_dashboards` table) is mutable. The admin UI MUST display showcase templates as non-editable and non-deletable, with "Install" and "Uninstall" buttons for managing installations only.

#### Scenario: Showcase source files are not listed in editable templates
- **GIVEN** admin views the template management or dashboard list
- **WHEN** they look for editable templates
- **THEN** showcase source files MUST NOT appear
- **AND** showcase installations (installed dashboards) MUST appear as regular group-shared dashboards

#### Scenario: Installed dashboard is fully editable
- **GIVEN** admin has installed showcase `horizon-labs`
- **WHEN** they view the installed dashboard
- **THEN** it MUST be editable like any other group-shared dashboard
- **AND** they MUST be able to modify widgets, rename, reorder, etc.

#### Scenario: Admin UI prevents editing showcase source
- **GIVEN** the admin UI displaying a showcase card
- **WHEN** admin tries to click or interact with the source template
- **THEN** the UI MUST show only "Install" and "Uninstall" buttons
- **AND** MUST NOT offer "Edit" or "Delete source" options

### Requirement: REQ-DEMO-009 CLI commands for operations

The system MUST expose two Symfony console commands for showcase management.

1. `php occ mydash:demo-showcases:install <showcase-id> [--lang=nl] [--force]` — installs the specified showcase. The `--force` flag bypasses the idempotency guard and reinstalls even if the showcase is already installed (creating a new dashboard). Without `--force`, reinstall returns the existing UUID. Output MUST include the installed dashboard UUID and any skipped widgets.
2. `php occ mydash:demo-showcases:list` — lists all available showcases with installation status. Output format: table with columns `ID`, `Name`, `Status`, `Language`.

Both commands MUST validate admin role (require Nextcloud admin user credentials or skip if run as web/cron context). Commands MUST be non-interactive and suitable for automation.

#### Scenario: Install via CLI
- **GIVEN** admin runs `php occ mydash:demo-showcases:install de-bron`
- **WHEN** the command completes
- **THEN** the system MUST output "Installed dashboard {uuid}"
- **AND** the dashboard MUST be created and visible to all users

#### Scenario: Force reinstall via CLI
- **GIVEN** showcase `de-bron` is already installed with UUID `U1`
- **WHEN** admin runs `php occ mydash:demo-showcases:install de-bron --force`
- **THEN** the command MUST reinstall the showcase, creating a new dashboard
- **AND** the old dashboard `U1` MUST be removed or superseded
- **AND** the command MUST output the new UUID

#### Scenario: Install without --force returns existing UUID
- **GIVEN** showcase `van-der-berg` is already installed with UUID `U1`
- **WHEN** admin runs `php occ mydash:demo-showcases:install van-der-berg` (no --force)
- **THEN** the command MUST output the existing UUID `U1` without creating a duplicate
- **AND** MUST indicate that the showcase was already installed

#### Scenario: List command shows all showcases
- **GIVEN** 5 showcases available
- **WHEN** admin runs `php occ mydash:demo-showcases:list`
- **THEN** the output MUST show a table with 5 rows
- **AND** each row MUST include showcase ID, name, and installation status (Installed/Not installed)

#### Scenario: CLI honors the same validation as API
- **GIVEN** admin tries to install a non-existent showcase via CLI
- **WHEN** the command runs
- **THEN** the system MUST output an error "Showcase not found"
- **AND** exit with code 1
