# Demo Data Showcases

## Why

Administrators have no way to populate MyDash with representative example dashboards that illustrate different organizational use cases. Installing MyDash results in an empty app — there are no dashboards to view, and new users cannot see the platform's capabilities without manually building demo content from scratch. This blocks both sales demonstrations and user onboarding.

This change introduces bundled showcase ZIP archives — pre-built, fully populated example dashboards for five organizational archetypes (healthcare, university, municipality, tech startup, law firm) — that administrators can install with a single click, populating `group_shared` dashboards visible to all users.

## What Changes

- Ship 5 showcase ZIP archives under `showcases/{id}/{id}.zip` (de-bron, de-linden, gemeente-duin, horizon-labs, van-der-berg), each containing:
  - `export.json` — canonical machine-readable manifest with full page content and widget definitions
  - `nl/` — per-locale directory tree with home.json, navigation.json, footer.json, and media assets
  - `nl/_media/*.jpg` — bundled image assets referenced by widgets

- Add `POST /api/admin/demo-showcases/{id}/install` to install a showcase as a `group_shared` dashboard
- Add `GET /api/admin/demo-showcases` to list available showcases with installation status
- Add `DELETE /api/admin/demo-showcases/{id}` to uninstall a showcase
- Add CLI commands `php occ mydash:demo-showcases:install` and `php occ mydash:demo-showcases:list`

- Track installed showcases via `metadata.showcaseId` on dashboard records for idempotent reinstallation
- Validate widget types at install time; gracefully skip unknown widget types
- Support 8 widget types: `heading`, `text`, `divider`, `links`, `image`, `file`, `news`, `video`, `people`

## Capabilities

### New Capabilities

- `demo-data-showcases` — bundled showcase ZIP loading, validation, and installation

### Modified Capabilities

- `dashboards` — no changes to existing REQ-DASH-001..012; installations use existing `group_shared` dashboard records

## Affected Code Units

- `showcases/` directory (bundled under app root) — ZIP archives with manifests and media
- `lib/Service/DemoShowcaseService.php` — showcase discovery, validation, installation logic
- `lib/Controller/DemoShowcaseController.php` — REST API endpoints
- `lib/Command/DemoShowcaseInstallCommand.php` — CLI install command
- `lib/Command/DemoShowcaseListCommand.php` — CLI list command
- `appinfo/routes.php` — 3 new REST routes
- `appinfo/info.xml` — OCS commands registration

## Dependencies

- No new Composer or npm dependencies required
- Uses existing dashboard CRUD services and widget validation
- Uses Nextcloud `IAppConfig` and logger

## Impact Summary

**Data:** 5 new ZIP archives bundled under `showcases/`, ~500 KB total. Installations create regular `group_shared` dashboard records (existing schema, no migration).

**Endpoints:** 3 new REST endpoints (admin-only), 2 new CLI commands.

**Performance:** ZIP loading is on-demand (admin install only); no runtime impact on dashboard rendering.

**Backwards compatibility:** No changes to existing dashboard or widget schema. Installations are additive only.

## Standards & References

- Nextcloud admin endpoint patterns (IGroupManager, admin auth)
- ZIP archive extraction via PHP ZipArchive
- JSON schema validation (export.json manifest)
- Dashboard visibility via `group_shared` + `groupId = 'default'` (REQ-DASH-012)
- Widget type registry and validation (existing widget capability)
