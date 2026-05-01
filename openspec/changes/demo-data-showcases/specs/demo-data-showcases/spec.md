---
status: draft
---

# Demo Data Showcases Specification

## Purpose

The `demo-data-showcases` capability provides administrators with one-click installation of pre-built, fully populated example dashboards that illustrate different organizational use cases (marketing, engineering, all-staff, project, community). Showcases are bundled as JSON templates, loaded from disk on demand, and installed as `group_shared` dashboards visible to all users (via REQ-DASH-012 default-group sentinel). The capability includes widget type validation, graceful skip-on-missing for unknown widgets, multi-language support, and idempotent installation via API and CLI commands.

## Data Model

Showcases are read-only JSON files stored in `appdata/demo/` with the structure:

```json
{
  "id": "marketing",
  "name": "Marketing Overview",
  "description": "Dashboard for marketing campaigns, leads, and ROI tracking",
  "language": "en",
  "showcaseTag": "marketing",
  "gridColumns": 12,
  "metadataFields": [
    {"name": "campaignId", "label": "Campaign ID", "type": "text"},
    {"name": "roi", "label": "ROI %", "type": "number"}
  ],
  "widgets": [
    {
      "type": "welcome",
      "position": {"x": 0, "y": 0, "w": 12, "h": 2},
      "config": {"title": "Welcome to Marketing Dashboard"}
    },
    {
      "type": "calendar",
      "position": {"x": 0, "y": 2, "w": 6, "h": 4},
      "config": {"showWeekends": true}
    }
  ]
}
```

Showcase installations create `group_shared` dashboard records with:
- `type = 'group_shared'`
- `groupId = 'default'` (visible to all users via REQ-DASH-012)
- `metadata.showcaseId = '{showcase-id}'` (for idempotency tracking)
- `metadata.sourceLanguage = '{language-code}'`

The system maintains a registry of installed showcases by querying existing dashboards with matching showcase ID in metadata.

## ADDED Requirements

### Requirement: REQ-DEMO-001 Bundled showcase JSON files

The system MUST ship with at least 5 showcase JSON files in `appdata/demo/` directory, each defining a complete dashboard template. File naming convention: `{showcase-id}-{lang-code}.json` (e.g., `marketing-en.json`, `marketing-nl.json`). Each file MUST contain:
- `id`: Unique showcase identifier (immutable)
- `name`: Human-readable showcase title (localized by filename)
- `description`: One-sentence description of use case
- `language`: Language code (`'en'` or `'nl'`)
- `showcaseTag`: Category tag (`'marketing'`, `'engineering'`, `'all-staff'`, `'project'`, `'community'`)
- `gridColumns`: Dashboard grid width (integer, typically 12)
- `metadataFields`: Array of optional custom metadata field definitions
- `widgets`: Array of pre-configured widget objects with type, position, and config

The 5 bundled showcases MUST be: `marketing`, `engineering`, `all-staff`, `project`, `community`.

#### Scenario: Showcase files exist and load without error
- **GIVEN** MyDash is installed and enabled
- **WHEN** the system initializes
- **THEN** all 5 showcase JSON files MUST be readable from `appdata/demo/`
- **AND** each file MUST parse as valid JSON with required fields

#### Scenario: Localized showcase files are provided
- **GIVEN** showcase `marketing`
- **WHEN** admin installs with `lang=en` and separately with `lang=nl`
- **THEN** the system MUST load `marketing-en.json` for the first request
- **AND** `marketing-nl.json` for the second request

#### Scenario: Missing localized file falls back to default language
- **GIVEN** showcase `marketing` has `marketing-en.json` but no `marketing-fr.json`
- **WHEN** admin installs with `lang=fr`
- **THEN** the system MUST fall back to `marketing-en.json`
- **AND** the installed dashboard MUST carry `metadata.sourceLanguage = 'en'` to preserve the fact

#### Scenario: Invalid showcase JSON is rejected
- **GIVEN** a malformed JSON file in `appdata/demo/`
- **WHEN** the system attempts to load it
- **THEN** the system MUST log an error
- **AND** the showcase MUST NOT appear in the available list
- **AND** installation attempts MUST return HTTP 500

### Requirement: REQ-DEMO-002 List available showcases endpoint

The system MUST expose `GET /api/admin/demo-showcases` returning a JSON array of available showcases. Response format:

```json
[
  {
    "id": "marketing",
    "name": "Marketing Overview",
    "description": "Dashboard for marketing campaigns, leads, and ROI tracking",
    "thumbnailUrl": "/apps/mydash/appdata/demo/marketing-thumbnail.png",
    "language": "en",
    "isInstalled": true,
    "installedDashboardUuid": "9b2df4a1-2e8c-4a3b-8f1c-5d7e9a1b2c3d"
  }
]
```

