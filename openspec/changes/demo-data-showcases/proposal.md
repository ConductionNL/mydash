# Demo Data Showcases

Bundled "showcase" dashboards — fully populated example dashboards illustrating different organizational use cases (healthcare, university, municipality, tech startup, law firm). Admins can install them with one click to give end users a reference design and reduce blank-canvas friction.

## Why

MyDash dashboards start blank by default — users must build from scratch, creating friction and uncertainty about what's possible. Organizations need pre-built, validated dashboard templates that showcase best practices, accelerate onboarding, and reduce time-to-value. Unlike static documentation, interactive templates let users discover widget capability in context and customize from a working example. Admin control ensures quality and consistency while lowering user anxiety about the "right" way to set up a dashboard.

## What Changes

- **NEW** Bundled showcase ZIP archives under `showcases/{id}/` containing 5 ready-made dashboards (`de-bron`, `de-linden`, `gemeente-duin`, `horizon-labs`, `van-der-berg`), each with pre-configured widgets and NL-locale content. Each ZIP contains `export.json` plus `nl/` page files and `_media/` image assets.
- **NEW** Admin UI: "Demo data" tab in MyDash admin section listing showcases with preview thumbnail, description, and per-showcase "Install" / "Uninstall" button.
- **NEW** `GET /api/admin/demo-showcases` endpoint returning list of available showcases with metadata and installation status.
- **NEW** `POST /api/admin/demo-showcases/{id}/install?lang=en|nl` endpoint creating showcase as a `group_shared` dashboard (via REQ-DASH-012 default-group sentinel) visible to all users. Returns installed dashboard UUID and list of skipped widgets (if any).
- **NEW** `DELETE /api/admin/demo-showcases/{id}` endpoint soft-removing installed showcase (cascade delete widgets). Idempotent: 204 if not installed.
- **NEW** Widget skip mechanism: if a showcase references a widget type that doesn't exist at install time, the widget is silently skipped and recorded in response warnings (graceful degradation).
- **NEW** Localization: all v1 showcases are NL-only; install endpoint accepts `?lang=` for forward compatibility but always resolves to `nl` in v1. Multi-locale support is a v2 goal.
- **NEW** Idempotency: reinstalling an already-installed showcase returns the existing dashboard UUID without duplication.
- **NEW** CLI commands: `php occ mydash:demo-showcases:install <id> [--force]` and `php occ mydash:demo-showcases:list`. The `--force` flag bypasses the idempotency guard for reinstallation.
- **NEW** Read-only enforcement: bundled showcase ZIPs under `showcases/` cannot be edited via admin UI (they are app data, not user data). The installed dashboard is fully editable like any other.

## Capabilities

### New Capabilities
- `demo-data-showcases`: Admin-installable bundle of pre-built example dashboards (ZIP archives with `export.json` + media) with widget skip-on-missing, NL-only localization in v1, and per-showcase idempotent installation.

### Modified Capabilities
- (none — showcases integrate with core `dashboards` capability via REQ-DASH-012 default-group and standard widget storage.)

## Impact

- New files: `showcases/` with 5 ZIP showcase archives, `lib/Controller/AdminDemoShowcasesController.php`, `lib/Service/DemoShowcasesService.php`, `src/components/AdminDemoData.vue`, CLI command class.
- Routes: `GET /api/admin/demo-showcases`, `POST /api/admin/demo-showcases/{id}/install`, `DELETE /api/admin/demo-showcases/{id}`.
- Database: NO new tables — showcases are read-only files, installations stored in existing `oc_mydash_dashboards` table (with type `group_shared`, groupId `default`).
- Frontend: Admin tab with showcase list, thumbnails, install/uninstall buttons.
- Dependencies: uses existing `DashboardService`, `WidgetService`, no new external libraries.

## Affected code units

- `showcases/` — ZIP showcase archives (`de-bron`, `de-linden`, `gemeente-duin`, `horizon-labs`, `van-der-berg`)
- `lib/Controller/AdminDemoShowcasesController.php` — endpoints for list, install, delete
- `lib/Service/DemoShowcasesService.php` — load showcases from disk, validate widget types, install/uninstall logic
- `src/components/AdminDemoData.vue` — admin UI with showcase list, thumbnails, buttons
- `appinfo/routes.php` — add three new API routes (GET list, POST install, DELETE uninstall)
- `lib/Command/DemoShowcasesCommand.php` — CLI commands for install and list

## Why a new capability

The demo showcases are a self-contained feature: bundled templates, installation logic, skip-on-missing, localization, and CLI exposure. They depend on core dashboard and widget infrastructure but add no breaking changes to dashboards. Keeping them standalone lets us evolve showcase templates, add new locales, and refine install behavior independently.

## Approach

- Showcases stored as ZIP archives in `showcases/{id}/{id}.zip` (read-only from app). Each ZIP contains `export.json` manifest, `nl/` page JSON files, and `nl/_media/` JPEG assets.
- Install endpoint opens the ZIP, reads `export.json`, extracts `_media/` assets to an accessible location, and creates a `group_shared` dashboard with `groupId = 'default'` (visible to all users via REQ-DASH-012).
- Widget skip mechanism: unknown widget types recorded in response `skippedWidgets` array; installation proceeds with remaining widgets.
- Localization: v1 showcases are NL-only; install endpoint accepts `?lang=` for forward compatibility, always resolves to `nl`.
- Idempotency: track installed showcase ID in dashboard `metadata.showcaseId`; reinstall returns existing UUID. `--force` flag on CLI bypasses the guard.
- CLI commands mirror endpoints for ops convenience, with `--force` flag on install.
- Read-only enforcement: admin UI displays showcase ZIPs as non-editable templates; only installed dashboard is mutable.

## Notes

- Dashboard templates cannot be user-owned (they're templates, not personal customizations) — must use `group_shared` type with default-group sentinel.
- Per-user customization (e.g., hiding widgets, reordering) is OUT of scope for v1 — users install showcase and edit from there like any dashboard.
- Showcase preview images are static PNG files bundled in `appdata/demo/` — live preview is OUT of scope for v1.
- Showcase versioning (e.g., "Marketing v2") is OUT of scope for v1; one version per showcase name.
- Export showcase from installed dashboard back to template file is OUT of scope for v1.
- Showcase naming, "demo-data-showcases" terminology avoids any reference to intravox, intra, or voxcloud.
