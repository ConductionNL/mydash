# Demo Data Showcases

Bundled "showcase" dashboards — fully populated example dashboards illustrating different use cases (marketing, engineering, all-staff, project, community). Admins can install them with one click to give end users a reference design and reduce blank-canvas friction.

## Why

MyDash dashboards start blank by default — users must build from scratch, creating friction and uncertainty about what's possible. Organizations need pre-built, validated dashboard templates that showcase best practices, accelerate onboarding, and reduce time-to-value. Unlike static documentation, interactive templates let users discover widget capability in context and customize from a working example. Admin control ensures quality and consistency while lowering user anxiety about the "right" way to set up a dashboard.

## What Changes

- **NEW** Bundled showcase JSON files under `appdata/demo/` containing 5 ready-made dashboards (marketing, engineering, all-staff, project, community), each with pre-configured widgets, metadata fields, and localization.
- **NEW** Admin UI: "Demo data" tab in MyDash admin section listing showcases with preview thumbnail, description, and per-showcase "Install" / "Uninstall" button.
- **NEW** `GET /api/admin/demo-showcases` endpoint returning list of available showcases with metadata and installation status.
- **NEW** `POST /api/admin/demo-showcases/{id}/install?lang=en|nl` endpoint creating showcase as a `group_shared` dashboard (via REQ-DASH-012 default-group sentinel) visible to all users. Returns installed dashboard UUID and list of skipped widgets (if any).
- **NEW** `DELETE /api/admin/demo-showcases/{id}` endpoint soft-removing installed showcase (cascade delete widgets). Idempotent: 204 if not installed.
- **NEW** Widget skip mechanism: if a showcase references a widget type that doesn't exist at install time, the widget is silently skipped and recorded in response warnings (graceful degradation).
- **NEW** Localization: each showcase ships with default language; install endpoint accepts `?lang=en|nl` to fetch localized variant if available.
- **NEW** Idempotency: reinstalling an already-installed showcase returns the existing dashboard UUID without duplication.
- **NEW** CLI commands: `php occ mydash:demo-showcases:install <id>` and `php occ mydash:demo-showcases:list`.
- **NEW** Read-only enforcement: bundled showcase files in `appdata/demo/` cannot be edited via admin UI (they are app data, not user data). The installed dashboard is fully editable like any other.

## Capabilities

### New Capabilities
- `demo-data-showcases`: Admin-installable bundle of pre-built example dashboards with widget skip-on-missing, multi-language support, and idempotent installation.

### Modified Capabilities
- (none — showcases integrate with core `dashboards` capability via REQ-DASH-012 default-group and standard widget storage.)

## Impact

- New files: `appdata/demo/` with 5 JSON showcase definitions, `lib/Controller/AdminDemoShowcasesController.php`, `lib/Service/DemoShowcasesService.php`, `src/components/AdminDemoData.vue`, CLI command class.
- Routes: `GET /api/admin/demo-showcases`, `POST /api/admin/demo-showcases/{id}/install`, `DELETE /api/admin/demo-showcases/{id}`.
- Database: NO new tables — showcases are read-only files, installations stored in existing `oc_mydash_dashboards` table (with type `group_shared`, groupId `default`).
- Frontend: Admin tab with showcase list, thumbnails, install/uninstall buttons.
- Dependencies: uses existing `DashboardService`, `WidgetService`, no new external libraries.

## Affected code units

- `appdata/demo/` — JSON showcase files (marketing, engineering, all-staff, project, community)
- `lib/Controller/AdminDemoShowcasesController.php` — endpoints for list, install, delete
- `lib/Service/DemoShowcasesService.php` — load showcases from disk, validate widget types, install/uninstall logic
- `src/components/AdminDemoData.vue` — admin UI with showcase list, thumbnails, buttons
- `appinfo/routes.php` — add three new API routes (GET list, POST install, DELETE uninstall)
- `lib/Command/DemoShowcasesCommand.php` — CLI commands for install and list

## Why a new capability

The demo showcases are a self-contained feature: bundled templates, installation logic, skip-on-missing, localization, and CLI exposure. They depend on core dashboard and widget infrastructure but add no breaking changes to dashboards. Keeping them standalone lets us evolve showcase templates, add new locales, and refine install behavior independently.

## Approach

- Showcases stored as JSON files in `appdata/demo/` (read-only from app).
- Install endpoint creates a `group_shared` dashboard with `groupId = 'default'` (visible to all users via REQ-DASH-012).
- Widget skip mechanism: unknown widget types recorded in response `skippedWidgets` array; installation proceeds with remaining widgets.
- Localization: each showcase file names like `marketing-en.json` or `marketing-nl.json`; install endpoint prefers user locale, falls back to default.
- Idempotency: track installed showcase ID in dashboard metadata (or query existing group_shared dashboard with showcase tag); reinstall returns existing UUID.
- CLI commands mirror endpoints for ops convenience.
- Read-only enforcement: admin UI displays showcase files as non-editable templates; only installed dashboard is mutable.

## Notes

- Dashboard templates cannot be user-owned (they're templates, not personal customizations) — must use `group_shared` type with default-group sentinel.
- Per-user customization (e.g., hiding widgets, reordering) is OUT of scope for v1 — users install showcase and edit from there like any dashboard.
- Showcase preview images are static PNG files bundled in `appdata/demo/` — live preview is OUT of scope for v1.
- Showcase versioning (e.g., "Marketing v2") is OUT of scope for v1; one version per showcase name.
- Export showcase from installed dashboard back to template file is OUT of scope for v1.
- Showcase naming, "demo-data-showcases" terminology avoids any reference to intravox, intra, or voxcloud.