The endpoint MUST be admin-only (HTTP 403 for non-admin users). Response MUST include installation status (`isInstalled` boolean) and UUID if installed. Showcase list MUST be deduplicated by `id` (prefer the user's locale if available, else the first found).

#### Scenario: List endpoint requires admin role
- **GIVEN** a non-admin user
- **WHEN** they send `GET /api/admin/demo-showcases`
- **THEN** the system MUST return HTTP 403

#### Scenario: All showcases appear in the list
- **GIVEN** 5 showcase JSON files in `appdata/demo/`
- **WHEN** admin calls `GET /api/admin/demo-showcases`
- **THEN** the response MUST include 5 items
- **AND** each item MUST have `id`, `name`, `description`, `thumbnailUrl`, `language`, `isInstalled`

#### Scenario: Installation status is accurate
- **GIVEN** admin has installed showcase `marketing`
- **WHEN** they call `GET /api/admin/demo-showcases`
- **THEN** the `marketing` item MUST have `isInstalled: true`
- **AND** MUST include `installedDashboardUuid`

#### Scenario: Installation status is false if not yet installed
- **GIVEN** showcase `engineering` has never been installed
- **WHEN** admin calls `GET /api/admin/demo-showcases`
- **THEN** the `engineering` item MUST have `isInstalled: false`
- **AND** MUST NOT include `installedDashboardUuid` (or it MUST be null)

### Requirement: REQ-DEMO-003 Install showcase endpoint

The system MUST expose `POST /api/admin/demo-showcases/{id}/install?lang=en|nl` creating an installed showcase as a `group_shared` dashboard with `groupId = 'default'`. The created dashboard MUST be visible to all users (via REQ-DASH-012). Response format:

```json
{
  "installedDashboardUuid": "9b2df4a1-2e8c-4a3b-8f1c-5d7e9a1b2c3d",
  "skippedWidgets": []
}
```

If any widget types are unknown at install time, they MUST be silently skipped and listed in `skippedWidgets` array (graceful degradation). The endpoint MUST be admin-only (HTTP 403 for non-admin). Return HTTP 201 on success, HTTP 404 if showcase not found, HTTP 400 if validation fails.

#### Scenario: Install showcase creates visible group-shared dashboard
- **GIVEN** admin sends `POST /api/admin/demo-showcases/marketing/install`
- **WHEN** the installation completes successfully
- **THEN** the system MUST create a dashboard with `type = 'group_shared'`, `groupId = 'default'`
- **AND** all users MUST see it in their `GET /api/dashboards/visible` response
- **AND** the response MUST return HTTP 201 with `installedDashboardUuid`

#### Scenario: Install with language parameter
- **GIVEN** admin sends `POST /api/admin/demo-showcases/marketing/install?lang=nl`
- **WHEN** showcase `marketing-nl.json` exists
- **THEN** the system MUST load the Dutch variant
- **AND** the installed dashboard MUST carry `metadata.sourceLanguage = 'nl'`

#### Scenario: Locale fallback if not available
- **GIVEN** admin sends `POST /api/admin/demo-showcases/marketing/install?lang=fr`
- **WHEN** `marketing-fr.json` does not exist
- **THEN** the system MUST fall back to the first available language (typically `marketing-en.json`)
- **AND** the response MUST include the effective language used

#### Scenario: Unknown showcase returns 404
- **GIVEN** admin sends `POST /api/admin/demo-showcases/unknown-id/install`
- **WHEN** no showcase with that ID exists
- **THEN** the system MUST return HTTP 404 with message "Showcase not found"

#### Scenario: Non-admin user cannot install
- **GIVEN** a non-admin user
- **WHEN** they send `POST /api/admin/demo-showcases/marketing/install`
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
- **THEN** the dashboard metadata MUST contain `showcaseId: 'marketing'` (or equivalent tag)
- **AND** MUST also contain the source language

### Requirement: REQ-DEMO-004 Idempotent installation

Reinstalling an already-installed showcase MUST return the existing dashboard's UUID without creating a duplicate. The system MUST track installation state by querying existing `group_shared` dashboards with matching `showcaseId` in metadata.

#### Scenario: Reinstall returns same UUID
- **GIVEN** admin has installed showcase `marketing`, receiving UUID `U1`
- **WHEN** they install `marketing` again
- **THEN** the system MUST return the same UUID `U1`
- **AND** no new dashboard MUST be created
- **AND** the existing dashboard MUST NOT be modified

#### Scenario: Each showcase maintains separate installation state
- **GIVEN** admin has installed both `marketing` and `engineering`
- **WHEN** they query installation status for both
- **THEN** both MUST show `isInstalled: true` with different UUIDs
- **AND** each has its own showcase metadata

### Requirement: REQ-DEMO-005 Widget type validation and skip-on-missing

At install time, the system MUST validate each widget type in the showcase against the registered widget registry. If a widget type is not registered (unknown), it MUST be silently skipped, recorded in the response, and logged; the installation MUST succeed with valid widgets only.

#### Scenario: All known widget types are installed
- **GIVEN** a showcase with widgets of types `welcome`, `calendar`, `recent-activity` (all registered)
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
- **GIVEN** admin has installed showcase `marketing`, creating dashboard `D1`
- **WHEN** they send `DELETE /api/admin/demo-showcases/marketing`
- **THEN** the system MUST soft-delete dashboard `D1` and all its widget placements
- **AND** the response MUST return HTTP 204
- **AND** `GET /api/dashboards/visible` for any user MUST no longer include `D1`

#### Scenario: Uninstall is idempotent
- **GIVEN** admin sends `DELETE /api/admin/demo-showcases/marketing`
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
- **WHEN** they send `DELETE /api/admin/demo-showcases/marketing`
- **THEN** the system MUST return HTTP 403

### Requirement: REQ-DEMO-007 Localization support

Each showcase MUST support localization via separate JSON files per language. The install endpoint accepts an optional `?lang=en|nl` query parameter to select the desired language variant. If the exact language is not available, the system MUST fall back to the first available variant (typically English). The installed dashboard MUST record its source language in metadata for audit and potential future re-install.

#### Scenario: Install with explicit language preference
- **GIVEN** showcase `marketing` has both `marketing-en.json` and `marketing-nl.json`
- **WHEN** admin sends `POST /api/admin/demo-showcases/marketing/install?lang=nl`
- **THEN** the system MUST load and install `marketing-nl.json`
- **AND** the dashboard metadata MUST record `sourceLanguage: 'nl'`

#### Scenario: Language fallback if not available
- **GIVEN** showcase `engineering` has only `engineering-en.json`
- **WHEN** admin requests `?lang=de`
- **THEN** the system MUST fall back to `engineering-en.json` (first available)
- **AND** the installed dashboard MUST note the fallback in metadata or logs

#### Scenario: Default language if no parameter given
- **GIVEN** showcase `marketing` with both EN and NL variants
- **WHEN** admin sends `POST /api/admin/demo-showcases/marketing/install` (no `?lang` param)
- **THEN** the system MUST select based on admin's user locale (if available)
- **OR** fall back to English

#### Scenario: List endpoint includes language code
- **GIVEN** showcase list response
- **WHEN** items are returned
- **THEN** each item MUST include a `language` field (`'en'`, `'nl'`, etc.)
- **AND** the frontend MUST display the language to the admin

### Requirement: REQ-DEMO-008 Read-only showcase source files

Bundled showcase JSON files in `appdata/demo/` are read-only template definitions and MUST NOT be edited or deleted by admins via the admin UI. Only the installed dashboard (a copy in `oc_mydash_dashboards` table) is mutable. The admin UI MUST display showcase templates as non-editable and non-deletable, with "Install" and "Uninstall" buttons for managing installations only.

#### Scenario: Showcase source files are not listed in editable templates
- **GIVEN** admin views the template management or dashboard list
- **WHEN** they look for editable templates
- **THEN** showcase source files MUST NOT appear
- **AND** showcase installations (installed dashboards) MUST appear as regular group-shared dashboards

#### Scenario: Installed dashboard is fully editable
- **GIVEN** admin has installed showcase `marketing`
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

1. `php occ mydash:demo-showcases:install <showcase-id> [--lang=en|nl]` — installs the specified showcase. Output MUST include the installed dashboard UUID and any skipped widgets.
2. `php occ mydash:demo-showcases:list` — lists all available showcases with installation status. Output format: table with columns `ID`, `Name`, `Status`, `Language`.

Both commands MUST validate admin role (require Nextcloud admin user credentials or skip if run as web/cron context). Commands MUST be non-interactive and suitable for automation.

#### Scenario: Install via CLI
- **GIVEN** admin runs `php occ mydash:demo-showcases:install marketing`
- **WHEN** the command completes
- **THEN** the system MUST output "Installed dashboard {uuid}"
- **AND** the dashboard MUST be created and visible to all users

#### Scenario: Install with language via CLI
- **GIVEN** admin runs `php occ mydash:demo-showcases:install marketing --lang=nl`
- **WHEN** showcase `marketing-nl.json` exists
- **THEN** the Dutch variant MUST be installed

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
